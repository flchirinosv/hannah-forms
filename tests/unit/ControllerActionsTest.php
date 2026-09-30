<?php

declare(strict_types=1);

namespace Tests\Unit;

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

        self::$adminEmail = 'smoke-' . bin2hex(random_bytes(3)) . '@test.local';
        self::$adminId = User::create('Smoke Admin', self::$adminEmail, 'clave-smoke-123', 1);
        // Usuario objetivo para /admin/users/* (no degradar/desactivar al propio admin).
        self::$otherId = (int) User::create('Smoke Otro', 'smoke-b-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-smoke-123', 2);
        self::ensureForm();
    }

    /**
     * El registro público apagado tiene que cerrar GET y POST por igual.
     *
     * Se usa la tabla de rutas REAL (no una ruta escrita a mano) para que el
     * test no pueda quedarse comprobando un endpoint que ya no existe. Con el
     * registro abierto, el smoke de arriba recorre las dos rutas de verdad.
     */
    public function testElRegistroApagadoCierraGetYPost(): void
    {
        Config::setForTesting('app.registration_enabled', false);

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
        self::$adminId = null;
        self::$otherId = null;
        self::$formId = 0;
        self::$fieldId = 0;
        self::$submissionId = 0;
        self::$slug = '';
    }
}