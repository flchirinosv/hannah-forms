<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * P1 — La sugerencia de PII del builder AVISA; no marca.
 *
 * `fields.is_pii` es la única puerta del enmascarado (`responses.pii` decide si
 * sale o no, y `App\Services\Pii` es el único sitio que enmascara). El dueño lo
 * activa a mano, y ese es el fallo que costaba caro: una pregunta que se titula
 * «Nombre y apellido» se quedaba en claro para todo el panel, sin error ni aviso
 * en ninguna parte.
 *
 * La ayuda del builder avisa cuando la ETIQUETA huele a dato personal y ofrece
 * un botón de un clic. Lo que este test fija es el límite de esa ayuda, que es
 * donde se puede convertir en un problema:
 *
 *   1. AVISA, no MARCA. El patrón existe en el cliente y no puede escribir
 *      `is_pii` por su cuenta: «Nombre de la empresa» y «¿Qué nombre le pones a
 *      tu proyecto?» no son datos personales, y un auto-marcado los escondería
 *      sin que nadie lo pidiera. Marcar es una decisión del dueño, y un dato
 *      personal mal clasificado hacia el otro lado se publica sin avisar.
 *   2. La única forma de que el patrón escriba el flag es el botón, que acaba en
 *      `applySettings()` como cualquier otro cambio del panel.
 *   3. «No es» es POR ETIQUETA: se descarta la pregunta que se estaba mirando,
 *      no el campo para siempre. Si el dueño reescribe la etiqueta, es otra
 *      pregunta y el aviso vuelve (por eso se guarda la cadena, no un booleano).
 *   4. Secciones y páginas quedan fuera: el servidor les fuerza `is_pii = false`
 *      porque no guardan respuestas, así que sugerir ahí sería ruido.
 *
 * Es un test de contrato de contenido (como `BuilderValidationsContractTest`),
 * no de DOM: no hay jsdom en el proyecto y el arnés de `tests/js` es del
 * `fill.js`. Lo que ata es que la estructura que sostiene el comportamiento no
 * desaparezca: sin botón no hay marcado de un clic, y sin estado por etiqueta
 * el descarte se vuelve permanente.
 */
class BuilderPiiSuggestionTest
{
    private const JS = 'public/assets/js/builder.js';
    private const PANEL = 'views/builder/index.php';

    private function js(): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::JS);
        assertTrue($src !== '' && $src !== false, 'No se pudo leer ' . self::JS);
        return $src;
    }

    private function panel(): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::PANEL);
        assertTrue($src !== '' && $src !== false, 'No se pudo leer ' . self::PANEL);
        return $src;
    }

    /** 1. El patrón existe, pero no escribe el flag: avisa y nada más. */
    public function testElPatronDeSugerenciaNuncaEscribeElFlagPorSiMismo(): void
    {
        $js = $this->js();

        assertTrue(
            str_contains($js, 'PII_HINT_RE'),
            'El patrón de sugerencia de PII desapareció de builder.js.'
        );

        // ElÚnico sitio donde se puede llegar a `fieldPii.checked = true` es el
        // botón. Si alguien lo mueve a `updatePiiSuggestion()` (o al propio
        // listener de la etiqueta) el auto-marcado está de vuelta, y este test
        // lo dice con nombre y apellidos.
        assertSame(
            1,
            preg_match_all('/fieldPii\.checked\s*=\s*true/', $js),
            'Solo el botón de «Sí, es dato personal» puede activar `fieldPii`. '
                . 'Si esto vale más de 1, hay un auto-marcado: una pregunta como '
                . '«Nombre de la empresa» se escondería sola.'
        );
    }

    /** 2. El botón existe, es alcanzable y su clic acaba en el guardado. */
    public function testElBotonDeMarcarEstaEnElPanelYGuarda(): void
    {
        $panel = $this->panel();
        $js = $this->js();

        assertTrue(
            str_contains($panel, 'id="btnPiiSuggest"'),
            'El botón de marcado de PII no está en el panel del builder.'
        );
        assertTrue(
            str_contains($panel, 'id="piiSuggestWrap"'),
            'Falta el aviso de PII en el panel del builder.'
        );
        assertTrue(
            (bool) preg_match(
                "/getElementById\('btnPiiSuggest'\)[\s\S]{0,400}?applySettings\(\)/",
                $js
            ),
            'El botón de PII tiene que pasar por `applySettings()`: si no, el flag '
                . 'se marca en pantalla y no se guarda.'
        );
    }

    /** 3. «No es» se recuerda POR ETIQUETA, no como un boolean eterno. */
    public function testElDescarteGuardaLaEtiquetaYNoUnBooleano(): void
    {
        $js = $this->js();

        assertTrue(
            str_contains($js, 'piiDismissedLabel'),
            'El descarte de la sugerencia de PII tiene que recordar la etiqueta.'
        );
        assertTrue(
            (bool) preg_match(
                "/getElementById\('btnPiiDismiss'\)[\s\S]{0,400}?piiDismissedLabel\s*=\s*el\.fieldLabel\.value\.trim\(\)/",
                $js
            ),
            '«No es» debe descartar la etiqueta que se está mirando. Si se guarda '
                . 'un booleano, el aviso no vuelve nunca aunque el dueño reescriba '
                . 'la pregunta, que es justo el caso que lo requería.'
        );
    }

    /** 4. Secciones y páginas: no se sugiere, porque no guardan respuestas. */
    public function testLaSugerenciaRespetaQueElControlSeEscondaEnEstructurales(): void
    {
        $js = $this->js();

        assertTrue(
            (bool) preg_match(
                "/function updatePiiSuggestion\(\)[\s\S]{0,600}?fieldPiiWrap\.classList\.contains\('d-none'\)[\s\S]{0,300}?classList\.add\('d-none'\)/",
                $js
            ),
            'La sugerencia de PII debe callarse cuando el interruptor está oculto: '
                . 'el servidor fuerza `is_pii = false` en secciones y páginas, así '
                . 'que un aviso ahí sería un callejón sin salida.'
        );
    }
}
