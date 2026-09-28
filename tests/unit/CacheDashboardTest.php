<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;

/**
 * Caché del dashboard (2026-09-28) — Cache::remember/forgetLike + la
 * invalidación que DISPARAN las mutaciones de forms y submissions.
 *
 * Contrato: `remember()` solo puede re-ejecutar el computo en pérdida de
 * caché; `forgetLike('dash:')` borra el prefijo entero; Form::create y
 * Submission::create llaman a esa invalidación (F2: con tener el id antes que
 * la invalidación — un DELETE en medio resetea LAST_INSERT_ID en MariaDB).
 */
class CacheDashboardTest
{
    private static bool $envLoaded = false;
    private static ?int $userId = null;

    public function setUp(): void
    {
        self::loadEnv();
        self::cleanupResiduals();
        self::$userId = User::create('Cache User', 'cache-' . bin2hex(random_bytes(4)) . '@test.local', 'password', 2);
    }

    public function tearDown(): void
    {
        self::cleanupResiduals();
        self::cleanCache();
    }

    public function testRememberCalculaUnaSolaVezDentroDelTtl(): void
    {
        self::cleanCache();
        $key = 'dash:test-' . bin2hex(random_bytes(4));
        $calls = 0;
        $compute = static function () use (&$calls) {
            $calls++;
            return ['valor' => $calls];
        };

        $first = Cache::remember($key, 60, $compute);
        $second = Cache::remember($key, 60, $compute);

        assertSame(['valor' => 1], $first, 'primera lectura calcula');
        assertSame(['valor' => 1], $second, 'segunda lectura usa la caché (no recalcula)');
        assertSame(1, $calls, 'el computo debe ejecutarse UNA sola vez');
    }

    public function testForgetLikeInvalidaTodoElPrefijo(): void
    {
        self::cleanCache();
        $prefix = 'dash:test-' . bin2hex(random_bytes(4));
        $keyA = $prefix . ':a';
        $keyB = $prefix . ':b';
        $calls = 0;
        $compute = static function () use (&$calls) {
            $calls++;
            return ['c' => $calls];
        };

        Cache::remember($keyA, 60, $compute);
        Cache::remember($keyB, 60, $compute);
        assertSame(2, $calls, 'dos claves = dos cálculos');

        Cache::forgetLike($prefix);
        Cache::remember($keyA, 60, $compute);
        Cache::remember($keyB, 60, $compute);
        assertSame(4, $calls, 'después del forgetLike ambos recalculan');
    }

    public function testFormCreateInvalidaLaCacheDelDashboard(): void
    {
        $marker = 'dash:marker-' . bin2hex(random_bytes(4));
        Cache::remember($marker, 3600, static fn() => ['vivo' => true]);

        $formId = Form::create((int) self::$userId, 'Cache Invalida-' . bin2hex(random_bytes(3)));

        $db = Database::getInstance(Config::db());
        $row = $db->fetch('SELECT cache_key FROM cache WHERE cache_key = :k', ['k' => $marker]);
        assertSame(null, $row, 'Form::create debe invalidar la caché de dashboards');

        $db->execute('DELETE FROM forms WHERE id = :id', ['id' => $formId]);
    }

    public function testSubmissionCreateInvalidaLaCacheDelDashboard(): void
    {
        $marker = 'dash:marker-' . bin2hex(random_bytes(4));
        Cache::remember($marker, 3600, static fn() => ['vivo' => true]);

        $formId = Form::create((int) self::$userId, 'Cache Sub-' . bin2hex(random_bytes(3)));
        Cache::remember($marker, 3600, static fn() => ['vivo' => true]); // restaurar tras el bump del Form::create
        $subId = Submission::create($formId, '127.0.0.1');

        $db = Database::getInstance(Config::db());
        $row = $db->fetch('SELECT cache_key FROM cache WHERE cache_key = :k', ['k' => $marker]);
        assertSame(null, $row, 'Submission::create debe invalidar la caché de dashboards');

        $db->execute('DELETE FROM submissions WHERE id = :id', ['id' => $subId]);
        $db->execute('DELETE FROM forms WHERE id = :id', ['id' => $formId]);
    }

    /* ---------- Helpers ---------- */

    private static function cleanCache(): void
    {
        try {
            Cache::resetRequestFlag();
            $db = Database::getInstance(Config::db());
            $db->execute("DELETE FROM cache WHERE cache_key LIKE 'dash:test-%' OR cache_key LIKE 'dash:marker-%'");
        } catch (\Throwable $e) {
        }
    }

    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
            register_shutdown_function(static function () {
                try {
                    self::cleanupResiduals();
                } catch (\Throwable $e) {
                }
            });
        }
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute("DELETE FROM users WHERE email LIKE '%@test.local'");
        self::$userId = null;
    }
}