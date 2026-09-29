<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * V1 — La barra de progreso del fill medía el paso a la vista, no el formulario.
 *
 * Regresión real (reportada por el autor de la app): en un formulario de
 * varias páginas, contestar la primera ponía la barra al 100%. La causa es que
 * `countAnswered()` medía sobre `visibleFieldCards()`, que excluye los bloques
 * con `.vf-step-hidden` — y esa clase la lleva el paso visible que no es el
 * actual, porque solo se ve un paso a la vez. O sea: el denominador era «las
 * preguntas de la página en la que estoy». Además, en una matriz (o un ranking)
 * bastaba UNA fila marcada para que `isEmptyValue(getFieldValue())` la diera
 * por contestada, cuando el envío exige todas (`val.matrix_all`).
 *
 * Hay dos capas de test porque los dos fallos se muerde el hocico en sitios
 * distintos:
 *
 *   · `tests/js/fill-progress.js` extrae del JS REAL las funciones de progreso
 *     y las corre contra un DOM mínimo. Es el que reproduce el 100% falso; sin
 *     `node` este entorno no lo ejecuta y el test lo dice, en vez de fingir.
 *   · Los métodos de aquí son el CONTRATO: que el progreso mida con su propio
 *     conjunto, que la matriz sea completa-o-nada y que un paso que deja de
 *     saltarse recupere sus tarjetas. Corren siempre, sin node.
 *
 * Ojo al escribir el caso de multipágina del JS: si las tarjetas no cuelgan de
 * un `.vf-page-block`, el filtro viejo no las excluía y el test pasaba verde con
 * el bug puesto. Ya está avisado ahí.
 */
class FillProgressTest
{
    private const JS = 'public/assets/js/fill.js';
    private const HARNESS = 'tests/js/fill-progress.js';

    /* ---------- comportamiento (necesita node) ---------- */

    public function testLaBarraMideElFormularioEnteroYLaMatrizEsCompletaONada(): void
    {
        if (!$this->nodeAvailable()) {
            // Sin node no se puede ejecutar el arnés. Se avisa y se pasa: el
            // runner trata un SKIP lanzado desde setUp() como ERROR, y ponerlo
            // en rojo en una máquina sin node sería un fallo inventado.
            printf("        (sin node: el comportamiento de la barra no se ejecuta; %s)\n", self::HARNESS);
            return;
        }

        $output = [];
        $code = 0;
        $command = escapeshellcmd(PHP_OS_FAMILY === 'Windows' ? 'node' : 'node')
            . ' ' . escapeshellarg($this->path(self::HARNESS)) . ' 2>&1';
        @exec($command, $output, $code);

        $text = implode("\n", $output);
        assertEquals(
            0,
            $code,
            'el arnés de progreso del fill falló (código ' . $code . "):\n" . $text
        );
        assertTrue(
            str_contains($text, 'Fallidos: 0'),
            'el arnés de progreso no llegó al final o tuvo fallos:\n' . $text
        );
    }

    /* ---------- contrato (siempre) ---------- */

    public function testElProgresoMideConSuPropioConjuntoDeTarjetas(): void
    {
        $body = $this->functionBody('function countAnswered(');

        assertTrue(
            str_contains($body, 'progressFieldCards()'),
            'countAnswered() tiene que medir sobre progressFieldCards(): si vuelve a '
                . 'usar visibleFieldCards() el denominador es solo el paso a la vista y la '
                . 'barra se llena al terminar la primera página'
        );
        assertTrue(
            !str_contains($body, 'visibleFieldCards()'),
            'countAnswered() volvió a usar visibleFieldCards(), que excluye el bloque del '
                . 'paso que no es el actual (.vf-step-hidden es de maquetación, no de estado)'
        );
    }

