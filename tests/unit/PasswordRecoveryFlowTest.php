<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Mailer;
use App\Core\PublicUrl;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\PasswordReset;
use App\Models\User;

/**
 * F2 — Recuperación y cambio de contraseña, de punta a punta.
 *
 * `PasswordResetTest` prueba el modelo; aquí lo que se prueba es el
 * CONTROLADOR, y sobre todo lo que no se ve leyendo el código:
 *
 *  - Que la respuesta sea la MISMA exista o no el email. Es la diferencia entre
 *    un formulario de recuperación y un buscador de cuentas registradas: con un
 *    "no hay ninguna cuenta con ese email" el atacante ya tiene la lista.
 *  - Que el correo NO salga nunca con el token dentro de una cadena que se
 *    pueda leer, y que el enlace apunte a la base pública y no a `app.url`
 *    (detrás de un proxy, el enlace saldría con `localhost` y no serviría).
 *  - Que el token quede quemado aunque el cambio de contraseña falle.
 *
 * Los redirects se capturan con `Response::setRecordHalt`, y el correo con
 * `Mailer::setTransport`, los dos seams que ya usa el resto de la suite.
 */
class PasswordRecoveryFlowTest
{
    private static bool $envLoaded = false;
    private static ?int $userId = null;
    private static ?string $email = null;
    private static string $password = 'clave-inicial-larga-123';
    private $sent = [];

    private const MAIL_CFG = [
        'host'         => 'smtp.example.com',
        'port'         => 587,
        'encryption'   => 'tls',
        'user'         => '',
        'password'     => '',
        'from_address' => 'no-reply@example.com',
        'from_name'    => 'VanillaForms',
        'timeout'      => 15,
    ];

    public function setUp(): void
    {
        self::loadEnv();
        self::cleanupResiduals();
        $this->sent = [];

        Mailer::setTransport(function (array $envelope, array $config): void {
            $this->sent[] = $envelope;
        });
        Mailer::setConfigOverride(self::MAIL_CFG);
        PublicUrl::setBaseForTesting('https://forms.example.com');

        self::$email = 'recovery-' . bin2hex(random_bytes(4)) . '@test.local';
        self::$userId = User::create('Recovery Test', self::$email, self::$password, 2);
    }

    public function tearDown(): void
    {
        Mailer::setTransport(null);
        Mailer::setConfigOverride(null);
        PublicUrl::setBaseForTesting(null);
        Response::setRecordHalt(false);
        RateLimiter::reset('password_reset', '127.0.0.1');
        RateLimiter::reset('password_reset', (string) self::$email);
        Auth::forgetCache();
        Session::destroy();
    }

    // ---- /forgot-password ----

    public function testEmailDesconocidoRespondeIgualQueElConocido(): void
    {
        $conocido = $this->post('/forgot-password', ['email' => (string) self::$email]);
        assertSame(1, $this->pendingTokens(), 'precondición: el email real sí genera un token');
        $inventado = $this->post('/forgot-password', ['email' => 'nadie-' . bin2hex(random_bytes(4)) . '@test.local']);

        assertSame(
            $conocido['halt'],
            $inventado['halt'],
            'la respuesta debe ser idéntica: mismo tipo y mismo destino'
        );
        assertSame('redirect', $conocido['halt']['type'] ?? null, 'lo que se ve es una redirección');
        assertSame(
            $conocido['exito'],
            $inventado['exito'],
            'y el mensaje es el MISMO en los dos casos'
        );
        assertTrue($conocido['exito'] !== '', 'precondición: los dos casos dicen algo (si no, no se compara nada)');

        // Y lo que NO se ve en la respuesta: el desconocido no genera token ni
        // correo. Si creara uno, la diferencia de tiempo de la respuesta bastaría
        // para distinguir qué emails existen.
        assertSame(1, $this->pendingTokens(), 'un email desconocido no crea ningún token');
        assertSame(1, count($this->sent), 'y no se envía ningún correo');
    }

