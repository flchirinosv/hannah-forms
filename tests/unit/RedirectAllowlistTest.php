<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Database;
use App\Core\Response;
use App\Controllers\FillController;
use App\Models\Field;
use App\Models\Form;
use App\Models\User;
use App\Services\RedirectGuard;

/**
 * F5.1 — redirect_url tras el envío: solo se sigue si el host está en la
 * allowlist del SERVIDOR. Caso bloqueado = gracias interno con aviso y la
 * respuesta igual se guarda (PRG sano, sin trampolín de phishing).
 */
class RedirectAllowlistTest
{
    private static bool $cleanupHookRegistered = false;
    private static ?int $userId = null;
    private static ?int $formId = null;
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

        self::$userId = User::create('Test F51', 'f51-' . bin2hex(random_bytes(4)) . '@test.local', 'password');
        self::$formId = Form::create((int) self::$userId, 'Form F51 ' . bin2hex(random_bytes(4)));
        Form::setStatus((int) self::$formId, 'published');
        Field::create((int) self::$formId, 'text', 'Un dato', '', '', false);
        $form = Form::find((int) self::$formId);
        self::$slug = (string) ($form['slug'] ?? '');

        RedirectGuard::setAllowlistForTesting(null);
    }

    public function tearDown(): void
    {
        RedirectGuard::setAllowlistForTesting(null);
        Response::setRecordHalt(false);
    }

    public function testRedirectUrlEnAllowlistSeSigue(): void
    {
        RedirectGuard::setAllowlistForTesting(['example.com']);
        Form::saveSettings((int) self::$formId, [
            'thanks' => ['message' => '', 'redirect_url' => 'https://example.com/gracias'],
        ]);

        $halt = self::submit();
        assertSame('redirect_external', $halt['type'] ?? null, 'debe ir a la URL externa permitida');
        assertEquals('https://example.com/gracias', $halt['url'] ?? null);
        assertSame(1, self::subsDelta(), 'el envío permitido guarda la respuesta');
    }

    public function testRedirectUrlFueraDeAllowlistMuestraThanksConAviso(): void
    {
        RedirectGuard::setAllowlistForTesting([]);
        Form::saveSettings((int) self::$formId, [
            'thanks' => ['message' => '', 'redirect_url' => 'https://example.com/gracias'],
        ]);

        $halt = self::submit();
        assertSame('redirect', $halt['type'] ?? null, 'NO se sigue la URL externa: PRG al thanks interno');
        assertSame('/f/' . self::$slug . '/thanks', $halt['url'] ?? null);
        assertSame(1, self::subsDelta(), 'el envío bloqueado igual guarda la respuesta');

        $controller = new FillController();
        ob_start();
        $controller->thanks(self::$slug);
        $html = (string) ob_get_clean();
        assertTrue(
            str_contains($html, 'example.com'),
            'el gracias debe avisar del host bloqueado'
        );
    }

    public function testGraciasSinAvisoSiNoHuboRedirectBloqueado(): void
    {
        $controller = new FillController();
        ob_start();
        $controller->thanks(self::$slug);
        $html = (string) ob_get_clean();
        assertFalse(
            str_contains($html, 'lista de dominios permitidos'),
            'sin flash no hay aviso'
        );
    }

    private static function submit(): ?array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.33';
        $_SESSION['_csrf_token'] = 'f51-token';
        $_POST = ['field_1' => 'algo', '_token' => 'f51-token'];
        Response::setRecordHalt(true);
        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        $halt = Response::lastHalt();
        ob_get_clean();
        Response::setRecordHalt(false);
        return $halt;
    }

    private static function subsDelta(): int
    {
        $db = Database::getInstance(Config::db());
        $row = $db->fetch('SELECT COUNT(*) AS c FROM submissions WHERE form_id = :id', ['id' => (int) self::$formId]);
        return (int) ($row['c'] ?? 0);
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
        self::$userId = null;
        self::$formId = null;
        self::$slug = '';
    }
}