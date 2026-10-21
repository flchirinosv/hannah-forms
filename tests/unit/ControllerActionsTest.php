<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppSettings;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;

/**
 * Smoke de TODAS las rutas de config/routes.php (fase 4, cobertura de
 * controllers).
 *
 * Cada ruta se despacha por el Router REAL (middleware incluido) y se exige que
 * el controlador RESPONDA: un halt registrado (redirect/abort/json/...) o
 * salida renderizada. El fallo que caza este test es el controlador que corre y
 * "se cae en silencio" — alcanzar el final sin haber salido ni cortado, que con
 * el `return;` de la fase 4 ya no dice nada si no hay respuesta visible.
 *
 * NOTA: no extiende la cobertura de cada controller (eso lo hacen los tests
 * temáticos); asegura que el cableado entero —ruta → middleware → firma del
 * método → respuesta— no reviente con TypeError ni deje endpoints mudos.
 */
class ControllerActionsTest
{
    private static bool $cleanupHookRegistered = false;
    private static ?int $adminId = null;
    private static ?int $otherId = null;
    private static int $formId = 0;
    private static int $fieldId = 0;
    private static int $submissionId = 0;
    private static string $adminEmail = '';
    private static string $slug = '';

    /**
     * Filas de `app_settings` tal y como estaban ANTES del smoke.
     *
     * El smoke recorre `POST /admin/settings/registration` y también
     * `POST /admin/settings/review`, y esas acciones escriben en `app_settings`
     * de verdad: es una tabla de la INSTALACIÓN, no un fixture. Sin guardar el
     * valor previo y devolverlo en `cleanupResiduals()`, una corrida de tests
     * dejaría el registro o la revisión de esta instancia al revés.
     *
     * @var array<string, array{exists: bool, value: ?string}>
     */
    private static array $savedSettings = [];

    /** Claves de `app_settings` que el smoke escribe y hay que devolver. */
    private const APP_SETTINGS_KEYS = ['registration_enabled', 'review_required', 'review_notify_email', 'instance_name', 'instance_color', 'instance_logo', 'instance_favicon', 'mail_host', 'mail_port', 'mail_encryption', 'mail_user', 'mail_password', 'mail_from_address', 'mail_from_name', 'mail_timeout', 'instance_public_url', 'instance_timezone', 'instance_locale', 'instance_turnstile_site_key', 'instance_turnstile_secret_key'];

