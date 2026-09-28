<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Services\FormSettings;
use App\Services\Webhook;

/**
 * Cierre del SSRF del webhook (fase 2, item 2.2).
 *
 * `settings.webhook.url` lo configura el dueño del formulario y, con el
 * registro abierto, cualquiera puede tener un formulario. Antes `fire()` solo
 * comprobaba que la URL fuese http/https y hacía `file_get_contents`: eso
 * convierte el envío de una respuesta en un POST a una IP que elige el atacante
 * dentro de la red del servidor.
 *
 * Probado en vivo contra este entorno: con el webhook puesto a
 * `http://127.0.0.1:9999/ssrf-proof`, una respuesta anónima hizo que el servidor
 * emitiera el POST al servicio interno (con el payload de 242 bytes).
 *
 * Estos tests fijan las cuatro propiedades del arreglo:
 *  1. la clasificación de IP (loopback, RFC1918, metadata de nube, CGNAT,
 *     reservadas, IPv6 y las IPv4 mapeadas);
 *  2. que un host que resuelve a una sola IP privada se caiga, incluidos los
 *     literales y los que parecen externos (`127.0.0.1.nip.io`);
 *  3. que se caiga si CUALQUIERA de las IP resueltas es privada (rebinding);
 *  4. que lo público siga funcionando, y que el opt-in de red local exista.
 *
 * Sin red: `Webhook::setResolver()` fija la resolución.
 */
class WebhookSsrfTest
{
    /** @var array<int,array> URLs que llegaron al handler */
    private array $fired = [];
    /** @var array<int,string> Log de error_log() capturado */
    private array $logs = [];

    public function setUp(): void
    {
        $this->fired = [];
        $this->logs = [];
        Webhook::setResolver(null);
        Webhook::setAllowPrivate(null);
        Webhook::setHandler(function (string $url, array $payload): void {
            $this->fired[] = $url;
        });
    }

    public function tearDown(): void
    {
        Webhook::setHandler(null);
        Webhook::setResolver(null);
        Webhook::setAllowPrivate(null);
    }

    /* ---------- 1. Clasificación de IP ---------- */

    public function testBloqueaLoopbackYLinkLocal(): void
    {
        foreach (['127.0.0.1', '127.0.0.53', '127.255.255.254', '0.0.0.0', '::1', '::'] as $ip) {
            assertTrue(Webhook::isBlockedIp($ip), 'debe bloquear ' . $ip);
        }
        // La metadata de nube es el caso que más importa.
        assertTrue(Webhook::isBlockedIp('169.254.169.254'), 'metadata de nube');
        assertTrue(Webhook::isBlockedIp('169.254.0.1'), 'link-local');
        assertTrue(Webhook::isBlockedIp('fe80::1'), 'link-local v6');
    }

    public function testBloqueaRedesPrivadas(): void
    {
        foreach ([
            '10.0.0.1', '10.255.255.254',
            '172.16.0.1', '172.31.255.254',
            '192.168.0.1', '192.168.1.1',
            '100.64.0.1',            // CGNAT
            'fc00::1', 'fd12:3456::1', // ULA
        ] as $ip) {
            assertTrue(Webhook::isBlockedIp($ip), 'debe bloquear ' . $ip);
        }
    }

    public function testBloqueaReservadasMulticastYDocumentacion(): void
    {
        foreach ([
            '224.0.0.1', '239.255.255.255', '255.255.255.255',
            '192.0.2.5', '198.51.100.5', '203.0.113.5', '198.18.0.1',
            'ff02::1', '2001:db8::1',
        ] as $ip) {
            assertTrue(Webhook::isBlockedIp($ip), 'debe bloquear ' . $ip);
        }
    }

