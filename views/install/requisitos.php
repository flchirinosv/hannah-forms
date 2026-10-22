<?php
use App\Core\View;

$pasos = [
    'requisitos' => 'Requisitos',
    'base'       => 'Base de datos',
    'admin'      => 'Administrador',
    'listo'      => 'Listo',
];
$actual = 'requisitos';
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

<h2 class="h5 mb-3">Requisitos del servidor</h2>

<?php if ($requisitos === []): ?>
    <div class="alert alert-success d-flex align-items-center" role="status">
        <i class="fa-solid fa-circle-check me-2" aria-hidden="true"></i>
        <span>Todo listo. PHP <?= View::escape($phpVersion) ?>, extensiones y permisos correctos.</span>
    </div>
<?php else: ?>
    <ul class="list-group mb-3">
        <?php foreach ($requisitos as $p): ?>
            <?php $esError = ($p['nivel'] ?? '') === 'error'; ?>
            <li class="list-group-item d-flex align-items-start">
                <i class="fa-solid <?= $esError ? 'fa-circle-xmark text-danger' : 'fa-triangle-exclamation text-warning' ?> me-2 mt-1"
                   aria-hidden="true"></i>
                <span><?= View::escape($p['texto']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($hayErrores): ?>
    <div class="alert alert-danger" role="alert">
        Corrige los requisitos marcados en rojo antes de continuar.
    </div>
    <a href="/install.php?paso=requisitos" class="btn btn-outline-secondary app-form-cta">Volver a comprobar</a>
<?php else: ?>
    <a href="/install.php?paso=base" class="btn btn-primary app-btn-primary app-form-cta">Continuar</a>
<?php endif; ?>
