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
        <div class="app-head-actions">
            <button type="button" class="btn btn-primary app-btn-create" data-bs-toggle="modal" data-bs-target="#newFormModal">
                <i class="fa-solid fa-plus me-1"></i>Nuevo formulario
            </button>
        </div>
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

    <?php // M2 — Los DOS vacíos se distinguen por las CUENTAS del alcance, no
          // por `empty($forms)`: un filtro que no casa con nada también deja la
          // página sin filas, y con el criterio viejo eso caía en el «aún no
          // tienes formularios», con su botón de crear el primero, que es
          // mentira cuando hay catorce. `$listado['total']` no vale para
          // distinguirlos porque viene filtrado: con el filtro puesto es 0 en
          // los dos casos. ?>
    <?php if ((int) $stats['total'] === 0): ?>
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
        <?php // M2 — La barra de búsqueda/filtro/orden es un formulario GET de verdad:
          // los tres viajan en la URL (`?q=…&estado=…&orden=…`) y el servidor
          // pinta solo la página que toca. Antes era todo de cliente sobre el
          // listado entero, y eso con el `LIMIT` en SQL solo habría roto la
          // pantalla. Los botones de estado son enlaces porque el estado que
          // está puesto tiene que ser un estado de la URL, no un `active` que
          // solo existe en memoria. ?>
    <?php $filtroQs = static function (array $cambios) use ($filtros): string {
        $qs = array_filter([
            'q'      => $filtros['q'] !== '' ? $filtros['q'] : null,
            'estado' => $filtros['estado'] !== '' ? $filtros['estado'] : null,
            'orden'  => $filtros['orden'] !== 'updated' ? $filtros['orden'] : null,
        ], static fn ($v): bool => $v !== null);
        foreach ($cambios as $k => $v) {
            if ($v === '' || $v === null) {
                unset($qs[$k]);
            } else {
                $qs[$k] = $v;
            }
        }
        return $qs === [] ? '' : '?' . http_build_query($qs);
    }; ?>
        <form method="get" action="/dashboard" class="d-flex flex-wrap align-items-center gap-2 mb-3" data-vf-dash-search>
            <?php // El filtro que se está aplicando viaja escondido: el
                  // buscador conserva el estado y el enlace de estado conserva
                  // lo escrito. Sin esto, filtrar borraba la búsqueda. ?>
            <input type="hidden" name="estado" value="<?= View::escape($filtros['estado']) ?>">
            <input type="hidden" name="orden" value="<?= View::escape($filtros['orden']) ?>">
            <div class="input-group input-group-sm app-dash-search">
                <span class="input-group-text app-dash-search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" id="dashSearch" name="q" value="<?= View::escape($filtros['q']) ?>" class="form-control form-control-sm" placeholder="Buscar por título o descripción…" aria-label="Buscar formulario">
            </div>
            <div class="btn-group btn-group-sm app-dash-filters" id="dashFilters" role="group" aria-label="Filtrar por estado">
                <?php foreach ([
                    'all'       => 'Todos',
                    'published' => 'Publicados',
                    'draft'     => 'Borradores',
                    // F3 — Su propio filtro: un formulario en revisión NO es un borrador
                    // y filtrarlo con los borradores lo escondería de quien tiene
                    // la tarea de aprobarlo.
                    'in_review' => 'En revisión',
                    'closed'    => 'Cerrados',
                ] as $clave => $texto): ?>
                    <a class="btn btn-outline-secondary<?= $filtros['estado'] === $clave ? ' active' : '' ?>"
                       href="/dashboard<?= $filtroQs(['estado' => $clave === 'all' ? '' : $clave, 'page' => null]) ?>"
                       <?= $filtros['estado'] === $clave ? 'aria-current="true"' : '' ?>><?= $texto ?></a>
                <?php endforeach; ?>
            </div>
            <select id="dashSort" name="orden" class="form-select form-select-sm app-dash-sort" aria-label="Ordenar">
                <?php foreach ([
                    'updated'  => 'Más recientes',
                    'alpha'    => 'Alfabético',
                    'subs'     => 'Más respuestas',
                    'activity' => 'Última actividad',
                ] as $clave => $texto): ?>
                    <option value="<?= $clave ?>"<?= $filtros['orden'] === $clave ? ' selected' : '' ?>><?= $texto ?></option>
                <?php endforeach; ?>
            </select>
            <?php // Sin JS el `Enter` del buscador envía el formulario, pero el
                  // desplegable no tiene a dónde ir solo: este botón es su
                  // camino, y con JS no lo enseña nadie. ?>
            <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Aplicar</button></noscript>
        </form>

        <?php if (empty($forms)): ?>
            <?php // Con la barra todavía arriba: quitar el filtro se hace desde
                  // aquí, sin tener que saber la URL del dashboard. ?>
            <div id="dashNoResults">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-5">
                        <div class="app-empty-icon mb-3"><i class="fa-solid fa-magnifying-glass"></i></div>
                        <h4 class="h6 fw-bold">Sin resultados</h4>
                        <p class="text-muted small mb-3">Ningún formulario coincide con tu búsqueda o filtro.</p>
                        <?php if ($filtros['q'] !== '' || $filtros['estado'] !== '' || $filtros['orden'] !== 'updated'): ?>
                            <a href="/dashboard" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-rotate-left me-1"></i>Quitar el filtro</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
        <div class="row g-3" id="dashGrid">
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
                                <?= View::escape(\App\Models\Form::statusLabel((string) $form['status'])) ?>
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
                                                      data-confirm="¿Publicar este formulario? Aprobarlo lo deja visible para cualquiera que tenga el enlace, y ya no es un borrador."
                                                      data-confirm-ok="Publicar" data-confirm-tone="primary">
                                                    <?= \App\Core\Csrf::field() ?>
                                                    <button type="submit" class="dropdown-item"><i class="fa-solid fa-check me-2 text-success"></i>Aprobar</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="post" action="/forms/<?= (int) $form['id'] ?>/review/return" class="m-0" data-confirm="¿Devolver este formulario al dueño como borrador? No se publicará."
                                                data-confirm-ok="Devolver" data-confirm-tone="primary">
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
                                                  data-confirm="¿Eliminar este formulario y todas sus respuestas? Esta acción no se puede deshacer."
                                                  data-confirm-ok="Eliminar formulario">
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

        <?= View::partial('dashboard/_pagination', [
            'listado' => $listado,
            'base'    => '/dashboard',
            'qs'      => $filtroQs([]),
        ]) ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Modal Nuevo Formulario -->
