<?php
use App\Core\View;

// Botón `?` de ayuda, el mismo helper que usa el builder: aquí lo necesitan
// los ajustes operativos, que se editan en la ficha y no en el modal.
$helpButton = static fn(string $html, string $label = 'Qué hace este ajuste'): string => View::help($html, $label);
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
                                <form method="post" action="/forms/<?= (int) $form['id'] ?>/review/approve" id="formApprove" data-confirm="¿Publicar este formulario? Aprobarlo lo deja visible para cualquiera que tenga el enlace, y ya no es un borrador.">
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
                            <p class="text-muted small mt-3 mb-0"><i class="fa-solid fa-circle-info me-1"></i>El embed está desactivado. Actívalo en la <em>Configuración</em> del constructor para obtener el código iframe.</p>
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

            <?php // Ajustes operativos: acceso, agenda, cupo, avisos, webhook,
                  // gracias, revisión e idioma. Se editan aquí y NO en el builder.
                  // Cada puerta escribe solo sus bloques con
                  // `FormSettings::merge()`: si el constructor guardara el array
                  // entero, cada pulsación de «Guardar» en el builder dejaría
                  // estos ocho ajustes en sus valores de fábrica. ?>
            <?php $emailFieldLabels = $emailFieldLabels ?? []; ?>
            <div class="card border-0 shadow-sm mb-4" id="settings">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <h3 class="h6 fw-bold m-0"><i class="fa-solid fa-sliders me-2 text-primary"></i>Ajustes del formulario</h3>
                        <span class="small text-muted">operativos</span>
                    </div>
                    <p class="text-muted small mb-3">
                        Quién responde, cuándo, cuánto, qué se avisa y qué ve al terminar. El tema, el quiz y el resto
                        de comportamiento siguen en <a href="/forms/<?= (int) $form['id'] ?>/builder">Configuración</a>.
                    </p>

                    <form method="post" action="/forms/<?= (int) $form['id'] ?>/settings" id="formSettings"
                          data-vf-form-id="<?= (int) $form['id'] ?>">
                        <?= \App\Core\Csrf::field() ?>

                        <fieldset class="app-settings-group mb-3">
                            <legend class="app-settings-group-title">
                                <button type="button" class="app-group-toggle" data-bs-toggle="collapse" data-bs-target="#grp-acceso"
                                        aria-expanded="true" aria-controls="grp-acceso">
                                    <span><i class="fa-solid fa-lock me-1"></i>Acceso</span>
                                    <i class="fa-solid fa-chevron-down app-group-caret ms-auto"></i>
                                </button>
                            </legend>
                            <div class="collapse show" id="grp-acceso">
                            <div class="mb-2">
                                <label for="access_mode" class="form-label small fw-semibold">Quién puede responder</label>
                                <select class="form-select form-select-sm" id="access_mode" name="access_mode">
                                    <option value="public"<?= (($settings['access']['mode'] ?? 'public') === 'public') ? ' selected' : '' ?>>Cualquiera con el enlace</option>
                                    <option value="email"<?= (($settings['access']['mode'] ?? 'public') === 'email') ? ' selected' : '' ?>>Cualquiera que verifique su correo</option>
                                    <option value="allowlist"<?= (($settings['access']['mode'] ?? 'public') === 'allowlist') ? ' selected' : '' ?>>Solo los correos de la lista</option>
                                </select>
                                <?= $helpButton('Cambia entre «abierto a cualquiera con el enlace», «pide un correo y manda un enlace» y «solo los correos de la lista», que se edita más abajo en la tarjeta de Acceso.') ?>
                            </div>
                            <p class="small text-muted mb-0" id="stAccessHint" role="status"></p>
                            </div>
                        </fieldset>

                        <fieldset class="app-settings-group mb-3">
                            <legend class="app-settings-group-title">
                                <button type="button" class="app-group-toggle" data-bs-toggle="collapse" data-bs-target="#grp-agenda"
                                        aria-expanded="true" aria-controls="grp-agenda">
                                    <span><i class="fa-solid fa-calendar-days me-1"></i>Programación y cupo</span>
                                    <i class="fa-solid fa-chevron-down app-group-caret ms-auto"></i>
                                </button>
                            </legend>
                            <div class="collapse show" id="grp-agenda">
                            <div class="row g-2">
                                <div class="col">
                                    <label for="open_at" class="form-label small fw-semibold">Abre el</label>
                                    <input type="datetime-local" class="form-control form-control-sm" id="open_at" name="open_at"
                                           value="<?= View::escape(View::datetimeLocal($settings['schedule']['open_at'] ?? null)) ?>">
                                    <?= $helpButton('Antes de esta fecha el formulario no acepta envíos. Vacío = desde ya.') ?>
                                </div>
                                <div class="col">
                                    <label for="close_at" class="form-label small fw-semibold">Cierra el</label>
                                    <input type="datetime-local" class="form-control form-control-sm" id="close_at" name="close_at"
                                           value="<?= View::escape(View::datetimeLocal($settings['schedule']['close_at'] ?? null)) ?>">
                                    <?= $helpButton('Después de esta fecha deja de aceptarlos. Vacío = sin fecha de cierre. Lo que ya se envió se conserva.') ?>
                                </div>
                            </div>
                            <p class="small text-muted mt-2 mb-2">
                                <i class="fa-solid fa-globe me-1"></i>Se interpretan en la zona horaria de la instalación
                                (<code>APP_TIMEZONE</code>), no en la de quien responde. Si <em>Abre</em> es posterior a
                                <em>Cierra</em>, el ajuste se rechaza al guardar.
                            </p>
                            <div class="form-check form-switch mb-1">
                                <?php // El `hidden` con el MISMO `name` va delante: sin él, desmarcar la
                                          // casilla no enviaría nada y el ajuste se quedaría como estaba en
                                          // vez de apagarse. `fromDashboardPost()` distingue el ausente del
                                          // vacío justamente para esto. ?>
                                <input type="hidden" name="quota_enabled" value="0">
                                <input class="form-check-input" type="checkbox" id="quota_enabled" name="quota_enabled" value="1"<?= !empty($settings['quota']['enabled']) ? ' checked' : '' ?>>
                                <label class="form-check-label small fw-semibold" for="quota_enabled">Limitar respuestas<?= $helpButton('Al llegar al máximo, el formulario deja de aceptar envíos. Lo que ya se envió se conserva.') ?></label>
                            </div>
                            <div class="mt-2">
                                <label for="quota_max" class="form-label small fw-semibold">Máximo de respuestas</label>
                                <input type="number" class="form-control form-control-sm" id="quota_max" name="quota_max" min="1" placeholder="Ej: 100"
                                       value="<?= (int) ($settings['quota']['max'] ?? 0) > 0 ? (int) $settings['quota']['max'] : '' ?>">
                            </div>
                            </div>
                        </fieldset>

                        <fieldset class="app-settings-group mb-3">
                            <legend class="app-settings-group-title">
                                <button type="button" class="app-group-toggle" data-bs-toggle="collapse" data-bs-target="#grp-avisos"
                                        aria-expanded="true" aria-controls="grp-avisos">
                                    <span><i class="fa-solid fa-envelope me-1"></i>Notificaciones</span>
                                    <i class="fa-solid fa-chevron-down app-group-caret ms-auto"></i>
                                </button>
                            </legend>
                            <div class="collapse show" id="grp-avisos">
                            <div class="form-check form-switch mb-1">
                                <?php // El `hidden` con el MISMO `name` va delante: sin él, desmarcar la
                                          // casilla no enviaría nada y el ajuste se quedaría como estaba en
                                          // vez de apagarse. `fromDashboardPost()` distingue el ausente del
                                          // vacío justamente para esto. ?>
                                <input type="hidden" name="notify_enabled" value="0">
                                <input class="form-check-input" type="checkbox" id="notify_enabled" name="notify_enabled" value="1"<?= !empty($settings['notify']['enabled']) ? ' checked' : '' ?>>
                                <label class="form-check-label small fw-semibold" for="notify_enabled">Notificar al recibir<?= $helpButton('Te avisa a ti de cada envío.') ?></label>
                            </div>
                            <div class="mb-3 mt-2">
                                <label for="notify_emails" class="form-label small fw-semibold">Correos (separados por coma)</label>
                                <input type="text" class="form-control form-control-sm" id="notify_emails" name="notify_emails"
                                       value="<?= View::escape(implode(', ', (array) ($settings['notify']['emails'] ?? []))) ?>"
                                       placeholder="Ej:RRHH@empresa.com, datos@empresa.com">
                                <?= $helpButton('Hasta 10, separados por coma. Los que no sean un correo válido se descartan solos, sin aviso.') ?>
                            </div>
                            <div class="form-check form-switch mb-1">
                                <?php // El `hidden` con el MISMO `name` va delante: sin él, desmarcar la
                                          // casilla no enviaría nada y el ajuste se quedaría como estaba en
                                          // vez de apagarse. `fromDashboardPost()` distingue el ausente del
                                          // vacío justamente para esto. ?>
                                <input type="hidden" name="confirm_enabled" value="0">
                                <input class="form-check-input" type="checkbox" id="confirm_enabled" name="confirm_enabled" value="1"<?= !empty($settings['notify']['confirmation']['enabled']) ? ' checked' : '' ?>>
                                <label class="form-check-label small fw-semibold" for="confirm_enabled">Enviar confirmación a quien responde<?= $helpButton('Necesita un campo de tipo email en el formulario (lista de abajo) y que el correo saliente esté configurado en el servidor.') ?></label>
                            </div>
                            <div class="row g-2 mt-2">
                                <div class="col-md-4">
                                    <label for="confirm_to_field" class="form-label small fw-semibold">Campo email</label>
                                    <input type="text" class="form-control form-control-sm" id="confirm_to_field" name="confirm_to_field"
                                           list="confirmFieldList" placeholder="Ej: Correo electrónico">
                                    <datalist id="confirmFieldList">
                                        <?php foreach ($emailFieldLabels as $label): ?>
                                            <option value="<?= View::escape($label) ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div class="col-md-4">
                                    <label for="confirm_subject" class="form-label small fw-semibold">Asunto</label>
                                    <input type="text" class="form-control form-control-sm" id="confirm_subject" name="confirm_subject"
                                           value="<?= View::escape($settings['notify']['confirmation']['subject'] ?? '') ?>"
                                           placeholder="Ej: Recibimos tu respuesta">
                                </div>
                                <div class="col-md-12">
                                    <label for="confirm_message" class="form-label small fw-semibold">Mensaje</label>
                                    <textarea class="form-control form-control-sm" id="confirm_message" name="confirm_message" rows="2" placeholder="Vacío = mensaje por defecto"><?= View::escape($settings['notify']['confirmation']['message'] ?? '') ?></textarea>
                                </div>
                            </div>
                            </div>
                        </fieldset>

                        <fieldset class="app-settings-group mb-3">
                            <legend class="app-settings-group-title">
                                <button type="button" class="app-group-toggle" data-bs-toggle="collapse" data-bs-target="#grp-webhook"
                                        aria-expanded="true" aria-controls="grp-webhook">
                                    <span><i class="fa-solid fa-link me-1"></i>Webhook</span>
                                    <i class="fa-solid fa-chevron-down app-group-caret ms-auto"></i>
                                </button>
                            </legend>
                            <div class="collapse show" id="grp-webhook">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label for="webhook_url" class="form-label small fw-semibold">Webhook (URL)</label>
                                    <input type="url" class="form-control form-control-sm" id="webhook_url" name="webhook_url"
                                           value="<?= View::escape($settings['webhook']['url'] ?? '') ?>" placeholder="https://ejemplo.com/hook">
                                    <?= $helpButton('Si se llena, cada envío se manda por HTTP a esa URL. Debe ser <code>http</code> o <code>https</code> y alcanzable desde el servidor.') ?>
                                </div>
                                <div class="col-md-3">
                                    <label for="webhook_kind" class="form-label small fw-semibold">Formato</label>
                                    <select class="form-select form-select-sm" id="webhook_kind" name="webhook_kind">
                                        <option value="generic"<?= (($settings['webhook']['kind'] ?? 'generic') === 'generic') ? ' selected' : '' ?>>JSON</option>
                                        <option value="slack"<?= (($settings['webhook']['kind'] ?? 'generic') === 'slack') ? ' selected' : '' ?>>Slack</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="webhook_secret" class="form-label small fw-semibold">Secreto (firma)</label>
                                    <input type="text" class="form-control form-control-sm" id="webhook_secret" name="webhook_secret"
                                           value="<?= View::escape($settings['webhook']['secret'] ?? '') ?>" placeholder="Opcional" autocomplete="off">
                                </div>
                            </div>
                            <div class="form-check form-switch mt-2 mb-1">
                                <?php // El `hidden` con el MISMO `name` va delante: sin él, desmarcar la
                                          // casilla no enviaría nada y el ajuste se quedaría como estaba en
                                          // vez de apagarse. `fromDashboardPost()` distingue el ausente del
                                          // vacío justamente para esto. ?>
                                <input type="hidden" name="webhook_allow_private" value="0">
                                <input class="form-check-input" type="checkbox" id="webhook_allow_private" name="webhook_allow_private" value="1"<?= !empty($settings['webhook']['allow_private_hosts']) ? ' checked' : '' ?>>
                                <label class="form-check-label small fw-semibold" for="webhook_allow_private">Permitir destinos internos<?= $helpButton('Hacen falta <strong>los dos</strong> opt-ins: este y <code>WEBHOOK_ALLOW_PRIVATE_HOSTS</code> en el servidor. Con el servidor sin él, esta casilla no habilita nada.') ?></label>
                            </div>
                            <p class="small text-muted mb-0">
                                <i class="fa-solid fa-shield-halved me-1"></i>Por seguridad, las direcciones internas
                                (<code>localhost</code>, <code>192.168.x.x</code>, <code>10.x.x.x</code>) están bloqueadas.
                                Quien administra el servidor las habilita con <code>WEBHOOK_ALLOW_PRIVATE_HOSTS</code>.
                            </p>
                            </div>
                        </fieldset>

                        <fieldset class="app-settings-group mb-3">
                            <legend class="app-settings-group-title">
                                <button type="button" class="app-group-toggle" data-bs-toggle="collapse" data-bs-target="#grp-gracias"
                                        aria-expanded="true" aria-controls="grp-gracias">
                                    <span><i class="fa-solid fa-flag-checkered me-1"></i>Mensaje final</span>
                                    <i class="fa-solid fa-chevron-down app-group-caret ms-auto"></i>
                                </button>
                            </legend>
                            <div class="collapse show" id="grp-gracias">
                            <div class="mb-3">
                                <label for="thanks_message" class="form-label small fw-semibold">Mensaje de gracias</label>
                                <textarea class="form-control form-control-sm" id="thanks_message" name="thanks_message" rows="2" placeholder="En blanco = mensaje por defecto"><?= View::escape($settings['thanks']['message'] ?? '') ?></textarea>
                                <?= $helpButton('Lo que ve la persona al terminar. En blanco se usa el mensaje por defecto de la instalación.') ?>
                            </div>
                            <div>
                                <label for="thanks_redirect" class="form-label small fw-semibold">URL de redirección tras enviar</label>
                                <input type="url" class="form-control form-control-sm" id="thanks_redirect" name="thanks_redirect"
                                       value="<?= View::escape($settings['thanks']['redirect_url'] ?? '') ?>" placeholder="https://… (opcional)">
                                <?= $helpButton('A dónde se le lleva al terminar, <em>después</em> de ver el mensaje. Solo se sigue si el dominio está permitido por el servidor (<code>APP_REDIRECT_ALLOWLIST</code>).') ?>
                            </div>
                            </div>
                        </fieldset>

                        <fieldset class="app-settings-group mb-3">
                            <legend class="app-settings-group-title">
                                <button type="button" class="app-group-toggle" data-bs-toggle="collapse" data-bs-target="#grp-revision"
                                        aria-expanded="true" aria-controls="grp-revision">
                                    <span><i class="fa-solid fa-user-check me-1"></i>Revisión</span>
                                    <i class="fa-solid fa-chevron-down app-group-caret ms-auto"></i>
                                </button>
                            </legend>
                            <div class="collapse show" id="grp-revision">
                            <div class="form-check form-switch mb-1">
                                <?php // El `hidden` con el MISMO `name` va delante: sin él, desmarcar la
                                          // casilla no enviaría nada y el ajuste se quedaría como estaba en
                                          // vez de apagarse. `fromDashboardPost()` distingue el ausente del
                                          // vacío justamente para esto. ?>
                                <input type="hidden" name="review_enabled" value="0">
                                <input class="form-check-input" type="checkbox" id="review_enabled" name="review_enabled" value="1"<?= !empty($settings['review']['enabled']) ? ' checked' : '' ?>>
                                <label class="form-check-label small fw-semibold" for="review_enabled">Exigir revisión antes de publicar<?= $helpButton('Si está activa, el botón «Publicar» desaparece y el formulario espera en la bandeja de revisión.') ?></label>
                            </div>
                            <div class="form-check form-switch">
                                <?php // El `hidden` con el MISMO `name` va delante: sin él, desmarcar la
                                          // casilla no enviaría nada y el ajuste se quedaría como estaba en
                                          // vez de apagarse. `fromDashboardPost()` distingue el ausente del
                                          // vacío justamente para esto. ?>
                                <input type="hidden" name="review_notify_email" value="0">
                                <input class="form-check-input" type="checkbox" id="review_notify_email" name="review_notify_email" value="1"<?= !empty($settings['review']['notify_email']) ? ' checked' : '' ?>>
                                <label class="form-check-label small fw-semibold" for="review_notify_email">Avisar por correo a los revisores<?= $helpButton('El aviso lleva al panel de revisión, nunca al enlace público.') ?></label>
                            </div>
                            <p class="small text-muted mb-0 mt-2" id="stReviewHint" role="status"></p>
                            </div>
                        </fieldset>

                        <fieldset class="app-settings-group mb-3">
                            <legend class="app-settings-group-title">
                                <button type="button" class="app-group-toggle" data-bs-toggle="collapse" data-bs-target="#grp-idioma"
                                        aria-expanded="true" aria-controls="grp-idioma">
                                    <span><i class="fa-solid fa-language me-1"></i>Idioma público</span>
                                    <i class="fa-solid fa-chevron-down app-group-caret ms-auto"></i>
                                </button>
                            </legend>
                            <div class="collapse show" id="grp-idioma">
                                <div class="mb-3">
                                    <label for="locale" class="form-label small fw-semibold">Idioma del formulario</label>
                                    <select class="form-select form-select-sm" id="locale" name="locale">
                                        <option value="es"<?= (($settings['locale']['code'] ?? 'es') === 'es') ? ' selected' : '' ?>>Español</option>
                                        <option value="en"<?= (($settings['locale']['code'] ?? 'es') === 'en') ? ' selected' : '' ?>>English</option>
                                    </select>
                                    <?= $helpButton('Idioma con el que se presenta el formulario. Quien responda puede cambiarlo con <code>?lang=</code> en el enlace.') ?>
                                </div>
                            </div>
                        </fieldset>

                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar ajustes</button>
                    </form>
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
                            Cambia el modo en <em>Ajustes del formulario</em>, más abajo.
                        </p>
                    <?php elseif ($accessMode === 'email'): ?>
                        <p class="text-muted small mb-0">
                            <i class="fa-solid fa-circle-info me-1"></i>Cualquiera que escriba un correo recibe un
                            enlace. No hay lista: cualquier correo válido entra. Cambia el modo en
                            <em>Ajustes del formulario</em>, más abajo.
                        </p>
                    <?php else: ?>
                        <p class="text-muted small">
                            Solo los correos de esta lista reciben un enlace. Quitar a alguien de la lista
                            <strong>invalida los enlaces que ya se le hayan enviado</strong>.
                        </p>

                        <?php // El alta se hace por fetch (`dashboard.js`): con
                              // recarga, añadir diez correos obligaba a diez
                              // viajes y la lista se recolocaba sola al scroll.
                              // El `method`/`action` siguen ahí a propósito, así
                              // que SIN JavaScript el formulario funciona igual
                              // (la misma puerta responde en HTML o en JSON). ?>
                        <form method="post" action="/forms/<?= (int) $form['id'] ?>/access/email"
                              class="mb-3" id="accessAddForm" data-role="access-add-form">
                            <?= \App\Core\Csrf::field() ?>
                            <div class="input-group input-group-sm">
                                <input type="email" name="email" id="accessAddEmail" class="form-control" required maxlength="190"
                                       placeholder="nombre@ejemplo.com" aria-label="Correo con acceso">
                                <button type="submit" class="btn btn-outline-primary" id="accessAddBtn">
                                    <i class="fa-solid fa-plus me-1"></i>Añadir
                                </button>
                            </div>
                            <p class="small mb-0 mt-1" id="accessAddError" role="alert"></p>
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

                        <?php // La lista es un partial porque el alta por fetch
                              // devuelve este MISMO HTML ya escapado (ver
                              // `AccessController::addEmail()`): con dos copias,
                              // añadir un email y recargar mostraría listas
                              // distintas según por dónde se mirara. ?>
                        <div id="accessList">
                            <?= View::partial('dashboard/forms/_access_list', [
                                'accessList'  => $accessList,
                                'accessCount' => $accessCount,
                                'formId'      => (int) $form['id'],
                            ]) ?>
                        </div>
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