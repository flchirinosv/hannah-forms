<?php
use App\Core\View;
?>
<div class="container-fluid px-lg-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="h4 fw-bold mb-0"><i class="fa-solid fa-clipboard-list me-2 text-primary"></i>Todos los formularios</h2>
            <p class="text-muted small mb-0">Vista global de formularios de todos los usuarios.</p>
        </div>
        <a href="/admin" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Volver a usuarios</a>
    </div>

    <div class="row g-3" data-paginate data-rows="9">
        <?php if (empty($forms)): ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>No hay formularios registrados.
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($forms as $form): ?>
            <div class="col-md-6 col-xl-4 paginate-item">
                <div class="card border-0 shadow-sm app-form-card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-start justify-content-between mb-2">
                            <span class="app-status app-status-<?= View::escape($form['status']) ?>"><?= ucfirst(View::escape($form['status'])) ?></span>
                            <span class="app-sub-count small text-muted"><i class="fa-solid fa-file-lines me-1"></i><?= (int) $form['submissions_count'] ?></span>
                        </div>
                        <h4 class="h6 fw-bold mb-1"><?= View::escape($form['title']) ?></h4>
                        <p class="text-muted small mb-2">Propietario: <strong><?= View::escape($form['owner_name']) ?></strong></p>
                        <div class="d-flex gap-2 mt-auto">
                            <a href="/forms/<?= (int) $form['id'] ?>/analytics" class="btn btn-sm btn-outline-success app-btn-icon" title="Analítica"><i class="fa-solid fa-chart-column"></i></a>
                            <a href="/f/<?= View::escape($form['slug']) ?>" target="_blank" class="btn btn-sm btn-outline-info app-btn-icon" title="Ver público"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>