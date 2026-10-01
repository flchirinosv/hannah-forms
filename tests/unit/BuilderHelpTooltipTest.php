<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * La ayuda del modal de configuración vive en un tooltip, no en un párrafo.
 *
 * El problema que resuelve: con doce ajustes, cada uno con su texto gris debajo,
 * el modal era una columna de párrafos. Los controles quedaban separados de su
 * etiqueta, el grupo «Programación y cupo» ocupaba más alto que el de al lado y
 * hadía que recorrer el texto para saber qué se iba a tocar. Ahora el texto se
 * lee al pasar por encima (o al enfocar, o al tocar) de un `?` junto a la
 * etiqueta.
 *
 * Lo que estos tests fijan son las DOS mitades del cambio, porque la segunda es
 * la que se rompe en silencio:
 *
 *  1. La EXPLICACIÓN se esconde: la ayuda está en `data-bs-title` y se inicializa
 *     con `hover focus click` (el `click` es lo que la hace alcanzable en un
 *     móvil, donde no hay hover).
 *  2. El ESTADO NO se esconde: `stFontNote`, `stAdvanceHint`, `stAccessHint` y
 *     `stReviewHint` los escribe la aplicación al vuelo para decir si el ajuste
 *     sirve. Si un refactor los metiera en un tooltip, quien no pasara el ratón
 *     por encima creería que un ajuste guardado funciona cuando no es así.
 *
 * Sin BD: solo lee archivos.
 */
class BuilderHelpTooltipTest
{
    private const VISTA_BUILDER = 'views/builder/index.php';
    private const JS_APP = 'public/assets/js/app.js';
    private const CSS_APP = 'public/assets/css/app.css';

    /** El `?` se pinta desde una closure de la vista, no desde un Partial. */
    public function testLaAyudaSeGeneraConUnBotonDataBsToggle(): void
    {
        $vista = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::VISTA_BUILDER);

        assertTrue(str_contains($vista, '$helpButton ='), 'la vista declara la closure de ayuda');
        assertTrue(
            str_contains($vista, "class=\"app-help\" data-bs-toggle=\"tooltip\" data-bs-html=\"true\""),
            'el botón lleva data-bs-toggle=tooltip y data-bs-html (para que <code> siga siendo código)'
        );
        assertTrue(str_contains($vista, 'data-bs-title=' . '"'), 'y el texto viaja en data-bs-title');
        assertTrue(
            str_contains($vista, 'aria-label=' . '"'),
            'el botón tiene nombre accesible: en un móvil es la única etiqueta que hay'
        );
    }

    /** Los tooltips se inician en app.js, que se carga en todas las páginas. */
    public function testLosTooltipsSeInicianEnAppJsConHoverFocusClick(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::JS_APP);

        assertTrue(str_contains($js, ".app-help[data-bs-toggle=\"tooltip\"]"), 'app.js busca los .app-help');
        assertTrue(str_contains($js, 'bootstrap.Tooltip'), 'con el plugin de Bootstrap');
        assertTrue(
            str_contains($js, "trigger: 'hover focus click'"),
            'con click incluido: en un móvil no hay hover y la ayuda sería inalcanzable'
        );
        assertTrue(str_contains($js, 'container: \'body\''), 'y fuera del modal, que recorta el tooltip');
    }

    /**
     * El clic en un `?` que cuelga de un `<label for=...>` no debe encender el
     * interruptor de al lado: el clic en un label reenvía al control.
     */
    public function testElClicEnLaAyudaNoDisparaElControlDelLabel(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::JS_APP);

        assertTrue(str_contains($js, "if (btn.closest('label'))"), 'se comprueba que el botón vive en un label');
        assertTrue(str_contains($js, 'e.preventDefault();'), 'y se anula el reenvío al control');
        assertTrue(str_contains($js, 'e.stopPropagation();'), 'y la propagación, que el modal usa para cerrar');
    }

    /** La mitad que se rompe en silencio: el estado no puede acabar en un tooltip. */
    public function testLosHintsDeEstadoSiguenVisibles(): void
    {
        $vista = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::VISTA_BUILDER);

        foreach (['stFontNote', 'stAdvanceHint', 'stAccessHint', 'stReviewHint'] as $id) {
            $pos = strpos($vista, 'id="' . $id . '"');
            assertTrue($pos !== false, 'el hint ' . $id . ' sigue en el modal');
            if ($pos === false) {
                continue;
            }
            // Se mira la ETIQUETA que lo envuelve: si algún día alguien lo
            // convierte en data-bs-title, el assert de este bloque se queda sin
            // comprobar nada, así que se mira el trozo de alrededor.
            $trozo = substr($vista, max(0, $pos - 260), 320);
            assertFalse(
                str_contains($trozo, 'data-bs-title'),
                $id . ' es estado (lo escribe la aplicación), no explicación: no va en un tooltip'
            );
        }
    }

    /**
     * Y que el texto que sí era explicación haya salido del cuerpo del modal.
     *
     * Solo se miran los grupos que el modal CONSERVA (tema y comportamiento): los
     * operativos salen de aquí en el bloque siguiente del plan, y cuando se
     * quiten esta comprobación pasa a abarcar el modal entero.
     */
    public function testLosTextosDeAyudaYaNoEstanSueltosEnLosGruposQueQuedan(): void
    {
        $vista = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::VISTA_BUILDER);
        $ini = strpos($vista, '>Tema</h6>');
        $fin = strpos($vista, '>Programación y cupo</h6>', $ini === false ? 0 : $ini);
        assertTrue($ini !== false && $fin !== false, 'se localizan los dos grupos que quedan en el modal');
        if ($ini === false || $fin === false) {
            return;
        }
        $bloque = substr($vista, $ini, $fin - $ini);
        // Lo único que puede seguir siendo un `<p class="form-text">` en este
        // bloque es `#stFontNote`, que lleva `role="status"` porque lo escribe
        // la aplicación al validar la fuente.
        foreach (explode("\n", $bloque) as $linea) {
            if (str_contains($linea, '<p class="form-text')) {
                assertTrue(
                    str_contains($linea, 'role="status"'),
                    'todo `form-text` que queda en el bloque es estado: ' . trim($linea)
                );
            }
        }
        assertTrue(str_contains($bloque, '$helpButton('), 'y en su lugar hay botones de ayuda');
    }

    /** El CSS del botón: existe y no depende de nada que no esté en el repo. */
    public function testElBotonDeAyudaTieneCssYNoUsaTokensInexistentes(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::CSS_APP);

        assertTrue(str_contains($css, '.app-help {'), 'el botón está pintado en app.css');
        assertTrue(str_contains($css, '.app-help::after'), 'con área táctil ampliada para el dedo');
        assertFalse(
            str_contains($css, 'var(--vf-muted'),
            'no se inventan tokens: el color sale de --vf-text-muted'
        );
    }
}