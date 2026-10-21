<?php
/**
 * Ajustes de la INSTANCIA: registro público y política de revisión.
 *
 * Los dos deciden quién puede crear una cuenta o publicar formularios en la
 * instalación entera, así que no viven en la tabla de usuarios (de la que no
 * son una fila) ni en la matriz de permisos (que es de roles y personas): van
 * en su propia pantalla, con su propio permiso `admin.access`.
 *
 * El formulario de revisión es NATIVO y responde con PRG: la pantalla no
 * repinta ningún hueco. El interruptor de registro va por fetch
 * (`dashboard.js`, enganchado a `#instance`), porque su tarjeta sí se queda en
 * pantalla y recargar entera para mover una casilla era otra operación.
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
        'subtitle' => 'Deciden quién puede <strong>crear cuentas</strong> y quién puede <strong>publicar formularios</strong>'
            . ' en esta instalación. No dependen de nadie en concreto: son del panel entero.',
    ]) ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100" id="instance"
                 data-csrf="<?= View::escape(Csrf::token()) ?>"
                 data-registration-endpoint="/admin/settings/registration">
                <div class="card-body">
                    <h3 class="h6 fw-bold mb-1"><i class="fa-solid fa-globe me-2 text-primary"></i>Acceso de cualquiera</h3>
                    <p class="text-muted small mb-3">
                        El registro público es la puerta de <code>/register</code>. Con él cerrado, las cuentas
                        se crean desde la pantalla de usuarios de este panel.
                    </p>
                    <div class="form-check form-switch d-flex align-items-center gap-2">
                        <input type="checkbox" role="switch" class="form-check-input" id="registrationToggle"
                               <?= ($registrationEnabled ?? false) ? 'checked' : '' ?>
                               aria-describedby="registrationHint">
                        <label class="form-check-label fw-semibold" for="registrationToggle">
                            <span data-role="registration-state">
                                <?= ($registrationEnabled ?? false) ? 'Registro público abierto' : 'Registro público cerrado' ?>
                            </span>
                        </label>
                    </div>
                    <p class="form-text mb-0" id="registrationHint" role="status"></p>
                </div>
            </div>
        </div>

        <?php // F3 (revisada) — La política de revisión es de la INSTANCIA y no
              // del formulario. Un POST NATIVO (no fetch): la pantalla no repinta
              // huecos, así que el PRG de siempre es el camino único, con o sin
              // JavaScript. ?>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <form method="post" action="/admin/settings/review">
                    <?= Csrf::field() ?>
                    <div class="card-body">
                        <h3 class="h6 fw-bold mb-1"><i class="fa-solid fa-user-check me-2 text-primary"></i>Revisión de formularios</h3>
                        <p class="text-muted small mb-3">
                            Con la revisión activa, un formulario pasa por la bandeja de revisión antes de publicarse.
                        </p>
                        <div class="form-check form-switch mb-1">
                            <?php // El `hidden` con el MISMO `name` va delante: sin él, desmarcar la casilla no
                                  // enviaría nada. El handler trata el ausente como apagado, pero el oculto deja
                                  // el apagado explícito. ?>
                            <input type="hidden" name="review_required" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="reviewRequiredToggle"
                                   name="review_required" value="1"<?= ($reviewRequired ?? false) ? ' checked' : '' ?>>
                            <label class="form-check-label small fw-semibold" for="reviewRequiredToggle">Exigir revisión a todos</label>
                        </div>
                        <div class="form-check form-switch">
                            <input type="hidden" name="review_notify_email" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="reviewNotifyToggle"
                                   name="review_notify_email" value="1"<?= ($reviewNotifyEmail ?? false) ? ' checked' : '' ?>>
                            <label class="form-check-label small fw-semibold" for="reviewNotifyToggle">Avisar por correo a los revisores</label>
                        </div>
                        <p class="form-text mb-0">
                            Quien tenga el permiso <code>forms.publish_without_review</code> queda exento; se concede
                            en <a href="/admin/roles">Roles y permisos</a> (a un rol) o como
                            <strong>permiso extra</strong> de una persona, en la pantalla de usuarios.
                        </p>
                    </div>
                    <div class="card-footer bg-white border-0 d-flex justify-content-end">
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
                    <h3 class="h6 fw-bold mb-1"><i class="fa-solid fa-palette me-2 text-primary"></i>Marca de la instancia</h3>
                    <p class="text-muted small mb-3">
                        El nombre, el logo, el favicon y el color con los que se presenta la aplicación en el panel,
                        el inicio de sesión y los formularios publicados. Deja un campo en blanco para volver al valor
                        por defecto.
                    </p>

                    <form method="post" action="/admin/settings/brand" class="row g-3 align-items-end">
                        <?= Csrf::field() ?>
                        <div class="col-lg-5">
                            <label class="form-label" for="instanceName">Nombre</label>
                            <input type="text" class="form-control" id="instanceName" name="instance_name"
                                   maxlength="100" value="<?= View::escape($brandName ?? '') ?>"
                                   placeholder="<?= View::escape(\App\Core\Config::get('app.name', 'VanillaForms')) ?>">
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label" for="instanceColor">Color primario</label>
                            <div class="input-group">
                                <input type="color" class="form-control form-control-color" id="instanceColor"
                                       value="<?= View::escape($brandColor ?? Brand::DEFAULT_COLOR) ?>"
                                       aria-label="Selector de color" data-vf-color-for="instanceColorText">
                                <input type="text" class="form-control" id="instanceColorText" name="instance_color"
                                       value="<?= View::escape($brandColor ?? Brand::DEFAULT_COLOR) ?>"
                                       pattern="#[0-9a-fA-F]{6}" maxlength="7">
                            </div>
                            <div class="form-text">Formato <code>#rrggbb</code>.</div>
                        </div>
                        <div class="col-lg-4 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary app-btn-primary">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Guardar marca
                            </button>
                        </div>
                    </form>

                    <hr class="my-4">

                    <div class="row g-4">
                        <?php // Logo ?>
                        <div class="col-lg-6">
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
                            <form method="post" action="/admin/settings/brand/logo" enctype="multipart/form-data" class="mb-2">
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
                        <div class="col-lg-6">
                            <h4 class="h6 fw-semibold">Favicon</h4>
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <span class="app-logo">
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
                            <form method="post" action="/admin/settings/brand/favicon" enctype="multipart/form-data" class="mb-2">
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
                    <h3 class="h6 fw-bold mb-1"><i class="fa-solid fa-envelope me-2 text-primary"></i>Correo (SMTP)</h3>
                    <p class="text-muted small mb-3">
                        El servidor con el que la instancia manda sus correos: avisos de respuesta, invitaciones y
                        recordatorios de una lista. Déjalo en blanco para volver al <code>.env</code>.
                        <?php if (empty($mailSecretAvailable)): ?>
                            <br><span class="text-danger fw-semibold">
                                No hay <code>APP_KEY</code>: no se puede guardar la contraseña cifrada. Genérala con
                                <code>php scripts/generate-key.php --write</code> (la contraseña sigue leyéndose del <code>.env</code>).
                            </span>
                        <?php endif; ?>
                    </p>

                    <?php if (empty($mailConfigured)): ?>
                        <div class="alert alert-warning app-alert mb-3" role="status">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>
                            Ahora mismo no hay ningún servidor configurado, así que los correos no se envían
                            (la app funciona igual: solo no salen los avisos).
                        </div>
                    <?php endif; ?>

                    <form method="post" action="/admin/settings/mail" class="row g-3">
                        <?= Csrf::field() ?>
                        <div class="col-lg-4">
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
                        <div class="col-lg-3">
                            <label class="form-label" for="mailTimeout">Tiempo de espera (s)</label>
                            <input type="number" class="form-control" id="mailTimeout" name="mail_timeout"
                                   min="1" max="120" value="<?= (int) ($mailTimeout ?? 15) ?>">
                        </div>

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

                        <div class="col-lg-6">
                            <label class="form-label" for="mailFromAddress">Remitente (dirección)</label>
                            <input type="email" class="form-control" id="mailFromAddress" name="mail_from_address"
                                   value="<?= View::escape($mailFromAddress ?? '') ?>" placeholder="no-reply@tudominio.com">
                        </div>
                        <div class="col-lg-6">
                            <label class="form-label" for="mailFromName">Remitente (nombre)</label>
                            <input type="text" class="form-control" id="mailFromName" name="mail_from_name"
                                   value="<?= View::escape($mailFromName ?? '') ?>" placeholder="<?= View::escape($brandName ?? '') ?>">
                        </div>

                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary app-btn-primary">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Guardar correo
                            </button>
                        </div>
                    </form>

                    <hr class="my-4">

                    <h4 class="h6 fw-semibold">Probar el envío</h4>
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

<?php // `dashboard.js` con `defer`, igual que en el resto del panel: aquí solo
      // vive el bloque del interruptor de registro. ?>
<script src="<?= View::asset('js/dashboard.js') ?>" defer></script>
