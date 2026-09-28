<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;

class Answer
{
    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    /**
     * Fase 3: conteo de respuestas NO vacías de varios campos en UNA consulta.
     *
     * Antes `AnalyticsController` llamaba a `nonEmptyInForm()` una vez por
     * pregunta, así que una visita costaba 2 consultas por campo. Aquí se
     * agrupa por `field_id` y se devuelve el mapa `[fieldId => int]`.
     *
     * No se trae `a.value`: los campos de texto libre guardan párrafos enteros
     * y agrupar por un TEXT de ese tamaño fuerza una tabla temporal enorme
     * para un dato que aquí no se usa.
     *
     * @param int[] $fieldIds
     * @return array<int, int> `[fieldId => respuestas no vacías]`
     */
    public static function nonEmptyCountsInForm(int $formId, array $fieldIds, string $from = '', string $to = ''): array
    {
        $ids = self::normalizarIds($fieldIds);
        if ($ids === []) {
            return [];
        }

        [$where, $params] = self::whereFormulario($formId, $from, $to);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($params, $ids);

        $rows = self::db()->fetchAll(
            "SELECT a.field_id, COUNT(*) AS c
             FROM answers a
             JOIN submissions s ON s.id = a.submission_id
             $where
               AND a.value IS NOT NULL AND a.value <> ''
               AND a.field_id IN ($ph)
             GROUP BY a.field_id",
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['field_id']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Fase 3: distribución de valores de varios campos en UNA consulta.
     *
     * Devuelve `[fieldId => [['value' => mixed, 'c' => int], ...]]`, con las
     * filas de cada campo ordenadas por conteo descendente — el mismo orden que
     * daba `countByFieldInForm()` con su `ORDER BY c DESC`.
     *
     * Agrupar por `(field_id, value)` en vez de traer cada fila sirve para las
     * dos cosas a la vez: comprime los duplicados (10 000 respuestas de "Sí"
     * son una fila) y cada agregación posterior multiplica por `c` en lugar de
     * contar fila a fila. Es la consulta que sustituye a
     * `countByFieldInForm`, `numberStatsInForm`, `matrixStatsInForm`,
     * `rankingStatsInForm` y `npsStatsInForm` en la analítica.
     *
     * @param int[] $fieldIds
     * @return array<int, array<int, array{value: mixed, c: int}>>
     */
    public static function valueCountsInForm(int $formId, array $fieldIds, string $from = '', string $to = ''): array
    {
        $ids = self::normalizarIds($fieldIds);
        if ($ids === []) {
            return [];
        }

        [$where, $params] = self::whereFormulario($formId, $from, $to);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($params, $ids);

        $rows = self::db()->fetchAll(
            "SELECT a.field_id, a.value, COUNT(*) AS c
             FROM answers a
             JOIN submissions s ON s.id = a.submission_id
             $where
               AND a.field_id IN ($ph)
             GROUP BY a.field_id, a.value
             ORDER BY a.field_id, c DESC",
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['field_id']][] = [
                'value' => $row['value'],
                'c'     => (int) $row['c'],
            ];
        }
        return $out;
    }

    /**
     * Ids enteros, únicos y positivos. Cierra el `IN ()` vacío (que es error de
     * sintaxis en MariaDB) y evita loteos por debajo de 1.
     *
     * @return int[]
     */
    private static function normalizarIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    /**
     * Filtro de formulario + fechas para las consultas de `answers` unidas a
     * `submissions`, con los MISMOS límites que usa `Submission::dateBounds()`.
     *
     * OJO: los límites salen de `Clock::dayStartUtc()`/`dayEndUtc()`, no de
     * concatenar `' 00:00:00'`. Los TIMESTAMP viven en UTC y el filtro `from`/
     * `to` es un día de la zona de quien mira: para Caracas (UTC-4) el día
     * empieza a las 04:00 UTC, así que con el patrón ingenuo las respuestas
     * mandadas de 00:00 a 04:00 UTC (la NOCHE del día anterior en Caracas)
     * caían en el día equivocado. Además el final es EXCLUSIVO (`<`), que es
     * lo que permite escribir `>= desde AND < hasta` sin bordes que dominar.
     *
     * El día se valida con `Submission::isValidDay()`: `2026-02-31` no es una
     * fecha y `createFromFormat` la "corrige" a marzo sin avisar.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function whereFormulario(int $formId, string $from, string $to): array
    {
        $where = 'WHERE s.form_id = ?';
        $params = [$formId];

        if (Submission::isValidDay($from)) {
            $where .= ' AND s.finished_at >= ?';
            $params[] = Clock::dayStartUtc(trim($from));
        }
        if (Submission::isValidDay($to)) {
            $where .= ' AND s.finished_at < ?';
            $params[] = Clock::dayEndUtc(trim($to));
        }

        return [$where, $params];
    }

    public static function create(int $submissionId, int $fieldId, string $value): void
    {
        self::db()->execute(
            'INSERT INTO answers (submission_id, field_id, value) VALUES (:sid, :fid, :value)',
            ['sid' => $submissionId, 'fid' => $fieldId, 'value' => $value]
        );
    }

    /**
     * Representación legible de un valor de respuesta para vistas de detalle:
     * arrays JSON -> "a; b" y objetos JSON -> "clave: valor; ...". Si no es
     * JSON compuesto devuelve el texto crudo.
     */
    public static function displayValue(string $value): string
    {
        // P10: los adjuntos se muestran como "nombre.pdf (1,2 MB)", nunca como
        // JSON crudo (ni con la ruta interna de storage).
        if (\App\Services\Uploader::isAttachmentJson($value)) {
            return \App\Services\Uploader::displayValue($value);
        }

        $trimmed = ltrim($value);
        if ($trimmed === '' || ($trimmed[0] !== '[' && $trimmed[0] !== '{')) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded) || $decoded === []) {
            return $value;
        }

