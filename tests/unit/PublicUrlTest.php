<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\PublicUrl;
use App\Core\Request;

/**
 * `App\Core\PublicUrl` — de dónde sale la URL que se copia, la del QR y la del
 * embed.
 *
 * Regresión real: los tres salían de `app.url` (APP_URL, default
 * `http://127.0.0.1:8080`). Servir la app en cualquier otro host dejaba el
 * enlace copiado y el QR apuntando a `127.0.0.1`, que para quien los recibe no
 * existe. O sea: la URL pública era correcta solo si alguien se acordaba de
 * editar el `.env`.
 *
 * Aquí se fija el orden de resolución (pin → petición → `app.url`) y, sobre
 * todo, el filtro del `Host`: ese valor lo manda el cliente y acaba dentro de
 * un `value=`, de un `data-url=` y de la URL que se codifica en el QR, así que
 * un host con CR/LF, `@` o comillas tiene que descartarse, no colarse.
 */
class PublicUrlTest
{
    /** Server keys tocados por cada test, para restaurarlos. */
    private array $serverBackup = [];

    public function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        PublicUrl::setBaseForTesting(null);
        Request::setTrustProxyForTesting(null);
    }

    public function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        PublicUrl::setBaseForTesting(null);
        Request::setTrustProxyForTesting(null);
    }

    /** Deja `$_SERVER` sin rastro de una petición web: el caso CLI. */
    private function cleanServer(): void
    {
        foreach (['HTTP_HOST', 'HTTPS', 'HTTP_X_FORWARDED_PROTO', 'SERVER_PORT', 'REMOTE_ADDR'] as $key) {
            unset($_SERVER[$key]);
        }
    }

    /* ---------- 1. el pin explícito manda ---------- */

    public function testElPinPublicUrlGanaATodoLoDemas(): void
    {
        PublicUrl::setBaseForTesting('https://formularios.midominio.com/');
        $this->cleanServer();

        assertEquals('https://formularios.midominio.com', PublicUrl::base());
        assertTrue(PublicUrl::isPinned(), 'con el pin puesto, isPinned() debe decir que sí');
    }

    public function testElPinSeNormalizaYNoDejaBarraFinal(): void
    {
        foreach ([
            'https://a.example.com/'      => 'https://a.example.com',
            'https://a.example.com///'    => 'https://a.example.com',
            'https://a.example.com/ruta/' => 'https://a.example.com/ruta',
            'HTTPS://A.Example.com'       => 'https://A.Example.com',
            'a.example.com:8443'          => 'http://a.example.com:8443',
        ] as $raw => $expected) {
            PublicUrl::setBaseForTesting((string) $raw);
            assertEquals($expected, PublicUrl::base(), 'normalizaría mal: ' . $raw);
        }
    }

    public function testUnPinInutilizableCaeEnLaPeticion(): void
    {
        $this->cleanServer();
        $_SERVER['HTTP_HOST'] = 'app.example.com';

        // Un pin con CR/LF o con un host que no es host no se puede usar, pero
        // tampoco puede dejar la URL vacía: se sigue al siguiente peldaño.
        PublicUrl::setBaseForTesting("https://malo\r\nHost: otro");
        assertEquals('http://app.example.com', PublicUrl::base());
    }

    /* ---------- 2. la petición ---------- */

    public function testDeduceElEsquemaYElHostDeLaPeticion(): void
    {
        $this->cleanServer();
        $_SERVER['HTTP_HOST'] = 'encuesta.midominio.com';
        $_SERVER['HTTPS'] = 'on';

        assertEquals('https://encuesta.midominio.com', PublicUrl::base());
    }

    public function testConservaElPuertoDelHost(): void
    {
        $this->cleanServer();
        $_SERVER['HTTP_HOST'] = '127.0.0.1:10001';

        assertEquals('http://127.0.0.1:10001', PublicUrl::base());
    }

    public function testAceptaIpv6ConCorchetes(): void
    {
        $this->cleanServer();
        $_SERVER['HTTP_HOST'] = '[::1]:8080';

        assertEquals('http://[::1]:8080', PublicUrl::base());
    }

    public function testHttpsOffCuentaComoHttp(): void
    {
        $this->cleanServer();
        $_SERVER['HTTP_HOST'] = 'x.example.com';
        $_SERVER['HTTPS'] = 'off';

        assertEquals('http://x.example.com', PublicUrl::base());
    }

    public function testSinPeticionCaeAAppUrl(): void
    {
        $this->cleanServer();   // sin HTTP_HOST: es el caso CLI (tests, cron)

        $base = PublicUrl::base();
        assertTrue($base !== '', 'en CLI tiene que caer a app.url, no devolver cadena vacía');
        assertEquals(
            rtrim((string) Config::get('app.url', ''), '/'),
            $base,
            'sin petición, PublicUrl::base() debe ser exactamente app.url'
        );
    }

    /* ---------- 3. X-Forwarded-Proto solo con proxy de confianza ---------- */

    public function testElEsquemaDelProxySoloSeCreeConTrustProxy(): void
    {
        $this->cleanServer();
        $_SERVER['HTTP_HOST'] = 'x.example.com';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        Request::setTrustProxyForTesting(false);
        assertEquals(
            'http://x.example.com',
            PublicUrl::base(),
            'sin proxy de confianza, X-Forwarded-Proto lo pone el cliente y no se le cree'
        );

        Request::setTrustProxyForTesting(true);
        assertEquals('https://x.example.com', PublicUrl::base());
    }

    /* ---------- 4. el filtro del Host ---------- */

    public function testRechazaHostsQueNoSonUnHost(): void
    {
        $this->cleanServer();
        $fallback = rtrim((string) Config::get('app.url', ''), '/');

        $malos = [
            "x.example.com\r\nX-Inyectado: 1",  // header splitting
            "x.example.com'",                    // rompería un atributo entrecomillado
            'x.example.com" onload="alert(1)',    // lo mismo
            'user@ejemplo.com',                   // credenciales embebidas
            'x.example.com/a',                    // barra: no es un host
            'x.example.com?a=1',                  // query
            'x.example.com#f',                    // fragmento
            '-mal.example.com',                   // etiqueta que empieza por -
            'mal-.example.com',                   // etiqueta que acaba en -
            'mal..example.com',                   // etiqueta vacía
            'x.example.com:0',                    // puerto fuera de rango
            'x.example.com:99999',                // puerto fuera de rango
            'x.example.com:abc',                  // puerto no numérico
            '[' . str_repeat('a', 300) . ']',     // longitud absurda
        ];

        foreach ($malos as $host) {
            $_SERVER['HTTP_HOST'] = $host;
            assertEquals(
                $fallback,
                PublicUrl::base(),
                'este Host tenía que descartarse y caer a app.url: ' . var_export($host, true)
            );
        }
    }

    public function testUnHostConEspaciosSeRecortaAntesDeValidar(): void
    {
        $this->cleanServer();
        // PHP no permitiría un Host con espacio, pero el test fija el
        // $_SERVER directamente: el recorte es lo que evita que pase.
        $_SERVER['HTTP_HOST'] = '  x.example.com  ';
        assertEquals('http://x.example.com', PublicUrl::base());
    }

    /* ---------- 5. los avisos de host local ---------- */

    public function testDetectaQueLaUrlEsLocal(): void
    {
        $locales = ['http://127.0.0.1:8080', 'http://localhost:10001', 'http://[::1]:8080', 'http://192.168.1.10', 'http://servidor'];
        foreach ($locales as $base) {
            PublicUrl::setBaseForTesting($base);
            assertTrue(PublicUrl::isLocalBase(), 'debería detectarse como local: ' . $base);
        }
    }

    public function testUnaUrlPublicaNoSeMarcaComoLocal(): void
    {
        $publicas = ['https://encuesta.midominio.com', 'https://8.8.8.8', 'https://[2001:4860:4860::8888]'];
        foreach ($publicas as $base) {
            PublicUrl::setBaseForTesting($base);
            assertTrue(!PublicUrl::isLocalBase(), 'no debería marcarse como local: ' . $base);
        }
    }

    public function testSinBaseNoHayQueAvisarDeNada(): void
    {
        // Pin vacío = no fijado, así que cae a la petición (no hay en CLI) y
        // luego a `app.url`. El segundo argumento anula ESE último peldaño:
        // `''` en la pin no significa «sin URL», significa «sin pin».
        PublicUrl::setBaseForTesting('', '');
        assertEquals('', PublicUrl::base());
        assertTrue(!PublicUrl::isLocalBase(), 'sin base no se puede afirmar que sea local');
    }

    /* ---------- 6. las URLs derivadas ---------- */

    public function testLasUrlsDelFormularioSeConstruyenDesdeLaBase(): void
    {
        PublicUrl::setBaseForTesting('https://midominio.com/');

        assertEquals('https://midominio.com/f/encuesta-2026', PublicUrl::fillUrl('encuesta-2026'));
        assertEquals('https://midominio.com/f/encuesta-2026/embed', PublicUrl::embedUrl('encuesta-2026'));
    }

    public function testElSlugSeEscapaEnLaUrl(): void
    {
        PublicUrl::setBaseForTesting('https://midominio.com');

        // rawurlencode: un slug con espacio o acento no puede romper el atributo.
        $url = PublicUrl::fillUrl('mi encuesta ñ');
        assertTrue(!str_contains($url, ' '), 'el espacio del slug no puede quedar crudo en la URL');
        assertEquals('https://midominio.com/f/' . rawurlencode('mi encuesta ñ'), $url);
    }

    /* ---------- 7. la config existe de verdad ---------- */

    public function testLaConfigPublicUrlEstaDeclarada(): void
    {
        // El aviso de AGENTS: `Config::get()` con notación de punto devuelve el
        // default EN SILENCIO si la clave no existe en config.php, y eso ya
        // costó una vez una ruta de escritura equivocada. Aquí el default es ''
        // y por eso el fallo sería silencioso de verdad.
        $app = Config::all()['app'] ?? [];
        assertTrue(
            array_key_exists('public_url', $app),
            'falta app.public_url en config/config.php: con el default vacío, '
                . 'PublicUrl caería siempre a la petición sin que nadie lo notase'
        );
    }
}