    public function testBloqueaIPv4MapeadaEnIPv6(): void
    {
        // Bypass clásico: ::ffff:127.0.0.1 sigue siendo loopback.
        assertTrue(Webhook::isBlockedIp('::ffff:127.0.0.1'), 'mapeada a loopback');
        assertTrue(Webhook::isBlockedIp('::ffff:10.0.0.1'), 'mapeada a RFC1918');
        assertTrue(Webhook::isBlockedIp('::ffff:169.254.169.254'), 'mapeada a metadata');
        // …y una pública mapeada NO se bloquea (es una IP pública normal).
        assertTrue(!Webhook::isBlockedIp('::ffff:93.184.216.34'), 'pública mapeada se permite');
    }

    public function testPermiteIpsPublicas(): void
    {
        foreach ([
            '93.184.216.34', '8.8.8.8', '1.1.1.1', '172.32.0.1', '172.15.255.254',
            '11.0.0.1', '100.63.255.255', '100.128.0.1', '2606:2800:220:1::',
        ] as $ip) {
            assertTrue(!Webhook::isBlockedIp($ip), 'no debe bloquear ' . $ip);
        }
    }

    public function testUnaCadenaNoIpsSeBloqueaPorDefecto(): void
    {
        // Ante la duda, se bloquea: no es una IP que podamos clasificar.
        assertTrue(Webhook::isBlockedIp('no-es-una-ip'), 'basura');
        assertTrue(Webhook::isBlockedIp(''), 'vacía');
    }

    /* ---------- 2. Destinos que se caen ---------- */

    public function testNoDisparaAHostQueResuelveAPrivada(): void
    {
        Webhook::setResolver(static fn(string $h): array => ['127.0.0.1']);
        Webhook::fire(['webhook' => ['url' => 'http://mi-host-de-prueba.example/hook']], ['a' => 1]);

        assertSame(0, count($this->fired), 'no debe disparar a un host que resuelve a loopback');
    }

    public function testNoDisparaALiteralesPrivados(): void
    {
        $casos = [
            'http://127.0.0.1/hook',
            'http://127.0.0.1:8080/hook',
            'http://localhost/hook',
            'http://[::1]/hook',
            'http://10.0.0.5/hook',
            'http://192.168.1.10/hook',
            'http://169.254.169.254/latest/meta-data/',
            'http://[::ffff:127.0.0.1]/hook',
        ];
        foreach ($casos as $url) {
            Webhook::setResolver(null);   // literales: sin DNS
            Webhook::fire(['webhook' => ['url' => $url]], ['a' => 1]);
            assertSame(0, count($this->fired), 'no debe disparar a ' . $url);
        }
    }

    public function testNoDisparaAHostQueSoloResuelveEnLaRedLocal(): void
    {
        // Un nombre sin DNS público es un vector同等 de bueno: `intranet`,
        // `metadata`, el nombre corto del host, etc.
        Webhook::setResolver(static fn(string $h): array => []);
        Webhook::fire(['webhook' => ['url' => 'http://intranet/hook']], ['a' => 1]);

        assertSame(0, count($this->fired), 'sin IP resoluble no se dispara');
    }

    public function testNoSigueRedirecciones(): void
    {
        // Si un host público respondiera con un 30x a una IP interna, el cliente
        // antiguo podía seguirlo (follow_location). El nuevo no sigue nada.
        $t = Webhook::resolveTarget('http://93.184.216.34/hook', false);
        assertNotNull($t, 'un host público se resuelve');
        assertSame('/hook', $t['path'], 'no hay redirección implicada en la petición');
    }

    /* ---------- 3. Rebinding ---------- */

    public function testSiUnaIpResueltaEsPrivadaSeCaeTodo(): void
    {
        // El atacante sirve un DNS que devuelve una IP pública en la primera
        // consulta y una privada en la segunda. Se rechaza si CUALQUIERA es
        // privada, no solo la primera.
        Webhook::setResolver(static fn(string $h): array => ['93.184.216.34', '127.0.0.1']);
        Webhook::fire(['webhook' => ['url' => 'http://rebind.example/hook']], ['a' => 1]);

        assertSame(0, count($this->fired), 'una sola IP privada ya lo tumba');
    }

