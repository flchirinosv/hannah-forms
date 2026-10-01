<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Models\Role;
use App\Models\RoleGroup;
use App\Models\User;

/**
 * Gestión de roles y grupos desde el panel: lo que el smoke no puede cazar.
 *
 * Los tres caminos que se prueban aquí nacieron de que la pantalla de grupos
 * fuera inusable y de que no existiera el alta de roles:
 *
 * 1. `groupUpdate` exigía un `name` que su propio formulario no mandaba, así
 *    que guardar la pertenencia de un grupo fallaba SIEMPRE. El POST es la
 *    pertenencia completa; el nombre solo se toca si viene.
 * 2. Un rol nuevo nace sin permisos (fail closed) y la matriz lo refleja.
 * 3. La pertenencia de un usuario se edita desde su fila, y como `groups[]` es
 *    una N:M, un POST sin casillas significa "sácalo de todos".
 *
 * Todos los fixtures son `@test.local` / `rbac-rol-*` y se limpian solos.
 */
class AdminRbacTest
{
    private static bool $cleanupHookRegistered = false;
    private static int $adminId = 0;
    private static int $targetId = 0;
    private static int $groupA = 0;
    private static int $groupB = 0;
    private static string $prefix = '';

    public function setUp(): void
    {
        self::ensureCleanupHook();

        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::cleanupResiduals();

        self::$prefix = 'rbac-' . bin2hex(random_bytes(3));
        self::$adminId = (int) User::create(
            'RBAC Admin ' . self::$prefix,
            self::$prefix . '-admin@test.local',
            'clave-rbac-123',
            1
        );
        self::$targetId = (int) User::create(
            'RBAC Target ' . self::$prefix,
            self::$prefix . '-target@test.local',
            'clave-rbac-123',
            2
        );
        self::$groupA = (int) RoleGroup::create('Grupo A ' . self::$prefix, 'primero');
        self::$groupB = (int) RoleGroup::create('Grupo B ' . self::$prefix, 'segundo');
    }

    public function tearDown(): void
    {
        $_POST = [];
    }

    /* ---------------------------------------------------------------
     * 1. Editar un grupo sin mandar el nombre
     * --------------------------------------------------------------- */

    public function testGuardarGrupoSinNombreConservaElNombreYPersisteLaPertenencia(): void
    {
        $editorId = (int) $this->roleWithPermission('rbac-edit', 'forms.create');
        RoleGroup::setRoles(self::$groupA, [$editorId]);
        RoleGroup::setUsers(self::$groupA, [self::$targetId]);

        $this->authAsAdmin();

        // Este es el POST que mandaba el formulario antes de arreglarlo:
        // group_id + roles[] + users[], sin `name`.
        $out = $this->call('groupUpdate', ['group_id' => (string) self::$groupA, 'roles' => [(string) $editorId]]);

        assertTrue($out['exito'] !== '', 'sin `name` el guardado debe funcionar: ' . $out['error']);

        $group = RoleGroup::find(self::$groupA);
        assertSame('Grupo A ' . self::$prefix, (string) $group['name'], 'el nombre no puede cambiar si no viene en el POST');
        assertSame([], self::groupUsers(self::$groupA), 'sin users[] la pertenencia se vacía: el POST es la lista completa');
        assertSame([$editorId], self::groupRoles(self::$groupA), 'el rol marcado tiene que quedar guardado');
    }

    public function testGuardarGrupoConNombreVacioNoDejaElGrupoSinNombre(): void
    {
        $this->authAsAdmin();

        $out = $this->call('groupUpdate', [
            'group_id' => (string) self::$groupA,
            'name'     => '   ',
            'roles'    => [],
        ]);

        assertTrue($out['error'] !== '', 'un nombre presente pero vacío es un error, no un "no lo toques"');
        assertTrue(str_contains($out['error'], 'nombre'), 'el error debe nombrar el campo: ' . $out['error']);

        $group = RoleGroup::find(self::$groupA);
        assertSame('Grupo A ' . self::$prefix, (string) $group['name'], 'el nombre anterior tiene que sobrevivir al error');
    }

