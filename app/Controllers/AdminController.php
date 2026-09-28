<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Core\View;
use App\Models\Role;
use App\Models\RoleGroup;
use App\Models\User;
use App\Models\Form;

/**
 * Panel de administración.
 *
 * El middleware `admin` ya pide `admin.access`; dentro de cada acción se
 * vuelve a comprobar el permiso concreto (`admin.users`, `admin.groups`, ...)
 * como defensa en profundidad y para que un grupo con `admin.access` (pero sin
 * el resto) no abra acciones que no le corresponden.
 */
class AdminController
{
    // ------------------------------------------------------------------
    // Usuarios (/admin)
    // ------------------------------------------------------------------

    public function index(): void
    {
        if (!Auth::can('admin.users')) {
            Response::forbidden();
            return;
        }

        View::render('admin/users', [
            'users'         => User::all(),
            'roles'         => Role::all(),
            'groups'        => RoleGroup::all(),
            'groupMembers'  => self::groupMembers(),
            'csrf'          => Csrf::token(),
        ]);
    }

    public function updateRole(): void
    {
        if (!Auth::can('admin.users')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin');
            return;
        }

        $userId = (int) Request::post('user_id', 0);
        $roleId = (int) Request::post('role_id', 0);

        $target = User::find($userId);
        if ($target === null) {
            Session::flash('error', 'Usuario no encontrado.');
            Response::redirect('/admin');
            return;
        }

        // No permitir degradar al propio admin
        if (Auth::id() === $userId && $roleId !== 1) {
            Session::flash('error', 'No puedes cambiar tu propio rol de administrador.');
            Response::redirect('/admin');
            return;
        }

        if (!in_array($roleId, array_column(Role::all(), 'id'), true)) {
            Session::flash('error', 'Rol inválido.');
            Response::redirect('/admin');
            return;
        }

        User::updateRole($userId, $roleId);
        SecurityLog::record('admin.role_change', 'usuario #' . $userId . ' -> rol #' . $roleId);
        Session::flash('success', 'Rol actualizado.');
        Response::redirect('/admin');
        return;
    }

    public function toggleActive(): void
    {
        if (!Auth::can('admin.users')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin');
            return;
        }

        $userId = (int) Request::post('user_id', 0);
        $target = User::find($userId);

        if ($target === null) {
            Session::flash('error', 'Usuario no encontrado.');
            Response::redirect('/admin');
            return;
        }

        if (Auth::id() === $userId) {
            Session::flash('error', 'No puedes desactivar tu propia cuenta.');
            Response::redirect('/admin');
            return;
        }

        User::toggleActive($userId);
        SecurityLog::record('admin.user_toggle', 'usuario #' . $userId . ( (int) $target['is_active'] === 1 ? ' -> desactivado' : ' -> activado'));
        Session::flash('success', 'Estado de usuario actualizado.');
        Response::redirect('/admin');
        return;
    }

    public function allForms(): void
    {
        if (!Auth::can('admin.access')) {
            Response::forbidden();
            return;
        }

        $forms = Form::all();
        View::render('admin/forms', [
            'forms' => $forms,
            'csrf'  => Csrf::token(),
        ]);
    }

    // ------------------------------------------------------------------
    // Grupos de roles (/admin/groups)
    // ------------------------------------------------------------------

    public function groups(): void
    {
        if (!Auth::can('admin.groups')) {
            Response::forbidden();
            return;
        }

        View::render('admin/groups', [
            'groups'        => RoleGroup::all(),
            'roles'         => Role::all(),
            'users'         => User::all(),
            'groupRoles'    => self::groupRoles(),
            'groupMembers'  => self::groupMembers(),
            'csrf'          => Csrf::token(),
        ]);
    }

    public function groupCreate(): void
    {
        if (!Auth::can('admin.groups')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin/groups');
            return;
        }

        $name = trim(Request::post('name', ''));
        if ($name === '' || mb_strlen($name) > 80) {
            Session::flash('error', 'El nombre del grupo es obligatorio (máx. 80 caracteres).');
            Response::redirect('/admin/groups');
            return;
        }

        $groupId = RoleGroup::create($name, trim(Request::post('description', '')));
        RoleGroup::setRoles($groupId, self::postedIds('roles'));
        RoleGroup::setUsers($groupId, self::postedIds('users'));
        SecurityLog::record('admin.group_create', 'grupo #' . $groupId . ' («' . $name . '»)');
        Session::flash('success', 'Grupo creado.');
        Response::redirect('/admin/groups');
        return;
    }

    public function groupUpdate(): void
    {
        if (!Auth::can('admin.groups')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin/groups');
            return;
        }

        $groupId = (int) Request::post('group_id', 0);
        if (RoleGroup::find($groupId) === null) {
            Session::flash('error', 'Grupo no encontrado.');
            Response::redirect('/admin/groups');
            return;
        }

        $name = trim(Request::post('name', ''));
        if ($name === '' || mb_strlen($name) > 80) {
            Session::flash('error', 'El nombre del grupo es obligatorio (máx. 80 caracteres).');
            Response::redirect('/admin/groups');
            return;
        }

        RoleGroup::update($groupId, $name, trim(Request::post('description', '')));
        RoleGroup::setRoles($groupId, self::postedIds('roles'));
        RoleGroup::setUsers($groupId, self::postedIds('users'));
        SecurityLog::record('admin.group_create', 'grupo #' . $groupId . ' actualizado («' . $name . '»)');
        Session::flash('success', 'Grupo actualizado.');
        Response::redirect('/admin/groups');
        return;
    }

