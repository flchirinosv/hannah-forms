<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppSettings;
use App\Core\Config;
use App\Core\Database;
use App\Core\UploadSettings;
use App\Services\Uploader;

/**
 * Límites de subida de archivos editables desde el panel (AG6).
 *
 * Precedencia: la fila gana, sin fila manda el `.env`. Un campo de formulario
 * solo puede restringir, nunca ampliar (lo aplica `Uploader`). El límite efectivo
 * tiene en cuenta `upload_max_filesize` de PHP, pero el valor guardado es el
 * configurado por la instancia.
 *
 * `UploadSettings` escribe en `app_settings`; se guardan las filas previas y se
 * reponen al terminar.
 */
class UploadSettingsTest
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

        foreach (UploadSettings::KEYS as $key) {
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
            ['a' => UploadSettings::MAX_SIZE_MB, 'b' => UploadSettings::MAX_FILES]
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
        Config::setForTesting('app', ['uploads' => ['max_size_mb' => 25, 'max_files' => 7]]);

        assertSame(25, UploadSettings::maxSizeMb());
        assertSame(7, UploadSettings::maxFiles());
    }

    public function testLaFilaGanaAlEnv(): void
    {
        Config::setForTesting('app', ['uploads' => ['max_size_mb' => 25, 'max_files' => 7]]);
        UploadSettings::setForTesting([
            UploadSettings::MAX_SIZE_MB => '15',
            UploadSettings::MAX_FILES => '3',
        ]);

        assertSame(15, UploadSettings::maxSizeMb());
        assertSame(3, UploadSettings::maxFiles());
    }

    public function testUploaderLeeDesdeUploadSettings(): void
    {
        UploadSettings::setForTesting([UploadSettings::MAX_SIZE_MB => '12', UploadSettings::MAX_FILES => '4']);

        $mb = Uploader::maxSizeMb([]);
        $files = Uploader::maxFiles([]);
        assertTrue($mb >= 1 && $mb <= 12, 'el límite global viene de UploadSettings');
        assertSame(4, $files);
    }

    public function testSaveGuardaYVaciarBorra(): void
    {
        $r = UploadSettings::save(['max_size_mb' => '20', 'max_files' => '6']);
        assertTrue($r['ok']);
        assertSame('20', (string) self::fila(UploadSettings::MAX_SIZE_MB)['value']);

        UploadSettings::save(['max_size_mb' => '', 'max_files' => '']);
        assertNull(self::fila(UploadSettings::MAX_SIZE_MB));
        assertNull(self::fila(UploadSettings::MAX_FILES));
    }

    public function testValoresInvalidosNoGuardanNada(): void
    {
        $r = UploadSettings::save(['max_size_mb' => '0', 'max_files' => '1']);
        assertFalse($r['ok']);
        assertNull(self::fila(UploadSettings::MAX_SIZE_MB));

        $r2 = UploadSettings::save(['max_size_mb' => '10', 'max_files' => '-1']);
        assertFalse($r2['ok']);
        assertNull(self::fila(UploadSettings::MAX_FILES));
    }

    public function testAllDistingueLoGuardadoDeLoResuelto(): void
    {
        Config::setForTesting('app', ['uploads' => ['max_size_mb' => 10, 'max_files' => 5]]);
        UploadSettings::setForTesting([UploadSettings::MAX_SIZE_MB => '16']);

        $all = UploadSettings::all();
        assertSame('16', $all['stored_max_size_mb']);
        assertSame('', $all['stored_max_files']);
        assertSame(10, $all['env_max_size_mb']);
        assertSame(16, $all['max_size_mb']);
    }
}
