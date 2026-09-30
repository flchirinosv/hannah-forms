<?php
use App\Core\View;
?>
<div class="container px-lg-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3">
        <div>
            <a href="/dashboard" class="text-decoration-none text-muted small"><i class="fa-solid fa-arrow-left me-1"></i>Volver al dashboard</a>
            <h2 class="h4 fw-bold mb-0 mt-2"><i class="fa-solid fa-clipboard-list me-2 text-primary"></i><?= View::escape($form['title']) ?></h2>
        </div>
        <div class="d-flex gap-2">
            <a href="/forms/<?= (int) $form['id'] ?>/builder" class="btn btn-primary app-btn-primary"><i class="fa-solid fa-pen me-1"></i>Editar campos</a>
            <a href="/forms/<?= (int) $form['id'] ?>/responses" class="btn btn-outline-primary"><i class="fa-solid fa-inbox me-1"></i>Respuestas</a>
            <a href="/forms/<?= (int) $form['id'] ?>/analytics" class="btn btn-outline-success"><i class="fa-solid fa-chart-column me-1"></i>Analítica</a>
            <?php // P9 — Vista imprimible (imprimir o PDF desde el navegador) y export de la estructura. ?>
            <a href="/forms/<?= (int) $form['id'] ?>/print" class="btn btn-outline-secondary" target="_blank" rel="noopener" title="Abrir la vista imprimible"><i class="fa-solid fa-print me-1"></i>Imprimir</a>
            <a href="/forms/<?= (int) $form['id'] ?>/export-structure.json" class="btn btn-outline-secondary" title="Descargar la estructura como plantilla JSON"><i class="fa-solid fa-file-export me-1"></i>Exportar</a>
            <form method="post" action="/forms/<?= (int) $form['id'] ?>/duplicate">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-outline-secondary" title="Crear una copia en borrador"><i class="fa-solid fa-clone me-1"></i>Duplicar</button>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h3 class="h6 fw-bold m-0">Detalles</h3>
                        <span class="app-status app-status-<?= View::escape($form['status']) ?>"><?= ucfirst(View::escape($form['status'])) ?></span>
                    </div>
                    <p class="text-muted small mb-4"><?= View::escape($form['description'] ?: 'Sin descripción') ?></p>

                    <?php // F3 — Traza de la revisión. Es lo que distingue «revisado» de
                          // «nadie miró esto»: quién lo pidió y quién lo aprobó. Solo se
                          // pinta si hay algo que contar. ?>
                    <?php $reviewTrail = $reviewTrail ?? null; ?>
                    <?php if (!empty($reviewTrail) && ($reviewTrail['solicitado'] !== null || $reviewTrail['aprobado'] !== null)): ?>
                        <div class="alert alert-light border small mb-4">
                            <div class="fw-semibold mb-1"><i class="fa-solid fa-clipboard-check me-1 text-primary"></i>Revisión</div>
                            <ul class="list-unstyled mb-0 small text-muted">
                                <?php if ($reviewTrail['solicitado'] !== null): ?>
                                    <li><i class="fa-regular fa-clock me-1"></i>Enviado a revisión el <?= View::escape(View::datetime($reviewTrail['solicitado'])) ?></li>
                                <?php endif; ?>
                                <?php if ($reviewTrail['aprobado'] !== null): ?>
                                    <li><i class="fa-solid fa-check me-1 text-success"></i>Aprobado<?= $reviewTrail['aprobador'] !== null ? ' por ' . View::escape($reviewTrail['aprobador']) : '' ?> el <?= View::escape(View::datetime($reviewTrail['aprobado'])) ?></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="/forms/<?= (int) $form['id'] ?>/update" id="formUpdate">
                        <?= \App\Core\Csrf::field() ?>
                        <div class="mb-3">
                            <label for="title" class="form-label">Título</label>
                            <input type="text" class="form-control" id="title" name="title" value="<?= View::escape($form['title']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Descripción</label>
                            <textarea class="form-control" id="description" name="description" rows="3"><?= View::escape($form['description']) ?></textarea>
                        </div>
                    </form>

                    <div class="row g-2 mb-4">
                        <div class="col-sm-4">
                            <button type="submit" form="formUpdate" class="btn btn-primary w-100 app-btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar</button>
                        </div>
                        <?php // F3 — Con revisión activa el dueño no tiene botón de publicar:
                              // el que hay sería aprobarse a uno mismo. Se muestra el
                              // de «enviar a revisión» en su lugar. ?>
                        <?php $reviewRequired = $reviewRequired ?? false; ?>
                        <?php $canReview = $canReview ?? false; ?>
                        <?php if ($form['status'] === 'draft' && $reviewRequired): ?>
                            <div class="col-sm-6">
                                <form method="post" action="/forms/<?= (int) $form['id'] ?>/review/send" id="formSendReview">
                                    <?= \App\Core\Csrf::field() ?>
                                    <button type="submit" class="btn btn-info w-100"><i class="fa-solid fa-paper-plane me-1"></i>Enviar a revisión</button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <?php if ($form['status'] === 'in_review' && $canReview): ?>
                            <div class="col-6 col-sm-3">
                                <form method="post" action="/forms/<?= (int) $form['id'] ?>/review/approve" id="formApprove">
                                    <?= \App\Core\Csrf::field() ?>
                                    <button type="submit" class="btn btn-success w-100"><i class="fa-solid fa-check me-1"></i>Aprobar</button>
                                </form>
                            </div>
                            <div class="col-6 col-sm-3">
                                <form method="post" action="/forms/<?= (int) $form['id'] ?>/review/return" id="formReturnReview" data-confirm="¿Devolver este formulario al dueño como borrador? No se publicará.">
                                    <?= \App\Core\Csrf::field() ?>
                                    <button type="submit" class="btn btn-outline-warning w-100"><i class="fa-solid fa-rotate-left me-1"></i>Devolver</button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <?php // Cuál es el hueco de la «acción principal» depende del estado:
                              // con revisión activa y en borrador no la hay (el hueco lo
                              // ocupa el envío a revisión), y en revisión sin permiso la
                              // acción principal es un aviso, no un botón. ?>
                        <?php $inReview = $form['status'] === 'in_review'; ?>
                        <?php if ($inReview && !$canReview): ?>
                            <div class="col-sm-6">
                                <div class="alert alert-info mb-0 py-2 px-3 h-100 d-flex align-items-center">
                                    <i class="fa-solid fa-hourglass-half me-2"></i>Esperando revisión. Volverá al borrador o se publicará.
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php $mainSlot = ($form['status'] === 'draft' && !$reviewRequired) || $form['status'] === 'published' || $form['status'] === 'closed'; ?>
                        <div class="<?= $mainSlot || $inReview ? 'col-sm-6' : 'col-sm-12' ?>">
                            <?php if ($form['status'] === 'draft' && !$reviewRequired): ?>
                                <form method="post" action="/forms/<?= (int) $form['id'] ?>/publish" id="formPublishActions">
                                    <?= \App\Core\Csrf::field() ?>
                                    <button type="submit" class="btn btn-success w-100"><i class="fa-solid fa-globe me-1"></i>Publicar</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($form['status'] === 'published'): ?>
                                <form method="post" action="/forms/<?= (int) $form['id'] ?>/close" id="formClose">
                                    <?= \App\Core\Csrf::field() ?>
                                    <button type="submit" class="btn btn-warning w-100"><i class="fa-solid fa-lock me-1"></i>Cerrar</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($form['status'] === 'closed'): ?>
                                <form method="post" action="/forms/<?= (int) $form['id'] ?>/publish" id="formPublishActions">
                                    <?= \App\Core\Csrf::field() ?>
                                    <button type="submit" class="btn btn-success w-100"><i class="fa-solid fa-globe me-1"></i>Reabrir</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php if ($canDelete): ?>
                        <div class="col-sm-6">
                            <form method="post" action="/forms/<?= (int) $form['id'] ?>/delete" id="formDelete" data-confirm="¿Eliminar este formulario y todas sus respuestas? Esta acción no se puede deshacer.">
                                <?= \App\Core\Csrf::field() ?>
                                <button type="submit" class="btn btn-outline-danger w-100"><i class="fa-solid fa-trash me-1"></i>Eliminar</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h3 class="h6 fw-bold mb-3">Enlace para compartir</h3>
                    <?php $settings = $settings ?? []; ?>
                    <?php if ($form['status'] === 'published'): ?>
                        <div class="input-group mb-2">
                            <input type="text" class="form-control form-control-sm app-share-input" id="shareUrl" value="<?= View::escape($shareUrl) ?>" readonly>
                            <button class="btn btn-outline-secondary btn-sm btn-copy-link" type="button" data-url="<?= View::escape($shareUrl) ?>" title="Copiar enlace"><i class="fa-solid fa-copy"></i></button>
                        </div>
                        <a href="<?= View::escape($shareUrl) ?>" target="_blank" class="btn btn-outline-info btn-sm w-100"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Abrir en nueva pestaña</a>

                        <?php // La URL se dedujo del host de la petición y salió local: el QR solo
                              // abrirá desde esta red. Ver App\Core\PublicUrl. ?>
                        <?php if (!empty($publicUrlIsLocal)): ?>
                            <div class="alert alert-warning d-flex gap-2 mt-3 mb-0 py-2 px-3 small app-share-hint" role="alert">
                                <i class="fa-solid fa-triangle-exclamation mt-1"></i>
                                <span>Este enlace apunta a una dirección local, así que el QR y el enlace solo abren en esta red. Si la app se sirve en otro dominio, define <code>APP_PUBLIC_URL</code> en el <code>.env</code>.</span>
                            </div>
                        <?php endif; ?>

                        <?php // P7 — QR del enlace público (SVG, sin GD). ?>
                        <img src="/forms/<?= (int) $form['id'] ?>/qr.svg" alt="Código QR del formulario" class="img-fluid mx-auto d-block my-3 app-qr-img" width="180" height="180">

                        <?php // P7 — Código de embed cuando está habilitado. ?>
                        <?php if (!empty($settings['embed']['enabled'])): ?>
                            <div class="mt-2">
                                <label for="embedSnippet" class="form-label small mb-1">Código para embeber (iframe)</label>
                                <textarea class="form-control form-control-sm" id="embedSnippet" rows="3" readonly><iframe src="<?= View::escape($embedUrl) ?>" width="100%" height="640" frameborder="0"></iframe></textarea>
                                <a href="<?= View::escape($embedUrl) ?>" target="_blank" class="small text-decoration-none d-inline-block mt-1"><i class="fa-solid fa-up-right-from-square me-1"></i>Ver página de embed</a>
                            </div>
                        <?php else: ?>
                            <p class="text-muted small mt-3 mb-0"><i class="fa-solid fa-circle-info me-1"></i>El embed está desactivado. Actívalo en <em>Configuración</em> para obtener el código iframe.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-muted small mb-3">Publica el formulario para obtener el enlace de respuesta.</p>
                        <form method="post" action="/forms/<?= (int) $form['id'] ?>/publish" id="formPublishSidebar">
                            <?= \App\Core\Csrf::field() ?>
                            <button type="submit" class="btn btn-success btn-sm w-100"><i class="fa-solid fa-globe me-1"></i>Publicar ahora</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="h6 fw-bold m-0">Campos</h3>
                        <a href="/forms/<?= (int) $form['id'] ?>/builder" class="small text-decoration-none"><i class="fa-solid fa-pen me-1"></i>Editar</a>
                    </div>
                    <?php if (empty($form['fields'])): ?>
                        <p class="text-muted small mb-0">Aún no hay campos. Ve al <a href="/forms/<?= (int) $form['id'] ?>/builder">builder</a> para agregar preguntas.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush app-field-list">
                            <?php foreach ($form['fields'] as $field): ?>
                            <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                <span class="small app-truncate-1"><?= View::escape($field['label']) ?></span>
                                <span class="badge text-bg-light text-muted"><?= View::escape($field['type']) ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?= View::asset('js/dashboard.js') ?>" defer></script>