    public function groupDelete(): void
    {
        if (!Auth::can('admin.groups')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin/groups');
            return;
        }

        $groupId = (int) Request::post('group_id', 0);
        $group = RoleGroup::find($groupId);
        if ($group === null) {
            Session::flash('error', 'Grupo no encontrado.');
            Response::redirect('/admin/groups');
            return;
        }

        RoleGroup::delete($groupId);
        SecurityLog::record('admin.group_delete', 'grupo #' . $groupId . ' eliminado');
        Session::flash('success', 'Grupo eliminado.');
        Response::redirect('/admin/groups');
        return;
    }

    // ------------------------------------------------------------------
    // Matriz de permisos (/admin/roles)
    // ------------------------------------------------------------------

    public function roles(): void
    {
        if (!Auth::can('admin.roles')) {
            Response::forbidden();
            return;
        }

        View::render('admin/roles', [
            'roles'       => Role::allWithPermissions(),
            'permissions' => self::catalog(),
            'csrf'        => Csrf::token(),
        ]);
    }

    public function updateRoleMatrix(): void
    {
        if (!Auth::can('admin.roles')) {
            Response::forbidden();
            return;
        }
        if (!Csrf::verifyRequest()) {
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin/roles');
            return;
        }

        $catalogKeys = array_keys(self::catalog());
        $roles = Role::all();

        // El navegador siempre manda `perms[<roleId>][]` = claves marcadas.
        // Un POST sin esa estructura (p. ej. cuerpo JSON o una petición vacía)
        // NO significa "desmarcar todo": sin la matriz no hay nada que guardar
        // y vaciarla dejaría todos los roles sin permisos salvo admin.access.
        $posted = Request::post('perms', []);
        if ($posted === []) {
            Session::flash('error', 'No se recibió la matriz de permisos. Nada que guardar.');
            Response::redirect('/admin/roles');
            return;
        }

        foreach ($roles as $role) {
            $roleId = (int) $role['id'];
            // El navegador manda `perms[<roleId>][]` = claves marcadas.
            $keys = isset($posted[(string) $roleId]) && is_array($posted[(string) $roleId])
                ? array_values($posted[(string) $roleId])
                : [];

            // Solo claves del catálogo (nunca junk del cliente).
            $keys = array_values(array_filter(array_map('strval', $keys), static fn($k) => in_array($k, $catalogKeys, true)));

            // El rol admin siempre conserva admin.access: sin él el propio
            // panel queda inaccesible y nadie puede arreglar la matriz.
            if (strtolower((string) $role['name']) === 'admin' && !in_array('admin.access', $keys, true)) {
                $keys[] = 'admin.access';
            }

            if ($keys === Role::permissionKeys($roleId)) {
                continue; // sin cambios: no tocar ni re-loggear
            }

            Role::setPermissions($roleId, $keys);
            SecurityLog::record(
                'admin.role_matrix',
                'rol «' . (string) $role['name'] . '»: ' . implode(', ', $keys) . '.'
            );
        }

        // Un cambio de permisos afecta a la caché de permisos de CADA request;
        // con la caché de procesos y el retorno normal se ve en el siguiente.
        Session::flash('success', 'Matriz de permisos actualizada.');
        Response::redirect('/admin/roles');
        return;
    }

    // ------------------------------------------------------------------
    // Log de seguridad (/admin/security-log)
    // ------------------------------------------------------------------

    public function securityLog(): void
    {
        if (!Auth::can('logs.view')) {
            Response::forbidden();
            return;
        }

        $event = trim(Request::query('event', ''));
        $page = max(1, (int) Request::query('page', '1'));

        $logs = SecurityLog::recent($event, $page, 50);

        View::render('admin/security_log', [
            'logs'    => $logs,
            'event'   => $event,
            'events'  => SecurityLog::knownEvents(),
            'csrf'    => Csrf::token(),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Catálogo de permisos: 'clave' => 'descripción'. Sin autoload: archivo
     * plano de config.
     *
     * @return array<string,string>
     */
    private static function catalog(): array
    {
        $catalog = require dirname(__DIR__, 2) . '/config/permissions.php';
        return array_map(static fn($v) => is_array($v) ? (string) ($v[0] ?? '') : '', (array) $catalog);
    }

    /** roles/users marcados por checkbox: 'roles[]' => list<int>. */
    private static function postedIds(string $key): array
    {
        $posted = Request::post($key, []);
        if (!is_array($posted)) {
            return [];
        }
        return array_values(array_map('intval', $posted));
    }

    /** Por grupo, los ids de usuarios que lo componen. */
    private static function groupMembers(): array
    {
        $out = [];
        foreach (RoleGroup::all() as $group) {
            $out[(int) $group['id']] = RoleGroup::userIds((int) $group['id']);
        }
        return $out;
    }

    /** Por grupo, los ids de roles que lo componen. */
    private static function groupRoles(): array
    {
        $out = [];
        foreach (RoleGroup::all() as $group) {
            $out[(int) $group['id']] = RoleGroup::roleIds((int) $group['id']);
        }
        return $out;
    }
}