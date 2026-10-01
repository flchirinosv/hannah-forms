<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppSettings;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Models\User;

/**
 * Bloque 4 — interruptor de registro público.
 *
 * Lo que se fija aquí es el ORDEN de las dos fuentes: la fila de `app_settings`
 * gana si alguien la escribió, y si no hay fila manda `REGISTRATION_ENABLED`
 * del `.env`. Es el orden que deja el control donde estaba (el despliegue
 * gobierna) sin quitarle el interruptor a quien no tiene acceso al servidor.
 *
 * Y una segunda cosa, más fácil de romper sin querer: `app_settings` es una
 * tabla de la INSTALACIÓN, no un fixture. Estos tests la tocan, y al terminar la
 * fila guardada deja de existir para que la corrida no deje el registro de la
 * instancia al revés.
 */
class RegistrationSwitchTest
{
    private const KEY = 'registration_enabled';

    private ?int $adminId = null;
    private ?int $userId = null;
    private string $csrfToken = '';

    public function setUp(): void
    {
        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::borrarFila();

        $this->adminId = User::create('Switch Admin', 'switch-admin-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-larga-123', 1);
        $this->userId = User::create('Switch User', 'switch-user-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-larga-123', 2);
        $this->csrfToken = Csrf::token();
    }

    public function tearDown(): void
    {
        Session::destroy();
        Response::setRecordHalt(false);
        AppSettings::forgetForTesting();
        Config::forgetForTesting();
        Auth::forgetCache();
        unset($_POST, $_GET, $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN']);
        self::borrarFila();
        self::limpiarUsuarios();
    }

    private static function borrarFila(): void
    {
        Database::getInstance(Config::db())->execute(
            'DELETE FROM app_settings WHERE key_name = :k',
            ['k' => self::KEY]
        );
    }

    private static function limpiarUsuarios(): void
    {
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'switch-%@test.local'");
    }

    /** @param array<string, mixed> $post */
    private function post(array $post, ?int $como): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $this->csrfToken;
        $_POST = $post + ['_token' => $this->csrfToken];

        if ($como !== null) {
            Auth::assumeLoggedIn($como);
        } else {
            Auth::forgetCache();
        }

        Response::setRecordHalt(true);
        ob_start();
        try {
            (new AdminController())->updateRegistration();
        } finally {
            $body = (string) ob_get_clean();
        }
        $halt = Response::lastHalt() ?? [];
        Response::setRecordHalt(false);

        return ['halt' => $halt, 'body' => $body];
    }

    private static function body(array $halt, string $body): array
    {
        $json = json_decode($body, true);
        assertTrue(
            is_array($json),
            'la respuesta debe ser JSON, no un 302: ' . substr($body, 0, 200)
        );

        return $json;
    }

    private static function statusOf(?array $halt): int
    {
        return $halt === null ? 0 : (int) ($halt['status'] ?? 0);
    }

    /* ------------------------------------------------------------------
     * Orden de las fuentes
     * ------------------------------------------------------------------ */

    /**
     * Sin fila, manda el `.env`. Es el estado por defecto de toda instalación
     * recién migrada, así que va contra la BD de verdad y no con un override.
     */
    public function testSinFilaMandaElValorDelEnv(): void
    {
        Config::setForTesting('app.registration_enabled', true);
        assertNull(AppSettings::get(self::KEY), 'la fila no está: el ajuste se decide fuera de la tabla');
        assertSame(true, AuthController::registrationEnabled());

        Config::setForTesting('app.registration_enabled', false);
        assertSame(false, AuthController::registrationEnabled());
    }

    /**
     * La fila gana al `.env` en LOS DOS sentidos. Si solo ganara para
     * «encendido», el panel no podría cerrar un registro que el `.env` tiene
     * abierto, y el interruptor sería un adorno.
     */
    public function testLaFilaGanaAlEnvEnLosDosSentidos(): void
    {
        Config::setForTesting('app.registration_enabled', true);

        self::borrarFila();
        AppSettings::setBool(self::KEY, false);
        assertSame(false, AuthController::registrationEnabled(), 'el panel puede cerrar lo que el .env abrió');

        AppSettings::setBool(self::KEY, true);
        Config::setForTesting('app.registration_enabled', false);
        assertSame(true, AuthController::registrationEnabled(), 'y abrirlo aunque el .env lo tenga cerrado');
    }

    /**
     * Lo que se guarda es exactamente `0`/`1`, y no «el valor del `.env`»
     * volcado dentro de la tabla. Si se guardara el default, la fila se
     * convertiría en una segunda fuente de verdad que el operador ya no
     * controla desde el fichero.
     */
    public function testLoQueSeGuardaEsElInterruptorYNoElDefault(): void
    {
        Config::setForTesting('app.registration_enabled', true);

        $this->post(['enabled' => '0'], (int) $this->adminId);

        $db = Database::getInstance(Config::db());
        $row = $db->fetch('SELECT value FROM app_settings WHERE key_name = :k', ['k' => self::KEY]);
        assertNotNull($row, 'la escritura tiene que existir');
        assertSame('0', (string) $row['value'], 'guarda 0, no el true que venía del .env');
    }

