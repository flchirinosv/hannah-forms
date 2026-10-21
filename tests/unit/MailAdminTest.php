<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminController;
use App\Core\AppSettings;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Mailer;
use App\Core\MailSettings;
use App\Core\Response;
use App\Core\Secret;
use App\Core\SecurityLog;
use App\Core\Session;
use App\Models\User;

/**
 * La tarjeta de correo de la instalación (AG3): guardar el SMTP y probarlo.
 *
 * Lo que se ata aquí es la puerta (`admin.access`) y que la contraseña **se
 * guarde cifrada**, que es lo que hace que este ajuste sea seguro de tener en la
 * BD. El correo de prueba se comprueba con el transporte inyectable de `Mailer`:
 * sin eso el test intentaría abrir un socket real.
 */
class MailAdminTest
{
    /** @var array<int,array{envelope:array,config:array}> */
    private array $sent = [];

    private ?int $adminId = null;
    private ?int $userId = null;
    private string $csrfToken = '';

    /** @var array<string, ?string> */
    private array $previas = [];

    public function setUp(): void
    {
        $this->sent = [];
        AppSettings::forgetForTesting();
        Config::forgetForTesting();
        Mailer::setConfigOverride(null);
        Mailer::setTransport(function (array $envelope, array $config): void {
            $this->sent[] = ['envelope' => $envelope, 'config' => $config];
        });

        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        foreach (MailSettings::KEYS as $key) {
            $this->previas[$key] = AppSettings::get($key);
        }
        self::borrarFilas();

        $this->adminId = User::create('Mail Admin', 'mailadmin-admin-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-larga-123', 1);
        $this->userId  = User::create('Mail User', 'mailadmin-user-' . bin2hex(random_bytes(3)) . '@test.local', 'clave-larga-123', 2);
        $this->csrfToken = Csrf::token();
    }

    public function tearDown(): void
    {
        Session::destroy();
        Response::setRecordHalt(false);
        Mailer::setTransport(null);
        Mailer::setConfigOverride(null);
        Secret::setKeyForTesting(null);
        AppSettings::forgetForTesting();
        Config::forgetForTesting();
        Auth::forgetCache();
        unset($_POST, $_GET, $_FILES, $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN']);

        self::borrarFilas();
        foreach ($this->previas as $key => $value) {
            if ($value !== null) {
                AppSettings::set($key, $value);
            }
        }
        self::limpiarUsuarios();
    }

    private static function borrarFilas(): void
    {
        Database::getInstance(Config::db())->execute(
            'DELETE FROM app_settings WHERE key_name IN (:a, :b, :c, :d, :e, :f, :g, :h)',
            [
                'a' => MailSettings::HOST, 'b' => MailSettings::PORT, 'c' => MailSettings::ENCRYPTION,
                'd' => MailSettings::USER, 'e' => MailSettings::PASSWORD, 'f' => MailSettings::FROM_ADDRESS,
                'g' => MailSettings::FROM_NAME, 'h' => MailSettings::TIMEOUT,
            ]
        );
    }

    private static function limpiarUsuarios(): void
    {
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'mailadmin-%@test.local'");
    }

    private static function fila(string $key): ?array
    {
        return Database::getInstance(Config::db())->fetch(
            'SELECT value FROM app_settings WHERE key_name = :k',
            ['k' => $key]
        );
    }

    private function conHalt(callable $accion): array
    {
        Response::setRecordHalt(true);
        try {
            $accion();
        } finally {
            $halt = Response::lastHalt() ?? [];
            Response::setRecordHalt(false);
        }
        return $halt;
    }

    /** @param array<string,mixed> $post */
    private function guardar(array $post, ?int $como): array
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

        return $this->conHalt(function (): void {
            (new AdminController())->updateMail();
        });
    }

    /** @param array<string,mixed> $post */
    private function probar(array $post, ?int $como): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $this->csrfToken;
        $_POST = $post + ['_token' => $this->csrfToken];
        $_GET = [];

        if ($como !== null) {
            Auth::assumeLoggedIn($como);
        } else {
            Auth::forgetCache();
        }

