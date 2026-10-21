<?php

declare(strict_types=1);

/**
 * generate-key — Genera una APP_KEY para cifrar los secretos de la instancia.
 *
 * Uso:
 *   php scripts/generate-key.php            # imprime la línea para pegar en .env
 *   php scripts/generate-key.php --write    # la escribe en .env (si no existe)
 *
 * Por qué existe: al mover la contraseña SMTP (y otros secretos) a la base de
 * datos para poder editarlos desde /admin, hay que cifrarlos para que robar un
 * backup de la BD no los revele. La llave vive en .env, NUNCA en la BD.
 *
 * Decisiones:
 *  - Con `--write` NO sobreescribe una APP_KEY existente con valor: sobreescribir
 *    la llave dejaría los secretos ya guardados indescifrables. Si ya hay una, se
 *    avisa y se sale sin tocar nada.
 *  - La llave no se imprime como «secreto» en logs: sale por STDOUT porque la
 *    está ejecutando quien administra la instalación y la necesita para pegarla.
 */

require_once __DIR__ . '/../config/autoload.php';

use App\Core\Secret;

$write = in_array('--write', array_slice($argv, 1), true);

$key = Secret::generateKey();

if (!$write) {
    echo 'APP_KEY=' . $key . PHP_EOL;
    echo PHP_EOL;
    echo 'Pega esa línea en tu .env (o ejecuta: php scripts/generate-key.php --write).' . PHP_EOL;
    exit(0);
}

$envPath = dirname(__DIR__) . '/.env';
if (!is_file($envPath)) {
    fwrite(STDERR, 'No existe .env. Copia .env.example a .env primero.' . PHP_EOL);
    exit(1);
}

$contents = (string) file_get_contents($envPath);

// Si ya hay una APP_KEY con valor, no la pisamos: cambiar la llave invalida los
// secretos ya cifrados con la anterior.
if (preg_match('/^APP_KEY=(.+)$/m', $contents, $m) === 1 && trim($m[1]) !== '') {
    fwrite(STDERR, 'Ya hay una APP_KEY con valor en .env. No se sobreescribe:' . PHP_EOL);
    fwrite(STDERR, 'cambiarla dejaría indescifrables los secretos ya guardados.' . PHP_EOL);
    exit(1);
}

if (preg_match('/^APP_KEY=.*$/m', $contents) === 1) {
    $contents = (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents, 1);
} else {
    $contents = rtrim($contents) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL;
}

if (file_put_contents($envPath, $contents) === false) {
    fwrite(STDERR, 'No se pudo escribir .env (¿permisos?). Pégala a mano:' . PHP_EOL);
    echo 'APP_KEY=' . $key . PHP_EOL;
    exit(1);
}

echo 'APP_KEY escrita en .env.' . PHP_EOL;
