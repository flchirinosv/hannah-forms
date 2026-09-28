<?php

declare(strict_types=1);

/**
 * create-admin — Crea (o actualiza) una cuenta de administrador.
 *
 * Existe por el hueco que abre `SEED_DEMO`: los usuarios demo del seed comparten
 * una contraseña publicada y uno es admin, así que sembrarlos a mano es lo
 * contrario de una instalación segura. Con el seed detrás de un opt-in, esta es
 * la vía para dejar el primer admin de una instancia real.
 *
 * Uso:
 *   ADMIN_PASSWORD=una-larga php scripts/create-admin.php
 *   php scripts/create-admin.php --email=admin@mi-dominio.com --name=Administración
 *   php scripts/create-admin.php --password-stdin < secreto.txt
 *   php scripts/create-admin.php --force        # actualiza uno existente
 *
 * Decisiones:
 *  - La contraseña NUNCA va en un argumento de línea de comandos (queda en el
 *    historial del shell y en `ps`), y nunca se imprime. Se lee de
 *    ADMIN_PASSWORD, de --password-stdin, o de un prompt sin eco.
 *  - Mínimo 10 caracteres, el mismo que impone el registro (item 2.7): si el
 *    admin puede tener una contraseña floja, el resto de la política vale poco.
 *  - Si ya hay un admin, se niega a crear otro sin --force, para no dejar admins
 *    por accidente ni una cuenta vieja con contraseña conocida.
 */

require_once __DIR__ . '/../config/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

Env::load(dirname(__DIR__) . '/.env');

const PASSWORD_MIN = 10;

$opts = [];
$passwordStdin = false;
$force = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--password-stdin') {
        $passwordStdin = true;
    } elseif (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $opts[$k] = $v;
    } else {
        fwrite(STDERR, 'Argumento desconocido: ' . $arg . PHP_EOL);
        exit(1);
    }
}

$email = trim((string) ($opts['email'] ?? Env::get('ADMIN_EMAIL', 'admin@local.test')));
$name = trim((string) ($opts['name'] ?? 'Administración'));

// La contraseña no entra por `--password`: el chequeo va antes de usarla en
// ninguna parte, porque un `isset($opts['password'])` más abajo con un `??`
// silencioso aceptaría la contraseña del argumento sin que nadie se entere. Se
// rechaza con un mensaje que dice por dónde sí va.
if (array_key_exists('password', $opts)) {
    fwrite(STDERR, <<<TXT
    --password no está permitido: el argumento queda en el historial del shell y
    en la lista de procesos de todo el sistema. Usa una de estas formas:

        ADMIN_PASSWORD=una-larga php scripts/create-admin.php
        php scripts/create-admin.php --password-stdin < secreto.txt
        php scripts/create-admin.php            # prompt sin eco, si hay terminal

    TXT);
    exit(1);
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, 'Email inválido: ' . $email . PHP_EOL);
    exit(1);
}
if (mb_strlen($name) > 100) {
    fwrite(STDERR, 'El nombre no puede superar los 100 caracteres.' . PHP_EOL);
    exit(1);
}
if (mb_strlen($email) > 190) {
    fwrite(STDERR, 'El email no puede superar los 190 caracteres.' . PHP_EOL);
    exit(1);
}

/* ---- Contraseña: de env, de stdin o de prompt sin eco. Sin argumentos. ---- */

// `?:` y `??` juntos sin paréntesis dependen del orden de precedencia (y PHP 8
// los cambió al respecto de PHP 7). Los paréntesis son el contrato.
$password = (string) (getenv('ADMIN_PASSWORD') ?: Env::get('ADMIN_PASSWORD', ''));

if ($passwordStdin) {
    $in = stream_get_contents(STDIN);
    $password = $in === false ? '' : trim($in);
} elseif ($password === '' && function_exists('posix_isatty') && posix_isatty(STDIN)) {
    fwrite(STDOUT, 'Contraseña del admin: ');
    // Sin eco: si se escribiera en claro, quedaría en la terminal y en su
    // historial de shell (read -rs no existe en PHP, se usa stty).
    @shell_exec('stty -echo 2>/dev/null');
    $password = trim((string) fgets(STDIN));
    @shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, PHP_EOL);

    fwrite(STDOUT, 'Repite la contraseña: ');
    @shell_exec('stty -echo 2>/dev/null');
    $password2 = trim((string) fgets(STDIN));
    @shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, PHP_EOL);

    if ($password !== $password2) {
        fwrite(STDERR, 'Las contraseñas no coinciden.' . PHP_EOL);
        exit(1);
    }
}

if ($password === '') {
    fwrite(STDERR, <<<TXT
    Falta la contraseña. Formas de darla (nunca como argumento, que queda en el
    historial del shell y en la lista de procesos):

        ADMIN_PASSWORD=una-larga php scripts/create-admin.php
        php scripts/create-admin.php --password-stdin < secreto.txt
        php scripts/create-admin.php            # prompt sin eco, si hay terminal

    TXT);
    exit(1);
}

if (strlen($password) < PASSWORD_MIN) {
    fwrite(STDERR, 'La contraseña debe tener al menos ' . PASSWORD_MIN . ' caracteres.' . PHP_EOL);
    exit(1);
}

$config = Config::all();

try {
    $db = Database::getInstance($config['database']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error de conexión: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$adminRole = $db->fetch('SELECT id FROM roles WHERE name = :n', ['n' => 'admin']);
if ($adminRole === null) {
    fwrite(STDERR, "Falta el rol 'admin'. Aplica primero: mysql < database/seed.sql" . PHP_EOL);
    exit(1);
}

$adminsActuales = (int) $db->fetch('SELECT COUNT(*) AS c FROM users WHERE role_id = :r', ['r' => (int) $adminRole['id']])['c'];
if ($adminsActuales > 0 && !$force) {
    fwrite(STDERR, <<<TXT
    Ya hay {$adminsActuales} administrador(es). No se crea otro por si acaso.

    Si de verdad quieres añadirlo o cambiar una cuenta existente:

        php scripts/create-admin.php --force --email=...

    TXT);
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$existente = $db->fetch('SELECT id FROM users WHERE email = :e', ['e' => $email]);

if ($existente !== null) {
    $db->execute(
        'UPDATE users SET role_id = :role, name = :name, password_hash = :hash, is_active = 1 WHERE id = :id',
        ['role' => (int) $adminRole['id'], 'name' => $name, 'hash' => $hash, 'id' => (int) $existente['id']]
    );
    echo 'Actualizada la cuenta ' . $email . ' (admin).' . PHP_EOL;
    exit(0);
}

$db->execute(
    'INSERT INTO users (role_id, name, email, password_hash, is_active)
     VALUES (:role, :name, :email, :hash, 1)',
    ['role' => (int) $adminRole['id'], 'name' => $name, 'email' => $email, 'hash' => $hash]
);

echo 'Creada la cuenta ' . $email . ' (admin, id ' . $db->lastInsertId() . ').' . PHP_EOL;
