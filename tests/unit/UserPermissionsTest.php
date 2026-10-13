<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Models\Role;
use App\Models\RoleGroup;
use App\Models\User;

/**
 * Permisos DIRECTOS por usuario (RBAC, cuarta fuente) — 2026-10-13.
 *
 * Además del rol propio y de los roles de los grupos, una persona puede recibir
 * permisos SUELTOS con `user_permissions`: la vía para dar UN permiso a UNA
 * persona sin cambiarle el rol (que cambia todo su conjunto) ni meterla en un
 * grupo (que se lo da a todo el equipo).
 *
 * El contrato que se pinza aquí:
 *  - `Auth::permissions()` SUMA los directos a los del rol y los grupos;
 *  - `setPermissions()` reemplaza la lista completa (quitar un permiso no es
 *    «no lo toques»);
 *  - `User::hasPermission()` y `User::reviewers()` —que reproducen la unión en
 *    SQL— también los ven, o la revisión de la instancia ignoraría una exención
 *    concedida a mano;
 *  - el desglose efectivo separa rol / grupos / extra (auditoría).
 *
 * Fixtures `@test.local` y limpieza por prefijo; `user_permissions` cae en
 * cascada con el usuario.
 */
class UserPermissionsTest
{
    private static bool $cleanupHookRegistered = false;
    private static int $userId = 0;

    public function setUp(): void
    {
        self::ensureCleanupHook();

        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::cleanupResiduals();

        // Rol `user` (id 2): tiene forms.create y NO forms.manage_all, así que
        // este último sirve de permiso directo sin ambigüedad.
        self::$userId = (int) User::create(
            'Uperm User ' . bin2hex(random_bytes(3)),
            'uperm-' . bin2hex(random_bytes(4)) . '@test.local',
            'clave-uperm-123',
            2
        );
    }

    public function tearDown(): void
    {
        self::cleanupResiduals();
        Auth::forgetCache();
    }

    public function testUnPermisoDirectoSumaSinTocarRolNiGrupo(): void
    {
        self::authAs(self::$userId);

        assertFalse(Auth::can('forms.manage_all'), 'sin nada, el rol user no ve formularios ajenos');
        assertFalse(Auth::can('admin.access'), 'ni entra al panel');

        User::setPermissions(self::$userId, ['forms.manage_all']);
        self::authAs(self::$userId);

        assertTrue(Auth::can('forms.manage_all'), 'el permiso directo se suma');
        assertFalse(Auth::can('admin.access'), 'pero solo lo concedido: no arrastra el resto de admin');

        // Revocar (lista vacía = sin permisos directos) lo cierra en el siguiente
        // request, igual que el resto de la caché de `Auth`.
        User::setPermissions(self::$userId, []);
        self::authAs(self::$userId);
        assertFalse(Auth::can('forms.manage_all'), 'al quitar el permiso directo desaparece');
    }

    public function testSetPermissionsReemplazaLaListaCompleta(): void
    {
        User::setPermissions(self::$userId, ['forms.manage_all', 'logs.view']);
        assertSame(
            ['forms.manage_all', 'logs.view'],
            self::sorted(User::permissionKeys(self::$userId)),
            'las dos claves quedan guardadas'
        );

        // Solo una: la otra se quita (N:M, la lista entera es la verdad).
        User::setPermissions(self::$userId, ['logs.view']);
        assertSame(
            ['logs.view'],
            User::permissionKeys(self::$userId),
            'una clave que no viene se quita, no se conserva'
        );
    }

    public function testSetPermissionsDescartaClavesFueraDelCatalogo(): void
    {
        User::setPermissions(self::$userId, ['logs.view', 'permiso.inventado', '']);

        $keys = User::permissionKeys(self::$userId);
        assertSame(['logs.view'], $keys, 'solo entra lo que existe en el catálogo');
    }

