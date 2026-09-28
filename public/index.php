<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use App\Core\Router;

// Headers de seguridad base.
// La CSP tiene default SEGURO en config/config.php (no hace falta tocar .env).
// Exige que los scripts estén externalizados, que ya es el caso: no hay
// <script> inline ni handlers en línea en ninguna vista. Para desactivarla
// del todo hay que poner CSP_POLICY= (vacío) a propósito.
// FRAME_OPTIONS permite DENY (default), SAMEORIGIN o vacío para embeber forms.
$app = \App\Core\Config::get('app', []);
$frameOptions = (string) ($app['frame_options'] ?? 'DENY');
if ($frameOptions !== '') {
    header('X-Frame-Options: ' . $frameOptions);
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$csp = (string) ($app['csp_policy'] ?? '');
if ($csp !== '') {
    header('Content-Security-Policy: ' . $csp);
}

$router = new Router();

require_once __DIR__ . '/../config/routes.php';

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'
);