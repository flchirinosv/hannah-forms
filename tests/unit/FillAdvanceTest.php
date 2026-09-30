<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FormSettings;

/**
 * F5 — Avance automático entre páginas.
 *
 * La idea es sencilla (contestar el último campo obligatorio del paso salta al
 * siguiente) y por eso mismo es fácil de hacer mal de dos maneras que salen
 * caras:
 *
 *   · Que avance con un campo que el navegador da por bueno y la persona no
 *     escribió. `required` + `checkValidity()` dicen que un campo con un
 *     espacio ya está contestado; la revalidación del navegador además cambia
 *     de opinión entre navegadores. La medida de completitud tiene que ser
 *     `isCardAnswered()`, la misma que usan el envío y la barra de progreso.
 *   · Que el formulario se recorra solo al cargar. El borrador local restaura
 *     valores y `evaluateConditions()` resuelve pasos omitidos: si el avance
 *     escucha `change` sin más, un paso que el borrador dejó completo se come
 *     el resto del formulario sin que nadie haya tocado nada.
 *
 * Hay dos capas de test, y cada una cubre lo que la otra no:
 *
 *   · `tests/js/fill-advance.js` extrae del JS REAL `autoAdvanceAllowed()` y
 *     todo lo que decide por ella (incluidos `isCardAnswered` y `getFieldValue`,
 *     sin copiar ni una línea), y la corre contra un DOM mínimo. Es lo que
 *     distingue «obligatoria vacía» de «opcional vacía» de «paso omitido». Sin
 *     `node` no se ejecuta y el test lo dice, en vez de fingir.
 *   · Los métodos de aquí son el CONTRATO: que el opt-in sea opt-in, que el
 *     disparador sea `change` y no `input`, que el foco se mueva al bloque
 *     nuevo (y que ese bloque lo pueda recibir) y que el arranque no arme el
 *     mecanismo antes de tiempo. Corren siempre, sin node.
 *
 * Ojo al escribir el caso de multipágina del JS: si el arnés pasa un solo
 * bloque en `pageBlocks`, `currentStep >= pageBlocks.length - 1` siempre es
 * cierto y se acaba probando el guard de «último paso» sin querer. Está
 * avisado en el arnés.
 */
class FillAdvanceTest
{
    private const JS = 'public/assets/js/fill.js';
    private const HARNESS = 'tests/js/fill-advance.js';

    /* ---------- comportamiento (necesita node) ---------- */

    public function testSoloAvanzaTrasContestarLasObligatoriasVisiblesDelPaso(): void
    {
        if (!$this->nodeAvailable()) {
            // Sin node no se puede ejecutar el arnés. Se avisa y se pasa: el
            // runner trata un SKIP lanzado desde setUp() como ERROR, y ponerlo
            // en rojo en una máquina sin node sería un fallo inventado.
            printf("        (sin node: el comportamiento del avance automático no se ejecuta; %s)\n", self::HARNESS);
            return;
        }

        $output = [];
        $code = 0;
        $command = 'node ' . escapeshellarg($this->path(self::HARNESS)) . ' 2>&1';
        @exec($command, $output, $code);

        $text = implode("\n", $output);
        assertEquals(
            0,
            $code,
            'el arnés de avance automático del fill falló (código ' . $code . "):\n" . $text
        );
        assertTrue(
            str_contains($text, 'Fallidos: 0'),
            'el arnés de avance automático no llegó al final o tuvo fallos:\n' . $text
        );
    }

    /* ---------- el opt-in (siempre) ---------- */

    public function testElAvanceAutomaticoEsOptInYApagadoPorOmision(): void
    {
        assertSame(
            false,
            FormSettings::defaults()['advance']['auto'],
            'el avance automático tiene que venir apagado: es un detalle que molesta si aparece '
                . 'sin pedirlo, y en un formulario de una sola pregunta no hace nada'
        );

        assertSame(
            false,
            FormSettings::normalize([])['advance']['auto'],
            'un formulario sin el bloque advance no puede acabar con el avance activado'
        );
        assertSame(
            true,
            FormSettings::normalize(['advance' => ['auto' => true]])['advance']['auto'],
            'un advance.auto explícito tiene que respetarse'
        );
        assertSame(
            true,
            FormSettings::normalize(['advance' => ['auto' => '1']])['advance']['auto'],
            'el builder manda el booleano como checkbox, pero un "1" en un JSON guardado a mano '
                . 'tiene que activar el opt-in igual que en las demás banderas'
        );
        assertSame(
            false,
            FormSettings::normalize(['advance' => ['enabled' => true]])['advance']['auto'],
            'OJO: la clave del bloque es auto, no enabled. Si algún guardado trae enabled, se '
                . 'ignora a propósito: activar el avance por una clave que el builder nunca envía '
                . 'sería un opt-in que nadie enciende desde la interfaz'
        );
    }