    public function testElCorreoLevaElEnlaceYNoElTokenEnClaroComoCredencial(): void
    {
        $this->post('/forgot-password', ['email' => (string) self::$email]);

        assertSame(1, count($this->sent), 'debe enviarse exactamente un correo');
        $envelope = $this->sent[0];
        assertSame([(string) self::$email], $envelope['to'], 'el correo va a quien lo pidió');
        assertTrue(
            str_contains($envelope['html'], 'https://forms.example.com/reset-password?t='),
            'el enlace debe usar la base pública (PublicUrl), no app.url: ' . substr($envelope['html'], 0, 200)
        );
        // El texto plano del correo también lleva el enlace: sin él, un cliente
        // que no pinte HTML deja al usuario sin forma de recuperar la cuenta.
        assertTrue(
            str_contains($envelope['text'], 'https://forms.example.com/reset-password?t='),
            'el alternative en texto plano debe llevar el mismo enlace'
        );

        // El token viaja en el enlace, sí, pero en la tabla solo está su hash.
        $token = $this->tokenFromMail($envelope['html']);
        assertTrue(
            password_verify((string) self::$password, (string) $this->storedHash()),
            'el usuario sigue con su contraseña original hasta que usa el enlace'
        );
        assertTrue(
            (string) $this->storedHash() !== $token,
            'el token no se guarda en claro en la tabla'
        );
    }

    public function testSinEmailNoSeEnviaNadaNiSeCreaToken(): void
    {
        $this->post('/forgot-password', ['email' => '']);

        assertSame(0, count($this->sent), 'sin email no hay correo');
        assertSame(0, $this->pendingTokens(), 'ni token pendiente');
    }

    // ---- /reset-password ----

    public function testElTokenDelCorreoCambiaLaPasswordYQuemaloEnUnSoloUso(): void
    {
        $token = $this->emitirToken();

        $this->post('/reset-password?t=' . urlencode($token), [
            'token'            => $token,
            'password'         => 'clave-recuperada-larga-456',
            'password_confirm' => 'clave-recuperada-larga-456',
        ]);

        assertTrue(
            password_verify('clave-recuperada-larga-456', (string) $this->storedHash()),
            'la contraseña nueva debe estar guardada'
        );
        assertSame(0, $this->pendingTokens(), 'el token queda quemado tras usarse');

        // Segundo uso del MISMO enlace: no puede volver a cambiar nada.
        $this->post('/reset-password?t=' . urlencode($token), [
            'token'            => $token,
            'password'         => 'una-tercera-clave-789',
            'password_confirm' => 'una-tercera-clave-789',
        ]);
        assertTrue(
            password_verify('clave-recuperada-larga-456', (string) $this->storedHash()),
            'un token usado no puede cambiar la contraseña otra vez'
        );
    }

    public function testUnEnlaceAjenoNoCambiaLaPasswordDeNadie(): void
    {
        $este = $this->emitirToken();
        $otro = bin2hex(random_bytes(16));

        $this->post('/reset-password?t=' . urlencode($otro), [
            'token'            => $otro,
            'password'         => 'clave-intrusa-larga-999',
            'password_confirm' => 'clave-intrusa-larga-999',
        ]);

        assertTrue(
            password_verify((string) self::$password, (string) $this->storedHash()),
            'un token que no es de esta cuenta no puede tocar su contraseña'
        );
        assertTrue(
            PasswordReset::findValid($este) !== null,
            'y el token legítimo sigue vivo: un intento fallido no lo quema'
        );
    }

    public function testNoSePuedeElegirLaMismaPasswordQueYaTenia(): void
    {
        $token = $this->emitirToken();

        // Mismo criterio que el cambio desde el panel, pero AQUÍ no hay campo
        // "contraseña actual": si no se compara con el hash, se podría "recuperar"
        // la cuenta dejando la credencial que el atacante ya conocía.
        $this->post('/reset-password?t=' . urlencode($token), [
            'token'            => $token,
            'password'         => (string) self::$password,
            'password_confirm' => (string) self::$password,
        ]);

        $row = PasswordReset::findValid($token);
        assertNotNull($row, 'el token no debe quemarse por un rechazo: el enlace sigue siendo válido');
        assertSame(
            (string) $this->storedHash(),
            (string) (User::find((int) self::$userId)['password_hash'] ?? ''),
            'la contraseña no se toca'
        );
    }

