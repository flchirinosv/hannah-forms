<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View;
use App\Services\FieldAggregator;

/**
 * W3 — Las vistas de una tarjeta y los gráficos que las acompañan.
 *
 * Dos mitades, como en W2: el servidor decide QUÉ vistas ofrece cada familia
 * (`FieldAggregator::VISTAS`, una lista blanca) y pinta el conmutador; el JS
 * las ejecuta. Aquí se comprueban las dos, porque un botón que aparece y no
 * hace nada (o una vista ofrecida que miente) no da ningún error.
 *
 * Solo HTML y texto: no toca la base de datos.
 */
class AnalyticsChartsTest
{
    private static function fuente(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    private static function conmutador(string $familia, string $activa): string
    {
        return (string) View::partial('analytics/_views', [
            'views'  => FieldAggregator::VISTAS[$familia],
            'active' => $activa,
        ]);
    }

    /**
     * La lista blanca, exacta y por familia. Si alguien «mejora» el selector
     * añadiendo un anillo a los numéricos, este test lo frena: un doughnut
     * sobre 10 000 valores distintos no informa, y una línea sobre categóricas
     * tampoco.
     */
    public function testCadaFamiliaTieneSuListaBlancaExacta(): void
    {
        assertEquals(['bars', 'doughnut', 'table'], FieldAggregator::VISTAS['choice']);
        assertEquals(['histogram', 'summary'], FieldAggregator::VISTAS['numeric']);
        assertEquals(['histogram', 'summary'], FieldAggregator::VISTAS['nps']);
        assertEquals(['table', 'bars'], FieldAggregator::VISTAS['ranking']);

        assertFalse(
            in_array('doughnut', FieldAggregator::VISTAS['numeric'], true),
            'un anillo no representa una distribución numérica'
        );
        assertFalse(
            in_array('histogram', FieldAggregator::VISTAS['choice'], true),
            'no hay histograma sin eje numérico'
        );
        assertFalse(
            in_array('doughnut', FieldAggregator::VISTAS['ranking'], true),
            'un promedio de orden no es una parte de un todo'
        );
    }

    /**
     * Toda familia declarada tiene que ser alcanzable desde algún tipo, y cada
     * tipo con tarjeta tiene que caer en una. Una familia sin tipos es código
     * muerto; un tipo con tarjeta y sin familia se queda sin conmutador.
     */
    public function testLasFamiliasYLosTiposSeCubrenEnLosDosSentidos(): void
    {
        $tipos = [
            'select' => 'choice', 'radio' => 'choice', 'checkbox' => 'choice',
            'number' => 'numeric', 'scale' => 'numeric', 'range' => 'numeric', 'star' => 'numeric',
            'nps'    => 'nps',
            'ranking' => 'ranking',
        ];
        $alcanzadas = [];
        foreach ($tipos as $tipo => $familia) {
            assertEquals($familia, FieldAggregator::familiaDe($tipo), "familia de '$tipo'");
            $alcanzadas[$familia] = true;
        }
        foreach (array_keys(FieldAggregator::VISTAS) as $familia) {
            assertArrayHasKey($familia, $alcanzadas, "la familia '$familia' no la usa ningún tipo");
        }

        // Los que no tienen conmutador (matriz: una sola representación; texto
        // abierto: lista; secciones: no guardan respuestas).
        foreach (['matrix', 'text', 'textarea', 'file', 'section', 'page'] as $tipo) {
            assertNull(FieldAggregator::familiaDe($tipo), "'$tipo' no debe ofrecer vistas");
        }
    }

    /**
     * El conmutador pinta un botón por vista permitida, con una sola activa y
     * con el nombre accesible (el icono solo no dice nada a un lector).
     */
    public function testElConmutadorPintaUnaSolaVistaActiva(): void
    {
        $html = self::conmutador('choice', 'bars');

        foreach (FieldAggregator::VISTAS['choice'] as $vista) {
            assertTrue(
                str_contains($html, 'data-vf-view="' . $vista . '"'),
                "falta el botón de la vista '$vista'"
            );
        }
        assertEquals(1, substr_count($html, 'aria-pressed="true"'), 'solo una vista llega activa');
        assertSame(1, preg_match('/data-vf-view="bars"\s+aria-pressed="true"/', $html), 'la activa es la que pide el servidor');
        assertTrue(str_contains($html, 'visually-hidden'), 'cada icono lleva su texto para lectores');

        $ranking = self::conmutador('ranking', 'table');
        assertSame(1, preg_match('/data-vf-view="table"\s+aria-pressed="true"/', $ranking), 'el ranking abre en tabla');
    }

    /**
     * La matriz ya calculaba `colCounts` y no los pintaba nadie: la tabla
     * enseñaba las filas y obligaba a sumar a mano. La fila «Total» va en
     * `<tfoot>` (es un pie, no otra fila de datos) y el total general es la
     * suma de las celdas, no el número de respuestas de la cabecera.
     */
    public function testLaMatrizPintaLaFilaDeTotalesPorColumna(): void
    {
        $fuente = self::fuente('views/analytics/show.php');

        assertTrue(str_contains($fuente, '<tfoot>'), 'la matriz tiene pie de tabla');
        assertTrue(str_contains($fuente, "array_sum(\$mv['colCounts'])"), 'el total general suma las celdas');
        assertTrue(str_contains($fuente, "\$mv['colCounts'][\$col]"), 'cada columna lleva su total');
        assertTrue(str_contains($fuente, '>Total</th>'), 'el pie se rotula «Total»');
    }

    /**
     * Las tarjetas que ofrecen vistas llevan familia y los lienzos que el JS
     * necesita. Un `data-vf-family` sin canvas (o al revés) deja un botón que
     * no pinta nada y ningún test de PHP lo notaría.
     */
    public function testLasTarjetasDeclaranSuFamiliaYSuLienzo(): void
    {
        $fuente = self::fuente('views/analytics/show.php');

        assertTrue(str_contains($fuente, 'data-vf-family="choice"'), 'la tarjeta de opciones declara su familia');
        assertTrue(str_contains($fuente, 'data-vf-family="ranking"'), 'la de ranking también');
        assertTrue(str_contains($fuente, 'vf-chart-card'), 'las tarjetas comparten el contenedor del conmutador');
        assertTrue(
            str_contains($fuente, 'data-vf-family="<?= View::escape($familiaNumerica) ?>"'),
            'la familia numérica se resuelve con `FieldAggregator::familiaDe()` (numeric o nps)'
        );

        assertTrue(str_contains($fuente, 'data-vf-chart-box'), 'el JS necesita la caja que oculta «Resumen»');
        assertTrue(str_contains($fuente, 'class="ranking-chart"'), 'el ranking tiene lienzo para las barras');
        assertTrue(str_contains($fuente, 'vf-choice-table'), 'las opciones tienen tabla alternativa');
        assertTrue(
            str_contains($fuente, "'dist'   => \$stat['dist'] ?? []"),
            'el histograma viaja en data-chart, no se recalcula en el navegador'
        );
    }

    /**
     * El JS tiene que: leer la vista de `data-vf-view`, aceptar las claves de
     * la lista blanca y destruir el gráfico anterior (si no, cambiar de vista
     * apila instancias sobre el mismo canvas). Y no puede volver a la barra
     * redundante «Promedio/Mínimo/Máximo», que W3 retiró.
     */
    public function testElJsRepintaSinApilarYRetiraLaBarraRedundante(): void
    {
        $js = self::fuente('public/assets/js/analytics.js');

        assertTrue(str_contains($js, 'data-vf-view'), 'escucha el conmutador');
        assertTrue(str_contains($js, 'function destruir'), 'destruye el Chart antes de repintar');
        assertTrue(str_contains($js, 'chartsPorCanvas.delete'), 'suelta la instancia destruida');
        assertTrue(str_contains($js, "indexAxis: 'y'"), 'las barras de opciones y del ranking son horizontales');
        assertTrue(str_contains($js, 'window.vfRenderFieldChart'), 'expone el repintado para depurar');

        assertFalse(
            str_contains($js, "'Promedio', 'Mínimo', 'Máximo'"),
            'la barra redundante de promedio/mínimo/máximo ya no se pinta'
        );
    }
}