    public function testGuardarGrupoRenombraYActualizaLaDescripcion(): void
    {
        $this->authAsAdmin();

        $out = $this->call('groupUpdate', [
            'group_id'     => (string) self::$groupB,
            'name'         => 'Renombrado ' . self::$prefix,
            'description'  => 'descripción nueva',
            'groups'       => [],
        ]);

        assertTrue($out['exito'] !== '', 'renombrar debe funcionar: ' . $out['error']);

        $group = RoleGroup::find(self::$groupB);
        assertSame('Renombrado ' . self::$prefix, (string) $group['name']);
        assertSame('descripción nueva', (string) $group['description']);
    }

    /* ---------------------------------------------------------------
     * 2. Alta de roles
     * --------------------------------------------------------------- */

    public function testCrearRolLoDejaSinPermisosYApareceEnLaMatriz(): void
    {
        $this->authAsAdmin();
        $name = 'rbac-nuevo-' . bin2hex(random_bytes(3));

        $out = $this->call('roleCreate', ['name' => $name, 'description' => 'sin permisos todavía']);

        assertTrue($out['exito'] !== '', 'el alta debe funcionar: ' . $out['error']);

        $role = Role::findByName($name);
        assertNotNull($role, 'el rol debe existir con el nombre en minúsculas');

        // Fail closed: nace sin permisos, y la matriz lo dice en vez de fingir
        // que tiene los del rol admin.
        assertSame([], Role::permissionKeys((int) $role['id']), 'un rol nuevo no puede nacer con permisos');

        $matriz = array_column(Role::allWithPermissions(), 'name');
        assertTrue(in_array($name, $matriz, true), 'el rol nuevo tiene que aparecer como columna de la matriz');
    }

    public function testCrearRolNormalizaAMinusculas(): void
    {
        $this->authAsAdmin();
        $sufijo = bin2hex(random_bytes(3));

        $this->call('roleCreate', ['name' => 'Rbac-Mixto-' . $sufijo]);

        // OJO: `roles.name` es `utf8mb4_unicode_ci`, o sea el WHERE no
        // distingue mayúsculas. La aserción va sobre el VALOR guardado, que es
        // lo único que lee el resto de la app, no sobre si el WHERE lo encuentra.
        $row = Role::findByName('Rbac-Mixto-' . $sufijo);
        assertNotNull($row, 'el rol debe existir');
        assertSame('rbac-mixto-' . $sufijo, (string) $row['name'], 'el nombre se guarda en minúsculas');

        $mayusculas = Database::getInstance(Config::db())->fetchAll(
            'SELECT name FROM roles WHERE BINARY name <> BINARY LOWER(name)'
        );
        assertSame([], $mayusculas, 'no puede quedar ningún rol con mayúsculas en el nombre');
    }

    public function testCrearRolRechazaDuplicadosYNombresInvalidos(): void
    {
        $this->authAsAdmin();
        $db = Database::getInstance(Config::db());

        // Duplicado de uno que ya existe (el seed). La comparación es
        // case-insensitive por collation, así que «ADMIN» colisiona con «admin»
        // sin que haga falta normalizar para que el duplicado se detecte.
        $dup = $this->call('roleCreate', ['name' => 'ADMIN']);
        assertTrue($dup['error'] !== '', 'admin ya existe: no se puede crear un segundo admin');
        assertSame('admin', (string) (Role::findByName('ADMIN')['name'] ?? ''), 'el admin original no se toca');
        assertSame(1, (int) (Role::findByName('ADMIN')['id'] ?? 0), 'sigue siendo el mismo rol');

        // Con espacios, con caracteres fuera de la lista, vacío y demasiado
        // largo. Cada intento deja la tabla exactamente como estaba.
        $antes = (int) ($db->fetch('SELECT COUNT(*) AS n FROM roles')['n'] ?? 0);
        foreach (['con espacio', 'con/barra', 'acentuado-é', '', str_repeat('a', 51)] as $bad) {
            $out = $this->call('roleCreate', ['name' => $bad]);
            assertTrue($out['error'] !== '', 'nombre inválido rechazado: "' . $bad . '"');
            assertSame($antes, (int) ($db->fetch('SELECT COUNT(*) AS n FROM roles')['n'] ?? 0), 'no se crea nada con "' . $bad . '"');
        }
    }

