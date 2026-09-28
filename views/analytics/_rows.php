<?php
/**
 * P1 (2026-09-28) — Filas de la tabla de detalle de respuestas, paginadas en
 * el servidor. Uso doble: primera carga (render server-side en show.php) y
 * lazy-loading por fetch vía `GET /forms/{id}/analytics/rows`.
 *
 * Variables esperadas:
 *   $submissions      items de la página (Submission::byFormFilteredPaginated)
 *   $answers          answers por submission id (Submission::withAnswersByIds)
 *   $answerableFields campos respuesta en orden
 *   $offset           índice base para la columna «#» (server-side offset)
 */
use App\Core\View;
if (empty($submissions)) {
    return;
}
$idx = (int) ($offset ?? 1);
foreach ($submissions as $submission) {
    $row = $answers[$submission['id']]['answers'] ?? [];
    echo '<tr class="paginate-item">';
    echo '  <td class="text-muted" data-label="#">' . $idx++ . '</td>';
    foreach ($answerableFields as $field) {
        echo '  <td class="app-answer-cell" data-label="' . View::escape($field['label']) . '">';
        $val = $row[(int) $field['id']] ?? '';
        if (is_string($val) && \App\Services\Uploader::isAttachmentJson($val)) {
            $val = \App\Services\Uploader::displayValue($val);
        } elseif (is_string($val) && str_starts_with($val, '[')) {
            $decoded = json_decode($val, true);
            if (is_array($decoded) && $decoded !== []) {
                $val = implode('; ', array_map('strval', $decoded));
            } elseif ($val === '[]' || $val === '') {
                $val = '';
            }
        } elseif (is_string($val) && str_starts_with($val, '{')) {
            $decoded = json_decode($val, true);
            if (is_array($decoded) && $decoded !== []) {
                $parts = [];
                foreach ($decoded as $k => $v) {
                    $parts[] = $k . ': ' . $v;
                }
                $val = implode('; ', $parts);
            } else {
                $val = '';
            }
        }
        echo $val !== '' ? View::escape($val) : '<span class="text-muted">—</span>';
        echo '  </td>';
    }
    echo '  <td class="small text-muted" data-label="Fecha">' . View::datetime($submission['finished_at']) . '</td>';
    echo '</tr>';
}