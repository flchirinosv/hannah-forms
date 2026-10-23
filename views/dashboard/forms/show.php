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
        <div class="d-flex flex-wrap gap-2 app-head-actions">
            <a href="/forms/<?= (int) $form['id'] ?>/builder" class="btn btn-primary app-btn-primary"><i class="fa-solid fa-pen me-1"></i>Editar campos</a>
            <a href="/forms/<?= (int) $form['id'] ?>/access" class="btn btn-outline-primary"><i class="fa-solid fa-users me-1"></i>Participantes</a>
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
                        <?php // Los cuatro bloques de esta tarjeta que dependen de
                              // `forms.status` son partials (insignia, traza de
                              // revisión, fila de acciones y tarjeta de compartir):
                              // un POST de estado devuelve su HTML y los repinta sin
                              // recarga. Ver `_status_badge.php`. ?>
                        <div id="vfSlotStatusBadge">
                            <?= View::partial('dashboard/forms/_status_badge', ['form' => $form]) ?>
                        </div>
                    </div>
                    <p class="text-muted small mb-4"><?= View::escape($form['description'] ?: 'Sin descripción') ?></p>

                    <?php // F3 — Traza de la revisión. Es lo que distingue «revisado» de
                          // «nadie miró esto»: quién lo pidió y quién lo aprobó. Solo se
                          // pinta si hay algo que contar. ?>
                    <div id="vfSlotReviewTrail">
                        <?= View::partial('dashboard/forms/_review_trail', ['reviewTrail' => $reviewTrail ?? null]) ?>
                    </div>

                    <form method="post" action="/forms/<?= (int) $form['id'] ?>/update" id="formUpdate" data-vf-async="slots">
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

                    <div id="vfSlotActions">
                        <?= View::partial('dashboard/forms/_status_actions', [
                            'form'           => $form,
                            'reviewRequired' => $reviewRequired ?? false,
                            'canReview'      => $canReview ?? false,
                            'canDelete'      => $canDelete ?? false,
                        ]) ?>
                    </div>
                </div>
            </div>
            <div class="card border-0 shadow-sm mb-4">
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

            <?php // F4 / Fase 5 — La tarjeta de acceso y el listado de participantes
                  // viven ahora en su propia pantalla (`GET /forms/{id}/access`,
                  // botón «Participantes» en la cabecera): juntos eran una tarjeta
                  // de altura impredecible —la lista puede ser larga— dentro de la
                  // columna de los ajustes. El MODO y el identificador se siguen
                  // editando en «Ajustes del formulario», aquí abajo. ?>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h3 class="h6 fw-bold mb-3">Enlace para compartir</h3>
                    <div id="vfSlotShare">
                        <?= View::partial('dashboard/forms/_share_card', [
                            'form'             => $form,
                            'settings'         => $settings ?? [],
                            'shareUrl'         => $shareUrl ?? '',
                            'embedUrl'         => $embedUrl ?? '',
                            'publicUrlIsLocal' => $publicUrlIsLocal ?? false,
                        ]) ?>
                    </div>
                </div>
            </div>

            <?php // Ajustes operativos: acceso, agenda, cupo, avisos, webhook,
                  // gracias e idioma. Se editan aquí y NO en el builder.
                  // Cada puerta escribe solo sus bloques con
                  // `FormSettings::merge()`: si el constructor guardara el array
                  // entero, cada pulsación de «Guardar» en el builder dejaría
                  // estos ajustes en sus valores de fábrica. ?>
            <?php $emailFieldLabels = $emailFieldLabels ?? []; ?>
            <div class="card border-0 shadow-sm" id="settings">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <h3 class="h6 fw-bold m-0"><i class="fa-solid fa-sliders me-2 text-primary"></i>Ajustes del formulario</h3>
                        <span class="small text-muted">operativos</span>
                    </div>
                    <p class="text-muted small mb-3">
                        Quién responde, cuándo, cuánto, qué se avisa y qué ve al terminar. El tema, el quiz y el resto
                        de comportamiento siguen en <a href="/forms/<?= (int) $form['id'] ?>/builder">Configuración</a>.
                    </p>

                    <?php // `data-vf-async="slots"`: el guardado va por fetch y
                          // devuelve los huecos de la ficha, que también cambian:
                          // guardar la agenda o el acceso de un formulario
                          // publicado con revisión lo deja en borrador. Sin
                          // esto, guardar un ajuste y que la insignia dijera
                          // «Publicado» era una recarga de distancia.
                          // `data-vf-shortcut` habilita Ctrl/Cmd+S con el foco
                          // dentro del formulario (ver `app.js`). ?>
                    <form method="post" action="/forms/<?= (int) $form['id'] ?>/settings" id="formSettings"
                          data-vf-form-id="<?= (int) $form['id'] ?>" data-vf-async="slots" data-vf-shortcut="save">
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
                                    <?php // Fase 6 — La cuarta opción, y la última en aparecer en
                                          // pantalla a propósito: hasta aquí la puerta y el
                                          // panel estaban escritos y probados, y lo que
                                          // faltaba era el interruptor, que es lo que
                                          // hace «funciona» más que «está». Si el modo
                                          // nuevo se hubiera enseñado primero, un fallo de
                                          // la puerta se habría visto como un selector
                                          // que no hace nada. ?>
                                    <option value="roster"<?= (($settings['access']['mode'] ?? 'public') === 'roster') ? ' selected' : '' ?>>Solo un listado de participantes</option>
                                </select>
                                <?= $helpButton('Cambia entre «abierto a cualquiera con el enlace», «pide un correo y manda un enlace», «solo los correos de la lista» y «solo las personas de un listado», que también se edita en la tarjeta de Acceso.') ?>
                            </div>
                            <?php /* Fase 6 — Con qué campo se identifica a cada fila de la
                                     lista. Lo decide el SERVIDOR (no hay nadie en el
                                     navegador que lo enseñe) y `dashboard.js` lo
                                     enseña y esconde al cambiar de modo.

                                     Oculto viene **con `disabled`**, y no por descuido:
                                     un `<select>` que se ve apagado sigue mandando su
                                     valor, así que un formulario en «Público» con la
                                     lista ya configurada para correo guardaría
                                     «cédula» —la primera opción— cada vez que se
                                     guardara cualquier ajuste, y al volver al listado
                                     el identificador elegido habría desaparecido. Con
                                     `disabled` el campo no viaja, que es lo que dice
                                     `FormSettings::fromDashboardPost()` («si no viene,
                                     no lo toques»).

                                     Y sin JavaScript el camino también existe, con
                                     un guardado de más: se elige «Solo un listado de
                                     participantes», se guarda, y al volver a abrir la
                                     ficha el selector ya está a la vista con el
                                     identificador que tuviera. No es un campo que
                                     exista solo en el navegador —el que depende del
                                     JS es el modo, que se enseña y esconde al vuelo
                                     sin recargar—, pero no se puede cambiar en la
                                     misma visita. */ ?>
                            <div class="mb-2" id="accessIdentifierBox"<?= (($settings['access']['mode'] ?? 'public') === 'roster') ? '' : ' hidden' ?>>
                                <label for="access_identifier" class="form-label small fw-semibold">Con qué se identifica a cada participante</label>
                                <select class="form-select form-select-sm" id="access_identifier" name="access_identifier"<?= (($settings['access']['mode'] ?? 'public') === 'roster') ? '' : ' disabled' ?>>
                                    <?php $identificadorActual = (string) ($settings['access']['identifier'] ?? 'cedula'); ?>
                                    <option value="cedula"<?= $identificadorActual === 'cedula' ? ' selected' : '' ?>>Cédula</option>
                                    <option value="correo"<?= $identificadorActual === 'correo' ? ' selected' : '' ?>>Correo electrónico</option>
                                    <option value="codigo"<?= $identificadorActual === 'codigo' ? ' selected' : '' ?>>Código</option>
                                </select>
                                <?= $helpButton('Decide qué se le pide a la persona en la pantalla de acceso y qué columna del archivo es la identidad de cada fila; el código de la invitación sirve siempre, además de ese dato. Con gente en la lista, cambiarla reescribe la identidad de todos y se rechaza si alguno se queda sin ella o si dos personas acabarían con la misma.') ?>
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
                                <div class="col-12 col-sm-6">
                                    <label for="confirm_to_field" class="form-label small fw-semibold">Campo email</label>
                                    <input type="text" class="form-control form-control-sm" id="confirm_to_field" name="confirm_to_field"
                                           list="confirmFieldList" placeholder="Ej: Correo electrónico">
                                    <datalist id="confirmFieldList">
                                        <?php foreach ($emailFieldLabels as $label): ?>
                                            <option value="<?= View::escape($label) ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label for="confirm_subject" class="form-label small fw-semibold">Asunto</label>
                                    <input type="text" class="form-control form-control-sm" id="confirm_subject" name="confirm_subject"
                                           value="<?= View::escape($settings['notify']['confirmation']['subject'] ?? '') ?>"
                                           placeholder="Ej: Recibimos tu respuesta">
                                </div>
                                <div class="col-12">
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
                                <div class="col-12">
                                    <label for="webhook_url" class="form-label small fw-semibold">Webhook (URL)</label>
                                    <input type="url" class="form-control form-control-sm" id="webhook_url" name="webhook_url"
                                           value="<?= View::escape($settings['webhook']['url'] ?? '') ?>" placeholder="https://ejemplo.com/hook">
                                    <?= $helpButton('Si se llena, cada envío se manda por HTTP a esa URL. Debe ser <code>http</code> o <code>https</code> y alcanzable desde el servidor.') ?>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label for="webhook_kind" class="form-label small fw-semibold">Formato</label>
                                    <select class="form-select form-select-sm" id="webhook_kind" name="webhook_kind">
                                        <option value="generic"<?= (($settings['webhook']['kind'] ?? 'generic') === 'generic') ? ' selected' : '' ?>>JSON</option>
                                        <option value="slack"<?= (($settings['webhook']['kind'] ?? 'generic') === 'slack') ? ' selected' : '' ?>>Slack</option>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-6">
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

                        <?php // El `.spinner-border.d-none` dentro del botón es el
                              // contrato con `app.js`: mientras el POST está en
                              // vuelo lo quita del `d-none`. Sin él, guardar unos
                              // ajustes tardaba lo que tardara el servidor y solo
                              // se veía el botón apagado. ?>
                        <button type="submit" class="btn btn-primary app-form-cta w-100">
                            <i class="fa-solid fa-floppy-disk me-1"></i>Guardar ajustes
                            <span class="spinner-border spinner-border-sm d-none ms-1" role="status" aria-hidden="true"></span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?= View::asset('js/dashboard.js') ?>" defer></script>