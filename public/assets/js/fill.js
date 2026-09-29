/* VanillaForms — fill.js (Fase UX-1)
 * Validación inline, contador de caracteres, progreso y autosave
 * de la página pública de respuesta. Sin dependencias.
 */
(function () {
    'use strict';

    const form = document.getElementById('vfForm');
    if (!form) {
        return;
    }

    const slug = form.dataset.slug || '';
    const hasServerErrors = document.body.dataset.hasErrors === '1';
    const allFieldCards = Array.prototype.slice.call(form.querySelectorAll('.vf-field-card'));

    // P9 — i18n reducido: el servidor manda data-vf-i18n (JSON) con las claves
    // visibles (ui./val./js.) para el idioma del formulario. Si falta algo se
    // cae al fallback en español (comportamiento previo).
    let I18N = {};
    try {
        I18N = JSON.parse(form.dataset.vfI18n || '{}') || {};
    } catch (e) {
        /* catálogo inválido: se usa el fallback en español */
    }
    const FALLBACK_I18N = {
        'ui.anterior': 'Anterior',
        'ui.siguiente': 'Siguiente',
        'ui.enviar': 'Enviar',
        'ui.enviando': 'Enviando…',
        'val.required': 'Esta pregunta es obligatoria.',
        'val.email': 'Ingresa un correo válido.',
        'val.url': 'Ingresa una URL válida.',
        'val.tel': 'Ingresa un teléfono válido.',
        'val.number': 'Ingresa un número válido.',
        'val.min_gte': 'Debe ser mayor o igual a {min}.',
        'val.max_lte': 'Debe ser menor o igual a {max}.',
        'val.min_len': 'Debe tener al menos {n} caracteres.',
        'val.max_len': 'Debe tener como máximo {n} caracteres.',
        'val.nps_range': 'Debe estar entre 0 y 10.',
        'val.scale_range': 'Debe elegir un valor entre 1 y {max}.',
        'val.matrix_all': 'Debes responder todas las filas.',
        'val.ranking_all': 'Debes ordenar todas las opciones.',
        'val.ranking_range': 'Los órdenes deben estar entre 1 y {max}.',
        'val.ranking_unique': 'Los órdenes no pueden repetirse.',
        'val.star_min': 'Debe elegir al menos 1 estrella.',
        'val.star_max': 'Debe elegir como máximo {max} estrellas.',
        'val.file_too_big': 'El archivo supera el tamaño máximo permitido ({max} MB).',
        'val.file_count': 'Puedes adjuntar como máximo {max} archivo(s).',
        'js.saved': 'Progreso guardado',
        'js.restored': 'Restauramos tu progreso anterior.',
    };
    function t(key, params) {
        let s = I18N[key] !== undefined ? I18N[key] : (FALLBACK_I18N[key] || key);
        if (params) {
            Object.keys(params).forEach((name) => {
                s = s.split('{' + name + '}').join(String(params[name]));
            });
        }
        return s;
    }

    const conditionalEls = Array.prototype.slice.call(form.querySelectorAll('[data-vf-logic]')).map((el) => ({
        el: el,
        logic: parseLogic(el.dataset.vfLogic),
    }));
    // Tarjetas SIN condición propia. El servidor las puede dejar ocultas (gap
    // sin JavaScript: una pregunta dentro de una sección cuya condición es
    // falsa, o de un bloque de página omitido), así que el cliente también
    // tiene que poder levantarlo cuando la situación cambie. Antes no hacía
    // falta porque el render nunca las ocultaba.
    const plainFieldCards = allFieldCards.filter((el) => !el.hasAttribute('data-vf-logic'));
    const draftKey = 'vf.draft.' + slug;
    const SAVE_DEBOUNCE = 500;

    let saveTimer = null;

    /* ---------- P4: multi-paso ---------- */
    const pageBlocks = Array.prototype.slice.call(form.querySelectorAll('.vf-page-block'));
    const hasPages = pageBlocks.length > 0;
    let currentStep = 0;

    // P8 — salta-página: cada bloque lleva la condición del campo «page» que lo
    // abre (data-vf-page-logic). Si la condición se cumple, el paso se omite.
    const pageSkipLogic = pageBlocks.map((pb) => parseLogic(pb.getAttribute('data-vf-page-logic')));

    // Paso (índice 0-based) al que pertenece una tarjeta: el de su bloque
    // contenedor. Si no hay páginas, todo vive en el paso 0 implícito.
    function stepOfCard(card) {
        if (!hasPages) {
            return 0;
        }
        const pb = card.closest('.vf-page-block');
        const idx = pb ? pageBlocks.indexOf(pb) : -1;
        return idx < 0 ? 0 : idx;
    }

    function stepCards(step) {
        return allFieldCards.filter((card) => stepOfCard(card) === step);
    }

    // Visible por condición (ni oculta por su condición ni por su sección).
    function conditionVisible(card) {
        if (card.classList.contains('vf-hidden')) {
            return false;
        }
        const sec = card.closest('.vf-section-block');
        return !(sec && sec.classList.contains('vf-hidden'));
    }

    // P8 — El bloque de un paso se omite cuando su condición (page-skip) se
    // cumple. El servidor replica esta decisión en submit de forma autoritativa.
    function pageSkipped(idx) {
        const logic = pageSkipLogic[idx];
        if (!logic) {
            return false;
        }
        return evalLogic(logic, currentValues());
    }

    function visibleSteps() {
        const out = [];
        pageBlocks.forEach((pb, i) => {
            if (!pageSkipped(i)) {
                out.push(i);
            }
        });
        return out;
    }

    // Resuelve un paso pedido al paso visible más cercano (adelante o atrás,
    // según dir) cuando el pedido está saltado por condición.
    function resolveStep(step, dir) {
        const vis = visibleSteps();
        if (vis.length === 0) {
            return 0;
        }
        const s = Math.max(0, Math.min(step, pageBlocks.length - 1));
        if (vis.indexOf(s) >= 0) {
            return s;
        }
        if (dir < 0) {
            for (let i = vis.length - 1; i >= 0; i--) {
                if (vis[i] < s) {
                    return vis[i];
                }
            }
            return vis[0];
        }
        for (let i = 0; i < vis.length; i++) {
            if (vis[i] >= s) {
                return vis[i];
            }
        }
        return vis[vis.length - 1];
    }

    function gotoStep(step, opts) {
        opts = opts || {};
        if (!hasPages) {
            currentStep = 0;
            return;
        }
        currentStep = resolveStep(step, opts.dir === undefined ? 1 : opts.dir);
        paintSteps(opts.scroll !== false);
        if (opts.save !== false) {
            scheduleSave();
        }
    }

    // Pinta el estado de los pasos: oculta los no actuales y los saltados,
    // renumera el badge y reconstruye los controles prev/siguiente/enviar según
    // la posición del paso dentro de los visibles (P8).
    function paintSteps(shouldScroll) {
        if (!hasPages) {
            return;
        }
        const vis = visibleSteps();
        if (vis.indexOf(currentStep) < 0 && vis.length > 0) {
            const after = vis.find((v) => v > currentStep);
            currentStep = after !== undefined ? after : vis[vis.length - 1];
        }
        pageBlocks.forEach((pb, i) => {
            const rank = vis.indexOf(i);
            const show = rank >= 0;
            pb.classList.toggle('vf-step-hidden', !(show && i === currentStep));
            if (show) {
                // Visible de nuevo: se le quita el marcador de omitido para que
                // applyCardVisibility() le devuelva la visibilidad a sus tarjetas
                // (si no, un paso que deja de saltarse se quedaba invisible).
                pb.removeAttribute('data-vf-skipped');
            } else {
                // P8 — Paso omitido por su condición: sus campos no cuentan
                // (ni en el progreso ni en el envío) y el servidor tampoco los
                // persiste. OJO: `.vf-step-hidden` es de MAQUETACIÓN (solo se
                // ve un paso a la vez), así que por sí solo no puede decidir
                // qué cuenta; el estado que significa «este paso no aplica» es
                // este atributo.
                pb.setAttribute('data-vf-skipped', '1');
                return;
            }
            const foot = pb.querySelector('.vf-page-footer');
            if (!foot) {
                return;
            }
            const num = foot.querySelector('.vf-step-num');
            if (num) {
                num.textContent = String(rank + 1);
            }
            const total = foot.querySelector('.vf-step-total');
            if (total) {
                total.textContent = String(vis.length);
            }
            buildFooterControls(foot, rank, vis.length);
        });
        // La visibilidad de las tarjetas depende de los marcadores de salto que
        // se acaban de poner y quitar, y la barra depende de las tarjetas.
        applyCardVisibility();
        updateProgress();
        if (shouldScroll !== false) {
            const target = pageBlocks[currentStep];
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    // Reconstruye los controles del pie de paso (los del servidor asumen que
    // todos los pasos se ven; con saltos la lógica prev/enviar cambia).
    function buildFooterControls(foot, rank, count) {
        foot.querySelectorAll('button[data-vf-action], #vfSubmit, .vf-footer-spacer').forEach((b) => b.remove());
        const badge = foot.querySelector('.vf-step-badge');
        if (rank > 0) {
            const prev = document.createElement('button');
            prev.type = 'button';
            prev.className = 'btn vf-nav-btn';
            prev.dataset.vfAction = 'prev';
            prev.innerHTML = '<i class="fa-solid fa-arrow-left me-2"></i>' + t('ui.anterior');
            foot.insertBefore(prev, badge);
        } else {
            const spacer = document.createElement('span');
            spacer.className = 'vf-footer-spacer';
            foot.insertBefore(spacer, badge);
        }
        if (rank >= count - 1) {
            const submit = document.createElement('button');
            submit.type = 'submit';
            submit.id = 'vfSubmit';
            submit.className = 'vf-submit';
            submit.innerHTML = '<span class="vf-submit-label"><i class="fa-solid fa-paper-plane me-2"></i>' + t('ui.enviar') + '</span>';
            foot.appendChild(submit);
        } else {
            const next = document.createElement('button');
            next.type = 'button';
            next.className = 'btn vf-nav-btn vf-nav-next';
            next.dataset.vfAction = 'next';
            next.innerHTML = t('ui.siguiente') + '<i class="fa-solid fa-arrow-right ms-2"></i>';
            foot.appendChild(next);
        }
    }

    // Valida SOLO el paso actual; no avanza si hay errores.
    function validateStep() {
        let anyError = false;
        let firstError = null;
        stepCards(currentStep).forEach((card) => {
            if (!conditionVisible(card)) {
                return;
            }
            const errors = validateField(card);
            renderState(card, errors);
            if (errors.length > 0) {
                anyError = true;
                if (!firstError) {
                    firstError = card;
                }
            }
        });
        if (!anyError) {
            return true;
        }
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const focusable = firstError.querySelector('input, textarea, select');
            if (focusable) {
                focusable.focus({ preventScroll: true });
            }
        }
        return false;
    }

    function goNext() {
        if (currentStep >= pageBlocks.length - 1 || !validateStep()) {
            return;
        }
        gotoStep(currentStep + 1, { dir: 1 });
    }

    function goPrev() {
        if (currentStep <= 0) {
            return;
        }
        gotoStep(currentStep - 1, { dir: -1 });
    }

    /* ---------- utilidades ---------- */

    function parseLogic(raw) {
        try {
            const o = JSON.parse(raw);
            return o && Array.isArray(o.rules) ? o : null;
        } catch (e) {
            return null;
        }
    }

    // Solo las tarjetas realmente visibles (ni ocultas por su condición ni por
    // una sección contenedora oculta ni por un paso saltado) participan en
    // progreso, borrador y envío (P8).
    function visibleFieldCards() {
        return allFieldCards.filter((card) => {
            if (card.classList.contains('vf-hidden')) {
                return false;
            }
            const sec = card.closest('.vf-section-block');
            if (sec && sec.classList.contains('vf-hidden')) {
                return false;
            }
            if (hasPages) {
                const pb = card.closest('.vf-page-block');
                if (pb && pb.classList.contains('vf-step-hidden')) {
                    return false;
                }
            }
            return true;
        });
    }

    // Las tarjetas contra las que se mide el PROGRESO (V1). Deliberadamente es
    // OTRO conjunto que visibleFieldCards(): aquella excluye el bloque de la
    // página que no es la actual, y con eso el denominador de un formulario
    // multipágina era solo la página a la vista, de modo que contestarla entera
    // ponía la barra al 100% sin haber tocado el resto.
    //
    // `.vf-step-hidden` es de maquetación (se ve un paso a la vez), así que no
    // puede decidir el progreso. El paso de verdad OMITIDO sí se excluye, y sin
    // filtro propio: paintSteps() le pone data-vf-skipped y applyCardVisibility()
    // deja sus tarjetas con vf-hidden, que es justo lo que esta lista filtra.
    function progressFieldCards() {
        return allFieldCards.filter((card) => {
            if (card.classList.contains('vf-hidden')) {
                return false;
            }
            const sec = card.closest('.vf-section-block');
            if (sec && sec.classList.contains('vf-hidden')) {
                return false;
            }
            return true;
        });
    }

    function currentValues() {
        const m = {};
        allFieldCards.forEach((card) => {
            const id = card.dataset.vfFieldId;
            if (!id) {
                return;
            }
            m[id] = getFieldValue(card);
        });
        return m;
    }

    function evalRule(rule, values) {
        const raw = values[String(rule.field_id)];
        const empty = isEmptyValue(raw);
        const expected = String(rule.value == null ? '' : rule.value);
        const op = rule.op || '';

        if (op === 'filled') {
            return !empty;
        }
        if (op === 'empty') {
            return empty;
        }
        // Afirmaciones con fuente vacía/desconocida => false.
        if (empty) {
            return op === 'neq' || op === 'not_contains';
        }

        switch (op) {
            case 'eq': return matches(raw, expected);
            case 'neq': return !matches(raw, expected);
            case 'contains': return containsFn(raw, expected);
            case 'not_contains': return !containsFn(raw, expected);
            case 'matches': return matchesRegex(raw, expected);
            case 'gt': return compareNum(raw, expected) > 0;
            case 'gte': return compareNum(raw, expected) >= 0;
            case 'lt': return compareNum(raw, expected) < 0;
            case 'lte': return compareNum(raw, expected) <= 0;
            default: return false;
        }
    }

    function evalLogic(logic, values) {
        if (!logic || !logic.rules || !logic.rules.length) {
            return true;
        }
        const operator = logic.operator === 'any' ? 'any' : 'all';
        const results = logic.rules.map((r) => evalRule(r, values));
        return operator === 'any' ? results.indexOf(true) >= 0 : results.indexOf(false) < 0;
    }

    function matches(value, expected) {
        if (Array.isArray(value)) {
            return value.indexOf(expected) >= 0;
        }
        return String(value) === expected;
    }

    // P8 — op matches (regex): el valor cumple el patrón. En arrays (checkbox)
    // basta con que una opción coincida. Patrón inválido → false (fail-safe).
    function matchesRegex(value, pattern) {
        let re;
        try {
            re = new RegExp(pattern, 'iu');
        } catch (e) {
            return false;
        }
        if (Array.isArray(value)) {
            return value.some((v) => re.test(String(v)));
        }
        return re.test(String(value));
    }

    function containsFn(value, needle) {
        if (Array.isArray(value)) {
            return value.indexOf(needle) >= 0;
        }
        return needle === '' || String(value).indexOf(needle) >= 0;
    }

    function compareNum(value, expected) {
        const a = Number(String(value));
        const b = Number(expected);
        if (isNaN(a) || isNaN(b)) {
            return String(value).localeCompare(String(expected));
        }
        return a < b ? -1 : a > b ? 1 : 0;
    }

    function clearInputs(container) {
        container.querySelectorAll('input, textarea, select').forEach((inp) => {
            if (inp.type === 'radio' || inp.type === 'checkbox') {
                inp.checked = false;
            } else {
                inp.value = '';
            }
        });
        container.querySelectorAll('.vf-range-wrap').forEach((w) => {
            const r = w.querySelector('.vf-range-input');
            const o = w.querySelector('.vf-range-val');
            if (r && o) {
                o.textContent = r.value;
            }
        });
        container.classList.remove('vf-has-error', 'vf-is-valid');
        const errEl = container.querySelector('.vf-error');
        if (errEl) {
            errEl.textContent = '';
        }
    }

    function setHidden(el, hidden) {
        el.classList.toggle('vf-hidden', hidden);
        if (hidden) {
            clearInputs(el);
        }
    }

    // ¿La tarjeta está en un bloque de página OMITIDO por su condición (P8)?
    // Lo decide paintSteps() poniéndole data-vf-skipped al bloque, no la clase
    // .vf-step-hidden (esa la llevan también el resto de páginas visibles).
    function cardInSkippedStep(card) {
        const pb = card.closest('.vf-page-block');
        return !!(pb && pb.getAttribute('data-vf-skipped') === '1');
    }

    // Última decisión de visibilidad por campo, ya evaluada. `null` = todavía no
    // se ha evaluado ninguna condición, y entonces manda lo que el servidor
    // pintó (el gap sin JavaScript: el render de inicialización ya viene con
    // las preguntas que no aplican en vf-hidden).
    let ownVisible = null;

    // Aplica la visibilidad a todas las tarjetas. Vive separada de
    // evaluateConditions() porque paintSteps() también la necesita: el orden
    // correcto es «evaluar condiciones -> marcar pasos omitidos -> aplicar», y
    // antes el marcado iba dentro de paintSteps() (que ocultaba las tarjetas del
    // paso saltado sin poder volver a enseñarlas nunca).
    function applyCardVisibility() {
        conditionalEls.forEach((c) => {
            const sec = c.el.closest('.vf-section-block');
            const own = ownVisible !== null && ownVisible[c.el.dataset.vfFieldId] === false;
            const secHidden = !!(sec && sec !== c.el && ownVisible !== null && ownVisible[sec.dataset.vfFieldId] === false);
            setHidden(c.el, own || secHidden || cardInSkippedStep(c.el));
        });
        // Sin condición propia: solo dependen de su sección y de su paso.
        plainFieldCards.forEach((el) => {
            const sec = el.closest('.vf-section-block');
            const secHidden = !!sec && (
                sec.classList.contains('vf-hidden')
                || (ownVisible !== null && ownVisible[sec.dataset.vfFieldId] === false)
            );
            setHidden(el, secHidden || cardInSkippedStep(el));
        });
    }

    // Evalúa todas las condiciones en una sola pasada (dos fases: cálculo y
    // aplicación) para que sea independiente del orden del DOM. Una tarjeta se
    // oculta si su propia condición falla, si su sección contenedora lo está o
    // si su paso se está saltando.
    function evaluateConditions() {
        const values = currentValues();
        const own = {};
        conditionalEls.forEach((c) => {
            own[c.el.dataset.vfFieldId] = evalLogic(c.logic, values);
        });
        ownVisible = own;
        applyCardVisibility();
        // P8 — salta-página: repintar pasos visibles (puede cambiar con valores).
        paintSteps(false);
    }

    function isEmptyValue(value) {
        if (Array.isArray(value)) {
            return value.filter((v) => v !== '' && v != null).length === 0;
        }
        if (value && typeof value === 'object') {
            return Object.keys(value).every((k) => value[k] === '' || value[k] == null);
        }
        return value === null || (typeof value === 'string' && value.trim() === '');
    }

    function len(value) {
        return Array.from(String(value)).length;
    }

    function getFieldValue(card) {
        const type = card.dataset.vfType;

        // P10: los archivos no tienen «valor» asignable; para required/progreso
        // basta con la lista de nombres seleccionados.
        if (type === 'file') {
            const input = card.querySelector('input[type=file]');
            if (!input || !input.files) {
                return [];
            }
            return Array.prototype.map.call(input.files, (f) => f.name);
        }

        if (type === 'checkbox') {
            return Array.prototype.filter
                .call(card.querySelectorAll('input[type=checkbox]:checked'), (i) => i)
                .map((i) => i.value);
        }

        if (type === 'matrix') {
            const out = {};
            card.querySelectorAll('[data-vf-row]').forEach((rowEl) => {
                const checked = rowEl.querySelector('input[type=radio]:checked');
                out[rowEl.dataset.vfRow] = checked ? checked.value : '';
            });
            return out;
        }

        if (type === 'ranking') {
            const out = {};
            card.querySelectorAll('[data-vf-rank]').forEach((rowEl) => {
                const input = rowEl.querySelector('input[type=number]');
                out[rowEl.dataset.vfRank] = input ? input.value : '';
            });
            return out;
        }

        if (type === 'radio' || type === 'scale' || type === 'nps' || type === 'star') {
            const checked = card.querySelector('input[type=radio]:checked');
            return checked ? checked.value : '';
        }

        const el = card.querySelector('input:not([type=radio]):not([type=checkbox]), textarea, select');
        return el ? el.value : '';
    }

    function setFieldValue(card, value) {
        const type = card.dataset.vfType;

        if (type === 'checkbox') {
            const values = Array.isArray(value) ? value : [value];
            card.querySelectorAll('input[type=checkbox]').forEach((i) => {
                i.checked = values.indexOf(i.value) >= 0;
            });
            return;
        }

        if (type === 'matrix') {
            const vals = (value && typeof value === 'object') ? value : {};
            card.querySelectorAll('[data-vf-row]').forEach((rowEl) => {
                const want = vals[rowEl.dataset.vfRow];
                rowEl.querySelectorAll('input[type=radio]').forEach((i) => {
                    i.checked = String(i.value) === String(want);
                });
            });
            return;
        }

        if (type === 'ranking') {
            const vals = (value && typeof value === 'object') ? value : {};
            card.querySelectorAll('[data-vf-rank]').forEach((rowEl) => {
                const input = rowEl.querySelector('input[type=number]');
                if (input) {
                    input.value = vals[rowEl.dataset.vfRank] || '';
                }
            });
            return;
        }

        if (type === 'radio' || type === 'scale' || type === 'nps' || type === 'star') {
            card.querySelectorAll('input[type=radio]').forEach((i) => {
                i.checked = String(i.value) === String(value);
            });
            return;
        }

        // P10: un input[type=file] no admite value por JS (InvalidStateError)
        // y el borrador nunca debe intentar rellenarlo.
        if (type === 'file') {
            return;
        }

        const el = card.querySelector('input, textarea, select');
        if (el) {
            el.value = value;
        }
    }

    /* ---------- validación ---------- */

    function validateField(card) {
        const errors = [];
        const type = card.dataset.vfType;
        const value = getFieldValue(card);
        const required = card.dataset.vfRequired === '1';

        if (required && isEmptyValue(value)) {
            errors.push(t('val.required'));
        }

        if (isEmptyValue(value)) {
            return errors;
        }

        if (type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            errors.push(t('val.email'));
        }

        if (type === 'url' && !/^https?:\/\/[^\s]+\.[^\s]+$/i.test(value)) {
            errors.push(t('val.url'));
        }

        if (type === 'tel') {
            const digits = String(value).replace(/\D/g, '');
            if (/[^0-9+().\-\s\/]/.test(value) || digits.length < 6) {
                errors.push(t('val.tel'));
            }
        }

        if (type === 'number' || type === 'range' || type === 'nps' || type === 'star' || type === 'scale') {
            const num = Number(value);
            if (value !== '' && isNaN(num)) {
                errors.push(t('val.number'));
            }
            if (!isNaN(num)) {
                const min = (type === 'star' || type === 'scale') ? 1 : parseFloat(card.dataset.vfMin);
                const max = parseFloat(card.dataset.vfMax);
                if (type === 'scale') {
                    if (max && !isNaN(max) && (num < 1 || num > max)) {
                        errors.push(t('val.scale_range', { max: max }));
                    }
                } else {
                    if (!isNaN(min) && num < min) {
                        errors.push(type === 'star' ? t('val.star_min') : t('val.min_gte', { min: min }));
                    }
                    if (!isNaN(max) && num > max) {
                        errors.push(type === 'star' ? t('val.star_max', { max: max }) : (type === 'nps' ? t('val.nps_range') : t('val.max_lte', { max: max })));
                    }
                }
            }
        }

        if (type === 'file') {
            const input = card.querySelector('input[type=file]');
            const files = input && input.files ? Array.prototype.slice.call(input.files) : [];
            const maxBytes = parseInt(card.dataset.vfFileMaxSize || '', 10);
            const maxFiles = parseInt(card.dataset.vfFileMaxFiles || '', 10);
            const multiple = card.dataset.vfFileMultiple === '1';

            if (!multiple && files.length > 1) {
                errors.push(t('val.file_count', { max: 1 }));
            } else if (!isNaN(maxFiles) && maxFiles > 0 && files.length > maxFiles) {
                errors.push(t('val.file_count', { max: maxFiles }));
            }
            if (!isNaN(maxBytes) && maxBytes > 0 && files.some((f) => f.size > maxBytes)) {
                errors.push(t('val.file_too_big', { max: Math.round(maxBytes / 1048576) }));
            }
        }

        if (type === 'matrix') {
            const rows = card.querySelectorAll('[data-vf-row]').length;
            const answered = card.querySelectorAll('[data-vf-row] input[type=radio]:checked').length;
            if (answered < rows) {
                errors.push(t('val.matrix_all'));
            }
        }

        if (type === 'ranking') {
            const inputs = Array.prototype.map.call(
                card.querySelectorAll('[data-vf-rank] input[type=number]'),
                (i) => i.value
            );
            const filled = inputs.filter((v) => v !== '');
            const wrap = card.querySelector('[data-vf-ranking]');
            const total = (wrap && wrap.dataset.vfCount ? parseInt(wrap.dataset.vfCount, 10) : inputs.length) || inputs.length;
            if (filled.length && filled.length < total) {
                errors.push(t('val.ranking_all'));
            }
            if (filled.length === total) {
                const nums = filled.map(Number);
                if (nums.some((n) => isNaN(n) || n < 1 || n > total)) {
                    errors.push(t('val.ranking_range', { max: total }));
                }
                if (new Set(nums).size !== total) {
                    errors.push(t('val.ranking_unique'));
                }
            }
        }

        if (type === 'text' || type === 'textarea') {
            const minLen = parseInt(card.dataset.vfMinLen || '', 10);
            const maxLen = parseInt(card.dataset.vfMaxLen || '', 10);
            const l = len(value);
            if (!isNaN(minLen) && l < minLen) {
                errors.push(t('val.min_len', { n: minLen }));
            }
            if (!isNaN(maxLen) && l > maxLen) {
                errors.push(t('val.max_len', { n: maxLen }));
            }
        }

        return errors;
    }

    function renderState(card, errors) {
        card.classList.toggle('vf-has-error', errors.length > 0);
        card.classList.toggle('vf-is-valid', errors.length === 0 && !isEmptyValue(getFieldValue(card)));

        const errEl = card.querySelector('.vf-error');
        if (errEl) {
            errEl.textContent = errors.join(' ');
        }

        card.querySelectorAll('input, textarea, select').forEach((el) => {
            const invalid = errors.length > 0;
            el.classList.toggle('is-invalid', invalid);
            el.classList.toggle('is-valid', !invalid && !isEmptyValue(getFieldValue(card)));
            el.setAttribute('aria-invalid', invalid ? 'true' : 'false');
        });
    }

    /* ---------- progreso ---------- */

    // ¿Cuenta la tarjeta como respondida para la barra?
    //
    // En los tipos de respuesta compuesta es «completa o nada»: una matriz con
    // una de sus cinco filas marcadas NO cuenta, igual que NO deja enviar
    // (validateField exige todas las filas, val.matrix_all). Antes el progreso
    // usaba isEmptyValue(getFieldValue()), que para una matriz devuelve «vacío»
    // solo si están TODAS las filas vacías, así que con una sola fila contestada
    // la barra se llenaba y el envío se rechazaba por el mismo dato.
    function isCardAnswered(card) {
        const type = card.dataset.vfType;

        if (type === 'matrix') {
            const rows = card.querySelectorAll('[data-vf-row]');
            return rows.length > 0
                && card.querySelectorAll('[data-vf-row] input[type=radio]:checked').length === rows.length;
        }

        if (type === 'ranking') {
            const inputs = Array.prototype.map.call(
                card.querySelectorAll('[data-vf-rank] input[type=number]'),
                (i) => i.value
            );
            return inputs.length > 0 && inputs.every((v) => v !== '');
        }

        return !isEmptyValue(getFieldValue(card));
    }

    /* V1 — Progreso de completitud real: si hay preguntas obligatorias
     * visibles, el % mide cuántas de ellas están respondidas (el resto son
     * opcionales, no bloquean el envío). Sin obligatorias, cae al % de campos
     * respondidos del conjunto visible (como antes). El denominador es
     * progressFieldCards() — TODO el formulario, no el paso a la vista. */
    function countAnswered() {
        const cards = progressFieldCards();
        let done = 0;
        let reqDone = 0;
        let reqTotal = 0;
        cards.forEach((card) => {
            const req = card.dataset.vfRequired === '1';
            const filled = isCardAnswered(card);
            if (filled) {
                done += 1;
            }
            if (req) {
                reqTotal += 1;
                if (filled) {
                    reqDone += 1;
                }
            }
        });
        if (reqTotal > 0) {
            return Math.round((reqDone / reqTotal) * 100);
        }
        return cards.length ? Math.round((done / cards.length) * 100) : 0;
    }

    function updateProgress() {
        const pct = countAnswered();
        const bar = document.getElementById('vfProgressBar');
        const label = document.getElementById('vfProgressLabel');
        if (bar) {
            bar.style.width = pct + '%';
        }
        if (label) {
            label.textContent = pct + '%';
        }
        // Si la barra se decide con el conjunto del progreso, se decide AQUÍ y
        // no una sola vez al cargar: un formulario cuya lógica va revelando
        // preguntas pasa de 1 a varias, y con el `add` de la versión anterior
        // (evaluado una vez, al final de apply()) la barra se quedaba oculta
        // para siempre justo en los formularios que más la necesitan.
        // Con el conjunto del paso, además, un multipágina de una pregunta por
        // página ocultaba siempre: solo se ve un paso a la vez.
        const wrap = document.getElementById('vfProgressWrap');
        if (wrap) {
            wrap.classList.toggle('d-none', progressFieldCards().length < 2);
        }
    }

    /* ---------- P10: lista de archivos elegidos ---------- */

    function humanFileSize(bytes) {
        if (bytes < 1024) {
            return bytes + ' B';
        }
        if (bytes < 1048576) {
            return (bytes / 1024).toFixed(bytes < 10240 ? 1 : 0) + ' KB';
        }
        return (bytes / 1048576).toFixed(bytes < 10485760 ? 1 : 0) + ' MB';
    }

    function updateFileLists() {
        form.querySelectorAll('.vf-file-input').forEach((input) => {
            const list = input.closest('.vf-fieldset, .vf-field-card').querySelector('[data-vf-file-list]');
            if (!list) {
                return;
            }
            const files = input.files ? Array.prototype.slice.call(input.files) : [];
            list.textContent = '';
            if (!files.length) {
                list.hidden = true;
                return;
            }
            list.hidden = false;
            files.forEach((f) => {
                const item = document.createElement('div');
                item.className = 'vf-file-item d-flex align-items-center gap-2';
                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-paperclip';
                const name = document.createElement('span');
                name.className = 'vf-file-name text-truncate';
                name.textContent = f.name;
                const size = document.createElement('span');
                size.className = 'text-muted ms-auto';
                size.textContent = humanFileSize(f.size);
                item.appendChild(icon);
                item.appendChild(name);
                item.appendChild(size);
                list.appendChild(item);
            });
        });
    }

    /* ---------- contador de caracteres ---------- */

    function updateCharCounters() {
        form.querySelectorAll('[data-vf-charcount-for]').forEach((counter) => {
            const key = counter.dataset.vfCharcountFor;
            const el = form.querySelector('[name="' + key + '"]');
            if (!el) {
                return;
            }
            const max = parseInt(counter.dataset.vfMaxLen || '', 10);
            const current = len(el.value);
            counter.textContent = current + ' / ' + max;
            counter.classList.toggle('warn', current >= max * 0.85);
            counter.classList.toggle('over', current > max);
        });
    }

    /* ---------- autosave ---------- */

    function snapshot() {
        const values = {};
        visibleFieldCards().forEach((card) => {
            // P10: los archivos no se guardan en el borrador (el navegador no
            // puede restaurarlos); el campo se volv a pedir al enviar.
            if (card.dataset.vfType === 'file') {
                return;
            }
            values[card.dataset.vfKey] = getFieldValue(card);
        });
        return values;
    }

    function showAutosave(message) {
        const el = document.getElementById('vfAutosave');
        if (!el) {
            return;
        }
        el.innerHTML = '<i class="fa-solid fa-circle-check"></i>' + message;
        el.classList.add('show');
        clearTimeout(showAutosave._timer);
        showAutosave._timer = setTimeout(() => el.classList.remove('show'), 2400);
    }

    function saveDraft() {
        try {
            // M6: sessionStorage en lugar de localStorage para no persistir
            // respuestas (posible PII) entre sesiones del navegador.
            // P4: el borrador recuerda también el paso para reanudar ahí.
            sessionStorage.setItem(draftKey, JSON.stringify({
                savedAt: Date.now(),
                step: hasPages ? currentStep : 0,
                values: snapshot(),
            }));
            showAutosave(t('js.saved'));
        } catch (e) {
            /* sin almacenamiento local: no es crítico */
        }
    }

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveDraft, SAVE_DEBOUNCE);
    }

    // P7: prefill por URL (?field_<id>=valor[,valor...]). Se aplica ANTES del
    // borrador local (P4), así que el borrador y las respuestas con errores del
    // servidor siempre ganan sobre el prefill. La validación en el servidor
    // (opciones permitidas, etc.) sigue siendo la autoritativa.
    function applyPrefill() {
        if (hasServerErrors) {
            return;
        }
        try {
            const params = new URLSearchParams(window.location.search);
            let applied = 0;
            allFieldCards.forEach((card) => {
                const key = card.dataset.vfKey || '';
                if (!key.startsWith('field_')) {
                    return;
                }
                const raw = params.get(key);
                if (raw === null || raw === '') {
                    return;
                }
                const type = card.dataset.vfType;
                if (type === 'file') {
                    return;
                }
                setFieldValue(card, type === 'checkbox' ? raw.split(',') : raw);
                applied++;
            });
            if (applied > 0) {
                updateCharCounters();
                updateProgress();
            }
        } catch (e) {
            /* URL malformada: se ignora el prefill */
        }
    }

    function restoreDraft() {
        if (hasServerErrors) {
            // Quedaron errores del servidor: el envío ocurre en el último paso.
            if (hasPages) {
                gotoStep(pageBlocks.length - 1, { scroll: false });
            }
            return;
        }
        applyPrefill();
        try {
            const raw = sessionStorage.getItem(draftKey);
            if (!raw) {
                return;
            }
            const draft = JSON.parse(raw);
            if (!draft || !draft.values) {
                return;
            }
            allFieldCards.forEach((card) => {
                const value = draft.values[card.dataset.vfKey];
                if (value === undefined || value === null) {
                    return;
                }
                setFieldValue(card, value);
            });
            showAutosave(t('js.restored'));
            // P4: reanudar en el paso en que se quedó el usuario.
            if (hasPages) {
                const saved = Math.max(0, Math.floor(Number(draft.step) || 0));
                gotoStep(Math.min(saved, pageBlocks.length - 1), { scroll: false });
            }
        } catch (e) {
            /* borrador corrupto: se ignora */
        }
        // Tras restaurar, aplicar las condiciones para ocultar grupos/preguntas
        // que no aplican (y limpiar sus valores si quedaron del borrador).
        evaluateConditions();
        updateProgress();
    }

    /* ---------- envío ---------- */
    // M7: bandera para impedir envíos duplicados (además del disabled del botón).
    let submitting = false;

    function onSubmit(e) {
        if (submitting) {
            e.preventDefault();
            return;
        }

        let firstError = null;
        let anyError = false;

        visibleFieldCards().forEach((card) => {
            const errors = validateField(card);
            renderState(card, errors);
            if (errors.length > 0) {
                anyError = true;
                if (!firstError) {
                    firstError = card;
                }
            }
        });

        if (anyError) {
            e.preventDefault();
            // Si en un formulario multi-paso el error está en un paso oculto,
            // mostrar ese paso antes de hacer scroll/focus.
            if (hasPages && firstError) {
                const errStep = stepOfCard(firstError);
                if (errStep !== currentStep) {
                    gotoStep(errStep);
                }
            }
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const focusable = firstError.querySelector('input, textarea, select');
            if (focusable) {
                focusable.focus({ preventScroll: true });
            }
            return;
        }

        // M6: el borrador no vuelve a necesitarse: se limpia antes de enviar
        // para no dejar respuestas (posible PII) en el almacenamiento.
        try {
            sessionStorage.removeItem(draftKey);
        } catch (err) {
            /* almacenamiento no disponible: no es crítico */
        }

        submitting = true;

        const btn = document.getElementById('vfSubmit');
        if (btn) {
            btn.classList.add('vf-submitting');
            btn.setAttribute('disabled', '');
            btn.setAttribute('aria-busy', 'true');
            btn.querySelector('.vf-submit-label').textContent = t('ui.enviando');
        }
    }

    /* ---------- attach ---------- */

    function attach() {
        const afterChange = () => {
            evaluateConditions();
            updateProgress();
            scheduleSave();
        };

        allFieldCards.forEach((card) => {
            const type = card.dataset.vfType;

            card.querySelectorAll('input, textarea, select').forEach((el) => {
                const isChoice = el.type === 'radio' || el.type === 'checkbox' || el.tagName === 'SELECT';

                // P10: los archivos solo se pueden leer en el evento change.
                if (el.type === 'file') {
                    el.addEventListener('change', () => {
                        updateFileLists();
                        renderState(card, validateField(card));
                        afterChange();
                    });
                    return;
                }

                const validate = () => renderState(card, validateField(card));

                if (isChoice) {
                    el.addEventListener('change', () => {
                        validate();
                        afterChange();
                    });
                } else {
                    el.addEventListener('blur', validate);
                    el.addEventListener('input', () => {
                        if (el.type === 'range') {
                            const out = form.querySelector('output[for="' + el.id + '"]');
                            if (out) {
                                out.textContent = el.value;
                            }
                            validate();
                        } else {
                            updateCharCounters();
                        }
                        afterChange();
                        if (card.classList.contains('vf-has-error')) {
                            validate();
                        }
                    });
                }
            });
        });

        form.addEventListener('submit', onSubmit);

        // P4: navegación entre pasos (Anterior/Siguiente) y Enter para avanzar.
        form.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-vf-action]');
            if (!btn) {
                return;
            }
            e.preventDefault();
            if (btn.dataset.vfAction === 'next') {
                goNext();
            } else if (btn.dataset.vfAction === 'prev') {
                goPrev();
            }
        });
        form.addEventListener('keydown', (e) => {
            if (!hasPages || e.key !== 'Enter') {
                return;
            }
            if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'BUTTON') {
                return;
            }
            if (currentStep >= pageBlocks.length - 1) {
                return;
            }
            e.preventDefault();
            goNext();
        });

        // P4: comenzar mostrando el primer paso (los demás se ocultan).
        if (hasPages) {
            gotoStep(0, { scroll: false, save: false });
        }

        updateCharCounters();
        updateFileLists();
        updateProgress();
        restoreDraft();
        // Aplicar condiciones tras cualquier restauración (con o sin errores).
        evaluateConditions();
    }

    attach();
})();