    public function testElOptInLlegaAlClienteComoAtributoYLaVistaLoExpone(): void
    {
        $view = (string) file_get_contents($this->path('views/fill/show.php'));

        assertTrue(
            str_contains($view, "data-vf-auto-advance"),
            'views/fill/show.php tiene que poner data-vf-auto-advance en el #vfForm: el ajuste '
                . 'es del servidor y el cliente solo puede enterarse por un atributo'
        );
        assertTrue(
            str_contains($view, "\$settings['advance']['auto']"),
            'el atributo sale de settings.advance.auto, no de un literal: si el toggle del builder '
                . 'no hace nada, es porque aquí se está leyendo otra cosa'
        );
    }

    /* ---------- el disparador (siempre) ---------- */

    public function testElDisparadorEsChangeYNoInput(): void
    {
        $src = (string) file_get_contents($this->path(self::JS));

        assertTrue(
            (bool) preg_match("/form\.addEventListener\('change',[\s\S]{0,200}maybeAutoAdvance/", $src),
            "el avance automático tiene que colgar de un 'change' delegado en el form: en un "
                . "campo de texto, 'change' salta al perder el foco o al pulsar Enter, que es "
                . "«he terminado este campo». Con 'input' el formulario avanzaría a media palabra"
        );
        assertTrue(
            !preg_match("/addEventListener\('input',[\s\S]{0,200}maybeAutoAdvance/", $src),
            "no se debe lanzar el avance desde 'input': con teclado y con lector de pantalla eso "
                . 'es avanzar mientras se escribe'
        );
    }

    public function testLaDecisionCombinaContestadoYValido(): void
    {
        $body = $this->functionBody('function stepComplete(');

        assertTrue(
            str_contains($body, 'isCardAnswered('),
            'stepComplete() tiene que decidir con isCardAnswered(): es la medida que usan el '
                . 'envío y la barra, y la que trata la matriz como completa-o-nada. Medir aquí '
                . 'con otra cosa abre la puerta a que el paso esté «completo» y el envío no'
        );
        assertTrue(
            str_contains($body, 'validateField(card).length === 0'),
            '«terminado» son DOS cosas: contestado Y válido. Con isCardAnswered() solo, el '
                . 'avance se dispararía con un ranking de posiciones repetidas (que el campo ya '
                . 'tiene en rojo con val.ranking_unique) o con un email mal escrito, y el error '
                . 'se quedaría en la página anterior sin que nadie lo volviera a ver. El avance '
                . 'automático es lo único que cambia de página sin que la persona pulse nada, así '
                . 'que es justo este paso el que tiene que mirar la validez'
        );
        $src = (string) file_get_contents($this->path(self::JS));
        assertTrue(
            !preg_match('/function (stepComplete|requiredVisibleCards|autoAdvanceAllowed)\([\s\S]*?checkValidity\(/', $src),
            'el avance automático no puede usar checkValidity() ni reportValidity(): required '
                . 'considera contestado un campo con un espacio, y la respuesta de la validación '
                . 'del navegador no es la misma en todos los navegadores'
        );
    }

    public function testSoloCuentanLasObligatoriasVisiblesYSinObligatoriasNoSeAvanza(): void
    {
        $body = $this->functionBody('function requiredVisibleCards(');
        assertTrue(
            str_contains($body, "dataset.vfRequired === '1'"),
            'requiredVisibleCards() tiene que filtrar por obligatoria: una opcional no puede '
                . 'bloquear el salto, o el formulario se atasca en pasos que la persona ya no '
                . 'tiene nada que poner'
        );
        assertTrue(
            str_contains($body, 'conditionVisible('),
            'requiredVisibleCards() tiene que descartar las tarjetas que su condición o su '
                . 'sección ocultan: no se pueden contestar y el servidor tampoco las pide'
        );

        $complete = $this->functionBody('function stepComplete(');
        assertTrue(
            (bool) preg_match('/req\.length > 0/', $complete),
            'un paso SIN obligatorias visibles no está «completo»: no hay nada que esperar, y '
                . 'devolver true hacía que el primer cambio se comiera el resto del formulario'
        );
    }