    public function testElConjuntoDelProgresoNoFiltraPorLaClaseDeMaquetacion(): void
    {
        $body = $this->functionBody('function progressFieldCards(');

        assertTrue(!str_contains($body, 'vf-step-hidden'), 'progressFieldCards() no debe mirar '
            . '.vf-step-hidden: esa clase la lleva el paso visible que no es el actual, así que '
            . 'filtrar por ella deja fuera justo lo que hay que contar');
        assertTrue(
            str_contains($body, 'vf-hidden'),
            'progressFieldCards() debe seguir excluyendo las tarjetas ocultas: ahí caen las '
                . 'que su condición o su sección ocultan, y también las del paso OMITIDO '
                . '(paintSteps le pone data-vf-skipped y applyCardVisibility las deja en vf-hidden)'
        );
    }

    public function testMatrizYRankingCuentanSoloSiEstanCompletos(): void
    {
        $src = (string) file_get_contents($this->path(self::JS));
        assertTrue(
            str_contains($src, 'function isCardAnswered('),
            'fill.js necesita isCardAnswered(): sin ella el progreso usa isEmptyValue(), que '
                . 'para una matriz solo dice «vacía» si están TODAS las filas vacías, y una '
                . 'fila contestada llenaba la barra de un formulario que luego no se podía enviar'
        );

        $body = $this->functionBody('function isCardAnswered(');
        assertTrue(
            str_contains($body, "type === 'matrix'")
                && str_contains($body, 'rows.length')
                && str_contains($body, 'input[type=radio]:checked'),
            'la rama de matriz tiene que comparar las filas marcadas contra el total de filas '
                . '(una fila de cinco no cuenta, igual que al enviar)'
        );
        assertTrue(
            str_contains($body, "type === 'ranking'")
                && str_contains($body, 'input[type=number]'),
            'el ranking es el mismo caso que la matriz (validateField exige todas las '
                . 'posiciones) y se comprueba con la misma regla'
        );
    }

    public function testUnPasoQueDejaDeSaltarseRecuperaSusTarjetas(): void
    {
        $src = (string) file_get_contents($this->path(self::JS));

        assertTrue(
            str_contains($src, 'function applyCardVisibility('),
            'fill.js necesita applyCardVisibility() separado de evaluateConditions(): paintSteps() '
                . 'también tiene que poder aplicar la visibilidad, porque ocultar las tarjetas de '
                . 'un paso saltado sin poder volver a mostrarlas dejaba un paso invisible para '
                . 'siempre en cuanto dejaba de aplicar su condición'
        );
        assertTrue(
            str_contains($src, "removeAttribute('data-vf-skipped')")
                && str_contains($src, "setAttribute('data-vf-skipped', '1')"),
            'paintSteps() tiene que poner Y quitar data-vf-skipped: ese atributo es el estado '
                . '"este paso no aplica" (la clase .vf-step-hidden es solo maquetación)'
        );
        assertTrue(
            !str_contains($this->functionBody('function paintSteps('), 'setHidden('),
            'paintSteps() ya no debe ocultar tarjetas a pelo: la visibilidad se decide en un solo '
                . 'sitio (applyCardVisibility), que es lo que permite volver a enseñarlas'
        );
    }

    public function testLaBarraSeRecalculaAlNavegarEntrePasos(): void
    {
        $body = $this->functionBody('function paintSteps(');
        assertTrue(
            str_contains($body, 'updateProgress()'),
            'paintSteps() tiene que recalcular la barra: con la lógica de salto-página, saltar o '
                . 'dejar de saltar un paso cambia cuántas preguntas cuentan, y navigating sin '
                . 'recontar dejaba el porcentaje del paso anterior'
        );
    }

    public function testLaBarraNoSeOcultaEnUnFormularioDeUnaPreguntaPorPagina(): void
    {
        $src = (string) file_get_contents($this->path(self::JS));
        assertTrue(
            str_contains($src, 'progressFieldCards().length < 2'),
            'la condición de visibilidad de la barra debe usar el conjunto del progreso, no el '
                . 'del paso actual: en un multipágina de una pregunta por página el paso tiene '
                . 'una sola tarjeta y la barra se escondía justo donde más hacía falta'
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
