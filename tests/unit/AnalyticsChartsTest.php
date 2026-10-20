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

    /**
     * G1 — El bug que dejaba las tarjetas en blanco.
     *
     * `pintar()` creaba un `Chart` sobre un canvas con una instancia viva,
     * Chart.js lanzaba «Canvas is already in use» y el `catch` se lo tragaba: el
     * lienzo quedaba vacío sin ningún error visible. El arnés
     * `tests/js/analytics-charts.js` corre las funciones REALES del ciclo de
     * vida (`soltarCanvas`/`destruir`/`crear`/`pintar`) contra un `Chart` falso
     * que imita el guard, así que la propiedad queda atada de verdad y no por
     * lectura de código. Necesita `node`; sin él avisa y pasa.
     */
    public function testElRepintadoNoDejaElLienzoEnBlanco(): void
    {
        if (!$this->nodeAvailable()) {
            printf("        (sin node: el repintado de gráficos no se ejecuta; %s)\n", self::HARNESS);
            return;
        }

        $output = [];
        $code = 0;
        $command = escapeshellcmd('node') . ' ' . escapeshellarg($this->path(self::HARNESS)) . ' 2>&1';
        @exec($command, $output, $code);

        $text = implode("\n", $output);
        assertEquals(0, $code, 'el arnés de los gráficos falló (código ' . $code . "):\n" . $text);
        assertTrue(
            str_contains($text, 'Fallidos: 0'),
            'el arnés de los gráficos no llegó al final o tuvo fallos:' . "\n" . $text
        );
    }

    /**
     * El contrato sin node: el JS tiene que soltar el canvas aunque la
     * instancia no esté en el `Map`, y no puede volver a tragarse el error de
     * reutilización. Es la mitad barata que sí corre siempre.
     */
    public function testElJsSueltaElCanvasFueraDelMapYNorepiteElError(): void
    {
        $js = self::fuente('public/assets/js/analytics.js');

        assertTrue(str_contains($js, 'function soltarCanvas'), 'hay una red de seguridad que suelta el canvas fuera del Map');
        assertTrue(str_contains($js, 'Chart.getChart(canvas)'), 'consulta el registro interno de Chart.js, no solo el Map');
        assertTrue(str_contains($js, 'catch (e) { /* ya destruido */ }'), 'destruir un Chart ya destruido no puede romper');
        // El error de reutilización NO puede terminar en el `console.error`: hay
        // un reintento antes. La única aparición del log es el fallo DEFINITIVO.
        assertSame(
            1,
            substr_count($js, "console.error('[VanillaForms] Error chart '"),
            'el log del error tiene que ser el fallo definitivo, no el primero (hay reintento)'
        );
    }

    /**
     * G5 — Las respuestas compuestas se leen, no se descifran.
     *
     * En la tabla de detalle un checkbox salía como «a; b», una matriz como
     * «Fila: valor; …» y un ranking como «Opción: 3» en una sola línea ancha.
     * Ahora cada valor es un chip, la matriz va fila→valor y el ranking se
     * ordena por el puesto. El marcador PII (`[oculto]`) sigue sin
     * reinterpretarse: solo un JSON VÁLIDO se reformatea.
     */
    public function testLasRespuestasCompuestasSePintanConChips(): void
    {
        $html = View::partial('analytics/_rows', [
            'submissions'      => [['id' => 1, 'finished_at' => '2026-10-19 10:00:00', 'participant_id' => null]],
            'answers'          => [1 => ['answers' => [
                11 => '["Fiebre","Tos"]',
                12 => '{"Edad":"18-25","Sexo":"F"}',
                13 => '{"Opcion B":"1","Opcion A":"2"}',
                14 => 'Ana & <López>',
            ]]],
            'answerableFields' => [
                ['id' => 11, 'type' => 'checkbox', 'label' => 'Síntomas'],
                ['id' => 12, 'type' => 'matrix', 'label' => 'Matriz'],
                ['id' => 13, 'type' => 'ranking', 'label' => 'Ranking'],
                ['id' => 14, 'type' => 'text', 'label' => 'Nombre'],
            ],
            'offset'           => 1,
            'formId'           => 0,
        ]);

        assertTrue(str_contains($html, 'vf-answer-chips'), 'el checkbox lista chips');
        assertSame(2, substr_count($html, 'class="vf-chip"'), 'un chip por valor de checkbox');
        assertTrue(str_contains($html, 'vf-answer-matrix'), 'la matriz se pinta como filas fila→valor');
        assertTrue(str_contains($html, 'vf-chip-val">18-25'), 'el valor de la matriz va aparte de la fila');
        assertTrue(str_contains($html, 'vf-answer-ranking'), 'el ranking es su propia lista');
        assertTrue(
            strpos($html, 'vf-chip-pos">1') < strpos($html, 'vf-chip-pos">2'),
            'el ranking se ordena por el puesto, no por el orden del JSON'
        );
        assertTrue(str_contains($html, 'Ana &amp; &lt;López&gt;'), 'un texto suelto se sigue escapando');
    }

    public function testElMarcadorPiiNoSeReformateaComoJson(): void
    {
        // Con PII en la lista de tipos, el valor se enmascara ANTES de mirar si
        // empieza por `[`. `[oculto]` empieza por `[` y NO es JSON: si el orden
        // fuera el otro, se perdería.
        $pii = View::partial('analytics/_rows', [
            'submissions'      => [['id' => 1, 'finished_at' => '2026-10-19 10:00:00', 'participant_id' => null]],
            'answers'          => [1 => ['answers' => [11 => 'Juan Pérez']]],
            'answerableFields' => [['id' => 11, 'type' => 'text', 'label' => 'Nombre']],
            'offset'           => 1,
            'formId'           => 0,
            'piiTypes'         => [11 => 'text'],
        ]);
        assertTrue(str_contains($pii, \App\Services\Pii::MASK), 'el marcador sobrevive como texto, no como lista JSON');
        assertFalse(str_contains($pii, 'vf-answer-chips'), 'un valor enmascarado no se convierte en chips');
    }

    /**
     * G2 — Con una etiqueta larga el conmutador de vista bajaba a una fila
     * nueva. La cabecera de la tarjeta usa `.vf-card-head` sin `flex-wrap` en el
     * bloque de acciones, y el título puede encogerse (`min-width:0`) para no
     * empujarlos.
     */
    public function testLaCabeceraDeTarjetaNoEmpujaElConmutador(): void
    {
        $fuente = self::fuente('views/analytics/show.php');
        $css = self::fuente('public/assets/css/app.css');

        assertTrue(str_contains($fuente, 'vf-card-head d-flex align-items-start justify-content-between'), 'la cabecera no usa `flex-wrap`');
        assertFalse(str_contains($fuente, 'vf-card-head d-flex flex-wrap'), 'un `flex-wrap` aquí vuelve a bajar los botones');
        assertSame(3, substr_count($fuente, 'class="vf-card-title"'), 'las tres tarjetas conmutables acotan su título');
        assertTrue(str_contains($css, '.vf-card-head > .vf-card-title'), 'el título se define en CSS');
        assertTrue(str_contains($css, 'min-width: 0;'), 'sin `min-width:0` el título empuja a los botones');
        assertTrue(str_contains($css, '.vf-card-head > .vf-card-actions'), 'el bloque de acciones queda anclado');
        assertTrue(str_contains($css, 'flex: 0 0 auto;'), 'las acciones no se encogen ni bajan de línea');
    }

    /**
     * G3 — El índice de preguntas se desbordaba porque un flex item de columna
     * tiene `min-width:auto` y no encoge por debajo del texto.
     */
    public function testElIndiceDePreguntasEncojeSuTexto(): void
    {
        $css = self::fuente('public/assets/css/app.css');

        assertTrue(str_contains($css, '.app-analytics-index .nav { min-width: 0; }'), 'el `nav` deja encoger a sus items');
        assertTrue(str_contains($css, '.app-analytics-index .nav-link'), 'los enlaces del índice tienen su regla');
        assertTrue(str_contains($css, 'text-overflow: ellipsis;'), 'y terminan en puntos suspensivos');
    }

    /**
     * G4 — La tabla de detalle es ancha (una columna por pregunta). Se pulió
     * con columna `#` y cabecera fijas, cebra y celdas compactas, sin cambiar
     * su estructura ni la regla móvil de tarjetas.
     */
    public function testLaTablaDeDetalleEsMasLegible(): void
    {
        $css = self::fuente('public/assets/css/app.css');

        assertTrue(str_contains($css, '.app-detail-table th:first-child'), 'la columna `#` se queda fija');
        assertTrue(str_contains($css, 'min-width: max-content;'), 'la tabla conserva un ancho mínimo para el scroll horizontal');
        assertTrue(str_contains($css, '.app-detail-table th {'), 'la cabecera de la tabla tiene su bloque');
        assertTrue(str_contains($css, 'position: sticky;'), 'cabecera y columna se pegan al desplazar');
        assertTrue(str_contains($css, 'tr:nth-child(even)'), 'hay cebra para seguir la fila');
        assertTrue(str_contains($css, '.app-detail-table tbody tr:nth-child(even) td:first-child'), 'la cebra tiñe también la celda fija (si no, desentona)');
        // La regla móvil (tabla→tarjetas con data-label) no se puede perder.
        assertTrue(str_contains($css, 'td::before'), 'la tabla sigue apilándose en tarjetas en móvil');
    }

    /* ---------- ayudas ---------- */

    private const HARNESS = 'tests/js/analytics-charts.js';

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

    private function path(string $rel): string
    {
        return dirname(__DIR__, 2) . '/' . $rel;
    }
}
