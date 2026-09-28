<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FormSettings;
use App\Services\Turnstile;

/**
 * B2 — Cloudflare Turnstile (verificación bot opt-in por formulario).
 *
 * Sin red: el transporte de siteverify se inyecta con
 * Turnstile::setTransportForTesting() y las claves se simulan con
 * setKeysForTesting() (Config no tiene setter). Al fijar las claves, los tests
 * no dependen de lo que tenga el .env de la máquina.
 */
class TurnstileTest
{
    public function setUp(): void
    {
        Turnstile::setTransportForTesting(null);
        Turnstile::setKeysForTesting(null, null);
    }

    public function tearDown(): void
    {
        Turnstile::setTransportForTesting(null);
        Turnstile::setKeysForTesting(null, null);
    }

    public function testExitoCuandoCloudflareDiceSuccess(): void
    {
        Turnstile::setKeysForTesting('site', 'secreto');
        Turnstile::setTransportForTesting(static fn(string $body): array => ['success' => true]);
        assertTrue(Turnstile::verify('tok-abc', '203.0.113.5'));
    }

    public function testFalloCuandoCloudflareNiegaElToken(): void
    {
        Turnstile::setKeysForTesting('site', 'secreto');
        Turnstile::setTransportForTesting(static fn(string $b): array => [
            'success' => false,
            'error-codes' => ['invalid-input-response'],
        ]);
        assertFalse(Turnstile::verify('tok-abc'));
    }

    public function testTokenVacioNiLlegaAlTransporte(): void
    {
        Turnstile::setKeysForTesting('site', 'secreto');
        $called = false;
        Turnstile::setTransportForTesting(static function (string $b) use (&$called): array {
            $called = true;
            return ['success' => true];
        });
        assertFalse(Turnstile::verify('   '));
        assertFalse($called, 'sin token no hay nada que verificar en la API');
    }

    public function testRedCaidaEquivaleAFalloYNoRompeElRequest(): void
    {
        Turnstile::setKeysForTesting('site', 'secreto');
        Turnstile::setTransportForTesting(static function (string $b): array {
            throw new \RuntimeException('sin red');
        });
        assertFalse(Turnstile::verify('tok-abc'));
    }

    public function testSecretoVacioNuncaVerificaAunqueLaApiDigaQueSi(): void
    {
        // Instancia sin TURNSTILE_SECRET_KEY: no se llama a la API y no se
        // "verifica" nada (fallo seguro: nunca se abre un form "protegido").
        Turnstile::setKeysForTesting('site', '');
        $called = false;
        Turnstile::setTransportForTesting(static function (string $b) use (&$called): array {
            $called = true;
            return ['success' => true];
        });
        assertFalse(Turnstile::verify('tok-abc'));
        assertFalse($called);
    }

    public function testElBodyMandaSecretoTokenEIpValida(): void
    {
        Turnstile::setKeysForTesting('site', 'abc123');
        $captured = '';
        Turnstile::setTransportForTesting(static function (string $body) use (&$captured): array {
            $captured = $body;
            return ['success' => true];
        });

        assertTrue(Turnstile::verify('tok-abc', '203.0.113.5'));
        parse_str($captured, $q);
        assertSame('abc123', $q['secret'] ?? null);
        assertSame('tok-abc', $q['response'] ?? null);
        assertSame('203.0.113.5', $q['remoteip'] ?? null);

        // Una IP que no es IP no viaja (no se manda basura a la API).
        $captured = '';
        assertTrue(Turnstile::verify('tok-abc', 'no-es-ip'));
        parse_str($captured, $q2);
        assertFalse(isset($q2['remoteip']));
    }

    public function testIsConfiguredExigeLasDosClaves(): void
    {
        Turnstile::setKeysForTesting(null, null);
        Turnstile::setKeysForTesting('site', '');
        assertFalse(Turnstile::isConfigured(), 'sin secret no hay widget');
        Turnstile::setKeysForTesting('', 'secret');
        assertFalse(Turnstile::isConfigured(), 'sin site no hay widget');
        Turnstile::setKeysForTesting('site', 'secret');
        assertTrue(Turnstile::isConfigured());
    }

    public function testEsquemaAntiBotOptInYWhitelist(): void
    {
        $defaults = FormSettings::defaults();
        assertTrue(array_key_exists('anti_bot', $defaults));
        assertFalse($defaults['anti_bot']['enabled']);

        assertTrue(FormSettings::normalize(['anti_bot' => ['enabled' => true]])['anti_bot']['enabled']);
        assertFalse(FormSettings::normalize(['anti_bot' => ['enabled' => false]])['anti_bot']['enabled']);
        // Una clave random que nadie controla no se cuela en el whitelist.
        $n = FormSettings::normalize(['anti_bot' => ['bogus' => 1, 'enabled' => true]]);
        assertTrue($n['anti_bot']['enabled']);
        assertSame(1, count($n['anti_bot']));
    }
}