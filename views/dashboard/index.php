<?php
use App\Core\View;

// El texto relativo y los data-*epoch se resuelven con los helpers de View:
// antes esta vista hacía `time() - strtotime($timestamp)`, que con PHP fuera de
// UTC iba desviado (y con PHP en Mexico City, dos horas más). Ver
// App\Core\View::relative() y View::timestamp().
$relativeTs = static function (string $timestamp): string {
    if ($timestamp === '') {
        return 'Sin respuestas aún';
    }
    return View::relative($timestamp);
};
?>
<div class="container-fluid px-lg-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3">
        <div>
            <h2 class="h4 fw-bold mb-0">Hola, <?= View::escape($user['name']) ?> 👋</h2>
            <p class="text-muted mb-0 small">Gestiona tus formularios y analiza las respuestas.</p>
        </div>
        <button type="button" class="btn btn-primary app-btn-create" data-bs-toggle="modal" data-bs-target="#newFormModal">
            <i class="fa-solid fa-plus me-1"></i>Nuevo formulario
        </button>
    </div>

    <?php
    $cards = [
        ['label' => 'Formularios', 'value' => $stats['total'], 'icon' => 'fa-list-check', 'color' => 'primary'],
        ['label' => 'Publicados', 'value' => $stats['published'] ?? 0, 'icon' => 'fa-globe', 'color' => 'success'],
        ['label' => 'Borradores', 'value' => $stats['draft'] ?? 0, 'icon' => 'fa-pen', 'color' => 'warning'],
        ['label' => 'Respuestas', 'value' => $stats['submissions'], 'icon' => 'fa-file-lines', 'color' => 'info'],
    ];
    ?>
    <div class="row g-3 mb-4">
        <?php foreach ($cards as $card): ?>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm app-stat-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="app-stat-icon bg-<?= $card['color'] ?>-subtle text-<?= $card['color'] ?>">
                        <i class="fa-solid <?= $card['icon'] ?>"></i>
                    </div>
                    <div>
                        <div class="app-stat-value"><?= number_format((int) $card['value']) ?></div>
                        <div class="app-stat-label text-muted small"><?= View::escape($card['label']) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php // F3 — Aviso permanente de que hay algo en revisión. Va fuera de las
          // tarjetas de conteo a propósito: los 4 tiles llenan la fila y meter un
          // quinto solo cuando aplica deja una rejilla coja según el estado. ?>
    <?php if (($stats['in_review'] ?? 0) > 0): ?>
        <div class="alert alert-info d-flex flex-wrap align-items-center gap-2 py-2 px-3 small app-share-hint" role="alert">
            <i class="fa-solid fa-hourglass-half"></i>
            <span><?= (int) $stats['in_review'] ?> formulario<?= (int) $stats['in_review'] === 1 ? '' : 's' ?> en revisión: no se sirve al público hasta que se apruebe.</span>
            <?php if ($canReview ?? false): ?>
                <a href="/review" class="alert-link ms-auto">Ir a la bandeja de revisión <i class="fa-solid fa-arrow-right ms-1"></i></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($forms)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <div class="app-empty-icon mb-3"><i class="fa-solid fa-clipboard-list"></i></div>
                <h4 class="h6 fw-bold">Aún no tienes formularios</h4>
                <p class="text-muted small">Crea tu primer formulario y empieza a recopilar respuestas.</p>
                <button type="button" class="btn btn-primary app-btn-primary" data-bs-toggle="modal" data-bs-target="#newFormModal">
                    <i class="fa-solid fa-plus me-1"></i>Crear formulario
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <div class="input-group input-group-sm app-dash-search">
                <span class="input-group-text app-dash-search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" id="dashSearch" class="form-control form-control-sm" placeholder="Buscar por título o descripción…" aria-label="Buscar formulario">
            </div>
            <div class="btn-group btn-group-sm app-dash-filters" id="dashFilters" role="group" aria-label="Filtrar por estado">
                <button type="button" class="btn btn-outline-secondary active" data-filter="all">Todos</button>
                <button type="button" class="btn btn-outline-secondary" data-filter="published">Publicados</button>
                <button type="button" class="btn btn-outline-secondary" data-filter="draft">Borradores</button>
                <?php // F3 — Su propio filtro: un formulario en revisión NO es un borrador
                      // y filtrarlo con los borradores lo escondería de quien tiene
                      // la tarea de aprobarlo. ?>
                <button type="button" class="btn btn-outline-secondary" data-filter="in_review">En revisión</button>
                <button type="button" class="btn btn-outline-secondary" data-filter="closed">Cerrados</button>
            </div>
            <select id="dashSort" class="form-select form-select-sm app-dash-sort" aria-label="Ordenar">
                <option value="updated">Más recientes</option>
                <option value="alpha">Alfabético</option>
                <option value="subs">Más respuestas</option>
                <option value="activity">Última actividad</option>
            </select>
        </div>

        <div class="row g-3" id="dashGrid" data-paginate data-rows="9">
            <?php foreach ($forms as $form): ?>
            <?php
            $subCount = (int) ($form['submissions_count'] ?? 0);
            $lastAt = (string) ($form['last_submission_at'] ?? '');
            $lastText = $relativeTs($lastAt);
            $publicUrl = '/f/' . $form['slug'];
            ?>
            <div class="col-md-6 col-xl-4 app-dash-card-col paginate-item"
                 data-status="<?= View::escape($form['status']) ?>"
                 data-title="<?= View::escape(mb_strtolower($form['title'] . ' ' . $form['description'])) ?>"
                 data-subs="<?= $subCount ?>"
                 data-updated="<?= View::timestamp($form['updated_at']) ?>"
                 data-activity="<?= $lastAt === '' ? 0 : View::timestamp($lastAt) ?>">
                <div class="card border-0 shadow-sm app-form-card h-100 app-dash-card">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-start justify-content-between mb-2">
                            <span class="app-status app-status-<?= View::escape($form['status']) ?>">
                                <?= $form['status'] === 'in_review' ? 'En revisión' : ucfirst(View::escape($form['status'])) ?>
                            </span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="app-sub-count small text-muted d-none d-sm-inline">
                                    <i class="fa-solid fa-file-lines me-1"></i><?= $subCount ?>
                                </span>
                                <div class="dropdown app-dash-dd">
                                    <button class="btn btn-sm btn-light app-dd-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Más acciones">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 app-dash-menu">
                                        <li><a class="dropdown-item" href="#" data-role="copy" data-url="<?= View::escape($publicUrl) ?>"><i class="fa-solid fa-link me-2 text-muted"></i>Copiar enlace</a></li>
                                        <li><a class="dropdown-item" href="<?= $publicUrl ?>" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square me-2 text-muted"></i>Abrir público</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <?php // F3 — La bandera se lee del JSON de `settings` que YA
                                              // viene en la fila (`f.*`): llamar aquí a
                                              // `Form::settings()` por formulario serían N consultas
                                              // en cada carga del dashboard, y el listado está
                                              // cacheado precisamente para no pagarlas. ?>
                                        <?php $needsReview = !empty(\App\Models\Form::settingsFrom($form)['review']['enabled']); ?>
                                        <?php if ($form['status'] === 'draft' || $form['status'] === 'closed'): ?>
                                            <?php // Un formulario con revisión no ofrece «Publicar»: el botón
                                                  // solo produciría un error de vuelta. ?>
                                            <?php if ($form['status'] === 'draft' && $needsReview): ?>
                                                <li>
                                                    <form method="post" action="/forms/<?= (int) $form['id'] ?>/review/send" class="m-0">
                                                        <?= \App\Core\Csrf::field() ?>
                                                        <button type="submit" class="dropdown-item"><i class="fa-solid fa-paper-plane me-2 text-info"></i>Enviar a revisión</button>
                                                    </form>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <form method="post" action="/forms/<?= (int) $form['id'] ?>/publish" class="m-0">
                                                        <?= \App\Core\Csrf::field() ?>
                                                        <button type="submit" class="dropdown-item"><i class="fa-solid fa-globe me-2 text-success"></i><?= $form['status'] === 'closed' ? 'Reabrir' : 'Publicar' ?></button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php // F3 — Aprobar/devolver desde la propia tarjeta, que es
                                              // donde el revisor ya está mirando. ?>
                                        <?php if ($form['status'] === 'in_review' && ($canReview ?? false)): ?>
                                            <li>
                                                <form method="post" action="/forms/<?= (int) $form['id'] ?>/review/approve" class="m-0"
                                                      data-confirm="¿Publicar este formulario? Aprobarlo lo deja visible para cualquiera que tenga el enlace, y ya no es un borrador.">
                                                    <?= \App\Core\Csrf::field() ?>
                                                    <button type="submit" class="dropdown-item"><i class="fa-solid fa-check me-2 text-success"></i>Aprobar</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="post" action="/forms/<?= (int) $form['id'] ?>/review/return" class="m-0" data-confirm="¿Devolver este formulario al dueño como borrador? No se publicará.">
                                                    <?= \App\Core\Csrf::field() ?>
                                                    <button type="submit" class="dropdown-item"><i class="fa-solid fa-rotate-left me-2 text-warning"></i>Devolver a borrador</button>
                                                </form>
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($form['status'] === 'published'): ?>
                                            <li>
                                                <form method="post" action="/forms/<?= (int) $form['id'] ?>/close" class="m-0">
                                                    <?= \App\Core\Csrf::field() ?>
                                                    <button type="submit" class="dropdown-item"><i class="fa-solid fa-lock me-2 text-warning"></i>Cerrar</button>
                                                </form>
                                            </li>
                                        <?php endif; ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="post" action="/forms/<?= (int) $form['id'] ?>/delete" class="m-0"
                                                  data-confirm="¿Eliminar este formulario y todas sus respuestas? Esta acción no se puede deshacer.">
                                                <?= \App\Core\Csrf::field() ?>
                                                <button type="submit" class="dropdown-item text-danger"><i class="fa-solid fa-trash me-2"></i>Eliminar</button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <h4 class="h6 fw-bold card-title mb-1"><?= View::escape($form['title']) ?></h4>
                        <p class="text-muted small mb-2 flex-grow-1 app-truncate-2"><?= View::escape($form['description'] ?: 'Sin descripción') ?></p>
                        <div class="app-dash-meta small text-muted mb-3">
                            <span><i class="fa-regular fa-clock me-1"></i>Últ. respuesta: <?= View::escape($lastText) ?></span>
                        </div>
                        <?php
                        // V5 — Sparkline de actividad (últimos días, por día local).
                        $sparkCounts = $activity['forms'][(int) $form['id']] ?? null;
                        if (is_array($sparkCounts) && count($sparkCounts) > 0):
                            $sparkMax = max(1, (int) ($activity['max'] ?? 0));
                            $sparkW = 150;
                            $sparkH = 24;
                            $sparkN = count($sparkCounts);
                            $barW = $sparkW / $sparkN;
                        ?>
                        <div class="app-spark mb-3" title="Actividad: respuestas en los últimos <?= $sparkN ?> días" aria-label="Actividad: respuestas en los últimos <?= $sparkN ?> días">
                            <svg class="app-spark-svg" viewBox="0 0 <?= $sparkW ?> <?= $sparkH ?>" preserveAspectRatio="none" role="img">
                                <?php foreach ($sparkCounts as $sparkI => $sparkV): ?>
                                    <?php
                                        $sparkV = (int) $sparkV;
                                        $h = $sparkV > 0 ? max(2, (int) round(($sparkV / $sparkMax) * ($sparkH - 3))) : 1;
                                        $x = round($sparkI * $barW);
                                        $barWd = max(2, (int) round($barW) - 1);
                                    ?>
                                    <rect class="<?= $sparkI === $sparkN - 1 ? 'app-spark-last' : 'app-spark-bar' ?>" x="<?= $x ?>" y="<?= $sparkH - $h ?>" width="<?= $barWd ?>" height="<?= $h ?>" rx="1"></rect>
                                <?php endforeach; ?>
                            </svg>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex gap-2">
                            <a href="/forms/<?= (int) $form['id'] ?>" class="btn btn-sm btn-outline-secondary app-btn-icon" title="Ver detalles"><i class="fa-solid fa-eye"></i></a>
                            <a href="/forms/<?= (int) $form['id'] ?>/builder" class="btn btn-sm btn-outline-primary app-btn-icon" title="Editar campos"><i class="fa-solid fa-pen"></i></a>
                            <a href="/forms/<?= (int) $form['id'] ?>/analytics" class="btn btn-sm btn-outline-success app-btn-icon" title="Analítica"><i class="fa-solid fa-chart-column"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-info app-btn-icon btn-copy-link" data-url="<?= View::escape($publicUrl) ?>" title="Copiar enlace"><i class="fa-solid fa-copy"></i></button>
                            <?php if ($form['status'] !== 'published'): ?>
                            <a href="<?= $publicUrl ?>" target="_blank" class="btn btn-sm btn-outline-secondary app-btn-icon" title="Abrir público"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div id="dashNoResults" class="d-none">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <div class="app-empty-icon mb-3"><i class="fa-solid fa-magnifying-glass"></i></div>
                    <h4 class="h6 fw-bold">Sin resultados</h4>
                    <p class="text-muted small mb-0">Ningún formulario coincide con tu búsqueda o filtro.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Nuevo Formulario -->