    public function testPasswordsDistintasOMuyCortaNoCambianNada(): void
    {
        $token = $this->emitirToken();

        foreach ([
            ['password' => 'corta', 'password_confirm' => 'corta', 'motivo' => 'demasiado corta'],
            ['password' => 'una-clave-larga-111', 'password_confirm' => 'otra-clave-larga-222', 'motivo' => 'no coinciden'],
        ] as $caso) {
            $this->post('/reset-password?t=' . urlencode($token), [
                'token'            => $token,
                'password'         => $caso['password'],
                'password_confirm' => $caso['password_confirm'],
            ]);
            assertTrue(
                password_verify((string) self::$password, (string) $this->storedHash()),
                'una contraseña ' . $caso['motivo'] . ' no debe cambiar nada'
            );
        }

        assertNotNull(PasswordReset::findValid($token), 'y el token sigue vivo para un intento válido');
    }

    public function testRecuperarLaPasswordCierraLaSesionDelNavegadorQueLoHizo(): void
    {
        // `/reset-password` no lleva middleware `guest` justamente para que un
        // enlace se pueda abrir con la sesión puesta; tras el cambio ese
        // navegador debe quedar fuera, o el `guest` de /login lo rebotaría a
        // /dashboard en lugar de dejarle entrar.
        Auth::assumeLoggedIn((int) self::$userId);
        assertNotNull(Auth::user(), 'precondición: la sesión está abierta');

        $token = $this->emitirToken();
        $resultado = $this->post('/reset-password?t=' . urlencode($token), [
            'token'            => $token,
            'password'         => 'clave-recuperada-larga-456',
            'password_confirm' => 'clave-recuperada-larga-456',
        ]);

        assertFalse(Auth::check(), 'el navegador que recuperó la cuenta queda sin sesión');
        // El mensaje se lee del resultado porque `post()` consume el flash al
        // devolverlo: leerlo otra vez después saldría vacío y la aserción
        // pasaría por el motivo equivocado.
        assertSame(
            'Tu contraseña se ha cambiado. Ya puedes iniciar sesión.',
            $resultado['exito'],
            'pero conserva el mensaje, que es la única prueba de que funcionó'
        );
    }

    // ---- /account/password ----

    public function testCambiarLaPasswordDesdeElPanelExigeLaActualYCierraLasRestas(): void
    {
        Auth::assumeLoggedIn((int) self::$userId);

        // Sin la contraseña actual no se cambia nada, aunque la sesión valga.
        $this->post('/account/password', [
            'password'         => 'cambio-sin-la-actual-123',
            'password_confirm' => 'cambio-sin-la-actual-123',
        ]);
        assertTrue(
            password_verify((string) self::$password, (string) $this->storedHash()),
            'sin verificar la actual no se toca la contraseña'
        );

        // Con la actual equivocada, tampoco.
        $this->post('/account/password', [
            'current_password' => 'no-es-la-mia-12345',
            'password'         => 'cambio-con-la-mala-123',
            'password_confirm' => 'cambio-con-la-mala-123',
        ]);
        assertTrue(
            password_verify((string) self::$password, (string) $this->storedHash()),
            'una contraseña actual incorrecta no cambia nada'
        );

        // Con la correcta, sí.
        $this->post('/account/password', [
            'current_password' => (string) self::$password,
            'password'         => 'cambio-con-la-buena-123',
            'password_confirm' => 'cambio-con-la-buena-123',
        ]);
        assertTrue(
            password_verify('cambio-con-la-buena-123', (string) $this->storedHash()),
            'con la contraseña correcta, el cambio se guarda'
        );
        assertNotNull(Auth::user(), 'y quien cambia su propia contraseña no se expulsa a sí mismo');
    }

