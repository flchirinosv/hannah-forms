<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Answer;
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
     * @return array<int, array{field: array, values: mixed, count: int, non_empty: int, nps?: array}>
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

            $stats[$fieldId] = $stat;
        }

        return $stats;
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
