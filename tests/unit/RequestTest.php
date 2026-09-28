<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;

/**
 * Tests unitarios de Request (M3: json()).
 *
 * El cuerpo se inyecta con Request::setRequestBodyForTests(): en el runner
 * (CLI puro) php://input no es legible, así que sin el seam estos tests
 * pasaban por devolver [] siempre.
 */
class RequestTest
{
    public function setUp(): void
    {
        Request::setRequestBodyForTests(null);
        Request::resetJsonState();
        unset($_SERVER['CONTENT_LENGTH'], $_SERVER['CONTENT_TYPE'], $_SERVER['REQUEST_METHOD']);
    }

    public function testJsonVacioRetornaArrayVacio(): void
    {
        assertTrue([] === Request::json());
    }

    public function testJsonInvalidoRetornaArrayVacio(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        Request::setRequestBodyForTests('{invalid json');
        assertTrue([] === Request::json());
    }

    public function testJsonValidoSeDecodifica(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        Request::setRequestBodyForTests('{"a":1,"b":[true,null]}');
        assertTrue(['a' => 1, 'b' => [true, null]] === Request::json());
    }

    public function testJsonContentTypeIncorrectoRetornaArrayVacio(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'text/plain';
        Request::setRequestBodyForTests('{"ok":1}');
        assertTrue([] === Request::json());
    }

    public function testJsonSinContentTypeRetornaArrayVacio(): void
    {
        Request::setRequestBodyForTests('{"ok":1}');
        assertTrue([] === Request::json());
    }

    public function testJsonCuerpoDemasiadoGrandeSeRechaza(): void
    {
        // A1: un Content-Length mayor al límite debe rechazar y activar la bandera.
        $_SERVER['CONTENT_LENGTH'] = 99999999;
        assertTrue([] === Request::json());
        assertTrue(Request::jsonTooLarge(), 'El cuerpo excede el límite y debe marcarse como demasiado grande');
    }

    public function testJsonCuerpoRealPorEncimaDelLimiteSeRechaza(): void
    {
        // Otra puerta A1: aunque Content-Length mienta, el cuerpo real se mide.
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        Request::setRequestBodyForTests(str_repeat('x', 3000000));
        assertTrue([] === Request::json());
        assertTrue(Request::jsonTooLarge());
    }

    public function testJsonNormalNoActivaBanderaDeSobredimension(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        Request::setRequestBodyForTests('{"ok":1}');
        assertTrue(['ok' => 1] === Request::json());
        assertFalse(Request::jsonTooLarge(), 'Sin exceso de cuerpo no debe marcarse como demasiado grande');
    }
}