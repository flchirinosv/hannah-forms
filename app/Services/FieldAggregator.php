<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Answer;
use App\Models\FormParticipant;
use App\Models\Submission;

/**
 * Fase 3: agregación de la analítica en un número FIJO de consultas.
 *
 * El problema: `AnalyticsController::show()` llamaba a un método de `Answer`
 * por pregunta (y otro para `non_empty`, y otro más si era NPS). Con 11
 * preguntas eran 16 SELECT; con 50 serían ~103 y con 200 ~403, todas con la
 * misma forma de filtro. La analítica es la pantalla que se recarga cada vez
 * que se toca un filtro de fecha, así que ese coste se paga una y otra vez.
 *
 * La solución: DOS consultas para el formulario entero —
 *   1. `nonEmptyCountsInForm()`  -> conteos no vacíos por campo;
 *   2. `valueCountsInForm()`     -> distribución de valores por campo.
 * y que el resto de la aritmética (promedios, NPS, matrices, rankings) se
 * resuelva en PHP sobre esos datos ya traídos.
 *
 * OJO al multiplicar por `c`: la consulta 2 agrupa por `(field_id, value)`, así
 * que cada fila trae cuántas respuestas había con ESE valor. Todo lo que antes
 * contaba "filas" ahora suma `c`. Olvidarse de un `* $c` no peta: devuelve
 * números plausibles pero equivocados, y por eso hay un test de equivalencia
 * contra los métodos por campo (`AnalyticsAggregationTest`).
 *
 * Por qué agrupa la consulta 2 en vez de traer cada respuesta: 10 000
 * respuestas de "Sí" son una fila en vez de 10 000, y el filtro de fechas sigue
 * aplicando en SQL (lo que se trae son solo los valores del rango pedido).
 */