        if (array_is_list($decoded)) {
            return implode('; ', $decoded);
        }

        $parts = [];
        foreach ($decoded as $key => $val) {
            $parts[] = $key . ': ' . $val;
        }
        return implode('; ', $parts);
    }

    public static function bySubmission(int $submissionId): array
    {
        return self::db()->fetchAll(
            'SELECT a.field_id, a.value FROM answers a WHERE a.submission_id = :sid',
            ['sid' => $submissionId]
        );
    }

    /**
     * P10: detalle de una respuesta (para descarga autorizada): trae la fila
     * de answers + la submission + el form dueño, de modo que el controller
     * pueda validar acceso en una sola consulta.
     */
    public static function findWithContext(int $answerId): ?array
    {
        $row = self::db()->fetch(
            'SELECT a.id AS answer_id, a.field_id, a.value,
                    s.id AS submission_id, s.form_id,
                    f.user_id, f.slug, f.title
             FROM answers a
             INNER JOIN submissions s ON s.id = a.submission_id
             INNER JOIN forms f ON f.id = s.form_id
             WHERE a.id = :id',
            ['id' => $answerId]
        );
        return $row === null ? null : $row;
    }

    /**
     * P10: adjuntos de una submission, aplanados y con el índice de descarga:
     * [['answer_id','field_id','index','name','size','mime','stored']].
     */
    public static function attachmentsBySubmission(int $submissionId): array
    {
        $rows = self::db()->fetchAll(
            'SELECT id, field_id, value FROM answers
             WHERE submission_id = :sid AND value LIKE :like
             ORDER BY id',
            ['sid' => $submissionId, 'like' => '%"stored"%']
        );

        $out = [];
        foreach ($rows as $r) {
            $metas = \App\Services\Uploader::decode((string) $r['value']);
            if ($metas === null) {
                continue;
            }
            foreach ($metas as $i => $meta) {
                $out[] = [
                    'answer_id' => (int) $r['id'],
                    'field_id'  => (int) $r['field_id'],
                    'index'     => $i,
                    'name'      => (string) ($meta['name'] ?? 'archivo'),
                    'size'      => (int) ($meta['size'] ?? 0),
                    'mime'      => (string) ($meta['mime'] ?? ''),
                    'stored'    => (string) ($meta['stored'] ?? ''),
                    // La vista previa solo existe si se generó el webp en la
                    // subida (imagenes rasterizadas). El download usa el MISMO
                    // criterio en `meta['thumb']`, así que nunca pintamos un
                    // <img> que vaya a responder 404.
                    'has_thumb' => !empty($meta['thumb']),
                ];
            }
        }
        return $out;
    }

    /**
     * P10: valores de respuesta que SON adjuntos (JSON con la clave `stored`).
     * Se usa antes de borrar (form/submission/field) para purgar los archivos
     * del disco: los ON DELETE CASCADE de la BD no tocan el filesystem.
     */
    public static function attachmentValuesByField(int $fieldId): array
    {
        return self::db()->fetchAll(
            "SELECT value FROM answers
             WHERE field_id = :fid AND value LIKE '%\"stored\"%'",
            ['fid' => $fieldId]
        );
    }

    /**
     * Conteo de respuestas agrupadas por valor para un campo (select/radio).
     */
    public static function countByFieldInForm(int $formId, int $fieldId, string $from = '', string $to = ''): array
    {
        return self::queryField(
            $formId,
            $fieldId,
            $from,
            $to,
            'a.value, COUNT(*) AS c',
            'GROUP BY a.value ORDER BY c DESC',
            true
        );
    }

    /**
     * Conteo de respuestas descompuestas para checkbox (valores JSON).
     * Devuelve un array de ['value' => ..., 'c' => ...].
     */
    public static function countsCheckboxInForm(int $formId, int $fieldId, string $from = '', string $to = ''): array
    {
        $rows = self::queryField(
            $formId,
            $fieldId,
            $from,
            $to,
            'a.value, COUNT(*) AS c',
            'GROUP BY a.value ORDER BY c DESC',
            true
        );

        $counts = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row['value'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $opt) {
                    $counts[(string) $opt] = ($counts[(string) $opt] ?? 0) + (int) $row['c'];
                }
            } else {
                $counts[(string) $row['value']] = ($counts[(string) $row['value']] ?? 0) + (int) $row['c'];
            }
        }

        $result = [];
        foreach ($counts as $value => $c) {
            $result[] = ['value' => $value, 'c' => $c];
        }
        return $result;
    }

    /**
     * Estadísticas numéricas (count, avg, min, max) para un campo numérico.
     *
     * OJO: `a.value` es TEXT, así que `MIN(a.value)`/`MAX(a.value)` comparan
     * como CADENA y dan la respuesta equivocada: con 10, 9 y 7 devolvían
     * min=10 y max=9. Hay que castear a número (equivalente a lo que hace la
     * agregación de la fase 3 en PHP).
     */
    public static function numberStatsInForm(int $formId, int $fieldId, string $from = '', string $to = ''): ?array
    {
        $conds = " AND a.value REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'";
        return self::queryField(
            $formId,
            $fieldId,
            $from,
            $to,
            'COUNT(*) AS answer_count, AVG(a.value) AS avg_value, MIN(a.value + 0) AS min_value, MAX(a.value + 0) AS max_value',
            $conds,
            false
        );
    }

    /**
     * Conteo de respuestas no vacías para un campo.
     */
    public static function nonEmptyInForm(int $formId, int $fieldId, string $from = '', string $to = ''): int
    {
        $conds = " AND a.value IS NOT NULL AND a.value <> ''";
        $row = self::queryField($formId, $fieldId, $from, $to, 'COUNT(*) AS c', $conds, false);
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Matriz / Likert: descompone las respuestas JSON ({fila: columna}) en
     * conteos por fila y columna.
     *
     * Devuelve:
     *   rows      => listado de filas (etiquetas)
     *   cols      => listado de columnas
     *   matrix    => [fila => [col => c, '_total' => c]]
     *   colCounts => [col => c]
     *   total     => respuestas que contestaron la matriz
     */
    public static function matrixStatsInForm(int $formId, int $fieldId, array $rows, array $cols, string $from = '', string $to = ''): array
    {
        $rows = array_values($rows);
        $cols = array_values($cols);

        $byRow = [];
        foreach ($rows as $r) {
            $key = (string) $r;
            $byRow[$key] = ['_total' => 0];
            foreach ($cols as $c) {
                $byRow[$key][(string) $c] = 0;
            }
        }
        $colCounts = [];
        foreach ($cols as $c) {
            $colCounts[(string) $c] = 0;
        }
        $total = 0;

        $raw = self::queryField($formId, $fieldId, $from, $to, 'a.value', " AND a.value IS NOT NULL AND a.value <> ''", true);
        foreach ($raw as $row) {
            $decoded = json_decode($row['value'], true);
            if (!is_array($decoded)) {
                continue;
            }
            $total++;
            foreach ($decoded as $rowLabel => $col) {
                $rowLabel = (string) $rowLabel;
                $col = (string) $col;
                if ($col !== '' && isset($byRow[$rowLabel][$col])) {
                    $byRow[$rowLabel][$col]++;
                    $byRow[$rowLabel]['_total']++;
                }
                if ($col !== '' && isset($colCounts[$col])) {
                    $colCounts[$col]++;
                }
            }
        }

        return [
            'rows' => $rows,
            'cols' => $cols,
            'matrix' => $byRow,
            'colCounts' => $colCounts,
            'total' => $total,
        ];
    }

    /**
     * Ranking: promedia el orden (1..N) de cada opción entre las respuestas
     * JSON ({opción: orden}). Devuelve [['value' => ..., 'avg' => ?float, 'c' => int]].
     */
    public static function rankingStatsInForm(int $formId, int $fieldId, array $options, string $from = '', string $to = ''): array
    {
        $sum = [];
        $counts = [];
        foreach ($options as $o) {
            $sum[(string) $o] = 0;
            $counts[(string) $o] = 0;
        }

        $raw = self::queryField($formId, $fieldId, $from, $to, 'a.value', " AND a.value IS NOT NULL AND a.value <> ''", true);
        foreach ($raw as $row) {
            $decoded = json_decode($row['value'], true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $opt => $rank) {
                if (isset($sum[(string) $opt]) && is_numeric($rank)) {
                    $sum[(string) $opt] += (float) $rank;
                    $counts[(string) $opt]++;
                }
            }
        }

        $result = [];
        foreach ($sum as $opt => $s) {
            $result[] = [
                'value' => $opt,
                'avg' => $counts[$opt] > 0 ? round($s / $counts[$opt], 2) : null,
                'c' => $counts[$opt],
            ];
        }
        return $result;
    }

    /**
     * Score NPS (Net Promoter Score): promotores (9-10) menos detractores
     * (0-6), sobre el total, en escala de -100 a 100.
     */
    public static function npsStatsInForm(int $formId, int $fieldId, string $from = '', string $to = ''): array
    {
        $conds = " AND a.value REGEXP '^[0-9]+$'";
        $row = self::queryField($formId, $fieldId, $from, $to, 'COUNT(*) AS c', $conds, false);
        $total = (int) ($row['c'] ?? 0);
        if ($total === 0) {
            return ['answer_count' => 0, 'promoters' => 0, 'passives' => 0, 'detractors' => 0, 'nps' => null];
        }

        $raw = self::queryField($formId, $fieldId, $from, $to, 'a.value', $conds, true);
        $promoters = 0;
        $passives = 0;
        $detractors = 0;
        foreach ($raw as $r) {
            $n = (int) $r['value'];
            if ($n >= 9) {
                $promoters++;
            } elseif ($n >= 7) {
                $passives++;
            } else {
                $detractors++;
            }
        }

        return [
            'answer_count' => $total,
            'promoters' => $promoters,
            'passives' => $passives,
            'detractors' => $detractors,
            'nps' => (int) round((($promoters - $detractors) / $total) * 100),
        ];
    }

    /**
     * Helper que construye una consulta de answers unida a submissions
     * con el filtro de formulario y fechas (los métodos por campo).
     *
     * @param string $select columnas a seleccionar
     * @param string $extra  condiciones SQL adicionales y/o agrupación
     * @param bool   $many   true para fetchAll, false para fetch (una fila)
     * @return array|null|array[]
     */
    private static function queryField(int $formId, int $fieldId, string $from, string $to, string $select, string $extra, bool $many)
    {
        // Fase 3: los límites de día salen de Clock (UTC/local), no de
        // concatenar ' 00:00:00' / ' 23:59:59'. Ver whereFormulario().
        [$where, $params] = self::whereFormulario($formId, $from, $to);
        $where .= ' AND a.field_id = ?';
        $params[] = $fieldId;

        $sql = "SELECT $select
                FROM answers a
                JOIN submissions s ON s.id = a.submission_id
                $where $extra";

        return $many
            ? self::db()->fetchAll($sql, $params)
            : self::db()->fetch($sql, $params);
    }
}