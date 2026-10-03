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
 *  2. El ESTADO NO se esconde: `stFontNote` y `stAdvanceHint` (en el modal) y
 *     `stAccessHint` / `stReviewHint` (en la ficha, que se pintan desde
 *     `dashboard.js`) los escribe la aplicación al vuelo para decir si el ajuste
 *     sirve. Si un refactor los metiera en un tooltip, quien no pasara el ratón
 *     por encima creería que un ajuste guardado funciona cuando no es así.
 *
 * El nombre de la clase se quedó en «Builder» aunque la mitad de los ajustes se
 * mudara a la ficha: la regla que fija estos tests es de las dos pantallas.
 *
 * Sin BD: solo lee archivos.
 */
class BuilderHelpTooltipTest
{
    private const VISTA_BUILDER = 'views/builder/index.php';
    private const VISTA_FICHA = 'views/dashboard/forms/show.php';
    private const JS_APP = 'public/assets/js/app.js';
    private const JS_DASHBOARD = 'public/assets/js/dashboard.js';
    private const CSS_APP = 'public/assets/css/app.css';

    /**
     * El `?` se pinta con UN helper (`View::help()`), no con una closure por
     * vista: hay dos pantallas con ajustes y dos copias del botón divergen.
     * Aquí se comprueba el helper (que es donde vive el HTML) y que las dos
     * vistas lo usen.
     */
    public function testLaAyudaSeGeneraConUnBotonDataBsToggle(): void
    {
        $helper = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/View.php');

        assertTrue(str_contains($helper, 'public static function help('), 'el helper vive en View');
        assertTrue(
            str_contains($helper, "class=\"app-help\" data-bs-toggle=\"tooltip\" data-bs-html=\"true\""),
            'el botón lleva data-bs-toggle=tooltip y data-bs-html (para que <code> siga siendo código)'
        );
        assertTrue(str_contains($helper, 'data-bs-title="'), 'y el texto viaja en data-bs-title');
        assertTrue(
            str_contains($helper, 'aria-label="'),
            'el botón tiene nombre accesible: en un móvil es la única etiqueta que hay'
        );
        assertTrue(
            str_contains($helper, 'self::escape($html)'),
            'el texto se escapa al meterlo en el atributo'
        );

        foreach ([self::VISTA_BUILDER, self::VISTA_FICHA] as $vista) {
            $contenido = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $vista);
            assertTrue(str_contains($contenido, '$helpButton ='), $vista . ' declara $helpButton');
            assertTrue(str_contains($contenido, 'View::help('), $vista . ' y delega en View::help()');
            assertTrue(str_contains($contenido, '$helpButton('), $vista . ' lo usa en algún ajuste');
        }
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