    public function testElUltimoPasoYLosOmitidosNoAvanzan(): void
    {
        $body = $this->functionBody('function autoAdvanceAllowed(');

        assertTrue(
            (bool) preg_match('/currentStep >= pageBlocks\.length - 1/', $body),
            'el último paso lleva el botón de Enviar, no el de Siguiente: avanzar desde ahí se '
                . 'pasa por encima del envío'
        );
        assertTrue(
            str_contains($body, 'data-vf-skipped'),
            'un paso OMITIDO no puede ser ni origen ni señal: sus campos ni se validan ni se '
                . 'persisten, así que un salto desde ahí deja el formulario en un paso que no '
                . 'aplica. Ojo: el atributo, no la clase .vf-step-hidden, que también la llevan '
                . 'los pasos visibles que no son el actual'
        );
        assertTrue(
            (bool) preg_match("/getAttribute\('data-vf-skipped'\) === '1'/", $body),
            'el skip se comprueba contra el VALOR del atributo, no por presencia: toggleAttribute() '
                . 'deja el valor en "" y una comprobación por presencia marcaría como omitido un '
                . 'paso que no lo está. Es el mismo criterio que usa el resto de fill.js'
        );
    }

    public function testElArranqueNoArmaElMecanismoConElBorradorPuesto(): void
    {
        $src = (string) file_get_contents($this->path(self::JS));

        assertTrue(
            (bool) preg_match('/let autoAdvanceReady = false;/', $src),
            'autoAdvanceReady tiene que empezar en false: si el mecanismo está armado desde el '
                . 'primer momento, un paso que el borrador restaurado dejó completo avanza solo'
        );

        $readyAt = strpos($src, 'autoAdvanceReady = true;');
        $restoreAt = strpos($src, 'restoreDraft();');
        $evaluateAt = strpos($src, 'evaluateConditions();');
        assertTrue(
            $readyAt !== false && $restoreAt !== false && $evaluateAt !== false
                && $readyAt > $restoreAt
                && $readyAt > $evaluateAt,
            'autoAdvanceReady se enciende AL FINAL del arranque, después de restoreDraft() y de '
                . 'evaluateConditions(): hasta entonces los pasos que vienen del borrador y los '
                . 'omitidos todavía no son el estado final, y decidir sobre el provisional es '
                . 'saltarse la parte del formulario que la persona no ha visto'
        );
    }

    public function testIrAtrasNoRebotaAlSiguiente(): void
    {
        $body = $this->functionBody('function goPrev(');

        assertTrue(
            str_contains($body, 'cancelAutoAdvance()'),
            'volver atrás es una decisión consciente: si el paso al que se vuelve ya está '
                . 'completo (típico de un borrador), sin cancelar el temporizador el formulario '
                . 'rebota al siguiente y la persona pierde el sitio donde estaba'
        );
    }

    /* ---------- accesibilidad (siempre) ---------- */

    public function testElSaltoMueveElFocoAlBloqueDelPasoYElBloqueLoPuedeRecibir(): void
    {
        $src = (string) file_get_contents($this->path(self::JS));

        $advance = $this->functionBody('function doAutoAdvance(');
        assertTrue(
            str_contains($advance, 'focus: true'),
            'el salto automático tiene que pasar focus:true a gotoStep(): sin mover el foco, el '
                . 'teclado se queda en un campo del paso anterior (ya oculto) y quien navega con '
                . 'lector de pantalla no se entera de que ha cambiado de página'
        );
        assertTrue(
            str_contains($this->functionBody('function gotoStep('), 'opts.focus'),
            'gotoStep() tiene que hacer caso a opts.focus. Ojo con la tentación de mover el '
                . 'foco en TODOS los saltos: el manual también pasaría por ahí, y tirar al '
                . 'principio de la página en cada «Siguiente» es peor que no moverlo'
        );
        assertTrue(
            str_contains($this->functionBody('function focusCurrentStep('), 'pageBlocks[currentStep]'),
            'el foco va al BLOQUE del paso, no al primer input: al saltar, la persona todavía no '
                . 'ha leído las preguntas de la página nueva y meterle el cursor en un campo la '
                . 'manda a escribir a ciegas'
        );

        $view = (string) file_get_contents($this->path('views/fill/show.php'));
        assertTrue(
            str_contains($view, 'tabindex="-1"'),
            'el bloque de página necesita tabindex="-1" para que focus() le funcione: no lo pone '
                . 'en el orden de tabulación, solo lo hace focusable por script, que es lo que '
                . 'hace falta al saltar'
        );
        assertTrue(
            str_contains($view, 'aria-label='),
            'el bloque necesita nombre accesible, o el lector de pantalla anuncia un cambio de '
                . 'página sin decir a cuál se ha ido («Paso 2 de 3»)'
        );
        assertTrue(
            str_contains($view, "\$t('ui.paso')") && str_contains($view, "\$t('ui.de')"),
            'la etiqueta se compone con las claves de i18n de las que ya usa el badge del pie '
                . '(ui.paso / ui.de), no con un texto pegado: con un literal, el nombre accesible '
                . 'del bloque no cambiaría de idioma con el resto'
        );

        $js = $this->functionBody('function stepLabel(');
        assertTrue(
            str_contains($js, "t('ui.paso')") && str_contains($js, "t('ui.de')"),
            'stepLabel() de fill.js tiene que componerse con las MISMAS claves que la vista: si '
                . 'una de las dos se queda atrás, el bloque renumerado y su pie dicen cosas '
                . 'distintas'
        );
        assertTrue(
            str_contains($this->functionBody('function paintSteps('), 'stepLabel(rank + 1, vis.length)'),
            'paintSteps() renumera el nombre accesible del bloque con los mismos números que el '
                . 'badge del pie (rank y vis.length): al saltarse un paso, si solo se actualizara '
                . 'el badge, un lector de pantalla oiría «Paso 2 de 3» en el bloque y «Paso 2 de 2» '
                . 'en el pie de la misma página'
        );
    }

