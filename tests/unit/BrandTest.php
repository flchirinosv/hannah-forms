<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppSettings;
use App\Core\Brand;
use App\Core\Config;

/**
 * Marca de la instancia (AG2): nombre, color, logo y favicon.
 *
 * El patrón que se prueba es el de `AppSettings`: **la fila guardada gana; si no
 * hay fila, manda el `.env` o el default del código**. Un `Brand` que mezclara
 * las dos fuentes (o que leyera la fila en cada llamada pero el `.env` solo al
 * arrancar) daría un resultado distinto según el orden de los tests, que es
 * justo lo que se evita.
 *
 * Va por `setForTesting`/`forgetForTesting` y `Config::setForTesting` para no
 * tocar la BD: la propiedad es la precedencia, no el `INSERT`.
 */
class BrandTest
{
    public function setUp(): void
    {
        AppSettings::forgetForTesting();
        Config::forgetForTesting();
    }

    public function tearDown(): void
    {
        // Un override vivo se lo traga el siguiente test que lea AppSettings/Config.
        AppSettings::forgetForTesting();
        Config::forgetForTesting();
    }

    public function testElNombreSinFilaCaeAlAppNameDelEnv(): void
    {
        Config::setForTesting('app.name', 'Mi Encuestas');

        assertSame('Mi Encuestas', Brand::name(), 'sin fila, el nombre sale del .env');
    }

    public function testElNombreGuardadoGanaSobreElEnv(): void
    {
        Config::setForTesting('app.name', 'Del .env');
        AppSettings::setForTesting([Brand::NAME => '  Cliente Feliz  ']);

        assertSame('Cliente Feliz', Brand::name(), 'la fila gana y se recorta');
    }

    public function testUnNombreEnBlancoNoDejaLaBarraVacia(): void
    {
        Config::setForTesting('app.name', 'Fallback');
        AppSettings::setForTesting([Brand::NAME => '   ']);

        assertSame('Fallback', Brand::name(), 'un nombre en blanco cae al .env');
    }

    public function testElColorSinFilaEsElDefaultDelCodigo(): void
    {
        assertSame(Brand::DEFAULT_COLOR, Brand::color(), 'sin color guardado, el default');
        assertSame('', Brand::themeCss(), 'sin color guardado no se emite <style>');
    }

    public function testElColorGuardadoSeNormalizaAMinusculas(): void
    {
        AppSettings::setForTesting([Brand::COLOR => '#AABBCC']);

        assertSame('#aabbcc', Brand::color(), 'el hex guardado se normaliza');
        // Con solo el primario guardado, el CSS lleva su variable y la tinta
        // automática sobre el degradado (y NO la del degradado, que cae al
        // default de app.css).
        $css = Brand::themeCss();
        assertTrue(str_contains($css, '--vf-primary:#aabbcc'), 'el CSS usa el hex normalizado: ' . $css);
        assertTrue(str_contains($css, '--vf-on-grad:'), 'el texto sobre el degradado se calcula: ' . $css);
        assertTrue(!str_contains($css, '--vf-grad-start'), 'sin fila de degradado no se emite su variable');
    }

    public function testUnColorInvalidoCaeAlDefaultYNoSeInyecta(): void
    {
        AppSettings::setForTesting([Brand::COLOR => 'red; } body { display:none']);

        assertSame(Brand::DEFAULT_COLOR, Brand::color(), 'un color inválido no pasa');
        assertSame('', Brand::themeCss(), 'y no se inyecta nada en el <style>');
    }

    public function testElDegradadoYElFondoSeEmiteComoVariables(): void
    {
        AppSettings::setForTesting([
            Brand::COLOR_GRAD_START => '#112233',
            Brand::COLOR_GRAD_END   => '#445566',
            Brand::COLOR_BG         => '#778899',
            Brand::COLOR_TEXT       => '#ffcc00',
        ]);

        assertSame('#112233', Brand::gradientStart());
        assertSame('#445566', Brand::gradientEnd());
        assertSame('#778899', Brand::background());
        assertSame('#ffcc00', Brand::text());

        $css = Brand::themeCss();
        assertTrue(str_contains($css, '--vf-grad-start:#112233'), 'inicio del degradado: ' . $css);
        assertTrue(str_contains($css, '--vf-grad-end:#445566'), 'fin del degradado');
        assertTrue(str_contains($css, '--vf-instance-bg:#778899'), 'fondo de instancia');
        assertTrue(str_contains($css, '--vf-on-grad:#ffcc00'), 'texto elegido a mano');
    }