    public function testCrearRolExigeElPermisoDeMatriz(): void
    {
        $this->authAs((int) $this->roleWithPermission('rbac-solo-lectura', 'forms.create'));
        $name = 'rbac-noautorizado-' . bin2hex(random_bytes(3));

        $out = $this->call('roleCreate', ['name' => $name]);

        assertSame(403, $out['halt']['code'] ?? 0, 'sin `admin.roles` la respuesta es 403, no un flash');
        assertNull(Role::findByName($name), 'y el rol no se crea');
    }

    /* ---------------------------------------------------------------
     * 3. Grupos de un usuario, editados desde su fila
     * --------------------------------------------------------------- */

    public function testGuardarGruposDeUnUsuarioReemplazaLaPertenenciaCompleta(): void
    {
        $this->authAsAdmin();
        $gid = (int) $this->roleWithPermission('rbac-otro', 'forms.create');
        RoleGroup::setUsers(self::$groupA, [self::$targetId]);
        RoleGroup::setUsers(self::$groupB, [self::$targetId]);
        assertSame([self::$groupA, self::$groupB], self::userGroups(self::$targetId));

        // Solo uno marcado: sale del otro.
        $out = $this->call('updateUserGroups', [
            'user_id' => (string) self::$targetId,
            'groups'  => [(string) self::$groupB],
        ]);
        assertTrue($out['exito'] !== '', 'el guardado debe funcionar: ' . $out['error']);
        assertSame([self::$groupB], self::userGroups(self::$targetId));

        // Ninguno marcado: se queda sin grupos (POST vacío = lista completa).
        $out = $this->call('updateUserGroups', ['user_id' => (string) self::$targetId]);
        assertTrue($out['exito'] !== '', 'quitar el último grupo también es un guardado: ' . $out['error']);
        assertSame([], self::userGroups(self::$targetId), 'un POST sin groups[] deja al usuario fuera de todos');
    }

    public function testGuardarGruposDeUnUsuarioRechazaUnGrupoInexistente(): void
    {
        $this->authAsAdmin();
        RoleGroup::setUsers(self::$groupA, [self::$targetId]);

        $out = $this->call('updateUserGroups', [
            'user_id' => (string) self::$targetId,
            'groups'  => ['99999999'],
        ]);

        assertTrue($out['error'] !== '', 'un grupo que no existe no se puede colar en la N:M');
        assertSame([self::$groupA], self::userGroups(self::$targetId), 'la pertenencia anterior se queda intacta');
    }

    public function testGuardarGruposExigeElPermisoDeGrupos(): void
    {
        // admin.users pero no admin.groups: se puede cambiar el rol, no meter a
        // alguien en grupos por la vía de la fila.
        $this->authAs((int) $this->roleWithPermission('rbac-users', 'admin.users'));
        RoleGroup::setUsers(self::$groupA, [self::$targetId]);

        $out = $this->call('updateUserGroups', [
            'user_id' => (string) self::$targetId,
            'groups'  => [(string) self::$groupB],
        ]);

        assertSame(403, $out['halt']['code'] ?? 0, 'sin `admin.groups` la respuesta es 403');
        assertSame([self::$groupA], self::userGroups(self::$targetId), 'y la pertenencia no se toca');
    }

    /* ---------------------------------------------------------------
     * 4. Baja de roles (bloque 5): lo propio y vacío, nunca lo del sistema
     * --------------------------------------------------------------- */

    public function testSeBorraUnRolPropioQueNoLlevaANadie(): void
    {
        $rolId = $this->roleWithPermission('rbac-humo-' . self::$prefix, 'forms.create');
        assertNotNull(Role::find($rolId), 'el rol existe antes de borrarlo');

        $motivo = null;
        assertTrue(Role::delete($rolId, $motivo), 'un rol propio sin personas sí se borra: ' . (string) $motivo);
        assertNull(Role::find($rolId), 'y deja de existir');
    }

    public function testNoSeBorraUnRolQueTienePersonas(): void
    {
        $rolId = $this->roleWithPermission('rbac-congente-' . self::$prefix, 'forms.create');
        self::setUserRole(self::$targetId, $rolId);

        $motivo = null;
        assertFalse(Role::delete($rolId, $motivo), 'un rol con personas no se puede borrar');
        assertTrue(
            str_contains((string) $motivo, 'persona'),
            'el motivo dice cuántas personas lo llevan: ' . (string) $motivo
        );
        assertNotNull(Role::find($rolId), 'y el rol sigue ahí');
    }