        return $this->conHalt(function (): void {
            (new AdminController())->sendTestMail();
        });
    }

    /* ------------------------------------------------------------------ */

    public function testUnAdminGuardaElSmtpYVuelveAAjustes(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());

        $halt = $this->guardar([
            'mail_host' => 'smtp.panel.test', 'mail_port' => '587', 'mail_encryption' => 'tls',
            'mail_user' => 'u@t.test', 'mail_password' => 'clave-secreta',
            'mail_from_address' => 'no-reply@t.test', 'mail_from_name' => 'Panel', 'mail_timeout' => '20',
        ], (int) $this->adminId);

        assertSame('redirect', (string) ($halt['type'] ?? ''), 'POST nativo: PRG');
        assertSame('/admin/settings', (string) ($halt['url'] ?? ''));
        assertSame('smtp.panel.test', MailSettings::all()['host'], 'el host se guarda');
        assertSame(20, MailSettings::all()['timeout']);
    }

    public function testLaContrasenaSeGuardaCifrada(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());

        $this->guardar([
            'mail_host' => 'h', 'mail_port' => '587', 'mail_encryption' => 'tls',
            'mail_password' => 'mi-clave-smtp', 'mail_from_address' => '', 'mail_from_name' => '', 'mail_timeout' => '15',
        ], (int) $this->adminId);

        $row = self::fila(MailSettings::PASSWORD);
        assertNotNull($row, 'hay fila de contraseña');
        assertFalse(str_contains((string) $row['value'], 'mi-clave-smtp'), 'en claro NO');
        assertSame('mi-clave-smtp', MailSettings::all()['password'], 'pero el correo la ve descifrada');
    }

    public function testSinAdminAccessNoSeGuardaNada(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());
        $halt = $this->guardar([
            'mail_host' => 'h', 'mail_port' => '587', 'mail_encryption' => 'tls',
            'mail_user' => '', 'mail_password' => '', 'mail_from_address' => '', 'mail_from_name' => '', 'mail_timeout' => '15',
        ], (int) $this->userId);

        assertSame(403, (int) ($halt['code'] ?? 0));
        assertNull(self::fila(MailSettings::HOST), 'un usuario normal no escribe el SMTP');
    }

    public function testElCambioQuedaEnElLogDeSeguridad(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());
        $this->guardar([
            'mail_host' => 'h', 'mail_port' => '587', 'mail_encryption' => 'tls',
            'mail_user' => '', 'mail_password' => '', 'mail_from_address' => '', 'mail_from_name' => '', 'mail_timeout' => '15',
        ], (int) $this->adminId);

        $row = Database::getInstance(Config::db())->fetch(
            "SELECT event FROM security_log WHERE event = 'instance.mail_update' ORDER BY id DESC LIMIT 1"
        );
        assertNotNull($row, 'el ajuste de correo deja traza');
        assertTrue(in_array('instance.mail_update', SecurityLog::knownEvents(), true), 'y el evento es conocido');
        assertTrue(in_array('instance.mail_test', SecurityLog::knownEvents(), true), 'igual que la prueba');
    }

    /* ------------------------------------------------------------------
     * Correo de prueba
     * ------------------------------------------------------------------ */

    public function testLaPruebaEnviaAlDestinoIndicado(): void
    {
        // `isConfigured()` pasa: el override le da host, y el transporte captura.
        Mailer::setConfigOverride([
            'host' => 'smtp.test', 'port' => 587, 'encryption' => 'tls', 'user' => '',
            'password' => '', 'from_address' => 'no-reply@t.test', 'from_name' => 'T', 'timeout' => 15,
        ]);

        $halt = $this->probar(['test_to' => 'destino@test.local'], (int) $this->adminId);

        assertSame('redirect', (string) ($halt['type'] ?? ''));
        assertSame(1, count($this->sent), 'se manda exactamente un correo');
        assertSame('destino@test.local', $this->sent[0]['envelope']['to'][0]);
    }

    public function testLaPruebaSinDestinoUsaElCorreoDeQuienLaPide(): void
    {
        Mailer::setConfigOverride([
            'host' => 'smtp.test', 'port' => 587, 'encryption' => 'tls', 'user' => '',
            'password' => '', 'from_address' => 'no-reply@t.test', 'from_name' => 'T', 'timeout' => 15,
        ]);

        $me = User::find((int) $this->adminId);
        $this->probar([], (int) $this->adminId);

        assertSame(1, count($this->sent));
        $esperado = is_array($me) ? (string) ($me['email'] ?? '') : '';
        assertSame($esperado, $this->sent[0]['envelope']['to'][0], 'sin destino, se manda al correo del admin');
    }

    public function testSinSmtpConfiguradoLaPruebaNoIntentaEnviar(): void
    {
        Mailer::setConfigOverride(['host' => '', 'port' => 587, 'encryption' => 'tls', 'timeout' => 15]);

        $this->probar(['test_to' => 'destino@test.local'], (int) $this->adminId);

        assertSame(0, count($this->sent), 'sin host no se abre conexión, solo se avisa');
    }

    public function testUnDestinoInvalidoNoEnvia(): void
    {
        Mailer::setConfigOverride([
            'host' => 'smtp.test', 'port' => 587, 'encryption' => 'tls', 'user' => '',
            'password' => '', 'from_address' => 'no-reply@t.test', 'from_name' => 'T', 'timeout' => 15,
        ]);

        $this->probar(['test_to' => 'no-es-correo'], (int) $this->adminId);

        assertSame(0, count($this->sent), 'un destino inválido no dispara el envío');
    }

    public function testUnFalloDeEnvioNoTumbalaPeticion(): void
    {
        Mailer::setConfigOverride([
            'host' => 'smtp.test', 'port' => 587, 'encryption' => 'tls', 'user' => '',
            'password' => '', 'from_address' => 'no-reply@t.test', 'from_name' => 'T', 'timeout' => 15,
        ]);
        Mailer::setTransport(function (array $envelope, array $config): void {
            throw new \RuntimeException('conexión rechazada');
        });

        // No debe propagar la excepción: el controlador la convierte en aviso.
        $halt = $this->probar(['test_to' => 'destino@test.local'], (int) $this->adminId);
        assertSame('redirect', (string) ($halt['type'] ?? ''), 'un fallo de SMTP es un aviso, no un error de servidor');
    }
}
