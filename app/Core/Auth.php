<?php

declare(strict_types=1);

namespace App\Core;

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