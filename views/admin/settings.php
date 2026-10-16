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
</div>

<?php // `dashboard.js` con `defer`, igual que en el resto del panel: aquí solo
      // vive el bloque del interruptor de registro. ?>
<script src="<?= View::asset('js/dashboard.js') ?>" defer></script>
