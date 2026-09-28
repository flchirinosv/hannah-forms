<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Response;

/**
 * Tests unitarios de Response (M4: open redirect del sanitizador interno).
 *
 * No invocan redirect()/abort() (en CLI no emiten cabeceras y el flujo no
 * corta): la barrera real está en el sanitizador que las alimenta, y es lo
 * que se aserta aquí. El registro lastHalt() se cubre en los tests de
 * controllers (acción real + return;).
 */
class ResponseTest
{
    public function testRedirectRutaRelativaPermitida(): void
    {
        assertSame('/dashboard', Response::sanitizeRedirectUrl('/dashboard'));
        assertSame('/login?next=/f/mi-forma', Response::sanitizeRedirectUrl('/login?next=/f/mi-forma'));
        assertSame('/amsi/page/1', Response::sanitizeRedirectUrl('/amsi/page/1'));
    }

    public function testRedirectRutaVaciaSeCorrigeARaiz(): void
    {
        assertSame('/', Response::sanitizeRedirectUrl(''));
        assertSame('/', Response::sanitizeRedirectUrl('/'));
    }

    public function testRedirectRutaAbsolutaHttpSeCorrigeARaiz(): void
    {
        assertSame('/', Response::sanitizeRedirectUrl('http://evil.com/'));
        assertSame('/', Response::sanitizeRedirectUrl('https://evil.com/x'));
        assertSame('/', Response::sanitizeRedirectUrl('javascript:alert(1)'));
    }

    public function testRedirectProtokollRelativeYBackslashSeCorrigeanARaiz(): void
    {
        // '//host' y '/\host' son "protocol-relative" para el navegador.
        assertSame('/', Response::sanitizeRedirectUrl('//evil.com'));
        assertSame('/', Response::sanitizeRedirectUrl('/\\evil.com'));
        assertSame('/', Response::sanitizeRedirectUrl('///evil.com'));
    }

    public function testRedirectConCrlfOControlSeCorrigeARaiz(): void
    {
        // Header injection vía 'Location: ...' — respetado el CRLF.
        assertSame('/', Response::sanitizeRedirectUrl("/evil.com\r\nX-Injected: 1"));
        assertSame('/', Response::sanitizeRedirectUrl("/x\nSet-Cookie: a=1"));
        assertSame('/', Response::sanitizeRedirectUrl("/pa\x00th"));
    }

    public function testRedirectConEsquemaOBarraDobleEncodadoSeCorrigeARaiz(): void
    {
        assertSame('/', Response::sanitizeRedirectUrl('/%2f%2fevil.com'));
        assertSame('/', Response::sanitizeRedirectUrl('/%5cevil.com'));
        assertSame('/', Response::sanitizeRedirectUrl('/%2f/http://evil.com'));
        assertSame('/', Response::sanitizeRedirectUrl('/x%0d%0aSet-Cookie: a=1'));
        // Un esquema tras una sola barra es una ruta del MISMO origen
        // ('/http://…' no es protocol-relative): se permite.
        assertSame('/http://evil.com', Response::sanitizeRedirectUrl('/http://evil.com'));
        // Anchors y querystrings percent-encoded no son un esquema: se permiten.
        assertSame('/f/1?next=%2Fok', Response::sanitizeRedirectUrl('/f/1?next=%2Fok'));
    }

    public function testExternalUrlSoloAdmiteHttpHttpsConHost(): void
    {
        assertSame('https://gracias.example.com/x', Response::sanitizeExternalUrl('https://gracias.example.com/x'));
        assertSame('/', Response::sanitizeExternalUrl('javascript:alert(1)'));
        assertSame('/', Response::sanitizeExternalUrl('ftp://example.com/x'));
        assertSame('/', Response::sanitizeExternalUrl('http:///sin-host'));
        assertSame('/', Response::sanitizeExternalUrl(''));
    }

    public function testExternalUrlRechazaUserinfoYControl(): void
    {
        assertSame('/', Response::sanitizeExternalUrl('https://user:pass@example.com/x'));
        assertSame('/', Response::sanitizeExternalUrl('https://example.com/x' . "\r\n"));
    }

    public function testLastHaltParaRedisabledGrabarSoloConSeamActivado(): void
    {
        // El registro es opt-in: con la bandera apagada no debe quedar nada.
        Response::setRecordHalt(true);
        Response::redirect('/admin');
        assertSame(['type' => 'redirect', 'url' => '/admin'], Response::lastHalt());

        Response::setRecordHalt(false);
        Response::redirect('/otra');
        assertNull(Response::lastHalt());
        Response::setRecordHalt(true);
        assertNull(Response::lastHalt(), 'setRecordHalt(false) debe descartar el log');
    }
}