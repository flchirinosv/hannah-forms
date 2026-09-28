<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RedirectGuard;

/**
 * F5.1 — Allowlist de dominios para `thanks.redirect_url`.
 *
 * El default es BLOQUEAR TODO: sin APP_REDIRECT_ALLOWLIST (o sin seam), una
 * URL externa no se sigue y el thanks interno muestra el aviso. Los tests que
 * quieren permitir una URL la fijan con `setAllowlistForTesting()` (Config no
 * tiene setter); el seam se debe restaurar a null, o las respuestas de lo que
 * corra después cambian.
 */
class RedirectGuardTest
{
    public function tearDown(): void
    {
        RedirectGuard::setAllowlistForTesting(null);
    }

    public function testSinAllowlistTodoBloqueado(): void
    {
        RedirectGuard::setAllowlistForTesting([]);
        assertFalse(RedirectGuard::hostAllowed('https://example.com/gracias'));
        assertFalse(RedirectGuard::hostAllowed('http://127.0.0.1/x'));
    }

    public function testHostEnListaPermitido(): void
    {
        RedirectGuard::setAllowlistForTesting(['example.com']);
        assertTrue(RedirectGuard::hostAllowed('https://example.com/gracias'));
        assertTrue(RedirectGuard::hostAllowed('http://example.com:8080/gracias'), 'el puerto no forma parte del host');
        assertTrue(RedirectGuard::hostAllowed('https://example.com/path?q=1#f'), 'ruta/query no afectan');
    }

    public function testHostFueraDeListaBloqueado(): void
    {
        RedirectGuard::setAllowlistForTesting(['example.com']);
        assertFalse(RedirectGuard::hostAllowed('https://evil.com/'));
        assertFalse(RedirectGuard::hostAllowed('https://example.com.evil.com/'), 'el prefijo no hace que case con el dominio');
        assertFalse(RedirectGuard::hostAllowed('https://subdominio.example.com/'), 'subdominio distinto NO está implícito');
        assertFalse(RedirectGuard::hostAllowed(''), 'URL vacía');
        assertFalse(RedirectGuard::hostAllowed('/solo-ruta'), 'ruta relativa no tiene host');
    }

    public function testComparacionDeHostSinSensibilidadAMinusculas(): void
    {
        RedirectGuard::setAllowlistForTesting(['Example.COM']);
        assertTrue(RedirectGuard::hostAllowed('HTTPS://example.com/'), 'host y lista se normalizan a minúsculas');
    }

    public function testHostOfDevuelveHostLimpio(): void
    {
        assertSame('example.com', RedirectGuard::hostOf('https://Example.com:8443/x'));
        assertSame('127.0.0.1', RedirectGuard::hostOf('http://127.0.0.1:8080'));
        assertSame('', RedirectGuard::hostOf('javascript:alert(1)'));
        assertSame('', RedirectGuard::hostOf(''));
    }
}