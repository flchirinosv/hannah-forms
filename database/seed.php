<?php

declare(strict_types=1);

/**
 * Seed — Usuarios demo de VanillaForms, DETRÁS DE UN OPT-IN.
 *
 * Por qué este archivo existe aparte de `database/seed.sql` (que solo crea los
 * roles): el seed metía tres usuarios con la MISMA contraseña publicada en el
 * repositorio, uno de ellos con rol admin. Cualquiera que desplegara la app y
 * ejecutara el seed se encontraba un admin con contraseña `password123`, y el
 * propio archivo del repositorio se la entregaba. Con el registro abierto de
 * la app, eso no es un detalle: es una puerta.
 *
 * La puerta se cierra con `SEED_DEMO=1` en `.env`, que es deliberadamente
 * INVERSO a lo que se espera: por defecto NO se siembran usuarios. Sin el
 * opt-in, el script dice que no hace nada y sale con código 1, para que un
 * despliegue que se lo encuentre en la tubería se note.
 *
 * Uso:
 *   SEED_DEMO=1 php database/seed.php
 *   SEED_DEMO=1 SEED_DEMO_PASSWORD=otra-cosa php database/seed.php
 *
 * Idempotente: se puede repetir, y con `--fresh` resetea la contraseña de los
 * usuarios demo que ya existieran.
 *
 * Por qué PHP y no SQL: el hash se genera aquí con `password_hash()` en vez de
 * llevar un bcrypt escrito en el archivo. Un hash en el repo es una contraseña
 * conocida aunque el script esté protegido por un opt-in.
 */

require_once __DIR__ . '/../config/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

Env::load(dirname(__DIR__) . '/.env');

// La clave del opt-in se lee del entorno real del proceso primero: así se puede
// sembrar sin ensuciar el .env (`SEED_DEMO=1 php database/seed.php`).
$optIn = getenv('SEED_DEMO') !== false ? getenv('SEED_DEMO') : Env::get('SEED_DEMO', false);
$optIn = filter_var($optIn, FILTER_VALIDATE_BOOLEAN);

if (!$optIn) {
    fwrite(STDERR, <<<TXT
    Seed de usuarios demo DESACTIVADO (no se ha creado nada).

    Los usuarios demo comparten una contraseña publicada, y uno es admin, así que
    no se siembran salvo que lo pidas explícitamente:

        SEED_DEMO=1 php database/seed.php

    Para cambiar la contraseña del seed:

        SEED_DEMO=1 SEED_DEMO_PASSWORD=una-que-no-sea-pública php database/seed.php

    Los ROLES sí se crean siempre, y eso lo hace database/seed.sql (sin
    credenciales). Para una instalación de producción, crea el primer admin a
    mano: php scripts/create-admin.php

    TXT);
    exit(1);
}

$password = (string) (getenv('SEED_DEMO_PASSWORD') ?: Env::get('SEED_DEMO_PASSWORD', 'password123'));

if (strlen($password) < 10) {
    fwrite(STDERR, 'SEED_DEMO_PASSWORD debe tener al menos 10 caracteres.' . PHP_EOL);
    exit(1);
}

$config = Config::all();

try {
    $db = Database::getInstance($config['database']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error de conexión: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// El seed de demo necesita los roles: si alguien lo corre contra una BD donde
// solo pasó schema.sql, avisamos en vez de fallar con una FK.
$roles = [];
foreach ($db->fetchAll('SELECT id, name FROM roles') as $r) {
    $roles[(string) $r['name']] = (int) $r['id'];
}
foreach (['admin', 'user', 'editor'] as $need) {
    if (!isset($roles[$need])) {
        fwrite(STDERR, "Falta el rol '{$need}'. Aplica primero: mysql < database/seed.sql" . PHP_EOL);
        exit(1);
    }
}

$usuarios = [
    ['role' => 'admin',  'name' => 'Admin Demo',     'email' => 'admin@demo.com'],
    ['role' => 'user',   'name' => 'Usuario Normal', 'email' => 'user@demo.com'],
    ['role' => 'editor', 'name' => 'Editor Demo',    'email' => 'editor@demo.com'],
];

// Un mismo hash para los tres: solo hay que calcular el bcrypt una vez (y es
// el mismo trabajo que hace login en cada intento, que es de lo que va el coste).
$hash = password_hash($password, PASSWORD_DEFAULT);

$fresh = in_array('--fresh', $argv, true);

$db->beginTransaction();
try {
    foreach ($usuarios as $u) {
        $existe = $db->fetch('SELECT id FROM users WHERE email = :email', ['email' => $u['email']]);

        if ($existe !== null) {
            if (!$fresh) {
                echo "  (ya existe) {$u['email']}" . PHP_EOL;
                continue;
            }
            $db->execute(
                'UPDATE users SET role_id = :role, name = :name, password_hash = :hash WHERE id = :id',
                ['role' => $roles[$u['role']], 'name' => $u['name'], 'hash' => $hash, 'id' => (int) $existe['id']]
            );
            echo "  (actualizado) {$u['email']}" . PHP_EOL;
            continue;
        }

        $db->execute(
            'INSERT INTO users (role_id, name, email, password_hash, is_active)
             VALUES (:role, :name, :email, :hash, 1)',
            ['role' => $roles[$u['role']], 'name' => $u['name'], 'email' => $u['email'], 'hash' => $hash]
        );
        echo "  (creado) {$u['email']} [{$u['role']}]" . PHP_EOL;
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'Error al sembrar: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo PHP_EOL;
echo 'Usuarios demo listos. Contraseña: la de SEED_DEMO_PASSWORD' . PHP_EOL;

if ($password === 'password123') {
    echo PHP_EOL;
    echo 'AVISO: la contraseña por defecto es pública (está en el repositorio).' . PHP_EOL;
    echo 'No dejes estos usuarios en una instancia expuesta; bórralos o cambia' . PHP_EOL;
    echo 'la contraseña con SEED_DEMO_PASSWORD antes de desplegar.' . PHP_EOL;
}