<div class="modal fade" id="newFormModal" tabindex="-1" aria-labelledby="newFormModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="post" action="/forms">
                <?= \App\Core\Csrf::field() ?>
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold" id="newFormModalLabel"><i class="fa-solid fa-plus-circle me-2 text-primary"></i>Nuevo formulario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php // P9 — Arranque en blanco o desde plantilla (database/templates). ?>
                    <div class="mb-3">
                        <span class="form-label d-block">¿Cómo quieres empezar?</span>
                        <div class="d-flex flex-wrap gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="mode" id="modeBlank" value="blank" checked>
                                <label class="form-check-label" for="modeBlank">En blanco</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="mode" id="modeTemplate" value="template">
                                <label class="form-check-label" for="modeTemplate">Desde una plantilla</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3" id="templatePicker" hidden>
                        <span class="form-label d-block">Elige una plantilla</span>
                        <?php if (empty($templates)): ?>
                            <div class="form-text">No hay plantillas instaladas en database/templates.</div>
                        <?php else: ?>
                            <?php
                            // V6 — Mini-preview declarativa desde el JSON de la
                            // plantilla (TemplateService::list → `types`).
                            $vfTypeLabel = [
                                'text' => 'Texto', 'textarea' => 'Texto largo', 'email' => 'Email',
                                'number' => 'Número', 'date' => 'Fecha', 'time' => 'Hora',
                                'datetime-local' => 'Fecha y hora', 'select' => 'Desplegable',
                                'radio' => 'Opción única', 'checkbox' => 'Multiopción',
                                'url' => 'URL', 'tel' => 'Teléfono', 'scale' => 'Escala',
                                'range' => 'Rango', 'nps' => 'NPS', 'star' => 'Estrellas',
                                'matrix' => 'Matriz', 'ranking' => 'Ranking', 'file' => 'Archivo',
                            ];
                            ?>
                            <div class="row g-2">
                                <?php foreach ($templates as $tplIdx => $tpl): ?>
                                <div class="col-sm-6">
                                    <label class="app-tpl-card<?= $tplIdx === 0 ? ' app-tpl-checked' : '' ?>">
                                        <input class="form-check-input d-none" type="radio" name="template"
                                               value="<?= View::escape((string) $tpl['key']) ?>"<?= $tplIdx === 0 ? ' checked' : '' ?>>
                                        <span class="app-tpl-body">
                                            <span class="app-tpl-name"><?= View::escape((string) $tpl['name']) ?></span>
                                            <?php if (!empty($tpl['description'])): ?>
                                                <span class="app-tpl-desc"><?= View::escape((string) $tpl['description']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($tpl['types'])): ?>
                                                <span class="app-tpl-chips">
                                                    <?php foreach ($tpl['types'] as $tfType): ?>
                                                        <span class="app-tpl-chip" title="<?= View::escape((string) $tfType) ?>"><?= View::escape($vfTypeLabel[(string) $tfType] ?? (string) $tfType) ?></span>
                                                    <?php endforeach; ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="app-tpl-q small text-muted"><?= (int) $tpl['questions'] ?> preguntas<?= (int) $tpl['sections'] > 0 ? ' · ' . (int) $tpl['sections'] . ' secciones' : '' ?></span>
                                        </span>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="title" class="form-label">Título <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="title" name="title" placeholder="Ej: Encuesta de satisfacción" required autofocus>
                        <div class="form-text">Si eliges una plantilla y dejas el título vacío, se usa el de la plantilla.</div>
                    </div>
                    <div class="mb-1">
                        <label for="description" class="form-label">Descripción</label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="Describe el propósito del formulario (opcional)"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary app-btn-primary"><i class="fa-solid fa-check me-1"></i>Crear</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?= View::asset('js/dashboard.js') ?>" defer></script>