<div class="modal fade" id="newFormModal" tabindex="-1" aria-labelledby="newFormModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <?php // El <form> ES el .modal-content: Bootstrap lo necesita como hijo
              // directo de .modal-dialog para que su flex (header/body/footer)
              // funcione y el .modal-body pueda scrollear. Envolverlo en un
              // <form> rompía la cadena flex: el .modal-content recortaba con
              // overflow:hidden y el cuerpo no bajaba (no había scroll). ?>
        <form method="post" action="/forms" class="modal-content border-0">
            <?= \App\Core\Csrf::field() ?>
                <div class="modal-header app-navbar text-white border-0 py-2">
                    <div class="d-flex align-items-center gap-2 text-white">
                        <span class="app-logo-sm"><i class="fa-solid fa-plus"></i></span>
                        <span class="fw-semibold" id="newFormModalLabel">Nuevo formulario</span>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-4">
                    <?php // P9/V6 — Galería de arranque: una tarjeta «en blanco» y una
                          // por plantilla, al estilo de la portada de Google Forms. El
                          // servidor NO lee `mode`: solo `template` (vacío = en blanco),
                          // así que la elección es un único grupo de radios `template` y
                          // elegir una tarjeta ya decide el modo. ?>
                    <h6 class="app-type-grid-title"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>¿Cómo quieres empezar?</h6>
                    <div class="app-tpl-grid mb-4">
                        <label class="app-tpl-card app-tpl-checked">
                            <input class="d-none" type="radio" name="template" value="" checked>
                            <span class="app-tpl-cover app-tpl-cover-blank"><i class="fa-solid fa-plus"></i></span>
                            <span class="app-tpl-body">
                                <span class="app-tpl-name"><?= View::escape((string) ($blank['name'] ?? 'Formulario en blanco')) ?></span>
                                <span class="app-tpl-desc"><?= View::escape((string) ($blank['description'] ?? '')) ?></span>
                            </span>
                        </label>
                        <?php if (!empty($templates)): ?>
                            <?php
                            // Icono de la portada de la tarjeta: el del primer tipo
                            // de la plantilla (`TemplateService::list()` → `types`).
                            $vfTypeIcon = [
                                'text' => 'fa-i-cursor', 'textarea' => 'fa-align-left', 'email' => 'fa-envelope',
                                'number' => 'fa-hashtag', 'date' => 'fa-calendar', 'time' => 'fa-clock',
                                'datetime-local' => 'fa-calendar-check', 'select' => 'fa-caret-down',
                                'radio' => 'fa-circle-dot', 'checkbox' => 'fa-square-check',
                                'url' => 'fa-link', 'tel' => 'fa-phone', 'scale' => 'fa-star',
                                'range' => 'fa-sliders', 'nps' => 'fa-chart-column',
                                'star' => 'fa-star-half-stroke', 'matrix' => 'fa-table',
                                'ranking' => 'fa-list-ol', 'file' => 'fa-paperclip',
                            ];
                            ?>
                            <?php foreach ($templates as $tpl): ?>
                                <?php $tipos = is_array($tpl['types'] ?? null) ? $tpl['types'] : []; ?>
                                <label class="app-tpl-card">
                                    <input class="d-none" type="radio" name="template" value="<?= View::escape((string) $tpl['key']) ?>">
                                    <span class="app-tpl-cover"><i class="fa-solid <?= View::escape($vfTypeIcon[(string) ($tipos[0] ?? '')] ?? 'fa-clipboard-list') ?>"></i></span>
                                    <span class="app-tpl-body">
                                        <span class="app-tpl-name"><?= View::escape((string) $tpl['name']) ?></span>
                                        <?php if (!empty($tpl['description'])): ?>
                                            <span class="app-tpl-desc"><?= View::escape((string) $tpl['description']) ?></span>
                                        <?php endif; ?>
                                        <span class="app-tpl-q"><?= (int) $tpl['questions'] ?> pregunta<?= (int) $tpl['questions'] === 1 ? '' : 's' ?><?php if ((int) $tpl['sections'] > 0): ?> · <?= (int) $tpl['sections'] ?> sección<?= (int) $tpl['sections'] === 1 ? '' : 'es' ?><?php endif; ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($templates)): ?>
                        <div class="form-text mb-4">No hay plantillas instaladas en <code>database/templates</code>.</div>
                    <?php endif; ?>
                    <div class="form-text">El formulario toma como título y descripción lo que dice la tarjeta elegida; los cambias en el constructor.</div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary app-btn-primary"><i class="fa-solid fa-check me-1"></i>Crear</button>
                </div>
        </form>
    </div>
</div>

<script src="<?= View::asset('js/dashboard.js') ?>" defer></script>