<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\DashboardLimit;
use App\Core\Env;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Core\Session;
use App\Controllers\FormController;
use App\Models\Form;
use App\Models\User;

/**
 * F5.3 — Límites del dashboard por USUARIO (creaciones y exportaciones).
 *
 * Config no tiene setter, así que los límites se fuerzan sembrando filas de
 * `rate_limits` con RateLimiter directamente con el MISMO scope+identifier que
 * usa DashboardLimit (`dash:create`/`dash:export` + el id de usuario), no con
 * DB::execute a pelo: así el test rompe si se cambia la convención de claves.
 */
class DashboardLimitTest
{
    private static bool $envLoaded = false;
    private static ?int $userId = null;

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
        self::$userId = User::create('Test F53', 'f53-' . bin2hex(random_bytes(4)) . '@test.local', 'password');
        Auth::forgetCache();
        Response::setRecordHalt(false);
    }

    public function tearDown(): void
    {
        Session::remove('user_id');
        Auth::forgetCache();
        Response::setRecordHalt(false);
    }

    public function testSinSesionLosDosLimitesDejanPasar(): void
    {
        Session::remove('user_id');
        Auth::forgetCache();
        assertTrue(DashboardLimit::createAllowed(), 'anon (solo tests) no comparte contador de creaciones');
        assertTrue(DashboardLimit::exportAllowed(), 'anon (solo tests) no comparte contador de exportaciones');
    }

    public function testCrearRegistraElIntentoDelUsuario(): void
    {
        self::authAs(self::$userId);
        assertTrue(DashboardLimit::createAllowed());
        $row = Database::getInstance(Config::db())->fetch(
            'SELECT COUNT(*) AS c FROM rate_limits WHERE scope = :s AND identifier = :i',
            ['s' => 'dash:create', 'i' => (string) self::$userId]
        );
        assertSame(1, (int) ($row['c'] ?? 0), 'un create permitido deja su contador en la ventana');
    }

    public function testExcesoDeCreacionesBloquea(): void
    {
        self::authAs(self::$userId);
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::recordHit('dash:create', (string) self::$userId, DashboardLimit::WINDOW);
        }
        assertFalse(DashboardLimit::createAllowed(), '30 creaciones en la hora => bloqueado');
        assertTrue(DashboardLimit::exportAllowed(), 'las exportaciones tienen su propio contador y no se contaminan');
    }

    public function testExcesoDeExportacionesBloquea(): void
    {
        self::authAs(self::$userId);
        for ($i = 0; $i < 120; $i++) {
            RateLimiter::recordHit('dash:export', (string) self::$userId, DashboardLimit::WINDOW);
        }
        assertFalse(DashboardLimit::exportAllowed(), '120 exportaciones en la hora => bloqueado');
        assertTrue(DashboardLimit::createAllowed(), 'el límite de creación sigue libre');
    }

    public function testCrearFormularioBloqueadoNoCrea(): void
    {
        self::authAs(self::$userId);
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::recordHit('dash:create', (string) self::$userId, DashboardLimit::WINDOW);
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['_csrf_token'] = 'f53-token';
        $_POST = ['title' => 'Form limitado', '_token' => 'f53-token'];
        Response::setRecordHalt(true);
        $controller = new FormController();
        ob_start();
        $controller->create();
        $halt = Response::lastHalt();
        ob_end_clean();
        Response::setRecordHalt(false);

        assertSame('redirect', $halt['type'] ?? null);
        assertEquals('/dashboard', $halt['url'] ?? null, 'vuelve al dashboard con el aviso, no al builder');
        assertSame(0, count(self::formsOf(self::$userId)), 'no se creó ningún formulario');
        assertTrue(
            str_contains((string) Session::getFlash('error', ''), 'límite de formularios por hora'),
            'el flash deja claro que es el techo por hora'
        );
    }

    public function testCrearFormularioNormalRegistraUnIntento(): void
    {
        self::authAs(self::$userId);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['_csrf_token'] = 'f53-token-ok';
        $_POST = ['title' => 'Form normal', '_token' => 'f53-token-ok'];
        Response::setRecordHalt(true);
        $controller = new FormController();
        ob_start();
        $controller->create();
        $halt = Response::lastHalt();
        ob_end_clean();
        Response::setRecordHalt(false);

        assertSame('redirect', $halt['type'] ?? null);
        assertSame(1, count(self::formsOf(self::$userId)), 'el envío sano crea el formulario');
        $row = Database::getInstance(Config::db())->fetch(
            'SELECT attempts FROM rate_limits WHERE scope = :s AND identifier = :i',
            ['s' => 'dash:create', 'i' => (string) self::$userId]
        );
        assertEquals(1, (int) ($row['attempts'] ?? 0), 'la creación consumió su intento de la ventana');
    }

    /* ---------- Helpers ---------- */

    private static function authAs(int $userId): void
    {
        Session::set('user_id', $userId);
        Auth::forgetCache();
    }

    /** @return array<int, array<string, mixed>> */
    private static function formsOf(int $userId): array
    {
        return Database::getInstance(Config::db())->fetchAll(
            'SELECT * FROM forms WHERE user_id = :uid',
            ['uid' => $userId]
        );
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
    }
}