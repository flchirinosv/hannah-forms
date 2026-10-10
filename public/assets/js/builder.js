/* VanillaForms Builder
 * Secciones contenedoras (1 nivel), lógica condicional (motor espejo de
 * App\Services\LogicEvaluator) y DnD anidado. Sin dependencias externas
 * además de Sortable.
 */
(function () {
    'use strict';

    // Config de servidor vía data-* (CSP: sin <script> inline).
    const appEl = document.getElementById('builderApp');
    if (!appEl) {
        return;
    }
    const FORM_ID = Number(appEl.dataset.formId);
    const CSRF = appEl.dataset.csrf;
    const FORM_STATUS = appEl.dataset.status || 'draft';

    // Settings del formulario (configuración de formulario, P1).
    let FORM_SETTINGS = {};
    try {
        const parsed = JSON.parse(appEl.dataset.settings || '{}') || {};
        FORM_SETTINGS = (typeof parsed === 'object' && !Array.isArray(parsed)) ? parsed : {};
    } catch (err) {
        FORM_SETTINGS = {};
    }

    // P10 — Límites globales de upload (techo del servidor; se muestran en el
    // panel del campo `file` para no prometer más de lo que el host permite).
    let UPLOAD_LIMITS = { max_size_mb: 10, max_files: 5, extensions: [] };
    try {
        const parsedLimits = JSON.parse(appEl.dataset.uploadLimits || '{}') || {};
        if (typeof parsedLimits === 'object' && !Array.isArray(parsedLimits)) {
            UPLOAD_LIMITS = Object.assign(UPLOAD_LIMITS, parsedLimits);
        }
    } catch (err) {
        /* sin data-upload-limits: defaults */
    }

    let nextUid = 1;

    // Mapea ids de BD -> _uid para traducir jerarquía y reglas al cargar.
    const initIds = {};
    let initialFields = [];
    try {
        initialFields = JSON.parse(appEl.dataset.fields || '[]') || [];
    } catch (err) {
        initialFields = [];
    }
    if (!Array.isArray(initialFields)) {
        initialFields = [];
    }
    initialFields.forEach((f) => {
        initIds[Number(f.id)] = 'field-' + (nextUid++);
    });

    function translateLogic(logic) {
        if (!logic || !Array.isArray(logic.rules)) {
            return null;
        }
        const rules = [];
        logic.rules.forEach((r) => {
            const uid = initIds[Number(r.field_id)];
            if (!uid) {
                return; // fuente eliminada: se descarta la regla
            }
            rules.push({ field_id: uid, op: r.op, value: r.value });
        });
        return rules.length ? { operator: logic.operator === 'any' ? 'any' : 'all', rules: rules } : null;
    }

    // Estado de la sugerencia de PII. Va AQUÍ, con el resto del estado del
    // módulo, y no junto a su listener: `applySettings()` la lee al abrir el
    // panel y se llama durante el arranque, antes de que se haya evaluado una
    // `let` declarada más abajo (que la dejaría en zona muerta temporal).
    // Guarda la ETIQUETA que el dueño descartó con «No es», no un booleano:
    // si reescribe la pregunta, es otra pregunta y hay que volver a preguntarlo.
    let piiDismissedLabel = null;

    // P1 — Términos que hacen sospechar que la respuesta identifica a alguien.
    // Van arriba (y no junto a la función que los usa) por lo mismo: es un
    // `const`, y `applySettings()` ya corre antes de esa declaración.
    const PII_HINT_RE = /\b(nomb[re]|apellid|email|e-?mail|correo|tel[eé]fon|celular|m[oó]vil|whatsapp|direcci[oó]n|domicilio|calle|avenida|postal|\bcp\b|cedula|c[eé]dula|dni|pasaporte|registro|n[oó]mero de documento|identidad|fecha de nacimiento|nacimiento|edad|telefono)\b/i;

    function resetPiiSuggestion() {
        piiDismissedLabel = null;
        if (typeof updatePiiSuggestion === 'function') updatePiiSuggestion();
    }

    let fields = Array.isArray(initialFields) ? initialFields.map((f) => ({
        _uid: initIds[Number(f.id)],
        id: f.id ? Number(f.id) : null,
        type: f.type,
        label: f.label || '',
        placeholder: f.placeholder || '',
        help_text: f.help_text || '',
        is_required: Boolean(f.is_required),
        is_pii: Boolean(f.is_pii),
        // `fields.show_header` (0013). Ausente = visible, que es lo que había
        // antes de que el ajuste existiera: un payload viejo no puede convertir
        // un salto de página en una caja invisible sin que nadie lo pidiera.
        show_header: f.show_header == null ? true : Boolean(Number(f.show_header)),
        options: Array.isArray(f.options) ? f.options : [],
        validations: f.validations && typeof f.validations === 'object' ? f.validations : {},
        parentId: f.parent_id ? (initIds[Number(f.parent_id)] || null) : null,
        logic: translateLogic(f.logic),
    })) : [];

    let selectedId = null;
    let lastDeleted = null;
    let sortables = [];

    // F0.2 (2026-09-27): los controles que el panel NO expone. Lo que esté en
    // esta lista se re-deriva de los inputs en cada applySettings() (y se
    // borra si el input queda vacío); lo que NO esté se conserva tal cual.
    // Sin esta separación, editar el engranaje de una pregunta destruía en
    // silencio las validaciones sin control (min_length/max_length en textos,
    // max_files en archivos) y el autosave lo persistía.
    const MANAGED_VALIDATION_KEYS = [
        'min', 'max', 'min_length', 'max_length',
        'accept', 'max_size_mb', 'multiple',
        'correct', 'randomize',
    ];

    function preservedValidations(f) {
        const prev = (f.validations && typeof f.validations === 'object' && !Array.isArray(f.validations))
            ? f.validations
            : {};
        const out = {};
        Object.keys(prev).forEach((k) => {
            if (MANAGED_VALIDATION_KEYS.indexOf(k) === -1 && prev[k] !== undefined && prev[k] !== null) {
                out[k] = prev[k];
            }
        });
        return out;
    }

    const typeInfo = {
        // min_length/max_length se declaran porque FillController.php:966-973
        // los aplica a text/textarea. Los inputs ya existían y fillSettings() ya
        // los leía; al no declararlos aquí quedaban ocultos (fillSettings
        // línea 822-823) y applySettings() no los re-serializaba, así que
        // abrirlos y teclear borraba el cap que traía la plantilla.
        text:     { icon: 'fa-i-cursor',      label: 'Texto',      hasPlaceholder: true, validations: ['min_length', 'max_length'] },
        textarea: { icon: 'fa-align-left',    label: 'Párrafo',    hasPlaceholder: true, hasHelp: true, validations: ['min_length', 'max_length'] },
        email:    { icon: 'fa-envelope',      label: 'Email',      hasPlaceholder: true },
        number:   { icon: 'fa-hashtag',       label: 'Número',     hasPlaceholder: true, validations: ['min', 'max'] },
        select:   { icon: 'fa-caret-down',    label: 'Desplegable',hasOptions: true },
        radio:    { icon: 'fa-circle-dot',    label: 'Opción única',hasOptions: true },
        checkbox: { icon: 'fa-square-check',  label: 'Casillas',   hasOptions: true },
        date:     { icon: 'fa-calendar',      label: 'Fecha' },
        scale:    { icon: 'fa-star',          label: 'Escala',     isScale: true, validations: ['min', 'max'] },
        url:      { icon: 'fa-link',          label: 'URL',        hasPlaceholder: true },
        tel:      { icon: 'fa-phone',         label: 'Teléfono',   hasPlaceholder: true },
        time:     { icon: 'fa-clock',         label: 'Hora' },
        'datetime-local': { icon: 'fa-calendar-check', label: 'Fecha y hora' },
        range:    { icon: 'fa-sliders',       label: 'Rango',      validations: ['min', 'max'] },
        matrix:   { icon: 'fa-table',         label: 'Matriz',     isMatrix: true, hasHelp: true },
        nps:      { icon: 'fa-chart-column',  label: 'NPS',        isNps: true, validations: ['min', 'max'] },
        star:     { icon: 'fa-star-half-stroke', label: 'Estrellas', isScale: true, validations: ['min', 'max'] },
        ranking:  { icon: 'fa-list-ol',       label: 'Ranking',    hasOptions: true, hasHelp: true },
        file:     { icon: 'fa-paperclip',     label: 'Archivo',    hasHelp: true, isFile: true },
        section:  { icon: 'fa-heading',       label: 'Sección de preguntas', isSection: true, hasHelp: true },
        page:     { icon: 'fa-file-lines',    label: 'Página (salto de paso)', isPage: true, hasHelp: true },
    };

    const el = {
        canvas: document.getElementById('canvasFields'),
        empty: document.getElementById('emptyCanvas'),
        title: document.getElementById('builderTitle'),
        desc: document.getElementById('builderDescription'),
        titleText: document.getElementById('builderTitleText'),
        saveBtn: document.getElementById('btnSaveStructure'),
        livePreviewBtn: document.getElementById('btnLivePreview'),
        // F3: vista previa embebida en el lienzo. Misma maqueta que el modal en
        // pantalla completa (`#livePreviewModal`), que sigue intacto: ambos
        // pintan `buildPreviewHtml()` dentro de `.fill-body .fill-container`.
        canvasPreview: document.getElementById('canvasPreview'),
        canvasPreviewContent: document.getElementById('canvasPreviewContent'),
        btnCanvasPreview: document.getElementById('btnCanvasPreview'),
        settingsForm: document.getElementById('settingsForm'),
        // F1: modal de alta con la cuadrícula de tipos.
        addFieldModal: document.getElementById('addFieldModal'),
        addFieldHint: document.getElementById('addFieldHint'),
        // F2: drawer re-estilizado sobre el modal de Bootstrap. #settingsForm
        // vive aquí desde el arranque; no hay panel lateral ni reubicaciones.
        fieldSettingsModal: document.getElementById('fieldSettingsModal'),
        fieldSettingsHost: document.getElementById('fieldSettingsHost'),
        fieldSettingsTitle: document.getElementById('fieldSettingsTitle'),
        fieldId: document.getElementById('fieldId'),
        fieldTypeLabel: document.getElementById('fieldTypeLabel'),
        fieldLabel: document.getElementById('fieldLabel'),
        fieldPlaceholder: document.getElementById('fieldPlaceholder'),
        fieldPlaceholderWrap: document.getElementById('fieldPlaceholderWrap'),
        fieldHelp: document.getElementById('fieldHelp'),
        fieldHelpWrap: document.getElementById('fieldHelpWrap'),
        fieldOptionsWrap: document.getElementById('fieldOptionsWrap'),
        optionsList: document.getElementById('optionsList'),
        btnAddOption: document.getElementById('btnAddOption'),
        fieldMatrixWrap: document.getElementById('fieldMatrixWrap'),
        fieldMatrixRows: document.getElementById('fieldMatrixRows'),
        fieldMatrixCols: document.getElementById('fieldMatrixCols'),
        fieldRankingHintWrap: document.getElementById('fieldRankingHintWrap'),
        fieldScaleWrap: document.getElementById('fieldScaleWrap'),
        fieldScaleMax: document.getElementById('fieldScaleMax'),
        // P10 — Ajustes del campo de archivo.
        fieldFileWrap: document.getElementById('fieldFileWrap'),
        valFileAccept: document.getElementById('valFileAccept'),
        valFileMaxSize: document.getElementById('valFileMaxSize'),
        valFileMultiple: document.getElementById('valFileMultiple'),
        valFileLimitsHint: document.getElementById('valFileLimitsHint'),
        validationWrap: document.getElementById('fieldValidationWrap'),
        valMin: document.getElementById('valMin'),
        valMax: document.getElementById('valMax'),
        valMinLen: document.getElementById('valMinLen'),
        valMaxLen: document.getElementById('valMaxLen'),
        valMinWrap: document.getElementById('valMinWrap'),
        valScaleHint: document.getElementById('valScaleHint'),
        valMaxWrap: document.getElementById('valMaxWrap'),
        valMinLenWrap: document.getElementById('valMinLenWrap'),
        valMaxLenWrap: document.getElementById('valMaxLenWrap'),
        fieldRequired: document.getElementById('fieldRequired'),
        fieldPii: document.getElementById('fieldPii'),
        fieldPiiWrap: document.getElementById('fieldPiiWrap'),
        fieldPageHeader: document.getElementById('fieldPageHeader'),
        fieldPageHeaderWrap: document.getElementById('fieldPageHeaderWrap'),
        btnApply: null, // se eliminó el botón «Aplicar»: la edición es en vivo
        btnDelete: document.getElementById('btnDeleteField'),
        btnUndo: document.getElementById('btnUndo'),
        btnRedo: document.getElementById('btnRedo'),
        saveStatus: document.getElementById('saveStatus'),
        paletteItems: document.querySelectorAll('.app-palette-item'),
        // F1 (rediseño): el «+» de la barra y el del lienzo vacío abren el mismo
        // modal que los «+» entre tarjetas. La paleta ya no es una columna.
        btnAddQuestion: document.getElementById('btnAddQuestion'),
        btnEmptyAdd: document.getElementById('btnEmptyAdd'),
        logicWrap: document.getElementById('logicWrap'),
        logicHowThis: document.getElementById('logicHowThis'),
        logicRulesList: document.getElementById('logicRulesList'),
        logicField: document.getElementById('logicField'),
        logicOp: document.getElementById('logicOp'),
        logicValue: document.getElementById('logicValue'),
        logicDatalist: document.getElementById('logicDatalist'),
        logicForwardWarn: document.getElementById('logicForwardWarn'),
        logicEmptyHint: document.getElementById('logicEmptyHint'),
        // F3: aviso de herencia (condición de la sección).
        logicInheritedNote: document.getElementById('logicInheritedNote'),
        // P8 — Quiz y barajado de opciones.
        fieldQuizWrap: document.getElementById('fieldQuizWrap'),
        quizAnswer: document.getElementById('quizAnswer'),
        fieldShuffleWrap: document.getElementById('fieldShuffleWrap'),
        fieldShuffleOps: document.getElementById('fieldShuffleOps'),
    };

    /* ---------- Utilidades de lógica (espejo del servidor) ---------- */
    const OP_TEXTS = {
        eq: '=', neq: '≠', contains: 'contiene', not_contains: 'no contiene',
        gt: '>', gte: '≥', lt: '<', lte: '≤', filled: 'tiene valor', empty: 'está vacío',
        matches: 'coincide con (regex)',
    };
    let logicOps = null;

    // P8 — Cuándo aplicar el "barajar opciones" por campo.
    const CHOICE_TYPES = ['select', 'radio', 'checkbox'];

    function opsFor(type) {
        if (type === 'radio' || type === 'select') {
            return [['eq', 'Igual a'], ['neq', 'Distinto de'], ['contains', 'Contiene'], ['not_contains', 'No contiene'], ['filled', 'Tiene valor'], ['empty', 'Está vacío'], ['matches', 'Coincide con (regex)']];
        }
        if (type === 'checkbox') {
            return [['contains', 'Incluye la opción'], ['not_contains', 'No incluye la opción'], ['eq', 'Igual a'], ['neq', 'Distinto de'], ['filled', 'Tiene valor'], ['empty', 'Vacío']];
        }
        if (type === 'number' || type === 'range' || type === 'scale' || type === 'nps' || type === 'star') {
            return [['gt', 'Mayor que'], ['gte', 'Mayor o igual'], ['lt', 'Menor que'], ['lte', 'Menor o igual'], ['eq', 'Igual a'], ['neq', 'Distinto de'], ['filled', 'Tiene valor'], ['empty', 'Está vacío']];
        }
        return [['eq', 'Igual a'], ['neq', 'Distinto de'], ['filled', 'Tiene valor'], ['empty', 'Está vacío'], ['matches', 'Coincide con (regex)']];
    }

    function typeIconOf(f) {
        return typeInfo[f.type] ? typeInfo[f.type].icon : 'fa-question';
    }

    /* ---------- Render (jerárquico) ---------- */
    function renderFieldCanvas() {
        destroySortables();
        el.canvas.innerHTML = '';

        const childrenOf = {};
        fields.forEach((f) => {
            if (f.parentId) {
                (childrenOf[f.parentId] = childrenOf[f.parentId] || []).push(f);
            }
        });
        const roots = fields.filter((f) => !f.parentId);
        roots.forEach((f) => appendNode(f, el.canvas, childrenOf));

        el.empty.style.display = roots.length ? 'none' : 'block';
        initSortables();
        renderCanvasPreview();
    }

    // F1 (2026-09-27) — destino de inserción de un «+», calculado al hacer clic
    // (no en render) para que siga siendo correcto si el array cambió.
    //   - El «+» cuelga de una tarjeta raíz  → hermano posterior, a nivel raíz.
    //   - El «+» cuelga de una hija de sección → hija posterior de esa sección.
    //   - Sin tarjeta dueña (lienzo vacío)    → al principio.
    function addTargetFor(owner) {
        if (!owner) {
            return { parentUid: null, atRoot: 0 };
        }
        const idx = fields.indexOf(owner);
        if (owner.parentId) {
            return { parentUid: owner.parentId, atIndex: idx + 1 };
        }
        let n = 0;
        for (let i = 0; i < fields.length; i++) {
            if (fields[i] === owner) break;
            if (!fields[i].parentId) n++;
        }
        return { parentUid: null, atRoot: n + 1 };
    }

    // El botón «+» de inserción. F1: es HIJO de la tarjeta que precede y abre
    // el modal con la cuadrícula de tipos (antes era un hermano suelto con un
    // dropdown; al arrastrar se separaba de su elemento). Ver docs/design.md.
    function createAddHandle(owner) {
        const wrap = document.createElement('div');
        wrap.className = 'app-canvas-add';
        wrap.innerHTML =
            '<button type="button" class="btn app-add-between-btn"' +
            ' aria-label="Agregar elemento aquí" title="Agregar elemento aquí">' +
            '<i class="fa-solid fa-plus"></i></button>';

        wrap.querySelector('button').addEventListener('click', (e) => {
            e.stopPropagation();
            openAddFieldModal(addTargetFor(owner));
        });
        return wrap;
    }

    // Índice (en el array plano) donde insertar el atRoot-ésimo campo raíz.
    function insertPosForRoot(afterRoot) {
        let seen = 0;
        for (let i = 0; i < fields.length; i++) {
            if (!fields[i].parentId) {
                if (seen === afterRoot) return i;
                seen++;
            }
        }
        return fields.length;
    }

    function appendNode(f, container, childrenOf) {
        const node = buildFieldNode(f);
        container.appendChild(node);

        if (f.type === 'section') {
            const childrenWrap = document.createElement('div');
            childrenWrap.className = 'app-section-children';
            childrenWrap.dataset.parentUid = f._uid;
            childrenWrap.dataset.hint = 'Arrastra preguntas aquí o usa el botón «Agregar pregunta dentro».';
            (childrenOf[f._uid] || []).forEach((child) => {
                const childNode = buildFieldNode(child);
                childrenWrap.appendChild(childNode);
                // Cada hija también lleva su «+», que inserta después de ella
                // dentro de la misma sección.
                childNode.appendChild(createAddHandle(child));
            });
            node.appendChild(childrenWrap);

            const control = document.createElement('div');
            control.className = 'app-add-control';

            // F1: un único botón que abre la cuadrícula de tipos. Antes era un
            // split (texto rápido + ▾ con la lista) y su dropdown duplicaba la
            // lista de tipos de la paleta. Ahora comparte modal con los «+».
            const addQuick = document.createElement('button');
            addQuick.type = 'button';
            addQuick.className = 'btn app-btn-add-child w-100';
            addQuick.title = 'Agregar una pregunta dentro de esta sección';
            addQuick.innerHTML = '<i class="fa-solid fa-plus me-1"></i>Agregar pregunta dentro';
            addQuick.addEventListener('click', (e) => {
                e.stopPropagation();
                openAddFieldModal({ parentUid: f._uid });
            });

            control.appendChild(addQuick);
            node.appendChild(control);
        }

        // F1: el «+» cuelga de esta tarjeta (último hijo) y se mueve con ella al
        // arrastrar. En una sección, cuelga por debajo del bloque completo: ahí
        // significa «hermano posterior a nivel raíz», porque las preguntas de la
        // sección se agregan con el control de arriba.
        node.appendChild(createAddHandle(f));
    }

    /* ---------- Construcción de tarjetas (lienzo WYSIWYG) ---------- */
    // Badges de la derecha de la tarjeta.
    function cardHeadRight(f) {
        const badge = typeInfo[f.type] ? typeInfo[f.type].label : f.type;
        let headRight = '<span class="badge text-bg-light text-muted text-uppercase" style="font-size:.65rem">' + esc(badge) + '</span>';
        if (f.type === 'section') {
            const childCount = fields.filter((x) => x.parentId === f._uid).length;
            const countBadge = childCount > 0
                ? '<span class="app-section-count">' + childCount + ' pregunta' + (childCount === 1 ? '' : 's') + '</span>'
                : '<span class="app-section-count app-section-count-empty">Grupo vacío</span>';
            headRight = '<span class="d-flex align-items-center gap-2">' + countBadge + headRight + '</span>';
        }
        return headRight;
    }

    function cardHeadHtml(f) {
        const required = f.is_required ? '<span class="text-danger">*</span>' : '';
        // Un salto de paso no guarda etiqueta (el builder no inventa contenido),
        // así que su tarjeta se identifica por el nombre del tipo. El resto de
        // campos, igual que hasta ahora.
        const fallback = f.type === 'page' ? 'Página' : 'Sin título';
        return '<div class="d-flex justify-content-between align-items-start mb-1">' +
            '<span class="app-field-label fw-semibold small app-label-edit" title="Clic para editar la etiqueta">' + esc(f.label || fallback) + ' ' + required + '</span>' +
            cardHeadRight(f) +
        '</div>';
    }

    function cardBodyHtml(f) {
        switch (f.type) {
            case 'textarea':
                return '<textarea class="form-control" rows="2" placeholder="' + esc(f.placeholder || '') + '"></textarea>';
            case 'select':
                return '<select class="form-select"><option value="">Selecciona…</option>' + optionsHtml(f.options) + '</select>';
            case 'radio':
                return '<div class="d-flex flex-column gap-1">' + f.options.map(o =>
                    '<div class="form-check"><input class="form-check-input" type="radio" disabled><label class="form-check-label">' + esc(o.value) + '</label></div>'
                ).join('') + '</div>';
            case 'checkbox':
                return '<div class="d-flex flex-column gap-1">' + f.options.map(o =>
                    '<div class="form-check"><input class="form-check-input" type="checkbox" disabled><label class="form-check-label">' + esc(o.value) + '</label></div>'
                ).join('') + '</div>';
            case 'number':
                return '<input type="number" class="form-control" placeholder="' + esc(f.placeholder || '') + '">';
            case 'email':
                return '<input type="email" class="form-control" placeholder="' + esc(f.placeholder || '') + '">';
            case 'url':
                return '<input type="url" class="form-control" placeholder="' + esc(f.placeholder || 'https://') + '">';
            case 'tel':
                return '<input type="tel" class="form-control" placeholder="' + esc(f.placeholder || '') + '">';
            case 'date':
                return '<input type="date" class="form-control">';
            case 'time':
                return '<input type="time" class="form-control">';
            case 'datetime-local':
                return '<input type="datetime-local" class="form-control">';
            case 'range':
                const rv = f.validations || {};
                const rmin = rv.min != null ? Number(rv.min) : 0;
                const rmax = rv.max != null ? Number(rv.max) : 100;
                const rval = Math.round((rmin + rmax) / 2);
                return '<div class="d-flex align-items-center gap-3 app-range-preview">' +
                    '<input type="range" class="form-range flex-grow-1" min="' + rmin + '" max="' + rmax + '" value="' + rval + '" disabled>' +
                    '<span class="app-range-val">' + rval + '</span>' +
                '</div>';
            case 'scale':
                const max = Number(f.validations.max) || 5;
                return '<div class="d-flex gap-2 scale-preview">' + Array.from({length: max}, (_, i) =>
                    '<span class="scale-dot">' + (i + 1) + '</span>'
                ).join('') + '</div>';
            case 'matrix':
                const mr = f.options.filter(o => o.group === 'rows').map(o => o.value);
                const mc = f.options.filter(o => o.group === 'cols').map(o => o.value);
                if (!mr.length || !mc.length) {
                    return '<p class="text-muted small mb-0">Configura filas y columnas en el panel.</p>';
                }
                return '<div class="vf-matrix-preview">' +
                    '<table class="table table-sm vf-matrix-table align-middle mb-0">' +
                        '<thead><tr><th></th>' + mc.map(c => '<th class="small fw-semibold text-center">' + esc(c) + '</th>').join('') + '</tr></thead>' +
                        '<tbody>' + mr.map(r =>
                            '<tr><th class="small fw-normal text-start">' + esc(r) + '</th>' +
                            mc.map(() => '<td class="text-center"><input type="radio" disabled></td>').join('') +
                            '</tr>'
                        ).join('') + '</tbody>' +
                    '</table></div>';
            case 'file': {
                const fv = f.validations || {};
                return '<input type="file" class="form-control"' +
                    (fv.accept ? ' accept="' + esc(fv.accept) + '"' : '') +
                    (fv.multiple ? ' multiple' : '') + ' disabled>' +
                    '<p class="text-muted small mt-2 mb-0">Máx. ' +
                    esc(String(Number(fv.max_size_mb) || UPLOAD_LIMITS.max_size_mb)) + ' MB por archivo' +
                    (fv.accept ? ' · ' + esc(fv.accept) : '') + '</p>';
            }
            case 'nps':
                return '<div class="d-flex flex-wrap gap-1 nps-preview">' + [0,1,2,3,4,5,6,7,8,9,10].map(i =>
                    '<span class="nps-dot" title="' + i + '">' + i + '</span>'
                ).join('') + '</div>';
            case 'star':
                const smax = Number(f.validations.max) || 5;
                return '<div class="d-flex gap-1 star-preview">' + Array.from({length: smax}, () =>
                    '<i class="fa-solid fa-star star-icon"></i>'
                ).join('') + '</div>';
            case 'ranking':
                const ropts = f.options.map(o => o.value);
                if (!ropts.length) {
                    return '<p class="text-muted small mb-0">Agrega opciones en el panel.</p>';
                }
                return '<div class="d-flex flex-column gap-1">' + ropts.map((o, i) =>
                    '<div class="d-flex align-items-center gap-2 ranking-preview">' +
                        '<span class="badge text-bg-light ranking-pos">' + (i + 1) + '</span>' +
                        '<span class="small">' + esc(o) + '</span>' +
                    '</div>'
                ).join('') + '</div>';
            case 'section':
                return '<div class="app-section-preview"><i class="fa-solid fa-heading"></i></div>';
            case 'page':
                return '<div class="app-section-preview text-primary"><i class="fa-solid fa-file-lines"></i><span class="small ms-2">Inicia un nuevo paso — todo lo de abajo pasa a la siguiente página</span></div>';
            default:
                return '<input type="text" class="form-control" placeholder="' + esc(f.placeholder || '') + '">';
        }
    }

    /**
     * F3 — El chip de una sección debe decir a quién afecta. El síntoma
     * reportado era «la condición solo oculta el título»: la tarjeta no
     * decía que el alcance era el bloque entero.
     */
    function cardChipHtml(f) {
        if (f.type === 'section' || f.type === 'page') {
            if (!hasRules(f)) return '';
            const kids = fields.filter((x) => x.parentId === f._uid).length;
            const scope = f.type === 'section'
                ? 'Se oculta el bloque entero' + (kids ? ' (' + kids + (kids === 1 ? ' pregunta' : ' preguntas') + ')' : ' (grupo vacío)')
                : 'Se omite el paso entero';
            return '<div class="app-logic-chip app-logic-inherited" title="El alcance de la regla es ' + (f.type === 'section' ? 'la sección completa' : 'la página completa') + '">'
                + '<i class="fa-solid fa-layer-group me-1"></i>Si: ' + esc(rulesText(f))
                + ' <span class="app-logic-scope">→ ' + esc(scope) + '</span></div>';
        }
        const own = f.logic && f.logic.rules && f.logic.rules.length ? logicChip(f) : '';
        return own + inheritedChip(f);
    }

    /** F3 — Sección a la que pertenece el campo, si es que pertenece a alguna. */
    function parentSectionOf(f) {
        if (!f.parentId) return null;
        return fields.find((x) => x._uid === f.parentId && x.type === 'section') || null;
    }

    function hasRules(f) {
        return Boolean(f && f.logic && f.logic.rules && f.logic.rules.length);
    }

    /**
     * F3 — La regla de la sección se aplica a sus hijas (AND), así que una
     * hija sin regla propia también es condicional. Sin este chip el autor
     * veía la tarjeta sin nada raro y no entendía por qué a veces no sale:
     * el backend ya lo resolvía (`isFieldVisible`), pero el builder no lo
     * comunicaba. No es editable aquí: se edita en la sección.
     */
    function inheritedChip(f) {
        const sec = parentSectionOf(f);
        if (!hasRules(sec)) return '';
        const name = (sec.label || '').trim() || 'sin título';
        const own = hasRules(f);
        return '<div class="app-logic-chip app-logic-inherited" title="Se combina con la condición de la sección (se edita en la sección)">'
            + '<i class="fa-solid fa-layer-group me-1"></i>'
            + (own
                ? 'Además, su sección «' + esc(name) + '» exige: ' + esc(rulesText(sec))
                : 'Solo se muestra si su sección «' + esc(name) + '» cumple: ' + esc(rulesText(sec)))
            + '</div>';
    }

    function cardHelpHtml(f) {
        return f.help_text ? '<div class="form-text">' + esc(f.help_text) + '</div>' : '';
    }

    // Refresca SOLO la tarjeta de un campo tras una edición en vivo
    // (sin reconstruir todo el lienzo ni perder el foco del panel).
    function refreshCard(f) {
        const node = el.canvas.querySelector('[data-uid="' + f._uid + '"]');
        if (!node) return;
        const bodyEl = node.querySelector('.app-field-body');
        if (bodyEl) {
            bodyEl.innerHTML = cardHeadHtml(f) + cardBodyHtml(f) + cardChipHtml(f) + cardHelpHtml(f);
            attachLabelEdit(node, f);
        }
        // F2: el pie se sincroniza (no se reescribe entero para no recablear
        // listeners). El switch del pie refleja el estado del panel y al revés.
        const req = node.querySelector('.app-required-toggle input');
        if (req) req.checked = Boolean(f.is_required);
    }

    // Edición de etiqueta "inline" (directo en la tarjeta del lienzo).
    function attachLabelEdit(node, f) {
        const span = node.querySelector('.app-field-label');
        if (!span) return;
        span.addEventListener('click', (e) => {
            e.stopPropagation();
            if (span.isContentEditable) return;
            span.dataset.orig = span.textContent;
            span.contentEditable = 'true';
            span.classList.add('app-label-editing');
            span.focus();
            const done = () => {
                span.contentEditable = 'false';
                span.classList.remove('app-label-editing');
                const raw = span.textContent || '';
                const v = raw.replace(/\*$/, '').trim();
                if (v !== (f.label || '')) {
                    pushHistory({ mark: false });
                    f.label = v;
                    markDirty();
                    renderFieldCanvas();
                }
            };
            span.addEventListener('keydown', (ev) => {
                if (ev.key === 'Enter') { ev.preventDefault(); span.blur(); }
                if (ev.key === 'Escape') { span.textContent = span.dataset.orig || ''; span.blur(); }
            });
            span.addEventListener('blur', done, { once: true });
        });
    }

    // F2: pie de tarjeta — selector de tipo y switch «Obligatoria». El tipo de
    // los elementos estructurales (sección/página) NO se cambia desde aquí: el
    // `<select>` viene deshabilitado a propósito (no se convierten).
    function cardFooterHtml(f) {
        const info = typeInfo[f.type] || {};
        const structural = Boolean(info.isSection || info.isPage);
        const opts = Object.keys(typeInfo)
            .filter((t) => !(typeInfo[t].isSection || typeInfo[t].isPage))
            .map((t) => '<option value="' + esc(t) + '"' + (t === f.type ? ' selected' : '') + '>' + esc(typeInfo[t].label) + '</option>')
            .join('');
        const typeSel = '<select class="form-select form-select-sm app-type-select"' + (structural ? ' disabled' : '')
            + ' title="Cambiar el tipo de pregunta" aria-label="Cambiar el tipo de pregunta">' + opts + '</select>';
        const reqToggle = structural
            ? ''
            : '<label class="app-required-toggle" title="¿Obligatoria?"><input type="checkbox" class="form-check-input"' + (f.is_required ? ' checked' : '') + '><span>Obligatoria</span></label>';
        const tools =
            '<div class="app-field-tools">' +
                '<button type="button" class="btn btn-sm app-tool-btn" data-act="duplicate" title="Duplicar (Ctrl+D)" aria-label="Duplicar"><i class="fa-solid fa-copy"></i></button>' +
                '<button type="button" class="btn btn-sm app-tool-btn" data-act="settings" title="Configurar" aria-label="Configurar este elemento"><i class="fa-solid fa-gear"></i></button>' +
                '<button type="button" class="btn btn-sm app-tool-btn text-danger" data-act="delete" title="Eliminar (Supr)" aria-label="Eliminar"><i class="fa-solid fa-trash"></i></button>' +
            '</div>';
        return '<div class="app-field-footer d-flex align-items-center gap-2">' + typeSel + reqToggle + tools + '</div>';
    }

    /** F2 — Cambia el tipo de una pregunta conservando lo que tenga sentido. */
    function changeFieldType(uid, newType) {
        const idx = fields.findIndex((x) => x._uid === uid);
        if (idx === -1) return;
        const f = fields[idx];
        const cur = typeInfo[f.type] || {};
        const nxt = typeInfo[newType] || {};
        if (newType === f.type) return;
        // Sección/página no se convierten: cambian de rol solo por el «+». Un
        // <select> activo en su pie prometería algo que el servidor rechaza
        // (hijas colgando de un tipo nuevo, parent_id huérfano).
        if (cur.isSection || cur.isPage || nxt.isSection || nxt.isPage) return;
        pushHistory();

        const oldV = (f.validations && typeof f.validations === 'object' && !Array.isArray(f.validations))
            ? f.validations
            : {};

        // Opciones: se conservan entre tipos con opciones; un tipo recién
        // «con opciones» nace con dos, como addFieldByType; matriz y el resto
        // no tienen.
        let options;
        if (nxt.isMatrix) {
            options = [];
        } else if (nxt.hasOptions) {
            options = (cur.hasOptions ? f.options : []).length
                ? f.options
                : [{ value: 'Opción 1' }, { value: 'Opción 2' }];
        } else {
            options = [];
        }

        // Validaciones: la escala/estrellas/NPS tienen TAMAÑO (max), no rango;
        // NPS además fija 0–10. Del resto se conservan las claves que el tipo
        // nuevo DECLARA en typeInfo (las que tienen control en el panel) si ya
        // existían; lo que el tipo nuevo no maneja se descarta.
        const nuevoV = {};
        if (nxt.isNps) {
            nuevoV.min = 0;
            nuevoV.max = 10;
        } else if (nxt.isScale) {
            nuevoV.max = Math.max(2, Math.min(Number(oldV.max) || 5, 10));
        }
        if (Array.isArray(nxt.validations)) {
            nxt.validations.forEach((key) => {
                if (oldV[key] !== undefined && oldV[key] !== null) nuevoV[key] = oldV[key];
            });
        }

        f.type = newType;
        f.options = options;
        f.validations = nuevoV;
        f.placeholder = nxt.hasPlaceholder ? (f.placeholder || '') : '';
        f.help_text = nxt.hasHelp ? (f.help_text || '') : '';

        renderFieldCanvas();
        if (selectedId === uid) fillSettings(fields.find((x) => x._uid === uid));
        markDirty();
        showToast('Tipo cambiado a ' + ((typeInfo[newType] || {}).label || newType), 'success');
    }

    /** F2 — El switch «Obligatoria» del pie escribe el flag en memoria. */
    function setFieldRequired(uid, checked) {
        const f = fields.find((x) => x._uid === uid);
        if (!f) return;
        f.is_required = Boolean(checked);
        // Si el drawer está abierto sobre este mismo campo, su interruptor se
        // sincroniza; si está cerrado no se toca: aplicaría el valor del panel
        // como si fuera el del campo (applySettings() no corre aquí a propósito).
        if (selectedId === uid && settingsModalOpen && el.fieldRequired) {
            el.fieldRequired.checked = f.is_required;
        }
        refreshCard(f);
        markDirty();
    }

    function buildFieldNode(f) {
        const node = document.createElement('div');
        node.className = 'app-canvas-field' + (f._uid === selectedId ? ' selected' : '') + (f.type === 'section' ? ' app-canvas-section' : '') + (f.type === 'page' ? ' app-canvas-page' : '');
        node.dataset.uid = f._uid;

        node.innerHTML =
            '<div class="app-field-main">' +
                '<div class="app-field-drag"><i class="fa-solid fa-grip-vertical"></i></div>' +
                '<div class="app-field-body">' + cardHeadHtml(f) + cardBodyHtml(f) + cardChipHtml(f) + cardHelpHtml(f) + '</div>' +
            '</div>' +
            cardFooterHtml(f);

        attachLabelEdit(node, f);

        // F2: el pie de la tarjeta es funcional, así que sus propios controles
        // se escuchan aquí (el delegado de tools queda para los botones).
        const typeSel = node.querySelector('.app-type-select');
        if (typeSel) {
            typeSel.addEventListener('change', () => changeFieldType(f._uid, typeSel.value));
        }
        const req = node.querySelector('.app-required-toggle');
        if (req) {
            req.addEventListener('change', () => setFieldRequired(f._uid, req.checked));
        }

        node.addEventListener('click', (e) => {
            if (e.target.closest('.app-field-drag')) return;
            if (e.target.closest('.app-tool-btn')) return;
            if (e.target.closest('.app-btn-add-child')) return;
            if (e.target.closest('.app-add-control')) return;
            // F1: el «+» cuelga de la tarjeta, así que su clic bubblea hasta ella.
            if (e.target.closest('.app-canvas-add')) return;
            // F2: el pie (tipo/obligatoria) no selecciona la tarjeta.
            if (e.target.closest('.app-field-footer')) return;
            // Un clic que venga del área de preguntas (hijos) lo maneja la propia
            // pregunta; no debe rebotar seleccionando la sección (event bubbling).
            if (node.classList.contains('app-canvas-section') && e.target.closest('.app-section-children')) return;
            selectFieldById(f._uid);
        });

        return node;
    }

    function logicChip(f) {
        return '<div class="app-logic-chip"><i class="fa-solid fa-code-branch me-1"></i>Si: ' + esc(rulesText(f)) + '</div>';
    }

    /** «etiqueta operador valor» de las reglas de un campo, en texto plano. */
    function rulesText(f) {
        const join = f.logic.operator === 'any' ? ' O ' : ' Y ';
        const parts = (f.logic.rules || []).map((r) => {
            const src = fields.find((x) => x._uid === r.field_id);
            const label = src ? (src.label || (typeInfo[src.type] ? typeInfo[src.type].label : 'campo')) : '?';
            return label + ' ' + (OP_TEXTS[r.op] || r.op) + (r.value ? ' "' + r.value + '"' : '');
        });
        return parts.join(join);
    }

    function optionsHtml(options) {
        return options.map(o => '<option value="' + esc(o.value) + '">' + esc(o.value) + '</option>').join('');
    }

    // Escapa para TEXTO y para atributo entre comillas (dobles O simples).
    //
    // Antes se usaba el truco de textContent → innerHTML, que escapa & < > pero
    // NO las comillas: al interpolar en `placeholder="' + esc(f.placeholder) + '"`,
    // un placeholder con `"` cerraba el atributo y lo que venía detrás se
    // convertía en atributos nuevos (`onfocus=…`). Como el builder lo abre
    // cualquiera con rol editor o admin (canEdit()), eso es XSS con la sesión
    // de quien revisa un formulario ajeno, no solo auto-XSS.
    //
    // Escapar también las comillas no cambia nada en un nodo de texto (el
    // navegador las decodifica igual), así que una sola función sirve para
    // todos los call sites.
    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /* ---------- Historial undo/redo ---------- */
    let historyUndo = [];
    let historyRedo = [];

    function pushHistory(opts) {
        opts = opts || {};
        historyUndo.push(snapshotState());
        if (historyUndo.length > 50) historyUndo.shift();
        historyRedo.length = 0;
        if (opts.mark !== false) markDirty();
    }

    function snapshotState() {
        return {
            fields: JSON.parse(JSON.stringify(fields)),
            title: el.title.value,
            desc: el.desc.value,
            selectedId: selectedId,
        };
    }

    function restoreState(s) {
        fields = s.fields;
        el.title.value = s.title || '';
        el.desc.value = s.desc || '';
        el.titleText.textContent = el.title.value.trim() || 'Sin título';
        selectedId = s.selectedId;
        renderFieldCanvas();
        if (selectedId) {
            const f = fields.find((x) => x._uid === selectedId);
            if (f) fillSettings(f); else clearSettings();
        } else {
            clearSettings();
        }
    }

    function undo() {
        if (!historyUndo.length) return;
        historyRedo.push(snapshotState());
        const s = historyUndo.pop();
        restoreState(s);
        markDirty();
    }

    function redo() {
        if (!historyRedo.length) return;
        historyUndo.push(snapshotState());
        const s = historyRedo.pop();
        restoreState(s);
        markDirty();
    }

    /* ---------- F2: configuración por elemento (drawer) ----------
       El engranaje de cada tarjeta abre el drawer de la derecha
       (#fieldSettingsModal re-estilizado como panel lateral). Es el modal de
       Bootstrap: mismo Esc, backdrop, foco y aria-modal.

       Invariante: #settingsForm es la única fuente y vive SIEMPRE dentro del
       drawer desde el render del servidor. No hay panel estático al que
       devolverlo ni reubicaciones: todas las ~45 referencias cacheadas en
       `el` apuntan a controles que están ahí desde que carga la página.
       Ver docs/design.md §4.2. */

    let settingsModalOpen = false;
    let settingsOpener = null;    // { uid } del engranaje que abrió, para devolver el foco

    /**
     * El botón que abrió el modal puede morir en el re-render que hace
     * selectFieldById(), así que el engranaje se localiza por uid al volver,
     * no por la referencia al nodo original.
     */
    function settingsOpenerEl() {
        if (!settingsOpener) return null;
        return el.canvas.querySelector(
            '.app-canvas-field[data-uid="' + settingsOpener.uid + '"] .app-tool-btn[data-act="settings"]'
        );
    }

    function openFieldSettings(uid) {
        const f = fields.find((x) => x._uid === uid);
        if (!f || !el.fieldSettingsModal) return;
        selectFieldById(uid);
        settingsOpener = { uid: uid };
        if (el.fieldSettingsTitle) {
            const info = typeInfo[f.type] || {};
            el.fieldSettingsTitle.textContent = f.label || info.label || 'Configuración';
        }
        // bootstrap.bundle se carga en el layout DESPUÉS de builder.js: el
        // acceso a bootstrap.Modal se resuelve de forma perezosa al hacer clic.
        if (!settingsModalOpen && window.bootstrap) {
            settingsModalOpen = true;
            bootstrap.Modal.getOrCreateInstance(el.fieldSettingsModal).show();
        }
    }

    function closeFieldSettings() {
        if (el.fieldSettingsModal && settingsModalOpen && window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(el.fieldSettingsModal).hide();
        }
    }

    function bindFieldSettingsModal() {
        if (!el.fieldSettingsModal) return;
        el.fieldSettingsModal.addEventListener('shown.bs.modal', () => {
            if (el.fieldLabel) el.fieldLabel.focus();
        });
        // Al cerrar (botón, Escape o backdrop) se devuelve el foco al engranaje
        // que abrió; el drawer no se rellena ni se vacía: #settingsForm es el
        // mismo durante toda la sesión y show/hide no lo tocan.
        el.fieldSettingsModal.addEventListener('hidden.bs.modal', () => {
            settingsModalOpen = false;
            const gear = settingsOpenerEl();
            if (gear) gear.focus();
            settingsOpener = null;
        });
    }

    /* ---------- Selection & settings ---------- */
    function selectFieldById(uid) {
        const f = fields.find((x) => x._uid === uid);
        if (!f) return;
        if (selectedId !== null && selectedId !== uid) pushHistory({ mark: false });
        selectedId = uid;
        fillSettings(f);
        renderFieldCanvas();
    }

    function fillSettings(f) {
        const info = typeInfo[f.type] || {};
        // F2: el formulario vive siempre dentro del drawer; no hay estado vacío
        // que alternar.

        el.fieldId.value = f.id ?? '';
        el.fieldTypeLabel.value = info.label || f.type;
        el.fieldLabel.value = f.label || '';
        el.fieldPlaceholder.value = f.placeholder || '';
        el.fieldHelp.value = f.help_text || '';
        el.fieldRequired.checked = Boolean(f.is_required);
        el.fieldPii.checked = Boolean(f.is_pii);
        el.fieldPageHeader.checked = f.show_header !== false;

        el.fieldPlaceholderWrap.classList.toggle('d-none', !info.hasPlaceholder);
        el.fieldHelpWrap.classList.toggle('d-none', !info.hasHelp);
        el.fieldOptionsWrap.classList.toggle('d-none', !info.hasOptions);
        el.fieldMatrixWrap.classList.toggle('d-none', !info.isMatrix);
        el.fieldRankingHintWrap.classList.toggle('d-none', f.type !== 'ranking');
        el.fieldScaleWrap.classList.toggle('d-none', !info.isScale);
        el.fieldFileWrap.classList.toggle('d-none', !info.isFile);
        el.validationWrap.classList.toggle('d-none', !info.validations);

        if (info.isMatrix) {
            el.fieldMatrixRows.value = f.options.filter(o => o.group === 'rows').map(o => o.value).join('\n');
            el.fieldMatrixCols.value = f.options.filter(o => o.group === 'cols').map(o => o.value).join('\n');
        }

        const isSection = Boolean(info.isSection);
        const isStructural = isSection || Boolean(info.isPage);
        el.fieldRequired.disabled = isStructural;
        // F1: un contenedor (sección o página) no guarda respuestas, así que el
        // flag se oculta en vez de solo deshabilitarse: un control visible pero
        // muerto es una pregunta que el dueño se hace y no puede responder.
        el.fieldPiiWrap.classList.toggle('d-none', isStructural);
        el.fieldPii.disabled = isStructural;
        // El ajuste de cabecera es al revés que el de PII: solo el salto de
        // página lo tiene, y solo para él se muestra.
        el.fieldPageHeaderWrap.classList.toggle('d-none', !info.isPage);
        if (isStructural) {
            el.fieldRequired.checked = false;
            el.fieldPii.checked = false;
            el.fieldLabel.placeholder = isSection ? 'Título de la sección' : 'Título de la página';
            const helpLabel = el.fieldHelpWrap.querySelector('label');
            if (helpLabel) helpLabel.textContent = 'Descripción';
        } else {
            el.fieldRequired.disabled = false;
            el.fieldLabel.placeholder = 'Pregunta';
            const helpLabel = el.fieldHelpWrap.querySelector('label');
            if (helpLabel) helpLabel.textContent = 'Texto de ayuda';
        }

        // P8: las páginas SÍ admiten condiciones (salta a página: si la
        // condición se cumple, el bloque se omite). El tab de lógica siempre
        // queda disponible.
        if (el.logicHowThis && info.isPage) {
            el.logicHowThis.textContent = 'Esta página se OMITIRÁ por completo (no se pedirán sus preguntas) si se cumplen las condiciones de abajo.';
        } else if (el.logicHowThis) {
            el.logicHowThis.textContent = 'Este campo aparecerá solo si se cumplen las condiciones de abajo (según las respuestas de otra pregunta).';
        }

        const v = f.validations || {};
        el.valMin.value = v.min ?? '';
        el.valMax.value = v.max ?? '';
        el.valMinLen.value = v.min_length ?? '';
        el.valMaxLen.value = v.max_length ?? '';
        el.valMinWrap.classList.toggle('d-none', !(info.validations && info.validations.includes('min')));
        el.valMaxWrap.classList.toggle('d-none', !(info.validations && info.validations.includes('max')));
        el.valMinLenWrap.classList.toggle('d-none', !(info.validations && info.validations.includes('min_length')));
        el.valMaxLenWrap.classList.toggle('d-none', !(info.validations && info.validations.includes('max_length')));

        // Para una escala (estrellas, NPS) «mínimo» y «máximo» no son un rango
        // que rellene quien responde, sino cuántos niveles tiene la escala. Sin
        // esta línea el mismo rótulo significa dos cosas según el tipo de campo
        // y no hay forma de saberlo desde el panel.
        if (el.valScaleHint) {
            if (info.isScale) {
                el.valScaleHint.textContent = info.isNps
                    ? 'Escala de 0 a 10. Mínimo 0 y máximo 10 son los valores habituales; con NPS el mínimo siempre es 0.'
                    : 'Número de niveles de la escala. Ej: 5 para cinco estrellas.';
                el.valScaleHint.classList.remove('d-none');
            } else {
                el.valScaleHint.classList.add('d-none');
            }
        }
        // Se repinta al abrir el panel: el descarte es POR ETIQUETA, así que al
        // saltar a otro campo el aviso se recalcula desde cero. La `let` vive
        // arriba, con el resto del estado, porque `applySettings` se llama desde
        // el arranque del builder y aquí todavía no existiría (zona muerta).
        resetPiiSuggestion();

        // El aviso de la cabecera del paso se repinta al abrir el panel por el
        // mismo motivo: el interruptor se rellena desde el estado guardado, así
        // que el aviso tiene que decidir sobre el título que viene del servidor
        // y no sobre lo que hubiera quedado escrito de la última apertura.
        if (typeof updatePageHeaderHint === 'function') updatePageHeaderHint();

        // P8 — Quiz: input de respuesta correcta visible solo con modo quiz,
        // en preguntas puntuables (no secciones/páginas/matriz/ranking).
        const quizOn = !!(FORM_SETTINGS.quiz && FORM_SETTINGS.quiz.enabled);
        const isQuizField = !isStructural && !['matrix', 'ranking', 'file'].includes(f.type);
        if (el.fieldQuizWrap) {
            el.fieldQuizWrap.classList.toggle('d-none', !(quizOn && isQuizField));
        }
        if (el.quizAnswer) el.quizAnswer.value = v.correct !== undefined ? String(v.correct) : '';

        // P8 — Barajar opciones en tipos de opción (no matriz/ranking).
        const isChoice = CHOICE_TYPES.includes(f.type);
        if (el.fieldShuffleWrap) {
            el.fieldShuffleWrap.classList.toggle('d-none', !isChoice);
        }
        if (el.fieldShuffleOps) el.fieldShuffleOps.checked = !!v.randomize;

        if (info.isScale) el.fieldScaleMax.value = v.max || 5;

        // P10 — Ajustes de archivo: extensiones, tamaño y múltiple.
        if (info.isFile) {
            el.valFileAccept.value = v.accept || '';
            el.valFileMaxSize.value = v.max_size_mb || '';
            el.valFileMultiple.checked = !!v.multiple;
            updateUploadLimitsHint();
        }

        renderOptions(f.options);
        renderLogicSettings(f);
    }

    /** P10 — Texto de ayuda con los límites reales del servidor. */
    function updateUploadLimitsHint() {
        if (!el.valFileLimitsHint) {
            return;
        }
        const exts = Array.isArray(UPLOAD_LIMITS.extensions) && UPLOAD_LIMITS.extensions.length
            ? UPLOAD_LIMITS.extensions.join(', ')
            : '—';
        el.valFileLimitsHint.textContent = 'Límites del servidor: ' + UPLOAD_LIMITS.max_size_mb + ' MB por archivo, ' +
            UPLOAD_LIMITS.max_files + ' archivo(s). Formatos: ' + exts + '.';
    }

    function addOptionRow(value, placeholder) {
        const row = document.createElement('div');
        row.className = 'input-group input-group-sm';
        row.innerHTML =
            '<input type="text" class="form-control option-input" value="' + esc(value || '') + '" placeholder="' + esc(placeholder || 'Opción') + '">' +
            '<button type="button" class="btn btn-outline-danger btn-remove-option" title="Quitar"><i class="fa-solid fa-xmark"></i></button>';
        row.querySelector('.btn-remove-option').addEventListener('click', () => {
            row.remove();
            applySettings();
            if (!el.optionsList.children.length) addOptionRow('', 'Opción 1');
        });
        el.optionsList.appendChild(row);
    }

    function renderOptions(options) {
        const opts = options.length ? options : [{ value: '' }, { value: '' }];
        el.optionsList.innerHTML = '';
        opts.forEach((opt, i) => addOptionRow(opt.value, 'Opción ' + (i + 1)));
    }

    /* ---------- Lógica condicional (drawer) ---------- */
    function currentLogicOperator() {
        const checked = document.querySelector('input[name="logicOperator"]:checked');
        return checked ? checked.value : 'all';
    }

    function renderLogicSettings(f) {
        const sources = fields.filter((x) => x._uid !== f._uid && x.type !== 'section' && x.type !== 'page');
        const hasSources = sources.length > 0;

        if (el.logicWrap) {
            el.logicEmptyHint.style.display = hasSources ? 'none' : 'block';
            el.logicEmptyHint.textContent = f.type === 'section'
                ? 'Para condicionar este grupo agrega primero al menos una pregunta arriba.'
                : (f.type === 'page'
                    ? 'Para omitir esta página agrega primero al menos una pregunta arriba.'
                    : 'Para condicionar esta pregunta agrega primero al menos otra pregunta arriba.');
        }

        renderInheritedNote(f);

        el.logicField.innerHTML = sources.map((s) =>
            '<option value="' + esc(s._uid) + '">' + esc((s.label || 'Sin título') + ' — ' + (typeInfo[s.type] ? typeInfo[s.type].label : s.type)) + '</option>'
        ).join('');

        const current = el.logicField.value;
        const selSource = sources.find((s) => s._uid === current) || (hasSources ? sources[0] : null);

        if (hasSources) {
            el.logicField.value = selSource._uid;
            renderLogicOps(selSource);
            renderLogicValueInput(selSource);
        }

        // Combinador all/any
        const operator = (f.logic && f.logic.operator) || 'all';
        document.querySelectorAll('input[name="logicOperator"]').forEach((r) => { r.checked = r.value === operator; });

        renderLogicRules(f);

        // Warning por referencia a un campo posterior.
        let fwd = false;
        if (f.logic && f.logic.rules) {
            const pos = {};
            fields.forEach((x, i) => { pos[x._uid] = i; });
            const selfPos = pos[f._uid];
            f.logic.rules.forEach((r) => {
                if (pos[r.field_id] !== undefined && selfPos !== undefined && pos[r.field_id] > selfPos) {
                    fwd = true;
                }
            });
        }
        el.logicForwardWarn.style.display = fwd ? 'block' : 'none';
    }

    /**
     * F3 — Explica el alcance real de la condición, que es donde el autor se
     * confundía («la condición de la sección solo afecta al título»):
     *  - En una SECCIÓN: avisa de que se oculta el bloque entero, no el título.
     *  - En una HIJA: avisa de que se hereda la regla de su sección y que las
     *    dos se combinan (AND), con el enlace lógico a dónde se edita.
     */
    function renderInheritedNote(f) {
        if (!el.logicInheritedNote) return;
        let html = '';

        if (f.type === 'section') {
            const kids = fields.filter((x) => x.parentId === f._uid);
            html = '<i class="fa-solid fa-circle-info me-1"></i>'
                + 'Si se cumple, se oculta <strong>la sección completa</strong>'
                + (kids.length
                    ? ' (' + kids.length + (kids.length === 1 ? ' pregunta' : ' preguntas') + ' y su título), no solo el título.'
                    : ', no solo el título. Agrega preguntas dentro para que la regla las afecte.');
        } else {
            const sec = parentSectionOf(f);
            if (hasRules(sec)) {
                const name = (sec.label || '').trim() || 'sin título';
                html = '<i class="fa-solid fa-layer-group me-1"></i>'
                    + 'Además de las condiciones de esta pestaña, esta pregunta hereda la de su sección «'
                    + esc(name) + '». Se muestran solo si <strong>ambas</strong> se cumplen. '
                    + 'La regla de la sección se edita en la propia sección.';
            }
        }

        if (html) {
            el.logicInheritedNote.innerHTML = html;
            el.logicInheritedNote.classList.remove('d-none');
        } else {
            el.logicInheritedNote.classList.add('d-none');
            el.logicInheritedNote.innerHTML = '';
        }
    }

    function renderLogicOps(source) {
        el.logicOp.innerHTML = opsFor(source.type).map((o) => '<option value="' + o[0] + '">' + esc(o[1]) + '</option>').join('');
        if (!el.logicOp.value) el.logicOp.value = 'eq';
    }

    function renderLogicValueInput(source) {
        const numeric = source.type === 'number' || source.type === 'range' || source.type === 'scale' || source.type === 'nps' || source.type === 'star';
        el.logicValue.type = numeric ? 'number' : 'text';
        el.logicDatalist.innerHTML = (source.options || []).map((o) =>
            '<option value="' + esc(o.value) + '">'
        ).join('');
        if (numeric) el.logicValue.setAttribute('inputmode', 'decimal');
    }

    function renderLogicRules(f) {
        el.logicRulesList.innerHTML = '';
        const rules = (f.logic && f.logic.rules) || [];
        rules.forEach((r, i) => {
            const src = fields.find((x) => x._uid === r.field_id);
            const label = src ? (src.label || (typeInfo[src.type] ? typeInfo[src.type].label : 'campo')) : 'campo';
            const row = document.createElement('div');
            row.className = 'app-logic-rule';
            row.innerHTML =
                '<span class="small"><i class="fa-solid ' + typeIconOf(src || {type: 'text'}) + ' me-1 text-muted"></i><strong>' + esc(label) + '</strong> ' +
                esc(OP_TEXTS[r.op] || r.op) + (r.value ? ' “' + esc(r.value) + '”' : '') + '</span>' +
                '<button type="button" class="btn btn-sm app-tool-btn" data-act="rm-rule" data-ridx="' + i + '" title="Quitar regla"><i class="fa-solid fa-xmark"></i></button>';
            el.logicRulesList.appendChild(row);
        });
        el.logicRulesList.querySelectorAll('[data-act="rm-rule"]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const idx = Number(btn.dataset.ridx);
                const target = fields.find((x) => x._uid === selectedId);
                if (!target || !target.logic) return;
                target.logic.rules.splice(idx, 1);
                if (!target.logic.rules.length) target.logic = null;
                renderLogicSettings(target);
                renderFieldCanvas();
                markDirty();
            });
        });
    }

    function addLogicRule() {
        const f = fields.find((x) => x._uid === selectedId);
        if (!f) return;
        const src = fields.find((x) => x._uid === el.logicField.value);
        if (!src) return;
        const op = el.logicOp.value;
        const value = (op === 'filled' || op === 'empty') ? '' : el.logicValue.value.trim();

        if (!f.logic) {
            f.logic = { operator: currentLogicOperator(), rules: [] };
        }
        f.logic.operator = currentLogicOperator();
        f.logic.rules.push({ field_id: src._uid, op: op, value: value });

        renderFieldCanvas();
        fillSettings(f);
        markDirty();
    }

    function clearSettings() {
        if (selectedId !== null) pushHistory({ mark: false });
        selectedId = null;
        // F2: sin panel lateral, cerrar la selección también cierra el drawer
        // (Esc/backdrop/«Cerrar» lo hacen por su cuenta; esto cubre Atajos y
        // borrados). El formulario no se toca: es el mismo durante la sesión.
        closeFieldSettings();
        renderFieldCanvas();
    }

    /* ---------- Aplicación en vivo de la configuración ---------- */
    // Escribe los controles del panel sobre el campo seleccionado y refresca
    // SOLO su tarjeta en el lienzo (no hay botón «Aplicar»: se aplica al teclear).
    function applySettings() {
        const uid = selectedId;
        if (uid === null) return;
        const idx = fields.findIndex(f => f._uid === uid);
        if (idx === -1) return;

        const f = fields[idx];
        const info = typeInfo[f.type] || {};
        const isStructural = Boolean(info.isSection) || Boolean(info.isPage);

        f.label = el.fieldLabel.value.trim();
        // F2: el título del modal refleja la etiqueta mientras se teclea.
        if (settingsModalOpen && el.fieldSettingsTitle && info.label) {
            el.fieldSettingsTitle.textContent = f.label || info.label;
        }
        if (info.hasPlaceholder) f.placeholder = el.fieldPlaceholder.value.trim();
        if (info.hasHelp) f.help_text = el.fieldHelp.value.trim();
        f.is_required = isStructural ? false : el.fieldRequired.checked;
        f.is_pii = isStructural ? false : el.fieldPii.checked;
        // Un salto de página nuevo nace con cabecera visible: es lo que había
        // siempre, y además sin título ni descripción no se pintaría igual.
        f.show_header = info.isPage ? el.fieldPageHeader.checked : true;

        if (info.hasOptions) {
            f.options = Array.from(el.optionsList.querySelectorAll('.option-input'))
                .map(i => ({ value: i.value.trim() }))
                .filter(o => o.value !== '');
        }

        if (info.isMatrix) {
            const mrows = el.fieldMatrixRows.value.split('\n').map(s => s.trim()).filter(Boolean);
            const mcols = el.fieldMatrixCols.value.split('\n').map(s => s.trim()).filter(Boolean);
            f.options = [
                ...mrows.map(r => ({ group: 'rows', value: r })),
                ...mcols.map(c => ({ group: 'cols', value: c })),
            ];
        }

        // F0.2: se parte de las validaciones que NO tienen control en este panel
        // (p. ej. max_files de un campo de archivo) y encima se escriben las que
        // sí. Antes `const v = {}` las borraba todas en silencio.
        const v = preservedValidations(f);
        if (info.isScale) {
            v.max = Number(el.fieldScaleMax.value) || 5;
            if (v.max < 2) v.max = 2;
            if (v.max > 10) v.max = 10;
        }
        if (info.validations && info.validations.includes('min') && el.valMin.value !== '') v.min = Number(el.valMin.value);
        if (info.validations && info.validations.includes('max') && el.valMax.value !== '') v.max = Number(el.valMax.value);
        if (info.validations && info.validations.includes('min_length') && el.valMinLen.value !== '') v.min_length = Number(el.valMinLen.value);
        if (info.validations && info.validations.includes('max_length') && el.valMaxLen.value !== '') v.max_length = Number(el.valMaxLen.value);

        // P10 — Campo de archivo: accept (extensiones), tamaño máx. y múltiple.
        // Solo se guarda lo que el usuario fijó; el servidor acota a sus límites.
        if (info.isFile) {
            const accept = (el.valFileAccept.value || '').trim();
            if (accept !== '') {
                v.accept = accept;
            }
            const maxMb = Number(el.valFileMaxSize.value);
            if (!isNaN(maxMb) && maxMb > 0) {
                v.max_size_mb = Math.min(Math.round(maxMb), Number(UPLOAD_LIMITS.max_size_mb) || maxMb);
            }
            if (el.valFileMultiple.checked) {
                v.multiple = true;
            }
        }

        // P8 — Quiz: respuesta correcta (solo con modo quiz y en preguntas
        // puntuables); barajado de opciones para tipos de opción.
        if (!isStructural && !['matrix', 'ranking', 'file'].includes(f.type)
            && FORM_SETTINGS.quiz && FORM_SETTINGS.quiz.enabled) {
            const correct = (el.quizAnswer.value || '').trim();
            if (correct !== '') v.correct = correct;
        }
        if (CHOICE_TYPES.includes(f.type) && el.fieldShuffleOps && el.fieldShuffleOps.checked) {
            v.randomize = true;
        }

        f.validations = isStructural ? {} : v;

        // Persistir el combinador elegido de la condición.
        if (f.logic && f.logic.rules && f.logic.rules.length) {
            f.logic.operator = currentLogicOperator();
        }

        refreshCard(f);
        markDirty();
    }

    function cloneField(f) {
        const c = JSON.parse(JSON.stringify(f));
        delete c.id;
        c._uid = 'field-' + (nextUid++);
        return c;
    }

    function duplicateField(uid) {
        const idx = fields.findIndex(f => f._uid === uid);
        if (idx === -1) return;
        pushHistory();
        const src = fields[idx];
        const clones = [cloneField(src)];

        if (src.type === 'section') {
            const newSectionUid = clones[0]._uid;
            for (let ci = idx + 1; ci < fields.length && fields[ci].parentId === uid; ci++) {
                const c = cloneField(fields[ci]);
                c.parentId = newSectionUid;
                clones.push(c);
            }
        }

        fields.splice(idx + 1, 0, ...clones);
        selectedId = clones[0]._uid;
        renderFieldCanvas();
        fillSettings(fields.find((x) => x._uid === selectedId));
        showToast('Campo duplicado', 'success');
    }

    function deleteField(uid) {
        const idx = fields.findIndex(f => f._uid === uid);
        if (idx === -1) return;
        pushHistory();
        const removed = [{ field: fields[idx], index: idx }];

        if (fields[idx].type === 'section') {
            fields.forEach((f, i) => {
                if (f.parentId === uid) removed.push({ field: f, index: i });
            });
        }

        selectedId = null;
        lastDeleted = removed.slice().sort((a, b) => a.index - b.index);
        // Eliminar de atrás hacia delante para conservar índices.
        removed.slice().sort((a, b) => b.index - a.index).forEach((r) => fields.splice(r.index, 1));
        clearSettings();
        showToast(removed.length > 1 ? 'Sección y sus preguntas eliminadas' : 'Elemento eliminado', 'dark', { label: 'Deshacer', onAction: undoDelete, duration: 5000 });
    }

    function deleteSelected() {
        if (selectedId === null) return;
        deleteField(selectedId);
    }

    function undoDelete() {
        if (!lastDeleted) return;
        lastDeleted.forEach((r) => {
            fields.splice(Math.min(r.index, fields.length), 0, r.field);
        });
        selectedId = lastDeleted[0] ? lastDeleted[0].field._uid : null;
        lastDeleted = null;
        renderFieldCanvas();
        if (selectedId) fillSettings(fields.find((x) => x._uid === selectedId));
        showToast('Elemento restaurado', 'success');
    }

    /* ---------- Add / palette ---------- */
    /* ---------- Modal de alta: cuadrícula de tipos (F1) ---------- */
    // Destino pendiente mientras el modal está abierto: { parentUid, atRoot, atIndex }.
    let pendingAddTarget = null;

    function openAddFieldModal(target) {
        if (!el.addFieldModal) return;
        pendingAddTarget = target || { parentUid: null };

        const insideSection = Boolean(pendingAddTarget.parentUid);
        // Dentro de una sección no se pueden insertar elementos estructurales:
        // el servidor rechaza que una sección anide otra y los saltos de página
        // son de raíz (BuilderController.php:123-133).
        el.addFieldModal.querySelectorAll('[data-vf-structural-part]').forEach((n) => {
            // setAttribute/removeAttribute explícitos, NO toggleAttribute():
            // toggleAttribute deja el atributo con valor "" y el selector de
            // app.css es [data-vf-structural-hidden] (por presencia). Con
            // toggleAttribute los tiles estructurales seguían visibles dentro
            // de una sección y al pulsarlos se insertaban en la raíz sin avisar.
            if (insideSection) {
                n.setAttribute('data-vf-structural-hidden', '1');
            } else {
                n.removeAttribute('data-vf-structural-hidden');
            }
        });
        el.addFieldHint.textContent = insideSection
            ? 'Elige el tipo de pregunta que quieres agregar dentro de esta sección.'
            : 'Elige el tipo de elemento que quieres agregar al formulario.';

        if (window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(el.addFieldModal).show();
        }
    }

    function bindAddFieldModal() {
        if (!el.addFieldModal) return;
        el.addFieldModal.querySelectorAll('.app-type-tile').forEach((tile) => {
            tile.addEventListener('click', () => {
                const target = pendingAddTarget || { parentUid: null };
                pendingAddTarget = null;
                if (window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(el.addFieldModal).hide();
                }
                addFieldByType(tile.dataset.type, target.parentUid, target.atRoot, target.atIndex);
            });
        });
        // Al cerrar sin elegir tipo se limpia el destino pendiente.
        el.addFieldModal.addEventListener('hidden.bs.modal', () => { pendingAddTarget = null; });

        // Medición del diálogo al abrirlo. NO arregla nada: solo anota el
        // rectángulo cuando el diálogo se queda medio fuera de la pantalla, que
        // es el síntoma que se reportó en móvil («se oscurece la pantalla y el
        // modal no aparece»). El arreglo es CSS (rejilla de dos columnas, tope
        // en `dvh` y anclaje arriba, en el bloque `@media` de `app.css`); esto
        // está para que, si vuelve a pasar, haya números en la consola en vez
        // de otra suposición. Con `top >= 0` y `height > 0` no dice nada, así que
        // un constructor que va bien no llena la consola.
        el.addFieldModal.addEventListener('show.bs.modal', () => {
            const dialogo = el.addFieldModal.querySelector('.modal-dialog');
            if (!dialogo) return;
            const r = dialogo.getBoundingClientRect();
            if (r.top < 0 || r.height < 120) {
                console.warn('[vanillaforms] el diálogo de «Agregar elemento» no cabe en la pantalla',
                    { top: Math.round(r.top), alto: Math.round(r.height), vh: window.innerHeight, ancho: Math.round(r.width) });
            }
        });
    }

    function addFieldByType(type, parentUid, atRoot, atIndex) {
        const info = typeInfo[type];
        const isPage = Boolean(info && info.isPage);
        const isStructural = Boolean(info && (info.isSection || isPage));
        if (isStructural) {
            // Los elementos estructurales son siempre de raíz: un «atIndex»
            // apuntaría al interior de una sección, donde el servidor lo
            // rechaza (BuilderController.php:123-133).
            parentUid = null;
            atIndex = null;
        }
        pushHistory();

        let defaultLabel = 'Nueva pregunta (' + (info ? info.label : type) + ')';
        if (info && info.isSection) defaultLabel = 'Nueva sección';
        // El salto de página vuelve a nacer con «Página N», como la sección nace
        // con «Nueva sección»: quien edita necesita ver de qué paso se trata en
        // el constructor y en el divisor del preview, y un nombre es más rápido
        // de reconocer que «Página» a secas. El N es el de pages que ya hay más
        // uno, no la posición en el formulario: las páginas también se
        // intercalan con las secciones y con el bloque implícito del principio,
        // así que el orden del lienzo no es el número del paso.
        //
        // Esto es contenido, no un rótulo del editor: se guarda y se publica
        // como cabecera del paso (con el interruptor encendido, que es el estado
        // por defecto). Por eso es un punto de partida para sobrescribir, no un
        // rótulo que el builder imponga: `label` es lo que escribió quien edita
        // y el fill no lo vuelve a fabricar. La migración 0012 sigue limpiando el
        // relleno que las instalaciones anteriores tenían guardado.
        if (isPage) defaultLabel = 'Página ' + (fields.filter((x) => x.type === 'page').length + 1);

        const f = {
            _uid: 'field-' + (nextUid++),
            id: null,
            type: type,
            label: defaultLabel,
            placeholder: '',
            help_text: '',
            is_required: false,
            is_pii: false,
            show_header: true,
            options: info && info.hasOptions ? [{ value: 'Opción 1' }, { value: 'Opción 2' }] : [],
            validations: (type === 'scale' || type === 'star') ? { max: 5 } : (type === 'nps' ? { min: 0, max: 10 } : {}),
            parentId: parentUid || null,
            logic: null,
        };

        if (atIndex !== null && atIndex !== undefined) {
            // Punto exacto: «+» colgado de una hija de sección. El índice ya
            // viene calculado, y como las hijas de una sección son contiguas no
            // hay que re derivarlo.
            fields.splice(atIndex, 0, f);
        } else if (parentUid) {
            let lastChildIdx = -1;
            fields.forEach((g, i) => { if (g.parentId === parentUid) lastChildIdx = i; });
            const secIdx = fields.findIndex((g) => g._uid === parentUid);
            const insertAt = lastChildIdx >= 0 ? lastChildIdx + 1 : (secIdx >= 0 ? secIdx + 1 : fields.length);
            fields.splice(insertAt, 0, f);
        } else if (atRoot !== null && atRoot !== undefined) {
            fields.splice(insertPosForRoot(atRoot), 0, f);
        } else {
            fields.push(f);
        }

        selectedId = f._uid;
        renderFieldCanvas();
        fillSettings(f);
        const newNode = el.canvas.querySelector('[data-uid="' + f._uid + '"]');
        if (newNode) {
            newNode.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            el.canvas.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    /* ---------- Live preview ---------- */
    function previewBody(f) {
        const info = typeInfo[f.type] || {};
        switch (f.type) {
            case 'textarea':
                return '<textarea class="form-control" rows="2" placeholder="' + esc(f.placeholder || '') + '"></textarea>';
            case 'select':
                return '<select class="form-select"><option value="">Selecciona…</option>' + optionsHtml(f.options) + '</select>';
            case 'radio':
                return '<div class="d-flex flex-column gap-1">' + f.options.map(o =>
                    '<div class="form-check"><input class="form-check-input" type="radio"><label class="form-check-label">' + esc(o.value) + '</label></div>'
                ).join('') + '</div>';
            case 'checkbox':
                return '<div class="d-flex flex-column gap-1">' + f.options.map(o =>
                    '<div class="form-check"><input class="form-check-input" type="checkbox"><label class="form-check-label">' + esc(o.value) + '</label></div>'
                ).join('') + '</div>';
            case 'number':
                return '<input type="number" class="form-control" placeholder="' + esc(f.placeholder || '') + '">';
            case 'email':
                return '<input type="email" class="form-control" placeholder="' + esc(f.placeholder || '') + '">';
            case 'url':
                return '<input type="url" class="form-control" placeholder="' + esc(f.placeholder || 'https://') + '">';
            case 'tel':
                return '<input type="tel" class="form-control" placeholder="' + esc(f.placeholder || '') + '">';
            case 'date':
                return '<input type="date" class="form-control">';
            case 'time':
                return '<input type="time" class="form-control">';
            case 'datetime-local':
                return '<input type="datetime-local" class="form-control">';
            case 'range':
                const rv = f.validations || {};
                const ua = rv.min != null ? Number(rv.min) : 0;
                const ub = rv.max != null ? Number(rv.max) : 100;
                return '<div class="d-flex align-items-center gap-3">' +
                    '<input type="range" class="form-range flex-grow-1" min="' + ua + '" max="' + ub + '" value="' + Math.round((ua + ub) / 2) + '">' +
                    '<span class="vf-range-val">' + Math.round((ua + ub) / 2) + '</span></div>';
            case 'scale':
                const m = Number(f.validations.max) || 5;
                return '<div class="d-flex flex-wrap gap-2">' + Array.from({length: m}, (_, i) =>
                    '<input type="radio" class="btn-check" name="pv' + f._uid + '" disabled><label class="btn btn-outline-primary btn-sm vf-scale-num">' + (i + 1) + '</label>'
                ).join('') + '</div>';
            case 'matrix':
                const pmr = f.options.filter(o => o.group === 'rows').map(o => o.value);
                const pmc = f.options.filter(o => o.group === 'cols').map(o => o.value);
                if (!pmr.length || !pmc.length) {
                    return '<p class="text-muted small mb-0">Configura filas y columnas en el panel.</p>';
                }
                return '<div class="vf-matrix-table-wrap table-responsive">' +
                    '<table class="table table-sm mb-0 vf-matrix-table">' +
                        '<thead><tr><th class="vf-matrix-empty"></th>' +
                            pmc.map(c => '<th scope="col" class="small fw-semibold text-center">' + esc(c) + '</th>').join('') +
                        '</tr></thead>' +
                        '<tbody>' + pmr.map(r =>
                            '<tr><th scope="row" class="small fw-normal text-start">' + esc(r) + '</th>' +
                            pmc.map(c => '<td class="text-center">' +
                                '<label class="vf-matrix-cell" title="' + esc(c) + '">' +
                                '<input type="radio" name="pv' + f._uid + '" disabled></label>' +
                            '</td>').join('') +
                            '</tr>'
                        ).join('') + '</tbody>' +
                    '</table></div>';
            case 'file': {
                const fv = f.validations || {};
                return '<input type="file" class="form-control"' +
                    (fv.accept ? ' accept="' + esc(fv.accept) + '"' : '') +
                    (fv.multiple ? ' multiple' : '') + ' disabled>' +
                    '<p class="text-muted small mt-2 mb-0">Máx. ' +
                    esc(String(Number(fv.max_size_mb) || UPLOAD_LIMITS.max_size_mb)) + ' MB por archivo' +
                    (fv.accept ? ' · ' + esc(fv.accept) : '') + '</p>';
            }
            case 'nps':
                // Las previews de NPS, estrella, matriz y ranking reutilizan el
                // markup del fill (`nps-option`/`scale-btn`, `star-option`/
                // `star-btn`, `vf-matrix-cell`, `vf-ranking-*`). Antes cada una
                // traía su propio HTML —con estilos inline y clases que no
                // existían, como `vf-nps-num` o `vf-matrix-preview`— así que la
                // preview se veía distinta del formulario real en los cuatro
                // tipos, que son justo los que más cambian de aspecto.
                return '<div class="nps-selector d-flex flex-wrap gap-1">' + [0,1,2,3,4,5,6,7,8,9,10].map(i =>
                    '<label class="nps-option">' +
                    '<input type="radio" name="pv' + f._uid + '" class="visually-hidden" disabled>' +
                    '<span class="scale-btn" tabindex="-1">' + i + '</span></label>'
                ).join('') + '</div>';
            case 'star':
                const pms = Number(f.validations.max) || 5;
                return '<div class="star-selector d-flex gap-1">' + Array.from({length: pms}, () =>
                    '<label class="star-option">' +
                    '<input type="radio" name="pv' + f._uid + '" class="visually-hidden" disabled>' +
                    '<i class="fa-solid fa-star star-btn" tabindex="-1"></i></label>'
                ).join('') + '</div>';
            case 'ranking':
                const prk = f.options.map(o => o.value);
                if (!prk.length) {
                    return '<p class="text-muted small mb-0">Agrega opciones en el panel.</p>';
                }
                return '<div class="vf-ranking-list d-flex flex-column gap-2">' + prk.map((o, i) =>
                    '<div class="d-flex align-items-center gap-2">' +
                        '<span class="badge text-bg-secondary vf-ranking-pos">' + (i + 1) + '</span>' +
                        '<span class="small flex-grow-1">' + esc(o) + '</span>' +
                        '<input type="number" class="form-control form-control-sm vf-ranking-input" min="1" max="' + prk.length + '" placeholder="1..' + prk.length + '" disabled>' +
                    '</div>'
                ).join('') + '</div>';
            default:
                return '<input type="text" class="form-control" placeholder="' + esc(f.placeholder || '') + '">';
        }
    }

    function previewCondNote(f) {
        if (f.logic && f.logic.rules && f.logic.rules.length) {
            const join = f.logic.operator === 'any' ? ' o ' : ' y ';
            const parts = f.logic.rules.map((r) => {
                const src = fields.find((x) => x._uid === r.field_id);
                const label = src ? (src.label || (typeInfo[src.type] ? typeInfo[src.type].label : 'campo')) : '?';
                return label + ' ' + (OP_TEXTS[r.op] || r.op) + (r.value ? ' "' + r.value + '"' : '');
            });
            return '<div class="vf-cond-chip"><i class="fa-solid fa-code-branch me-1"></i>Se muestra si: ' + esc(parts.join(join)) + '</div>';
        }
        return '';
    }

    function buildPreviewHtml() {
        const title = esc(el.title.value.trim() || 'Formulario sin título');
        const desc = el.desc.value.trim();
        const childrenOf = {};
        fields.forEach((f) => {
            if (f.parentId) (childrenOf[f.parentId] = childrenOf[f.parentId] || []).push(f);
        });
        const roots = fields.filter((f) => !f.parentId);

        let html = '<div class="text-center pt-2 pb-3">' +
            '<h1 class="h3 fw-bold vf-title mb-1">' + title + '</h1>' +
            (desc ? '<p class="text-muted mb-0 vf-desc">' + esc(desc) + '</p>' : '') +
        '</div><div class="vf-fields d-flex flex-column gap-3 mt-2">';

        roots.forEach((f) => {
            if (f.type === 'page') {
                // El preview reutiliza el markup del fill (`.vf-page-head`), como
                // ya hacía con el de sección: aquí era una insignia centrada, un
                // tercer diseño que no se parecía en nada a lo que sale publicado.
                // Y con `show_header` apagado no se pinta, que es lo que hace el
                // formulario final: el preview no puede prometer una cabecera que
                // luego no aparece.
                //
                // Y el MISMO criterio que el fill, que es lo segundo que se
                // olvidaba aquí: el formulario publicado solo pinta la cabecera si
                // el paso tiene título o descripción, porque una caja con el icono
                // y nada dentro no dice de qué paso se trata. Este preview pintaba
                // la caja vacía igual, así que se veía el icono en el constructor y
                // en el formulario publicado no había nada: el interruptor parecía
                // no hacer nada. Se comparan los DOS con `trim()` porque el fill
                // también los recorta: una etiqueta de solo espacios es sin título.
                const titulo = String(f.label || '').trim();
                const desc = String(f.help_text || '').trim();
                const sinCabecera = f.show_header === false || (titulo === '' && desc === '');
                if (!sinCabecera) {
                    // Título primero y icono al final, igual que en el fill: la
                    // fila reparte el sobrante con `space-between` y el icono cae
                    // a la derecha sin que ninguno de los dos tenga que empujarse
                    // con un margen. Mismo criterio que el encabezado de sección,
                    // para que un paso y una sección se lean igual.
                    html += '<div class="vf-page-head">' +
                        '<div>' +
                            (titulo ? '<h2 class="vf-page-title h5 fw-bold mb-0">' + esc(titulo) + '</h2>' : '') +
                            (desc ? '<p class="vf-page-desc text-muted mt-1 mb-0">' + esc(desc) + '</p>' : '') +
                        '</div>' +
                        '<span class="vf-page-icon"><i class="fa-solid fa-file-lines"></i></span>' +
                        '</div>';
                }
                html += '<div class="vf-page-break"><span class="text-muted small">— siguiente paso —</span></div>';
                return;
            }
            if (f.type === 'section') {
                // Misma estructura que el encabezado del fill (`.vf-section-inner`
                // con título a la izquierda e icono a la derecha). El preview
                // iba centrado con el icono encima, que es justo lo que hace
                // distinto al formulario que se va a ver.
                html += '<div class="vf-section my-2' + (f.logic && f.logic.rules && f.logic.rules.length ? ' vf-preview-dim' : '') + '">' +
                    '<div class="vf-section-inner">' +
                        '<div>' +
                            '<h2 class="vf-section-title h5 fw-bold mb-1">' + esc(f.label || 'Sin título') + '</h2>' +
                            (f.help_text ? '<p class="vf-section-desc text-muted mb-0">' + esc(f.help_text) + '</p>' : '') +
                        '</div>' +
                        '<span class="vf-section-icon"><i class="fa-solid fa-heading"></i></span>' +
                    '</div>' +
                    previewCondNote(f) +
                    '<div class="d-flex flex-column gap-3 mt-3">' +
                        (childrenOf[f._uid] || []).map((child) => previewCard(child, f)).join('') +
                    '</div></div>';
                return;
            }
            html += previewCard(f, null);
        });

        // El botón de enviar es el markup REAL de `views/fill/show.php`
        // (`.vf-submit-row` + `.vf-submit` + `.vf-submit-label`). Antes la
        // preview pintaba su propio `<button class="btn btn-lg
        // app-btn-primary">`: `app-btn-primary` pone fondo y NO pone color, y
        // al no ser `.vf-submit` tampoco heredaba su texto en claro, así que en
        // el constructor salía un botón con el texto del navegador (negro) que
        // además no se parecía en nada al que se publica. `type="button"`
        // porque en la preview no hay nada que enviar y el lienzo sí tiene
        // formularios reales cerca. El `id` del fill tampoco se copia: en la
        // página vive un `#vfSubmit` y duplicarlo aquí sería HTML inválido.
        html += '</div>' +
            '<div class="vf-submit-row flip-in">' +
            '<button type="button" class="vf-submit">' +
            '<span class="vf-submit-label"><i class="fa-solid fa-paper-plane me-2"></i>Enviar</span>' +
            '</button>' +
            '</div>';
        return html;
    }

    function previewCard(f, parent) {
        const cardClass = f.logic && f.logic.rules && f.logic.rules.length ? 'vf-preview-dim' : '';
        return '<div class="card border-0 shadow-sm app-fill-card ' + cardClass + '">' +
            '<div class="card-body">' +
                '<label class="vf-label fw-semibold">' + esc(f.label || 'Sin título') + ' ' + (f.is_required ? '<span class="text-danger">*</span>' : '') + '</label>' +
                previewBody(f) +
                previewCondNote(f) +
                (f.help_text ? '<div class="form-text">' + esc(f.help_text) + '</div>' : '') +
            '</div></div>';
    }

    function openLivePreview() {
        const content = document.getElementById('livePreviewContent');
        if (content) content.innerHTML = buildPreviewHtml();
        const modalEl = document.getElementById('livePreviewModal');
        if (modalEl && window.bootstrap) {
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }
    }

    /* ---------- Vista previa embebida (F3) ---------- */

    // Pinta el preview SOLO si el panel está visible: repintar un `d-none` es
    // trabajo de más, y la maqueta contiene inputs sin estado que no hace falta
    // construir si nadie los mira.
    function renderCanvasPreview() {
        if (!el.canvasPreview || !el.canvasPreviewContent) return;
        if (el.canvasPreview.classList.contains('d-none')) return;
        el.canvasPreviewContent.innerHTML = buildPreviewHtml();
    }

    function setCanvasPreviewOpen(open) {
        if (!el.canvasPreview || !el.btnCanvasPreview) return;
        el.canvasPreview.classList.toggle('d-none', !open);
        el.btnCanvasPreview.setAttribute('aria-pressed', open ? 'true' : 'false');
        el.btnCanvasPreview.classList.toggle('app-btn-active', open);
        el.btnCanvasPreview.title = open
            ? 'Ocultar la vista previa dentro del lienzo'
            : 'Mostrar la vista previa dentro del lienzo';
        if (open) renderCanvasPreview();
    }

    function toggleCanvasPreview() {
        const hidden = el.canvasPreview ? el.canvasPreview.classList.contains('d-none') : false;
        setCanvasPreviewOpen(hidden);
    }

    /* ---------- Save ---------- */
    /* ---------- Estado de guardado (autosave) ---------- */
    let dirty = false;
    let saving = false;
    let pendingSave = false;
    let lastSavedJson = null;
    let autoSaveTimer = null;

    function setStatus(text, cls) {
        if (!el.saveStatus) return;
        el.saveStatus.textContent = text;
        el.saveStatus.className = 'small align-self-center ms-2 ' + (cls || 'text-muted');
    }

    /**
     * Serializa al contrato de `POST /forms/{id}/builder/save`.
     *
     * OJO: el estado en memoria usa `parentId` (camelCase) y el servidor lee
     * `parent_id` (snake_case). Antes se mandaba `fields` en crudo, así que
     * la clave llegaba `undefined`, el servidor guardaba TODO como campos
     * sueltos y al reabrir el builder la sección aparecía vacía con sus
     * preguntas fuera. El mapeo es explícito a propósito: es la única
     * frontera entre las dos convenciones de nombres.
     */
    function buildPayload() {
        return {
            title: el.title.value.trim(),
            description: el.desc.value.trim(),
            status: FORM_STATUS,
            settings: FORM_SETTINGS,
            fields: fields.map((f) => ({
                id: f.id ?? null,
                _uid: f._uid,
                type: f.type,
                label: f.label || '',
                placeholder: f.placeholder || '',
                help_text: f.help_text || '',
                is_required: Boolean(f.is_required),
                is_pii: Boolean(f.is_pii),
                show_header: Boolean(f.show_header),
                options: Array.isArray(f.options) ? f.options : [],
                validations: f.validations && typeof f.validations === 'object' ? f.validations : {},
                parent_id: f.parentId || null,
                logic: f.logic
                    ? {
                        operator: f.logic.operator === 'any' ? 'any' : 'all',
                        rules: (f.logic.rules || []).map((r) => ({
                            field_id: r.field_id, op: r.op, value: r.value,
                        })),
                    }
                    : null,
            })),
        };
    }

    function markDirty() {
        dirty = true;
        pendingSave = true;
        setStatus('Sin guardar…', 'text-warning');
        clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(() => { autoSaveTimer = null; autoSave(); }, 2000);
    }

    async function autoSave() {
        if (!dirty || saving) return;
        await saveStructure({ silent: true });
        if (!dirty) setStatus('Guardado automáticamente', 'text-success');
        else setStatus('Esperando guardado…', 'text-warning');
    }

    async function saveStructure(opts) {
        opts = opts || {};
        // M1: serializar guardados — si hay uno en vuelo, se deja pendiente
        // y se reaparece al terminar con la última versión.
        if (saving) {
            pendingSave = true;
            return;
        }
        saving = true;

        let original = null;
        /* El botón se repone por `pintarBoton(false)`, y desde aquí abajo SOLO
         * hay una salida que lo hace. Antes el atajo de «no hay cambios» salía
         * antes del `try`, así que se saltaba el `finally` que lo repone: el
         * segundo clic sin cambios lo dejaba apagado con el spinner para
         * siempre, sin error y sin red. La puerta única es lo que evita que el
         * próximo atajo repita el fallo; lo vigila
         * `BuilderSaveButtonTest::testNingunaSalidaSeSaltaLaReposicionDelBoton`. */
        const pintarBoton = function (guardando) {
            if (opts.silent || !el.saveBtn) {
                return;
            }
            if (guardando) {
                original = el.saveBtn.innerHTML;
                el.saveBtn.disabled = true;
                el.saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Guardando…';
                return;
            }
            if (original !== null) {
                el.saveBtn.disabled = false;
                el.saveBtn.innerHTML = original;
                original = null;
            }
        };
        pintarBoton(true);
        setStatus('Guardando…', 'text-secondary');

        const payload = buildPayload();
        const payloadJson = JSON.stringify(payload);

        // M8: omitir el guardado si la estructura no cambió desde el último éxito.
        if (payloadJson === lastSavedJson) {
            dirty = false;
            pendingSave = false;
            setStatus('Guardado ✓', 'text-success');
            if (!opts.silent) showToast('Sin cambios por guardar', 'success');
            pintarBoton(false);
            saving = false;
            return;
        }

        try {
            const res = await fetch('/forms/' + FORM_ID + '/builder/save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: payloadJson,
            });
            const data = await res.json();
            if (data.ok) {
                lastSavedJson = payloadJson;
                dirty = false;
                pendingSave = false;
                el.titleText.textContent = payload.title;
                setStatus('Guardado ✓', 'text-success');
                if (!opts.silent) showToast('Estructura guardada correctamente', 'success');
            } else {
                dirty = true;
                pendingSave = true;
                setStatus('Error al guardar', 'text-danger');
                if (!opts.silent) showToast(data.error || 'Error al guardar', 'danger');
            }
        } catch (e) {
            dirty = true;
            pendingSave = true;
            setStatus('Error de conexión', 'text-danger');
            if (!opts.silent) showToast('Error de conexión al guardar', 'danger');
        } finally {
            pintarBoton(false);
            saving = false;
            // M1: si hubo cambios mientras se guardaba, relanzar el autosave
            if (pendingSave) {
                clearTimeout(autoSaveTimer);
                autoSaveTimer = setTimeout(() => { autoSaveTimer = null; autoSave(); }, 800);
            }
        }
    }

    function showToast(msg, type, opts) {
        opts = opts || {};
        let wrap = document.getElementById('toastWrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'toastWrap';
            wrap.className = 'app-toast-wrap';
            document.body.appendChild(wrap);
        }
        const t = document.createElement('div');
        t.className = 'app-toast text-bg-' + type + ' shadow';
        t.innerHTML =
            '<span><i class="fa-solid ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark') + ' me-2"></i>' + esc(msg) + '</span>' +
            (opts.label ? '<button type="button" class="app-toast-action" title="Deshacer">' + esc(opts.label) + '</button>' : '');
        wrap.appendChild(t);
        setTimeout(() => t.classList.add('show'), 20);
        const timer = setTimeout(() => dismiss(t), opts.duration || 2600);
        if (opts.onAction) {
            t.querySelector('.app-toast-action').addEventListener('click', () => {
                clearTimeout(timer);
                dismiss(t);
                opts.onAction();
            });
        }
    }

    function dismiss(t) {
        t.classList.remove('show');
        setTimeout(() => t.remove(), 300);
    }

    /* ---------- Keyboard shortcuts ---------- */
    function setupShortcuts() {
        document.addEventListener('keydown', (e) => {
            const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable;

            if ((e.ctrlKey || e.metaKey) && e.key === 'z') {
                e.preventDefault();
                undo();
                return;
            }
            if ((e.ctrlKey || e.metaKey) && (e.key === 'Z' || (e.ctrlKey && e.key === 'y'))) {
                e.preventDefault();
                redo();
                return;
            }
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
                e.preventDefault();
                saveStructure();
                return;
            }
            if ((e.ctrlKey || e.metaKey) && (e.key === 'd' || e.key === 'D')) {
                if (typing) return;
                e.preventDefault();
                if (selectedId !== null) duplicateField(selectedId);
                return;
            }
            if (!typing && (e.key === 'Delete' || e.key === 'Backspace') && selectedId !== null) {
                e.preventDefault();
                deleteSelected();
                return;
            }
            if (e.key === 'Escape') {
                const modalEl = document.getElementById('livePreviewModal');
                if (modalEl && window.bootstrap && modalEl.classList.contains('show')) {
                    bootstrap.Modal.getInstance(modalEl).hide();
                    return;
                }
                if (selectedId !== null) clearSettings();
            }
        });
    }

    /* ---------- Sortable (anidado) ---------- */
    function destroySortables() {
        sortables.forEach((s) => { try { s.destroy(); } catch (e) { /* noop */ } });
        sortables = [];
    }

    function initSortables() {
        if (!window.Sortable) {
            return;
        }
        const opts = {
            animation: 150,
            ghostClass: 'app-ghost',
            group: { name: 'builder', pull: true, put: true },
            handle: '.app-field-drag',
            // F1: el «+» ya no es hijo de la lista (cuelga de su tarjeta), pero
            // se mantiene el filtro como red de seguridad ante cualquier
            // versión de Sortable que evalúe descendientes.
            filter: '.app-canvas-add', // los botones «+» no se arrastran
            onEnd() {
                syncFieldsFromDom();
            },
        };
        sortables.push(Sortable.create(el.canvas, opts));
        document.querySelectorAll('.app-section-children').forEach((sc) => {
            sortables.push(Sortable.create(sc, opts));
        });
    }

    // Reconstruye el array plano en ORDEN DE LECTURA a partir del DOM anidado
    // (nivel superior, y los hijos justo después de su sección).
    function syncFieldsFromDom() {
        pushHistory();
        const byUid = {};
        fields.forEach((f) => { byUid[f._uid] = f; });
        const list = [];
        Array.from(el.canvas.children).forEach((top) => {
            const uid = top.dataset.uid;
            if (uid && byUid[uid]) {
                byUid[uid].parentId = null;
                list.push(byUid[uid]);
            }
            if (top.classList.contains('app-canvas-section')) {
                const sc = top.querySelector('.app-section-children');
                if (sc) {
                    Array.from(sc.children).forEach((child) => {
                        const cid = child.dataset.uid;
                        if (cid && byUid[cid]) {
                            byUid[cid].parentId = uid;
                            list.push(byUid[cid]);
                        }
                    });
                }
            }
        });
        fields = list;
    }

    /* ---------- init ---------- */
    function init() {
        el.paletteItems.forEach(item => {
            item.addEventListener('dragstart', e => {
                e.dataTransfer.setData('text/plain', item.dataset.type);
                item.classList.add('dragging');
            });
            item.addEventListener('dragend', e => item.classList.remove('dragging'));
            item.addEventListener('click', () => addFieldByType(item.dataset.type));
        });

        // Palette soltable: el destino detecta a qué sección agregar.
        el.canvas.addEventListener('dragover', e => e.preventDefault());
        el.canvas.addEventListener('drop', e => {
            e.preventDefault();
            const type = e.dataTransfer.getData('text/plain');
            if (!type || !typeInfo[type]) return;
            let parentUid = null;
            const childrenWrap = e.target.closest('.app-section-children');
            if (childrenWrap) {
                parentUid = childrenWrap.dataset.parentUid || null;
            } else {
                const section = e.target.closest('.app-canvas-section');
                if (section) parentUid = section.dataset.uid || null;
            }
            addFieldByType(type, parentUid);
        });

        // Delegación de tools (duplicar/configurar/eliminar) — UNA sola vez
        // (nunca dentro de renderFieldCanvas)
        el.canvas.addEventListener('click', (e) => {
            const btn = e.target.closest('.app-tool-btn');
            if (!btn) return;
            const toolNode = btn.closest('.app-canvas-field');
            const uid = toolNode && toolNode.dataset.uid;
            if (btn.dataset.act === 'duplicate') {
                duplicateField(uid);
            } else if (btn.dataset.act === 'settings') {
                openFieldSettings(uid);
            } else if (btn.dataset.act === 'delete') {
                deleteField(uid);
            }
        });

        // Selector de fuente -> operadores y valor acordes al tipo.
        el.logicField.addEventListener('change', () => {
            const src = fields.find((x) => x._uid === el.logicField.value);
            if (src) {
                renderLogicOps(src);
                renderLogicValueInput(src);
            }
        });
        el.logicOp.addEventListener('change', () => {
            const f = fields.find((x) => x._uid === selectedId);
        });
        el.logicValue.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                addLogicRule();
            }
        });

        el.saveBtn.addEventListener('click', saveStructure);
        el.livePreviewBtn.addEventListener('click', openLivePreview);
        if (el.btnCanvasPreview) {
            el.btnCanvasPreview.addEventListener('click', toggleCanvasPreview);
        }
        el.btnDelete.addEventListener('click', deleteSelected);
        el.btnUndo.addEventListener('click', undo);
        el.btnRedo.addEventListener('click', redo);

        // F1 (rediseño): el «+» de la barra y el del lienzo vacío agregan al
        // final (sin parentUid) a través de la cuadrícula de tipos.
        [el.btnAddQuestion, el.btnEmptyAdd].forEach((btn) => {
            if (btn) {
                btn.addEventListener('click', () => openAddFieldModal({ parentUid: null }));
            }
        });

        // «Abrir» el formulario público (CSP: sin onclick inline).
        const previewBtn = document.getElementById('btnPreviewUrl');
        if (previewBtn) {
            previewBtn.addEventListener('click', () => {
                if (appEl.dataset.slug) {
                    window.open('/f/' + appEl.dataset.slug, '_blank');
                }
            });
        }

        // P1 — Sugerencia de PII. `fields.is_pii` es lo único que abre la puerta
        // del enmascarado (App\Services\Pii), y hay que activarlo a mano, así que
        // el fallo típico es que nadie se acuerda y el nombre de un titular queda
        // en claro para todo el mundo. Aquí se AVISA, no se decide: una pregunta
        // puede llamarse «Nombre de la empresa» o «¿Qué nombre le pones a tu
        // proyecto?» y no ser un dato personal en ninguno de los dos casos, así
        // que marcar solo es del dueño. Por eso nunca se auto-marca.
        // El patrón (`PII_HINT_RE`) y el estado están declarados arriba del
        // módulo, no aquí: `updatePiiSuggestion()` se llama desde
        // `applySettings()` y esa corre en el arranque.
        function updatePiiSuggestion() {
            const wrap = document.getElementById('piiSuggestWrap');
            const text = document.getElementById('piiSuggestText');
            if (!wrap || !text || !el.fieldPii || !el.fieldPiiWrap) return;
            // Una sección o una página no guardan respuestas: el servidor les
            // fuerza `is_pii = false` y el control se esconde, así que tampoco
            // hay nada que sugerir.
            if (el.fieldPiiWrap.classList.contains('d-none')) {
                wrap.classList.add('d-none');
                return;
            }
            const label = el.fieldLabel.value.trim();
            const m = PII_HINT_RE.exec(label);
            const hit = m ? m[0] : null;
            if (!hit || el.fieldPii.checked) {
                wrap.classList.add('d-none');
                piiDismissedLabel = null;
                return;
            }
            // «No es» vale para la etiqueta que se estaba mirando. Si se
            // reescribe, la pregunta vuelve a ser otra y se vuelve a preguntar.
            if (piiDismissedLabel !== null && piiDismissedLabel !== label) {
                piiDismissedLabel = null;
            }
            if (piiDismissedLabel === label) {
                wrap.classList.add('d-none');
                return;
            }
            text.textContent = 'La pregunta dice «' + hit + '». Si la respuesta identifica a '
                + 'alguien, actívala para que salga enmascarada.';
            wrap.classList.remove('d-none');
        }

        if (el.fieldLabel) el.fieldLabel.addEventListener('input', updatePiiSuggestion);
        if (el.fieldPii) el.fieldPii.addEventListener('change', updatePiiSuggestion);
        /**
         * El interruptor de la cabecera del paso solo decide SI se pinta, nunca
         * QUÉ dice: el título y la descripción son contenido y salen de lo que
         * escribió el editor. Un salto de página nace sin título, así que el
         * caso por defecto —encendido y sin escribir nada— es exactamente el
         * que no tiene nada que pintar, y se veía como un interruptor roto: se
         * movía, se guardaba, y en el formulario publicado no había cabecera.
         * Sin esta frase, la única pista es que el ajuste no hace nada.
         *
         * El preview y el fill aplican el MISMO criterio (ver `previewBody()`):
         * sin título ni descripción no se pinta caja, ni con icono.
         */
        function updatePageHeaderHint() {
            const hint = document.getElementById('pageHeaderHint');
            if (!hint || !el.fieldPageHeader || !el.fieldPageHeaderWrap) return;
            // El control entero se esconde en los tipos que no son salto de
            // página, y en una sección no hay cabecera que apagar.
            if (el.fieldPageHeaderWrap.classList.contains('d-none')) {
                hint.classList.add('d-none');
                return;
            }
            const sinContenido = el.fieldLabel.value.trim() === ''
                && el.fieldHelp.value.trim() === '';
            if (!el.fieldPageHeader.checked || !sinContenido) {
                hint.classList.add('d-none');
                return;
            }
            hint.classList.remove('d-none');
        }
        const btnSuggest = document.getElementById('btnPiiSuggest');
        if (btnSuggest) {
            btnSuggest.addEventListener('click', () => {
                el.fieldPii.checked = true;
                updatePiiSuggestion();
                applySettings();
            });
        }
        const btnDismiss = document.getElementById('btnPiiDismiss');
        if (btnDismiss) {
            btnDismiss.addEventListener('click', () => {
                piiDismissedLabel = el.fieldLabel.value.trim();
                updatePiiSuggestion();
            });
        }

        // Edición en vivo: ya no hay botón «Aplicar», se persiste mientras se teclea.
        ['fieldLabel', 'fieldPlaceholder', 'fieldHelp'].forEach((k) => {
            el[k].addEventListener('input', applySettings);
        });
        // El aviso de la cabecera depende del título y de la descripción, que se
        // teclean: sin escucharlos solo se refrescaría al abrir el panel y se
        // quedaría diciendo que no hay nada que mostrar con el título ya escrito.
        ['fieldLabel', 'fieldHelp'].forEach((k) => {
            el[k].addEventListener('input', updatePageHeaderHint);
        });
        if (el.fieldPageHeader) el.fieldPageHeader.addEventListener('change', updatePageHeaderHint);
        ['fieldScaleMax', 'valMin', 'valMax', 'valMinLen', 'valMaxLen'].forEach((k) => {
            el[k].addEventListener('input', applySettings);
        });        ['fieldMatrixRows', 'fieldMatrixCols'].forEach((k) => {
            el[k].addEventListener('input', applySettings);
        });
        el.fieldRequired.addEventListener('change', applySettings);
        el.settingsForm.addEventListener('input', (e) => {
            if (e.target.classList.contains('option-input')) applySettings();
        });

        // Controles que applySettings() LEE pero que nadie escuchaba: sin esto
        // se escribían en el estado en memoria, sí, pero nada llamaba a
        // applySettings() y por tanto el autosave seguía guardando la
        // validación anterior. El síntoma era «no guarda la respuesta
        // correcta» (y, con el mismo descuido, tampoco la aleatorización ni los
        // límites de archivo): tecleabas, el input se veía bien y al recargar
        // había desaparecido. El servidor nunca estuvo en medio — la clave sí
        // viaja en validations; lo que faltaba era el disparador.
        ['quizAnswer', 'valFileAccept', 'valFileMaxSize'].forEach((k) => {
            if (el[k]) el[k].addEventListener('input', applySettings);
        });
        // Las casillas no dependen de 'input' para el guardado: 'change' es el
        // evento canónico del checkbox. `fieldPii` y `fieldPageHeader` son los
        // DOS interruptores de applySettings() que faltaban aquí, y los dos
        // fallaban igual: el interruptor se movía en el input, applySettings()
        // no corría, `f.<flag>` conservaba el valor del servidor y el autosave
        // guardaba exactamente lo que ya había. PII solo se guardaba si se
        // pulsaba el botón «Sí, es dato personal» (que sí llama a
        // applySettings), y el interruptor del salto de página no tenía
        // ninguna otra vía: no se guardaba nunca. Sin error, sin aviso y sin
        // diferencia en el payload — el peor sitio para un fallo de guardado.
        // La lista de flags con su control está en `TOP_LEVEL_FLAG_CONTROL`,
        // y `BuilderValidationsContractTest` ata las dos direcciones.
        ['fieldShuffleOps', 'valFileMultiple', 'fieldPii', 'fieldPageHeader'].forEach((k) => {
            if (el[k]) el[k].addEventListener('change', applySettings);
        });

        el.btnAddOption.addEventListener('click', () => {
            addOptionRow('', 'Opción ' + (el.optionsList.children.length + 1));
            applySettings();
        });

        const addLogicBtn = document.getElementById('btnAddLogic');
        if (addLogicBtn) addLogicBtn.addEventListener('click', addLogicRule);

        el.title.addEventListener('input', () => {
            el.titleText.textContent = el.title.value || 'Sin título';
            markDirty();
        });
        el.desc.addEventListener('input', markDirty);

        setupShortcuts();

        // ----- Configuración del formulario (P1) -----
        // bootstrap.bundle se carga en el layout DESPUÉS de builder.js, así que
        // el acceso a bootstrap.Modal se resuelve de forma perezosa al hacer
        // clic (para entonces el bundle ya está disponible).
        const settingsModalHolder = (() => {
            const elM = document.getElementById('formSettingsModal');
            return elM ? { el: elM, inst: null } : null;
        })();

        function ensureSettingsModal() {
            if (settingsModalHolder && !settingsModalHolder.inst && window.bootstrap) {
                settingsModalHolder.inst = bootstrap.Modal.getOrCreateInstance(settingsModalHolder.el);
            }
            return settingsModalHolder ? settingsModalHolder.inst : null;
        }

        const st = (id) => document.getElementById(id);

        function populateSettingsModal() {
            const s = FORM_SETTINGS;
            st('stPrimary').value = (s.theme && s.theme.primary) || '#0d6efd';
            st('stBackground').value = (s.theme && s.theme.background) || '#f6f6f6';
            st('stFont').value = (s.theme && s.theme.font) || '';
            st('stQuizEnabled').checked = !!(s.quiz && s.quiz.enabled);
            st('stRandQuestions').checked = !!(s.randomize && s.randomize.questions);
            // F5 — la clave del bloque es `auto`, no `enabled`: no entra en el
            // patron de los toggles de una clave de arriba.
            st('stAdvanceAuto').checked = !!(s.advance && s.advance.auto);
            st('stEmbedEnabled').checked = !!(s.embed && s.embed.enabled);
            st('stHoneypotEnabled').checked = !!(s.honeypot && s.honeypot.enabled);
            st('stAntiBotEnabled').checked = !!(s.anti_bot && s.anti_bot.enabled);
            updateAdvanceHint();
            paintThemePreview();
            checkFontLive();
        }

        // P1 — Vista previa del tema dentro de la propia modal. Los dos colores
        // se ven al instante sin salir del formulario, que es la razón por la
        // que la gente duda tanto de ellos. NO aplica la fuente a propósito: la
        // maqueta es solo de color; la tipografía elegida (una familia del
        // catálogo) se ve en el formulario publicado.
        function paintThemePreview() {
            const primary = st('stPrimary');
            const background = st('stBackground');
            const box = st('stThemePreview');
            if (!primary || !background || !box) return;
            const p = primary.value || '#0d6efd';
            const b = background.value || '#f6f6f6';
            box.style.backgroundColor = b;
            const btn = st('stThemePreviewBtn');
            if (btn) {
                btn.style.backgroundColor = p;
                btn.style.borderColor = p;
                btn.style.color = '#fff';
            }
            const bar = st('stThemePreviewBar');
            if (bar) bar.style.backgroundColor = p;
        }

        // La fuente ya no es texto libre: el <select> solo ofrece las familias
        // del catálogo (`FormSettings::fonts()`), que esta instalación sirve
        // desde /assets/fonts/. El aviso sigue existiendo porque es ESTADO (lo
        // comprueba BuilderHelpTooltipTest) y porque dice de dónde sale lo
        // elegido; un valor libre ya no puede llegar a la pantalla.
        function checkFontLive() {
            const input = st('stFont');
            const note = st('stFontNote');
            if (!input || !note) return;
            if (!input.value) {
                note.textContent = 'Predeterminada: la tipografía del panel.';
                note.className = 'form-text mb-0 text-muted';
                return;
            }
            note.textContent = '«' + input.value + '» se sirve desde esta instalación.';
            note.className = 'form-text mb-0 text-success';
        }

        /* Solo los bloques de DISEÑO. Los operativos (acceso, agenda, cupo,
         * avisos, webhook, gracias, revisión e idioma) se editan en la ficha del
         * formulario, con su propio POST.
         *
         * Y aquí está la razón de que sea una lista y no el objeto entero: el
         * servidor guarda con `FormSettings::merge(..., BUILDER_BLOCKS)`, y
         * `normalize()` arranca de los defaults. Si esto mandara el array
         * completo, cada «Guardar estructura» del constructor pondría los ocho
         * ajustes operativos de la ficha en sus valores de fábrica. */
        function collectSettingsFromModal() {
            const g = (id) => st(id);
            FORM_SETTINGS = {
                theme: {
                    primary: g('stPrimary').value || null,
                    background: g('stBackground').value || null,
                    font: g('stFont').value.trim() || null,
                },
                quiz: { enabled: g('stQuizEnabled').checked },
                randomize: { questions: g('stRandQuestions').checked },
                // F5 — dos claves? No: el bloque `advance` tiene una sola,
                // `auto`, asi que va aqui junto a los demas.
                advance: { auto: g('stAdvanceAuto').checked },
                embed: { enabled: g('stEmbedEnabled').checked },
                honeypot: { enabled: g('stHoneypotEnabled').checked },
                anti_bot: { enabled: g('stAntiBotEnabled').checked },
            };
        }

        /**
         * F5 — Explica cuando el ajuste hace algo y cuando no. Importa porque
         * un formulario de una sola pagina (que es el caso mas comun) tiene el
         * interruptor a mano y no ocurre NADA: sin esta frase, el ajuste parece
         * roto en lugar de inaplicable.
         */
        function updateAdvanceHint() {
            const hint = st('stAdvanceHint');
            const box = st('stAdvanceAuto');
            if (!hint || !box) return;
            if (!box.checked) {
                hint.textContent = '';
                return;
            }
            hint.textContent = 'Salta al siguiente paso al completar sus preguntas obligatorias. '
                + 'Solo en formularios con paginas.';
        }

        // Vista previa del tema y aviso de la fuente. Se engancha una sola vez al
        // arrancar el builder (no en cada apertura de la modal): los listeners
        // viven en los inputs, así que solo hay que leerlos.
        ['stPrimary', 'stBackground'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', paintThemePreview);
        });
        if (st('stFont')) st('stFont').addEventListener('change', checkFontLive);

        if (st('btnFormSettings') && settingsModalHolder) {
            st('btnFormSettings').addEventListener('click', () => {
                populateSettingsModal();
                const m = ensureSettingsModal();
                if (m) m.show();
            });
            st('btnSaveSettings').addEventListener('click', () => {
                collectSettingsFromModal();
                const m = ensureSettingsModal();
                if (m) m.hide();
                // El panel de la pregunta se rellena al abrirlo, y la visibilidad
                // del input de respuesta correcta depende de si el modo quiz está
                // activo. Si el owner lo acaba de encender en ESTE modal y el
                // drawer de la pregunta sigue abierto detrás, sin esto el input
                // seguiría oculto y el usuario vería un ajuste que no aparece.
                if (settingsModalOpen) {
                    const f = fields.find((x) => x._uid === selectedId);
                    if (f) fillSettings(f);
                }
                markDirty();
                showToast('Configuración aplicada. Se guardará en el siguiente guardado.', 'success');
            });
            // F5 — el texto de estado del avance automático cambia con el
            // toggle, asi que se escucha el checkbox y no solo se pinta al
            // abrir el modal. Los equivalentes de acceso y revisión viven en
            // `dashboard.js`, que es quien pinta ahora esos ajustes.
            if (st('stAdvanceAuto')) {
                st('stAdvanceAuto').addEventListener('change', updateAdvanceHint);
            }
        }

        bindAddFieldModal();
        // F2: #settingsForm vive desde el arranque dentro del drawer; no hay
        // reubicaciones que capturar.
        bindFieldSettingsModal();
        renderFieldCanvas();
        setStatus('Listo', 'text-muted');
    }

    init();

    // Marca de versión para depurar caché en consola (F12).
    console.log('[builder] v2026-10-11b (F3: ajustes como drawer a la derecha, vista previa embebida en el lienzo)');
})();