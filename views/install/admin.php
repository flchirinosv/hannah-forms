<?php
use App\Core\View;

$pasos = [
    'requisitos' => 'Requisitos',
    'base'       => 'Base de datos',
    'admin'      => 'Administrador',
    'listo'      => 'Listo',
];
$actual = 'admin';
$passwordMin = \App\Controllers\AuthController::PASSWORD_MIN;
?>
<ul class="nav nav-pills app-install-steps mb-4">
    <?php foreach ($pasos as $clave => $etiqueta): ?>
        <?php $activo = $clave === $actual; ?>
        <li class="nav-item">
            <span class="nav-link <?= $activo ? 'active' : 'disabled' ?>" <?= $activo ? 'aria-current="step"' : '' ?>>
                <?= View::escape($etiqueta) ?>
            </span>
        </li>
    <?php endforeach; ?>
</ul>

<h2 class="h5 mb-3">Cuenta de administrador</h2>

<?php if ($errores !== []): ?>
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
            <?php foreach ($errores as $e): ?>
                <li><?= View::escape($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="/install.php?paso=admin" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= View::escape($csrf) ?>">

    <div class="col-12">
        <label class="form-label" for="admin_name">Nombre</label>
        <input type="text" class="form-control" id="admin_name" name="admin_name"
               value="<?= View::escape($adminForm['name']) ?>" required>
    </div>
    <div class="col-12">
        <label class="form-label" for="admin_email">Email</label>
        <input type="email" class="form-control" id="admin_email" name="admin_email"
               value="<?= View::escape($adminForm['email']) ?>" autocomplete="off" required>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="admin_password">Contraseña</label>
        <input type="password" class="form-control" id="admin_password" name="admin_password"
               minlength="<?= (int) $passwordMin ?>" autocomplete="new-password" required>
        <div class="form-text">Al menos <?= (int) $passwordMin ?> caracteres.</div>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="admin_password_confirm">Repite la contraseña</label>
        <input type="password" class="form-control" id="admin_password_confirm" name="admin_password_confirm"
               minlength="<?= (int) $passwordMin ?>" autocomplete="new-password" required>
    </div>

    <div class="col-12">
        <label class="form-label" for="app_url">URL de la aplicación</label>
        <input type="url" class="form-control" id="app_url" name="app_url"
               value="<?= View::escape($appUrl) ?>" required>
        <div class="form-text">La dirección con la que se comparte el formulario, p. ej. https://forms.midominio.com</div>
    </div>

    <div class="col-12 d-flex justify-content-between">
        <a href="/install.php?paso=base" class="btn btn-outline-secondary app-form-cta">Atrás</a>
        <button type="submit" class="btn btn-primary app-btn-primary app-form-cta">Terminar instalación</button>
    </div>
</form>
