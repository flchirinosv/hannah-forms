<?php
/**
 * Sub-navegación del panel de administración. Cada pestaña solo aparece si el
 * usuario tiene el permiso correspondiente (RBAC): el enlace de "Usuarios"
 * exige `admin.users`, "Grupos" `admin.groups`, etc.
 */
use App\Core\Auth;
use App\Core\View;
$active = $active ?? '';
$items = [
    'users'       => ['/admin', 'Usuarios', 'fa-users', 'admin.users'],
    'groups'      => ['/admin/groups', 'Grupos de roles', 'fa-layer-group', 'admin.groups'],
    'roles'       => ['/admin/roles', 'Permisos', 'fa-key', 'admin.roles'],
    'security_log'=> ['/admin/security-log', 'Log de seguridad', 'fa-shield-halved', 'logs.view'],
    'forms'       => ['/admin/forms', 'Formularios', 'fa-clipboard-list', 'admin.access'],
];
$visible = array_filter($items, static function ($item) {
    return Auth::can($item[3]);
});
if ($visible === []) {
    return;
}
?>
<div class="mb-4">
    <ul class="nav nav-pills app-admin-nav">
        <?php foreach ($visible as $key => $item): ?>
            <li class="nav-item">
                <a class="nav-link <?= $active === $key ? 'active' : '' ?>" href="<?= View::escape($item[0]) ?>">
                    <i class="fa-solid <?= $item[2] ?> me-1"></i><?= View::escape($item[1]) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>