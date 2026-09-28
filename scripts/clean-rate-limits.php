<?php

declare(strict_types=1);

/**
 * clean-rate-limits — Mantenimiento de la tabla `rate_limits`.
 *
 * `rate_limits` guardaba las IPs de los respondientes en claro y sus filas
 * expiraban de forma lógica (la ventana deja de contar) pero NUNCA se borraban:
 * `RateLimiter::cleanExpired()` no tenía ni un solo call site. El hallazgo D3 del
 * plan de remediación lo cuantificó en 493 filas basura y +9 por ejecución, o sea
 * una tabla que crece sin techo y que retiene PII de gente que ya contestó.
 *
 * Desde la fase 3 hay dos vías y este script es la explícita:
 *
 *  1. `RateLimiter::maybeCleanExpired()` (1 % de las peticiones que tocan el
 *     limitador), que mantiene la tabla acotada aunque nadie programe un cron.
 *  2. Este script, para cron y para vaciarla cuando haga falta de verdad:
 *
 *         php scripts/clean-rate-limits.php            # solo lo expirado
 *         php scripts/clean-rate-limits.php --all      # vacía la tabla entera
 *
 * `--all` es de mantenimiento (tests, una resolución de incidente), no de
 * producción: vaciarla entera reinicia todos los contadores, o sea que también
 * reinicia la protección mientras dura el hueco. Por eso pide confirmación
 * interactiva, y `--yes` es para quien ya sabe lo que hace (un cron).
 */

require_once __DIR__ . '/../config/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\RateLimiter;

Env::load(dirname(__DIR__) . '/.env');

$todo = in_array('--all', $argv, true);
$si = in_array('--yes', $argv, true);

$opciones = array_values(array_filter($argv, static fn($a) => str_starts_with($a, '--')));
$desconocidas = array_diff($opciones, ['--all', '--yes']);
if ($desconocidas !== []) {
    fwrite(STDERR, 'Opción desconocida: ' . implode(', ', $desconocidas) . PHP_EOL);
    fwrite(STDERR, 'Uso: php scripts/clean-rate-limits.php [--all] [--yes]' . PHP_EOL);
    exit(1);
}

try {
    $db = Database::getInstance(Config::all()['database']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error de conexión: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$antes = (int) ($db->fetch('SELECT COUNT(*) AS c FROM rate_limits')['c'] ?? 0);
if ($antes === 0) {
    echo 'La tabla rate_limits ya está vacía.' . PHP_EOL;
    exit(0);
}

if ($todo) {
    if (!$si) {
        echo "La tabla tiene {$antes} filas y se va a VACIAR ENTERA." . PHP_EOL;
        echo 'Eso reinicia todos los contadores, también la protección contra' . PHP_EOL;
        echo 'fuerza bruta. Continúa solo si es lo que quieres: [s/N] ';
        $respuesta = fgets(STDIN);
        if ($respuesta === false || !in_array(trim($respuesta), ['s', 'S', 'si', 'sí', 'y'], true)) {
            echo 'Cancelado. No se borró nada.' . PHP_EOL;
            exit(0);
        }
    }
    $borradas = $db->execute('DELETE FROM rate_limits');
} else {
    $borradas = RateLimiter::cleanExpired(RateLimiter::MAX_WINDOW);
}

$despues = (int) ($db->fetch('SELECT COUNT(*) AS c FROM rate_limits')['c'] ?? 0);

echo ($todo ? 'Tabla vaciada' : 'Expirados eliminados') . ": {$borradas} fila(s)." . PHP_EOL;
echo "rate_limits: {$antes} -> {$despues} fila(s)." . PHP_EOL;

exit(0);
