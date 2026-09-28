<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;

/**
 * Tests unitarios de Request (M3: json()).
 */
class RequestTest
{
    public function testJsonVacioRetornaArrayVacio(): void
    {
        Request::resetJsonState();
        assertTrue([] === Request::json());
    }

    public function testJsonInvalidoRetornaArrayVacio(): void
    {
        Request::resetJsonState();
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $GLOBALS['HTTP_RAW_POST_DATA'] = '{invalid json';
        assertTrue([] === Request::json());
        unset($_SERVER['CONTENT_TYPE']);
    }

    public function testJsonContentTypeIncorrectoRetornaArrayVacio(): void
    {
        Request::resetJsonState();
        $_SERVER['CONTENT_TYPE'] = 'text/plain';
        $GLOBALS['HTTP_RAW_POST_DATA'] = '{"ok":1}';
        assertTrue([] === Request::json());
        unset($_SERVER['CONTENT_TYPE']);
    }

    public function testJsonCuerpoDemasiadoGrandeSeRechaza(): void
    {
        // A1: un Content-Length mayor al límite debe rechazar y activar la bandera.
        Request::resetJsonState();
        $_SERVER['CONTENT_LENGTH'] = 99999999;
        assertTrue([] === Request::json());
        assertTrue(Request::jsonTooLarge(), 'El cuerpo excede el límite y debe marcarse como demasiado grande');
        unset($_SERVER['CONTENT_LENGTH']);
    }

    public function testJsonNormalNoActivaBanderaDeSobredimension(): void
    {
        Request::resetJsonState();
        assertTrue([] === Request::json());
        assertFalse(Request::jsonTooLarge(), 'Sin cuerpo no debe marcarse como demasiado grande');
    }
}