    public function testNoSeBorraNingunRolDelSistemaAunqueEstenVacio(): void
    {
        foreach (Role::SYSTEM_ROLES as $nombre) {
            assertTrue(Role::isSystem($nombre), $nombre . ' es un rol del sistema');
        }

        $admin = Role::findByName('admin');
        assertNotNull($admin, 'admin existe');
        $motivo = null;
        assertFalse(
            Role::delete((int) $admin['id'], $motivo),
            'admin no se borra aunque la tabla `users` no tenga a nadie con ese rol'
        );
        assertTrue(
            str_contains((string) $motivo, 'sistema'),
            'y el motivo lo dice: ' . (string) $motivo
        );
        assertNotNull(Role::findByName('admin'), 'admin sigue existiendo');
    }

    public function testElBorradoSeLlevaElRolDeLosGruposYSusPermisos(): void
    {
        $rolId = $this->roleWithPermission('rbac-grupo-' . self::$prefix, 'forms.create');
        RoleGroup::setRoles(self::$groupA, [$rolId]);
        assertSame([$rolId], self::groupRoles(self::$groupA), 'el grupo confiere el rol');

        $motivo = null;
        assertTrue(Role::delete($rolId, $motivo), 'se borra: ' . (string) $motivo);
        assertSame([], self::groupRoles(self::$groupA), 'el grupo deja de conferir un rol que ya no existe');
        assertSame([], Role::permissionKeys($rolId), 'y sus permisos se van con él');
    }

    public function testElPanelBorraElRolYLoAnotaEnElLog(): void
    {
        $rolId = $this->roleWithPermission('rbac-log-' . self::$prefix, 'forms.create');
        $this->authAsAdmin();

        $out = $this->call('roleDelete', ['role_id' => (string) $rolId]);

        // Un `Response::redirect()` deja `type => 'redirect'`, no `code`: el
        // código 302 lo pone la cabecera, que en el runner no se emite.
        assertSame('redirect', $out['halt']['type'] ?? '', 'borrar un rol redirige a la matriz');
        assertTrue($out['exito'] !== '', 'y avisa: ' . (string) $out['error']);
        assertNull(Role::find($rolId), 'el rol ya no está');

        // El log importa porque un rol lo heredan también los grupos: quitar
        // `soporte-tecnico` deja a sus miembros sin nada, y eso no se ve en la
        // fila que se acaba de borrar.
        $eventos = array_column(SecurityLog::recent('admin.role_delete', 1, 200)['items'], 'detail');
        assertTrue(
            (bool) array_filter($eventos, static fn(string $d): bool => str_contains($d, 'rbac-log-' . self::$prefix)),
            'el borrado queda anotado en el log de seguridad'
        );
    }

    public function testElPanelNoBorraUnRolDelSistemaYExplicaPorQue(): void
    {
        $approver = Role::findByName('approver');
        assertNotNull($approver, 'la migración 0011 dejó el rol approver');
        $this->authAsAdmin();

        $out = $this->call('roleDelete', ['role_id' => (string) $approver['id']]);

        assertSame('redirect', $out['halt']['type'] ?? '', 'intenta y redirige a la matriz');
        assertTrue($out['exito'] === '', 'no dice que se borró');
        assertTrue(
            str_contains((string) $out['error'], 'sistema'),
            'el motivo es que es del sistema: ' . (string) $out['error']
        );
        assertNotNull(Role::findByName('approver'), 'approver sigue en pie');
    }

    public function testBorrarUnRolSinAdminRolesDa403(): void
    {
        $rolId = $this->roleWithPermission('rbac-sinpermiso-' . self::$prefix, 'forms.create');
        $this->authAs((int) $this->roleWithPermission('rbac-otro-' . self::$prefix, 'admin.users'));

        $out = $this->call('roleDelete', ['role_id' => (string) $rolId]);

        assertSame(403, $out['halt']['code'] ?? 0, 'sin `admin.roles` no se borra nada');
        assertNotNull(Role::find($rolId), 'el rol sigue ahí');
    }

