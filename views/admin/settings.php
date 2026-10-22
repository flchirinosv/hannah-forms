<?php
/**
 * Ajustes de la INSTANCIA: registro público, política de revisión, marca y
 * correo (SMTP).
 *
 * Los dos primeros deciden quién puede crear una cuenta o publicar formularios
 * en la instalación entera, así que no viven en la tabla de usuarios (de la que
 * no son una fila) ni en la matriz de permisos (que es de roles y personas):
 * van en su propia pantalla, con su propio permiso `admin.access`. AG2 sumó la
 * marca (nombre, color, logo y favicon) y AG3 el correo, que es la configuración
 * que antes exigía SSH para editar el `.env`.
 *
 * El formulario de revisión, el de marca y el de correo son NATIVOS y responden
 * con PRG: la pantalla no repinta ningún hueco. El interruptor de registro va
 * por fetch (`dashboard.js`, enganchado a `#instance`), porque su tarjeta sí se
 * queda en pantalla y recargar entera para mover una casilla era otra operación.
 */
use App\Core\Brand;
use App\Core\Csrf;
use App\Core\View;
?>
<div class="container-fluid px-lg-4">
    <?= View::partial('admin/_page_head', [
        'active'   => 'settings',
        'icon'     => 'fa-sliders',
        'title'    => 'Ajustes de la instancia',
        'subtitle' => 'Cómo se presenta esta instalación (marca), con qué manda correo (SMTP) y quién puede'
            . ' <strong>crear cuentas</strong> o <strong>publicar formularios</strong>. Son del panel entero.',
    ]) ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100" id="instance"
                 data-csrf="<?= View::escape(Csrf::token()) ?>"
                 data-registration-endpoint="/admin/settings/registration">
                <div class="card-body">
                    <div class="app-instance-head">
                        <span class="app-instance-head-icon"><i class="fa-solid fa-globe" aria-hidden="true"></i></span>
                        <div>
                            <h3 class="app-instance-head-title">Acceso de cualquiera</h3>
                            <p class="app-instance-head-sub">
                                La puerta de <code>/register</code>. Con ella cerrada, las cuentas se crean
                                desde la pantalla de usuarios de este panel.
                            </p>
                        </div>
                    </div>
                    <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                        <div class="form-check form-switch d-flex align-items-center gap-2 mb-0">
                            <input type="checkbox" role="switch" class="form-check-input" id="registrationToggle"
                                   <?= ($registrationEnabled ?? false) ? 'checked' : '' ?>
                                   aria-describedby="registrationHint">
                            <label class="form-check-label fw-semibold" for="registrationToggle">
                                <span data-role="registration-state">
                                    <?= ($registrationEnabled ?? false) ? 'Registro público abierto' : 'Registro público cerrado' ?>
                                </span>
                            </label>
                        </div>
                        <span class="app-instance-status <?= ($registrationEnabled ?? false) ? 'is-on' : 'is-off' ?>">
                            <i class="fa-solid <?= ($registrationEnabled ?? false) ? 'fa-lock-open' : 'fa-lock' ?>" aria-hidden="true"></i>
                            <?= ($registrationEnabled ?? false) ? 'Abierto' : 'Cerrado' ?>
                        </span>
                    </div>
                    <p class="form-text mb-0 mt-2" id="registrationHint" role="status"></p>
                </div>
            </div>
        </div>

        <?php // F3 (revisada) — La política de revisión es de la INSTANCIA y no
              // del formulario. Un POST NATIVO (no fetch): la pantalla no repinta
              // huecos, así que el PRG de siempre es el camino único, con o sin
              // JavaScript. ?>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <form method="post" action="/admin/settings/review" class="h-100 d-flex flex-column">
                    <?= Csrf::field() ?>
                    <div class="card-body">
                        <div class="app-instance-head">
                            <span class="app-instance-head-icon"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span>
                            <div>
                                <h3 class="app-instance-head-title">Revisión de formularios</h3>
                                <p class="app-instance-head-sub">
                                    Con la revisión activa, un formulario pasa por la bandeja antes de publicarse.
                                </p>
                            </div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3">
                            <div class="form-check form-switch mb-0">
                                <?php // El `hidden` con el MISMO `name` va delante: sin él, desmarcar la casilla no
                                      // enviaría nada. El handler trata el ausente como apagado, pero el oculto deja
                                      // el apagado explícito. ?>
                                <input type="hidden" name="review_required" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="reviewRequiredToggle"
                                       name="review_required" value="1"<?= ($reviewRequired ?? false) ? ' checked' : '' ?>>
                                <label class="form-check-label small fw-semibold" for="reviewRequiredToggle">Exigir revisión a todos</label>
                            </div>
                            <span class="app-instance-status <?= ($reviewRequired ?? false) ? 'is-on' : 'is-off' ?>">
                                <i class="fa-solid <?= ($reviewRequired ?? false) ? 'fa-lock-open' : 'fa-lock' ?>" aria-hidden="true"></i>
                                <?= ($reviewRequired ?? false) ? 'Revisión activa' : 'Sin revisión' ?>
                            </span>
                        </div>
                        <div class="form-check form-switch">
                            <input type="hidden" name="review_notify_email" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="reviewNotifyToggle"
                                   name="review_notify_email" value="1"<?= ($reviewNotifyEmail ?? false) ? ' checked' : '' ?>>
                            <label class="form-check-label small fw-semibold" for="reviewNotifyToggle">Avisar por correo a los revisores</label>
                        </div>
                        <p class="form-text mb-0 mt-2">
                            Quien tenga el permiso <code>forms.publish_without_review</code> queda exento; se concede
                            en <a href="/admin/roles">Roles y permisos</a> (a un rol) o como
                            <strong>permiso extra</strong> de una persona, en la pantalla de usuarios.
                        </p>
                    </div>
                    <div class="card-footer bg-white border-0 d-flex justify-content-end mt-auto">
                        <button type="submit" class="btn btn-primary app-btn-primary">
                            <i class="fa-solid fa-floppy-disk me-1"></i>Guardar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php // AG4 — Identidad operativa de la instancia: URL pública, zona horaria e
          // idioma. Los tres eran del `.env` y exigían SSH. Son un PIN que gana
          // sobre él; vaciar un campo borra la fila y vuelve al `.env`. Post
          // nativo con PRG, como marca y correo: la tarjeta no repinta huecos. ?>
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="app-instance-head">
                        <span class="app-instance-head-icon"><i class="fa-solid fa-earth-americas" aria-hidden="true"></i></span>
                        <div>
                            <h3 class="app-instance-head-title">Identidad operativa</h3>
                            <p class="app-instance-head-sub">
                                La dirección con la que se comparten los formularios, la zona horaria con la que se ven
                                las fechas y el idioma por defecto. Deja un campo en blanco para volver al
                                <code>.env</code>.
                            </p>
                        </div>
                    </div>

                    <form method="post" action="/admin/settings/instance" class="row g-3">
                        <?= Csrf::field() ?>
                        <div class="col-lg-6">
                            <label class="form-label" for="instancePublicUrl">URL pública</label>
                            <input type="url" class="form-control" id="instancePublicUrl" name="instance_public_url"
                                   value="<?= View::escape($instancePublicUrl ?? '') ?>"
                                   placeholder="<?= View::escape(($envPublicUrl ?? '') !== '' ? $envPublicUrl : 'https://formularios.tudominio.com') ?>">
                            <div class="form-text">
                                La que va en el enlace copiado, el QR y el embed.<?= ($envPublicUrl ?? '') !== '' ? ' Sin fila manda el <code>.env</code>.' : ' Vacía se deduce de cada petición (correcto sin proxy).' ?>
                            </div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label" for="instanceTimezone">Zona horaria</label>
                            <select class="form-select" id="instanceTimezone" name="instance_timezone">
                                <option value=""<?= ($instanceTimezone ?? '') === '' ? ' selected' : '' ?>>
                                    Por defecto (<?= View::escape($envTimezone ?? 'America/Caracas') ?>)</option>
                                <?php foreach (($timezoneOptions ?? []) as $tz): ?>
                                    <option value="<?= View::escape($tz) ?>"<?= ($instanceTimezone ?? '') === $tz ? ' selected' : '' ?>><?= View::escape($tz) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Con la que se muestran las fechas.</div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label" for="instanceLocale">Idioma por defecto</label>
                            <select class="form-select" id="instanceLocale" name="instance_locale">
                                <option value=""<?= ($instanceLocale ?? '') === '' ? ' selected' : '' ?>>
                                    Por defecto (<?= View::escape($envLocale ?? 'es') ?>)</option>
                                <option value="es"<?= ($instanceLocale ?? '') === 'es' ? ' selected' : '' ?>>Español</option>
                                <option value="en"<?= ($instanceLocale ?? '') === 'en' ? ' selected' : '' ?>>Inglés</option>
                            </select>
                            <div class="form-text">Un formulario puede fijar el suyo.</div>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary app-btn-primary">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Guardar identidad
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php // AG2 — Marca de la instancia. Nombre y color en un POST normal; logo y
          // favicon en formularios multipart propios (un archivo no viaja junto a
          // un texto, y su validación es otra). Todo con PRG: sin JavaScript se
          // comporta igual. ?>
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="app-instance-head">
                        <span class="app-instance-head-icon"><i class="fa-solid fa-palette" aria-hidden="true"></i></span>
                        <div>
                            <h3 class="app-instance-head-title">Marca de la instancia</h3>
                            <p class="app-instance-head-sub">
                                El nombre, el logo, el favicon y el color con los que se presenta la aplicación en el
                                panel, el inicio de sesión y los formularios publicados. Deja un campo en blanco para
                                volver al valor por defecto.
                            </p>
                        </div>
                    </div>

                    <form method="post" action="/admin/settings/brand" class="row g-3">
                        <?= Csrf::field() ?>
                        <div class="col-lg-5">
                            <label class="form-label" for="instanceName">Nombre</label>
                            <input type="text" class="form-control" id="instanceName" name="instance_name"
                                   maxlength="100" value="<?= View::escape($brandName ?? '') ?>"
                                   placeholder="<?= View::escape(\App\Core\Config::get('app.name', 'VanillaForms')) ?>">
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label" for="instanceColor">Color primario</label>
                            <div class="input-group">
                                <input type="color" class="form-control form-control-color" id="instanceColor"
                                       value="<?= View::escape($brandColor ?? Brand::DEFAULT_COLOR) ?>"
                                       aria-label="Selector de color" data-vf-color-for="instanceColorText">
                                <input type="text" class="form-control" id="instanceColorText" name="instance_color"
                                       value="<?= View::escape($brandColor ?? Brand::DEFAULT_COLOR) ?>"
                                       pattern="#[0-9a-fA-F]{6}" maxlength="7">
                            </div>
                            <div class="form-text mt-1">Formato <code>#rrggbb</code>.</div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label invisible" for="brandSaveBtn">Guardar</label>
                            <div class="d-flex justify-content-end">
                                <button type="submit" id="brandSaveBtn" class="btn btn-primary app-btn-primary">
                                    <i class="fa-solid fa-floppy-disk me-1"></i>Guardar marca
                                </button>
                            </div>
                        </div>
                    </form>

                    <hr class="my-4">

                    <p class="app-instance-subhead">Imágenes</p>
                    <div class="row g-4">
                        <?php // Logo ?>
                        <div class="col-lg-6 d-flex flex-column">
                            <h4 class="h6 fw-semibold">Logo</h4>
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <span class="app-logo-lg">
                                    <?php if (!empty($brandHasLogo) && !empty($brandLogoUrl)): ?>
                                        <img src="<?= View::escape($brandLogoUrl) ?>" alt="" class="app-logo-img">
                                    <?php else: ?>
                                        <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </span>
                                <div class="small text-muted">
                                    PNG, JPG, GIF o WEBP. Se muestra en la barra lateral y en la pantalla de acceso.
                                </div>
                            </div>
                            <form method="post" action="/admin/settings/brand/logo" enctype="multipart/form-data" class="mt-auto">
                                <?= Csrf::field() ?>
                                <div class="d-flex gap-2">
                                    <input type="file" class="form-control" name="brand_image" accept="image/png,image/jpeg,image/gif,image/webp" required>
                                    <button type="submit" class="btn btn-outline-primary app-form-cta">Subir</button>
                                </div>
                            </form>
                            <?php if (!empty($brandHasLogo)): ?>
                                <form method="post" action="/admin/settings/brand/logo/remove" data-confirm="¿Quitar el logo de la instancia?"
                                      data-confirm-ok="Sí, quitar" data-confirm-tone="danger">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn-outline-danger app-form-cta btn-sm">
                                        <i class="fa-solid fa-trash me-1"></i>Quitar logo
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <?php // Favicon ?>
                        <div class="col-lg-6 d-flex flex-column">
                            <h4 class="h6 fw-semibold">Favicon</h4>
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <span class="app-logo-lg">
                                    <?php if (!empty($brandHasFavicon) && !empty($brandFaviconUrl)): ?>
                                        <img src="<?= View::escape($brandFaviconUrl) ?>" alt="" class="app-logo-img">
                                    <?php else: ?>
                                        <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </span>
                                <div class="small text-muted">
                                    La pestaña del navegador. Cuadrado y pequeño (p. ej. 64×64).
                                </div>
                            </div>
                            <form method="post" action="/admin/settings/brand/favicon" enctype="multipart/form-data" class="mt-auto">
                                <?= Csrf::field() ?>
                                <div class="d-flex gap-2">
                                    <input type="file" class="form-control" name="brand_image" accept="image/png,image/jpeg,image/gif,image/webp" required>
                                    <button type="submit" class="btn btn-outline-primary app-form-cta">Subir</button>
                                </div>
                            </form>
                            <?php if (!empty($brandHasFavicon)): ?>
                                <form method="post" action="/admin/settings/brand/favicon/remove" data-confirm="¿Quitar el favicon de la instancia?"
                                      data-confirm-ok="Sí, quitar" data-confirm-tone="danger">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn-outline-danger app-form-cta btn-sm">
                                        <i class="fa-solid fa-trash me-1"></i>Quitar favicon
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php // AG3 — Correo (SMTP). La contraseña es un campo de SOLO ESCRITURA: no
          // se rellena nunca con el valor guardado (no se devuelve al HTML). La
          // pista «(guardada)» solo dice si hay una utilizable. ?>
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="app-instance-head">
                        <span class="app-instance-head-icon"><i class="fa-solid fa-envelope" aria-hidden="true"></i></span>
                        <div class="flex-grow-1">
                            <h3 class="app-instance-head-title">Correo (SMTP)</h3>
                            <p class="app-instance-head-sub">
                                El servidor con el que la instancia manda sus correos: avisos de respuesta, invitaciones y
                                recordatorios de una lista. Déjalo en blanco para volver al <code>.env</code>.
                            </p>
                        </div>
                        <span class="app-instance-status <?= empty($mailConfigured) ? 'is-off' : 'is-on' ?>">
                            <i class="fa-solid <?= empty($mailConfigured) ? 'fa-plug-circle-xmark' : 'fa-plug-circle-check' ?>" aria-hidden="true"></i>
                            <?= empty($mailConfigured) ? 'Sin configurar' : 'Configurado' ?>
                        </span>
                    </div>

                    <?php if (empty($mailConfigured)): ?>
                        <div class="alert alert-warning app-alert mb-3" role="status">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>
                            No hay ningún servidor configurado, así que los correos no se envían (la app funciona igual:
                            solo no salen los avisos).
                        </div>
                    <?php endif; ?>
                    <?php if (empty($mailSecretAvailable)): ?>
                        <div class="alert alert-danger app-alert mb-3" role="status">
                            <i class="fa-solid fa-key me-1"></i>
                            No hay <code>APP_KEY</code>: no se puede guardar la contraseña cifrada. Genérala con
                            <code>php scripts/generate-key.php --write</code> (la contraseña sigue leyéndose del <code>.env</code>).
                        </div>
                    <?php endif; ?>

                    <form method="post" action="/admin/settings/mail">
                        <?= Csrf::field() ?>

                        <p class="app-instance-subhead">Servidor</p>
                        <div class="row g-3">
                            <div class="col-lg-5">
                                <label class="form-label" for="mailHost">Servidor</label>
                                <input type="text" class="form-control" id="mailHost" name="mail_host"
                                       value="<?= View::escape($mailHost ?? '') ?>" placeholder="smtp.gmail.com">
                            </div>
                            <div class="col-lg-2">
                                <label class="form-label" for="mailPort">Puerto</label>
                                <input type="number" class="form-control" id="mailPort" name="mail_port"
                                       min="1" max="65535" value="<?= (int) ($mailPort ?? 587) ?>">
                            </div>
                            <div class="col-lg-3">
                                <label class="form-label" for="mailEncryption">Cifrado</label>
                                <select class="form-select" id="mailEncryption" name="mail_encryption">
                                    <option value="tls" <?= ($mailEncryption ?? '') === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                                    <option value="ssl" <?= ($mailEncryption ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                    <option value="" <?= ($mailEncryption ?? 'tls') === '' ? 'selected' : '' ?>>Ninguno</option>
                                </select>
                            </div>
                            <div class="col-lg-2">
                                <label class="form-label" for="mailTimeout">Espera (s)</label>
                                <input type="number" class="form-control" id="mailTimeout" name="mail_timeout"
                                       min="1" max="120" value="<?= (int) ($mailTimeout ?? 15) ?>">
                            </div>
                        </div>

                        <p class="app-instance-subhead mt-4">Acceso</p>
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <label class="form-label" for="mailUser">Usuario</label>
                                <input type="text" class="form-control" id="mailUser" name="mail_user"
                                       value="<?= View::escape($mailUser ?? '') ?>" autocomplete="off">
                            </div>
                            <div class="col-lg-6">
                                <label class="form-label" for="mailPassword">
                                    Contraseña
                                    <?php if (!empty($mailPasswordStored)): ?>
                                        <span class="badge bg-success-subtle text-success-emphasis ms-1">guardada</span>
                                    <?php endif; ?>
                                </label>
                                <input type="password" class="form-control" id="mailPassword" name="mail_password"
                                       value="" autocomplete="new-password"
                                       placeholder="<?= !empty($mailPasswordStored) ? 'Déjalo vacío para no cambiarla' : 'Contraseña del servidor' ?>">
                                <?php if (!empty($mailPasswordStored)): ?>
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" id="mailClearPassword" name="clear_password" value="1">
                                        <label class="form-check-label small" for="mailClearPassword">Borrar la contraseña guardada</label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <p class="app-instance-subhead mt-4">Remitente</p>
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <label class="form-label" for="mailFromAddress">Dirección</label>
                                <input type="email" class="form-control" id="mailFromAddress" name="mail_from_address"
                                       value="<?= View::escape($mailFromAddress ?? '') ?>" placeholder="no-reply@tudominio.com">
                            </div>
                            <div class="col-lg-6">
                                <label class="form-label" for="mailFromName">Nombre</label>
                                <input type="text" class="form-control" id="mailFromName" name="mail_from_name"
                                       value="<?= View::escape($mailFromName ?? '') ?>" placeholder="<?= View::escape($brandName ?? '') ?>">
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-primary app-btn-primary">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Guardar correo
                            </button>
                        </div>
                    </form>

                    <div class="app-instance-subpanel mt-4">
                        <p class="app-instance-subhead">Probar el envío</p>
                        <p class="text-muted small mb-2">
                            Guarda primero y luego manda un correo de prueba. Si no pones destino, se manda a tu propio correo.
                        </p>
                        <form method="post" action="/admin/settings/mail/test" class="row g-2 align-items-end">
                            <?= Csrf::field() ?>
                            <div class="col-lg-6">
                                <label class="form-label visually-hidden" for="mailTestTo">Destino de la prueba</label>
                                <input type="email" class="form-control" id="mailTestTo" name="test_to"
                                       placeholder="A quién (vacío = tu correo)">
                            </div>
                            <div class="col-lg-6 d-flex justify-content-end">
                                <button type="submit" class="btn btn-outline-primary app-form-cta" <?= empty($mailConfigured) ? 'disabled' : '' ?>>
                                    <i class="fa-solid fa-paper-plane me-1"></i>Enviar prueba
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php // AG7 — Cupos del dashboard por usuario (crear / exportar), por cuenta. ?>
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="app-instance-head">
                        <span class="app-instance-head-icon"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i></span>
                        <div>
                            <h3 class="app-instance-head-title">Cupos del dashboard</h3>
                            <p class="app-instance-head-sub">
                                Límites por usuario autenticado (por hora). Protegen la cuenta, no la IP.
                                Vacíalos para volver al <code>.env</code>.
                            </p>
                        </div>
                    </div>

                    <form method="post" action="/admin/settings/dashboard-limits">
                        <?= Csrf::field() ?>
                        <div class="row g-3">
                            <div class="col-lg-4">
                                <label class="form-label" for="dashCreatePerHour">Formularios creados / hora</label>
                                <input type="number" class="form-control" id="dashCreatePerHour" name="dash_create_per_hour"
                                       min="1" value="<?= View::escape($dashboardLimits['stored_create_per_hour'] ?? '') ?>"
                                       placeholder="<?= View::escape((string) ($dashboardLimits['env_create_per_hour'] ?? 30)) ?>">
                                <div class="form-text">
                                    Efectivo ahora: <strong><?= (int) ($dashboardLimits['create_per_hour'] ?? 30) ?>/h</strong>.
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label" for="dashExportPerHour">Exportaciones / hora</label>
                                <input type="number" class="form-control" id="dashExportPerHour" name="dash_export_per_hour"
                                       min="1" value="<?= View::escape($dashboardLimits['stored_export_per_hour'] ?? '') ?>"
                                       placeholder="<?= View::escape((string) ($dashboardLimits['env_export_per_hour'] ?? 120)) ?>">
                                <div class="form-text">
                                    Efectivo ahora: <strong><?= (int) ($dashboardLimits['export_per_hour'] ?? 120) ?>/h</strong>.
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-primary app-btn-primary">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Guardar cupos
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php // AG6 — Límites de subida de archivos. El campo de formulario solo
          // puede restringir, nunca ampliar. El límite efectivo tiene en cuenta
          // upload_max_filesize de PHP (lo aplica Uploader). ?>
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="app-instance-head">
                        <span class="app-instance-head-icon"><i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i></span>
                        <div>
                            <h3 class="app-instance-head-title">Subidas de archivos</h3>
                            <p class="app-instance-head-sub">
                                Tamaño máximo por archivo y número máximo por campo. Un campo puede bajar estos
                                límites, pero nunca subirlos. Vacíalos para volver al <code>.env</code>.
                            </p>
                        </div>
                    </div>

                    <form method="post" action="/admin/settings/uploads">
                        <?= Csrf::field() ?>
                        <div class="row g-3">
                            <div class="col-lg-4">
                                <label class="form-label" for="uploadMaxSizeMb">Tamaño máximo por archivo (MB)</label>
                                <input type="number" class="form-control" id="uploadMaxSizeMb" name="upload_max_size_mb"
                                       min="1" value="<?= View::escape($uploads['stored_max_size_mb'] ?? '') ?>"
                                       placeholder="<?= View::escape((string) ($uploads['env_max_size_mb'] ?? 10)) ?>">
                                <div class="form-text">
                                    Efectivo ahora: <strong><?= (int) ($uploads['max_size_mb'] ?? 10) ?> MB</strong>.
                                    Si PHP lo limita más, <code>Uploader</code> lo aplica igual.
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label" for="uploadMaxFiles">Archivos máximos por campo</label>
                                <input type="number" class="form-control" id="uploadMaxFiles" name="upload_max_files"
                                       min="1" value="<?= View::escape($uploads['stored_max_files'] ?? '') ?>"
                                       placeholder="<?= View::escape((string) ($uploads['env_max_files'] ?? 5)) ?>">
                                <div class="form-text">
                                    Efectivo ahora: <strong><?= (int) ($uploads['max_files'] ?? 5) ?></strong>.
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-primary app-btn-primary">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Guardar límites
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>


    <?php // AG5 — Anti-bot (Cloudflare Turnstile). El interruptor está en cada
          // formulario (settings.anti_bot.enabled), pero las CLAVES son de la
          // instancia: sin ellas, un formulario que pide Turnstile no se sirve.
          // La site key es pública; la secret se guarda cifrada y es de solo
          // escritura (post nativo con PRG, como marca/correo/identidad). ?>
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="app-instance-head">
                        <span class="app-instance-head-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
                        <div class="flex-grow-1">
                            <h3 class="app-instance-head-title">Anti-bot (Cloudflare Turnstile)</h3>
                            <p class="app-instance-head-sub">
                                Las claves con las que se verifica que quien responde no es un robot. Cada formulario
                                decide si lo usa; sin las claves, los que lo piden no se sirven. Déjalas en blanco para
                                volver al <code>.env</code>.
                            </p>
                        </div>
                        <span class="app-instance-status <?= empty($turnstile['configured']) ? 'is-off' : 'is-on' ?>">
                            <i class="fa-solid <?= empty($turnstile['configured']) ? 'fa-shield-halved' : 'fa-shield' ?>" aria-hidden="true"></i>
                            <?= empty($turnstile['configured']) ? 'Sin configurar' : 'Configurado' ?>
                        </span>
                    </div>

                    <?php if (empty($turnstile['configured'])): ?>
                        <div class="alert alert-warning app-alert mb-3" role="status">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>
                            Sin claves, los formularios que tengan el anti-bot encendido no se pueden responder (la app
                            funciona igual: solo quedan bloqueados esos).
                        </div>
                    <?php endif; ?>
                    <?php if (empty($secretAvailable)): ?>
                        <div class="alert alert-danger app-alert mb-3" role="status">
                            <i class="fa-solid fa-key me-1"></i>
                            No hay <code>APP_KEY</code>: no se puede guardar la secret key cifrada. Genérala con
                            <code>php scripts/generate-key.php --write</code> (la secret del <code>.env</code> se sigue leyendo).
                        </div>
                    <?php endif; ?>

                    <form method="post" action="/admin/settings/turnstile">
                        <?= Csrf::field() ?>

                        <div class="row g-3">
                            <div class="col-lg-6">
                                <label class="form-label" for="turnstileSiteKey">Site key</label>
                                <input type="text" class="form-control" id="turnstileSiteKey" name="turnstile_site_key"
                                       value="<?= View::escape($turnstile['stored_site_key'] ?? '') ?>"
                                       placeholder="<?= View::escape(($turnstile['env_site_key'] ?? '') !== '' ? $turnstile['env_site_key'] : '0x4AAAAAAA…') ?>"
                                       autocomplete="off">
                                <div class="form-text">
                                    Pública: viaja en el HTML del widget.<?= ($turnstile['env_site_key'] ?? '') !== '' ? ' Sin fila manda el <code>.env</code>.' : '' ?>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <label class="form-label" for="turnstileSecret">
                                    Secret key
                                    <?php if (!empty($turnstile['secret_stored'])): ?>
                                        <span class="badge bg-success-subtle text-success-emphasis ms-1">guardada</span>
                                    <?php endif; ?>
                                </label>
                                <input type="password" class="form-control" id="turnstileSecret" name="turnstile_secret"
                                       value="" autocomplete="new-password"
                                       placeholder="<?= !empty($turnstile['secret_stored']) ? 'Déjala vacía para no cambiarla' : 'Secret key de siteverify' ?>">
                                <?php if (!empty($turnstile['secret_stored'])): ?>
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" id="turnstileClearSecret" name="clear_secret" value="1">
                                        <label class="form-check-label small" for="turnstileClearSecret">Borrar la secret key guardada</label>
                                    </div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Privada: se guarda cifrada y no se vuelve a mostrar. Se necesitan <strong>las dos</strong> claves.
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-primary app-btn-primary">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Guardar claves
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php // AG8 — Hardening de sesión / HTTPS. ?>
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="app-instance-head">
                        <span class="app-instance-head-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
                        <div>
                            <h3 class="app-instance-head-title">Sesión y seguridad de cookies</h3>
                            <p class="app-instance-head-sub">
                                Nombre, duración, <code>Secure</code>, <code>HttpOnly</code> y <code>SameSite</code>.
                                Vacíalos para volver al <code>.env</code>. Los cambios afectan a las nuevas sesiones.
                            </p>
                        </div>
                    </div>

                    <form method="post" action="/admin/settings/session">
                        <?= Csrf::field() ?>
                        <div class="row g-3">
                            <div class="col-lg-4">
                                <label class="form-label" for="sessionName">Nombre de la cookie</label>
                                <input type="text" class="form-control" id="sessionName" name="session_name"
                                       value="<?= View::escape($sessionSettings['stored_name'] ?? '') ?>"
                                       placeholder="<?= View::escape((string) ($sessionSettings['env_name'] ?? 'vanillaforms_session')) ?>">
                                <div class="form-text">Solo letras, números y guion bajo.</div>
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label" for="sessionLifetime">Duración (segundos)</label>
                                <input type="number" class="form-control" id="sessionLifetime" name="session_lifetime"
                                       min="60" value="<?= View::escape($sessionSettings['stored_lifetime'] ?? '') ?>"
                                       placeholder="<?= View::escape((string) ($sessionSettings['env_lifetime'] ?? 7200)) ?>">
                                <div class="form-text">Mínimo 60 s. Efectivo ahora: <strong><?= (int) ($sessionSettings['lifetime'] ?? 7200) ?> s</strong>.</div>
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label" for="sessionSamesite">SameSite</label>
                                <select class="form-select" id="sessionSamesite" name="session_samesite">
                                    <option value="" <?= ($sessionSettings['stored_samesite'] ?? '') === '' ? 'selected' : '' ?>>Por defecto (<?= View::escape((string) ($sessionSettings['env_samesite'] ?? 'Lax')) ?>)</option>
                                    <option value="Lax" <?= ($sessionSettings['stored_samesite'] ?? '') === 'Lax' ? 'selected' : '' ?>>Lax</option>
                                    <option value="Strict" <?= ($sessionSettings['stored_samesite'] ?? '') === 'Strict' ? 'selected' : '' ?>>Strict</option>
                                    <option value="None" <?= ($sessionSettings['stored_samesite'] ?? '') === 'None' ? 'selected' : '' ?>>None</option>
                                </select>
                                <div class="form-text">Si usas <code>None</code> necesitas <code>Secure</code>.</div>
                            </div>
                            <div class="col-lg-4">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="sessionSecure" name="session_secure" value="1" <?= (($sessionSettings['stored_secure'] ?? '') === '1') || (($sessionSettings['stored_secure'] ?? '') === '' && !empty($sessionSettings['env_secure'])) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="sessionSecure">Secure</label>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="sessionHttpOnly" name="session_httponly" value="1" <?= (($sessionSettings['stored_httponly'] ?? '') === '1') || (($sessionSettings['stored_httponly'] ?? '') === '' && !empty($sessionSettings['env_httponly'])) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="sessionHttpOnly">HttpOnly</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-primary app-btn-primary">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Guardar sesión
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php // AG9 — Red, proxy y cabeceras de seguridad. ?>
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="app-instance-head">
                        <span class="app-instance-head-icon"><i class="fa-solid fa-network-wired" aria-hidden="true"></i></span>
                        <div>
                            <h3 class="app-instance-head-title">Red, proxy y cabeceras</h3>
                            <p class="app-instance-head-sub">
                                <code>TRUST_PROXY</code>, <code>APP_REDIRECT_ALLOWLIST</code>, <code>X-Frame-Options</code> y <code>Content-Security-Policy</code>.
                                Vacíalos para volver al <code>.env</code>.
                            </p>
                        </div>
                    </div>

                    <form method="post" action="/admin/settings/network">
                        <?= Csrf::field() ?>
                        <div class="row g-3">
                            <div class="col-lg-4">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="trustProxy" name="trust_proxy" value="1" <?= (($networkSettings['stored_trust_proxy'] ?? '') === '1') || (($networkSettings['stored_trust_proxy'] ?? '') === '' && !empty($networkSettings['env_trust_proxy'])) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="trustProxy">Confiar en proxy (<code>TRUST_PROXY</code>)</label>
                                </div>
                                <div class="form-text">Usar <code>X-Forwarded-For</code>/<code>X-Forwarded-Proto</code>.</div>
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label" for="redirectAllowlist">Allowlist de redirección externa</label>
                                <input type="text" class="form-control" id="redirectAllowlist" name="redirect_allowlist"
                                       value="<?= View::escape($networkSettings['stored_redirect_allowlist'] ?? '') ?>"
                                       placeholder="<?= View::escape((string) ($networkSettings['env_redirect_allowlist'] ?? '')) ?>">
                                <div class="form-text">Separados por coma. Vacío = bloqueadas.</div>
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label" for="frameOptions">X-Frame-Options</label>
                                <select class="form-select" id="frameOptions" name="frame_options">
                                    <option value="" <?= ($networkSettings['stored_frame_options'] ?? '') === '' ? 'selected' : '' ?>>Por defecto (<?= View::escape((string) ($networkSettings['env_frame_options'] ?? 'SAMEORIGIN')) ?>)</option>
                                    <option value="DENY" <?= ($networkSettings['stored_frame_options'] ?? '') === 'DENY' ? 'selected' : '' ?>>DENY</option>
                                    <option value="SAMEORIGIN" <?= ($networkSettings['stored_frame_options'] ?? '') === 'SAMEORIGIN' ? 'selected' : '' ?>>SAMEORIGIN</option>
                                </select>
                                <div class="form-text">Para embed usar <code>SAMEORIGIN</code> o ajustar por formulario.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="cspPolicy">Content-Security-Policy global</label>
                                <textarea class="form-control" id="cspPolicy" name="csp_policy" rows="3" placeholder="<?= View::escape((string) ($networkSettings['env_csp_policy'] ?? '')) ?>"><?= View::escape($networkSettings['stored_csp_policy'] ?? '') ?></textarea>
                                <div class="form-text">Vacío = sin cabecera global. El CSP del formulario puede tener su propia política.</div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-primary app-btn-primary">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Guardar ajustes de red
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php // AG10 — Webhook a red local. ?>
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="app-instance-head">
                        <span class="app-instance-head-icon"><i class="fa-solid fa-webhook" aria-hidden="true"></i></span>
                        <div>
                            <h3 class="app-instance-head-title">Webhook</h3>
                            <p class="app-instance-head-sub">
                                Permitir que los webhooks apunten a la red local (dos opt-ins: este y el del formulario).
                            </p>
                        </div>
                    </div>

                    <form method="post" action="/admin/settings/webhook">
                        <?= Csrf::field() ?>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="webhookAllowPrivate" name="webhook_allow_private_hosts" value="1" <?= (($webhookSettings['stored_allow_private_hosts'] ?? '') === '1') || (($webhookSettings['stored_allow_private_hosts'] ?? '') === '' && !empty($webhookSettings['env_allow_private_hosts'])) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="webhookAllowPrivate">Permitir hosts de red privada</label>
                        </div>
                        <div class="form-text">Efectivo ahora: <strong><?= !empty($webhookSettings['allow_private_hosts']) ? 'sí' : 'no' ?></strong>.</div>
                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-primary app-btn-primary">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Guardar ajustes de webhook
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php // `dashboard.js` con `defer`, igual que en el resto del panel: aquí solo
      // vive el bloque del interruptor de registro. ?>
<script src="<?= View::asset('js/dashboard.js') ?>" defer></script>
