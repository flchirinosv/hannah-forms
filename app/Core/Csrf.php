<?php

declare(strict_types=1);

namespace App\Core;

class Csrf
{
    public static function token(): string
    {
        if (!Session::has('_csrf_token')) {
            Session::set('_csrf_token', bin2hex(random_bytes(32)));
        }
        return Session::get('_csrf_token');
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function verify(?string $token): bool
    {
        $expected = Session::get('_csrf_token');
        if ($expected === null || $token === null) {
            return false;
        }
        return hash_equals($expected, $token);
    }

    public static function verifyRequest(): bool
    {
        $token = Request::post('_token');
        return self::verify(is_string($token) ? $token : null);
    }

    /**
     * Verifica el token CSRF contra el header X-CSRF-Token.
     * Util para endpoints JSON que no usan campos de formulario.
     */
    public static function verifyHeader(): bool
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        return self::verify(is_string($token) ? $token : null);
    }
}