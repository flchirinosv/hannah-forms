<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Session;
use App\Models\User;

/**
 * Test de integración de Auth (login, logout, check, user, role, A7).
 * Usa base de datos real y limpia sus datos entre ejecuciones.
 */
class AuthTest
{
    private static bool $envLoaded = false;
    private static ?int $userId = null;

    public function setUp(): void
    {
        self::loadEnv();
        self::cleanupResiduals();

        $email = 'authtest-' . bin2hex(random_bytes(4)) . '@test.local';
        self::$userId = User::create('Auth Test', $email, 'password', 2);
    }

    public function testLoginLogoutCiclo(): void
    {
        Auth::login((int) self::$userId);
        assertTrue(Auth::check());
        assertSame((int) self::$userId, Auth::id());
        assertNotNull(Auth::user());
        assertSame((int) self::$userId, (int) Auth::user()['id']);

        Auth::logout();
        assertFalse(Auth::check());
        assertNull(Auth::id());
        assertNull(Auth::user());
    }

    public function testUserCargaRol(): void
    {
        Auth::login((int) self::$userId);
        $user = Auth::user();
        assertNotNull($user);
        assertArrayHasKey('role_name', $user);
        assertSame('user', $user['role_name']);
        Auth::logout();
    }

    public function testUsuarioDesactivadoCierraSesion(): void
    {
        // Marcar usuario como desactivado (A7)
        \App\Core\Database::getInstance(\App\Core\Config::db())->execute(
            'UPDATE users SET is_active = 0 WHERE id = :id',
            ['id' => (int) self::$userId]
        );

        Auth::login((int) self::$userId);
        // A7: Auth::user() debe detectar is_active=0, hacer logout y devolver null
        assertNull(Auth::user());
        assertFalse(Auth::check());
        assertNull(Auth::id());
    }

    public function testDesactivacionTieneEfectoEnSiguienteRequest(): void
    {
        // Pre-cargar la caché del request (como un usuario navegando)
        Auth::login((int) self::$userId);
        assertNotNull(Auth::user());
        assertTrue(Auth::check());

        // El admin desactiva la cuenta en BD
        \App\Core\Database::getInstance(\App\Core\Config::db())->execute(
            'UPDATE users SET is_active = 0 WHERE id = :id',
            ['id' => (int) self::$userId]
        );

        // Mismo request: la caché se mantiene consistente
        assertNotNull(Auth::user());

        // Nuevo request: se revalida contra BD y se cierra sesión (A7 real)
        Auth::forgetCache();
        assertNull(Auth::user());
        assertFalse(Auth::check());
        assertNull(Auth::id());
    }

    public function testCambioDeRolTieneEfectoEnSiguienteRequest(): void
    {
        Auth::login((int) self::$userId);
        assertSame('user', Auth::role());

        // El admin promueve al usuario a admin en BD
        \App\Core\Database::getInstance(\App\Core\Config::db())->execute(
            'UPDATE users SET role_id = 1 WHERE id = :id',
            ['id' => (int) self::$userId]
        );

        assertSame('user', Auth::role(), 'Mismo request: rol cacheado sin cambios');

        // Nuevo request: el nuevo rol se refleja
        Auth::forgetCache();
        assertSame('admin', Auth::role());
    }

    public function tearDown(): void
    {
        if (self::$userId !== null) {
            \App\Core\Database::getInstance(\App\Core\Config::db())->execute(
                'DELETE FROM users WHERE id = :id',
                ['id' => (int) self::$userId]
            );
        }
        Session::destroy();
        self::$userId = null;
    }

    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            \App\Core\Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
        }
    }

    private static function cleanupResiduals(): void
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        foreach ($db->fetchAll("SELECT id FROM users WHERE email LIKE '%@test.local'") as $u) {
            $db->execute('DELETE FROM users WHERE id = :id', ['id' => (int) $u['id']]);
        }
    }
}
