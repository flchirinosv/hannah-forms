<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * La marca de la instancia tiene que llegar a las VISTAS por `Brand`/`View`, no
 * por valores escritos a mano.
 *
 * El fallo que esto evita no da error en ningún sitio: si una vista vuelve a
 * clavar `<link rel="icon" ... favicon.svg>` o `fa-list-check`, la marca se ve
 * bien en el panel y mal en ESA pantalla, y como el HTML es válido nada falla.
 * Por eso se comprueba en dos direcciones: que las vistas usan los helpers, y
 * que el favicon hardcodeado no sobrevive en ninguna.
 */
class BrandMarkupTest
{
    private static function raiz(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function leer(string $rel): string
    {
        return (string) file_get_contents(self::raiz() . '/' . $rel);
    }

    public function testLosLayoutsUsanLaMarcaDeLaInstancia(): void
    {
        foreach (['views/layouts/main.php', 'views/layouts/guest.php'] as $rel) {
            $vista = self::leer($rel);

            assertTrue(
                str_contains($vista, 'View::favicon()'),
                $rel . ' pinta el favicon por su helper'
            );
            assertTrue(
                str_contains($vista, 'View::brandStyle()'),
                $rel . ' inyecta el color de la instancia por su helper'
            );
            assertTrue(
                str_contains($vista, 'View::brandLogo('),
                $rel . ' pinta el logo por su helper'
            );
            assertTrue(
                str_contains($vista, 'Brand::name()'),
                $rel . ' toma el nombre de Brand, no de un literal'
            );
        }
    }

    public function testElFaviconHardcodeadoNoSobreviveEnNingunaVista(): void
    {
        // El `<link rel="icon" ... favicon.svg>` de antes de AG2. Su vuelta en
        // una sola vista deja esa pantalla con el icono viejo aunque la marca
        // esté subida, y es exactamente lo que no se vería revisando el panel.
        $vistas = glob(self::raiz() . '/views/**/*.php') ?: [];
        $vistas = array_merge($vistas, glob(self::raiz() . '/views/*.php') ?: []);

        assertTrue(count($vistas) >= 30, 'el barrido tiene que recorrer las vistas de verdad');

        $culpables = [];
        foreach ($vistas as $ruta) {
            $contenido = (string) file_get_contents($ruta);
            if (str_contains($contenido, 'favicon.svg')) {
                $culpables[] = str_replace(self::raiz() . '/', '', $ruta);
            }
        }

        assertSame([], $culpables, 'ninguna vista debe clavar favicon.svg: usa View::favicon()');
    }

    public function testElPanelDeAjustesTraeLaTarjetaDeMarca(): void
    {
        $settings = self::leer('views/admin/settings.php');

        assertTrue(
            str_contains($settings, 'action="/admin/settings/brand"'),
            'el nombre/color se guardan en la tarjeta de marca'
        );
        assertTrue(
            str_contains($settings, 'action="/admin/settings/brand/logo"'),
            'el logo tiene su formulario multipart'
        );
        assertTrue(
            str_contains($settings, 'action="/admin/settings/brand/favicon"'),
            'y el favicon el suyo'
        );
        assertTrue(
            str_contains($settings, 'enctype="multipart/form-data"'),
            'una subida sin enctype no manda el archivo'
        );
        assertFalse(
            str_contains($settings, 'oninput='),
            'nada de handlers en línea: la CSP los prohíbe'
        );
    }

    public function testElPanelAjustesNoCongelaElDefaultDeLosColores(): void
    {
        $settings = self::leer('views/admin/settings.php');

        // Los campos de TEXTO se prellenan con el valor GUARDADO, no con el
        // efectivo: si `gradient_start` mostrara el primario (su fallback) y el
        // admin guardara sin tocarlo, la fila quedaría escrita y el degradado
        // dejaría de seguir al primario cuando este cambie. El selector de color
        // sí usa el efectivo, porque `type="color"` no admite vacío.
        foreach ([
            'instanceColorText'     => '$brandColorStored',
            'instanceGradStartText' => '$brandGradStartStored',
            'instanceGradEndText'   => '$brandGradEndStored',
        ] as $id => $prefijo) {
            $pos = strpos($settings, 'id="' . $id . '"');
            assertTrue($pos !== false, 'falta el campo ' . $id);
            $chunk = substr($settings, (int) $pos, 260);
            assertTrue(
                str_contains($chunk, $prefijo),
                $id . ' tiene que prellenarse con ' . $prefijo . ' (el valor guardado), no con el efectivo'
            );
        }
    }

    public function testElPatronDeColorAceptaLaFormaCortaYLaLarga(): void
    {
        $settings = self::leer('views/admin/settings.php');
        assertTrue(
            substr_count($settings, 'pattern="#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?"') >= 4,
            'el patrón del navegador tiene que aceptar #rgb y #rrggbb, o rechazaría un valor que el servidor sí admite'
        );
    }
}
