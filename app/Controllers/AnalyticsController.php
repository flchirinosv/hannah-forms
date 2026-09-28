<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Answer;
use App\Models\Form;
use App\Models\Submission;

class AnalyticsController
{
    public function show(int $id): void
    {
        $form = Form::find($id);

        if ($form === null) {
            Response::notFound();
        }
        if (!$this->canAccess((int) $form['user_id'])) {
            Response::forbidden();
        }

        $from = Request::query('from', '');
        $to = Request::query('to', '');

        $from = self::validarFecha($from);
        $to = self::validarFecha($to);

        if ($from !== '' && $to !== '' && $from > $to) {
            $tmp = $from;
            $from = $to;
            $to = $tmp;
        }

        $formId = (int) $form['id'];

        // Migrado a Models (antes SQL crudo en el controller)
        $submissions = Submission::byFormFiltered($formId, (string) $from, (string) $to);
        $total = count($submissions);

        // Respuestas por campo (migrado a Models)
        $fieldStats = [];
        foreach ($form['fields'] as $field) {
            // Las secciones informativas no generan respuestas
            if (($field['type'] ?? '') === 'section') {
                continue;
            }
            $fieldId = (int) $field['id'];
            $values = match ($field['type']) {
                'select', 'radio' => Answer::countByFieldInForm($formId, $fieldId, (string) $from, (string) $to),
                'checkbox'        => Answer::countsCheckboxInForm($formId, $fieldId, (string) $from, (string) $to),
                'number', 'scale', 'range', 'nps', 'star' => Answer::numberStatsInForm($formId, $fieldId, (string) $from, (string) $to)
                    ?? ['answer_count' => 0, 'avg_value' => null, 'min_value' => null, 'max_value' => null],
                'matrix'          => Answer::matrixStatsInForm($formId, $fieldId, self::groupValues($field['options'] ?? [], 'rows'), self::groupValues($field['options'] ?? [], 'cols'), (string) $from, (string) $to),
                'ranking'         => Answer::rankingStatsInForm($formId, $fieldId, self::plainValues($field['options'] ?? []), (string) $from, (string) $to),
                default           => [],
            };
            $fieldStats[$fieldId] = [
                'field' => $field,
                'values' => $values,
                'count'  => $total,
                'non_empty' => Answer::nonEmptyInForm($formId, $fieldId, (string) $from, (string) $to),
            ];

            // NPS: score complementario (promotores/detractores) para la tarjeta.
            if (($field['type'] ?? '') === 'nps') {
                $fieldStats[$fieldId]['nps'] = Answer::npsStatsInForm($formId, $fieldId, (string) $from, (string) $to);
            }
        }

        // Progreso diario (migrado a Models)
        $daily = Submission::dailyStats($formId, (string) $from, (string) $to);

        // Respuestas de la tabla (N+1 resuelto: una sola consulta)
        $submissionIds = array_column($submissions, 'id');
        $answersBySubmission = Submission::withAnswersByIds($submissionIds);

        View::render('analytics/show', [
            'form'        => $form,
            'submissions' => $submissions,
            'answers'     => $answersBySubmission,
            'total'       => $total,
            'fieldStats'  => $fieldStats,
            'daily'       => $daily,
            'from'        => $from,
            'to'          => $to,
        ]);
    }

    public function exportCsv(int $id): void
    {
        $form = Form::find($id);
        if ($form === null) {
            Response::notFound();
        }
        if (!$this->canAccess((int) $form['user_id'])) {
            Response::forbidden();
        }

        // M5: aplicar el mismo filtro de fechas que en la pantalla de analítica
        $from = Request::query('from', '');
        $to = Request::query('to', '');
        $from = self::validarFecha($from);
        $to = self::validarFecha($to);

        // N+1 resuelto: cargar submissions + answers en una sola consulta
        $submissions = Submission::byFormFiltered((int) $form['id'], (string) $from, (string) $to);
        $submissionIds = array_column($submissions, 'id');
        $answersBySubmission = Submission::withAnswersByIds($submissionIds);

        $headers = ['#', 'Fecha'];
        $fieldById = [];
        foreach ($form['fields'] as $field) {
            if (($field['type'] ?? '') === 'section') {
                continue;
            }
            $headers[] = $field['label'];
            $fieldById[(int) $field['id']] = $field['label'];
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::safeFile($form['slug']) . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, $headers, ',', '"', '\\');

        foreach ($submissions as $i => $submission) {
            $byField = $answersBySubmission[(int) $submission['id']]['answers'] ?? [];

            $row = [$i + 1, $submission['finished_at']];
            foreach ($form['fields'] as $field) {
                if (($field['type'] ?? '') === 'section') {
                    continue;
                }
                $val = $byField[(int) $field['id']] ?? '';
                // P10: los adjuntos salen como "nombre (tamaño)", sin la ruta
                // interna de storage ni el JSON de metadata.
                if ($val !== '' && \App\Services\Uploader::isAttachmentJson((string) $val)) {
                    $val = \App\Services\Uploader::displayValue((string) $val);
                }
                // Decodificar JSON si es respuesta múltiple (checkbox) o
                // compuesta (matriz/ranking: {clave: valor}).
                if (str_starts_with($val, '[')) {
                    $decoded = json_decode($val, true);
                    if (is_array($decoded)) {
                        $val = implode('; ', $decoded);
                    }
                } elseif (str_starts_with($val, '{')) {
                    $decoded = json_decode($val, true);
                    if (is_array($decoded)) {
                        $parts = [];
                        foreach ($decoded as $k => $v) {
                            $parts[] = $k . ': ' . $v;
                        }
                        $val = implode('; ', $parts);
                    }
                }
                $row[] = self::csvSafe($val);
            }
            fputcsv($out, $row, ',', '"', '\\');
        }

        fclose($out);
        exit;
    }

    private static function safeFile(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }

    /**
     * Previene CSV injection (CWE-1236): antepone una comilla simple a
     * valores que podrían interpretarse como fórmula en Excel/Sheets
     * (=, +, @, - seguido de no-dígito).
     */
    private static function csvSafe(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $first = $value[0];
        if (in_array($first, ['=', '+', '@', "\t", "\r", "\n"], true)) {
            return "'" . $value;
        }
        if ($first === '-' && !preg_match('/^-[0-9.]+$/', $value)) {
            return "'" . $value;
        }

        return $value;
    }

    private function canAccess(int $ownerId): bool
    {
        return (int) Auth::id() === $ownerId || Auth::isAdmin() || Auth::role() === 'editor';
    }

    /**
     * Extrae los valores de las opciones con un grupo dado (matriz: rows/cols).
     */
    private static function groupValues(array $options, string $group): array
    {
        return array_values(array_map(
            static fn($o) => (string) $o['value'],
            array_filter($options, static fn($o) => is_array($o) && ($o['group'] ?? '') === $group)
        ));
    }

    /**
     * Extrae el valor de cada opción (ranking y genérico).
     */
    private static function plainValues(array $options): array
    {
        return array_values(array_map(static fn($o) => (string) $o['value'], $options));
    }

    private static function validarFecha(string $fecha): string
    {
        $fecha = trim($fecha);
        if ($fecha === '' || DateTime::createFromFormat('Y-m-d', $fecha) === false) {
            return '';
        }
        return $fecha;
    }
}