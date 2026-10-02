<?php
/**
 * F3 — Bandeja del revisor.
 *
 * Lista formularios de OTROS usuarios que esperan aprobación. Es aparte del
 * dashboard a propósito: el dashboard es «tus formularios» (o todos, con
 * `forms.manage_all`), y mezclar la cola de trabajo de la instancia con el
 * listado propio de cada dueño hace que ninguno de los dos se lea bien.
 */
use App\Core\View;
?>
<div class="container px-lg-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3">
        <div>
            <h2 class="h4 fw-bold mb-0"><i class="fa-solid fa-clipboard-check me-2 text-primary"></i>Bandeja de revisión</h2>
            <p class="text-muted small mb-0 mt-2">Formularios que esperan aprobación antes de servirse al público.</p>
        </div>
    </div>

    <?php if ($pending === []): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <div class="app-stat-icon bg-success-subtle text-success mx-auto mb-3"><i class="fa-solid fa-check"></i></div>
                <h3 class="h6 fw-bold mb-1">No hay nada pendiente</h3>
                <p class="text-muted small mb-0">Cuando alguien envíe un formulario a revisión aparecerá aquí.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($pending as $p): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100 app-dash-card">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-start justify-content-between mb-2">
                            <span class="app-status app-status-in_review">En revisión</span>
                            <?php // F3 — Lo más antiguo primero es lo que se ordena así en
                                  // `pendingReviews()`: lo que lleva más tiempo parado. ?>
                            <span class="small text-muted">
                                <i class="fa-regular fa-clock me-1"></i><?= View::escape(View::relative((string) ($p['review_requested_at'] ?? ''))) ?>
                            </span>
                        </div>
                        <h3 class="h6 fw-bold card-title mb-1"><?= View::escape($p['title']) ?></h3>
                        <p class="text-muted small mb-2 flex-grow-1 app-truncate-2"><?= View::escape($p['description'] ?: 'Sin descripción') ?></p>
                        <p class="small text-muted mb-3">
                            <i class="fa-regular fa-user me-1"></i><?= View::escape($p['owner_name']) ?>
                            <span class="text-muted">· <?= View::escape($p['owner_email']) ?></span>
                        </p>
                        <div class="d-flex gap-2">
                            <a href="/forms/<?= (int) $p['id'] ?>" class="btn btn-sm btn-outline-secondary flex-fill"><i class="fa-solid fa-eye me-1"></i>Ver</a>
                            <form method="post" action="/forms/<?= (int) $p['id'] ?>/review/approve" class="m-0 flex-fill"
                                  data-confirm="¿Publicar este formulario? Aprobarlo lo deja visible para cualquiera que tenga el enlace, y ya no es un borrador."
                                  data-confirm-ok="Publicar" data-confirm-tone="primary">
                                <?= \App\Core\Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-success w-100"><i class="fa-solid fa-check me-1"></i>Aprobar</button>
                            </form>
                            <form method="post" action="/forms/<?= (int) $p['id'] ?>/review/return" class="m-0 flex-fill"
                                  data-confirm="¿Devolver este formulario al dueño como borrador? No se publicará."
                                  data-confirm-ok="Devolver" data-confirm-tone="primary">
                                <?= \App\Core\Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-warning w-100"><i class="fa-solid fa-rotate-left me-1"></i>Devolver</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
