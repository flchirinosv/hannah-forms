<?php
use App\Core\View;

// F6 — Buscador/filtro/orden viajan en la URL y se resuelven en SQL (Form::page),
// con la paginación server-side. El buscador de `Enter` y el selector de orden se
// envían solos; los estados son enlaces para que el que está puesto sea el de la
// URL y no un `active` en memoria.
$filtros = $filtros ?? ['q' => '', 'estado' => '', 'orden' => 'updated'];
$filtrado = $filtros['q'] !== '' || $filtros['estado'] !== '' || $filtros['orden'] !== 'updated';
$filtroQs = static function (array $cambios) use ($filtros): string {
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
};
?>
<div class="container-fluid px-lg-4">
    <?= View::partial('admin/_page_head', [
        'active'   => 'forms',
        'icon'     => 'fa-clipboard-list',
        'title'    => 'Todos los formularios',
        'subtitle' => 'Vista global de formularios de todos los usuarios.',
        'actions'  => '<a href="/admin" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Volver a usuarios</a>',
    ]) ?>

    <?php if ((int) $listado['total'] === 0 && !$filtrado): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5 text-muted">
                <div class="app-empty-icon mb-3"><i class="fa-solid fa-inbox"></i></div>
                <h4 class="h6 fw-bold text-body">No hay formularios registrados</h4>
                <p class="small mb-0">Cuando alguien cree uno, aparecerá aquí.</p>
            </div>
        </div>
    <?php else: ?>
        <form method="get" action="/admin/forms" class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <input type="hidden" name="estado" value="<?= View::escape($filtros['estado']) ?>">
            <div class="input-group input-group-sm app-dash-search">
                <span class="input-group-text app-dash-search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="search" name="q" value="<?= View::escape($filtros['q']) ?>" class="form-control form-control-sm" placeholder="Buscar por título o descripción…" aria-label="Buscar formulario">
            </div>
            <div class="btn-group btn-group-sm app-dash-filters" role="group" aria-label="Filtrar por estado">
                <?php foreach ([
                    'all'       => 'Todos',
                    'published' => 'Publicados',
                    'draft'     => 'Borradores',
                    'in_review' => 'En revisión',
                    'closed'    => 'Cerrados',
                ] as $clave => $texto): ?>
                    <a class="btn btn-outline-secondary<?= $filtros['estado'] === $clave ? ' active' : '' ?>"
                       href="/admin/forms<?= $filtroQs(['estado' => $clave === 'all' ? '' : $clave]) ?>"
                       <?= $filtros['estado'] === $clave ? 'aria-current="true"' : '' ?>><?= $texto ?></a>
                <?php endforeach; ?>
            </div>
            <select name="orden" class="form-select form-select-sm app-dash-sort js-auto-submit" aria-label="Ordenar">
                <?php foreach ([
                    'updated'  => 'Más recientes',
                    'alpha'    => 'Alfabético',
                    'subs'     => 'Más respuestas',
                    'activity' => 'Última actividad',
                ] as $clave => $texto): ?>
                    <option value="<?= $clave ?>"<?= $filtros['orden'] === $clave ? ' selected' : '' ?>><?= $texto ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Aplicar</button></noscript>
        </form>

        <?php if (empty($forms)): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <div class="app-empty-icon mb-3"><i class="fa-solid fa-magnifying-glass"></i></div>
                    <h4 class="h6 fw-bold">Sin resultados</h4>
                    <p class="text-muted small mb-3">Ningún formulario coincide con la búsqueda o el filtro.</p>
                    <a href="/admin/forms" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-rotate-left me-1"></i>Quitar los filtros</a>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($forms as $form): ?>
                <div class="col-md-6 col-xl-4 paginate-item">
                    <div class="card border-0 shadow-sm app-form-card h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex align-items-start justify-content-between mb-2">
                                <span class="app-status app-status-<?= View::escape($form['status']) ?>"><?= View::escape(\App\Models\Form::statusLabel((string) $form['status'])) ?></span>
                                <span class="app-sub-count small text-muted"><i class="fa-solid fa-file-lines me-1"></i><?= (int) $form['submissions_count'] ?></span>
                            </div>
                            <h4 class="h6 fw-bold mb-1"><?= View::escape($form['title']) ?></h4>
                            <p class="text-muted small mb-2">Propietario: <strong><?= View::escape($form['owner_name']) ?></strong></p>
                            <div class="d-flex gap-2 mt-auto">
                                <a href="/forms/<?= (int) $form['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-sliders me-1"></i>Configurar</a>
                                <a href="/forms/<?= (int) $form['id'] ?>/analytics" class="btn btn-sm btn-outline-success app-btn-icon" title="Analítica"><i class="fa-solid fa-chart-column"></i></a>
                                <a href="/f/<?= View::escape($form['slug']) ?>" target="_blank" class="btn btn-sm btn-outline-info app-btn-icon" title="Ver público"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?= View::partial('dashboard/_pagination', [
                'listado' => $listado,
                'base'    => '/admin/forms',
                'qs'      => $filtroQs([]),
            ]) ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
