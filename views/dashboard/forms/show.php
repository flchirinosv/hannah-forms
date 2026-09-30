<?php
use App\Core\View;
?>
<div class="container px-lg-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3 app-page-head">
        <div>
            <a href="/dashboard" class="text-decoration-none text-muted small"><i class="fa-solid fa-arrow-left me-1"></i>Volver al dashboard</a>
            <h2 class="h4 fw-bold mb-0 mt-2"><i class="fa-solid fa-clipboard-list me-2 text-primary"></i><?= View::escape($form['title']) ?></h2>
        </div>
        <?php // Una sola acción primaria («Editar campos»); Respuestas y Analítica
              // son las dos que se usan a diario, y todo lo demás baja a un menú.
              // Antes eran seis botones del mismo peso en fila: en móvil no
              // cabían (`d-flex` sin `flex-wrap`) y nada decía cuál era el
              // importante. `app-dash-dd` + `app-dash-menu` son el mismo patrón
              // que usa la tarjeta del dashboard. ?>
        <div class="d-flex flex-wrap gap-2">
            <a href="/forms/<?= (int) $form['id'] ?>/builder" class="btn btn-primary app-btn-primary"><i class="fa-solid fa-pen me-1"></i>Editar campos</a>
            <a href="/forms/<?= (int) $form['id'] ?>/responses" class="btn btn-outline-primary"><i class="fa-solid fa-inbox me-1"></i>Respuestas</a>
            <a href="/forms/<?= (int) $form['id'] ?>/analytics" class="btn btn-outline-success"><i class="fa-solid fa-chart-column me-1"></i>Analítica</a>
            <div class="dropdown app-dash-dd">
                <button class="btn btn-outline-secondary app-dd-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Más acciones">
                    <i class="fa-solid fa-ellipsis-vertical"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 app-dash-menu">
                    <li><a class="dropdown-item" href="/forms/<?= (int) $form['id'] ?>/print" target="_blank" rel="noopener"><i class="fa-solid fa-print me-2 text-muted"></i>Imprimir</a></li>
                    <li><a class="dropdown-item" href="/forms/<?= (int) $form['id'] ?>/export-structure.json"><i class="fa-solid fa-file-export me-2 text-muted"></i>Exportar estructura</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="post" action="/forms/<?= (int) $form['id'] ?>/duplicate" class="m-0">
                            <?= \App\Core\Csrf::field() ?>
                            <button type="submit" class="dropdown-item"><i class="fa-solid fa-clone me-2 text-muted"></i>Duplicar</button>
                        </form>
                    </li>
                </ul>
            </div>
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
                        <div class="col">
                            <button type="submit" form="formUpdate" class="btn btn-outline-primary w-100"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar</button>
                        </div>
                        <?php // F3 — Con revisión activa el dueño no tiene botón de publicar:
                              // el que hay sería aprobarse a uno mismo. Se muestra el
                              // de «enviar a revisión» en su lugar. ?>
                        <?php $reviewRequired = $reviewRequired ?? false; ?>
                        <?php $canReview = $canReview ?? false; ?>
                        <?php if ($form['status'] === 'draft' && $reviewRequired): ?>
                            <div class="col">
                                <form method="post" action="/forms/<?= (int) $form['id'] ?>/review/send" id="formSendReview">
                                    <?= \App\Core\Csrf::field() ?>
                                    <button type="submit" class="btn btn-info w-100"><i class="fa-solid fa-paper-plane me-1"></i>Enviar a revisión</button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <?php if ($form['status'] === 'in_review' && $canReview): ?>
                            <div class="col">
                                <form method="post" action="/forms/<?= (int) $form['id'] ?>/review/approve" id="formApprove">
                                    <?= \App\Core\Csrf::field() ?>
                                    <button type="submit" class="btn btn-success w-100"><i class="fa-solid fa-check me-1"></i>Aprobar</button>
                                </form>
                            </div>
                            <div class="col">
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
                            <div class="col">
                                <div class="alert alert-info mb-0 py-2 px-3 h-100 d-flex align-items-center">
                                    <i class="fa-solid fa-hourglass-half me-2"></i>Esperando revisión. Volverá al borrador o se publicará.
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php $mainSlot = ($form['status'] === 'draft' && !$reviewRequired) || $form['status'] === 'published' || $form['status'] === 'closed'; ?>
                        <?php // Esta columna solo se emite si hay una acción principal que
                              // poner en ella. Antes se emitía siempre, con `col-sm-6` o
                              // `col-sm-12` según el caso, y en los tres estados que no
                              // tienen botón (en revisión con permiso —que ya tiene
                              // Aprobar/Devolver—, en revisión sin permiso —que tiene
                              // el aviso— y en borrador con revisión activa —que tiene
                              // «Enviar a revisión») quedaba media fila en blanco.
                              // Todas las acciones de la fila son `col`, de ancho
                              // igual, para que la suma sea 12 diga lo que diga el
                              // número de acciones que haya. ?>
                        <?php if ($mainSlot): ?>
                        <div class="col">
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
                        <?php endif; ?>
                        <?php if ($canDelete): ?>
                        <div class="col">
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

            <?php // F4 — Acceso. La lista se edita aquí y NO en el builder a propósito:
                  // quitar un email revoca sus enlaces, y esa consecuencia se ve
                  // mejor junto al formulario que dentro de un modal de ajustes. ?>
            <?php $accessMode = $accessMode ?? 'public'; ?>
            <?php $accessList = $accessList ?? []; ?>
            <?php $accessCount = $accessCount ?? 0; ?>
            <div class="card border-0 shadow-sm mb-4" id="access"
                 data-csrf="<?= View::escape(\App\Core\Csrf::token()) ?>"
                 data-link-endpoint="/forms/<?= (int) $form['id'] ?>/access/link">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="h6 fw-bold m-0"><i class="fa-solid fa-user-lock me-2 text-primary"></i>Acceso</h3>
                        <span class="badge text-bg-light text-muted"><?= View::escape(match ($accessMode) {
                            'email' => 'Por correo',
                            'allowlist' => 'Solo la lista',
                            default => 'Público',
                        }) ?></span>
                    </div>

                    <?php if ($accessMode === 'public'): ?>
                        <p class="text-muted small mb-0">
                            <i class="fa-solid fa-circle-info me-1"></i>Cualquiera con el enlace puede responder.
                            Cambia el modo en <a href="/forms/<?= (int) $form['id'] ?>/builder">Configuración</a>.
                        </p>
                    <?php elseif ($accessMode === 'email'): ?>
                        <p class="text-muted small mb-0">
                            <i class="fa-solid fa-circle-info me-1"></i>Cualquiera que escriba un correo recibe un
                            enlace. No hay lista: cualquier correo válido entra. Cambia el modo en
                            <a href="/forms/<?= (int) $form['id'] ?>/builder">Configuración</a>.
                        </p>
                    <?php else: ?>
                        <p class="text-muted small">
                            Solo los correos de esta lista reciben un enlace. Quitar a alguien de la lista
                            <strong>invalida los enlaces que ya se le hayan enviado</strong>.
                        </p>

                        <form method="post" action="/forms/<?= (int) $form['id'] ?>/access/email" class="mb-3">
                            <?= \App\Core\Csrf::field() ?>
                            <div class="input-group input-group-sm">
                                <input type="email" name="email" class="form-control" required maxlength="190"
                                       placeholder="nombre@ejemplo.com" aria-label="Correo con acceso">
                                <button type="submit" class="btn btn-outline-primary"><i class="fa-solid fa-plus me-1"></i>Añadir</button>
                            </div>
                        </form>

                        <?php // F4 — Salida del enlace recién emitido. Se rellena por JS y
                              // se queda a la vista a propósito: el token solo
                              // existe en claro en el momento de emitirlo, así que
                              // si el portapapeles falla no hay forma de recuperarlo
                              // y el enlace se habría perdido. ?>
                        <div class="d-none mb-3" id="accessLinkOut">
                            <label class="form-label small mb-1">
                                Enlace para <span data-role="access-link-for"></span>
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" readonly
                                       aria-label="Enlace de acceso generado">
                                <?php // `access-recopy` y no el `js-copy-url` de siempre:
                                      // ese prepende el origen de la página, y esta URL
                                      // ya viene absoluta desde PublicUrl.
                                      ?>
                                <button type="button" class="btn btn-outline-secondary"
                                        data-role="access-recopy" title="Copiar otra vez"><i class="fa-solid fa-copy"></i></button>
                            </div>
                            <p class="small text-muted mb-0 mt-1">
                            Al pedir otro enlace para el mismo correo, este deja de funcionar.</p>
                        </div>

                        <?php if ($accessCount === 0): ?>
                            <div class="alert alert-warning d-flex gap-2 mb-0 py-2 px-3 small" role="alert">
                                <i class="fa-solid fa-triangle-exclamation mt-1"></i>
                                <span>La lista está vacía: ahora mismo <strong>nadie</strong> puede responder a este
                                formulario, ni siquiera quien ya tenía un enlace.</span>
                            </div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush app-field-list mb-0">
                                <?php foreach ($accessList as $entry): ?>
                                <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                    <span class="small app-truncate-1"><?= View::escape($entry['email']) ?></span>
                                    <span class="d-flex gap-1">
                                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                                data-role="access-link" data-email="<?= View::escape($entry['email']) ?>"
                                                title="Generar y copiar el enlace de acceso para este correo"
                                                aria-label="Generar y copiar el enlace de acceso para <?= View::escape($entry['email']) ?>">
                                            <i class="fa-solid fa-link"></i>
                                        </button>
                                        <?php // `d-inline` es lo que hace que el botón se alinee con
                                              // el de al lado: sin él, este <form> es un bloque dentro
                                              // del `d-flex` de la fila y el botón se queda arriba
                                              // con el interlineado del form debajo, un píxel o dos
                                              // más bajo que su hermano. ?>
                                        <form method="post" action="/forms/<?= (int) $form['id'] ?>/access/email/remove" class="d-inline">
                                            <?= \App\Core\Csrf::field() ?>
                                            <input type="hidden" name="email" value="<?= View::escape($entry['email']) ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm"
                                                    data-confirm="¿Quitar <?= View::escape($entry['email']) ?> de la lista? Los enlaces que ya se le hayan enviado dejarán de funcionar."
                                                    title="Quitar de la lista y revocar sus enlaces"
                                                    aria-label="Quitar <?= View::escape($entry['email']) ?> de la lista">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </form>
                                    </span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($accessCount > count($accessList)): ?>
                                <p class="small text-muted mb-0 mt-2">y <?= (int) $accessCount - count($accessList) ?> más…</p>
                            <?php endif; ?>
                        <?php endif; ?>
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