    /**
     * El caso que motivó el gate de la navegación: un rol que solo puede
     * aprobar. Con `forms.review` y nada más, el enlace «Nuevo formulario» le
     * llevaba a un 403 con toda la pinta de un botón que funciona.
     *
     * Se comprueba la NAVEGACIÓN RENDERIZADA, no el catálogo: que el rol tenga
     * los permisos correctos no dice nada de lo que se le enseña.
     */
    public function testElApproverEntraAlPanelYNoLeOfrecemosCrearFormularios(): void
    {
        $approver = Role::findByName('approver');
        assertNotNull($approver, 'la migración 0011 dejó el rol approver');
        $userId = (int) User::create(
            'Approver ' . self::$prefix,
            self::$prefix . '-approver@test.local',
            'clave-approver-123',
            (int) $approver['id']
        );
        $this->authAs($userId);

        assertTrue(Auth::can('forms.review'), 'puede aprobar');
        assertTrue(Auth::can('dashboard.view'), 'y puede entrar al panel');
        assertFalse(Auth::can('forms.create'), 'pero no puede crear formularios');

        $nav = $this->renderNav();
        assertTrue(str_contains($nav, 'href="/review"'), 'la navegación le enseña la bandeja de revisión');
        assertTrue(str_contains($nav, 'href="/dashboard"'), 'y el dashboard, que es de donde sale');
        assertFalse(
            str_contains($nav, 'app-btn-create'),
            'pero NO el botón «Nuevo formulario»: sin `forms.create` ese botón solo produce 403'
        );
        assertFalse(str_contains($nav, 'href="/admin"'), 'ni Administración: no tiene `admin.access`');
    }

    /**
     * El botón de borrar es la mitad de la puerta: el modelo puede permitir una
     * baja que la pantalla no ofrece (rol propio con gente, por ejemplo), y eso
     * se lee como «el panel va roto». Se comprueba el HTML Renderizado, no el
     * fuente, porque lo que importa es lo que hay en pantalla.
     */
    public function testElBotonDeBorrarSoloApareceDondeSePuedeBorrar(): void
    {
        $propio = $this->roleWithPermission('rbac-boton-' . self::$prefix, 'forms.create');
        $this->authAsAdmin();

        $html = $this->renderRoles();

        preg_match_all('/action="\/admin\/roles\/delete".*?role_id" value="(\d+)"/s', $html, $m);
        $pintados = array_map('intval', $m[1]);

        $esperados = [];
        foreach (Role::allWithPermissions() as $rol) {
            $id = (int) $rol['id'];
            if (!Role::isSystem((string) $rol['name']) && Role::userCount($id) === 0) {
                $esperados[] = $id;
            }
        }
        sort($pintados);
        sort($esperados);

        assertSame($esperados, $pintados, 'el botón aparece exactamente en los roles propios y sin personas');
        assertTrue(
            in_array($propio, $pintados, true),
            'el rol de este test se puede borrar y por eso tiene botón (si no, la comparación de arriba no probaría nada)'
        );
    }

    /**
     * Un rol del sistema que falta en la base es un fallo que no se ve: la
     * matriz sale igual, la revisión deja de funcionar y nadie sabe por qué.
     */
    public function testElRolApproverExisteConSoloSusDosPermisos(): void
    {
        $approver = Role::findByName('approver');
        assertNotNull($approver, 'la migración 0011 debe crear el rol approver (¿aplicaste `php database/migrate.php`?)');

        $keys = Role::permissionKeys((int) $approver['id']);
        sort($keys);
        assertSame(
            ['dashboard.view', 'forms.review'],
            $keys,
            'approver aprueba y entra al panel; ni una clave más (nada de forms.edit_own: no es dueño de nada)'
        );
    }

    /* ---------------------------------------------------------------
     * Utilidades
     * --------------------------------------------------------------- */

    private function authAsAdmin(): void
    {
        $this->authAs((int) self::$adminId);
    }

    private function authAs(int $userId): void
    {
        Auth::assumeLoggedIn($userId);
        Auth::forgetCache();
    }

