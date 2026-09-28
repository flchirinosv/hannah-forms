<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Mailer;
use App\Services\FormSettings;
use App\Services\Notifier;
use App\Services\Webhook;

/**
 * Tests de Notifier (aviso al dueño + confirmación al respondiente) y
 * Webhook (P5), con transporte/handler inyectados para no tocar red ni SMTP.
 */
class NotificationsTest
{
    /** @var array<int,array> Sobres capturados por el transporte de prueba */
    private array $sent = [];

    /** @var array<int,array{url:string,payload:array}> Hooks capturados */
    private array $hooks = [];

    private array $cfg = [
        'host'         => 'smtp.example.com',
        'port'         => 587,
        'encryption'   => 'tls',
        'user'         => '',
        'password'     => '',
        'from_address' => 'no-reply@example.com',
        'from_name'    => 'VanillaForms',
        'timeout'      => 15,
    ];

    public function setUp(): void
    {
        $this->sent = [];
        $this->hooks = [];
        Mailer::setTransport(function (array $envelope, array $config): void {
            $this->sent[] = $envelope;
        });
        Mailer::setConfigOverride($this->cfg);
        // La validación SSRF resuelve el host antes de disparar, así que sin
        // este seam el test dependería de que `ejemplo.com` resuelva por DNS
        // (y fallaría en una suite sin red).
        Webhook::setResolver(static fn(string $host): array => ['93.184.216.34']);
        Webhook::setHandler(function (string $url, array $payload): void {
            $this->hooks[] = ['url' => $url, 'payload' => $payload];
        });
    }

    public function tearDown(): void
    {
        Webhook::setHandler(null);
        Webhook::setResolver(null);
    }

    private function form(): array
    {
        return [
            'id'     => 10,
            'slug'   => 'encuesta-prueba',
            'title'  => 'Encuesta de prueba',
            'fields' => [
                ['id' => 1, 'label' => 'Nombre', 'type' => 'text'],
                ['id' => 2, 'label' => 'Correo', 'type' => 'email'],
                ['id' => 3, 'label' => 'Intereses', 'type' => 'checkbox'],
            ],
        ];
    }

    public function testNotifierDeshabilitadoNoEnvia(): void
    {
        $settings = [
            'notify'  => ['enabled' => false, 'emails' => ['owner@example.com'], 'confirmation' => ['enabled' => false]],
            'webhook' => ['url' => ''],
        ];
        Notifier::afterSubmit($this->form(), $settings, 1, ['1' => 'Ana'], '2026-09-26 10:00:00');
        assertSame(0, count($this->sent));
    }

    public function testNotificarDuenoConResumen(): void
    {
        $settings = [
            'notify'  => ['enabled' => true, 'emails' => ['owner@example.com'], 'confirmation' => ['enabled' => false]],
            'webhook' => ['url' => ''],
        ];
        Notifier::afterSubmit($this->form(), $settings, 7, ['1' => 'Ana', '2' => 'ana@example.com'], '2026-09-26 10:00:00');

        assertSame(1, count($this->sent));
        assertSame(['owner@example.com'], $this->sent[0]['to']);
        assertTrue(str_contains($this->sent[0]['subject'], 'Nueva respuesta'), 'asunto por defecto');
        assertTrue(str_contains($this->sent[0]['html'], 'Encuesta de prueba'), 'html con título del formulario');
        assertTrue(str_contains($this->sent[0]['html'], 'ana@example.com'), 'html con respuestas');
        assertTrue(str_contains($this->sent[0]['text'], 'Ana'), 'versión texto con respuestas');
    }

    public function testConfirmacionUsaElCampoCorreo(): void
    {
        $settings = [
            'notify'  => [
                'enabled'      => false,
                'emails'       => [],
                'confirmation' => ['enabled' => true, 'to_field' => 'Correo', 'subject' => '', 'message' => ''],
            ],
            'webhook' => ['url' => ''],
        ];
        Notifier::afterSubmit($this->form(), $settings, 2, ['1' => 'Ana', '2' => 'ana@example.com'], '2026-09-26 10:00:00');

        assertSame(1, count($this->sent));
        assertSame(['ana@example.com'], $this->sent[0]['to']);
        assertTrue(str_contains($this->sent[0]['subject'], 'Gracias por tu respuesta'), 'asunto por defecto');
        assertTrue(str_contains($this->sent[0]['html'], 'Encuesta de prueba'));
    }

    public function testConfirmacionConToFieldInexistenteNoEnvia(): void
    {
        $settings = [
            'notify'  => [
                'enabled'      => false,
                'emails'       => [],
                'confirmation' => ['enabled' => true, 'to_field' => 'Teléfono', 'subject' => '', 'message' => ''],
            ],
            'webhook' => ['url' => ''],
        ];
        Notifier::afterSubmit($this->form(), $settings, 3, ['1' => 'Ana', '2' => 'ana@example.com'], '2026-09-26 10:00:00');
        assertSame(0, count($this->sent));
    }

    public function testSmtpNoConfiguradoNoRompeElFlujo(): void
    {
        Mailer::setTransport(null);
        Mailer::setConfigOverride(['host' => '']);
        $settings = [
            'notify'  => ['enabled' => true, 'emails' => ['owner@example.com'], 'confirmation' => ['enabled' => false]],
            'webhook' => ['url' => ''],
        ];
        Notifier::afterSubmit($this->form(), $settings, 4, ['1' => 'Ana'], '2026-09-26 10:00:00');
        assertTrue(true, 'no debe lanzar excepción');
    }

    public function testNotifierConSettingsNormalizadosEnvíaDuenoYConfirmacion(): void
    {
        $settings = FormSettings::normalize([
            'notify' => [
                'enabled'      => true,
                'emails'       => ['owner@example.com'],
                'confirmation' => ['enabled' => true, 'to_field' => 'Correo'],
            ],
        ]);
        Notifier::afterSubmit($this->form(), $settings, 5, ['1' => 'Ana', '2' => 'ana@example.com'], '2026-09-26 10:00:00');

        assertSame(2, count($this->sent));
        $tos = array_map(static fn (array $e): string => $e['to'][0], $this->sent);
        sort($tos);
        assertEquals(['ana@example.com', 'owner@example.com'], $tos);
    }

    public function testWebhookUrlVaciaNoDispara(): void
    {
        Webhook::fire(['webhook' => ['url' => '']], ['a' => 1]);
        assertSame(0, count($this->hooks));
    }

    public function testWebhookUrlInvalidaOSchemeRaroNoDispara(): void
    {
        Webhook::fire(['webhook' => ['url' => 'not-a-url']], ['a' => 1]);
        Webhook::fire(['webhook' => ['url' => 'file:///etc/passwd']], ['a' => 1]);
        assertSame(0, count($this->hooks));
    }

    public function testWebhookDisparaConPayload(): void
    {
        $settings = ['webhook' => ['url' => 'https://ejemplo.com/hook']];
        $payload = ['form' => ['id' => 10, 'slug' => 'encuesta-p'], 'answers' => ['1' => 'Ana']];

        Webhook::fire($settings, $payload);

        assertSame(1, count($this->hooks));
        assertSame('https://ejemplo.com/hook', $this->hooks[0]['url']);
        assertEquals($payload, $this->hooks[0]['payload']);
    }
}