    public function testLaConexionSeFijaALaIpValidada(): void
    {
        Webhook::setResolver(static fn(string $h): array => ['93.184.216.34', '8.8.8.8']);
        $t = Webhook::resolveTarget('https://webhook.example:8443/hook?x=1', false);

        assertNotNull($t);
        assertSame('93.184.216.34', $t['ip'], 'se conecta a una IP ya validada, no a la que toque el DNS en ese momento');
        assertSame('webhook.example', $t['host'], 'pero el Host/SNI sigue siendo el nombre (para el TLS)');
        assertSame(8443, $t['port'], 'puerto explícito');
        assertSame('/hook?x=1', $t['path'], 'camino + query');
    }

    /* ---------- 4. Lo público sigue funcionando ---------- */

    public function testHostPublicoSiDispara(): void
    {
        Webhook::setResolver(static fn(string $h): array => ['93.184.216.34']);
        Webhook::fire(['webhook' => ['url' => 'https://webhook.example/hook']], ['a' => 1]);

        assertSame(1, count($this->fired), 'un webhook público normal se sigue disparando');
        assertSame('https://webhook.example/hook', $this->fired[0]);
    }

    public function testOptinParaRedLocalPropia(): void
    {
        Webhook::setResolver(static fn(string $h): array => ['192.168.1.50']);

        // Sin opt-in: bloqueado.
        Webhook::fire(['webhook' => ['url' => 'http://mi-nas.local/hook']], ['a' => 1]);
        assertSame(0, count($this->fired), 'por defecto la red local está bloqueada');

        // Con los DOS opt-ins: pasa (alguien quiere su servicio de casa).
        Webhook::setAllowPrivate(true);
        Webhook::fire([
            'webhook' => ['url' => 'http://mi-nas.local/hook', 'allow_private_hosts' => true],
        ], ['a' => 1]);
        assertSame(1, count($this->fired), 'con ambos opt-ins se permite');
    }

    /**
     * FRONTERA DE AUTORIZACIÓN: el opt-in del formulario NO basta.
     *
     * El ajuste vive en los settings del formulario, y los settings los puede
     * guardar cualquiera con permiso de edición (dueño, editor o admin), así
     * que si ese flag fuera la única puerta, cualquiera que pueda crear o editar
     * un formulario convertiría el webhook en un proxy a la red interna
     * (loopback, RFC1918, metadata de nube): el SSRF que el item 2.2 cierra.
     * Por eso hace falta además el opt-in del SERVIDOR, que solo pone quien
     * opera la instancia.
     */
    public function testElOptinDelFormularioNoBastaSinElDelServidor(): void
    {
        Webhook::setResolver(static fn(string $h): array => ['192.168.1.50']);
        Webhook::setAllowPrivate(false);

        Webhook::fire([
            'webhook' => ['url' => 'http://mi-nas.local/hook', 'allow_private_hosts' => true],
        ], ['a' => 1]);

        assertSame(0, count($this->fired), 'el opt-in del formulario no puede abrir la red privada');
    }

    /**
     * Y al revés tampoco vale: con el servidor abierto, cada formulario sigue
     * teniendo que pedirlo. El opt-in del servidor no es una llave maestra.
     */
    public function testElOptinDelServidorSoloNoBasta(): void
    {
        Webhook::setResolver(static fn(string $h): array => ['192.168.1.50']);
        Webhook::setAllowPrivate(true);

        // El servidor lo permite, pero este formulario no lo pide.
        Webhook::fire(['webhook' => ['url' => 'http://mi-nas.local/hook']], ['a' => 1]);

        assertSame(0, count($this->fired), 'sin el opt-in del formulario sigue bloqueado');
    }