    /**
     * Crea un rol con exactamente un permiso, lo usa de fixture para probar
     * los gates, y devuelve su id. El permiso es real (del catálogo) para no
     * inventar filas en `permissions`.
     */
    private function roleWithPermission(string $name, string $permissionKey): int
    {
        $id = Role::create($name);
        if ($id === null) {
            // El nombre es único y el runner instancia la clase por test: si
            // quedara uno de una pasada anterior, se reutiliza.
            $existing = Role::findByName($name);
            $id = $existing === null ? 0 : (int) $existing['id'];
        }
        $permId = (int) (Database::getInstance(Config::db())->fetch(
            'SELECT id FROM permissions WHERE `key` = :key',
            ['key' => $permissionKey]
        )['id'] ?? 0);
        if ($permId > 0) {
            Database::getInstance(Config::db())->execute(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:r, :p)',
                ['r' => $id, 'p' => $permId]
            );
        }
        return $id;
    }

    /** GET de la matriz de roles: devuelve el HTML pintado. */
    private function renderRoles(): string
    {
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/roles';
        ob_start();
        try {
            (new AdminController())->roles();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    /** El layout principal con el contenido vacío: solo la barra de navegación. */
    private function renderNav(): string
    {
        $_SERVER['REQUEST_URI'] = '/dashboard';
        $content = '';
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/views/layouts/main.php';
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    /** Mueve a una persona a un rol (para probar que un rol con gente no se borra). */
    private static function setUserRole(int $userId, int $roleId): void
    {
        Database::getInstance(Config::db())->execute(
            'UPDATE users SET role_id = :r WHERE id = :u',
            ['r' => $roleId, 'u' => $userId]
        );
    }

    /** POST de navegador (con CSRF) contra una acción de AdminController. */
    private function call(string $action, array $post): array
    {
        Session::set('_csrf_token', Csrf::token());
        $_POST = ['_token' => (string) Session::get('_csrf_token')] + $post;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Request::setRequestBodyForTests(null);

        Response::setRecordHalt(true);
        ob_start();
        try {
            (new AdminController())->{$action}();
        } finally {
            ob_get_clean();
        }
        $halt = Response::lastHalt();
        Response::setRecordHalt(false);

        return [
            'halt'  => $halt,
            'exito' => Session::getFlash('success'),
            'error' => Session::getFlash('error'),
        ];
    }

    private static function groupUsers(int $groupId): array
    {
        return array_map('intval', array_column(
            Database::getInstance(Config::db())->fetchAll(
                'SELECT user_id FROM role_group_users WHERE group_id = :g ORDER BY user_id',
                ['g' => $groupId]
            ),
            'user_id'
        ));
    }

    private static function groupRoles(int $groupId): array
    {
        return array_map('intval', array_column(
            Database::getInstance(Config::db())->fetchAll(
                'SELECT role_id FROM role_group_roles WHERE group_id = :g ORDER BY role_id',
                ['g' => $groupId]
            ),
            'role_id'
        ));
    }

    private static function userGroups(int $userId): array
    {
        return array_map('intval', array_column(
            Database::getInstance(Config::db())->fetchAll(
                'SELECT group_id FROM role_group_users WHERE user_id = :u ORDER BY group_id',
                ['u' => $userId]
            ),
            'group_id'
        ));
    }

    /** Registra el hook una sola vez para toda la corrida (16 clases lo hacen). */
    private static function ensureCleanupHook(): void
    {
        if (self::$cleanupHookRegistered) {
            return;
        }
        self::$cleanupHookRegistered = true;
        register_shutdown_function([self::class, 'cleanupResiduals']);
    }

    public static function cleanupResiduals(): void
    {
        try {
            $db = Database::getInstance(Config::db());
        } catch (\Throwable $e) {
            return;
        }
        // Primero los usuarios: `users.role_id` es ON DELETE RESTRICT y un rol
        // de fixture no se puede borrar mientras alguien lo lleve.
        $db->execute("DELETE FROM users WHERE email LIKE '%@test.local'");
        // `role_groups` se limpia por el prefijo `rbac-`, NO por el nombre
        // original ('Grupo A'/'Grupo B'): uno de estos tests renombra el grupo,
        // y una limpieza que mirase el nombre viejo se lo escapaba y dejaba uno
        // más en cada corrida.
        $db->execute("DELETE FROM role_groups WHERE name LIKE '%rbac-%'");
        $db->execute("DELETE FROM roles WHERE name LIKE 'rbac-%' OR name LIKE 'humo-rol-%'");
    }
}