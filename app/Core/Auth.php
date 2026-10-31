<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Role;
use App\Models\User;

class Auth
{
    /**
     * Caché del usuario actual, solo para este request (static de proceso,
     * NO en sesión). Así, cambios de rol/is_active se reflejan en el
     * siguiente request: desactivar o degradar a un usuario con sesión
     * activa deja de tener efecto real de inmediato.
     */
    private static ?array $requestUser = null;

    /** Caché de permisos efectivos y de grupos del request (RBAC). */
    private static ?array $permissions = null;
    private static ?array $groups = null;

    public static function user(): ?array
    {
        $userId = Session::get('user_id');
        if ($userId === null) {
            return null;
        }

        $userId = (int) $userId;

        if (self::$requestUser !== null && (int) self::$requestUser['id'] === $userId) {
            return self::$requestUser;
        }

        $user = User::findWithRole($userId);

        // A7: si el usuario fue desactivado, cerrar sesión y tratar como anónimo
        if ($user === null || (int) ($user['is_active'] ?? 1) !== 1) {
            self::logout();
            return null;
        }

        // F2: si la contraseña se cambió DESPUÉS de abrirse esta sesión, la sesión
        // es de una versión anterior de la credencial y se cierra. Sin esto,
        // recuperar la contraseña no expulsaría a nadie: el atacante con la
        // sesión robada seguiría dentro con la contraseña nueva vigente.
        //
        // Las sesiones son ficheros de PHP y no se pueden revocar por id, así que
        // se comparan instantes: `auth_at` (cuándo se abrió) contra
        // `users.password_changed_at` (cuándo se cambió la clave).
        //
        // OJO: una sesión SIN `auth_at` es de antes de esta versión (o de un
        // test) y se cierra. Cuesta un cierre de sesión a todos al desplegar,
        // que es el precio de no dejar abiertas sesiones cuyo origen no se
        // puede comprobar; es preferible a aceptarlas sin verificar.
        $authAt = Session::get('auth_at');
        if ($authAt === null) {
            // Sesión anterior a F2 (o de un test que no pasó por
            // `assumeLoggedIn`): se cierra, porque no hay forma de comprobar de
            // qué credencial viene.
            \App\Core\SecurityLog::record('auth.session_revoked', 'sesión sin auth_at');
            self::logout();
            return null;
        }
        if (self::sessionIsStale($user, (int) $authAt)) {
            \App\Core\SecurityLog::record('auth.session_revoked', 'sesión anterior al cambio de contraseña');
            self::logout();
            return null;
        }

        self::$requestUser = $user;
        return $user;
    }

    /**
     * F2 — ¿Esta sesión quedó obsoleta por un cambio de contraseña?
     *
     * Se compara con `<=` y NO con `<`, y esa es la parte importante: los dos
     * instantes vienen de relojes distintos (PHP y MariaDB) y con granularidad
     * de SEGUNDO, así que "abrir sesión" y "cambiar la contraseña" dentro del
     * mismo segundo son indistinguibles. Ante la duda se cierra la sesión,
     * porque el error caro es dejar dentro al atacante con la credencial vieja,
     * no el inconvenience de que alguien tenga que entrar otra vez.
     *
     * La contrapartida —que un login legítimo en ese mismo segundo podría
     * perder la sesión— está cubierta: todo el que abre sesión pasa por
     * `markSessionFresh()`, que empuja `auth_at` un segundo por encima del
     * cambio cuando hace falta, de modo que solo quedan obsoletas las sesiones
     * que de verdad lo son.
     *
     * Los dos instantes se comparan como SEGUNDOS desde epoch y en UTC, que es
     * el contrato horario del proyecto. `password_changed_at` se guarda como
     * `UTC_TIMESTAMP()` desde MariaDB, así que reinterpretarlo en la zona local
     * lo desplazaría y cerraría (o dejaría abiertas) sesiones equivocadas.
     */
    private static function sessionIsStale(array $user, int $authAt): bool
    {
        $changedTs = self::passwordChangedTimestamp($user);
        if ($changedTs === null) {
            // Nunca se cambió la contraseña: la sesión es tan válida como la credencial.
            return false;
        }

        return $authAt <= $changedTs;
    }

    /**
     * `users.password_changed_at` como segundo UTC, o null si nunca se cambió.
     */
    private static function passwordChangedTimestamp(?array $user): ?int
    {
        $changedAt = $user['password_changed_at'] ?? null;
        if ($changedAt === null || $changedAt === '') {
            return null;
        }

        $ts = strtotime((string) $changedAt . ' UTC');
        return $ts === false ? null : $ts;
    }

    /**
     * F2 — Marca la sesión actual como recién abierta y VÁLIDA.
     *
     * Lo usan los tres caminos que legítimamente abren sesión: el login, el seam de test
     * y el cambio de contraseña del propio usuario (que, sin esto, se expulsaría
     * del panel en la página siguiente).
     *
     * El `+1` es lo que hace que la comparación estricta de `sessionIsStale()`
     * no casts a nadie: si el cambio y la apertura caen en el mismo segundo,
     * `auth_at` se adelanta un segundo al cambio en lugar de depender de que
     * dos relojes distintos coincidieran.
     */
    public static function markSessionFresh(?string $passwordChangedAt = null): void
    {
        $stamp = time();
        $changedTs = null;
        if ($passwordChangedAt !== null && $passwordChangedAt !== '') {
            $ts = strtotime($passwordChangedAt . ' UTC');
            $changedTs = $ts === false ? null : $ts;
        }
        if ($changedTs !== null && $stamp <= $changedTs) {
            $stamp = $changedTs + 1;
        }

        self::forgetCache();
        Session::set('auth_at', $stamp);
    }

