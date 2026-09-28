<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Webhook;

/**
 * B1 — Slack incoming-webhook y endurecimiento del envío: cuerpo en formato
 * Slack (`settings.webhook.kind`), firma HMAC-SHA256 opcional de cabecera
 * (`X-VanillaForms-Signature`) y UN único reintento con backoff cuando el
 * primer intento no devuelve 2xx.
 *
 * Sin redes: la resolución se fija a una IP pública y el transporte se
 * inyecta para capturar (target, body, firma, intento) y devolver el status.
 * El retry no ralentiza la suite: backoff a 0.
 */
class WebhookSlackTest
{
    /** @var array<int,array{body:string,signature:?string,attempt:int}> */
    private array $sent = [];

    public function setUp(): void
    {
        $this->sent = [];
        Webhook::setResolver(static fn(string $host): array => ['93.184.216.34']);
        Webhook::setTransport(function (array $target, string $body, ?string $signature, int $attempt): int {
            $this->sent[] = ['body' => $body, 'signature' => $signature, 'attempt' => $attempt];
            return 200;
        });
        Webhook::setRetryBackoff(0);
        Webhook::setAllowPrivate(null);
    }

    public function tearDown(): void
    {
        Webhook::setResolver(null);
        Webhook::setTransport(null);
        Webhook::setRetryBackoff(null);
        Webhook::setHandler(null);
        Webhook::setAllowPrivate(null);
    }

    private function settings(array $webhook): array
    {
        return ['webhook' => $webhook + ['url' => 'https://hooks.example.com/wh', 'allow_private_hosts' => false]];
    }

    private function payload(): array
    {
        return [
            'form'   => ['id' => 7, 'slug' => 'encuesta', 'title' => 'Encuesta de oficina'],
            'submission' => ['id' => 42, 'finished_at' => '2026-09-28 12:00:00', 'ip' => '10.0.0.9'],
            'fields' => [
                ['id' => 1, 'label' => 'Nombre'],
                ['id' => 2, 'label' => 'Te gusta'],
            ],
            'answers' => [
                1 => 'Ana',
                2 => ['Sí', 'Mucho'],
            ],
        ];
    }

    public function testSlackTransformaElCuerpoYFirmaConElSecreto(): void
    {
        $secret = 'clave-super-secreta';
        Webhook::fire($this->settings(['kind' => 'slack', 'secret' => $secret]), $this->payload());

        assertSame(1, count($this->sent));
        $s = $this->sent[0];
        $msg = json_decode($s['body'], true);
        assertTrue(is_array($msg), 'el cuerpo debe ser JSON');
        assertTrue(isset($msg['text']), 'mensaje Slack con texto');
        assertSame(false, $msg['unfurl_links'] ?? null);
        assertTrue(str_contains($msg['text'], 'Encuesta de oficina'));
        assertTrue(str_contains($msg['text'], 'Nombre: Ana'), 'respuesta simple en texto');
        assertTrue(str_contains($msg['text'], 'Te gusta: Sí; Mucho'), 'JSON anidado aplanado');
        assertSame('sha256=' . hash_hmac('sha256', $s['body'], $secret), $s['signature']);
    }

    public function testSinSecretoNoFirma(): void
    {
        Webhook::fire($this->settings(['kind' => 'generic']), $this->payload());

        assertSame(1, count($this->sent));
        assertSame(null, $this->sent[0]['signature']);
    }

    public function testGenericMandaElPayloadCrudo(): void
    {
        Webhook::fire($this->settings(['kind' => 'generic']), $this->payload());

        $decoded = json_decode($this->sent[0]['body'], true);
        assertSame('Encuesta de oficina', $decoded['form']['title']);
        assertSame(['Sí', 'Mucho'], $decoded['answers']['2']);
    }

    public function testRetryConBackoffTrasNo2xx(): void
    {
        Webhook::setTransport(function (array $target, string $body, ?string $signature, int $attempt): int {
            $this->sent[] = ['body' => $body, 'signature' => $signature, 'attempt' => $attempt];
            return $attempt === 1 ? 503 : 201;
        });

        Webhook::fire($this->settings(['kind' => 'slack', 'secret' => 'k']), $this->payload());

        assertSame(2, count($this->sent), 'un intento + un retry');
        assertSame([1, 2], array_column($this->sent, 'attempt'));
        assertSame($this->sent[0]['body'], $this->sent[1]['body'], 'mismo body en el retry');
        assertSame($this->sent[0]['signature'], $this->sent[1]['signature'], 'la firma no cambia');
    }

    public function testSinRetrySiRespuestaEs2xx(): void
    {
        Webhook::fire($this->settings(['kind' => 'generic']), $this->payload());
        assertSame(1, count($this->sent));
    }

    public function testDosFallosConsecutivosAgotanLosDosIntentosSinExcepcion(): void
    {
        Webhook::setTransport(function (array $t, string $b, ?string $s, int $attempt): int {
            $this->sent[] = ['body' => $b, 'signature' => $s, 'attempt' => $attempt];
            return 500;
        });

        Webhook::fire($this->settings(['kind' => 'generic']), $this->payload());
        assertSame([1, 2], array_column($this->sent, 'attempt'));
    }

    public function testSlackBodyTruncaMensajesLargos(): void
    {
        $answers = [];
        for ($i = 1; $i <= 300; $i++) {
            $answers[$i] = str_repeat('x', 40);
        }
        $payload = $this->payload();
        $payload['answers'] = $answers;

        $body = Webhook::slackBody($payload);
        assertTrue(mb_strlen($body['text']) <= 3900);
        assertTrue(str_ends_with($body['text'], '… (mensaje truncado)'));
    }
}