<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppSettings;
use App\Core\Config;
use App\Core\Database;
use App\Core\SessionSettings;

/**
 * Hardening de sesión / HTTPS editable desde el panel (AG8).
 */
class SessionSettingsTest
{
    /** @var array<string, ?string> */
    private array $previas = [];

    public function setUp(): void
    {
        AppSettings::forgetForTesting();
        Config::forgetForTesting();

        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        foreach (SessionSettings::KEYS as $key) {
            $this->previas[$key] = AppSettings::get($key);
        }
        self::borrarFilas();
    }

    public function tearDown(): void
    {
        AppSettings::forgetForTesting();
        Config::forgetForTesting();

        self::borrarFilas();
        foreach ($this->previas as $key => $value) {
            if ($value !== null) {
                AppSettings::set($key, $value);
            }
        }
    }

    private static function borrarFilas(): void
    {
        Database::getInstance(Config::db())->execute(
            'DELETE FROM app_settings WHERE key_name IN (:a, :b, :c, :d, :e)',
            [
                'a' => SessionSettings::NAME,
                'b' => SessionSettings::LIFETIME,
                'c' => SessionSettings::SECURE,
                'd' => SessionSettings::HTTPONLY,
                'e' => SessionSettings::SAMESITE,
            ]
        );
    }

    private static function fila(string $key): ?array
    {
        return Database::getInstance(Config::db())->fetch(
            'SELECT value FROM app_settings WHERE key_name = :k',
            ['k' => $key]
        );
    }

    public function testSinFilasSalenDelEnv(): void
    {
        Config::setForTesting('session', ['name' => 'sess_test', 'lifetime' => 3600, 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
        Config::setForTesting('app', []);

        assertSame('sess_test', SessionSettings::name());
        assertSame(3600, SessionSettings::lifetime());
        assertTrue(SessionSettings::secure());
        assertTrue(SessionSettings::httponly());
        assertSame('Strict', SessionSettings::samesite());
    }

    public function testLaFilaGanaAlEnv(): void
    {
        Config::setForTesting('session', ['name' => 'sess_test', 'lifetime' => 3600, 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
        SessionSettings::setForTesting([
            SessionSettings::NAME => 'misesion',
            SessionSettings::LIFETIME => '1800',
            SessionSettings::SECURE => '1',
            SessionSettings::HTTPONLY => '0',
            SessionSettings::SAMESITE => 'Strict',
        ]);

        assertSame('misesion', SessionSettings::name());
        assertSame(1800, SessionSettings::lifetime());
        assertTrue(SessionSettings::secure());
        assertFalse(SessionSettings::httponly());
        assertSame('Strict', SessionSettings::samesite());
    }

    public function testSaveGuardaYVaciarBorra(): void
    {
        $r = SessionSettings::save(['name' => 'vf_sess', 'lifetime' => '5400', 'secure' => '1', 'httponly' => '1', 'samesite' => 'Lax']);
        assertTrue($r['ok']);
        assertSame('vf_sess', (string) self::fila(SessionSettings::NAME)['value']);

        SessionSettings::save(['name' => '', 'lifetime' => '', 'secure' => null, 'httponly' => null, 'samesite' => '']);
        assertNull(self::fila(SessionSettings::NAME));
        assertNull(self::fila(SessionSettings::LIFETIME));
        assertNull(self::fila(SessionSettings::SAMESITE));
    }

    public function testValoresInvalidosNoGuardanNada(): void
    {
        $r = SessionSettings::save(['name' => 'con guion-malo', 'lifetime' => '7200', 'secure' => null, 'httponly' => null, 'samesite' => 'Lax']);
        assertFalse($r['ok']);
        assertNull(self::fila(SessionSettings::NAME));

        $r2 = SessionSettings::save(['name' => 'ok', 'lifetime' => '30', 'secure' => null, 'httponly' => null, 'samesite' => 'Lax']);
        assertFalse($r2['ok']);
        assertNull(self::fila(SessionSettings::LIFETIME));

        $r3 = SessionSettings::save(['name' => 'ok', 'lifetime' => '7200', 'secure' => null, 'httponly' => null, 'samesite' => 'Bad']);
        assertFalse($r3['ok']);
        assertNull(self::fila(SessionSettings::SAMESITE));
    }
}
