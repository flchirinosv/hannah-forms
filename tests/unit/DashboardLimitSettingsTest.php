<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppSettings;
use App\Core\Config;
use App\Core\Database;
use App\Core\DashboardLimitSettings;

/**
 * Cupos del dashboard por usuario editables desde el panel (AG7).
 *
 * Precedencia: fila gana, sin fila manda el `.env`. Defaults 30 y 120.
 * `DashboardLimit` los lee desde `DashboardLimitSettings`.
 */
class DashboardLimitSettingsTest
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

        foreach (DashboardLimitSettings::KEYS as $key) {
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
            'DELETE FROM app_settings WHERE key_name IN (:a, :b)',
            ['a' => DashboardLimitSettings::CREATE_PER_HOUR, 'b' => DashboardLimitSettings::EXPORT_PER_HOUR]
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
        Config::setForTesting('app', ['limits' => ['create_per_hour' => 40, 'export_per_hour' => 150]]);

        assertSame(40, DashboardLimitSettings::createPerHour());
        assertSame(150, DashboardLimitSettings::exportPerHour());
    }

    public function testLaFilaGanaAlEnv(): void
    {
        Config::setForTesting('app', ['limits' => ['create_per_hour' => 40, 'export_per_hour' => 150]]);
        DashboardLimitSettings::setForTesting([
            DashboardLimitSettings::CREATE_PER_HOUR => '15',
            DashboardLimitSettings::EXPORT_PER_HOUR => '60',
        ]);

        assertSame(15, DashboardLimitSettings::createPerHour());
        assertSame(60, DashboardLimitSettings::exportPerHour());
    }

    public function testSaveGuardaYVaciarBorra(): void
    {
        $r = DashboardLimitSettings::save(['create_per_hour' => '25', 'export_per_hour' => '80']);
        assertTrue($r['ok']);
        assertSame('25', (string) self::fila(DashboardLimitSettings::CREATE_PER_HOUR)['value']);

        DashboardLimitSettings::save(['create_per_hour' => '', 'export_per_hour' => '']);
        assertNull(self::fila(DashboardLimitSettings::CREATE_PER_HOUR));
        assertNull(self::fila(DashboardLimitSettings::EXPORT_PER_HOUR));
    }

    public function testValoresInvalidosNoGuardanNada(): void
    {
        $r = DashboardLimitSettings::save(['create_per_hour' => '0', 'export_per_hour' => '120']);
        assertFalse($r['ok']);
        assertNull(self::fila(DashboardLimitSettings::CREATE_PER_HOUR));

        $r2 = DashboardLimitSettings::save(['create_per_hour' => '30', 'export_per_hour' => '-5']);
        assertFalse($r2['ok']);
        assertNull(self::fila(DashboardLimitSettings::EXPORT_PER_HOUR));
    }

    public function testAllDistingueLoGuardadoDeLoResuelto(): void
    {
        Config::setForTesting('app', ['limits' => ['create_per_hour' => 30, 'export_per_hour' => 120]]);
        DashboardLimitSettings::setForTesting([DashboardLimitSettings::CREATE_PER_HOUR => '12']);

        $all = DashboardLimitSettings::all();
        assertSame('12', $all['stored_create_per_hour']);
        assertSame('', $all['stored_export_per_hour']);
        assertSame(30, $all['env_create_per_hour']);
        assertSame(12, $all['create_per_hour']);
    }
}