    /**
     * Un valor escrito a mano que no es booleano tiene que caer al lado
     * SEGURO, no lanzar. La tabla es `TEXT`: cualquiera puede escribir
     * «cualquiera» en ella con un `INSERT`, y eso no puede ser un 500 en
     * `/register`.
     */
    public function testUnValorRaroNoRompeYValeComoDesactivado(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute(
            'INSERT INTO app_settings (key_name, value) VALUES (:k, :v)',
            ['k' => self::KEY, 'v' => 'quiza']
        );

        assertSame(false, AppSettings::bool(self::KEY, true), 'lo que no se entiende es «desactivado»');
        assertSame(false, AuthController::registrationEnabled());
    }

    /* ------------------------------------------------------------------
     * La puerta
     * ------------------------------------------------------------------ */

    public function testUnAdminAbreElRegistroYLaFilaQuedaEscrita(): void
    {
        Config::setForTesting('app.registration_enabled', false);

        $r = $this->post(['enabled' => '1'], (int) $this->adminId);
        $data = self::body($r['halt'], $r['body']);

        assertSame(200, self::statusOf($r['halt']), 'guardado');
        assertSame(true, $data['ok'] ?? null);
        assertSame(true, $data['enabled'] ?? null, 'y el estado que queda');
        assertTrue(AuthController::registrationEnabled(), 'la fila manda sobre el .env cerrado');
    }

    /** Una casilla desmarcada NO viaja en el POST: ese es el caso de cerrar. */
    public function testSinCampoEnabledSeCierraElRegistro(): void
    {
        AppSettings::setBool(self::KEY, true);

        $r = $this->post([], (int) $this->adminId);
        $data = self::body($r['halt'], $r['body']);

        assertSame(200, self::statusOf($r['halt']));
        assertSame(false, $data['enabled'] ?? null);
        assertSame(false, AuthController::registrationEnabled());
    }

    /**
     * `admin.access` y no `admin.users`: abrir el registro no es tocar la
     * cuenta de nadie. Un grupo con `admin.access` pero sin `admin.users` puede
     * entrar al panel, así que el permiso concreto tiene que comprobarlo la
     * propia acción y no solo el middleware `admin` de la ruta.
     */
    public function testSinAdminAccessNoSeGuardaNada(): void
    {
        $r = $this->post(['enabled' => '1'], (int) $this->userId);
        $data = self::body($r['halt'], $r['body']);

        assertSame(403, self::statusOf($r['halt']));
        assertSame(false, $data['ok'] ?? null);
        assertNull(AppSettings::get(self::KEY), 'y no se escribe la fila');
    }

    public function testSinSesionNiCsrfNoSeGuardaNada(): void
    {
        $r = $this->post(['enabled' => '1'], null);
        assertSame(403, self::statusOf($r['halt']), 'sin sesión, 403 antes de mirar el token');
        assertNull(AppSettings::get(self::KEY));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['enabled' => '1', '_token' => 'no-es-el-token'];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'no-es-el-token';
        Auth::assumeLoggedIn((int) $this->adminId);
        Response::setRecordHalt(true);
        ob_start();
        try {
            (new AdminController())->updateRegistration();
        } finally {
            ob_get_clean();
        }
        $halt = Response::lastHalt() ?? [];
        Response::setRecordHalt(false);

        assertSame(419, self::statusOf($halt), 'con sesión pero sin token, 419 como en el resto de la app');
        assertNull(AppSettings::get(self::KEY));
    }

    /**
     * Abrir el registro cambia quién puede entrar en la instalación: es de las
     * pocas escrituras de configuración con efecto sobre el acceso de terceros,
     * y por eso va al log con el `user_id` de la sesión.
     */
    public function testElCambioQuedaEnElLogDeSeguridad(): void
    {
        $db = Database::getInstance(Config::db());
        $antes = (int) ($db->fetch("SELECT COUNT(*) AS c FROM security_log WHERE event LIKE 'instance.registration_%'")['c'] ?? 0);

        $this->post(['enabled' => '1'], (int) $this->adminId);

        $filas = $db->fetchAll(
            "SELECT event, user_id FROM security_log WHERE event LIKE 'instance.registration_%' ORDER BY id DESC LIMIT 1"
        );
        assertSame(1, count($filas), 'el evento se registra: ' . json_encode($filas));
        assertSame('instance.registration_on', (string) $filas[0]['event']);
        assertSame((int) $this->adminId, (int) $filas[0]['user_id'], 'y con quién lo hizo');

        // Y en `knownEvents()`: sin eso el log se escribe pero el filtro del
        // panel no lo ofrece y `recent($event)` lo ignora, o sea que el evento
        // existiría y sería invisible justo en la pantalla que lo audita.
        assertTrue(
            in_array('instance.registration_on', \App\Core\SecurityLog::knownEvents(), true)
                && in_array('instance.registration_off', \App\Core\SecurityLog::knownEvents(), true),
            'los dos eventos tienen que estar en el catálogo del filtro'
        );
        assertTrue(
            (int) ($db->fetch(
                "SELECT COUNT(*) AS c FROM security_log WHERE event = 'instance.registration_on' AND user_id = :u",
                ['u' => (int) $this->adminId]
            )['c'] ?? 0) >= 1,
            'y el filtro del panel lo encuentra por evento'
        );

        $this->post(['enabled' => '0'], (int) $this->adminId);
        $despues = $db->fetchAll(
            "SELECT event FROM security_log WHERE event = 'instance.registration_off' AND user_id = :u
             ORDER BY id DESC LIMIT 1",
            ['u' => (int) $this->adminId]
        );
        assertSame(1, count($despues), 'cerrar también: no es una escritura menor');
    }