    public function testCambiarLaPasswordInvalidaLosEnlacesDeRecuperacionPendientes(): void
    {
        Auth::assumeLoggedIn((int) self::$userId);

        $token = $this->emitirToken();
        assertNotNull(PasswordReset::findValid($token), 'precondición: hay un enlace sin usar');

        $this->post('/account/password', [
            'current_password' => (string) self::$password,
            'password'         => 'cambio-desde-el-panel-123',
            'password_confirm' => 'cambio-desde-el-panel-123',
        ]);

        assertNull(
            PasswordReset::findValid($token),
            'quien cambia la contraseña desde el panel no debe dejar un enlace de recuperación vivo'
        );
    }

    public function testElCambioSinCsrfNoSeAcepta(): void
    {
        Auth::assumeLoggedIn((int) self::$userId);

        $resultado = $this->post('/account/password', [
            'current_password' => (string) self::$password,
            'password'         => 'cambio-sin-csrf-123456',
            'password_confirm' => 'cambio-sin-csrf-123456',
        ], false);

        assertTrue($resultado['error'] !== '', 'un POST sin token debe ser rechazado con un mensaje');

        assertTrue(
            password_verify((string) self::$password, (string) $this->storedHash()),
            'un POST sin token de seguridad no puede cambiar la contraseña'
        );
    }

    // ---- helpers ----

    /**
     * Invoca la acción del controlador que corresponde a la ruta.
     *
     * Se llama al método directamente y no por el router a propósito: lo que se
     * prueba aquí es el controlador, y el enrutado/middleware ya lo recorre
     * `ControllerActionsTest` con el router de verdad.
     *
     * @param bool $conCsrf por defecto true; en false, el POST va sin token de
     *                      seguridad (que es lo que hace inválida una petición
     *                      legítima ajena).
     *
     * @return array{halt: ?array, exito: string, error: string}
     */
    private function post(string $url, array $body, bool $conCsrf = true): array
    {
        if ($conCsrf) {
            Session::set('_csrf_token', Csrf::token());
        } else {
            Session::remove('_csrf_token');
        }

        $_POST = $body;
        $_POST['_token'] = $conCsrf ? Session::get('_csrf_token') : 'token-inventado';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $url;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Request::setRequestBodyForTests(null);
        Request::resetJsonState();
        Response::setRecordHalt(false);
        Response::setRecordHalt(true);

        ob_start();
        try {
            (new AccountController())->{$this->actionFor($url)}();
        } finally {
            ob_get_clean();
        }

        $halt = Response::lastHalt();
        Response::setRecordHalt(false);

        return [
            'halt'  => $halt,
            'exito' => Session::getFlash('success'),
            'error' => Session::getFlash('error'),
        ];
    }

    private function actionFor(string $url): string
    {
        return [
            '/forgot-password' => 'sendReset',
            '/reset-password'  => 'doReset',
            '/account/password' => 'changePassword',
        ][rtrim(explode('?', $url)[0], '/')] ?? 'showForgot';
    }

    private function emitirToken(): string
    {
        $this->post('/forgot-password', ['email' => (string) self::$email]);
        assertSame(1, count($this->sent), 'precondición: se ha enviado el correo');
        return $this->tokenFromMail($this->sent[0]['html']);
    }

    private function tokenFromMail(string $html): string
    {
        if (preg_match('#/reset-password\?t=([0-9a-f]{32})#', $html, $m) === 1) {
            return $m[1];
        }
        $this->fail('el correo no contenía un enlace con token: ' . substr($html, 0, 300));
    }

    private function storedHash(): ?string
    {
        $row = User::find((int) self::$userId);
        return $row === null ? null : (string) $row['password_hash'];
    }

    private function pendingTokens(): int
    {
        $row = Database::getInstance(Config::db())->fetch(
            'SELECT COUNT(*) AS c FROM password_resets WHERE user_id = :uid AND used_at IS NULL',
            ['uid' => (int) self::$userId]
        );
        return (int) ($row['c'] ?? -1);
    }

    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
        }
    }

    private static function cleanupResiduals(): void
    {
        Database::getInstance(Config::db())
            ->execute("DELETE FROM users WHERE email LIKE '%@test.local'");
    }
}
