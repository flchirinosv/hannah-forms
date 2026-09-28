<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;

class Submission
{
    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    /**
     * Token de capacidad para la página pública de gracias (P8/quiz).
     *
     * El thanks es una URL PÚBLICA y sin sesión, así que el identificador que
     * viaja en ella no puede ser el AUTO_INCREMENT: con un entero correlativo
     * cualquiera recorre `?sid=1`, `?sid=2`… y lee las respuestas ajenas de ese
     * formulario. Son 128 bits de `random_bytes`, en hexadecimal: no se adivina
     * ni se fuerza por fuerza bruta.
     */
    public const TOKEN_LENGTH = 32;

    public static function newToken(): string
    {
        return bin2hex(random_bytes((int) (self::TOKEN_LENGTH / 2)));
    }

    /**
     * Un token solo se acepta si tiene la forma exacta que generamos; cualquier
     * otra cosa ni se consulta a la BD.
     */
    public static function isWellFormedToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{' . self::TOKEN_LENGTH . '}$/', $token) === 1;
    }

    public static function create(
        int $formId,
        string $ip = '',
        string $userAgent = '',
        ?string $publicToken = null
    ): int {
        if ($formId <= 0) {
            throw new \InvalidArgumentException('El ID del formulario debe ser mayor a 0.');
        }

        self::db()->execute(
            'INSERT INTO submissions (form_id, ip_address, user_agent, public_token)
             VALUES (:form_id, :ip, :ua, :token)',
            [
                'form_id' => $formId,
                'ip'      => $ip,
                'ua'      => $userAgent,
                'token'   => $publicToken ?? self::newToken(),
            ]
        );
        return self::db()->lastInsertId();
    }

    public static function byForm(int $formId): array
    {
        return self::db()->fetchAll(
            'SELECT * FROM submissions WHERE form_id = :form_id ORDER BY finished_at DESC',
            ['form_id' => $formId]
        );
    }

    public static function countByForm(int $formId): int
    {
        $row = self::db()->fetch('SELECT COUNT(*) AS c FROM submissions WHERE form_id = :form_id', ['form_id' => $formId]);
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Submissions de un formulario con paginación server-side (M2).
     *
     * @param int $formId id del formulario
     * @param int $page   página (>= 1, se clamp a la última si se excede)
     * @param int $perPage filas por página (1..100)
     * @return array{items: array, total: int, page: int, per_page: int, pages: int}
     */
    public static function byFormPaginated(int $formId, int $page = 1, int $perPage = 15): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $row = self::db()->fetch(
            'SELECT COUNT(*) AS c FROM submissions WHERE form_id = :form_id',
            ['form_id' => $formId]
        );
        $total = (int) ($row['c'] ?? 0);
        $pages = (int) max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        // LIMIT/OFFSET en ints calculados (PDO con prepares reales no liga
        // placeholders en la cláusula LIMIT; los valores ya vienen acotados).
        $limit = $perPage;
        $offset = ($page - 1) * $perPage;
        $items = self::db()->fetchAll(
            "SELECT * FROM submissions
             WHERE form_id = :form_id
             ORDER BY finished_at DESC, id DESC
             LIMIT $limit OFFSET $offset",
            ['form_id' => $formId]
        );

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $pages,
        ];
    }

    /**
     * Una submission con todas sus respuestas y metadata.
     * Devuelve null si no existe.
     */
    public static function findWithAnswers(int $id): ?array
    {
        $submission = self::db()->fetch('SELECT * FROM submissions WHERE id = :id', ['id' => $id]);
        if ($submission === null) {
            return null;
        }

        $rows = self::db()->fetchAll(
            'SELECT a.field_id, a.value FROM answers a WHERE a.submission_id = :sid ORDER BY a.id',
            ['sid' => (int) $id]
        );
        $answers = [];
        foreach ($rows as $r) {
            $answers[(int) $r['field_id']] = $r['value'];
        }

        return [
            'submission' => $submission,
            'answers'    => $answers,
        ];
    }

    /**
     * Igual que findWithAnswers(), pero localizada por el token de capacidad y
     * NO por el id (ver newToken()).
     *
     * Es la única vía para leer una respuesta desde fuera de la sesión del
     * dueño: elthanks público pide el token, nunca un id adivinable. La
     * comparación es en BD (índice único), no en PHP, para no filtrar por
     * tiempo la existencia de un token.
     */
    public static function findWithToken(string $token): ?array
    {
        if (!self::isWellFormedToken($token)) {
            return null;
        }

        $submission = self::db()->fetch(
            'SELECT * FROM submissions WHERE public_token = :token',
            ['token' => $token]
        );
        if ($submission === null) {
            return null;
        }

        return self::findWithAnswers((int) $submission['id']);
    }

    /**
     * Payload estructurado para la exportación JSON: metadata del form,
     * total y submissions con answers decodificadas (arrays/objetos).
     *
     * @param array $form         formulario (con 'fields')
     * @param array $bySubmission mapa de Submission::withAnswersByIds()
     * @param int   $total        cantidad total de submissions
     * @return array
     */
    public static function exportPayload(array $form, array $bySubmission, int $total): array
    {
        $fields = [];
        $fieldById = [];
        foreach ($form['fields'] as $field) {
            if (($field['type'] ?? '') === 'section') {
                continue;
            }
            $fields[] = [
                'id'    => (int) $field['id'],
                'type'  => (string) $field['type'],
                'label' => (string) $field['label'],
            ];
            $fieldById[(int) $field['id']] = true;
        }

        $submissions = [];
        foreach ($bySubmission as $sid => $row) {
            $answers = [];
            foreach ($row['answers'] as $fieldId => $value) {
                $answers[(int) $fieldId] = self::decodeValue((string) $value);
            }
            $submissions[] = [
                'id'          => (int) $sid,
                'finished_at' => (string) ($row['submission']['finished_at'] ?? ''),
                'ip_address'  => $row['submission']['ip_address'] ?? null,
                'user_agent'  => $row['submission']['user_agent'] ?? null,
                'answers'     => $answers,
            ];
        }

        return [
            'form' => [
                'id'     => (int) $form['id'],
                'title'  => (string) $form['title'],
                'slug'   => (string) $form['slug'],
                'status' => (string) $form['status'],
                'fields' => $fields,
            ],
            'total'       => $total,
            'submissions' => $submissions,
        ];
    }

    /**
     * Decodifica un valor de respuesta JSON compuesto; si no es JSON devuelve
     * el texto crudo. Los arrays vacíos se normalizan a ''.
     */
    private static function decodeValue(string $value)
    {
        // P10: los adjuntos se exportan como texto legible ("nombre (tamaño)");
        // la metadata interna (ruta de storage, sha) no sale del servidor.
        if (\App\Services\Uploader::isAttachmentJson($value)) {
            return \App\Services\Uploader::displayValue($value);
        }

        $trimmed = ltrim($value);
        if ($trimmed === '' || ($trimmed[0] !== '[' && $trimmed[0] !== '{')) {
            return $value;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return $value;
        }
        return $decoded === [] ? '' : $decoded;
    }

    public static function delete(int $id): void
    {
        self::db()->execute('DELETE FROM submissions WHERE id = :id', ['id' => $id]);
    }

    /**
     * Submissions de un formulario con filtro opcional por fecha.
     *
     * @param int         $formId
     * @param string      $from   fecha inicio en formato Y-m-d (vacío = sin límite)
     * @param string      $to     fecha fin en formato Y-m-d (vacío = sin límite)
     * @return array
     */
    public static function byFormFiltered(int $formId, string $from = '', string $to = ''): array
    {
        [$where, $params] = self::dateBounds($from, $to, 's.');

        return self::db()->fetchAll(
            "SELECT s.id, s.finished_at FROM submissions s WHERE s.form_id = :form_id$where ORDER BY s.finished_at DESC",
            $params + ['form_id' => $formId]
        );
    }

    /**
     * Total de respuestas agrupadas por día para un formulario (con filtro de fecha).
     *
     * Los buckets son DÍAS LOCALES, no días UTC: `DATE(s.finished_at)` agrupaba
     * por el día UTC, que para Caracas (UTC-4) empieza a las 20:00 del día
     * anterior. El resumen de "respuestas por día" no cuadraba con la fecha que
     * el dueño veía en la tabla de respuestas: las 4 h de la tarde iban al día
     * siguiente y las 4 h de la noche al anterior.
     *
     * El desplazamiento se calcula en PHP y se pasa a `CONVERT_TZ` con un
     * número, no con el nombre de la zona: el nombre exige que el servidor
     * tenga las tablas de zonas horarias cargadas y, si no las tiene, devuelve
     * NULL en silencio (agrupamiento vacío y ninguna pista de por qué). Ver
     * `Clock::localSqlOffsetFor()` y su limitación con los cambios de hora.
     */
    public static function dailyStats(int $formId, string $from = '', string $to = ''): array
    {
        [$where, $params] = self::dateBounds($from, $to, 's.');

        $desfase = Clock::localSqlOffsetFor();

        return self::db()->fetchAll(
            "SELECT DATE(CONVERT_TZ(s.finished_at, '+00:00', :desfase)) AS day, COUNT(*) AS c
             FROM submissions s WHERE s.form_id = :form_id$where
             GROUP BY day ORDER BY day",
            $params + ['form_id' => $formId, 'desfase' => $desfase]
        );
    }

    /**
     * Traduce un filtro de fechas de la INTERFAZ (días en la zona de
     * presentación) a la cláusula SQL que compara contra la columna TIMESTAMP.
     *
     * Lo que no se puede hacer es lo que se hacía: concatenar el texto
     * `$from . ' 00:00:00'` y compararlo contra la columna. MariaDB lo
     * interpretaría en la zona de la sesión (UTC), así que el filtro "28 de
     * septiembre" significaba el día en UTC y no el día del usuario: en Caracas
     * el día empieza a las 04:00 UTC, de modo que las respuestas de la noche
     * del día anterior (00:00-04:00 UTC) caían en el día equivocado y las de la
     * mañana del propio día (00:00-04:00 UTC del 28) se asignaban al 28 cuando
     * para quien las envió era todavía el 27. Cuatro horas de respuestas mal contabilizadas cada día.
     *
     * El límite superior es EXCLUSIVO (`<` del día siguiente a las 00:00) en vez
     * de `23:59:59`: no hay un segundo que se escape por el redondeo y las dos
     * condiciones que comparten los límites (el filtro y el agrupamiento) no
     * pueden discrepar en un instante.
     *
     * @param string $prefijo prefijo de tabla/alias de la columna ('s.' o '')
     * @return array{0: string, 1: array} [cláusula WHERE adicional, parámetros]
     */
    private static function dateBounds(string $from, string $to, string $prefijo): array
    {
        $where = '';
        $params = [];

        $col = $prefijo . 'finished_at';

        if (self::isValidDay($from)) {
            $where .= " AND {$col} >= :desde";
            $params['desde'] = Clock::dayStartUtc($from);
        }
        if (self::isValidDay($to)) {
            $where .= " AND {$col} < :hasta";
            $params['hasta'] = Clock::dayEndUtc($to);
        }

        return [$where, $params];
    }

    /**
     * Un `Y-m-d` real y dentro de un rango con sentido. `createFromFormat`
     * acepta cosas como `2026-02-31` (que PHP "corrige" a marzo) y acepta
     * cualquier junk con avisos, así que se compara el resultado con la entrada
     * en vez de confiar en que no fallen.
     */
    public static function isValidDay(string $day): bool
    {
        $day = trim($day);
        if ($day === '') {
            return false;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if ($dt === false) {
            return false;
        }
        $errores = \DateTimeImmutable::getLastErrors();
        if (is_array($errores) && ($errores['warning_count'] > 0 || $errores['error_count'] > 0)) {
            return false;
        }

        return $dt->format('Y-m-d') === $day;
    }

    /**
     * Submissions con sus metadatos y todas sus respuestas (resuelve N+1).
     * Devuelve un mapa: submission_id => ['submission' => [...], 'answers' => [field_id => value]].
     *
     * @param array $submissionIds lista de ids de submissions
     * @return array<int,array>
     */
    public static function withAnswersByIds(array $submissionIds): array
    {
        if (empty($submissionIds)) {
            return [];
        }
        $ids = array_map('intval', array_unique($submissionIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $submissions = self::db()->fetchAll(
            "SELECT id, finished_at, ip_address, user_agent
             FROM submissions WHERE id IN ($placeholders) ORDER BY finished_at DESC",
            $ids
        );

        // Cargar todas las answers de una sola consulta
        $answers = self::db()->fetchAll(
            "SELECT a.submission_id, a.field_id, a.value
             FROM answers a
             WHERE a.submission_id IN ($placeholders)",
            $ids
        );

        $bySubmission = [];
        foreach ($answers as $a) {
            $sid = (int) $a['submission_id'];
            $bySubmission[$sid][(int) $a['field_id']] = $a['value'];
        }

        $result = [];
        foreach ($submissions as $s) {
            $sid = (int) $s['id'];
            $result[$sid] = [
                'submission' => $s,
                'answers'    => $bySubmission[$sid] ?? [],
            ];
        }
        return $result;
    }

    /**
     * P10: valores de respuesta que son adjuntos de todas las submissions de un
     * formulario. Se consulta ANTES de borrar el form (cascade de BD) para
     * poder purgar los archivos del disco.
     */
    public static function attachmentValuesByForm(int $formId): array
    {
        return self::db()->fetchAll(
            "SELECT a.value
             FROM answers a
             INNER JOIN submissions s ON s.id = a.submission_id
             WHERE s.form_id = :fid AND a.value LIKE '%\"stored\"%'",
            ['fid' => $formId]
        );
    }
}