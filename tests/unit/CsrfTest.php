<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Csrf;
use App\Core\Session;

class CsrfTest
{
    public function setUp(): void
    {
        // Limpiar el token CSRF de la sesion para cada test
        // (evita session_destroy() que requiere sesion activa en CLI)
        Session::remove('_csrf_token');
    }

    public function testTokenSeGeneraUnaVez(): void
    {
        $token1 = Csrf::token();
        $token2 = Csrf::token();
        assertSame($token1, $token2);
    }

    public function testVerifyConTokenCorrecto(): void
    {
        $token = Csrf::token();
        assertTrue(Csrf::verify($token));
    }

    public function testVerifyConTokenIncorrecto(): void
    {
        Csrf::token();
        assertFalse(Csrf::verify('token-incorrecto'));
    }

    public function testVerifyConNull(): void
    {
        Csrf::token();
        assertFalse(Csrf::verify(null));
    }

    public function testVerifyHeaderConTokenCorrecto(): void
    {
        $token = Csrf::token();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        assertTrue(Csrf::verifyHeader());
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testVerifyHeaderSinToken(): void
    {
        Csrf::token();
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        assertFalse(Csrf::verifyHeader());
    }

    public function testVerifyHeaderConTokenIncorrecto(): void
    {
        Csrf::token();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'malo';
        assertFalse(Csrf::verifyHeader());
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testFieldContieneToken(): void
    {
        $token = Csrf::token();
        $field = Csrf::field();
        assertTrue(str_contains($field, 'name="_token"'));
        assertTrue(str_contains($field, htmlspecialchars($token, ENT_QUOTES)));
    }
}
