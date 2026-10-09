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
     * El registro público es opt-in (REGISTRATION_ENABLED, default false).
     *
     * Se comprueban las DOS puertas, no solo el formulario: apagar el GET y
     * dejar vivo el POST deja un endpoint que responde «token inválido» en vez
     * de «aquí no hay registro», que es la respuesta que hace perder el rato a
     * quien depura. El comportamiento (403 en las dos rutas) lo prueba
     * `ControllerActionsTest::testElRegistroApagadoCierraGetYPost`; aquí se
     * vigila que el gate exista en los dos métodos.
     */
    public function testElRegistroPublicoEstaApagadoPorDefectoYCierraLasDosPuertas(): void
    {
        $src = (string) file_get_contents($this->path('config/config.php'));
        assertTrue(
            str_contains($src, "'REGISTRATION_ENABLED', false"),
            'el default tiene que ser false: en una instalación self-hosted el alta la hace el admin, '
                . 'y con true cualquiera que llegue a /register se crea su propia cuenta'
        );

        $controller = (string) file_get_contents($this->path('app/Controllers/AuthController.php'));
        foreach (['showRegister', 'register'] as $accion) {
            $cuerpo = $this->cuerpoDelMetodo($controller, $accion);
            assertTrue(
                str_contains($cuerpo, 'registrationEnabled()'),
                'el gate tiene que estar en ' . $accion . '(): comprobarlo solo en el GET deja el POST '
                    . 'creando cuentas con la puerta cerrada'
            );
        }
    }

    public function testElLoginNoOfreceRegistroSiEstaApagado(): void
    {
        $login = (string) file_get_contents($this->path('views/auth/login.php'));
        assertTrue(
            str_contains($login, 'registrationEnabled()'),
            'el enlace «Regístrate aquí» tiene que depender del interruptor RESUELTO: '
                . 'leer `Config::get(\'app.registration_enabled\')` ignora la fila de `app_settings`, '
                . 'así que activar el registro desde /admin dejaba el login sin enlace'
        );
        assertFalse(
            str_contains($login, "Config::get('app.registration_enabled'"),
            'y no puede volver a leer el `.env` directo: la fila del panel gana'
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

    /* ---------- 2 bis. El alta por panel comparte reglas con el alta pública ---------- */

    /**
     * Con el registro público cerrado, `AdminController::createUser()` es la
     * única vía para crear un `user` o un `editor`
     * (`scripts/create-admin.php` solo hace administradores). Por eso sus reglas
     * NO pueden ser una copia: si el alta por panel aceptara una contraseña más
     * corta, existiría una cuenta creada por un admin que el registro público
     * rechazaría, y el mismo número estaría en tres sitios.
     *
     * Se ata el mecanismo, no el mensaje: las dos acciones tienen que llamar a
     * `AuthController::validateNewUser()` y ninguna a validar por su cuenta.
     */
    public function testElAltaPorPanelUsaLasMismasReglasQueElRegistroPublico(): void
    {
        $admin = (string) file_get_contents($this->path('app/Controllers/AdminController.php'));
        $auth = (string) file_get_contents($this->path('app/Controllers/AuthController.php'));

        assertTrue(
            str_contains($auth, 'public static function validateNewUser('),
            'las reglas de alta tienen que vivir en un método compartido y público: '
                . 'AuthController::PASSWORD_MIN ya se exporta por el mismo motivo.'
        );

        $cuerpo = $this->cuerpoDelMetodo($admin, 'createUser');
        assertTrue(
            str_contains($cuerpo, 'AuthController::validateNewUser('),
            'createUser() tiene que validar con AuthController::validateNewUser(): '
                . 'una copia de las reglas divergiría en silencio.'
        );

        // Y al revés: el alta pública no puede quedarse con su copia inline.
        $registro = $this->cuerpoDelMetodo($auth, 'register');
        assertTrue(
            str_contains($registro, 'self::validateNewUser('),
            'register() tiene que pasar por el mismo método que el alta por panel.'
        );

        // La constante no puede volver a tener un número escrito a mano en el
        // alta por panel: es el fallo que este test ya cazó una vez.
        assertFalse(
            (bool) preg_match('/strlen\(\$password\)\s*<\s*10/', $cuerpo),
            'createUser() no debe comprobar la longitud contra un 10 literal.'
        );
    }

    /**
     * El interruptor del registro público no gobierna el alta por panel, y esa
     * independencia es lo que hace que servir de camino a las cuentas. Si
     * alguien metiera el gate dentro de `createUser()`, el panel se quedaría sin
     * forma de dar de alta a nadie con la puerta cerrada, que es justo el
     * escenario para el que existe.
     */
    public function testElAltaPorPanelNoDependeDelInterruptorDeRegistro(): void
    {
        $admin = (string) file_get_contents($this->path('app/Controllers/AdminController.php'));
        $cuerpo = $this->cuerpoDelMetodo($admin, 'createUser');

        assertFalse(
            str_contains($cuerpo, 'registration_enabled') || str_contains($cuerpo, 'registrationEnabled'),
            'createUser() no debe mirar el interruptor del registro público: con la '
                . 'puerta cerrada tiene que seguir creando cuentas.'
        );
    }

    /**
     * El email ya ocupado aquí SÍ se puede nombrar, al revés que en el registro
     * público. No es una inconsistencia: el mensaje uniforme protege contra que
     * alguien sondee el registro público, y aquí quien pregunta ya tiene
     * `admin.users`. Lo que no puede pasar es que el alta por panel herede el
     * rate limit, que está pensado para una IP anónima y solo estorbaría.
     */
    public function testElAltaPorPanelDiceElEmailYaOcupadoYNoHeredaElRateLimit(): void
    {
        $admin = (string) file_get_contents($this->path('app/Controllers/AdminController.php'));
        $cuerpo = $this->cuerpoDelMetodo($admin, 'createUser');

        assertTrue(
            str_contains($cuerpo, 'Ya hay una cuenta con ese email.'),
            'el alta por panel puede nombrar el conflicto: quien pregunta ya tiene '
                . 'el permiso admin.users, y un «algo falló» le haría buscar un email libre a ciegas.'
        );
        assertFalse(
            str_contains($cuerpo, 'RateLimiter'),
            'createUser() no debe usar RateLimiter: sus límites son para una IP anónima '
                . 'y aquí solo permitiría que el propio admin se bloquee a sí mismo.'
        );
    }

    /**
     * El `role_id` del POST se contrasta con la tabla `roles`. Aceptarlo sin
     * comprobar crearía una cuenta sin rol interpretable, en la que
     * `Auth::can()` devuelve false para todo lo suyo sin decir nada: el síntoma
     * sería «el usuario no ve nada», sin ningún error en ningún sitio.
     */
    public function testElAltaPorPanelValidaElRolContraLaTabla(): void
    {
        $admin = (string) file_get_contents($this->path('app/Controllers/AdminController.php'));
        $cuerpo = $this->cuerpoDelMetodo($admin, 'createUser');

        assertTrue(
            str_contains($cuerpo, 'Role::find($roleId)'),
            'el rol del POST tiene que validarse contra la tabla, no contra un id suelto.'
        );
        assertTrue(
            str_contains($cuerpo, 'El rol indicado no existe.'),
            'un rol inválido necesita un mensaje: si no, la cuenta se crearía igual y '
                . 'el fallo aparecería como «no ve nada».'
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

    /**
     * Cuerpo de un método por nombre, del archivo entero.
     *
     * Corta en la llave de cierre que corresponde al método, contando llaves y
     * teniendo en cuenta cadenas y comentarios: buscar el primer `    }` a ojo
     * se lleva por delante los `if` de dentro del método y el test pasa sin
     * estar probando lo que dice.
     */
    private function cuerpoDelMetodo(string $src, string $metodo): string
    {
        $inicio = strpos($src, 'function ' . $metodo . '(');
        assertNotNull($inicio === false ? null : $inicio, 'no se encontró el método ' . $metodo);

        $llaves = 0;
        $abrio = false;
        $enComilla = null;
        for ($i = $inicio; $i < strlen($src); $i++) {
            $ch = $src[$i];
            if ($enComilla !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $enComilla) {
                    $enComilla = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $enComilla = $ch;
                continue;
            }
            if ($ch === '/' && $i + 1 < strlen($src) && $src[$i + 1] === '/') {
                $fin = strpos($src, "\n", $i);
                $i = $fin === false ? strlen($src) : $fin;
                continue;
            }
            if ($ch === '{') {
                $llaves++;
                $abrio = true;
                continue;
            }
            if ($ch === '}') {
                $llaves--;
                if ($abrio && $llaves === 0) {
                    return substr($src, $inicio, $i - $inicio + 1);
                }
            }
        }

        return '';
    }
}
