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
     * Ventana más larga en uso en la app: la de `register` y `submit` (600 s).
     *
     * Es el techo que necesita `cleanExpired()`: una fila más nueva que esto
     * puede pertenecer a una ventana activa, y borrarla abriría el límite
     * justo cuando alguien está insistir. Todo lo más viejo que la ventana
     * más larga ya no puede afectar a ninguna decisión, así que se puede
     * borrar sin mirar el scope.
     *
     * Si algún día se añade un límite con ventana mayor, hay que subir esto en
     * el mismo commit: si no, el cleanup borraría filas de un limitador vivo.
     */
    public const MAX_WINDOW = 600;

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

        // Punto único de limpieza oportunista: aquí es donde nace una fila, así
        // que es el único sitio que garantiza que la tabla se vacía sola aunque
        // nadie programe un cron (que es lo que pasaba: `cleanExpired()` no
        // tenía NINGÚN call site y la tabla crecía sin techo, con las IPs de los
        // respondientes guardadas en claro).
        self::maybeCleanExpired(self::$cleanupProbability);
    }

    /**
     * Probabilidad con la que cada escritura se lleva una limpieza, en tanto por
     * ciento. Es un seam, no un ajuste: en producción vale `self::PROBABILIDAD`
     * y no hay forma de tocarlo desde un formulario ni desde un request.
     *
     * Existe para que un test pueda comprobar que `recordHit()` REALMENTE limpia
     * sin depender de la suerte del 1 %: con la probabilidad real, un test que
     * escribiera unas pocas filas passaría igual con o sin la llamada, y
     * justement la llamada es lo único que mantiene la tabla acotada.
     */
    public static function setCleanupProbability(?int $percent): void
    {
        self::$cleanupProbability = $percent;
    }

    /**
     * Limpia las filas expiradas, pero solo de vez en cuando.
     *
     * El DELETE es barato con `idx_rate_window` (migración 0004) pero no es
     * gratis, y `recordHit()` está en el camino caliente de cada login y de cada
     * envío. Con un 1 % de las peticiones basta: la tabla se mantiene acotada y
     * el coste esperado es una centésima parte de un DELETE indexado.
     *
     * Como máximo una limpieza por petición aunque el limitador se use varias
     * veces (el login llama a `recordHit()` dos veces: por IP y por email).
     *
     * El retorno distingue "no tocó limpiar" (null) de "limpió y no había nada
     * que borrar" (0). Sin esa distinción no se puede comprobar desde un test
     * que la limpieza se schedulea como se dice: los dos casos devolvían 0 y un
     * `> 0` en el test daba 0 limpiezas siempre, que es un test que pasa sin
     * probar nada.
     *
     * @param int|null $probability porcentaje de peticiones (0-100) que limpian;
     *        null = el de por defecto (`self::PROBABILIDAD`)
     * @return int|null filas borradas, o null si esta vez no tocó limpiar
     */
    public static function maybeCleanExpired(?int $probability = null): ?int
    {
        if (self::$cleanedThisRequest) {
            return null;
        }
        $probability = $probability ?? self::$cleanupProbability ?? self::PROBABILIDAD;
        if (random_int(1, 100) > max(0, min(100, $probability))) {
            return null;
        }

        self::$cleanedThisRequest = true;

        return self::cleanExpired(self::MAX_WINDOW);
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
     *
     * Solo borra filas más viejas que la ventana; para vaciar la tabla entera
     * (mantenimiento) está `scripts/clean-rate-limits.php`.
     */
    public static function cleanExpired(int $windowSeconds): int
    {
        $stmt = self::db()->query(
            'DELETE FROM rate_limits WHERE window_start < (NOW() - INTERVAL ' . (int) $windowSeconds . ' SECOND)'
        );
        return $stmt ? (int) $stmt->rowCount() : 0;
    }

    /** Probabilidad por defecto de la limpieza oportunista, en tanto por ciento. */
    public const PROBABILIDAD = 1;

    private static bool $cleanedThisRequest = false;

    /** `null` = usar `self::PROBABILIDAD`. Lo cambia un test, no un request. */
    private static ?int $cleanupProbability = null;

    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }
}