    /**
     * Limpia la caché del request. Útil en tests para simular un nuevo
     * request o tras logout/refresh de datos.
     */
    public static function forgetCache(): void
    {
        self::$requestUser = null;
        self::$permissions = null;
        self::$groups = null;
    }

    public static function id(): ?int
    {
        $userId = Session::get('user_id');
        return $userId === null ? null : (int) $userId;
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function role(): ?string
    {
        $user = self::user();
        return $user['role_name'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /**
     * Ids de los grupos de roles del usuario actual (RBAC).
     *
     * @return array<int>
     */
    public static function groups(): array
    {
        if (self::$groups !== null) {
            return self::$groups;
        }
        self::$groups = [];
        $userId = self::id();
        if ($userId !== null) {
            self::$groups = User::groupIds($userId);
        }
        return self::$groups;
    }

    /**
     * Permisos efectivos del usuario actual: los de su rol + los de los roles
     * de sus grupos + sus permisos DIRECTOS (RBAC, cuatro fuentes). Cacheado por
     * request: un cambio de rol/grupo/permiso directo se refleja en el siguiente
     * request, igual que el resto de `Auth`.
     *
     * Los directos se SUMAN, nunca se restan: no hay una lista negra. Un permiso
     * que venga por rol y también directo cuenta una sola vez (`array_unique`).
     *
     * @return array<string>
     */
    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }
        $user = self::user();
        if ($user === null) {
            self::$permissions = [];
            return self::$permissions;
        }
        $perms = Role::effectivePermissionKeys((int) $user['role_id'], self::groups());
        $direct = User::permissionKeys((int) $user['id']);
        self::$permissions = array_values(array_unique(array_merge($perms, $direct)));
        sort(self::$permissions);
        return self::$permissions;
    }

    /**
     * ¿Tiene el usuario actual un permiso concreto (RBAC)?
     *
     * Es la ÚNICA forma de comprobar una capacidad en un controlador; no usar
     * `role()` para gates de negocio (el rol solo nombra un conjunto de
     * permisos, y la matriz vive en `/admin/roles`).
     */
    public static function can(string $key): bool
    {
        return in_array($key, self::permissions(), true);
    }

    public static function login(int $userId): void
    {
        self::$requestUser = null;
        // En el runner no hay sesión activa (CLI), así que regenerar el id
        // escribe un warning y no hace nada. En producción bootstrap la
        // abre siempre, de modo que este guard no debilita el login.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Session::set('user_id', $userId);
        // F2: instante de apertura de sesión, para poder cerrar las que
        // envejezcan por un cambio de contraseña (ver user()). Se lee el
        // `password_changed_at` del usuario para que un login hecho en el
        // MISMO segundo que un cambio de contraseña no se invalide a sí mismo.
        self::markSessionFresh((string) (User::find($userId)['password_changed_at'] ?? ''));
    }

    /**
     * F2 — Fija la sesión como si el usuario se hubiera autenticado AHORA.
     *
     * Es un SEAM de test, no una vía de producción: fija a mano el
     * `user_id` y el `auth_at` que en producción pone `login()`, sin tocar la
     * cookie de sesión (en el runner no hay sesión activa que regenerar).
     *
     * Existe como método y no como dos líneas copiadas en cada test porque el
     * `auth_at` es OBLIGATORIO: sin él, `user()` trata la sesión como
     * anterior a esta versión y la cierra (que es lo correcto en producción,
     * donde significa "hay que volver a entrar"). Un test que se saltara el
     * `auth_at` seguiría en verde, pero probando la rama equivocada: se
     * encontraría sin sesión y todos sus `Auth::can()` darían false.
     */
    public static function assumeLoggedIn(int $userId): void
    {
        Session::set('user_id', $userId);
        self::markSessionFresh((string) (User::find($userId)['password_changed_at'] ?? ''));
    }

    public static function logout(): void
    {
        self::$requestUser = null;
        Session::destroy();
    }

    /**
     * F2 — Cierra la sesión actual CONSERVANDO la sesión de PHP.
     *
     * Es lo que usa la recuperación de contraseña, y la diferencia con
     * `logout()` no es cosmética: `logout()` destruye la sesión entera, y con
     * ella se pierde el mensaje de "tu contraseña se ha cambiado" que se acaba de
     * escribir en el flash, así que la persona caería en un `/login` mudo sin
     * saber qué pasó.
     *
     * Aun así NO es un logout "de mentira": se borran las dos claves de
     * identidad y se regenera el id de sesión, de modo que la cookie anterior
     * deja de servir para nada (ni para el `auth_at`, ni para el `user_id`) y
     * una sesión fijada antes del cambio no se queda viva. Lo único que
     * sobrevive al array es el flash, que es justo lo que hay que conservar.
     */
    public static function revokeCurrentSession(): void
    {
        self::$requestUser = null;
        self::$permissions = null;
        self::$groups = null;
        Session::remove('user_id');
        Session::remove('auth_at');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}