    public function testHasPermissionYReviewersVenElPermisoDirecto(): void
    {
        assertFalse(
            User::hasPermission(self::$userId, 'forms.manage_all'),
            'antes de concederlo, hasPermission() dice que no'
        );

        User::setPermissions(self::$userId, ['forms.manage_all']);
        assertTrue(
            User::hasPermission(self::$userId, 'forms.manage_all'),
            'hasPermission() tiene que ver la cuarta fuente, o la exención de la revisión la ignoraría'
        );

        // `reviewers()` es el aviso de revisión: un permiso directo de
        // forms.review tiene que contar igual.
        User::setPermissions(self::$userId, ['forms.review']);
        $ids = array_map('intval', array_column(User::reviewers(), 'id'));
        assertTrue(
            in_array(self::$userId, $ids, true),
            'un revisor directo entra en reviewers() (los reales de la instancia no estorban: se busca el propio id)'
        );

        User::setPermissions(self::$userId, []);
        $ids = array_map('intval', array_column(User::reviewers(), 'id'));
        assertFalse(
            in_array(self::$userId, $ids, true),
            'y deja de estar al revocar el permiso'
        );
    }

    public function testElDesgloseEfectivoSeparaElOrigen(): void
    {
        // Un grupo con un rol propio que aporta analytics (permiso que el rol
        // `user` ya tiene; da igual: lo que se comprueba es que «grupos» es su
        // propia lista y «extra» la directa).
        $roleId = Role::create('uperm-rol-' . bin2hex(random_bytes(3)));
        assertNotNull($roleId, 'el rol de fixture se crea');
        Role::setPermissions((int) $roleId, ['analytics.view_own']);

        $groupId = RoleGroup::create('Grupo uperm-' . bin2hex(random_bytes(3)), 'test');
        RoleGroup::setRoles($groupId, [(int) $roleId]);
        RoleGroup::setUsers($groupId, [self::$userId]);

        User::setPermissions(self::$userId, ['forms.manage_all']);

        $map = User::effectivePermissionsMap();
        assertArrayHasKey(self::$userId, $map, 'el usuario está en el desglose');
        $eff = $map[self::$userId];

        assertTrue(
            in_array('forms.create', $eff['rol'], true),
            'el rol propio (user) trae forms.create'
        );
        assertTrue(
            in_array('analytics.view_own', $eff['grupos'], true),
            'los grupos van en su propio cubo'
        );
        assertSame(
            ['forms.manage_all'],
            $eff['extra'],
            '«extra» es exactamente lo directo'
        );

        // `total` es la unión de los tres: lo que evalúa Auth::can().
        $esperado = self::sorted(array_unique(array_merge($eff['rol'], $eff['grupos'], $eff['extra'])));
        assertSame($esperado, self::sorted($eff['total']), 'total = rol ∪ grupos ∪ extra');
        assertTrue(in_array('forms.manage_all', $eff['total'], true), 'y contiene la concesión directa');

        RoleGroup::delete($groupId);
    }

    public function testPermissionsMapTraeLaListaDirecta(): void
    {
        User::setPermissions(self::$userId, ['logs.view']);

        $map = User::permissionsMap();
        assertArrayHasKey(self::$userId, $map, 'el mapa incluye al usuario');
        assertSame(['logs.view'], $map[self::$userId], 'con su lista de permisos directos');
    }

    /* ---------- Helpers ---------- */

    private function authAs(int $userId): void
    {
        Auth::assumeLoggedIn($userId);
        Auth::forgetCache();
    }

    private static function sorted(array $keys): array
    {
        $keys = array_values($keys);
        sort($keys);
        return $keys;
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
        try {
            $db = Database::getInstance(Config::db());
        } catch (\Throwable $e) {
            return;
        }
        // `user_permissions` cae en cascada al borrar el usuario.
        $db->execute("DELETE FROM users WHERE email LIKE '%@test.local'");
        $db->execute("DELETE FROM role_groups WHERE name LIKE 'Grupo uperm-%'");
        $db->execute("DELETE FROM roles WHERE name LIKE 'uperm-%'");
    }
}
