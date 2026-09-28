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

        self::$requestUser = $user;
        return $user;
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
     * de sus grupos (RBAC). Cacheado por request: un cambio de rol/grupo se
     * refleja en el siguiente request, igual que el resto de `Auth`.
     *
     * @return array<string>
     */
    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }
        $user = self::user();
        self::$permissions = $user === null
            ? []
            : Role::effectivePermissionKeys((int) $user['role_id'], self::groups());
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
        session_regenerate_id(true);
        Session::set('user_id', $userId);
    }

    public static function logout(): void
    {
        self::$requestUser = null;
        Session::destroy();
    }
}