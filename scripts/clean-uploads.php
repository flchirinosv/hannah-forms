<?php

declare(strict_types=1);

/**
 * clean-uploads — Mantenimiento de storage/uploads/YYYY/MM.
 *
 * Los adjuntos viven en `storage/uploads/YYYY/MM/<hex>.<ext>` (ver
 * `Uploader::absolutePath`). Cuando la purga de dos fases borra el último
 * archivo de un mes, la carpeta queda vacía pero nadie la elimina: son
 * directorios huecos que se acumulan para siempre.
 *
 * Este script es el barrido manual/cron que complementa las purgas de datos:
 *
 *     php scripts/clean-uploads.php           # borra los meses vacíos
 *     php scripts/clean-uploads.php --dry-run # solo informa, no borra nada
 *
 * Es seguro por construcción: solo borra directorios que (a) no contienen
 * archivos y (b) siguen el patrón estricto `YYYY/MM` de dígitos. Cualquier
 * carpeta con contenido o con un nombre fuera del patrón se deja intacta.
 */

require_once __DIR__ . '/../config/autoload.php';

use App\Core\Env;
use App\Services\Uploader;

Env::load(dirname(__DIR__) . '/.env');

$dryRun = in_array('--dry-run', $argv, true);

$opciones = array_values(array_filter($argv, static fn($a) => str_starts_with($a, '--')));
$desconocidas = array_diff($opciones, ['--dry-run']);
if ($desconocidas !== []) {
    fwrite(STDERR, 'Opción desconocida: ' . implode(', ', $desconocidas) . PHP_EOL);
    fwrite(STDERR, 'Uso: php scripts/clean-uploads.php [--dry-run]' . PHP_EOL);
    exit(1);
}

$base = Uploader::baseDir();
if (!is_dir($base)) {
    echo "No existe {$base}; no hay nada que limpiar." . PHP_EOL;
    exit(0);
}

$borrados = Uploader::pruneEmptyMonths(null, $dryRun);

echo ($dryRun ? 'Vaciaría ' : 'Borrados ') . $borrados . ' directorio(s) vacío(s).' . PHP_EOL;
echo 'Base: ' . $base . PHP_EOL;

exit(0);