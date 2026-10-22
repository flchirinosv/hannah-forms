<?php
use App\Core\View;

$pasos = [
    'requisitos' => 'Requisitos',
    'base'       => 'Base de datos',
    'admin'      => 'Administrador',
    'listo'      => 'Listo',
];
$actual = 'base';
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

<h2 class="h5 mb-3">Conexión a la base de datos</h2>
<p class="text-secondary small">
    Si la base no existe, el instalador la crea. El usuario necesita permiso para
    crear bases y tablas.
</p>

<?php if ($errores !== []): ?>
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
            <?php foreach ($errores as $e): ?>
                <li><?= View::escape($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="/install.php?paso=base" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= View::escape($csrf) ?>">

    <div class="col-md-8">
        <label class="form-label" for="db_host">Servidor (host)</label>
        <input type="text" class="form-control" id="db_host" name="db_host"
               value="<?= View::escape($dbForm['host']) ?>" required>
    </div>
    <div class="col-md-4">
        <label class="form-label" for="db_port">Puerto</label>
        <input type="text" class="form-control" id="db_port" name="db_port"
               value="<?= View::escape($dbForm['port']) ?>" required>
    </div>

    <div class="col-12">
        <label class="form-label" for="db_name">Nombre de la base</label>
        <input type="text" class="form-control" id="db_name" name="db_name"
               value="<?= View::escape($dbForm['name']) ?>" required>
        <div class="form-text">Solo letras, números y guion bajo.</div>
    </div>

    <div class="col-md-6">
        <label class="form-label" for="db_user">Usuario</label>
        <input type="text" class="form-control" id="db_user" name="db_user"
               value="<?= View::escape($dbForm['user']) ?>" autocomplete="off" required>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="db_password">Contraseña</label>
        <input type="password" class="form-control" id="db_password" name="db_password"
               value="" autocomplete="new-password">
    </div>

    <div class="col-12 d-flex justify-content-between">
        <a href="/install.php?paso=requisitos" class="btn btn-outline-secondary app-form-cta">Atrás</a>
        <button type="submit" class="btn btn-primary app-btn-primary app-form-cta">Conectar y crear tablas</button>
    </div>
</form>
