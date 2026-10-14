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
        $_GET = [];
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

    public function testGuardarGrupoSinRecargaDevuelveLaCabeceraResumen(): void
    {
        $editorId = (int) $this->roleWithPermission('rbac-sum', 'forms.create');
        $this->authAsAdmin();

        $out = $this->callJson('groupUpdate', [
            'group_id' => (string) self::$groupA,
            'name'     => 'Grupo A ' . self::$prefix,
            'roles'    => [(string) $editorId],
            'users'    => [(string) self::$targetId],
        ]);

        assertTrue(($out['ok'] ?? false) === true, 'guardar sin recarga responde ok: ' . json_encode($out));

        $slot = 'vfGroupSummary' . self::$groupA;
        assertArrayHasKey($slot, $out['html'] ?? [], 'el hueco repintado es la cabecera-resumen');

        $html = (string) $out['html'][$slot];
        assertTrue(str_contains($html, '1 rol'), 'la insignia cuenta el rol: ' . $html);
        assertTrue(str_contains($html, '1 miembro'), 'la insignia cuenta el miembro: ' . $html);
        assertTrue(str_contains($html, 'app-rbac-effect'), 'se repinta el efecto, con el mismo bloque que pinta el GET');
        assertTrue(str_contains($html, 'forms'), 'el efecto nombra el prefijo del permiso que otorga el rol');
    }

    public function testBorrarGrupoSinRecargaCuentaLosQueQuedan(): void
    {
        $this->authAsAdmin();

        $out = $this->callJson('groupDelete', ['group_id' => (string) self::$groupA]);

        assertTrue(($out['ok'] ?? false) === true, 'borrar sin recarga responde ok: ' . json_encode($out));
        assertTrue(RoleGroup::find(self::$groupA) === null, 'la fila tiene que dejar de existir');

        // El conteo es del SERVIDOR porque la lista es global: el cliente no
        // sabe cuántos grupos hay en la instalación, y es con ese número (cero)
        // con el que el JS decide recargar cuando se borra el último.
        assertSame(
            count(RoleGroup::all()),
            (int) ($out['count'] ?? -1),
            'el count es el de los grupos que quedan, no el de la pantalla'
        );
        assertTrue((int) $out['count'] >= 1, 'groupB sigue ahí: con la lista no vacía no toca recargar');
    }

    public function testLaListaDeMiembrosTieneBuscadorYNotaDeVacio(): void
    {
        $this->authAsAdmin();

        $html = $this->renderGroups();

        assertTrue(str_contains($html, 'data-vf-filter'), 'el buscador de miembros va marcado para el filtro de cliente');
        assertTrue(str_contains($html, 'data-vf-list'), 'y su ancestro declara la lista que filtra');
        assertTrue(str_contains($html, 'app-filter-empty'), 'hay una nota para cuando la búsqueda no casa con nadie');
        assertTrue(
            str_contains($html, 'id="vfGroupSummary' . self::$groupA . '"'),
            'la cabecera-resumen es el hueco que el POST repinta sin recargar'
        );
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
     * 2b. Matriz de permisos: guardado sin recarga
     * --------------------------------------------------------------- */

    /**
     * Guardar la matriz por fetch devuelve un JSON con su aviso, y deja los
     * permisos exactamente como los manda el navegador: la matriz COMPLETA
     * (todos los roles), que es lo que envía el formulario. Un POST parcial
     * —solo el rol tocado— no es lo que manda el navegador y el controlador lo
     * interpretaría como «el resto sin permisos».
     */
    public function testLaMatrizSeGuardaSinRecarga(): void
    {
        $this->authAsAdmin();
        $rolId = $this->roleWithPermission('rbac-matriz-' . self::$prefix, 'forms.create');

        // Se envía la foto completa más el cambio del rol de este test.
        $perms = [];
        $antes = [];
        foreach (Role::all() as $rol) {
            $rid = (int) $rol['id'];
            $perms[(string) $rid] = Role::permissionKeys($rid);
            $antes[$rid] = self::sorted(Role::permissionKeys($rid));
        }
        $perms[(string) $rolId] = ['logs.view'];

        $out = $this->callJson('updateRoleMatrix', ['perms' => $perms]);

        assertTrue(($out['ok'] ?? false) === true, 'la respuesta JSON tiene que ser ok:true');
        assertTrue(
            str_contains((string) ($out['message'] ?? ''), 'Matriz'),
            'y traer el aviso del guardado: ' . json_encode($out)
        );
        assertSame(['logs.view'], self::sorted(Role::permissionKeys($rolId)), 'el rol tocado queda con lo marcado');

        // El resto de roles se queda como estaba: el POST venía completo.
        foreach (Role::all() as $rol) {
            $rid = (int) $rol['id'];
            if ($rid === $rolId) {
                continue;
            }
            assertSame(
                $antes[$rid],
                self::sorted(Role::permissionKeys($rid)),
                'un rol que no se tocó no cambia de permisos'
            );
        }
    }

    /** Una matriz vacía por JSON no borra nada: no hay nada que guardar. */
    public function testLaMatrizVaciaPorJsonNoBorraNada(): void
    {
        $this->authAsAdmin();
        $rolId = $this->roleWithPermission('rbac-vacia-' . self::$prefix, 'forms.create');

        $out = $this->callJson('updateRoleMatrix', []);

        assertTrue(($out['ok'] ?? true) === false, 'sin `perms` la respuesta tiene que ser un «no»');
        assertSame(['forms.create'], Role::permissionKeys($rolId), 'y los permisos no se tocan');
    }

    /** El guardado de la matriz por JSON exige `admin.roles` como el nativo. */
    public function testLaMatrizPorJsonExigeElPermiso(): void
    {
        $this->authAs((int) $this->roleWithPermission('rbac-sin-matriz-' . self::$prefix, 'forms.create'));

        $out = $this->callJson('updateRoleMatrix', ['perms' => ['1' => ['admin.access']]]);

        assertTrue(($out['ok'] ?? true) === false, 'sin `admin.roles` no hay ok:true');
        assertTrue(
            str_contains((string) ($out['error'] ?? ''), 'permiso'),
            'y el motivo lo dice: ' . json_encode($out)
        );
    }

    /**
     * La vista de la matriz trae los ganchos del rediseño: el guardado sin
     * recarga, el contenedor con scroll propio (sin él la cabecera no se pega)
     * y el `data-vf-count` / `data-vf-role-id` que el JS usa para recalcular el
     * «n/total» en vivo.
     */
    public function testLaVistaDeLaMatrizTraeContadoresYGuardadoSinRecarga(): void
    {
        $this->authAsAdmin();
        $html = $this->renderRoles();

        assertTrue(str_contains($html, 'data-vf-async="slots"'), 'guardar la matriz no debe recargar');
        assertTrue(str_contains($html, 'app-rbac-matrix-scroll'), 'falta el contenedor con scroll propio de la cabecera pegajosa');
        assertTrue(str_contains($html, 'data-vf-count="'), 'cada columna necesita su contador con data-vf-count');
        assertTrue(str_contains($html, 'data-vf-role-id="'), 'y cada casilla su data-vf-role-id para agrupar el contador');

        $roles = Role::all();
        assertSame(
            count($roles),
            substr_count($html, 'data-vf-count="'),
            'tiene que haber un contador por rol'
        );
    }

    /**
     * F6 — La vista del log filtra por evento (se envía solo) y por búsqueda de
     * detalle/IP/usuario, con su vacío propio.
     */
    public function testLaVistaDelLogFiltraYPaginaConLaUrl(): void
    {
        $this->authAsAdmin();
        $marca = 'rbac-log-' . self::$prefix;
        SecurityLog::record('admin.role_change', $marca);

        $html = $this->renderSecurityLog(['q' => $marca]);
        assertTrue(str_contains($html, 'name="q"'), 'la búsqueda viaja en el GET');
        assertTrue(str_contains($html, 'js-auto-submit'), 'el filtro de evento se envía solo al cambiar');
        assertTrue(str_contains($html, $marca), 'la fila que casa con la búsqueda sale');

        $vacio = $this->renderSecurityLog(['q' => 'zzz-no-existe-' . self::$prefix]);
        assertTrue(str_contains($vacio, 'Sin resultados'), 'un filtro sin resultados tiene su vacío');
        assertTrue(str_contains($vacio, 'Quitar los filtros'), 'con su enlace de salida');

        Database::getInstance(Config::db())->execute(
            'DELETE FROM security_log WHERE detail = :d',
            ['d' => $marca]
        );
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
     * 3b. Permisos extra de un usuario, editados desde su fila
     * --------------------------------------------------------------- */

    public function testGuardarPermisosExtraReemplazaLaListaCompleta(): void
    {
        $this->authAsAdmin();

        $out = $this->call('updateUserPermissions', [
            'user_id'     => (string) self::$targetId,
            'permissions' => ['forms.manage_all', 'logs.view'],
        ]);
        assertTrue($out['exito'] !== '', 'el guardado debe funcionar: ' . $out['error']);
        assertSame(
            ['forms.manage_all', 'logs.view'],
            self::sorted(User::permissionKeys(self::$targetId)),
            'las dos claves quedan guardadas'
        );

        // Solo una: la otra se quita (el POST es la lista completa).
        $out = $this->call('updateUserPermissions', [
            'user_id'     => (string) self::$targetId,
            'permissions' => ['logs.view'],
        ]);
        assertTrue($out['exito'] !== '', $out['error']);
        assertSame(['logs.view'], User::permissionKeys(self::$targetId), 'una clave ausente se quita');

        // Sin casillas: se queda sin permisos extra (POST vacío = lista vacía).
        $out = $this->call('updateUserPermissions', ['user_id' => (string) self::$targetId]);
        assertTrue($out['exito'] !== '', 'quitar el último permiso también es un guardado: ' . $out['error']);
        assertSame([], User::permissionKeys(self::$targetId));
    }

    public function testGuardarPermisosExtraDescartaClavesFueraDelCatalogo(): void
    {
        $this->authAsAdmin();

        // Una clave inventada se ignora sin romper el guardado: el catálogo no
        // se borra, así que no hay un «ya no existe» que rechazar (a diferencia
        // de un grupo).
        $out = $this->call('updateUserPermissions', [
            'user_id'     => (string) self::$targetId,
            'permissions' => ['logs.view', 'permiso.inventado'],
        ]);

        assertTrue($out['exito'] !== '', $out['error']);
        assertSame(['logs.view'], User::permissionKeys(self::$targetId), 'solo entra lo que existe en el catálogo');
    }

    public function testGuardarPermisosExtraExigeElPermisoDeUsuarios(): void
    {
        // Un rol con forms.create pero sin admin.users: no concede permisos.
        $this->authAs((int) $this->roleWithPermission('rbac-sin-users', 'forms.create'));

        $out = $this->call('updateUserPermissions', [
            'user_id'     => (string) self::$targetId,
            'permissions' => ['logs.view'],
        ]);

        assertSame(403, $out['halt']['code'] ?? 0, 'sin `admin.users` la respuesta es 403');
        assertSame([], User::permissionKeys(self::$targetId), 'y no se concede nada');
    }

    public function testGuardarPermisosExtraLoAnotaEnElLog(): void
    {
        $this->authAsAdmin();

        $this->call('updateUserPermissions', [
            'user_id'     => (string) self::$targetId,
            'permissions' => ['logs.view'],
        ]);

        $eventos = array_column(SecurityLog::recent('admin.user_permissions', 1, 200)['items'], 'detail');
        assertTrue(
            (bool) array_filter(
                $eventos,
                static fn(string $d): bool => str_contains($d, 'usuario #' . self::$targetId)
            ),
            'la concesión directa queda anotada en el log de seguridad'
        );
    }

    public function testSePintaLaColumnaDePermisosExtraConSuHueco(): void
    {
        User::setPermissions(self::$targetId, ['logs.view']);
        $this->authAsAdmin();

        $html = $this->renderUsers();

        assertTrue(
            str_contains($html, 'action="/admin/users/permissions"'),
            'el formulario de permisos extra tiene que estar en la fila'
        );
        assertTrue(
            str_contains($html, 'vfUserPerms' . self::$targetId),
            'con su hueco para el repintado por fetch'
        );
        assertTrue(
            str_contains($html, 'permsError' . self::$targetId),
            'y su nodo de error dentro del desplegable'
        );
        assertTrue(
            str_contains($html, 'logs.view'),
            'la insignia del permiso concedido se pinta'
        );
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
     * Fase 2 — El buscador y los filtros del listado de usuarios
     * --------------------------------------------------------------- */

    /**
     * El filtro va en la CONSULTA, no en la página: con el `LIMIT` de 25 ya
     * aplicado, un filtro de cliente solo ocultaría las filas ya pintadas y la
     * búsqueda de la persona 26 diría «sin resultados» con la fila ahí mismo.
     */
    public function testElBuscadorDeUsuariosFiltraEnSqlYNoSoloEnLaPagina(): void
    {
        $this->authAsAdmin();

        // La tabla marca sus filas con `data-vf-row`: el email del admin que
        // está mirando la página sale en la barra superior, así que «no aparece
        // su email» no probaría nada; el número de FILAS sí.
        $html = $this->renderUsers(['q' => self::$prefix . '-target']);
        assertTrue(
            str_contains($html, self::$prefix . '-target@test.local'),
            'la búsqueda por email encuentra a la persona'
        );
        assertSame(
            1,
            substr_count($html, 'data-vf-row'),
            'y deja UNA sola fila: el filtro va en la consulta, no esconde el resto en cliente'
        );

        $vacio = $this->renderUsers(['q' => 'zzz-nadie-' . self::$prefix]);
        assertTrue(str_contains($vacio, 'Sin resultados'), 'una búsqueda sin coincidencias lo dice');
        assertSame(0, substr_count($vacio, 'data-vf-row'), 'y no hay ninguna fila que enseñar');
        assertFalse(
            str_contains($vacio, 'Todavía no hay usuarios'),
            'y no confunde «sin resultados» con una instalación vacía'
        );
    }

    /** El estado se resuelve en SQL: activos/inactivos de verdad, no en cliente. */
    public function testElFiltroDeEstadoDeUsuariosViajaEnLaUrl(): void
    {
        User::setActive(self::$targetId, false);
        $this->authAsAdmin();

        $inactivos = $this->renderUsers(['q' => self::$prefix . '-target', 'estado' => 'inactivos']);
        assertTrue(
            str_contains($inactivos, self::$prefix . '-target@test.local'),
            'el inactivo sale al filtrar por inactivos'
        );

        $activos = $this->renderUsers(['q' => self::$prefix . '-target', 'estado' => 'activos']);
        assertFalse(
            str_contains($activos, self::$prefix . '-target@test.local'),
            'y NO sale al filtrar por activos'
        );
    }

    /** El rol también se filtra en SQL, no se descarta sobre lo ya pintado. */
    public function testElFiltroDeRolDeUsuariosEsElDeLaUrl(): void
    {
        $this->authAsAdmin();

        $comoAdmin = $this->renderUsers(['q' => self::$prefix, 'rol' => '1']);
        assertTrue(str_contains($comoAdmin, self::$prefix . '-admin@test.local'), 'el admin (rol 1) entra');
        assertFalse(str_contains($comoAdmin, self::$prefix . '-target@test.local'), 'y el user (rol 2) queda fuera');
    }

    /**
     * `User::page()` es el contrato del listado: sin él, «filtrar en SQL» sería
     * una promesa. Cuenta el TOTAL filtrado (no el de la página) y numera las
     * páginas sobre ese total.
     */
    public function testUserPageCuentaElTotalFiltradoYRecortaLasPaginas(): void
    {
        User::create('RBAC Extra ' . self::$prefix, self::$prefix . '-extra@test.local', 'clave-rbac-123', 2);

        $p1 = User::page(['q' => self::$prefix], 1, 2);
        assertSame(3, $p1['total'], 'tres usuarios de este test coinciden con el prefijo');
        assertSame(2, count($p1['items']), 'la página trae dos');
        assertSame(2, $p1['pages'], 'y salen dos páginas');

        $p2 = User::page(['q' => self::$prefix], 2, 2);
        assertSame(1, count($p2['items']), 'la segunda página trae el que falta');
        assertSame(2, $p2['page']);

        $fuera = User::page(['q' => self::$prefix], 99, 2);
        assertSame(2, $fuera['page'], 'una página por encima del final se recorta a la última');
    }

    /* ---------------------------------------------------------------
     * Fase 3 — La fila de usuarios: una puerta de acciones y tarjetas móviles
     * --------------------------------------------------------------- */

    /**
     * La fila tiene UN solo punto de entrada (`⋯`) con las cuatro acciones
     * dentro, en vez de cuatro controles sueltos estirados por la fila. Los
     * formularios conservan su `action`, así que sin JavaScript se envían
     * igual (recargando).
     */
    public function testLaFilaDeUsuariosTieneUnUnicoMenuDeAcciones(): void
    {
        $this->authAsAdmin();
        $html = $this->renderUsers(['q' => self::$prefix . '-target']);

        assertSame(1, substr_count($html, 'data-vf-row'), 'la búsqueda deja una sola fila');
        assertSame(1, substr_count($html, 'app-row-menu-btn'), 'una sola puerta de acciones por fila');
        assertTrue(
            str_contains($html, 'data-bs-auto-close="outside"'),
            'el menú no puede cerrarse al marcar la primera casilla: se editan varias cosas seguidas'
        );

        foreach (['role', 'groups', 'permissions', 'toggle'] as $accion) {
            assertSame(
                1,
                substr_count($html, 'action="/admin/users/' . $accion . '"'),
                'la fila tiene que llevar el formulario de /admin/users/' . $accion
            );
        }
    }

    /**
     * Cada celda lleva su `data-label` y la tabla su clase `.app-admin-table`:
     * es lo que la convierte en tarjetas apiladas en móvil. Una columna que se
     * añada sin rótulo sale sin nombre en el móvil y no da ningún error.
     */
    public function testLasCeldasDelListadoLlevanRotuloParaMovil(): void
    {
        $this->authAsAdmin();
        $html = $this->renderUsers(['q' => self::$prefix . '-target']);

        assertTrue(str_contains($html, 'app-admin-table'), 'la tabla lleva la clase que la apila en móvil');
        foreach (['Usuario', 'Email', 'Rol', 'Grupos', 'Permisos extra', 'Estado', 'Registro', 'Acciones'] as $rotulo) {
            assertTrue(
                str_contains($html, 'data-label="' . $rotulo . '"'),
                'falta el rótulo de la celda «' . $rotulo . '»'
            );
        }

        // La hoja de estilos es la mitad del contrato: sin la regla que oculta
        // la cabecera, los `data-label` no se llegan a pintar nunca.
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
        assertTrue(
            (bool) preg_match('/@media[^{]*\{\s*\.app-admin-table thead\s*\{\s*display:\s*none/s', $css),
            'app.css apila la tabla de usuarios en móvil ocultando su cabecera'
        );
    }

    /**
     * Cambiar el rol por fetch repinta la insignia de la columna: el `<select>`
     * del menú ya viene con el valor nuevo, pero lo que se VE en la fila es la
     * insignia, y sin repintarla el panel seguiría diciendo el rol anterior.
     */
    public function testCambiarElRolRepintaLaInsigniaDeLaFila(): void
    {
        $this->authAsAdmin();
        $editor = Role::findByName('editor');
        assertNotNull($editor, 'el rol editor existe');

        $out = $this->callJson('updateRole', [
            'user_id' => (string) self::$targetId,
            'role_id' => (string) $editor['id'],
        ]);

        assertTrue(($out['ok'] ?? false) === true, 'el cambio de rol responde ok: ' . json_encode($out));
        assertArrayHasKey('vfUserRole' . self::$targetId, $out['html'] ?? [], 'la respuesta trae el hueco de la insignia');
        assertTrue(
            str_contains((string) ($out['html']['vfUserRole' . self::$targetId] ?? ''), 'editor'),
            'y la insignia repintada dice el rol nuevo'
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

    /** GET del panel de usuarios: devuelve el HTML pintado. */
    private function renderUsers(array $query = []): string
    {
        $_POST = [];
        $_GET = $query;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin' . ($query === [] ? '' : '?' . http_build_query($query));
        ob_start();
        try {
            (new AdminController())->index();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    /** GET del panel de grupos: devuelve el HTML pintado. */
    private function renderGroups(): string
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/groups';
        ob_start();
        try {
            (new AdminController())->groups();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    /** Ordena una lista de claves para comparar sin depender del orden. */
    private static function sorted(array $keys): array
    {
        $keys = array_values($keys);
        sort($keys);
        return $keys;
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

    /** GET del log de seguridad: devuelve el HTML pintado. */
    private function renderSecurityLog(array $query = []): string
    {
        $_POST = [];
        $_GET = $query;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/security-log' . ($query === [] ? '' : '?' . http_build_query($query));
        ob_start();
        try {
            (new AdminController())->securityLog();
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

    /**
     * Como `call()`, pero simulando `X-Requested-With` para que el controlador
     * responda JSON, y devolviendo el cuerpo decodificado: es la única forma de
     * comprobar los huecos (`html`) que el fetch repone sin recargar.
     */
    private function callJson(string $action, array $post): array
    {
        Session::set('_csrf_token', Csrf::token());
        $_POST = ['_token' => (string) Session::get('_csrf_token')] + $post;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        Request::setRequestBodyForTests(null);

        Response::setRecordHalt(true);
        ob_start();
        try {
            (new AdminController())->{$action}();
        } finally {
            $raw = (string) ob_get_clean();
        }
        Response::setRecordHalt(false);
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
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