<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Allowlist de dominios para `thanks.redirect_url` (F5.1, post-submit).
 *
 * Vacía por defecto = NINGUNA URL externa se sigue tras el envío: el thanks
 * interno se muestra con un aviso. `APP_REDIRECT_ALLOWLIST` (config
 * `app.redirect_allowlist`) la habilita con una lista separada por comas; la
 * pone quien opera la instancia, no quien edita un formulario, porque el
 * `redirect_url` de un form no pasado por esta lista es un trampolín de
 * phishing/redirect abierto hacia un dominio ajeno.
 *
 * OJO al tocarlo: `Config` no tiene setter, así que los tests fijan la lista
 * con el seam `setAllowlistForTesting()` (null = leer de la config) y deben
 * restaurarlo en `tearDown`, igual que hace `Webhook::setAllowPrivate`.
 */
class RedirectGuard
{
    /** Seam de tests: array de hosts o null para leer de la config. */
    private static ?array $allowlistOverride = null;

    public static function setAllowlistForTesting(?array $hosts): void
    {
        self::$allowlistOverride = $hosts;
    }

    /**
     * ¿Está permitido seguir la redirección a $url?
     * La comparación es por HOST (sin esquema, puerto ni ruta), en minúsculas.
     */
    public static function hostAllowed(string $url): bool
    {
        $host = self::hostOf($url);
        if ($host === '') {
            return false;
        }
        return in_array($host, self::allowlist(), true);
    }

    /**
     * Host en minúsculas de una URL http/https, o '' si no es parseable.
     * parse_url con ruta/puerto/query devuelve host separado: solo se usa ese
     * campo, nunca se recompone el string (evita tropiezos con userinfo).
     */
    public static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) : '';
    }

    /** Lista efectiva de hosts permitidos, normalizada a minúsculas. */
    private static function allowlist(): array
    {
        if (self::$allowlistOverride !== null) {
            return array_map('strtolower', self::$allowlistOverride);
        }
        $raw = (array) Config::get('app.redirect_allowlist', []);
        return array_map('strtolower', $raw);
    }
}