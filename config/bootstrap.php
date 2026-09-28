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

date_default_timezone_set('America/Mexico_City');

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