    public function testLosCamposDeTextoAvanzanConRetardoYLasOpcionesSinMas(): void
    {
        $src = (string) file_get_contents($this->path(self::JS));
        $body = $this->functionBody('function maybeAutoAdvance(');

        assertTrue(
            str_contains($body, "target.type === 'radio'")
                && str_contains($body, "target.type === 'checkbox'")
                && str_contains($body, "target.tagName === 'SELECT'"),
            'una opción marcada, desmarcada o elegida en un desplegable es una decisión cerrada '
                . 'y puede saltar ya'
        );
        assertTrue(
            str_contains($body, 'setTimeout('),
            'en un campo de texto el change llega al perder el foco, y quien va tabulando por el '
                . 'paso encadena varios: el retardo agrupa esos cambios y, si sigue escribiendo, '
                . 'cada uno lo postpone'
        );
        assertTrue(
            (bool) preg_match('/clearTimeout\(autoAdvanceTimer\)/', $body),
            'hay que cancelar el temporizador anterior: sin esto, el primer change de una racha '
                . 'sigue contando y el salto ocurre a mitad de la escritura del siguiente campo'
        );
        assertTrue(
            substr_count($src, 'autoAdvanceAllowed(target)') >= 2,
            'la decisión se tiene que volver a comprobar DENTRO del temporizador: entre el change '
                . 'y el salto la persona puede haber tocado otro campo del mismo paso'
        );
    }

    public function testElBuilderMandaElAjusteYLoVuelveALeer(): void
    {
        $builder = (string) file_get_contents($this->path('public/assets/js/builder.js'));
        $view = (string) file_get_contents($this->path('views/builder/index.php'));

        assertTrue(
            str_contains($view, 'id="stAdvanceAuto"'),
            'falta el interruptor en el panel de ajustes del builder'
        );
        assertTrue(
            str_contains($builder, "advance: { auto: g('stAdvanceAuto').checked }"),
            'buildPayload() tiene que mandar advance.auto: sin la clave, el ajuste se pierde al '
                . 'guardar aunque el interruptor esté marcado (y, al revés, el toggle de otro '
                . 'formulario no se vería marcado)'
        );
        assertTrue(
            str_contains($builder, 'st(\'stAdvanceAuto\').checked = !!(s.advance && s.advance.auto)'),
            'applySettings() tiene que releer advance.auto al abrir el panel del formulario'
        );
    }

    /* ---------- ayudas ---------- */

    private function nodeAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $out = [];
        $code = 0;
        @exec('node --version 2>&1', $out, $code);
        return $code === 0;
    }

    private function functionBody(string $signature): string
    {
        $src = (string) file_get_contents($this->path(self::JS));
        $start = strpos($src, $signature);
        assertTrue($start !== false, 'no se encontró ' . $signature . ' en ' . self::JS);
        $end = strpos($src, "\n    }", (int) $start);
        return $end === false ? '' : substr($src, (int) $start, (int) $end - (int) $start);
    }

    private function path(string $rel): string
    {
        return dirname(__DIR__, 2) . '/' . $rel;
    }
}
