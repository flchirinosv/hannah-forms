<?php

declare(strict_types=1);

namespace App\Core;

/**
 * RateLimiter
 *
 * Prevención de fuerza bruta y abuso mediante un límite de intentos
 * por ventana de tiempo. Usa la tabla `rate_limits` de la base de datos
 * para poder aplicar límites incluso a peticiones anónimas (antes de login).
 *
 * Sin dependencias externas.
 *
 * Uso:
 *   if (!RateLimiter::isAllowed('login', $ip, 5, 300)) {
 *       // demasiados intentos: rechazar con 429
 *   }
 *   ...
 *   RateLimiter::recordHit('login', $ip);
 */
class RateLimiter
{
    /**
     * Verifica si la acción puede ejecutarse (no excede el límite).
     *
     * La expiración de la ventana se evalúa en MySQL (NOW()) para evitar
     * errores de zona horaria entre PHP (UTC) y el servidor de base de datos.
     *
     * @param string $scope     nombre de la acción (login, submit, etc.)
     * @param string $identifier identificador (IP, email, etc.)
     * @param int    $maxAttempts límite de intentos en la ventana
     * @param int    $windowSeconds duración de la ventana en segundos
     * @return bool true si se permite, false si excede el límite
     */
    public static function isAllowed(string $scope, string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        $row = self::db()->fetch(
            'SELECT attempts FROM rate_limits
             WHERE scope = :scope AND identifier = :identifier
               AND window_start >= NOW() - INTERVAL :win SECOND',
            ['scope' => $scope, 'identifier' => $identifier, 'win' => (int) $windowSeconds]
        );

        return $row === null || (int) $row['attempts'] < $maxAttempts;
    }

    /**
     * Registra un intento de la acción. Si la ventana anterior expiró,
     * reinicia el contador.
     */
    public static function recordHit(string $scope, string $identifier, int $windowSeconds = 0): void
    {
        // Si existe una ventana expirada para este scope, limpiarla para
        // iniciar una nueva ventana de conteo.
        if ($windowSeconds > 0) {
            self::db()->execute(
                'DELETE FROM rate_limits
                 WHERE scope = :scope AND identifier = :identifier
                   AND window_start < NOW() - INTERVAL :win SECOND',
                ['scope' => $scope, 'identifier' => $identifier, 'win' => (int) $windowSeconds]
            );
        }

        self::db()->execute(
            'INSERT INTO rate_limits (scope, identifier, attempts)
             VALUES (:scope, :identifier, 1)
             ON DUPLICATE KEY UPDATE attempts = attempts + 1',
            ['scope' => $scope, 'identifier' => $identifier]
        );
    }

    /**
     * Restablece el contador de una acción (p. ej. tras login exitoso).
     */
    public static function reset(string $scope, string $identifier): void
    {
        self::db()->execute(
            'DELETE FROM rate_limits WHERE scope = :scope AND identifier = :identifier',
            ['scope' => $scope, 'identifier' => $identifier]
        );
    }

    /**
     * Limpia los registros de rate limit expirados en todas las ventanas.
     */
    public static function cleanExpired(int $windowSeconds): int
    {
        $stmt = self::db()->query(
            'DELETE FROM rate_limits WHERE window_start < (NOW() - INTERVAL ' . (int) $windowSeconds . ' SECOND)'
        );
        return $stmt ? (int) $stmt->rowCount() : 0;
    }

    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }
}
