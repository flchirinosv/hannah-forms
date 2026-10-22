<?php

declare(strict_types=1);

/**
 * Instalador web de VanillaForms (AG11).
 *
 * Entrypoint PROPIO, a propósito: `config/bootstrap.php` conecta la base de
 * datos y MATA el proceso si falla. En una instalación nueva la base no existe,
 * así que ninguna ruta de `config/routes.php` llegaría a dibujarse. Aquí se
 * carga solo el autoload y el `.env` (con los fallbacks del código), y se
 * levanta una sesión propia para poder llevar el formulario por pasos.
 *
 * Flujo: requisitos → conexión BD → esquema+roles → primer admin y `APP_KEY` →
 * escribir `.env`, marcar `storage/installed.lock` y mandar a `/login`.
 *
 * Una vez marcado, este fichero se niega a hacer nada (ver `InstallGuard`): al
 * no cargar el bootstrap, seguiría siendo alcanzable por URL.
 */

require_once __DIR__ . '/../config/autoload.php';

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\EnvWriter;
use App\Core\Response;
use App\Core\Secret;
use App\Core\Session;
use App\Core\View;
use App\Services\InstallGuard;
use App\Services\Installer;

$raiz = dirname(__DIR__);

// El `.env` puede no existir todavía (primera instalación): se carga si está.
if (is_file($raiz . '/.env')) {
    Env::load($raiz . '/.env');
}

$config = Config::all();

// Sesión propia. La de la app la arranca `config/bootstrap.php`, que aquí no
// corre; sin sesión no habría CSRF ni forma de recordar el paso.
Session::start($config['session']);

// Cabeceras base, equivalentes a las de `public/index.php`.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');

// --- Gate: si ya hay instalación, el instalador no hace nada más. ------------
if (InstallGuard::estaInstalado() && !isset($_GET['force'])) {
    http_response_code(403);
    View::render('install/installed', [], 'install');
    exit;
}

// --- Requisitos del servidor ------------------------------------------------
$requisitos = InstallGuard::revisarRequisitos($raiz);

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- Estado del asistente ----------------------------------------------------
$paso = (string) ($_GET['paso'] ?? 'requisitos');
$errores = [];
$datos = Session::get('vf_install_datos', []);
if (!is_array($datos)) {
    $datos = [];
}

// Formulario de BD: preferimos lo que el usuario teclea; si no, los valores de
// `.env`/config, y si no, defaults de desarrollo.
$dbForm = [
    'host'     => (string) ($datos['host'] ?? $config['database']['host'] ?? '127.0.0.1'),
    'port'     => (string) ($datos['port'] ?? $config['database']['port'] ?? '3306'),
    'name'     => (string) ($datos['name'] ?? $config['database']['name'] ?? 'vanilla_forms'),
    'user'     => (string) ($datos['user'] ?? $config['database']['user'] ?? ''),
    'password' => (string) ($datos['password'] ?? ''),
    'charset'  => (string) ($datos['charset'] ?? $config['database']['charset'] ?? 'utf8mb4'),
];

$adminForm = [
    'name'  => (string) ($datos['admin_name'] ?? ''),
    'email' => (string) ($datos['admin_email'] ?? ''),
];

$appUrl = (string) ($datos['app_url'] ?? $config['app']['url'] ?? 'http://127.0.0.1:8080');

// --- POST --------------------------------------------------------------------
if ($metodo === 'POST') {
    if (!Csrf::verifyRequest()) {
        http_response_code(400);
        $errores[] = 'Token de seguridad inválido. Recarga la página.';
    } elseif ($paso === 'base') {
        $dbForm = [
            'host'     => trim((string) ($_POST['db_host'] ?? '')),
            'port'     => trim((string) ($_POST['db_port'] ?? '3306')),
            'name'     => trim((string) ($_POST['db_name'] ?? '')),
            'user'     => trim((string) ($_POST['db_user'] ?? '')),
            'password' => (string) ($_POST['db_password'] ?? ''),
            'charset'  => 'utf8mb4',
        ];

        if ($dbForm['host'] === '' || $dbForm['name'] === '' || $dbForm['user'] === '') {
            $errores[] = 'Host, nombre de base y usuario son obligatorios.';
        } elseif (!Installer::nombreValido($dbForm['name'])) {
            $errores[] = 'El nombre de la base solo admite letras, números y guion bajo.';
        } else {
            $instalador = new Installer($dbForm);
            try {
                // Conectar (sin base) comprueba credenciales; crear la base y
                // aplicar esquema/seed/migraciones completan este paso.
                $instalador->crearBase();
                $instalador->aplicarEsquema($raiz);
                $instalador->probarConexionConBase();

                Session::set('vf_install_datos', $dbForm);
                Response::redirect('/install.php?paso=admin');
                return;
            } catch (\Throwable $e) {
                $errores[] = $e->getMessage();
            }
        }
    } elseif ($paso === 'admin') {
        $adminForm = [
            'name'  => trim((string) ($_POST['admin_name'] ?? '')),
            'email' => trim((string) ($_POST['admin_email'] ?? '')),
        ];
        $password = (string) ($_POST['admin_password'] ?? '');
        $passwordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');
        $appUrl = trim((string) ($_POST['app_url'] ?? $appUrl));

        if (!isset($datos['name']) || $datos['name'] === '') {
            $errores[] = 'No hay datos de conexión de la base; vuelve al paso anterior.';
        } elseif ($appUrl === '') {
            $errores[] = 'La URL de la aplicación es obligatoria.';
        } else {
            $instalador = new Installer($datos);
            try {
                $instalador->probarConexionConBase();
                $instalador->crearAdmin(
                    $adminForm['name'],
                    $adminForm['email'],
                    $password,
                    $passwordConfirm
                );

                // APP_KEY: se genera SOLO si no hay una utilizable. Nunca se
                // sobrescribe una existente (perdería el descifrado de los
                // secretos guardados).
                $envPath = $raiz . '/.env';
                $writer = new EnvWriter($envPath);
                $writer->setMany([
                    'DB_HOST'   => $datos['host'],
                    'DB_PORT'   => $datos['port'],
                    'DB_NAME'   => $datos['name'],
                    'DB_USER'   => $datos['user'],
                    'DB_PASSWORD' => $datos['password'],
                    'DB_CHARSET' => $datos['charset'] ?? 'utf8mb4',
                    'APP_URL'   => $appUrl,
                ]);

                $claveActual = (string) Config::get('app.key', '');
                if (trim($claveActual) === '') {
                    $writer->set('APP_KEY', Secret::generateKey());
                }

                $writer->save();

                InstallGuard::marcarInstalado($adminForm['email']);

                Session::remove('vf_install_datos');
                Response::redirect('/install.php?paso=listo');
                return;
            } catch (\Throwable $e) {
                $errores[] = $e->getMessage();
            }
        }
    }
}

// --- Render ------------------------------------------------------------------
$pasos = ['requisitos', 'base', 'admin'];
$vista = in_array($paso, $pasos, true) ? $paso : 'listo';

View::render('install/' . $vista, [
    'csrf'       => Csrf::token(),
    'paso'       => $paso,
    'requisitos' => $requisitos,
    'hayErrores' => InstallGuard::hayErrores($requisitos),
    'errores'    => $errores,
    'dbForm'     => $dbForm,
    'adminForm'  => $adminForm,
    'appUrl'     => $appUrl,
    'phpVersion' => PHP_VERSION,
], 'install');