    /**
     * La mitad que se rompe en silencio: el estado no puede acabar en un tooltip.
     *
     * Se comprueba en las DOS pantallas y además que cada hint lo pinte el JS
     * que corresponde. Un `stAccessHint` que se quedara en el modal (obsoleto,
     * sin listener) o que viviera en la ficha sin su `textContent` aparecerían
     * igual de bien en el HTML: el aviso es solo para quien cambia el ajuste y
     * mira, así que un hueco vacío pasa desapercibido.
     */
    public function testLosHintsDeEstadoSiguenVisibles(): void
    {
        $builder = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::VISTA_BUILDER);
        $ficha = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::VISTA_FICHA);

        $donde = [
            'stFontNote'     => [$builder, 'en el modal'],
            'stAdvanceHint'  => [$builder, 'en el modal'],
            'stAccessHint'   => [$ficha, 'en la ficha'],
            'stReviewHint'   => [$ficha, 'en la ficha'],
        ];

        foreach ($donde as $id => [$vista, $dondeEsta]) {
            $pos = strpos($vista, 'id="' . $id . '"');
            assertTrue($pos !== false, 'el hint ' . $id . ' sigue ' . $dondeEsta);
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
            assertTrue(
                str_contains($trozo, 'role="status"'),
                $id . ' se anuncia a un lector de pantalla: es un mensaje, no una etiqueta'
            );
        }

        // Y que de verdad se pinten: el hint sin `textContent` es un hueco.
        $jsBuilder = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/builder.js');
        $jsFicha = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::JS_DASHBOARD);

        assertTrue(str_contains($jsBuilder, "st('stFontNote')"), 'el builder escribe el aviso de la fuente');
        assertTrue(str_contains($jsBuilder, "st('stAdvanceHint')"), 'y el del avance automático');
        assertTrue(str_contains($jsFicha, "getElementById('stAccessHint')"), 'la ficha escribe el del modo de acceso');
        assertTrue(str_contains($jsFicha, "getElementById('stReviewHint')"), 'y el de la revisión');
    }

    /**
     * Y que el texto que sí era explicación haya salido del CUERPO de los dos
     * paneles de ajustes, no solo del modal: los operativos se mudaron a la
     * ficha y sus párrafos se mudaron con ellos.
     *
     * Se recorta a los paneles (`#formSettingsModal` y la tarjeta `#settings`) y
     * no a la vista entera a propósito: el resto del builder es el panel de la
     * pregunta, donde un `form-text` sí es legitimately explicación (los formatos
     * de un adjunto, p. ej.), y la tarjeta de Acceso tiene los suyos. Un
     * `role="status"` obligatorio en toda la vista sería una regla falsa; y sin
     * recorte, este test no distinguiría los ajustes de una explicación suelta.
     *
     * La ficha se recorta POR SU IDENTIFICADOR y hasta el final de la vista, y
     * no entre dos tarjetas. Antes el corte era la tarjeta de Acceso, que iba
     * justo detrás: un par de anclas que eran una suposición de orden, así que
     * al mover las tarjetas el test caía sin que nada estuviera mal —o, peor,
     * seguía mirando un panel que ya no era el que decía mirar—. Ahora
     * `#settings` es la última tarjeta de la ficha, así que «hasta el final» SÍ
     * es el panel. Anclar por `id` y no por la clase del `div` también evita que
     * un cambio de margen rompa un test que no va de márgenes.
     */
    public function testLosTextosDeAyudaYaNoEstanSueltosEnLosPanelesDeAjustes(): void
    {
        $paneles = [
            self::VISTA_BUILDER => ['<div class="modal fade" id="formSettingsModal"', '<div class="modal-footer border-0">'],
            self::VISTA_FICHA   => ['id="settings"', null],
        ];

        foreach ($paneles as $ruta => [$ini, $fin]) {
            $vista = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $ruta);
            $desde = strpos($vista, $ini);
            $hasta = $desde === false ? false
                : ($fin === null ? strlen($vista) : strpos($vista, $fin, (int) $desde));

            assertTrue($desde !== false && $hasta !== false, 'se recorta el panel de ajustes de ' . $ruta);
            if ($desde === false || $hasta === false) {
                continue;
            }

            $bloque = substr($vista, $desde, $hasta - $desde);
            assertTrue(str_contains($bloque, '$helpButton('), $ruta . ': hay botones de ayuda');

            foreach (explode("\n", $bloque) as $linea) {
                if (!str_contains($linea, '<p class="form-text')) {
                    continue;
                }
                assertTrue(
                    str_contains($linea, 'role="status"'),
                    $ruta . ': todo `form-text` del panel es estado (lo escribe la aplicación): ' . trim($linea)
                );
            }
        }
    }

    /**
     * La partición de los ajustes. Si alguien devuelve el acceso al builder, o
     * mete el tema en la ficha, las dos listas de `FormSettings` dejan de cubrir
     * `defaults()` y un ajuste se queda sin puerta que lo escriba.
     */
    public function testCadaAjusteOperativoSeEditaEnLaFichaYNingunoEnElBuilder(): void
    {
        $builder = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::VISTA_BUILDER);
        $ficha = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::VISTA_FICHA);

        foreach ([
            'access_mode', 'open_at', 'close_at', 'quota_max', 'notify_emails',
            'confirm_to_field', 'webhook_url', 'thanks_message', 'review_enabled',
            'locale',
        ] as $campo) {
            assertTrue(str_contains($ficha, 'name="' . $campo . '"'), $campo . ' se edita en la ficha');
            assertFalse(
                str_contains($builder, 'id="' . $campo . '"'),
                $campo . ' ya no está en el modal del constructor'
            );
        }

        // Y al revés: los de diseño no se han colado en la ficha.
        foreach (['stPrimary', 'stBackground', 'stFont', 'stQuizEnabled', 'stEmbedEnabled'] as $campo) {
            assertTrue(str_contains($builder, 'id="' . $campo . '"'), $campo . ' sigue en el constructor');
            assertFalse(str_contains($ficha, 'id="' . $campo . '"'), $campo . ' no está en la ficha');
        }
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