    public function setUp(): void
    {
        self::ensureCleanupHook();

        try {
            $db = Database::getInstance(Config::db());
            $db->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::cleanupResiduals();

        // /register está CERRADO por defecto (REGISTRATION_ENABLED=false): sin
        // esto el smoke recorrería las dos rutas del registro y solo comprobaría
        // un 403, que es la mitad fácil. Con el registro abierto, el POST de
        // humo llega de verdad al alta de cuenta.
        Config::setForTesting('app.registration_enabled', true);

        // La fila de `app_settings` GANA sobre ese override (es lo que significa
        // «el panel ha tocado este ajuste»), así que para que el smoke vea el
        // estado que dice el `.env` hay que neutralizarla mientras corre.
        AppSettings::setForTesting([]);
        self::snapshotSettings($db);

        self::$adminEmail = 'smoke-' . bin2hex(random_bytes(3)) . '@test.local';
        self::$adminId = User::create('Smoke Admin', self::$adminEmail, 'clave-smoke-123', 1);
        // Usuario objetivo para /admin/users/* (no degradar/desactivar al propio admin).
        self::$otherId = (int) User::create('Smoke Otro', 'smoke-b-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-smoke-123', 2);
        self::ensureForm();
    }

/**
     * El alta por panel tiene que CREAR la cuenta, no solo no quejar.
     *
     * El smoke de arriba recorre la ruta, pero «no se paró con un error» también
     * lo diría de un POST que solo pintara un flash de «faltan campos». Aquí se
     * comprueba la fila en `users` y su `role_id`, que es lo que después decide
     * qué puede hacer la persona.
     *
     * Y se hace con el registro público APAGADO a propósito, que es el escenario
     * para el que existe: si esta prueba pasara solo con el registro abierto,
     * la acción podría estar heredando el gate y nadie se enteraría hasta que
     * alguien cerrara el interruptor.
     */
    public function testElAltaPorPanelCreaLaCuentaConElRegistroPublicoCerrado(): void
    {
        Config::setForTesting('app.registration_enabled', false);

        $db = Database::getInstance(Config::db());
        $email = 'smoke-alta-' . bin2hex(random_bytes(3)) . '@test.local';

        $router = new Router();
        require dirname(__DIR__, 2) . '/config/routes.php';
        $rp = new \ReflectionProperty(Router::class, 'routes');
        $rp->setAccessible(true);
        $ruta = null;
        foreach ($rp->getValue($router) as $r) {
            if ($r['path'] === '/admin/users/create' && $r['method'] === 'POST') {
                $ruta = $r;
                break;
            }
        }
        assertNotNull($ruta, 'tiene que existir la ruta POST /admin/users/create');

        // El cuerpo se pone a mano (no con `defaultPostBody`) porque aquí el
        // email es el que se va a comprobar después en la BD.
        $out = self::dispatchRouteConBody($ruta, '/admin/users/create', static function (array &$post) use ($email): void {
            $post['name'] = 'Alta Panel';
            $post['email'] = $email;
            $post['password'] = 'clave-smoke-123';
            $post['password_confirm'] = 'clave-smoke-123';
            $post['role_id'] = '2';
        });

        // Un alta correcta responde con un REDIRECT a /admin (PRG: el POST no
        // pinta nada). `lastHalt()` también registra los redirects, así que lo
        // que se comprueba es el tipo y el destino, no que no haya halt.
        $halt = $out['halt'];
        assertTrue(
            is_array($halt) && ($halt['type'] ?? '') === 'redirect' && ($halt['url'] ?? '') === '/admin',
            'el alta válida debe redirigir a /admin: ' . json_encode($halt, JSON_UNESCAPED_UNICODE)
        );

        $fila = $db->fetch('SELECT role_id, is_active FROM users WHERE email = :e', ['e' => $email]);
        assertNotNull($fila, 'la cuenta tiene que existir en users tras el alta por panel');
        assertSame(2, (int) $fila['role_id'], 'el rol elegido en el POST es el que se guarda');
        assertSame(1, (int) $fila['is_active'], 'la cuenta nace activa');

        // Y la contraseña tiene que servir para entrar: si `User::create()` no
        // la hasheara bien, el alta «funcionaría» y nadie podría iniciar sesión.
        $user = User::findByEmail($email);
        assertNotNull($user, 'no se encuentra por email');
        assertTrue(
            password_verify('clave-smoke-123', (string) $user['password_hash']),
            'la contraseña del alta por panel debe servir para iniciar sesión'
        );
    }

    /**
     * La regla compartida con el registro público se comprueba de verdad, no solo
     * en el código: una contraseña de 9 caracteres tiene que ser rechazada
     * aunque venga de un admin con permiso. Si `createUser()` validara por su
     * cuenta (o nada), esto crearía una cuenta que el registro público jamás
     * dejaría crear.
     */
    public function testElAltaPorPanelRespetaElMinimoDeContrasena(): void
    {
        $db = Database::getInstance(Config::db());
        $email = 'smoke-corta-' . bin2hex(random_bytes(3)) . '@test.local';
        $corta = '123456789'; // 9: uno por debajo de PASSWORD_MIN

        $router = new Router();
        require dirname(__DIR__, 2) . '/config/routes.php';
        $rp = new \ReflectionProperty(Router::class, 'routes');
        $rp->setAccessible(true);
        $ruta = null;
        foreach ($rp->getValue($router) as $r) {
            if ($r['path'] === '/admin/users/create' && $r['method'] === 'POST') {
                $ruta = $r;
                break;
            }
        }
        assertNotNull($ruta, 'tiene que existir la ruta POST /admin/users/create');

        self::dispatchRouteConBody($ruta, '/admin/users/create', static function (array &$post) use ($email, $corta): void {
            $post['name'] = 'Alta Corta';
            $post['email'] = $email;
            $post['password'] = $corta;
            $post['password_confirm'] = $corta;
            $post['role_id'] = '2';
        });

        assertNull(
            $db->fetch('SELECT id FROM users WHERE email = :e', ['e' => $email]),
            'una contraseña por debajo de PASSWORD_MIN no debe crear la cuenta'
        );
    }

    /**
     * El mismo helper de dispatch pero con un cuerpo POST propio, para las rutas
     * cuyo resultado se comprueba después en la BD (y no en el HTML, que en un
     * alta correcta es una redirección).
     */
    private static function dispatchRouteConBody(array $route, string $url, callable $cuerpo): array
    {
        $_SERVER['REQUEST_METHOD'] = $route['method'];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.88';
        $_SERVER['HTTP_USER_AGENT'] = 'ControllerActionsTest';
        unset($_SERVER['CONTENT_LENGTH'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        Auth::assumeLoggedIn((int) self::$adminId);
        Auth::forgetCache();

        $token = 'smoke-token';
        Session::set('_csrf_token', $token);

        $_POST = ['_token' => $token];
        $cuerpo($_POST);

        Request::setRequestBodyForTests(null);
        Request::resetJsonState();
        Response::setRecordHalt(false);
        Response::setRecordHalt(true);

        $router = new Router();
        require dirname(__DIR__, 2) . '/config/routes.php';

        ob_start();
        try {
            $router->dispatch($route['method'], $url);
        } finally {
            $html = (string) ob_get_clean();
        }

        $halt = Response::lastHalt();
        Response::setRecordHalt(false);

        return ['halt' => $halt, 'html' => $html];
    }

    /**
     * El registro apagado tiene que cerrar GET y POST por igual.
     *
     * Se usa la tabla de rutas REAL (no una ruta escrita a mano) para que el
     * test no pueda quedarse comprobando un endpoint que ya no existe. Con el
     * registro abierto, el smoke de arriba recorre las dos rutas de verdad.
     */
    public function testElRegistroApagadoCierraGetYPost(): void
    {
        Config::setForTesting('app.registration_enabled', false);
        // Y la fila de `app_settings` fuera: GANA sobre ese override (es lo que
        // significa «el panel ha tocado este ajuste»), así que un `1` que se
        // hubiera quedado de otra prueba dejaría `/register` abierto y este test
        // probaría el estado de la máquina, no el del `.env`.
        AppSettings::setForTesting([]);

        $router = new Router();
        require dirname(__DIR__, 2) . '/config/routes.php';
        $rp = new \ReflectionProperty(Router::class, 'routes');
        $rp->setAccessible(true);
        $rutas = array_values(array_filter(
            $rp->getValue($router),
            static fn (array $r): bool => $r['path'] === '/register'
        ));

        assertSame(2, count($rutas), 'deben existir las dos rutas de registro (GET y POST)');

        foreach ($rutas as $ruta) {
            $out = self::dispatchRoute($ruta, '/register');
            $html = (string) $out['html'];
            // `lastHalt()` devuelve la ÚLTIMA entrada del log, que es un array
            // ['type' => 'abort', 'code' => 403], no un entero suelto.
            $halt = $out['halt'];

            assertTrue(
                is_array($halt) && ($halt['type'] ?? '') === 'abort' && (int) ($halt['code'] ?? 0) === 403,
                $ruta['method'] . ' /register con el registro apagado debe cortar con 403, paró en '
                    . json_encode($halt, JSON_UNESCAPED_UNICODE)
            );
            assertFalse(
                str_contains($html, 'name="password_confirm"'),
                $ruta['method'] . ' /register no debe pintar el formulario de alta con la puerta cerrada'
            );
        }

        Config::forgetForTesting();
        // El override de `AppSettings` también es static: sin `forget` el
        // siguiente test leería un `null` de mentira aunque haya fila en la BD.
        AppSettings::forgetForTesting();
    }

    public function tearDown(): void
    {
        // El override de Config es global (static): sin esto, «registro
        // abierto» se lo encuentra el siguiente test que recorra /register y
        // estaría probando un estado que la instalación real no tiene.
        Config::forgetForTesting();
    }

    public function testTodasLasRutasRespondenSinErroresNiEndpointsMudos(): void
    {
        $router = new Router();
        require dirname(__DIR__, 2) . '/config/routes.php';

        $rp = new \ReflectionProperty(Router::class, 'routes');
        $rp->setAccessible(true);
        $routes = $rp->getValue($router);

        assertTrue(count($routes) >= 30, 'el smoke debe recorrer la tabla de rutas real');

        $fails = [];
        foreach ($routes as $route) {
            try {
                $url = self::concretize($route['path']);
                $out = self::dispatchRoute($route, $url);
                if ($out['halt'] === null && trim($out['html']) === '') {
                    $fails[] = $route['method'] . ' ' . $route['path'] . ' -> sin respuesta (ni halt ni salida)';
                }
            } catch (\Throwable $e) {
                $fails[] = $route['method'] . ' ' . $route['path'] . ' -> ' . get_class($e) . ': ' . $e->getMessage();
            } finally {
                self::ensureForm();
            }
        }

        assertSame([], $fails, implode("\n", $fails));
    }

    /** Ruta con {tokens} → valores de los fixtures. */
    private static function concretize(string $path): string
    {
        $map = [
            '{id}'   => (string) self::$formId,
            '{sid}'  => (string) self::$submissionId,
            '{slug}' => self::$slug,
        ];
        return strtr($path, $map);
    }

    private static function dispatchRoute(array $route, string $url): array
    {
        $_SERVER['REQUEST_METHOD'] = $route['method'];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.88';
        $_SERVER['HTTP_USER_AGENT'] = 'ControllerActionsTest';
        unset(
            $_SERVER['CONTENT_LENGTH'],
            $_SERVER['CONTENT_TYPE'],
            $_SERVER['HTTP_CONTENT_TYPE'],
            $_SERVER['HTTP_X_CSRF_TOKEN'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_X_REAL_IP']
        );

        // Estado de sesión según el middleware: guest requiere anónimo; el
        // resto de rutas (auth/admin/editor) van con un admin que las pasa.
        $mw = $route['middleware'];
        if (in_array('guest', $mw, true)) {
            Session::remove('user_id');
        } else {
            Auth::assumeLoggedIn((int) self::$adminId);
        }
        Auth::forgetCache();

        $token = 'smoke-token';
        Session::set('_csrf_token', $token);

        $_POST = [];
        if ($route['method'] === 'POST') {
            $_POST['_token'] = $token;
            self::defaultPostBody($route['path'], $_POST);
        }

        Request::setRequestBodyForTests(null);
        Request::resetJsonState();
        if (in_array('csrf-header', $mw, true)) {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
            $_SERVER['CONTENT_TYPE'] = 'application/json';
            Request::setRequestBodyForTests('{}');
        }

        Response::setRecordHalt(false);
        Response::setRecordHalt(true);

        $router = new Router();
        require dirname(__DIR__, 2) . '/config/routes.php';

        ob_start();
        try {
            $router->dispatch($route['method'], $url);
        } finally {
            $html = (string) ob_get_clean();
        }

        $halt = Response::lastHalt();
        Response::setRecordHalt(false);

        return ['halt' => $halt, 'html' => $html];
    }

    private static function defaultPostBody(string $path, array &$post): void
    {
        if ($path === '/forms') {
            $post['title'] = 'Form de humo';
        } elseif (str_contains($path, '/update')) {
            $post['title'] = 'Form actualizado';
        } elseif (str_contains($path, '/admin/users/create')) {
            // Con `role_id` explícito y un email de humo: sin ellos el POST sería
            // el "todos los campos son obligatorios", que no es el camino real.
            // El email entra en `cleanupResiduals()` por el prefijo `smoke-`.
            $post['name'] = 'Smoke Creado';
            $post['email'] = 'smoke-nuevo-' . bin2hex(random_bytes(3)) . '@test.local';
            $post['password'] = 'clave-smoke-123';
            $post['password_confirm'] = 'clave-smoke-123';
            $post['role_id'] = '2';
        } elseif (str_contains($path, '/admin/users/role')) {
            $post['user_id'] = (string) (int) self::$otherId;
            $post['role_id'] = '2';
        } elseif (str_contains($path, '/admin/users/toggle')) {
            $post['user_id'] = (string) (int) self::$otherId;
        } elseif (str_contains($path, '/admin/users/groups')) {
            // Sin `groups[]` el POST es la pertenencia COMPLETA (N:M), o sea
            // "sácalo de todos los grupos": con un body vacío el smoke
            // comprobaría el POST vacío y no el camino que de verdad usa la UI.
            $post['user_id'] = (string) (int) self::$otherId;
        } elseif (str_contains($path, '/admin/users/permissions')) {
            // Una clave real del catálogo: sin `permissions[]` el smoke probaría
            // el camino «quitar todos», que también es válido pero no el que
            // usa la UI al conceder.
            $post['user_id'] = (string) (int) self::$otherId;
            $post['permissions'] = ['logs.view'];
        } elseif ($path === '/admin/settings/registration') {
            // Sin `enabled` el POST sería el camino de «cerrar», que también
            // escribe; se manda explícito para que el smoke ejercite el
            // `INSERT ... ON DUPLICATE KEY` (la fila no existe todavía).
            $post['enabled'] = '1';
        } elseif ($path === '/admin/settings/review') {
            // Los dos interruptores explícitos: sin ellos el smoke probaría el
            // camino de apagar, y las filas de la revisión se devuelven en
            // `cleanupResiduals()`.
            $post['review_required'] = '1';
            $post['review_notify_email'] = '0';
        } elseif ($path === '/admin/settings/instance') {
            // Valores válidos explícitos: sin ellos el POST probaría el camino
            // de «vaciar», que también borra filas. Las tres se devuelven en
            // `cleanupResiduals()`.
            $post['instance_public_url'] = 'https://humo.test.local';
            $post['instance_timezone'] = 'America/Caracas';
            $post['instance_locale'] = 'es';
        } elseif ($path === '/admin/settings/turnstile') {
            // Coherencia: las dos claves o ninguna. El smoke no tiene por qué
            // tener APP_KEY para cifrar una secret, así que limpia las dos
            // (vaciar la site key + borrar la secret deja el estado coherente y
            // ejercita el camino de escritura sin depender del cifrado). Las
            // filas se devuelven en `cleanupResiduals()`.
            $post['turnstile_site_key'] = '';
            $post['clear_secret'] = '1';
        } elseif (str_contains($path, '/admin/roles/create')) {
            // El nombre lleva un prefijo reconocible porque `cleanupResiduals()`
            // lo borra: `roles` no tiene created_at ni opt-in de demo, y un rol
            // por ejecución se acumularía en la matriz de cada corrida.
            $post['name'] = 'humo-rol-' . bin2hex(random_bytes(3));
            $post['description'] = 'Rol creado por el smoke';
        } elseif (str_contains($path, '/login')) {
            $post['email'] = self::$adminEmail;
            $post['password'] = 'clave-smoke-123';
        } elseif (str_contains($path, '/register')) {
            $post['name'] = 'Humo Registro';
            $post['email'] = 'humo-' . bin2hex(random_bytes(3)) . '@test.local';
            $post['password'] = 'clave-smoke-123';
            $post['password_confirm'] = 'clave-smoke-123';
        } elseif (str_contains($path, '/access')) {
            // F4: las rutas de acceso necesitan un email para llegar a la parte
            // interesante. Sin esto el smoke solo comprobaría que un POST vacío no
            // revienta, que es la mitad fácil.
            $post['email'] = 'humo-acceso-' . bin2hex(random_bytes(3)) . '@test.local';
        } elseif (preg_match('#^/f/#', $path) === 1) {
            $post['field_' . self::$fieldId] = 'valor humo';
            $post['_vf_hp'] = '';
        }
    }

    /** Reconstruye los fixtures si una ruta los destruyó (delete, builder...). */
    private static function ensureForm(): void
    {
        $db = Database::getInstance(Config::db());

        if (self::$formId === 0 || Form::find(self::$formId) === null) {
            self::$formId = Form::create((int) self::$adminId, 'Form de humo ' . bin2hex(random_bytes(3)));
            Form::setStatus(self::$formId, 'published');
        }
        self::$slug = (string) (Form::find(self::$formId)['slug'] ?? '');

        $field = $db->fetch('SELECT id FROM fields WHERE id = :id', ['id' => self::$fieldId]);
        if ($field === null) {
            self::$fieldId = Field::create(self::$formId, 'text', 'Campo humo', '', '', false);
        }

        $row = $db->fetch('SELECT id FROM submissions WHERE id = :id', ['id' => self::$submissionId]);
        if ($row === null) {
            self::$submissionId = Submission::create(self::$formId, '198.51.100.88', 'ControllerActionsTest');
            \App\Models\Answer::create(self::$submissionId, self::$fieldId, 'valor humo');
        }
    }

    private static function ensureCleanupHook(): void
    {
        if (!self::$cleanupHookRegistered) {
            self::$cleanupHookRegistered = true;
            register_shutdown_function(static function () {
                try {
                    self::cleanupResiduals();
                } catch (\Throwable $e) {
                    // Sin conexión no hay residuales que borrar.
                }
            });
        }
    }

    /** Lee las filas de `app_settings` ANTES de que el smoke las pise. */
    private static function snapshotSettings(Database $db): void
    {
        self::$savedSettings = [];

        foreach (self::APP_SETTINGS_KEYS as $key) {
            try {
                $row = $db->fetch(
                    'SELECT value FROM app_settings WHERE key_name = :k LIMIT 1',
                    ['k' => $key]
                );
            } catch (\Throwable $e) {
                // Sin la migración 0010 no hay nada que guardar.
                return;
            }

            self::$savedSettings[$key] = [
                'exists' => $row !== null,
                'value'  => ($row === null || $row['value'] === null) ? null : (string) $row['value'],
            ];
        }
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        $users = $db->fetchAll("SELECT id FROM users WHERE email LIKE '%@test.local'");
        foreach ($users as $u) {
            $db->execute('DELETE FROM users WHERE id = :id', ['id' => (int) $u['id']]);
        }
        // Los roles del smoke (nombre `humo-rol-*`) también son basura: `roles`
        // no se autolimpia y cada corrida añadiría una columna más a la matriz.
        // Los CASCADE de role_permissions/role_group_roles se llevan lo suyo.
        $db->execute("DELETE FROM roles WHERE name LIKE 'humo-rol-%'");

        // `app_settings` es de la INSTALACIÓN: el smoke la escribe de verdad, así
        // que se devuelve el estado previo en vez de borrarlo a lo bruto.
        foreach (self::APP_SETTINGS_KEYS as $key) {
            $db->execute('DELETE FROM app_settings WHERE key_name = :k', ['k' => $key]);
        }
        foreach (self::$savedSettings as $key => $saved) {
            if ($saved['exists']) {
                $db->execute(
                    'INSERT INTO app_settings (key_name, value) VALUES (:k, :v)',
                    ['k' => $key, 'v' => (string) $saved['value']]
                );
            }
        }
        self::$savedSettings = [];
        self::$adminId = null;
        self::$otherId = null;
        self::$formId = 0;
        self::$fieldId = 0;
        self::$submissionId = 0;
        self::$slug = '';
    }
}