    /**
     * Loopback y metadata de nube son los objetivos de más premio, y son
     * justamente los que el opt-in del servidor tiene que cortar.
     */
    public function testElOptinDelServidorCortaLoopbackYMetadata(): void
    {
        Webhook::setAllowPrivate(false);
        foreach (['127.0.0.1' => '127.0.0.1', '169.254.169.254' => '169.254.169.254'] as $ip => $literal) {
            Webhook::setResolver(static fn(string $h): array => [$literal]);
            Webhook::fire([
                'webhook' => ['url' => 'http://host.local/hook', 'allow_private_hosts' => true],
            ], ['a' => 1]);
        }
        assertSame(0, count($this->fired), 'ni loopback ni metadata de nube pasan con el opt-in del formulario solo');
    }

    /**
     * El opt-in del servidor se lee de la config cuando nadie lo fija a mano.
     */
    public function testElOptinDelServidorVieneDeLaConfig(): void
    {
        Webhook::setAllowPrivate(null);
        // En este entorno la config no lo tiene activo: debe ser false.
        assertFalse(Webhook::serverAllowsPrivate(), 'sin WEBHOOK_ALLOW_PRIVATE_HOSTS la red privada está cerrada');
        assertFalse(
            Config::get('app.webhook.allow_private_hosts', false),
            'la config debe exponer app.webhook.allow_private_hosts en false por defecto'
        );
    }

    public function testElOptinSobreviveANormalize(): void
    {
        // normalize() tiene whitelist: si la clave no estuviera declarada, el
        // opt-in se perdería en cada guardado y el opt-in no serviría de nada.
        $n = FormSettings::normalize([
            'webhook' => ['url' => 'http://mi-nas.local/hook', 'allow_private_hosts' => true],
        ]);
        assertTrue($n['webhook']['allow_private_hosts'], 'el opt-in debe sobrevivir a normalize()');

        $d = FormSettings::normalize(['webhook' => ['url' => 'https://x.example/h']]);
        assertTrue(!$d['webhook']['allow_private_hosts'], 'por defecto va en false');
    }

    /* ---------- Robustitud de la URL ---------- */

    public function testRechazaEsquemasYFormasInvalidas(): void
    {
        Webhook::setResolver(static fn(string $h): array => ['93.184.216.34']);
        $casos = [
            'file:///etc/passwd',
            'gopher://93.184.216.34/',
            'ftp://93.184.216.34/x',
            'dict://93.184.216.34:11211/',
            'javascript:alert(1)',
            'not-a-url',
            'http://',
            '',
        ];
        foreach ($casos as $url) {
            Webhook::fire(['webhook' => ['url' => $url]], ['a' => 1]);
            assertSame(0, count($this->fired), 'debe descartar ' . var_export($url, true));
        }
    }

    public function testRechazaCrLfEnLaUrl(): void
    {
        Webhook::setResolver(static fn(string $h): array => ['93.184.216.34']);
        // Inyección de cabeceras / request splitting vía la URL.
        Webhook::fire([
            'webhook' => ['url' => "https://webhook.example/hook\r\nX-Inyectada: 1"],
        ], ['a' => 1]);
        assertSame(0, count($this->fired), 'CR/LF en la URL');

        Webhook::fire([
            'webhook' => ['url' => "https://webhook.example/hook\nHost: evil.example"],
        ], ['a' => 1]);
        assertSame(0, count($this->fired), 'LF en la URL');
    }

    public function testRechazaCredencialesEmbebidas(): void
    {
        Webhook::setResolver(static fn(string $h): array => ['93.184.216.34']);
        Webhook::fire(['webhook' => ['url' => 'https://user:pass@webhook.example/hook']], ['a' => 1]);
        assertSame(0, count($this->fired), 'credenciales embebidas');
    }

    public function testUrlVaciaNoDispara(): void
    {
        Webhook::setResolver(static fn(string $h): array => ['93.184.216.34']);
        Webhook::fire(['webhook' => ['url' => '']], ['a' => 1]);
        Webhook::fire(['webhook' => []], ['a' => 1]);
        Webhook::fire([], ['a' => 1]);
        assertSame(0, count($this->fired));
    }
}
