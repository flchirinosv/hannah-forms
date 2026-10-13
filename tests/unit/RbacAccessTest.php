<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Session;
use App\Models\Role;
use App\Models\RoleGroup;
use App\Models\User;

/**
 * RBAC — Auth::can() y permisos efectivos (2026-09-28).
 *
 * El usuario hereda los permisos de SU rol más los de los roles de los grupos a
 * los que pertenece (`Role::effectivePermissionKeys`). Todo gate de negocio se
 * evalúa con `Auth::can()`; estos tests pinzan el contrato:
 *
 *  - el rol `user` opera sus propios formularios pero no pisa /admin;
 *  - `editor` edita pero no elimina (F5.2: sin delete_own);
 *  - un grupo APORTA permisos de los roles que agrupa sin cambiarle el rol a
 *    nadie (la vía para `forms.manage_all` de un equipo no-admin).
 */
class RbacAccessTest
{
    private static bool $cleanupHookRegistered = false;
    private static ?int $userId = null;
    private static ?int $editorId = null;

    public function setUp(): void
    {
        self::ensureCleanupHook();
        self::cleanupResiduals();

        self::$userId = User::create('RBAC User', 'rbac-' . bin2hex(random_bytes(4)) . '@test.local', 'password', 2);
        self::$editorId = User::create('RBAC Editor', 'rbac-editor-' . bin2hex(random_bytes(4)) . '@test.local', 'password', 3);
        self::authAs(self::$userId);
    }

    public function tearDown(): void
    {
        self::cleanupResiduals();
        Session::remove('user_id');
        Auth::forgetCache();
    }

    public function testUserTieneSusPermisosPropiosSinAccesosAdmin(): void
    {
        Auth::forgetCache();
        foreach (['dashboard.view', 'forms.create', 'forms.edit_own', 'forms.delete_own', 'forms.export_own', 'analytics.view_own', 'responses.view_own', 'attachments.download_own'] as $perm) {
            assertTrue(Auth::can($perm), 'user debería poder ' . $perm);
        }
        assertFalse(Auth::can('admin.access'), 'un usuario normal no entra a /admin');
        assertFalse(Auth::can('forms.manage_all'), 'sin grupo que lo otorgue, no ve formularios ajenos');
    }

    public function testEditorNoTieneDeleteOwn(): void
    {
        self::authAs(self::$editorId);
        assertTrue(Auth::can('forms.edit_own'), 'editor edita formularios propios');
        assertFalse(Auth::can('forms.delete_own'), 'editor NO elimina formularios (ni propios)');
        assertTrue(Auth::can('forms.export_own'), 'editor sí exporta');
    }

    public function testUnGrupoAportaPermisosDeSusRoles(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute(
            'INSERT INTO roles (name, description) VALUES (:n, :d)',
            ['n' => 'equipo-rbac-' . bin2hex(random_bytes(3)), 'd' => 'rol de test']
        );
        $entryRoleId = (int) $db->lastInsertId();
        Role::setPermissions($entryRoleId, ['forms.manage_all']);

        $groupId = RoleGroup::create('Grupo RBAC-' . bin2hex(random_bytes(3)), 'test');
        RoleGroup::setRoles($groupId, [$entryRoleId]);
        RoleGroup::setUsers($groupId, [self::$userId]);

        self::authAs(self::$userId); // refresca la caché de permisos del request

        assertTrue(Auth::can('forms.manage_all'), 'el grupo aporta forms.manage_all sin tocar el rol del usuario');
        assertFalse(Auth::can('admin.access'), 'los permisos del grupo NO otorgan admin.access (el rol admin no está en el grupo)');

        $perms = Auth::permissions();
        assertTrue(
            in_array('forms.manage_all', $perms, true) && in_array('forms.create', $perms, true),
            'Auth::permissions() = unión de permisos del rol propio y de los roles del grupo'
        );

        // Salir del grupo revierte el permiso (la siguiente condición de sesión no lo ve).
        RoleGroup::delete($groupId);
        self::authAs(self::$userId);
        assertFalse(Auth::can('forms.manage_all'), 'al abandonar el grupo, el permiso heredado desaparece');

        $db->execute('DELETE FROM roles WHERE id = :id', ['id' => $entryRoleId]);
    }

    public function testForgetCacheReflejaElCambioDeSesion(): void
    {
        self::authAs(self::$editorId);
        assertFalse(Auth::can('forms.delete_own'), 'cambiamos a editor: sin delete_own');

        self::authAs(self::$userId);
        assertTrue(Auth::can('forms.delete_own'), 'vuelve el user: con delete_own');
    }

    public function testUnPermisoDirectoSeSumaSinTocarRolNiGrupo(): void
    {
        assertFalse(Auth::can('forms.manage_all'), 'el rol user no lo trae por defecto');

        User::setPermissions((int) self::$userId, ['forms.manage_all']);
        self::authAs((int) self::$userId); // refresca la caché de permisos del request

        assertTrue(Auth::can('forms.manage_all'), 'el permiso directo se suma');
        assertTrue(Auth::can('forms.create'), 'y los del rol propio siguen');
        assertFalse(Auth::can('admin.access'), 'sin arrastrar nada más');
    }

    /* ---------- Helpers ---------- */

    private static function authAs(int $userId): void
    {
        Auth::assumeLoggedIn($userId);
        Auth::forgetCache();
    }

    private static function ensureCleanupHook(): void
    {
        if (!self::$cleanupHookRegistered) {
            self::$cleanupHookRegistered = true;
            register_shutdown_function(static function () {
                try {
                    self::cleanupResiduals();
                } catch (\Throwable $e) {
                    // Sin conexión no hay residuales que borrar.
                }
            });
        }
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute("DELETE FROM role_groups WHERE name LIKE 'Grupo RBAC-%'");
        $db->execute("DELETE FROM roles WHERE name LIKE 'equipo-rbac-%'");
        $db->execute("DELETE FROM users WHERE email LIKE '%@test.local'");
        self::$userId = null;
        self::$editorId = null;
    }
}