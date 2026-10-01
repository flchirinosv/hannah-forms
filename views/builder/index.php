<?php
use App\Core\View;
use App\Services\FormSettings;
$formFields = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$settingsRaw = json_decode((string) ($form['settings'] ?? ''), true);
$formSettings = FormSettings::normalize(is_array($settingsRaw) ? $settingsRaw : []);
$formSettingsJson = json_encode($formSettings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
// P10 — Límites globales de upload que el builder muestra como techo (el
// servidor los vuelve a aplicar; aquí solo son UX).
$uploadLimits = json_encode([
    'max_size_mb' => \App\Services\Uploader::maxSizeMb(),
    'max_files'   => \App\Services\Uploader::maxFiles(),
    'extensions'  => \App\Services\Uploader::globalExts(),
], JSON_UNESCAPED_UNICODE);
$fieldTypes = [
    // Sección/página primero: son los elementos estructurales.
    'section' => ['label' => 'Sección de preguntas', 'icon' => 'fa-heading', 'desc' => 'Agrupa preguntas en un bloque; puede ocultarse completo por una condición'],
    'page'    => ['label' => 'Página (salto de paso)', 'icon' => 'fa-file-lines', 'desc' => 'Divide la encuesta en pasos'],
    'text'     => ['label' => 'Texto',       'icon' => 'fa-i-cursor',   'desc' => 'Respuesta corta'],
    'textarea' => ['label' => 'Párrafo',     'icon' => 'fa-align-left', 'desc' => 'Respuesta larga'],
    'email'    => ['label' => 'Email',       'icon' => 'fa-envelope',   'desc' => 'Correo electrónico'],
    'number'   => ['label' => 'Número',      'icon' => 'fa-hashtag',    'desc' => 'Valor numérico'],
    'select'   => ['label' => 'Desplegable', 'icon' => 'fa-caret-down', 'desc' => 'Elegir de una lista'],
    'radio'    => ['label' => 'Opción única','icon' => 'fa-circle-dot', 'desc' => 'Seleccionar una opción'],
    'checkbox' => ['label' => 'Casillas',    'icon' => 'fa-square-check','desc' => 'Seleccionar varias'],
    'date'     => ['label' => 'Fecha',       'icon' => 'fa-calendar',   'desc' => 'Fecha'],
    'scale'    => ['label' => 'Escala',      'icon' => 'fa-star',       'desc' => 'Escala de 1 a N'],
    'url'      => ['label' => 'URL',         'icon' => 'fa-link',       'desc' => 'Enlace web'],
    'tel'      => ['label' => 'Teléfono',    'icon' => 'fa-phone',      'desc' => 'Número de teléfono'],
    'time'     => ['label' => 'Hora',        'icon' => 'fa-clock',      'desc' => 'Hora del día'],
    'datetime-local' => ['label' => 'Fecha y hora', 'icon' => 'fa-calendar-check', 'desc' => 'Fecha con hora'],
    'matrix'   => ['label' => 'Matriz',      'icon' => 'fa-table',      'desc' => 'Matriz / escala Likert'],
    'nps'      => ['label' => 'NPS',         'icon' => 'fa-chart-column','desc' => 'Net Promoter Score 0-10'],
    'star'     => ['label' => 'Estrellas',    'icon' => 'fa-star-half-stroke','desc' => 'Calificación 1-5 (o 1-N)'],
    'range'    => ['label' => 'Rango',       'icon' => 'fa-sliders',    'desc' => 'Control deslizante'],
    'ranking'  => ['label' => 'Ranking',     'icon' => 'fa-list-ol',     'desc' => 'Ordenar opciones 1..N'],
    'file'     => ['label' => 'Archivo',     'icon' => 'fa-paperclip',   'desc' => 'Subir uno o varios archivos'],
];
?>
<div class="container-fluid px-lg-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3">
        <div>
            <a href="/forms/<?= (int) $form['id'] ?>" class="text-decoration-none text-muted small"><i class="fa-solid fa-arrow-left me-1"></i>Volver</a>
            <h2 class="h4 fw-bold mb-0 mt-2"><i class="fa-solid fa-pen-nib me-2 text-primary"></i>Builder — <span id="builderTitleText"><?= View::escape($form['title']) ?></span></h2>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="small align-self-center text-muted" id="saveStatus">Guardado ✓</span>
            <div class="btn-group d-none d-sm-inline-flex" role="group" aria-label="Deshacer / rehacer">
                <button class="btn btn-outline-secondary" type="button" id="btnUndo" title="Deshacer (Ctrl+Z)"><i class="fa-solid fa-rotate-left"></i></button>
                <button class="btn btn-outline-secondary" type="button" id="btnRedo" title="Rehacer (Ctrl+Shift+Z)"><i class="fa-solid fa-rotate-right"></i></button>
            </div>
            <button class="btn btn-outline-secondary" type="button" id="btnLivePreview"><i class="fa-solid fa-eye me-1"></i>Vista previa en vivo</button>
            <button class="btn btn-outline-secondary" type="button" id="btnFormSettings"><i class="fa-solid fa-gear me-1"></i>Configuración</button>
            <button class="btn btn-outline-secondary d-none d-md-inline-flex" type="button" id="btnPreviewUrl"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Abrir</button>
            <button class="btn btn-success app-btn-primary" type="button" id="btnSaveStructure"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar</button>
        </div>
    </div>

    <div class="row g-4 builder-layout">
        <!-- Paleta -->
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm app-builder-panel h-100">
                <div class="card-body">
                    <h3 class="h6 fw-bold text-uppercase small text-muted mb-3"><i class="fa-solid fa-cubes me-1"></i>Agrega elementos</h3>
                    <div class="app-palette-group">
                        <div class="app-palette-group-title"><i class="fa-solid fa-layer-group me-1"></i>Estructura</div>
                        <div class="app-palette-static">
                            <?php foreach ($fieldTypes as $type => $info): if (!in_array($type, ['section', 'page'], true)) continue; ?>
                            <div class="app-palette-item <?= $type === 'section' ? 'app-palette-section' : 'app-palette-page' ?>" draggable="true" data-type="<?= View::escape($type) ?>">
                                <span class="app-palette-icon"><i class="fa-solid <?= View::escape($info['icon']) ?>"></i></span>
                                <span class="d-flex flex-column">
                                    <span class="fw-semibold small"><?= View::escape($info['label']) ?></span>
                                    <span class="text-muted" style="font-size:.72rem"><?= View::escape($info['desc']) ?></span>
                                </span>
                                <span class="app-palette-grip ms-auto"><i class="fa-solid fa-grip-vertical"></i></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="app-palette-group">
                        <div class="app-palette-group-title"><i class="fa-solid fa-list-check me-1"></i>Tipos de pregunta</div>
                        <div class="app-palette-grid">
                            <?php foreach ($fieldTypes as $type => $info): if (in_array($type, ['section', 'page'], true)) continue; ?>
                            <div class="app-palette-item" draggable="true" data-type="<?= View::escape($type) ?>">
                                <span class="app-palette-icon"><i class="fa-solid <?= View::escape($info['icon']) ?>"></i></span>
                                <span class="fw-semibold small"><?= View::escape($info['label']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <p class="text-muted small mt-3 mb-0"><i class="fa-solid fa-circle-info me-1"></i>Haz clic (o arrastra) cualquier elemento para agregarlo al lienzo.</p>
                </div>
            </div>
        </div>

        <!-- Lienzo -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm app-builder-canvas-wrap">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <input type="text" class="form-control form-control-lg fw-bold app-title-input" id="builderTitle" value="<?= View::escape($form['title']) ?>" placeholder="Título del formulario">
                    </div>
                    <div class="mb-4">
                        <textarea class="form-control" id="builderDescription" rows="2" placeholder="Descripción (opcional)"><?= View::escape($form['description']) ?></textarea>
                    </div>

                    <div id="canvasFields" class="app-canvas d-flex flex-column gap-4"></div>

                    <div id="emptyCanvas" class="text-center py-5 app-empty-canvas">
                        <div class="app-empty-icon mb-3"><i class="fa-solid fa-arrow-down"></i></div>
                        <p class="h6 fw-bold">Arrastra campos aquí</p>
                        <p class="text-muted small">O haz clic en un tipo de campo de la paleta para agregarlo.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panel de configuración -->
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm app-builder-panel h-100">
                <div class="card-body" id="settingsPanel">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                        <h3 class="h6 fw-bold text-uppercase small text-muted mb-0"><i class="fa-solid fa-sliders me-1"></i>Configuración</h3>
                        <button type="button" class="btn btn-sm app-tool-btn" id="btnFieldSettingsOpen" disabled
                                title="Abrir la configuración en una ventana" aria-label="Abrir la configuración en una ventana">
                            <i class="fa-solid fa-up-right-from-square"></i>
                        </button>
                    </div>
                    <div class="text-center text-muted py-4" id="settingsEmpty">
                        <i class="fa-solid fa-hand-pointer fa-2x mb-2 d-block"></i>
                        <span class="small">Selecciona un campo del lienzo<br>para editar sus propiedades.</span>
                    </div>
                    <div id="settingsForm" class="d-none">
                        <ul class="nav nav-pills nav-sm mb-3" role="tablist" aria-label="Opciones del campo">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tabContent" type="button" role="tab" aria-selected="true">Contenido</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tabLogic" type="button" role="tab" aria-selected="false">Lógica</button>
                            </li>
                        </ul>
                        <div class="tab-content">
                        <div class="tab-pane fade show active" id="tabContent" role="tabpanel">
                        <input type="hidden" id="fieldId">
                        <div class="mb-3">
                            <label for="fieldTypeLabel" class="form-label small fw-semibold">Tipo</label>
                            <input type="text" class="form-control form-control-sm bg-light" id="fieldTypeLabel" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="fieldLabel" class="form-label small fw-semibold">Pregunta / Etiqueta</label>
                            <input type="text" class="form-control form-control-sm" id="fieldLabel" placeholder="Pregunta">
                        </div>
                        <div class="mb-3 d-none" id="fieldPlaceholderWrap">
                            <label for="fieldPlaceholder" class="form-label small fw-semibold">Placeholder</label>
                            <input type="text" class="form-control form-control-sm" id="fieldPlaceholder" placeholder="Texto de ejemplo">
                        </div>
                        <div class="mb-3 d-none" id="fieldHelpWrap">
                            <label for="fieldHelp" class="form-label small fw-semibold">Texto de ayuda</label>
                            <input type="text" class="form-control form-control-sm" id="fieldHelp" placeholder="Instrucción adicional">
                        </div>
                        <div class="mb-3 d-none" id="fieldOptionsWrap">
                            <label class="form-label small fw-semibold">Opciones</label>
                            <div id="optionsList" class="d-flex flex-column gap-2"></div>
                            <button type="button" class="btn btn-sm btn-outline-primary w-100 mt-2" id="btnAddOption"><i class="fa-solid fa-plus me-1"></i>Agregar opción</button>
                        </div>
                        <div class="mb-3 d-none" id="fieldMatrixWrap">
                            <label for="fieldMatrixRows" class="form-label small fw-semibold">Filas (una por línea)</label>
                            <textarea class="form-control form-control-sm mb-2" id="fieldMatrixRows" rows="3" placeholder="Enunciado por línea, ej.: Sabor&#10;Presentación"></textarea>
                            <label for="fieldMatrixCols" class="form-label small fw-semibold">Columnas / escala (una por línea)</label>
                            <textarea class="form-control form-control-sm" id="fieldMatrixCols" rows="2" placeholder="Ej.: Muy en desacuerdo&#10;En desacuerdo&#10;De acuerdo&#10;Muy de acuerdo"></textarea>
                        </div>
                        <div class="mb-3 d-none" id="fieldRankingHintWrap">
                            <p class="text-muted small mb-0"><i class="fa-solid fa-list-ol me-1"></i>El participante ordenará las opciones de <strong>1 (más importante)</strong> a <strong>N</strong>. Define las opciones en la lista de arriba.</p>
                        </div>
                        <div class="mb-3 d-none" id="fieldScaleWrap">
                            <label for="fieldScaleMax" class="form-label small fw-semibold">Escala máxima</label>
                            <input type="number" class="form-control form-control-sm" id="fieldScaleMax" min="2" max="10" value="5">
                        </div>
                        <div class="mb-3 d-none" id="fieldFileWrap">
                            <label for="valFileAccept" class="form-label small fw-semibold">Extensiones permitidas</label>
                            <input type="text" class="form-control form-control-sm mb-2" id="valFileAccept" placeholder="Ej.: pdf, docx, png">
                            <p class="form-text">Vacío = todos los formatos permitidos por el servidor. No se pueden agregar ejecutables ni HTML/SVG.</p>
                            <label for="valFileMaxSize" class="form-label small fw-semibold">Tamaño máximo por archivo (MB)</label>
                            <input type="number" class="form-control form-control-sm mb-2" id="valFileMaxSize" min="1" max="100" placeholder="10">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="valFileMultiple">
                                <label class="form-check-label small" for="valFileMultiple">Permitir varios archivos</label>
                            </div>
                            <p class="form-text mb-0" id="valFileLimitsHint"></p>
                        </div>
                        <div class="mb-3 d-none" id="fieldValidationWrap">
                            <label class="form-label small fw-semibold">Validaciones</label>
                            <p class="form-text">Lo que el campo acepte. Lo que quede fuera se rechaza al enviar, con el mensaje que ponga quien responde.</p>
                            <div class="row g-2">
                                <div class="col-6 d-none" id="valMinWrap">
                                    <label class="form-label small mb-1" for="valMin">Mínimo</label>
                                    <input type="number" class="form-control form-control-sm" id="valMin" placeholder="Ej: 1">
                                </div>
                                <div class="col-6 d-none" id="valMaxWrap">
                                    <label class="form-label small mb-1" for="valMax">Máximo</label>
                                    <input type="number" class="form-control form-control-sm" id="valMax" placeholder="Ej: 5">
                                </div>
                                <div class="col-6 d-none" id="valMinLenWrap">
                                    <label class="form-label small mb-1" for="valMinLen">Caracteres mínimos</label>
                                    <input type="number" class="form-control form-control-sm" id="valMinLen" placeholder="Ej: 10">
                                </div>
                                <div class="col-6 d-none" id="valMaxLenWrap">
                                    <label class="form-label small mb-1" for="valMaxLen">Caracteres máximos</label>
                                    <input type="number" class="form-control form-control-sm" id="valMaxLen" placeholder="Ej: 280">
                                </div>
                            </div>
                            <p class="form-text mb-0 d-none" id="valScaleHint"></p>
                        </div>
                        <!-- P8: quiz (respuesta correcta) y barajado de opciones -->
                        <div class="mb-3 d-none" id="fieldQuizWrap">
                            <label for="quizAnswer" class="form-label small fw-semibold">Respuesta correcta</label>
                            <input type="text" class="form-control form-control-sm" id="quizAnswer" placeholder="Valor exacto esperado">
                            <div class="text-muted mt-1" style="font-size:.72rem">Pregunta de quiz: suma un punto si coincide con la respuesta. En opciones múltiples separa las correctas con comas.</div>
                        </div>
                        <div class="form-check form-switch mb-3 d-none" id="fieldShuffleWrap">
                            <input class="form-check-input" type="checkbox" id="fieldShuffleOps">
                            <label class="form-check-label small" for="fieldShuffleOps">Barajar opciones (cada participante las ve en distinto orden)</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="fieldRequired">
                            <label class="form-check-label small" for="fieldRequired">Campo obligatorio</label>
                        </div>
                        <div class="form-check form-switch mb-3 d-none" id="fieldPageHeaderWrap">
                            <input class="form-check-input" type="checkbox" id="fieldPageHeader">
                            <label class="form-check-label small" for="fieldPageHeader">Mostrar la cabecera de esta p&aacute;gina</label>
                            <div class="text-muted mt-1" style="font-size:.72rem">Es el bloque con icono, t&iacute;tulo y descripci&oacute;n que abre el paso. Si lo desactivas, el paso se sigue existiendo y sigue contando en la barra de progreso y en el pie: lo que no se pinta es esa cabecera.</div>
                        </div>
                        <div class="form-check form-switch mb-3" id="fieldPiiWrap">
                            <input class="form-check-input" type="checkbox" id="fieldPii">
                            <label class="form-check-label small" for="fieldPii">Contiene datos personales</label>
                            <div class="text-muted mt-1" style="font-size:.72rem">Las respuestas de este campo saldr&aacute;n enmascaradas (IP, navegador y valor) para quien no tenga el permiso <code>responses.pii</code>. M&aacute;rcalo si pide un nombre, un tel&eacute;fono, un correo, un documento o una direcci&oacute;n.</div>
                            <div class="alert alert-warning py-2 px-2 mt-2 mb-0 d-none" id="piiSuggestWrap" role="status" style="font-size:.72rem">
                                <div id="piiSuggestText"></div>
                                <button type="button" class="btn btn-sm btn-outline-warning mt-1" id="btnPiiSuggest"><i class="fa-solid fa-shield-halved me-1"></i>S&iacute;, es dato personal</button>
                                <button type="button" class="btn btn-sm btn-link text-muted mt-1" id="btnPiiDismiss">No es</button>
                            </div>
                        </div>
                        </div>
                        <div class="tab-pane fade" id="tabLogic" role="tabpanel">
                        <div class="app-logic-wrap mb-3" id="logicWrap">
                            <div class="d-flex align-items-center gap-1 mb-1">
                                <i class="fa-solid fa-code-branch text-primary"></i>
                                <label class="form-label small fw-semibold mb-0">Mostrar condicionalmente</label>
                            </div>
                            <p class="text-muted mb-2" id="logicHowThis" style="font-size:.72rem">Este campo aparecerá solo si se cumplen las condiciones de abajo (según las respuestas de otra pregunta).</p>
                            <div class="alert alert-info py-2 px-3 small mb-2 d-none" id="logicInheritedNote" role="status"></div>
                            <div class="text-muted small mb-2 d-none" id="logicEmptyHint"></div>
                            <div id="logicRulesList" class="d-flex flex-column gap-1 mb-2"></div>
                            <div class="d-flex flex-column gap-2">
                                <select class="form-select form-select-sm" id="logicField" aria-label="Campo consultado"></select>
                                <div class="d-flex gap-2">
                                    <select class="form-select form-select-sm flex-shrink-0" id="logicOp" aria-label="Operador" style="max-width:150px"></select>
                                    <input type="text" class="form-control form-control-sm" id="logicValue" list="logicDatalist" placeholder="Valor (si aplica)">
                                    <datalist id="logicDatalist"></datalist>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddLogic"><i class="fa-solid fa-plus me-1"></i>Agregar condición</button>
                            </div>
                            <div class="d-flex align-items-center gap-2 mt-2">
                                <span class="small text-muted">Deben cumplirse</span>
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="radio" name="logicOperator" id="logicOpAll" value="all" checked>
                                    <label class="form-check-label small" for="logicOpAll">Todas (y)</label>
                                </div>
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="radio" name="logicOperator" id="logicOpAny" value="any">
                                    <label class="form-check-label small" for="logicOpAny">Cualquiera (o)</label>
                                </div>
                            </div>
                            <div class="alert alert-warning py-2 px-3 small mb-0 mt-2 d-none" id="logicForwardWarn" role="alert">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i>La condición hace referencia a una pregunta que aparece más abajo. Puede que aún no esté respondida cuando se evalúe.
                            </div>
                        </div>
                        </div>
                        </div>
                        <div class="d-flex flex-column gap-2">
                            <p class="text-muted small mb-0"><i class="fa-solid fa-circle-info me-1"></i>Los cambios se aplican y guardan automáticamente mientras escribes.</p>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-danger btn-sm flex-grow-1" id="btnDeleteField"><i class="fa-solid fa-trash me-1"></i>Eliminar campo</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- F2: el engranaje de cada tarjeta reubica la MISMA instancia de #settingsForm
     dentro de este modal (nunca se duplica: ver docs/design.md §4.2). -->
<div class="modal fade" id="fieldSettingsModal" tabindex="-1" aria-hidden="true" aria-labelledby="fieldSettingsTitle">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0">
            <div class="modal-header app-navbar text-white border-0 py-2">
                <div class="d-flex align-items-center gap-2 text-white flex-grow-1 min-w-0">
                    <button type="button" class="btn btn-link text-white text-decoration-none app-modal-back p-0" id="btnFieldSettingsBack"
                            title="Volver al panel" aria-label="Volver al panel">
                        <i class="fa-solid fa-chevron-left"></i>
                    </button>
                    <span class="fw-semibold text-truncate" id="fieldSettingsTitle">Configuración</span>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-4" id="fieldSettingsHost"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="livePreviewModal" tabindex="-1" aria-hidden="true" aria-labelledby="livePreviewTitle">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content border-0 overflow-hidden">
            <div class="modal-header app-navbar text-white border-0 py-2">
                <div class="d-flex align-items-center gap-2 text-white">
                    <span class="app-logo-sm"><i class="fa-solid fa-eye"></i></span>
                    <span class="fw-semibold" id="livePreviewTitle">Vista previa en vivo</span>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-0 vf-preview-scroll fill-body" style="padding:0!important">
                <div class="fill-container py-4 px-2 px-sm-3" id="livePreviewContent"></div>
            </div>
        </div>
    </div>
</div>

<?php // Ayuda del modal de configuración. Antes de los tooltips cada ajuste traía su
      // texto gris debajo, y con doce ajustes el modal era una columna de párrafos
      // en la que los controles quedaban separados de su etiqueta. Ahora el texto
      // viaja en `data-bs-title` y lo muestra Bootstrap al pasar por encima (o al
      // enfocar, o al tocar, que es como se ve en un móvil: el trigger lo pone
      // `app.js`). El HTML va escapado con ENT_QUOTES y el tooltip se pinta con
      // `data-bs-html="true"`, así que `<code>` y `<strong>` siguen siendo
      // negritas y monoespaciada.
      //
      // Lo que NO se esconde aquí es el ESTADO: `stFontNote`, `stAdvanceHint`,
      // `stAccessHint` y `stReviewHint` los escribe la aplicación al vuelo y
      // depends de ellos para saber si el ajuste sirve. Regla: la explicación se
      // esconde, el estado no.
      //
      // OJO: esta nota no puede escribir literalmente el cierre de PHP (el signo
      // de interrogación seguido de «mayor que») dentro del comentario: cerraría
      // el bloque y la closure de abajo se imprimiría como texto en el HTML en
      // vez de definirse.
$helpButton = static fn(string $html, string $label = 'Qué hace este ajuste'): string => \App\Core\View::help($html, $label);
?>

<div class="modal fade" id="formSettingsModal" tabindex="-1" aria-hidden="true" aria-labelledby="formSettingsTitle">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0">
            <div class="modal-header app-navbar text-white border-0 py-2">
                <div class="d-flex align-items-center gap-2 text-white">
                    <span class="app-logo-sm"><i class="fa-solid fa-gear"></i></span>
                    <span class="fw-semibold" id="formSettingsTitle">Configuración del formulario</span>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-light border small mb-4" role="note">
                    <p class="mb-0"><strong>Todo es opcional.</strong> Los ajustes vienen apagados y se guardan con la estructura. Si algo tiene un <span class="app-help-inline"><i class="fa-solid fa-circle-question" aria-hidden="true"></i> <strong>?</strong></span>, su explicación está ahí: pásale por encima, o tócalo si estás en el móvil.</p>
                    <p class="mb-0 mt-1">Aquí van los que cambian el <strong>relleno</strong>: tema, quiz, orden de las preguntas, avance automático, embed y anti-spam. Los que cambian <em>quién responde y cuándo</em> (acceso, fechas, cupo, avisos, webhook, mensaje final, revisión e idioma) están en la ficha del formulario.</p>
                </div>

                <h6 class="fw-bold text-uppercase small text-muted app-settings-group-title"><i class="fa-solid fa-palette me-1"></i>Tema</h6>
                <div class="row g-3 mb-2">
                    <div class="col-md-4">
                        <label for="stPrimary" class="form-label small fw-semibold">Color principal<?= $helpButton('Botón de enviar, barra de progreso y enlaces.') ?></label>
                        <input type="color" class="form-control form-control-color w-100" id="stPrimary" value="#0d6efd">
                    </div>
                    <div class="col-md-4">
                        <label for="stBackground" class="form-label small fw-semibold">Color de fondo<?= $helpButton('El fondo de la página del formulario.') ?></label>
                        <input type="color" class="form-control form-control-color w-100" id="stBackground" value="#f6f6f6">
                    </div>
                    <div class="col-md-4">
                        <label for="stFont" class="form-label small fw-semibold">Fuente<?= $helpButton('Solo letras, números, espacios y <code>- _ . ,</code>. Se admiten alternativas separadas por coma. <strong>Sin comillas:</strong> escribe <code>Times New Roman, serif</code>, no <code>\'Times New Roman\', serif</code>. Y ten en cuenta que esto no descarga la fuente: si el visitante no la tiene instalada, se verá la que tenga.') ?></label>
                        <input type="text" class="form-control form-control-sm" id="stFont" list="stFontList" placeholder="Ej: Georgia, serif">
                        <datalist id="stFontList">
                            <option value="system-ui, sans-serif"></option>
                            <option value="Georgia, serif"></option>
                            <option value="Inter, sans-serif"></option>
                            <option value="Source Sans 3, sans-serif"></option>
                            <option value="Verdana, sans-serif"></option>
                            <option value="monospace"></option>
                        </datalist>
                        <p class="form-text mb-0" id="stFontNote" role="status"></p>
                    </div>
                </div>
                <div class="border rounded p-3 mb-4 bg-light">
                    <p class="small fw-semibold mb-2"><i class="fa-solid fa-eye me-1"></i>Así se verá <span class="text-muted fw-normal">(solo los colores; la fuente depende del equipo de quien responde)</span></p>
                    <div class="d-flex flex-wrap align-items-center gap-3" id="stThemePreview">
                        <button type="button" class="btn btn-sm" id="stThemePreviewBtn" disabled>Enviar</button>
                        <span class="small fw-semibold" id="stThemePreviewTitle">Título del formulario</span>
                        <span class="small flex-grow-1" style="min-width:120px;max-width:220px;height:6px;border-radius:3px;background:#dee2e6"><span id="stThemePreviewBar" style="display:block;height:100%;width:60%;border-radius:3px"></span></span>
                    </div>
                </div>

                <h6 class="fw-bold text-uppercase small text-muted app-settings-group-title"><i class="fa-solid fa-toggle-on me-1"></i>Comportamiento</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="stQuizEnabled">
                            <label class="form-check-label small fw-semibold" for="stQuizEnabled">Modo quiz<?= $helpButton('Muestra la respuesta correcta y lleva la cuenta de aciertos al final.') ?></label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="stRandQuestions">
                            <label class="form-check-label small fw-semibold" for="stRandQuestions">Aleatorizar preguntas<?= $helpButton('Cada persona ve las preguntas en un orden distinto.') ?></label>
                        </div>
                    </div>
                    <?php // F5 — mismo criterio que el avance automático: su texto
                          // depende del interruptor, así que es estado y se queda
                          // a la vista. ?>
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="stAdvanceAuto">
                            <label class="form-check-label small fw-semibold" for="stAdvanceAuto">Avanzar solo al responder</label>
                            <p class="small text-muted mb-0 mt-1" id="stAdvanceHint" role="status"></p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="stEmbedEnabled">
                            <label class="form-check-label small fw-semibold" for="stEmbedEnabled">Permitir embeber (iframe)<?= $helpButton('Habilita el código para insertar el formulario dentro de otra web.') ?></label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="stHoneypotEnabled">
                            <label class="form-check-label small fw-semibold" for="stHoneypotEnabled">Anti-spam (honeypot)<?= $helpButton('Una trampa invisible para los robots. Si la rellenan, el envío se acepta pero <strong>no se guarda</strong>, así que quien la rellenó no ve ningún error.') ?></label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="stAntiBotEnabled">
                            <label class="form-check-label small fw-semibold" for="stAntiBotEnabled">Verificación bot (Turnstile)<?= $helpButton('<strong>Actívalo solo si ya hay claves de Cloudflare en el servidor.</strong> Sin ellas el formulario deja de mostrarse a todo el mundo, no solo a los bots.') ?></label>
                        </div>
                    </div>
                </div>
                <?php // Lo que falta aquí a propósito: acceso, agenda, cupo, avisos,
                      // webhook, mensaje final, revisión e idioma. Se editan en la
                      // ficha del formulario (`/forms/{id}`), que es donde se ven
                      // sus consecuencias (a quién llega el aviso, si la lista está
                      // vacía, si el formulario vuelve a la cola). El constructor se
                      // queda con lo que cambia el RELLENO, que es otra cosa. ?>
                <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success app-btn-primary" id="btnSaveSettings"><i class="fa-solid fa-check me-1"></i>Guardar configuración</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addFieldModal" tabindex="-1" aria-hidden="true" aria-labelledby="addFieldTitle">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0">
            <div class="modal-header app-navbar text-white border-0 py-2">
                <div class="d-flex align-items-center gap-2 text-white">
                    <span class="app-logo-sm"><i class="fa-solid fa-plus"></i></span>
                    <span class="fw-semibold" id="addFieldTitle">Agregar elemento</span>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small mb-4" id="addFieldHint">Elige el tipo de elemento que quieres agregar al formulario.</p>

                <h6 class="app-type-grid-title" data-vf-structural-part><i class="fa-solid fa-layer-group me-1"></i>Estructura</h6>
                <div class="app-type-grid mb-4" data-vf-structural-part>
                    <?php foreach ($fieldTypes as $type => $info): if (!in_array($type, ['section', 'page'], true)) continue; ?>
                    <button type="button" class="app-type-tile app-type-tile-structural" data-type="<?= View::escape($type) ?>" data-structural="1">
                        <span class="app-palette-icon"><i class="fa-solid <?= View::escape($info['icon']) ?>"></i></span>
                        <span class="app-type-tile-label"><?= View::escape($info['label']) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>

                <h6 class="app-type-grid-title"><i class="fa-solid fa-list-check me-1"></i>Tipos de pregunta</h6>
                <div class="app-type-grid">
                    <?php foreach ($fieldTypes as $type => $info): if (in_array($type, ['section', 'page'], true)) continue; ?>
                    <button type="button" class="app-type-tile" data-type="<?= View::escape($type) ?>">
                        <span class="app-palette-icon"><i class="fa-solid <?= View::escape($info['icon']) ?>"></i></span>
                        <span class="app-type-tile-label"><?= View::escape($info['label']) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="builderApp"
     data-form-id="<?= (int) $form['id'] ?>"
     data-slug="<?= View::escape($form['slug']) ?>"
     data-fields="<?= View::escape($formFields) ?>"
     data-settings="<?= View::escape($formSettingsJson) ?>"
     data-upload-limits="<?= View::escape($uploadLimits) ?>"
     data-csrf="<?= View::escape(\App\Core\Csrf::token()) ?>"
     data-status="<?= View::escape($form['status']) ?>"></div>

<p class="text-muted small text-center mb-0 mt-3">
    <i class="fa-solid fa-keyboard me-1"></i>Atajos: <kbd>Ctrl</kbd>+<kbd>S</kbd> guardar · <kbd>Ctrl</kbd>+<kbd>Z</kbd> deshacer · <kbd>Ctrl</kbd>+<kbd>D</kbd> duplicar · <kbd>Supr</kbd> eliminar · <kbd>Esc</kbd> cerrar selección · autoguardado activo
</p>

<script src="<?= View::asset('js/sortable.min.js') ?>" defer></script>
<script src="<?= View::asset('js/builder.js') ?>" defer></script>