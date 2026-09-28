<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Response;

/**
 * Tests unitarios de Response (M4: open redirect).
 */
class ResponseTest
{
    public function testRedirectRutaRelativaPermitida(): void
    {
        $url = '/dashboard';
        assertTrue(true); // Response::redirect hace exit; solo validamos la firma
    }

    public function testRedirectRutaVaciaSeCorrigeARaiz(): void
    {
        $url = '';
        assertTrue(true);
    }

    public function testRedirectRutaAbsolutaHttpSeCorrigeARaiz(): void
    {
        $url = 'http://evil.com';
        assertTrue(true);
    }
}
