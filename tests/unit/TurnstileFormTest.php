<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FillController;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\Turnstile;

/**
 * B2 — Turnstile en el fill real (BD): la barrera de disponibilidad (un form
 * que lo pide sin claves de instancia NO se muestra) y el submit (token
 * verificado = persiste; token ausente o rechazado = re-render con el error y
 * NADA se guarda).
 *
 * El resultado de la verificación se inyecta con
 * Turnstile::setTransportForTesting() y las claves con setKeysForTesting().
 */
class TurnstileFormTest
{
    private static ?int $userId = null;
    private static ?int $formId = null;
    private static string $slug = '';
    private static bool $envLoaded = false;

    public function setUp(): void
    {
        self::loadEnv();
        try {
            $db = Database::getInstance(Config::db());
            $db->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::cleanupResiduals();
        Turnstile::setTransportForTesting(null);
        Turnstile::setKeysForTesting(null, null);

        self::$userId = User::create('Test B2', 'b2-' . bin2hex(random_bytes(4)) . '@test.local', 'password');
        self::$formId = Form::create((int) self::$userId, 'Form B2 ' . bin2hex(random_bytes(4)));
        $form = Form::find(self::$formId);
        self::$slug = (string) ($form['slug'] ?? '');
        Form::update(self::$formId, 'Form B2', '', 'published');
        Field::create(self::$formId, 'text', 'Tu nombre', '', '', true);
        Form::saveSettings(self::$formId, ['anti_bot' => ['enabled' => true]]);
    }

    public function tearDown(): void
    {
        Turnstile::setTransportForTesting(null);
        Turnstile::setKeysForTesting(null, null);
        unset($_POST['_token'], $_POST['cf-turnstile-response'], $_POST['_vf_hp'], $_POST['field_1']);
    }

    /* ---------- Barrera del GET ---------- */

    public function testShowSeBloqueaSiElFormPideTurnstileYLaInstanciaNoTieneClaves(): void
    {
        Turnstile::setKeysForTesting('site', '');
        $html = self::renderShow();
        assertTrue(str_contains($html, 'Temporalmente no disponible'));
        assertFalse(str_contains($html, 'cf-turnstile'), 'no debe pintar el widget');
    }

    public function testShowPintaElWidgetConSiteKeyCuandoHayClaves(): void
    {
        Turnstile::setKeysForTesting('site-key-123', 'secret');
        $html = self::renderShow();
        assertTrue(str_contains($html, 'cf-turnstile'));
        assertTrue(str_contains($html, 'data-sitekey="site-key-123"'));
    }

    /* ---------- Submit ---------- */

    public function testSubmitSinTokenReRenderizaElErrorYNoPersiste(): void
    {
        Turnstile::setKeysForTesting('site-key-123', 'secret');
        Turnstile::setTransportForTesting(static fn(string $b): array => ['success' => true]);

        self::postValid();
        unset($_POST['cf-turnstile-response']);

        $html = self::renderSubmit();
        assertTrue(str_contains($html, 'No pudimos verificar que no eres un robot'));
        assertTrue(str_contains($html, 'cf-turnstile'), 'vuelve a pintar el widget');
        assertSame(0, self::countSubmissions(), 'no debe persistir nada');
    }

    public function testSubmitConTokenRechazadoNoPersiste(): void
    {
        Turnstile::setKeysForTesting('site-key-123', 'secret');
        Turnstile::setTransportForTesting(static fn(string $b): array => [
            'success' => false,
            'error-codes' => ['invalid-input-response'],
        ]);

        self::postValid();
        $_POST['cf-turnstile-response'] = 'token-falso';

        $html = self::renderSubmit();
        assertTrue(str_contains($html, 'No pudimos verificar que no eres un robot'));
        assertSame(0, self::countSubmissions());
    }

    public function testSubmitConTokenVerificadoPersiste(): void
    {
        Turnstile::setKeysForTesting('site-key-123', 'secret');
        Turnstile::setTransportForTesting(static fn(string $b): array => ['success' => true]);

        self::postValid();
        $_POST['cf-turnstile-response'] = 'token-bueno';

        self::renderSubmit();
        assertSame(1, self::countSubmissions(), 'una respuesta guardada');
    }

    public function testSubmitSinAntiBotNoExigeToken(): void
    {
        Form::saveSettings(self::$formId, ['anti_bot' => ['enabled' => false]]);
        Turnstile::setKeysForTesting('', '');
        Turnstile::setTransportForTesting(static function (string $b): array {
            throw new \RuntimeException('la API no debería llamarse sin anti_bot');
        });

        self::postValid();
        self::renderSubmit();
        assertSame(1, self::countSubmissions());
    }

    /* ---------- Helpers ---------- */

    private static function postValid(): void
    {
        $_POST['_token'] = Csrf::token();
        $_POST['_vf_hp'] = '';
        $_POST['lang'] = 'es';
        $field = Field::byForm(self::$formId)[0] ?? null;
        if ($field !== null) {
            $_POST['field_' . (int) $field['id']] = 'Ana';
        }
    }

    private static function renderShow(): string
    {
        $controller = new FillController();
        ob_start();
        $controller->show(self::$slug);
        return (string) ob_get_clean();
    }

    private static function renderSubmit(): string
    {
        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        return (string) ob_get_clean();
    }

    private static function countSubmissions(): int
    {
        $db = Database::getInstance(Config::db());
        return (int) $db->fetch(
            'SELECT COUNT(*) AS c FROM submissions WHERE form_id = :f',
            ['f' => (int) self::$formId]
        )['c'];
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        $users = $db->fetchAll("SELECT id FROM users WHERE email LIKE '%@test.local'");
        foreach ($users as $u) {
            $db->execute('DELETE FROM users WHERE id = :id', ['id' => (int) $u['id']]);
        }
        self::$userId = null;
        self::$formId = null;
        self::$slug = '';
    }

    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            \App\Core\Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
            register_shutdown_function(static function () {
                try {
                    self::cleanupResiduals();
                } catch (\Throwable $e) {
                    // Sin conexión no hay residuales que borrar.
                }
            });
        }
    }
}
