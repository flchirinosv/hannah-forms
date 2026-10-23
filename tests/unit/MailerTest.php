<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Mailer;

/**
 * Tests del cliente SMTP propio (P5): construcción del sobre, codificación
 * UTF-8, dot-stuffing, saneo de inyección de cabeceras y transporte
 * inyectable (sin tocar la red).
 */
class MailerTest
{
    /** @var array<int,array{envelope:array,config:array}> Sobre capturados por el transporte de prueba */
    private array $sent = [];

    private array $cfg = [
        'host'         => 'smtp.example.com',
        'port'         => 587,
        'encryption'   => 'tls',
        'user'         => 'usuario',
        'password'     => 'secreto',
        'from_address' => 'no-reply@example.com',
        'from_name'    => 'VanillaForms',
        'timeout'      => 15,
    ];

    public function setUp(): void
    {
        $this->sent = [];
        Mailer::setTransport(function (array $envelope, array $config): void {
            $this->sent[] = ['envelope' => $envelope, 'config' => $config];
        });
        Mailer::setConfigOverride($this->cfg);
    }

    public function tearDown(): void
    {
        // Sin esto, el transporte estático y el override de configuración se
        // filtran a los tests que vienen después: las notificaciones reales del
        // submit acabarían "enviándose" (correctamente, sin red) al closure de
        // prueba y la config de SMTP del test quedaría activa para el resto.
        Mailer::setTransport(null);
        Mailer::setConfigOverride(null);
    }

    public function testIsConfiguredFalseConHostVacio(): void
    {
        Mailer::setTransport(null);
        Mailer::setConfigOverride(['host' => '']);
        assertFalse(Mailer::isConfigured());

        Mailer::setConfigOverride(['host' => 'smtp.example.com']);
        assertTrue(Mailer::isConfigured());
    }

    public function testSendRegistraSobreEnTransporte(): void
    {
        $ok = Mailer::send(['a@example.com'], 'Asunto', '<p>Hola</p>', 'Hola');
        assertSame(true, $ok);
        assertSame(1, count($this->sent));
        assertSame('a@example.com', $this->sent[0]['envelope']['to'][0]);
        assertSame('Asunto', $this->sent[0]['envelope']['subject']);
        assertSame($this->cfg, $this->sent[0]['config']);
    }

    public function testSobreEsMultipartYConDotStuffing(): void
    {
        $env = Mailer::buildEnvelope(
            'no-reply@example.com',
            'VanillaForms',
            ['a@example.com'],
            'Asunto',
            ".línea con punto\n<p>ok</p>",
            'Texto plano'
        );

        assertTrue(str_contains($env['data'], 'multipart/alternative'));
        assertTrue(str_contains($env['data'], 'text/html'));
        assertTrue(str_contains($env['data'], 'text/plain'));
        assertTrue(str_contains($env['data'], '..línea con punto'), 'debe aplicar dot-stuffing');
        assertTrue(str_contains($env['data'], 'From: VanillaForms <no-reply@example.com>'));
        assertTrue(str_contains($env['data'], 'To: a@example.com'));
        assertTrue(str_contains($env['data'], 'MIME-Version: 1.0'));
    }

    public function testAsuntoConAcentosSeCodificaUtf8(): void
    {
        $env = Mailer::buildEnvelope(
            'no-reply@example.com',
            'VanillaForms',
            ['a@example.com'],
            'Nueva respuesta ñandú ✓',
            '<p>h</p>',
            'h'
        );
        assertTrue(str_contains($env['headers'], '=?UTF-8?B?'), 'asunto no ASCII debe ir en encoded-words');
    }

    public function testInyeccionDeCabecerasNeutralizada(): void
    {
        $env = Mailer::buildEnvelope(
            'no-reply@example.com',
            'VanillaForms',
            ['a@example.com'],
            "Asunto\r\nBcc: evil@x.com",
            '<p>h</p>',
            'h'
        );
        assertFalse(str_contains($env['headers'], "\nBcc:"), 'no se debe poder inyectar Bcc');
        assertFalse(str_contains($env['headers'], "\r\nTo: evil@x.com"));
    }

    public function testElCodigoDeRespuestaSmtpSeComparaComoEntero(): void
    {
        // Regresión: `substr()` devuelve string y las listas de llamadas son
        // `[220]`, `[250]`… Comparar `"220"` contra `220` con
        // `in_array(..., true)` daba false SIEMPRE, así que el saludo real
        // («220 in.mailjet.com ESMTP Mailjet») se rechazaba como inesperado y
        // ningún envío por SMTP llegaba a funcionar. El transporte de prueba no
        // pasa por `smtpSession`, así que se comprueba aquí el predicado.
        $expect = new \ReflectionMethod(Mailer::class, 'expect');
        $expect->setAccessible(true);

        try {
            $expect->invoke(null, '220 in.mailjet.com ESMTP Mailjet', [220], 'saludo');
        } catch (\RuntimeException $e) {
            fail('el 220 del saludo tiene que aceptarse: ' . $e->getMessage());
        }

        try {
            $expect->invoke(null, '535 autenticación rechazada', [220], 'saludo');
            fail('un código fuera de la lista debe lanzar');
        } catch (\RuntimeException $e) {
            assertTrue(str_contains($e->getMessage(), 'saludo inesperado'));
        }

        try {
            $expect->invoke(null, 'EOF', [220], 'saludo');
            fail('EOF no es una respuesta válida');
        } catch (\RuntimeException $e) {
            assertTrue(str_contains($e->getMessage(), 'saludo inesperado'));
        }
    }

    public function testSendSinDestinatariosValidosLanza(): void
    {
        try {
            Mailer::send(['esto no es un email'], 's', 'h', 'a');
            fail('debió lanzar RuntimeException');
        } catch (\RuntimeException $e) {
            assertTrue(str_contains($e->getMessage(), 'Sin destinatarios'));
        }
    }

    public function testSendSinHostLanza(): void
    {
        Mailer::setTransport(null);
        Mailer::setConfigOverride(['host' => '', 'from_address' => 'no-reply@example.com']);
        try {
            Mailer::send(['a@example.com'], 's', 'h', 'a');
            fail('debió lanzar RuntimeException');
        } catch (\RuntimeException $e) {
            assertTrue(str_contains($e->getMessage(), 'SMTP no configurado'));
        }
    }

    public function testSendSoloAceptaCorreosValidos(): void
    {
        $ok = Mailer::send(['a@example.com', ' inválido '], 's', 'h', 'a');
        assertSame(true, $ok);
        assertSame(['a@example.com'], $this->sent[0]['envelope']['to']);
    }
}