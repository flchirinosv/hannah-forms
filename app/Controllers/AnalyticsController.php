<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Form;
use App\Models\Submission;
use App\Services\FieldAggregator;

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

        // Fase 3: respuestas por campo en DOS consultas fijas, no 2 por
        // pregunta. Antes este bucle hacía 2 SELECT por campo (3 si era NPS):
        // 16 con 11 preguntas, ~103 con 50, ~403 con 200. La forma del
        // resultado es idéntica, así que la vista no cambia.
        $campos = array_values(array_filter(
            $form['fields'],
            static fn($f) => ($f['type'] ?? '') !== 'section'
        ));
        $fieldStats = FieldAggregator::forForm(
            $formId,
            $campos,
            array_map(static fn($f) => (int) $f['id'], $campos),
            $total,
            (string) $from,
            (string) $to
        );

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
     * Un `from`/`to` solo se acepta si es un día real de verdad.
     *
     * Se delega en `FieldAggregator::validDay()` (y no en un
     * `createFromFormat` a pelo) por dos razones: `2026-02-31` no es una fecha
     * y `createFromFormat` la "corrige" a marzo sin avisar, y la validación
     * tiene que ser la MISMA que usa el agregador de la fase 3, o la analítica
     * y el CSV podrían recortar periodos distintos con el mismo filtro.
     */
    private static function validarFecha(string $fecha): string
    {
        return FieldAggregator::validDay($fecha);
    }
}