    public function testElDegradadoSinFilaCaeAlPrimarioYAlVioletaDefault(): void
    {
        AppSettings::setForTesting([Brand::COLOR => '#0d6efd']);

        assertSame('#0d6efd', Brand::gradientStart(), 'sin extremo, el inicio es el primario');
        assertSame(Brand::DEFAULT_GRAD_END, Brand::gradientEnd(), 'y el fin, el violeta default');
        assertNull(Brand::background(), 'sin fondo guardado no hay color de fondo');
        assertNull(Brand::text(), 'sin texto guardado, el automático');
    }

    public function testLaTintaAutomaticaSeEmiteSoloSiHayAlgunColor(): void
    {
        assertSame('', Brand::themeCss(), 'sin ningún color no hay <style>');

        AppSettings::setForTesting([Brand::COLOR_BG => '#101010']);
        assertTrue(str_contains(Brand::themeCss(), '--vf-on-grad:'), 'con un color guardado, la tinta se calcula');
    }

    public function testUnColorDeTresDigitosNoVale(): void
    {
        // La forma corta `#abc` es válida en CSS pero se rechaza a propósito:
        // normalizarla exigiría expandirla, y admitirla solo aquí dejaría el CSV
        // de ajustes con dos formatos distintos para el mismo color.
        AppSettings::setForTesting([Brand::COLOR => '#abc']);

        assertSame(Brand::DEFAULT_COLOR, Brand::color(), 'solo #rrggbb');
        assertSame('', Brand::themeCss(), 'y no emite CSS');
    }

    public function testSinLogoNoHayUrlNiHasLogo(): void
    {
        AppSettings::setForTesting([]);

        assertSame(false, Brand::hasLogo(), 'sin fila no hay logo');
        assertSame(null, Brand::logoUrl(), 'y no hay URL');
        assertSame(null, Brand::mime('logo'), 'ni MIME');
        assertSame(null, Brand::absolutePath('logo'), 'ni ruta en disco');
    }

    public function testUnLogoGuardadoDaUrlConCacheBusting(): void
    {
        // Se escribe un archivo de verdad en storage/branding/ porque `logoUrl()`
        // comprueba que exista: un backup restaurado sin `storage/` tiene la fila
        // pero no el archivo, y entonces la URL debe ser null (fallback al icono).
        $dir = (string) Config::get('paths.storage', dirname(__DIR__, 2) . '/storage') . '/' . Brand::DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $name = 'test-brand-' . bin2hex(random_bytes(4)) . '.png';
        $path = $dir . '/' . $name;
        file_put_contents($path, "\x89PNG\r\n\x1a\n"); // firma PNG, no importa que sea válido

        try {
            AppSettings::setForTesting([
                Brand::LOGO => json_encode(['stored' => $name, 'mime' => 'image/png', 'size' => 8]),
            ]);

            assertSame(true, Brand::hasLogo(), 'la fila con metadatos es un logo');
            assertSame('image/png', Brand::mime('logo'), 'el MIME sale de los metadatos');
            assertSame($path, Brand::absolutePath('logo'), 'la ruta resuelve al archivo real');
            assertSame('/branding/logo?v=8', Brand::logoUrl(), 'la URL lleva cache-busting');

            // Sin el archivo, la URL desaparece aunque la fila siga.
            unlink($path);
            AppSettings::forgetForTesting();
            AppSettings::setForTesting([
                Brand::LOGO => json_encode(['stored' => $name, 'mime' => 'image/png', 'size' => 8]),
            ]);
            assertSame(null, Brand::logoUrl(), 'fila sin archivo => sin URL (fallback)');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testUnaFilaDeLogoRotaNoRompe(): void
    {
        foreach (['', 'no-es-json', '{"sin":"stored"}', '[]', 'null'] as $raw) {
            AppSettings::setForTesting([Brand::LOGO => $raw]);
            assertSame(false, Brand::hasLogo(), 'una fila rota (' . var_export($raw, true) . ') no es un logo');
            assertSame(null, Brand::logoUrl(), 'ni produce URL');
        }
    }

    public function testUnStoredConSeparadoresDeRutaNoSaleDelDirectorio(): void
    {
        AppSettings::setForTesting([
            Brand::LOGO => json_encode(['stored' => '../../etc/passwd', 'mime' => 'image/png', 'size' => 1]),
        ]);

        assertSame(true, Brand::hasLogo(), 'la fila existe…');
        assertSame(null, Brand::absolutePath('logo'), '…pero una ruta con `..` no resuelve');
        assertSame(null, Brand::logoUrl(), 'y sin archivo no hay URL');
    }

    public function testUnKindDesconocidoNoHaceNada(): void
    {
        assertSame(null, Brand::mime('avatar'), 'un kind que no es logo/favicon no lee nada');
        assertSame(null, Brand::absolutePath('avatar'), 'ni resuelve ruta');
    }
}
