<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Services\RosterDoor;

/**
 * Fase A — los dos límites anti-abuso del fill salen de la configuración.
 *
 * El default no cambia el comportamiento histórico (10 / 10), pero una encuesta
 * de censo se contesta desde una IP compartida (kiosco, colegio, la NAT de una
 * sede) y el límite que protege al servidor deja fuera a la persona número 11 si
 * está fijo. Estos tests fijan que el número se puede subir sin tocar código.
 *
 * Requiere base de datos (RateLimiter escribe en `rate_limits`).
 */
class RateLimitConfigTest
{
    public function setUp(): void
    {
        if (!self::dbAvailable()) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }
    }

    public function tearDown(): void
    {
        Config::forgetForTesting();
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

    public function testLosLimitesVivenEnLaConfiguracionConDefaultDiez(): void
    {
        // Sin override, el default es 10 en los dos: el mismo número de siempre.
        // Se compara con `>= 1` y no con `=== 10` porque un `.env` de la máquina
        // de turno puede tenerlo subido; lo que se fija aquí es que la clave
        // EXISTE y es un entero usable, no que la instalación use el default.
        assertTrue((int) Config::get('app.limits.fill_submit_per_10min', 0) >= 1, 'el límite del fill existe');
        assertTrue((int) Config::get('app.limits.roster_code_per_15min', 0) >= 1, 'el límite del código existe');
    }

    public function testElOverrideDeTestLlegaALaClaveAnidada(): void
    {
        Config::setForTesting('app.limits.fill_submit_per_10min', 3);
        assertSame(3, (int) Config::get('app.limits.fill_submit_per_10min'));
    }

    public function testLaPuertaDeRosterHonraElLimiteConfigurado(): void
    {
        // Con tope 2 por IP, el tercer intento desde la misma IP se bloquea; con
        // el default 10 haría falta llegar a once. Es la prueba de que
        // `permitirIntento()` lee la config y no la constante a pelo.
        Config::setForTesting('app.limits.roster_code_per_15min', 2);

        $formId = 999999; // no hace falta que exista: `permitirIntento` no toca `forms`
        $ip = '10.99.0.' . random_int(1, 254);

        RateLimiter::reset('form_code', $formId . '|' . $ip);

        assertTrue(RosterDoor::permitirIntento($formId, 'AAA', $ip), 'primer intento');
        assertTrue(RosterDoor::permitirIntento($formId, 'BBB', $ip), 'segundo intento');
        assertFalse(RosterDoor::permitirIntento($formId, 'CCC', $ip), 'el tercero se bloquea con tope 2');

        RateLimiter::reset('form_code', $formId . '|' . $ip);
    }
}
