<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppSettings;
use App\Core\Config;
use App\Core\Database;
use App\Core\NetworkSettings;

/**
 * Red y redirecciones editables desde el panel (AG9).
 */
class NetworkSettingsTest
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

        foreach (NetworkSettings::KEYS as $key) {
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
            'DELETE FROM app_settings WHERE key_name IN (:a, :b, :c, :d)',
            [
                'a' => NetworkSettings::TRUST_PROXY,
                'b' => NetworkSettings::REDIRECT_ALLOWLIST,
                'c' => NetworkSettings::FRAME_OPTIONS,
                'd' => NetworkSettings::CSP_POLICY,
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
        Config::setForTesting('app', ['security' => ['trust_proxy' => true]]);
        Config::setForTesting('app.redirect_allowlist', ['example.com']);
        Config::setForTesting('app', ['csp_policy' => "default-src 'self'"]);
        Config::setForTesting('app', ['security' => ['trust_proxy' => true], 'csp_policy' => "default-src 'self'"]);

        assertTrue(NetworkSettings::trustProxy());
        assertTrue(in_array('example.com', NetworkSettings::redirectAllowlist(), true));
        assertSame("default-src 'self'", NetworkSettings::cspPolicy());
    }

    public function testLaFilaGanaAlEnv(): void
    {
        NetworkSettings::setForTesting([
            NetworkSettings::TRUST_PROXY => '0',
            NetworkSettings::REDIRECT_ALLOWLIST => 'a.com,b.com',
            NetworkSettings::FRAME_OPTIONS => 'DENY',
            NetworkSettings::CSP_POLICY => "default-src 'none'",
        ]);

        assertFalse(NetworkSettings::trustProxy());
        assertSame(['a.com', 'b.com'], NetworkSettings::redirectAllowlist());
        assertSame('DENY', NetworkSettings::frameOptions());
        assertSame("default-src 'none'", NetworkSettings::cspPolicy());
    }

    public function testSaveGuardaYVaciarBorra(): void
    {
        $r = NetworkSettings::save(['trust_proxy' => true, 'redirect_allowlist' => 'x.com', 'frame_options' => 'SAMEORIGIN', 'csp_policy' => "default-src 'self'"]);
        assertTrue($r['ok']);
        assertSame('1', (string) self::fila(NetworkSettings::TRUST_PROXY)['value']);

        NetworkSettings::save(['trust_proxy' => false, 'redirect_allowlist' => '', 'frame_options' => '', 'csp_policy' => '']);
        assertSame('0', (string) self::fila(NetworkSettings::TRUST_PROXY)['value']);
        assertNull(self::fila(NetworkSettings::REDIRECT_ALLOWLIST));
        assertNull(self::fila(NetworkSettings::FRAME_OPTIONS));
        assertNull(self::fila(NetworkSettings::CSP_POLICY));
    }

    public function testValoresInvalidosNoGuardanNada(): void
    {
        $r = NetworkSettings::save(['trust_proxy' => false, 'redirect_allowlist' => '', 'frame_options' => 'BAD', 'csp_policy' => '']);
        assertFalse($r['ok']);
        assertNull(self::fila(NetworkSettings::FRAME_OPTIONS));
    }
}
