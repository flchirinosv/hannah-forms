<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppSettings;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
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
            'userGroups'    => User::groupsMap(),
            'canEditGroups' => Auth::can('admin.groups'),
            'csrf'          => Csrf::token(),
            // El interruptor de registro es de la INSTALACIÓN, no de esta
            // lista de usuarios: se pinta solo con `admin.access` (que es el
            // permiso del panel), y con el valor ya resuelto para que el
            // interruptor no tenga que preguntar nada por su cuenta.
            'registrationEnabled' => AuthController::registrationEnabled(),
            'canManageInstance'    => Auth::can('admin.access'),
        ]);
    }

    /**
     * POST /admin/settings/registration — abre o cierra el registro público.
     *
     * El interruptor se guarda por fetch, así que esta acción responde JSON.
     * Y responde JSON con `ok: false` en vez de un flash porque un fetch que
     * recibe un 302 sigue la redirección y descarga el HTML entero de la ficha
     * de usuarios en el proceso: el JS vería un `200` con la página dentro y
     * no sabría que el guardado falló.
     *
     * El permiso es `admin.access`, no `admin.users`: quien puede entrar al
     * panel administra la instalación, y abrir el registro no es tocar la
     * cuenta de nadie. Es también el mismo permiso que exige el middleware
     * `admin` de la ruta, así que no hay forma de llegar aquí con menos.
     *
     * Va al log de seguridad porque es la puerta que decide si cualquiera puede
     * crear una cuenta en la instalación: es de las pocas escrituras de
     * configuración con consecuencias sobre el acceso de terceros.
     */
    public function updateRegistration(): void
    {
        if (!Auth::can('admin.access')) {
            Response::json(['ok' => false, 'error' => 'No tienes permiso para cambiar los ajustes de la instalación.'], 403);
            return;
        }
        if (!Csrf::verifyRequest()) {
            Response::json(['ok' => false, 'error' => 'Token de seguridad inválido.'], 419);
            return;
        }

        // Ausente = no marcado. No se mira `Request::post('enabled')` con un
        // default: una casilla desmarcada no llega, y `post('enabled', '0')`
        // daría a entender que siempre hay un valor enviado.
        $enabled = (string) Request::post('enabled', '0') === '1';

        if (!AppSettings::setBool(AppSettings::REGISTRATION, $enabled)) {
            Response::json([
                'ok'     => false,
                'error'  => 'No se pudo guardar el ajuste. ¿Falta ejecutar las migraciones?',
                'enabled' => AuthController::registrationEnabled(),
            ], 500);
            return;
        }

        // El detalle es texto plano (el `user_id` lo pone `SecurityLog` solo):
        // el evento ya dice QUIÉN lo hizo, porque `record()` guarda el usuario
        // de la sesión.
        SecurityLog::record(
            $enabled ? 'instance.registration_on' : 'instance.registration_off',
            'registro público ' . ($enabled ? 'abierto' : 'cerrado') . ' desde el panel'
        );

        Response::json([
            'ok'      => true,
            'enabled' => $enabled,
            'message' => $enabled
                ? 'Registro público abierto: cualquiera con la dirección puede crear su cuenta.'
                : 'Registro público cerrado. Las cuentas se crean desde aquí.',
        ]);
    }

    /**
     * Rol de un usuario, cambiado desde su fila de `/admin`.
     *
     * Bimodal: el `select` se envía solo al cambiarlo (`js-auto-submit`, que
     * dispara un `requestSubmit()` y por tanto pasa por el delegado), así que
     * recargar la página entera por cada cambio de rol era tirar abajo el
     * desplegable de grupos que se estuviera editando al lado y todo lo que
     * hubiera escrito en cualquier otra parte.
     *
     * El `html` viene **vacío a propósito**: cambiar el rol no cambia nada de
     * lo que se ve en la fila (el `select` ya está en el valor nuevo, y el
     * nombre del rol lo pone el propio `select`), así que lo único que hay que
     * pintar es el aviso de que se guardó. Repintar la fila entera, o el botón
     * de los grupos, para un cambio de rol sería tirar estado away por nada.
     */
    public function updateRole(): void
    {
        if (!Auth::can('admin.users')) {
            if (Response::wantsJson()) {
                Response::json(['ok' => false, 'error' => 'No tienes permiso para cambiar roles.'], 403);
            } else {
                Response::forbidden();
            }
            return;
        }

        $json = Response::wantsJson();

        if (!Csrf::verifyRequest()) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Token de seguridad inválido.'], 419);
                return;
            }
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin');
            return;
        }

        $userId = (int) Request::post('user_id', 0);
        $roleId = (int) Request::post('role_id', 0);

        $target = User::find($userId);
        if ($target === null) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Usuario no encontrado.'], 404);
                return;
            }
            Session::flash('error', 'Usuario no encontrado.');
            Response::redirect('/admin');
            return;
        }

        // No permitir degradar al propio admin
        if (Auth::id() === $userId && $roleId !== 1) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'No puedes cambiar tu propio rol de administrador.'], 422);
                return;
            }
            Session::flash('error', 'No puedes cambiar tu propio rol de administrador.');
            Response::redirect('/admin');
            return;
        }

        // El rol se busca en la tabla y no se compara con una lista de ids: un
        // id inventado (o el de un rol borrado) dejaría la cuenta con un rol que
        // no existe y `Auth::can()` daría false para todo lo suyo sin decir nada.
        $role = Role::find($roleId);
        if ($role === null) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Rol inválido.'], 422);
                return;
            }
            Session::flash('error', 'Rol inválido.');
            Response::redirect('/admin');
            return;
        }

        // Idempotente con estado OBJETIVO: un reintento o un doble clic no
        // pueden degradar la cuenta a un rol anterior, porque el «objetivo» es
        // el rol que se acaba de elegir, no el contrario.
        User::updateRole($userId, $roleId);
        SecurityLog::record('admin.role_change', 'usuario #' . $userId . ' -> rol #' . $roleId);

        if ($json) {
            Response::json([
                'ok'      => true,
                'message' => 'Rol de «' . $target['name'] . '»: ' . ucfirst((string) $role['name']) . '.',
            ]);
            return;
        }

        Session::flash('success', 'Rol actualizado.');
        Response::redirect('/admin');
        return;
    }

    /**
     * Grupos a los que pertenece un usuario, editados desde su fila.
     *
     * La pertenencia es una N:M, así que el POST es la lista COMPLETA: un
     * `groups[]` sin el id de un grupo significa "sácalo de ahí", no "no lo
     * he tocado". Por eso `postedIds()` devuelve `[]` de verdad cuando no
     * viene nada, y quitar el último grupo es un POST vacío.
     *
     * El permiso es `admin.groups` y no `admin.users`: la operación mueve
     * membresía de grupos, que es lo que `admin.groups` autoriza. Quien solo
     * puede cambiar el rol de alguien no puede meterlo en grupos por aquí.
     */
    public function updateUserGroups(): void
    {
        $json = Response::wantsJson();

        if (!Auth::can('admin.groups')) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'No tienes permiso para gestionar los grupos.'], 403);
            } else {
                Response::forbidden();
            }
            return;
        }
        if (!Csrf::verifyRequest()) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Token de seguridad inválido.'], 419);
                return;
            }
            Session::flash('error', 'Token inválido.');
            Response::redirect('/admin');
            return;
        }

        $userId = (int) Request::post('user_id', 0);
        $target = User::find($userId);
        if ($target === null) {
            if ($json) {
                Response::json(['ok' => false, 'error' => 'Usuario no encontrado.'], 404);
                return;
            }
            Session::flash('error', 'Usuario no encontrado.');
            Response::redirect('/admin');
            return;
        }

        /* Un solo `RoleGroup::all()` para las dos cosas que hacen falta: validar
         * que ningún id venga inventado (map por id) y volver a pintar las
         * insignias del botón con el NOMBRE que le pone el servidor. No se usa
         * `User::groupsMap()` —que es la tabla entera— porque eso sería una
         * consulta por cada usuario de la instalación para pintar un botón. */
        $groupNames = array_column(RoleGroup::all(), 'name', 'id');
        $groupIds = self::postedIds('groups');
        foreach ($groupIds as $groupId) {
            if (!isset($groupNames[$groupId])) {
                if ($json) {
                    Response::json(['ok' => false, 'error' => 'Uno de los grupos ya no existe.'], 422);
                    return;
                }
                Session::flash('error', 'Grupo inválido.');
                Response::redirect('/admin');
                return;
            }
        }

        User::setGroups($userId, $groupIds);
        SecurityLog::record(
            'admin.group_membership',
            'usuario #' . $userId . ' -> grupos [' . implode(',', $groupIds) . ']'
        );

        $message = $groupIds === []
            ? '«' . $target['name'] . '» ya no está en ningún grupo.'
            : 'Grupos de «' . $target['name'] . '» actualizados.';

        if ($json) {
            /* Solo las insignias del botón (`vfUserGroups<uid>`), que es lo
             * único de la celda que depende de la pertenencia. Las casillas del
             * desplegable NO se repintan: son las que enviaron el POST, así que
             * ya son la verdad, y sustituirlas cerraría el desplegable que se
             * está editando.
             *
             * Se recorren los grupos del catálogo y no `$groupIds`, para que las
             * insignias salgan en el MISMO orden que las casillas del menú: el
             * POST va en el orden en que se marcaron, y en el botón se verían
             * desordenadas respecto a lo que hay justo debajo. */
            $myGroups = [];
            foreach ($groupNames as $groupId => $groupName) {
                if (in_array((int) $groupId, $groupIds, true)) {
                    $myGroups[] = ['id' => (int) $groupId, 'name' => (string) $groupName];
                }
            }
            Response::json([
                'ok'      => true,
                'message' => $message,
                'html'    => [
                    'vfUserGroups' . $userId => View::partial('admin/_user_groups', [
                        'myGroups' => $myGroups,
                    ]),
                ],
            ]);
            return;
        }

        Session::flash('success', $message);
        Response::redirect('/admin');
        return;
    }

    /**
     * Alta de cuenta desde el panel.
     *
     * Existe porque `REGISTRATION_ENABLED` está cerrado por defecto, y con esa
     * puerta cerrada el único alta que quedaba era `scripts/create-admin.php`,
     * que crea **administradores** y solo el primero. Sin esta acción, dar de
     * alta un `user` o un `editor` exigía abrir el registro público un rato,
     * dejar que la persona se apuntara y volverlo a cerrar: justo lo contrario
     * de lo que se pidió al cerrar el interruptor.
     *
     * Deliberadamente NO lleva rate limit ni mensaje uniforme en el email ya
     * ocupado: esas dos defensas son del alta pública, que es la puerta que un
     * atacante puede golpear a saco. Aquí ya se ha pasado el permiso
     * `admin.users`, así que el admin puede saber si el email está en uso.
     */
    public function createUser(): void
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

        $name = trim(Request::post('name', ''));
        $email = trim(Request::post('email', ''));
        $password = (string) Request::post('password', '');
        $passwordConfirm = (string) Request::post('password_confirm', '');
        $roleId = (int) Request::post('role_id', 2);

        $invalid = AuthController::validateNewUser($name, $email, $password, $passwordConfirm);
        if ($invalid !== null) {
            Session::flash('error', $invalid);
            Response::redirect('/admin');
            return;
        }

        // El rol se valida contra la tabla, no contra una lista de ids: un
        // `role_id` invented (o de un rol borrado) dejaría una cuenta sin rol
        // interpretable, y `Auth::can()` devolvería false para todo lo suyo sin
        // que nada lo dijera.
        $role = Role::find($roleId);
        if ($role === null) {
            Session::flash('error', 'El rol indicado no existe.');
            Response::redirect('/admin');
            return;
        }

        if (User::findByEmail($email) !== null) {
            Session::flash('error', 'Ya hay una cuenta con ese email.');
            Response::redirect('/admin');
            return;
        }

        $userId = User::create($name, $email, $password, $roleId);
        if ($userId === null) {
            Session::flash('error', 'No se pudo crear la cuenta.');
            Response::redirect('/admin');
            return;
        }

        SecurityLog::record('admin.user_create', 'usuario #' . $userId . ' rol ' . $role['name']);
        Session::flash('success', 'Cuenta creada para ' . $email . ' (rol ' . $role['name'] . ').');
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

        // Estado objetivo, no «invertir»: `active` es lo que pinta el botón
        // (views/admin/users.php lo manda en un `hidden`), y así un reintento o
        // un doble clic no se quedan activando lo que se quería desactivar. Un
        // POST sin el campo —una página cacheada de antes de este cambio— sigue
        // invirtiendo, que es lo que hacía: el camino sin JS no se rompe.
        $pedido = Request::post('active', null);
        $activo = $pedido === null
            ? ((int) $target['is_active'] === 1 ? 0 : 1)
            : ((int) $pedido === 1 ? 1 : 0);

        $cambio = User::setActive($userId, $activo === 1);

        // `Auth::forgetCache()` NO hace falta aquí: no te puedes desactivar a ti
        // mismo (el guard de arriba), así que el actor nunca es el afectado y su
        // caché —que es por request, no de sesión— no puede estar vieja.
        SecurityLog::record('admin.user_toggle', 'usuario #' . $userId . ($activo === 1 ? ' -> activado' : ' -> desactivado'));

        // Idempotente de verdad también en el mensaje: si ya estaba en ese
        // estado, «Estado de usuario actualizado» sería cierto pero no
        // avisa de que no pasó nada, que es la mitad de lo que el botón promete.
        $message = $cambio
            ? ($activo === 1 ? 'Usuario activado.' : 'Usuario desactivado.')
            : 'El usuario ya estaba en ese estado.';

        if (Response::wantsJson()) {
            // Los DOS partials de la fila, y solo los de ESE usuario: con el
            // estado objetivo, lo que hay que repintar es el resultado —insignia
            // y botón—, no «lo que le pasó». Un `html` con la fila entera
            // repondría también el selector de rol y el desplegable de grupos,
            // que no han cambiado, y se comerían un `<select>` abierto.
            $fresco = User::find($userId);
            Response::json([
                'ok'      => true,
                'message' => $message,
                'html'    => $fresco === null ? [] : [
                    'vfUserStatus' . $userId => View::partial('admin/_user_status', ['user' => $fresco]),
                    'vfUserToggle' . $userId => View::partial('admin/_user_toggle', [
                        'user' => $fresco,
                        'uid'  => $userId,
                    ]),
                ],
            ]);
            return;
        }

        Session::flash('success', $message);
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
            'roles'         => Role::allWithPermissions(),
            'users'         => User::all(),
            'groupRoles'    => self::groupRoles(),
            'groupMembers'  => self::groupMembers(),
            'groupPerms'    => self::groupEffectivePermissionsMap(),
            'catalog'       => self::catalog(),
            'csrf'          => Csrf::token(),
        ]);
    }

    /** Permisos efectivos por grupo, para la vista (ver groupEffectivePermissions). */
    private static function groupEffectivePermissionsMap(): array
    {
        $roleMap = self::groupRoles();
        $out = [];
        foreach (RoleGroup::all() as $group) {
            $out[(int) $group['id']] = self::groupEffectivePermissions($roleMap[(int) $group['id']] ?? []);
        }
        return $out;
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
        $current = RoleGroup::find($groupId);
        if ($current === null) {
            Session::flash('error', 'Grupo no encontrado.');
            Response::redirect('/admin/groups');
            return;
        }

        // `name` ausente ≠ nombre vacío. Editar la pertenencia (roles y miembros)
        // no tiene por qué mandar el nombre, y exigirlo hacía la función
        // inusable: la UI no lo mandaba, así que guardar un grupo fallaba siempre
        // con «El nombre del grupo es obligatorio» sin tocar absolutamente nada.
        // Presente pero vacío sí es un error: un grupo no puede quedarse sin
        // nombre a media edición.
        $postedName = Request::post('name');
        if ($postedName === null) {
            $name = (string) $current['name'];
        } else {
            $name = trim((string) $postedName);
            if ($name === '' || mb_strlen($name) > 80) {
                Session::flash('error', 'El nombre del grupo es obligatorio (máx. 80 caracteres).');
                Response::redirect('/admin/groups');
                return;
            }
        }

        // La fila del grupo solo se toca si el nombre o la descripción cambian:
        // `setRoles`/`setUsers` borran y reinsertan dentro de su propia
        // transacción, y hacerlo en cada guardado daría una falsa sensación de
        // cambio (y un `updated_at` que miente).
        $postedDescription = Request::post('description');
        $description = $postedDescription === null
            ? (string) ($current['description'] ?? '')
            : trim((string) $postedDescription);

        if ($name !== (string) $current['name'] || $description !== (string) ($current['description'] ?? '')) {
            RoleGroup::update($groupId, $name, $description);
        }
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

        $roles = Role::allWithPermissions();

        // El recuento va aparte (`allWithPermissions()` no lo trae) y en un
        // mapa indexado por id, no como una clave más del rol: la lista de la
        // baja se recorre con `foreach` y un `count` por fila son N consultas
        // para un número que se enseña junto al nombre.
        $usuarios = [];
        foreach ($roles as $rol) {
            $usuarios[(int) $rol['id']] = Role::userCount((int) $rol['id']);
        }

        View::render('admin/roles', [
            'roles'       => $roles,
            'userCounts'  => $usuarios,
            'permissions' => self::catalog(),
            'csrf'        => Csrf::token(),
        ]);
    }

    /**
     * Permisos que otorga un grupo = unión de los de sus roles.
     *
     * La vista de grupos lo pinta para que se vea el EFECTO de marcar un rol y
     * no solo el cableado: la pregunta de quien administra no es «¿qué roles
     * tiene este grupo?» sino «¿qué pasa si saco a esta persona de aquí?».
     */
    private static function groupEffectivePermissions(array $roleIds): array
    {
        $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds))));
        if ($roleIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($roleIds), '?'));
        return array_column(
            Database::getInstance(Config::db())->fetchAll(
                "SELECT DISTINCT p.`key`
                 FROM permissions p
                 JOIN role_permissions rp ON rp.permission_id = p.id
                 WHERE rp.role_id IN ($ph)
                 ORDER BY p.`key`",
                $roleIds
            ),
            'key'
        );
    }

    public function roleCreate(): void
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

        $raw = trim((string) Request::post('name', ''));
        if ($raw === '' || mb_strlen($raw) > 50) {
            Session::flash('error', 'El nombre del rol es obligatorio (máx. 50 caracteres).');
            Response::redirect('/admin/roles');
            return;
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $raw) !== 1) {
            Session::flash('error', 'El nombre solo admite letras, números, punto, guion y guion bajo.');
            Response::redirect('/admin/roles');
            return;
        }

        $description = trim((string) Request::post('description', ''));
        $existing = Role::findByName(mb_strtolower($raw));
        if ($existing !== null) {
            Session::flash('error', 'Ya existe un rol llamado «' . mb_strtolower($raw) . '».');
            Response::redirect('/admin/roles');
            return;
        }

        // Nace sin permisos a propósito (ver Role::create()): se avisa de que
        // hay que marcarlo en la matriz o no hace nada.
        $roleId = Role::create($raw, $description);
        if ($roleId === null) {
            Session::flash('error', 'No se pudo crear el rol.');
            Response::redirect('/admin/roles');
            return;
        }

        SecurityLog::record('admin.role_create', 'rol «' . (string) (Role::find($roleId)['name'] ?? $raw) . '» creado.');
        Session::flash('success', 'Rol creado sin permisos. Márcalos en la matriz de abajo.');
        Response::redirect('/admin/roles');
        return;
    }

    /**
     * POST /admin/roles/delete — borra un rol propio que no lleva a nadie.
     *
     * Las dos condiciones (no del sistema y sin usuarios) las decide
     * `Role::delete()`, que además devuelve el motivo. Aquí no se reimplementan:
     * una comprobación de «tiene usuarios» en el controlador y otra en el
     * modelo divergirían en cuanto una cambiara, y el «borrado en cascada» que
     * hace la base sería la única que queda.
     *
     * Va al log porque borrar un rol cambia lo que puede hacer un grupo entero:
     * los grupos confieren roles, así que quitar `soporte-tecnico` deja a sus
     * miembros sin nada que el grupo les traía.
     */
    public function roleDelete(): void
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

        $roleId = (int) Request::post('role_id', 0);
        $rol = $roleId > 0 ? Role::find($roleId) : null;
        $nombre = $rol === null ? ('#' . $roleId) : (string) $rol['name'];

        $motivo = null;
        if (!Role::delete($roleId, $motivo)) {
            Session::flash('error', 'No se pudo borrar «' . $nombre . '»: ' . (string) $motivo);
            Response::redirect('/admin/roles');
            return;
        }

        SecurityLog::record('admin.role_delete', 'rol «' . $nombre . '» borrado.');
        Session::flash('success', 'Rol «' . $nombre . '» borrado.');
        Response::redirect('/admin/roles');
        return;
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