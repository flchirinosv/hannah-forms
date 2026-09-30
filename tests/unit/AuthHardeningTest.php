<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Controllers\AuthController;
use App\Models\User;

/**
 * Fase 2, item 2.7 — login: oráculo de temporización, política de contraseña,
 * rehash oportunista y cookie de sesión.
 *
 * El oráculo era el más silencioso de los tres: el login hacía
 * `$user === null || !password_verify(...)`, y el `||` cortocircuitado se saltaba
 * la llamada a bcrypt cuando el email no existía. Medido en vivo sobre esta
 * máquina: **0.016 s con email inexistente frente a 0.33 s con email existente**
 * (un factor 20), o sea que el tiempo de respuesta enumeraba qué emails estaban
 * dados de alta. El arreglo es verificar siempre una contraseña, contra el hash
 * del usuario o contra un hash señuelo, para que ambos caminos hagan el mismo
 * trabajo. Aquí se comprueba que el señuelo es un bcrypt real de PASSWORD_DEFAULT
 * y no un hash inventado o más barato, que devolvería antes y volvería a
 * delatar la diferencia.
 */
class AuthHardeningTest
{
    /* ---------- 1. Oráculo de temporización ---------- */

    /**
     * El hash señuelo tiene que costar lo mismo que uno de verdad. Si fuese un
     * hash más barato, o un string que `password_verify` no puede verificar,
     * el caso "usuario inexistente" volvería a ser más rápido y el arreglo
     * solo ocultaría el síntoma.
     */
    public function testElHashSenueloEsBcryptRealDelMismoCoste(): void
    {
        $const = (new \ReflectionClass(AuthController::class))->getConstant('DUMMY_HASH');
        assertNotNull($const, 'debe existir el hash señuelo');
        assertTrue(is_string($const) && $const !== '', 'el hash señuelo debe ser un string');

        $info = password_get_info($const);
        assertEquals('bcrypt', $info['algoName'], 'el señuelo debe ser bcrypt, como PASSWORD_DEFAULT');
        assertEquals(
            password_get_info(password_hash('x', PASSWORD_DEFAULT))['options']['cost'],
            (int) ($info['options']['cost'] ?? 0),
            'el señuelo debe tener el mismo coste que genera PASSWORD_DEFAULT'
        );

        assertFalse(
            password_verify('lo-que-sea', $const),
            'el señuelo no puede validar ninguna contraseña'
        );
    }

    /**
     * Y el coste medido de los dos caminos tiene que ser el mismo orden de
     * magnitud. No se compara con un umbral absoluto (el CI es más lento que
     * esta máquina) sino la razón entre ambos, que es justo lo que delata el
     * email: si el caso inexistente fuera varias veces más rápido, el oráculo
     * sigue vivo aunque ahora tarde 100 ms en vez de 10.
     */
    public function testVerificarCuestaLoMismoConYSinUsuario(): void
    {
        $const = (string) (new \ReflectionClass(AuthController::class))->getConstant('DUMMY_HASH');
        $real = password_hash('la-contrasena-del-usuario', PASSWORD_DEFAULT);

        $t0 = microtime(true);
        password_verify('incorrecta', $real);
        $conHash = microtime(true) - $t0;

        $t0 = microtime(true);
        password_verify('incorrecta', $const);
        $conSenuelo = microtime(true) - $t0;

        // El señuelo no puede ser una fracción del coste real.
        assertTrue(
            $conSenuelo > 0.5 * $conHash,
            sprintf(
                'el hash señuelo debe costar como el real (señal %.4fs vs %.4fs): si se devuelve antes, el '
                . 'tiempo vuelve a delatar si el email existe',
                $conSenuelo,
                $conHash
            )
        );
    }

    /**
     * El código del login tiene que verificar SIEMPRE. Un `||` cortocircuitado
     * (o un `if` que solo verifique cuando `$user !== null`) devuelve a existir
     * el oráculo sin que ningún test de comportamiento lo note, porque el
     * resultado final es el mismo: "credenciales incorrectas".
     */
    public function testElLoginNoTieneCortoCircuitoDePasswordVerify(): void
    {
        $src = (string) file_get_contents($this->path('app/Controllers/AuthController.php'));

        // El patrón viejo, tal cual.
        assertFalse(
            str_contains($src, '$user === null || !password_verify'),
            'no debe volver el cortocircuito: el caso "no existe" se saltaría el bcrypt'
        );
        assertFalse(
            preg_match('/if\s*\(\s*\$user\s*===\s*null\s*\)\s*\{[^}]*password_verify/s', $src) === 1,
            'password_verify no puede quedar dentro de una rama que solo corre si el usuario existe'
        );

        // Y el señuelo tiene que estar en la expresión de decisión, no suelto.
        assertTrue(
            str_contains($src, 'self::DUMMY_HASH'),
            'el login debe verificar contra el hash señuelo cuando no hay usuario'
        );
    }

    /* ---------- 2. Política de contraseña ---------- */

    public function testElMinimoDeContrasenaEsAlMenos10(): void
    {
        $min = (new \ReflectionClass(AuthController::class))->getConstant('PASSWORD_MIN');
        assertNotNull($min, 'debe haber una constante de mínimo, no un número suelto');
        assertTrue((int) $min >= 10, 'el mínimo debe ser de 10 caracteres o más, es ' . $min);

        // Y la vista tiene que decir lo mismo que el servidor, o el usuario
        // escribe 8 y el navegador se lo acepta y el servidor lo rechaza.
        // Se comprueba que la vista INTERPOLE el valor que le pasa el
        // controlador, y no que por casualidad hoy coincidan: un
        // `minlength="10"` a mano cumplo este test y se rompía en el
        // instante en que PASSWORD_MIN dejara de ser 10.
        $view = (string) file_get_contents($this->path('views/auth/register.php'));
        assertTrue(
            str_contains($view, 'minlength="<?= (int) $min ?>'),
            'el minlength del formulario debe venir de PASSWORD_MIN'
        );
        assertFalse(
            preg_match('/minlength="\d+"/', $view) === 1,
            'no debe quedar un minlength con un número fijo: el mínimo vive en AuthController::PASSWORD_MIN'
        );
        assertFalse(
            str_contains($view, 'Mínimo 6 caracteres'),
            'no debe quedar el texto del mínimo antiguo'
        );
    }

