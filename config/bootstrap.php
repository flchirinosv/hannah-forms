<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Session;

require_once __DIR__ . '/autoload.php';

// Cargar variables de entorno desde .env (si existe) antes de leer config
Env::load(dirname(__DIR__) . '/.env');

$config = Config::all();

error_reporting(E_ALL);
ini_set('display_errors', $config['app']['env'] === 'development' ? '1' : '0');

// PHP trabaja en UTC, no en la zona de presentación.
//
// El string que devuelve la BD para una columna TIMESTAMP es UTC (porque la
// sesión fija `SET time_zone = '+00:00'`, ver App\Core\Database). Si PHP no
// está en UTC, `strtotime('2026-09-28 01:33:35')` coloca ese instante 6 h más
// tarde de lo que es (con America/Mexico_City) y todo lo que aritmétique con
// esa fecha queda desviado: los "hace X", los límites de los filtros por día y
// los `DATE()` de los agrupamientos. Imprimir el string en crudo no lo
// delata, que es justo por lo que llevaba tiempo escondido.
//
// La conversión a la zona que ve el usuario la hace App\Core\Clock (que usa
// `app.timezone`, por defecto America/Caracas) en el punto de presentación.
date_default_timezone_set('UTC');

try {
    Database::getInstance($config['database']);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'No se pudo conectar a la base de datos.';
    exit;
}

Session::start($config['session']);

// Las páginas de la app son dinámicas y a veces autenticadas: nunca cachear
// el HTML para que los assets versionados (View::asset) siempre se recarguen.
header('Cache-Control: no-store, must-revalidate');

const BASE_PATH = __DIR__ . '/../';

return $config;