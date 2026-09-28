<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;

/**
 * F4 — Request::ip() (superficie de red). Fija el contrato del trust proxy:
 * con `trust_proxy=false` (default) las cabeceras forjadas por el cliente no
 * valen nada y se usa REMOTE_ADDR; con `true` se lee la primera IP válida de
 * X-Forwarded-For (la del cliente, no la de los proxies) y se cae a
 * X-Real-IP/REMOTE_ADDR. Ningún valor válido → 0.0.0.0, que es el único estado
 * "sin IP" admitido por el rate limiter.
 */
class RequestIpTest
{
    public function setUp(): void
    {
        Request::setTrustProxyForTesting(null);
        unset(
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_X_REAL_IP']
        );
    }

    public function tearDown(): void
    {
        Request::setTrustProxyForTesting(null);
        unset(
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_X_REAL_IP']
        );
    }

    public function testSinProxyUsaRemoteAddr(): void
    {
        Request::setTrustProxyForTesting(false);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        assertSame('203.0.113.7', Request::ip());
    }

    public function testSinProxyIgnoraLasCabecerasForjadas(): void
    {
        // Un cliente cualquiera puede mandar esto; sin router de confianza
        // debe prevalecer REMOTE_ADDR (el bypass de rate limiting clásico).
        Request::setTrustProxyForTesting(false);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6';
        $_SERVER['HTTP_X_REAL_IP'] = '7.7.7.7';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        assertSame('203.0.113.7', Request::ip());
    }

    public function testSinProxySinRemoteAddrDevuelveZero(): void
    {
        Request::setTrustProxyForTesting(false);

        assertSame('0.0.0.0', Request::ip());
    }

    public function testSinProxyRemoteAddrInvalidaDevuelveZero(): void
    {
        Request::setTrustProxyForTesting(false);
        $_SERVER['REMOTE_ADDR'] = 'no-soy-una-ip';

        assertSame('0.0.0.0', Request::ip());
    }

    public function testConProxyTomaLaPrimeraIpDeLaCadena(): void
    {
        Request::setTrustProxyForTesting(true);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.10, 198.51.100.254';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.254';

        // El cliente real va primero; la respuesta no debe ser la del proxy.
        assertSame('198.51.100.10', Request::ip());
    }

    public function testConProxySaltaLosElementosInvalidos(): void
    {
        Request::setTrustProxyForTesting(true);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = ' no-es-ip , 203.0.113.99 , 10.0.0.1';

        assertSame('203.0.113.99', Request::ip());
    }

    public function testConProxySinXffCaeAXRealIp(): void
    {
        Request::setTrustProxyForTesting(true);
        $_SERVER['HTTP_X_REAL_IP'] = '203.0.113.50';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.1';

        assertSame('203.0.113.50', Request::ip());
    }

    public function testConProxyNingunaIpValidaDevuelveZero(): void
    {
        Request::setTrustProxyForTesting(true);
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'hola,  ';
        $_SERVER['HTTP_X_REAL_IP'] = '';
        unset($_SERVER['REMOTE_ADDR']);

        assertSame('0.0.0.0', Request::ip());
    }
}