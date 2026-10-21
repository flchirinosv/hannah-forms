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
</div>

<?php // `dashboard.js` con `defer`, igual que en el resto del panel: aquí solo
      // vive el bloque del interruptor de registro. ?>
<script src="<?= View::asset('js/dashboard.js') ?>" defer></script>