    /**
     * El mínimo de 10 tiene que caber con la contraseña del seed
     * (`password123`, 11 caracteres). Con 12 el admin demo se quedaba fuera de
     * su propia contraseña documentada, así que el valor está atado a esto.
     */
    public function testElMinimoNoRompeLaContrasenaDelSeed(): void
    {
        $min = (int) (new \ReflectionClass(AuthController::class))->getConstant('PASSWORD_MIN');
        assertTrue(
            strlen('password123') >= $min,
            'la contraseña del seed debe pasar el mínimo, o el seed deja de servir para iniciar sesión'
        );
    }

    /* ---------- 3. Rehash oportunista ---------- */

    /**
     * Sin flujo de "cambiar contraseña", el rehash en el login es la única vía de
     * migración de hash. Se prueba sobre la BD real: una cuenta con coste
     * bajo, un login correcto, y el hash debe quedar al coste actual.
     */
    public function testElLoginRehasheaUnHashViejo(): void
    {
        $email = 'rehash-' . bin2hex(random_bytes(4)) . '@test.local';
        $plain = 'contrasena-larga-2026';
        $id = User::create('Rehash', $email, $plain, 2);
        assertNotNull($id, 'debe poder crearse el usuario de prueba');

        try {
            // Se degrada el hash a un coste viejo, como si la cuenta llevara
            // años guardada con los parámetros de entonces.
            $db = \App\Core\Database::getInstance(Config::all()['database']);
            $old = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 4]);
            $db->execute('UPDATE users SET password_hash = :h WHERE id = :id', ['h' => $old, 'id' => (int) $id]);
            assertTrue(password_needs_rehash($old, PASSWORD_DEFAULT), 'coste 4 debe considerarse viejo');

            // El login: se ejercita el método real del controlador. En CLI no hay
            // petición, así que se inyecta lo que leería (`Request::post()`
            // lee `$_POST` a secas) con un token de CSRF válido en sesión; si no
            // se hiciera, el controlador saldría por el flash de error y el
            // rehash no se ejecutaría nunca —el test pasaría sin probar nada.
            $post = $_POST;
            try {
                $_POST = [
                    '_token'    => Csrf::token(),
                    'email'     => $email,
                    'password'  => $plain,
                ];
                RateLimiter::reset('login', Request::ip());
                RateLimiter::reset('login_email', hash('sha256', strtolower($email)));

                (new AuthController())->login();

                $after = User::findByEmail($email);
                assertNotNull($after, 'el usuario debe seguir existiendo');
                assertFalse(
                    password_needs_rehash((string) $after['password_hash'], PASSWORD_DEFAULT),
                    'tras un login correcto el hash debe estar al coste actual'
                );
                assertTrue(
                    password_verify($plain, (string) $after['password_hash']),
                    'el rehash no puede cambiar la contraseña'
                );
            } finally {
                $_POST = $post;
                Auth::logout();
            }
        } finally {
            $db = \App\Core\Database::getInstance(Config::all()['database']);
            $db->execute('DELETE FROM users WHERE email = :e', ['e' => $email]);
        }
    }

    public function testUpdatePasswordHashNoGuardaLaClaveEnClaro(): void
    {
        $src = (string) file_get_contents($this->path('app/Models/User.php'));
        assertTrue(
            str_contains($src, 'password_hash('),
            'el modelo debe hashear con password_hash()'
        );
        assertFalse(
            preg_match('/function\s+updatePasswordHash[^}]*\$plainPassword[^}]*\)\s*;/s', $src) === 1
            && !str_contains($src, 'password_hash($plainPassword'),
            'updatePasswordHash no puede guardar la contraseña sin pasar por password_hash()'
        );
    }

    /* ---------- 4. Cookie de sesión ---------- */

    /**
     * Sin `use_only_cookies`, PHP acepta un identificador de sesión que venga en
     * la URL (?SID=) y lo fija: permite robar una sesión mandando a la víctima
     * un enlace. `use_strict_mode` (que ya estaba) solo cubre el caso de un ID
     * no inicializado, no este.
     */
    public function testLaSesionNoAceptaElIdentificadorPorUrl(): void
    {
        $src = (string) file_get_contents($this->path('app/Core/Session.php'));
        assertTrue(
            str_contains($src, "ini_set('session.use_only_cookies', '1')"),
            'Session::start() debe fijar session.use_only_cookies'
        );
        assertTrue(
            str_contains($src, "ini_set('session.use_strict_mode', '1')"),
            'use_strict_mode debe seguir puesto'
        );
        // Y no basta con un default global: tiene que fijarse antes de
        // session_start(), que es donde se lee.
        $beforeStart = strpos($src, 'use_only_cookies') < strpos($src, 'session_start()');
        assertTrue($beforeStart, 'use_only_cookies tiene que fijarse ANTES de session_start()');
    }

    /* ---------- Utilidades ---------- */

    private function path(string $rel): string
    {
        return dirname(__DIR__, 2) . '/' . $rel;
    }
}
