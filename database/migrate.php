<?php

declare(strict_types=1);

/**
 * Migrate — Aplicador de migraciones de base de datos de VanillaForms.
 *
 * Resuelve la "nota de despliegue": permite aplicar cambios de schema
 * incrementales a una base existente SIN perder datos. Sustituye a
 * re-ejecutar database/schema.sql (que está pensado para instalaciones
 * nuevas desde cero).
 *
 * Uso:
 *   php database/migrate.php            # aplica las migraciones pendientes
 *   php database/migrate.php --status   # muestra aplicadas/pendientes
 *
 * Convenciones:
 *   - Las migraciones viven en database/migrations/0001_nombre.php, 0002_...
 *   - Cada archivo devuelve un closure que recibe la instancia de Database.
 *   - El estado se registra en la tabla `migrations`.
 *   - Cada migración se ejecuta dentro de una transacción.
 */

require_once __DIR__ . '/../config/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

// Cargar variables de entorno desde .env antes de leer config
Env::load(dirname(__DIR__) . '/.env');

$config = Config::all();

try {
    $db = Database::getInstance($config['database']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error de conexión: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Asegurar que la tabla de migraciones exista
$db->query(
    'CREATE TABLE IF NOT EXISTS migrations (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(190) NOT NULL UNIQUE,
        applied_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB'
);

$migrationsDir = __DIR__ . '/migrations';
$files = glob($migrationsDir . '/*.php');
if ($files === false || empty($files)) {
    fwrite(STDERR, 'No se encontraron migraciones en ' . $migrationsDir . PHP_EOL);
    exit(1);
}
sort($files);

$applied = array_column($db->fetchAll('SELECT name FROM migrations'), 'name');
$applied = array_fill_keys($applied, true);

$args = $_SERVER['argv'] ?? [];
$statusOnly = in_array('--status', $args, true);

if ($statusOnly) {
    echo "=== Estado de migraciones ===\n";
    foreach ($files as $file) {
        $name = basename($file);
        $state = isset($applied[$name]) ? 'APLICADA' : 'PENDIENTE';
        echo sprintf("  [%s] %s\n", str_pad($state, 9), $name);
    }
    exit(0);
}

$pending = 0;
foreach ($files as $file) {
    $name = basename($file);

    if (isset($applied[$name])) {
        echo "  [OK] $name (ya aplicada)\n";
        continue;
    }

    $migration = require $file;
    if (!is_callable($migration)) {
        fwrite(STDERR, "  [ERROR] $name no devuelve un closure ejecutable\n");
        exit(1);
    }

    // Nota: NO se envuelve en transacción. Las migraciones de este proyecto
    // usan DDL (CREATE TABLE/INDEX) y en MariaDB/MySQL el DDL provoca commit
    // implícito, de modo que una transacción envolvente se cerraría sola
    // (daría falsos errores). Cada migración debe ser idempotente y lo más
    // atómica posible; el registro en `migrations` se hace SOLO tras éxito.
    try {
        $migration($db);
        $db->execute('INSERT INTO migrations (name) VALUES (:name)', ['name' => $name]);
        echo "  [APLICADA] $name\n";
        $pending++;
    } catch (Throwable $e) {
        fwrite(STDERR, "  [ERROR] $name — " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

echo $pending === 0
    ? "\nBase de datos actualizada (0 migraciones pendientes).\n"
    : "\nAplicadas $pending migración(es) pendiente(s).\n";
