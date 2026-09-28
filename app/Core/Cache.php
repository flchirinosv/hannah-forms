<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Caché de consultas frecuentes en la tabla `cache` (2026-09-28).
 *
 * Uso previsto: valores derivados baratos de refrescar pero caros de recalcular
 * por mucha concurrencia (el layout del dashboard). Pérdida de caché ≠ fallo:
 * NINGÚN fallo aquí debe romper el request, por eso `remember()` solo puede
 * devolver el valor calculado en vivo, nunca lanzar.
 *
 * Invaldación por clave o prefijo (`forgetLike`): las mutaciones de forms y
 * submissions tienen que limpiar `dash:%`, o el usuario vería un dashboard
 * fantasma hasta el vencimiento del TTL.
 */
final class Cache
{
    /** @var bool una limpieza oportunista por request como en RateLimiter */
    private static bool $cleanedThisRequest = false;

    public static function remember(string $key, int $ttlSeconds, callable $compute): mixed
    {
        $db = self::db();
        if ($db === null) {
            return $compute();
        }

        $row = $db->fetch(
            'SELECT value, expires_at FROM cache WHERE cache_key = :k',
            ['k' => $key]
        );

        // Válido si existe y no vence en UTC (mismo patrón que RateLimiter: la
        // caducidad se decide del lado BD para no depender de la zona de PHP).
        if ($row !== null && $row['expires_at'] !== null && $row['expires_at'] > '') {
            try {
                $expires = strtotime((string) $row['expires_at'] . ' UTC');
                if ($expires !== false && $expires > time()) {
                    $value = json_decode((string) $row['value'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $value;
                    }
                }
            } catch (\Throwable $e) {
                // fila corrupta: se recalcula y se sobrescribe
            }
        }

        $value = $compute();

        try {
            $db->execute(
                'INSERT INTO cache (cache_key, value, expires_at)
                 VALUES (:k, :v, UTC_TIMESTAMP() + INTERVAL :t SECOND)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), expires_at = VALUES(expires_at)',
                ['k' => $key, 'v' => json_encode($value), 't' => $ttlSeconds]
            );
            self::cleanupOnce($db);
        } catch (\Throwable $e) {
            // La caché nunca interviene en el resultado de la petición.
        }

        return $value;
    }

    public static function forget(string $key): void
    {
        if (self::db() === null) {
            return;
        }
        try {
            self::db()->execute('DELETE FROM cache WHERE cache_key = :k', ['k' => $key]);
        } catch (\Throwable $e) {
        }
    }

    /** Invalida por prefijo (`dash:%`): portátil entre clave y prefijo. */
    public static function forgetLike(string $prefix): void
    {
        if (self::db() === null) {
            return;
        }
        try {
            self::db()->execute('DELETE FROM cache WHERE cache_key LIKE :p', ['p' => $prefix . '%']);
        } catch (\Throwable $e) {
        }
    }

    /** Borra la tabla entera: SOLO para tests y mantenimiento explícito. */
    public static function flush(): void
    {
        if (self::db() === null) {
            return;
        }
        try {
            self::db()->execute('DELETE FROM cache');
        } catch (\Throwable $e) {
        }
    }

    private static function db(): ?Database
    {
        try {
            return Database::getInstance(Config::db());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Limpieza oportunista de caducados (1 por request), como RateLimiter. */
    private static function cleanupOnce(Database $db): void
    {
        if (self::$cleanedThisRequest) {
            return;
        }
        self::$cleanedThisRequest = true;
        try {
            $db->execute('DELETE FROM cache WHERE expires_at < UTC_TIMESTAMP()');
        } catch (\Throwable $e) {
        }
    }

    /** Seam de tests: reinicia el flag de limpieza por request. */
    public static function resetRequestFlag(): void
    {
        self::$cleanedThisRequest = false;
    }
}