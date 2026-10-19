<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * Interacción del fill para estrellas y ranking.
 *
 * Dos cosas que un formulario publicado hace mal en silencio:
 *
 *   · Ranking: responderlo obliga a escribir 1..N en cada fila. Los botones ▲▼
 *     lo convierten en reordenar, y **el orden visual pasa a ser la respuesta**
 *     (cada input toma su puesto 1..N). Los `name` son de la opción original,
 *     así que el mapa opción=>orden que espera el servidor no cambia; eso es lo
 *     que fija la parte estática.
 *   · Estrellas: al elegir la N solo se pintaba la N. Rellenar 1..N es
 *     cosmético (el valor sigue siendo un único radio 1..max): si alguien lo
 *     atara a la validación, cambiaría la respuesta sin decirlo.
 *
 * Hay dos capas, como en el resto del fill: el arnés `tests/js/fill-ranking.js`
 * corre las funciones reales contra un DOM mínimo (necesita `node`), y los
 * métodos de aquí fijan el contrato sin node.
 */
class FillInteractionTest
{
    private const JS = 'public/assets/js/fill.js';
    private const CSS = 'public/assets/css/app.css';
    private const VIEW = 'views/fill/show.php';
    private const HARNESS = 'tests/js/fill-ranking.js';

    /* ---------- comportamiento (necesita node) ---------- */

    public function testMoverElRankingRenumeraPorOrdenVisualYSincronizaElBorrador(): void
    {
        if (!$this->nodeAvailable()) {
            printf("        (sin node: la interacción del ranking no se ejecuta; %s)\n", self::HARNESS);
            return;
        }

        $output = [];
        $code = 0;
        $command = escapeshellcmd('node') . ' ' . escapeshellarg($this->path(self::HARNESS)) . ' 2>&1';
        @exec($command, $output, $code);

        $text = implode("\n", $output);
        assertEquals(0, $code, 'el arnés del ranking falló (código ' . $code . "):\n" . $text);
        assertTrue(
            str_contains($text, 'Fallidos: 0'),
            'el arnés del ranking no llegó al final o tuvo fallos:' . "\n" . $text
        );
    }

    /* ---------- contrato (siempre) ---------- */

    public function testCadaFilaDelRankingTraeSusDosBotonesDeMover(): void
    {
        $view = (string) file_get_contents($this->path(self::VIEW));

        assertTrue(
            str_contains($view, 'data-vf-rank-move="up"') && str_contains($view, 'data-vf-rank-move="down"'),
            'la fila del ranking necesita sus dos botones (subir y bajar): sin el de bajar, media '
                . 'opción no existe'
        );
        assertTrue(
            str_contains($view, "\$t('ui.rank_up')") && str_contains($view, "\$t('ui.rank_down')"),
            'los botones de mover llevan aria-label por i18n: son de solo icono, así que sin '
                . 'etiqueta no tienen nombre accesible'
        );
        assertTrue(
            str_contains($view, 'type="button"'),
            'los botones de mover tienen que ser type="button": sin él envían el formulario al pulsarlos'
        );
    }

    public function testMoverUnaFilaNoTocaElNameYRestauraElOrdenAlVolver(): void
    {
        $body = $this->functionBody('function moveRanking(');

        assertTrue(
            !str_contains($body, 'name'),
            'moveRanking() no puede tocar el atributo name: el name ata cada input a su opción '
                . 'original y es la mitad del contrato con el servidor (opción => orden)'
        );
        assertTrue(
            str_contains($body, 'renumberRanking('),
            'mover una fila tiene que renumerar por el orden visual: si no, quedaría un hueco o '
                . 'un repetido y la validación (ranking_unique) rechazaría el envío'
        );
        assertTrue(
            str_contains((string) file_get_contents($this->path(self::JS)), 'syncRankingOrder(card.querySelector'),
            'setFieldValue() tiene que sincronizar el orden del ranking restaurado: con los botones, '
                . 'un borrador con posiciones se lee mal si las filas quedan en el orden original'
        );
    }

    public function testLasEstrellasSeRellenanYLosBotonesDeMoverTienenEstilo(): void
    {
        $js = (string) file_get_contents($this->path(self::JS));
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($this->path(self::CSS)));

        assertTrue(
            str_contains($js, 'function paintStars(')
                && str_contains($js, "closest('.star-selector')")
                && str_contains($js, "classList.toggle('is-lit'"),
            'fill.js tiene que pintar 1..N al elegir una estrella (y al pasar el ratón), con la '
                . 'clase is-lit: es lo único que hace visible el rating clásico'
        );
        assertTrue(
            str_contains($css, '.star-btn.is-lit'),
            'app.css necesita .star-selector .star-btn.is-lit: sin la regla, el JS pinta una clase '
                . 'que nadie colorea'
        );
        assertTrue(
            str_contains($css, '.vf-rank-btn'),
            'app.css necesita .vf-rank-btn: sin estilo, los botones de mover salen sin caja'
        );
    }

    public function testElNumeroSeOcultaConJsYQuedaComoSinJs(): void
    {
        $js = (string) file_get_contents($this->path(self::JS));
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($this->path(self::CSS)));
        $view = (string) file_get_contents($this->path(self::VIEW));

        assertTrue(
            str_contains($js, "classList.add('vf-ranking--js')"),
            'fill.js tiene que marcar el ranking como realzado con JS: sin esa clase el número '
                . 'no se oculta y el campo sigue editable'
        );
        assertTrue(
            str_contains($css, '.vf-ranking--js .vf-ranking-input'),
            'app.css tiene que ocultar el input numérico bajo la clase de realce: es el contrato '
                . 'con fill.js (el input se queda en el DOM porque lo lee el servidor)'
        );
        assertTrue(
            str_contains($css, '.vf-ranking--js .vf-rank-move') && str_contains($css, '.vf-rank-move {'),
            'las flechas van ocultas por defecto y las revela la clase de realce: sin JS no hay '
                . 'botones muertos, y con JS el orden se controla con ellos'
        );
        assertFalse(
            str_contains($view, 'class="vf-rank-move d-flex'),
            'el contenedor de las flechas no puede llevar `d-flex` (Bootstrap lo marca `!important`): '
                . 'anularía el `display: none` del estado sin JS'
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