final class FieldAggregator
{
    /**
     * Estadísticas por campo, en la MISMA forma que devolvía el bucle del
     * controller. La vista (`views/analytics/show.php`) no cambia.
     *
     * @param array $fields     campos del formulario (`id`, `type`, `options`)
     * @param int[] $fieldIds   ids de los campos ya filtrados (sin secciones)
     * @param array $piiTypes   field_id => tipo de los campos marcados (F1)
     * @param array|null $atr   filtro por atributo del listado (fase 7). Viaja a las
     *                           DOS consultas de abajo, y no a una: si el filtro
     *                           entrara solo en los valores, los «no vacíos» y los
     *                           promedios saldrían del formulario entero mientras los
     *                           gráficos salían del subconjunto.
     * @return array<int, array{field: array, values: mixed, count: int, non_empty: int, nps?: array, abiertas?: array}>
     */
    public static function forForm(
        int $formId,
        array $fields,
        array $fieldIds,
        int $total,
        string $from = '',
        string $to = '',
        array $piiTypes = [],
        ?array $atr = null
    ): array {
        $nonEmpty = Answer::nonEmptyCountsInForm($formId, $fieldIds, $from, $to, $atr);
        $values   = Answer::valueCountsInForm($formId, $fieldIds, $from, $to, $atr);

        // F1: el enmascarado va ANTES de agregar, no después. La consulta agrupa
        // por `(field_id, value)`, así que a estas alturas los valores crudos ya
        // vienen distintos entre sí: enmascarar el resultado daría N barras
        // `[oculto]` en vez de una con el total, y las etiquetas de los gráficos
        // (que van en `data-chart`) llevarían el dato.
        if ($piiTypes !== []) {
            $values = self::enmascararValores($values, $piiTypes);
        }

        // W2 — La tercera consulta del servicio, y solo si hay campos abiertos:
        // lo que la vista enseña debajo de «texto libre» no sale del agregado
        // (que agrupa por valor y ahí un párrafo es una fila única sin contexto).
        $abiertas = [];
        $idsAbiertos = [];
        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? '');
            if ($type !== 'section' && in_array($type, self::TIPOS_ABIERTOS, true)) {
                $idsAbiertos[] = (int) $field['id'];
            }
        }
        if ($idsAbiertos !== []) {
            $abiertas = Answer::latestByFieldsInForm($formId, $idsAbiertos, $from, $to, $atr, self::ABIERTAS_MAX);
            if ($piiTypes !== []) {
                $abiertas = self::enmascararAbiertas($abiertas, $piiTypes);
            }
        }

        $stats = [];
        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? '');
            if ($type === 'section') {
                // Las secciones informativas no generan respuestas.
                continue;
            }

            $fieldId = (int) $field['id'];
            $rows = $values[$fieldId] ?? [];

            $stat = [
                'field'     => $field,
                'values'    => self::valuesForType($type, $rows, $field),
                'count'     => $total,
                'non_empty' => $nonEmpty[$fieldId] ?? 0,
            ];

            if ($type === 'nps') {
                $stat['nps'] = self::nps($rows);
            }

            if (in_array($type, self::TIPOS_ABIERTOS, true)) {
                $stat['abiertas'] = $abiertas[$fieldId] ?? [];
            }

            $stats[$fieldId] = $stat;
        }

        return $stats;
    }

    /**
     * W2 — Los tipos que la analítica enseñaba como «No gráfica para texto
     * libre» (y cinco que ni siquiera eso: caían fuera de TODAS las ramas de la
     * vista y desaparecían sin más).
     *
     * `file` entra aquí porque su valor es texto legible para la vista (JSON de
     * adjuntos, que ella traduce con `Uploader::displayValue()`), y un listado
     * de archivos entregados también es una respuesta que se puede mirar.
     *
     * No incluye `section`/`page` (no guardan respuestas) ni ningún tipo con
     * agregado propio: `values` ya le dice a la vista qué pintar y una tarjeta
     * de más taparía la que sí informa.
     */
    public const TIPOS_ABIERTOS = [
        'text', 'textarea', 'email', 'url', 'tel', 'time', 'datetime-local', 'date', 'file',
    ];

    /** Cuántas respuestas recientes por campo caben en su tarjeta. */
    public const ABIERTAS_MAX = 5;

    /**
     * Fase 8: los TEchos de la comparación por atributo.
     *
     * Son tres números y están juntos porque los tres responde a la misma
     * pregunta («¿qué pasa con un atributo libre?»): un `.xlsx` con 800 sedes
     * distintas no puede traer 800 columnas.
     */
    public const GRUPOS_MAX = 8;
    public const FILAS_MAX  = 20;
    public const TOP_VALORES = 5;

    /** Un grupo del que no se teje su nombre: el cubo de «todo lo demás». */
    public const CLAVE_RESTO = '';

    /**
     * Fase 8 — Las columnas de la comparación: los `GRUPOS_MAX` valores más
     * frecuentes del atributo y un cubo «Resto» con lo que queda.
     *
     * El cubo se calcula con la **resta** del total menos lo que ocupa cada
     * columna, y por eso `valoresConConteo()` necesita traer el total entero (sin
     * `LIMIT`): con solo los 200 valores del desplegable, un atributo de 800
     * daría un resto negativo o, peor, un resto que en realidad es la cola del
     * `LIMIT` y no del atributo.
     *
     * Y **«Resto» no se pinta si no queda nadie**: con 8 valores exactos la resta
     * da 0, y una columna en cero diría que hay gente sin el atributo.
     */
    public static function gruposDeAtributo(int $formId, string $keyName): array
    {
        $conteo = FormParticipant::valoresConConteo($formId, $keyName);

        $grupos = [];
        $enColumnas = 0;
        foreach ($conteo['valores'] as $valor => $n) {
            if (count($grupos) >= self::GRUPOS_MAX) {
                break;
            }
            $grupos[] = ['clave' => (string) $valor, 'label' => (string) $valor, 'n' => $n];
            $enColumnas += $n;
        }

        $resto = $conteo['total'] - $enColumnas;
        if ($resto > 0) {
            $grupos[] = [
                'clave' => self::CLAVE_RESTO,
                'label' => 'Resto',
                'n'     => $resto,
                'nota'  => 'Los ' . $resto . ' valores menos frecuentes, agrupados.',
            ];
        }

        return $grupos;
    }

    /**
     * Fase 8 — Una fila por pregunta comparable, con una celda por grupo.
     *
     * Sigue el mismo principio que `forForm()`: **DOS consultas fijas** para todo
     * el formulario (`numericStatsByGroup()` y `topValueCountsByGroup()`) y el
     * resto de la aritmética en PHP. Una consulta por grupo y otra por pregunta
     * serían 9 × 20 = 180 SELECTs con la misma forma de filtro, que es
     * exactamente el problema que este servicio existe para no tener.
     *
     * Las preguntas se eligen ANTES de consultar, y solo se consultan las que se
     * van a pintar (las `FILAS_MAX` primeras): una lista de 200 preguntas no
     * puede convertir dos consultas fijas en dos consultas de 200 ids.
     *
     * **Qué preguntas son comparables y por qué el resto no aparece**:
     *
     *  - numéricas (`number`, `scale`, `range`, `star`): promedio y cuántas
     *    respuestas hay en cada grupo.
     *  - `nps`: además el NPS y sus tres cubos.
     *  - `select`/`radio`: el valor más repetido y con qué proporción.
     *  - `checkbox` **no**: lo que se guarda es la combinación entera en JSON
     *    (`["A","B"]`), así que «el valor más repetido» sería la combinación y no
     *    la respuesta a «qué eligen más». Se podría descifrar en PHP, pero el
     *    recorte por combinaciones (que es lo que se trae) perdería la cuenta de
     *    las opciones que solo salen en combinaciones minoritarias, y un
     *    porcentaje de esas sale mal. Se queda fuera de la comparación y sigue
     *    estando en su gráfico de arriba, que sí lo hace bien.
     *  - `matrix`/`ranking`: el valor es un JSON con toda la respuesta, por lo
     *    mismo. Un «valor más repetido» sería literalmente la respuesta entera.
     *
     * Y los campos marcados como PII **no se consultan** si quien mira no tiene el
     * permiso: es el mismo criterio que el resto de la analítica (una lista de
     * tipos vacía = puede verlos), aplicado aquí para que la comparación no se
     * convierta en el canal limpio que el resto de la pantalla tapa.
     *
     * @param array $grupos    columnas, de `gruposDeAtributo()`
     * @param array $piiTypes  field_id => tipo de los campos enmascarados (F1)
     *
     * @return array{key: string, grupos: array, filas: array}|array vazío
     */
    public static function forFormByGroup(
        int $formId,
        array $fields,
        array $grupos,
        string $keyName,
        string $from = '',
        string $to = '',
        array $piiTypes = [],
        ?array $atr = null
    ): array {
        if ($grupos === [] || trim($keyName) === '') {
            return [];
        }

        // Las filas se eligen primero (y solo las comparables), porque son las que
        // van a la consulta.
        $numericos = [];
        $opciones  = [];
        $elegidas  = [];
        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? '');
            if (!self::esComparable($type) || isset($piiTypes[(int) $field['id']])) {
                continue;
            }
            $elegidas[] = $field;
            if ($type === 'select' || $type === 'radio') {
                $opciones[] = (int) $field['id'];
            } else {
                $numericos[] = (int) $field['id'];
            }
            if (count($elegidas) >= self::FILAS_MAX) {
                break;
            }
        }

        if ($elegidas === []) {
            return [];
        }

        $claves = array_column($grupos, 'clave');
        $stats  = Answer::numericStatsByGroup($formId, $numericos, $keyName, $claves, $from, $to, $atr);
        $tops   = Answer::topValueCountsByGroup($formId, $opciones, $keyName, $claves, $from, $to, $atr, self::TOP_VALORES);

        $filas = [];
        foreach ($elegidas as $field) {
            $fieldId = (int) $field['id'];
            $type = (string) $field['type'];
            $celdas = [];
            foreach ($grupos as $grupo) {
                $celdas[] = ($type === 'select' || $type === 'radio')
                    ? self::celdaOpcion($tops[$fieldId][$grupo['clave']] ?? null)
                    : self::celdaNumerica($stats[$fieldId][$grupo['clave']] ?? null, $type === 'nps');
            }
            $filas[] = ['field' => $field, 'tipo' => $type, 'celdas' => $celdas];
        }

        return ['key' => $keyName, 'grupos' => $grupos, 'filas' => $filas];
    }

    /** ¿El tipo tiene una métrica que tenga sentido comparar entre grupos? */
    private static function esComparable(string $type): bool
    {
        return in_array($type, ['number', 'scale', 'range', 'star', 'nps', 'select', 'radio'], true);
    }

    /**
     * Celda numérica: el promedio y cuántas respuestas lo llenaron.
     *
     * El promedio sale de la `SUM`/`COUNT` de SQL, no de promediar promedios: las
     * celdas tienen `n` distintos y la media de las medias no es la media (con un
     * grupo de 2 personas y otro de 200, sale otra cosa).
     *
     * Sin respuestas no hay celda: `null` y la vista pinta «—». Un 0,00 sería un
     * promedio inventado.
     */
    private static function celdaNumerica(?array $stat, bool $esNps): array
    {
        if ($stat === null || $stat['n'] === 0) {
            return ['n' => 0, 'avg' => null, 'nps' => null, 'prom' => 0, 'pasi' => 0, 'det' => 0];
        }

        $celda = [
            'n'   => $stat['n'],
            'avg' => round($stat['suma'] / $stat['n'], 2),
            'nps' => null,
            'prom' => $stat['prom'],
            'pasi' => $stat['pasi'],
            'det'  => $stat['det'],
        ];

        if ($esNps) {
            $celda['nps'] = (int) round((($stat['prom'] - $stat['det']) / $stat['n']) * 100);
        }

        return $celda;
    }

    /**
     * Celda de opción única: los valores más repetidos y qué proporción de las
     * respuestas del grupo son.
     *
     * El denominador es el `total` de la ventana, que es el de TODAS las respuestas
     * del grupo, no el de las que han sobrevivido al `TOP_VALORES`. Por eso en un
     * grupo con 12 respuestas y 6 valores distintos sale «2 de 12 (17 %)» en vez
     * de «2 de 5 (40 %)»: el segundo número está inflado y no se puede
     * comparar con el de al lado.
     */
    private static function celdaOpcion(?array $top): array
    {
        if ($top === null || $top['total'] === 0) {
            return ['total' => 0, 'items' => []];
        }

        $items = [];
        foreach ($top['valores'] as $v) {
            $items[] = [
                'label' => (string) $v['value'],
                'c'     => $v['c'],
                'pct'   => (int) round(($v['c'] / $top['total']) * 100),
            ];
        }

        return ['total' => $top['total'], 'items' => $items];
    }

    /**
     * F1 — Enmascara las filas de un campo marcado y **fusiona** las que quedan
     * con el mismo marcador, sumando su `c`.
     *
     * La fusión no es un detalle: sin ella, 30 correos distintos darían 30
     * barras `[oculto]` con un 1 cada una, que es peor que no hacer nada porque
     * parece que hay una respuesta por barra.
     *
     * Qué pasa después con cada tipo, y por qué no hay que inventar nada:
     *
     *  - `conteoSimple` / `conteoCheckbox`: sale un único `[oculto]` con el
     *    total de respuestas. Es información útil y no dice quién.
     *  - `numerico` / `nps`: filtran los valores con una regex numérica, así que
     *    `[oculto]` no entra y el agregado sale a cero. **Honesto**: promedios y
     *    NPS a `null` en vez de un promedio inventado sobre datos que no se pueden
     *    mirar.
     *  - `matriz` / `ranking`: buscan la etiqueta en las opciones del campo, así
     *    que una fila o columna que solo tuviera respuestas personales
     *    desaparece en vez de enseñar el dato.
     */
    private static function enmascararValores(array $values, array $piiTypes): array
    {
        foreach ($piiTypes as $fieldId => $type) {
            $rows = $values[(int) $fieldId] ?? null;
            if ($rows === null || $rows === []) {
                continue;
            }

            $merged = [];
            foreach ($rows as $row) {
                $value = (string) $row['value'];
                $masked = Pii::maskValue(
                    \App\Services\Uploader::isAttachmentJson($value) ? 'file' : $value,
                    (string) $type
                );
                $merged[$masked] = ($merged[$masked] ?? 0) + (int) $row['c'];
            }

            $out = [];
            foreach ($merged as $masked => $c) {
                $out[] = ['value' => $masked, 'c' => $c];
            }
            $values[(int) $fieldId] = $out;
        }

        return $values;
    }

    /**
     * W2 — El enmascarado de las respuestas recientes de texto libre.
     *
     * El mismo criterio que `enmascararValores()`: **antes** de que la vista lo
     * pinte y sin permiso ningún valor crudo sale de aquí. Un párrafo de opinión
     * puede ser la cosa más personal del formulario (una denuncia, un caso
     * clínico), que es exactamente lo que `is_pii` existe para proteger.
     *
     * Aquí NO hay que fundir iguales: cada fila es una respuesta distinta con su
     * fecha y su enlace, y 5 `[oculto]` son 5 personas distintas.
     *
     * @return array<int, array<int, array{value: string, at: string, sid: int}>>
     */
    private static function enmascararAbiertas(array $abiertas, array $piiTypes): array
    {
        foreach ($piiTypes as $fieldId => $type) {
            $fid = (int) $fieldId;
            if (!isset($abiertas[$fid])) {
                continue;
            }
            foreach ($abiertas[$fid] as $i => $item) {
                $abiertas[$fid][$i]['value'] = Pii::maskValue((string) $item['value'], (string) $type);
            }
        }

        return $abiertas;
    }

    /**
     * El agregado que corresponde a cada tipo, con la misma forma que
     * devolvía el método por campo equivalente.
     *
     * @param array<int, array{value: mixed, c: int}> $rows
     */
    private static function valuesForType(string $type, array $rows, array $field): mixed
    {
        return match ($type) {
            'select', 'radio'       => self::conteoSimple($rows),
            'checkbox'              => self::conteoCheckbox($rows),
            'number', 'scale',
            'range', 'nps', 'star'  => self::numerico($rows),
            'matrix'                => self::matriz($rows, self::matrixRows($field), self::matrixCols($field)),
            'ranking'               => self::ranking($rows, self::plainOptions($field)),
            default                 => [],
        };
    }

    /**
     * select/radio: un conteo por valor, ya viene ordenado por frecuencia.
     * Es exactamente lo que devolvía `countByFieldInForm()`.
     */
    private static function conteoSimple(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['value' => $row['value'], 'c' => $row['c']];
        }
        return $out;
    }

    /**
     * checkbox: el valor guardado es un array JSON de opciones, así que una
     * respuesta puede sumar a varias. Se reparte el conteo de cada fila entre
     * sus opciones (multiplicado por `c`, que es el número de personas que
     * eligieron esa combinación exacta).
     */
    private static function conteoCheckbox(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['value'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $opt) {
                    $counts[(string) $opt] = ($counts[(string) $opt] ?? 0) + $row['c'];
                }
            } else {
                $counts[(string) $row['value']] = ($counts[(string) $row['value']] ?? 0) + $row['c'];
            }
        }

        $out = [];
        foreach ($counts as $value => $c) {
            $out[] = ['value' => $value, 'c' => $c];
        }
        return $out;
    }

    /**
     * number/scale/range/star: count, avg, min y max.
     *
     * El filtro de "es numérico" tiene que ser el MISMO que usaba el SQL
     * (`REGEXP '^-?[0-9]+(\\.[0-9]+)?$'`), porque un campo numérico también
     * puede tener respuestas vacías o texto y esas no entran al promedio. Sin
     * él, un campo con "N/A" en medio devolvería un avg corrupto.
     *
     * `min`/`max` se comparan en PHP con `<`/`>` sobre float, NO como cadenas:
     * `a.value` es TEXT, y comparar texto daría min=10 / max=9 para las
     * respuestas 10, 9 y 7 (es el bug que tenía `numberStatsInForm`, corregido
     * ahí con `MIN(a.value + 0)`). Se devuelven como float y la vista los
     * imprime con `View::escape()`, que castea a string y pinta "7"/"10"
     * igual que antes; `avg` también es float porque la vista le pasa
     * `number_format()`.
     */
    private static function numerico(array $rows): array
    {
        $sum = 0.0;
        $count = 0;
        $min = null;
        $max = null;

        foreach ($rows as $row) {
            $valor = (string) $row['value'];
            if (!preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $valor)) {
                continue;
            }

            $n = (float) $valor;
            $sum += $n * $row['c'];
            $count += $row['c'];

            if ($min === null || $n < $min) {
                $min = $n;
            }
            if ($max === null || $n > $max) {
                $max = $n;
            }
        }

        return [
            'answer_count' => $count,
            'avg_value'    => $count > 0 ? $sum / $count : null,
            'min_value'    => $min,
            'max_value'    => $max,
        ];
    }

    /**
     * NPS: promotores (9-10) menos detractores (0-6), sobre el total, en -100..100.
     * Se cuentan con `c` porque la consulta agrupa valores iguales.
     */
    private static function nps(array $rows): array
    {
        $total = 0;
        $promoters = 0;
        $passives = 0;
        $detractors = 0;

        foreach ($rows as $row) {
            $valor = (string) $row['value'];
            if (!preg_match('/^[0-9]+$/', $valor)) {
                continue;
            }

            $total += $row['c'];
            $n = (int) $valor;
            if ($n >= 9) {
                $promoters += $row['c'];
            } elseif ($n >= 7) {
                $passives += $row['c'];
            } else {
                $detractors += $row['c'];
            }
        }

        if ($total === 0) {
            return ['answer_count' => 0, 'promoters' => 0, 'passives' => 0, 'detractors' => 0, 'nps' => null];
        }

        return [
            'answer_count' => $total,
            'promoters'    => $promoters,
            'passives'     => $passives,
            'detractors'   => $detractors,
            'nps'          => (int) round((($promoters - $detractors) / $total) * 100),
        ];
    }

    /**
     * matrix / Likert: el valor es un objeto JSON {fila: columna}. Se cuentan
     * celdas por fila y por columna, ignorando las etiquetas que ya no
     * existen en las opciones del campo (alguien editó la matriz después de
     * recibir respuestas y la fila se quedó huérfana).
     */
    private static function matriz(array $rows, array $rowLabels, array $colLabels): array
    {
        $byRow = [];
        foreach ($rowLabels as $label) {
            $byRow[(string) $label] = ['_total' => 0];
            foreach ($colLabels as $col) {
                $byRow[(string) $label][(string) $col] = 0;
            }
        }
        $colCounts = [];
        foreach ($colLabels as $col) {
            $colCounts[(string) $col] = 0;
        }
        $total = 0;

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['value'], true);
            if (!is_array($decoded)) {
                continue;
            }
            $total += $row['c'];
            foreach ($decoded as $rowLabel => $col) {
                $rowLabel = (string) $rowLabel;
                $col = (string) $col;
                if ($col !== '' && isset($byRow[$rowLabel][$col])) {
                    $byRow[$rowLabel][$col] += $row['c'];
                    $byRow[$rowLabel]['_total'] += $row['c'];
                }
                if ($col !== '' && isset($colCounts[$col])) {
                    $colCounts[$col] += $row['c'];
                }
            }
        }

        return [
            'rows'      => $rowLabels,
            'cols'      => $colLabels,
            'matrix'    => $byRow,
            'colCounts' => $colCounts,
            'total'     => $total,
        ];
    }

    /**
     * ranking: promedia la posición (1..N) de cada opción entre quienes la
     * eligieron. Las opciones sin respuesta se quedan con avg null y c 0, que
     * es lo que la vista necesita para pintar "—".
     */
    private static function ranking(array $rows, array $options): array
    {
        $sums = [];
        $counts = [];
        foreach ($options as $option) {
            $sums[(string) $option] = 0;
            $counts[(string) $option] = 0;
        }

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['value'], true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $option => $rank) {
                if (isset($sums[(string) $option]) && is_numeric($rank)) {
                    $sums[(string) $option] += (float) $rank * $row['c'];
                    $counts[(string) $option] += $row['c'];
                }
            }
        }

        $out = [];
        foreach ($sums as $option => $sum) {
            $out[] = [
                'value' => $option,
                'avg'   => $counts[$option] > 0 ? round($sum / $counts[$option], 2) : null,
                'c'     => $counts[$option],
            ];
        }
        return $out;
    }

    /**
     * Filas de una matriz: las opciones del grupo 'rows'.
     */
    private static function matrixRows(array $field): array
    {
        return self::opcionesDelGrupo($field, 'rows');
    }

    /**
     * Columnas de una matriz: las opciones del grupo 'cols'.
     */
    private static function matrixCols(array $field): array
    {
        return self::opcionesDelGrupo($field, 'cols');
    }

    private static function opcionesDelGrupo(array $field, string $group): array
    {
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        return array_values(array_map(
            static fn($o) => (string) $o['value'],
            array_filter($options, static fn($o) => is_array($o) && ($o['group'] ?? '') === $group)
        ));
    }

    /**
     * Opciones de un campo sin agrupar (ranking).
     */
    private static function plainOptions(array $field): array
    {
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        return array_values(array_map(
            static fn($o) => (string) $o['value'],
            array_filter($options, static fn($o) => is_array($o))
        ));
    }

    /**
     * Días válidos para un filtro `from`/`to`.
     *
     * Vive aquí para que el controller y el agregador compartan la misma
     * validación: si uno acepta una fecha que el otro rechaza, la analítica y
     * el CSV exportan periodos distintos con el mismo filtro.
     */
    public static function validDay(string $day): string
    {
        $day = trim($day);
        return Submission::isValidDay($day) ? $day : '';
    }
}