    /* ------------------------------------------------------------------
     * Lo que el interruptor gobierna de verdad
     * ------------------------------------------------------------------ */

    /**
     * El interruptor no solo cambia un texto: las DOS puertas del registro
     * obedecen. Con la fila a `0` y el `.env` a `true`, `/register` tiene que
     * cortar con 403 en GET y en POST.
     */
    public function testLaFilaCierraLasDosPuertasDelRegistroSobreUnEnvAbierto(): void
    {
        Config::setForTesting('app.registration_enabled', true);
        AppSettings::setBool(self::KEY, false);

        $antes = count(self::usuarios());
        $get = self::capturar(static function (): void {
            (new AuthController())->showRegister();
        });
        $post = self::capturar(static function (): void {
            $_POST = ['name' => 'X', 'email' => 'nadie@ejemplo.com', 'password' => 'clave-larga-123'];
            (new AuthController())->register();
        });

        // `abort()` registra `code` y no `status`: leer `status` a pelo daría 0
        // y el test pasaría/fallaría por el motivo equivocado.
        assertSame(403, (int) ($get['halt']['code'] ?? 0), 'el GET no pinta el formulario');
        assertSame(403, (int) ($post['halt']['code'] ?? 0), 'y el POST tampoco crea cuentas');
        assertFalse(str_contains($post['body'], 'name="password_confirm"'), 'sin formulario de alta');
        assertFalse(str_contains($get['body'], 'name="password_confirm"'), 'tampoco en el GET');
        assertSame($antes, count(self::usuarios()), 'y ni una cuenta de más');
    }

    /* ------------------------------------------------------------------
     * La vista
     * ------------------------------------------------------------------ */

    public function testElInterruptorSePintaSoloConAdminAccessYRespetaElEstado(): void
    {
        $vista = (string) file_get_contents(dirname(__DIR__, 2) . '/views/admin/users.php');

        assertTrue(str_contains($vista, 'id="registrationToggle"'), 'el interruptor está en la ficha');
        assertTrue(str_contains($vista, 'if ($canManageInstance ?? false)'), 'y va detrás del permiso');
        assertTrue(
            str_contains($vista, "(\$registrationEnabled ?? false) ? 'checked' : ''"),
            'el `checked` sale del valor que Resolvió el controlador, no de un default del HTML'
        );
        assertTrue(
            str_contains($vista, "data-registration-endpoint=\"/admin/settings/registration\""),
            'con el endpoint en el DOM: nada de URL escrita dentro de dashboard.js'
        );
    }

    /**
     * El interruptor NO se reposiciona al terminar. Es lo contrario de lo
     * habitual y por eso está atado: si el guardado fallara, dejar la casilla
     * como el usuario la dejó sería mostrar un estado que no es el real — y el
     * estado que importa aquí es «¿puede cualquiera crear su cuenta?».
     */
    public function testElJsVuelveAlEstadoQueDevuelveElServidorYNoAlQueSeHizoClic(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/dashboard.js');

        assertTrue(
            str_contains($js, "toggle.checked = enabled;"),
            'la casilla se repinta con el `enabled` de la respuesta'
        );
        assertTrue(
            str_contains($js, "body.set('enabled', toggle.checked ? '1' : '0')"),
            'y lo que se manda es lo que el usuario acaba de marcar'
        );
        assertTrue(
            str_contains($js, 'card.dataset.registrationEndpoint'),
            'el endpoint se lee del DOM: la URL no está escrita en el JS'
        );
    }

    /** Sin fila, `get()` es `null` y no `''`: es lo que deja mandar al default. */
    public function testAusenteNoEsCadenaVacia(): void
    {
        assertNull(AppSettings::get('no_existe_este_ajuste'));
        assertSame(true, AppSettings::bool('no_existe_este_ajuste', true), 'el default se respeta');
    }

    private static function usuarios(): array
    {
        return Database::getInstance(Config::db())->fetchAll('SELECT id, email FROM users');
    }

    private static function capturar(callable $fn): array
    {
        Response::setRecordHalt(true);
        ob_start();
        try {
            $fn();
        } finally {
            $body = (string) ob_get_clean();
        }
        $halt = Response::lastHalt() ?? [];
        Response::setRecordHalt(false);

        return ['halt' => $halt, 'body' => $body];
    }
}
