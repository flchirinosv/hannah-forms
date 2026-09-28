<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\RateLimiter;

/**
 * Test de integración de RateLimiter. Requiere base de datos accesible.
 * Usa identificadores únicos por test y limpia sus datos tras ejecutar.
 */
class RateLimiterTest
{
    private static bool $envLoaded = false;

    private static string $scope = 'test:rate';
    private static string $id = '';

    public function setUp(): void
    {
        self::loadEnv();
        if (!self::dbAvailable()) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }
        // Identificador único por run para no colisionar con otros datos
        self::$id = 'test-' . bin2hex(random_bytes(4));
        // Asegurar limpieza inicial
        RateLimiter::reset(self::$scope, self::$id);
    }

    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
        }
    }

    private static function dbAvailable(): bool
    {
        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function testPermitidoPorDefecto(): void
    {
        assertTrue(RateLimiter::isAllowed(self::$scope, self::$id, 5, 300));
    }

    public function testBloqueaTrasSuperarLimite(): void
    {
        // Antes de cualquier intento se permite
        assertTrue(RateLimiter::isAllowed(self::$scope, self::$id, 5, 300));
        // Tras 5 intentos (máximo permitido) se bloquea el 6º
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::recordHit(self::$scope, self::$id, 300);
        }
        assertFalse(RateLimiter::isAllowed(self::$scope, self::$id, 5, 300));
        RateLimiter::reset(self::$scope, self::$id);
    }

    public function testResetRestableceContador(): void
    {
        RateLimiter::recordHit(self::$scope, self::$id, 300);
        RateLimiter::recordHit(self::$scope, self::$id, 300);
        RateLimiter::reset(self::$scope, self::$id);
        assertTrue(RateLimiter::isAllowed(self::$scope, self::$id, 2, 300));
        assertTrue(RateLimiter::isAllowed(self::$scope, self::$id, 5, 300));
        RateLimiter::reset(self::$scope, self::$id);
    }

    public function testLimiteNoCompartidoEntreIdentificadores(): void
    {
        $other = self::$id . '-other';
        RateLimiter::reset(self::$scope, $other);
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::recordHit(self::$scope, self::$id, 300);
        }
        // El otro identificador no se ve afectado
        assertTrue(RateLimiter::isAllowed(self::$scope, $other, 3, 300));
        RateLimiter::reset(self::$scope, $other);
    }
}
