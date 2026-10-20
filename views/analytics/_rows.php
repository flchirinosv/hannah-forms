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
 *   $offset           primer índice de la página (1 en la primera, ver abajo)
 *   $formId           id del formulario, para el enlace «Ver» de la fila, opcional
 *   $piiTypes         field_id => tipo de los campos marcados (F1), opcional
 *   $atributos        columnas del catálogo del listado (fase 7), opcional
 *   $attrsByPid       participant_id => (key_name => valor), opcional
 */
use App\Core\View;
use App\Services\Pii;
use App\Services\Uploader;

if (empty($submissions)) {
    return;
}
$idx = (int) ($offset ?? 1);
$formId = (int) ($formId ?? 0);
$piiTypes = is_array($piiTypes ?? null) ? $piiTypes : [];
$atributos = is_array($atributos ?? null) ? $atributos : [];
$attrsByPid = is_array($attrsByPid ?? null) ? $attrsByPid : [];
foreach ($submissions as $submission) {
    $row = $answers[$submission['id']]['answers'] ?? [];
    $del = $attrsByPid[(int) ($submission['participant_id'] ?? 0)] ?? [];
    echo '<tr class="paginate-item">';
    echo '  <td class="text-muted" data-label="#">' . $idx++ . '</td>';
    // Fase 7 — Una celda por atributo del CATÁLOGO, con la cabecera que pone
    // `show.php`. Solo salen si hay filtro, así que una lista sin atributos deja la
    // tabla igual que estaba. Una celda vacía es «—» y no una celda en blanco: en un
    // `table-hover` un hueco vacío no se lee, y eldueño no distingue «no lo
    // rellenó» de «se olvidó la columna».
    foreach ($atributos as $atributo) {
        $valor = (string) ($del[(string) $atributo['key_name']] ?? '');
        echo '  <td data-label="' . View::escape($atributo['label']) . '">';
        echo $valor !== '' ? View::escape($valor) : '<span class="text-muted">—</span>';
        echo '  </td>';
    }
    foreach ($answerableFields as $field) {
        $fieldId = (int) $field['id'];
        echo '  <td class="app-answer-cell" data-label="' . View::escape($field['label']) . '">';
        $val = $row[$fieldId] ?? '';
        $esAdjunto = is_string($val) && Uploader::isAttachmentJson($val);

        // F1: el enmascarado va ANTES de reinterpretar el valor, porque
        // `[oculto]` empieza por `[` y el bloque de JSON de abajo lo trataría
        // como una lista (el mismo motivo por el que el CSV enmascara dentro
        // del bucle, después de decodificar). Un adjunto marcado se sustituye
        // por el marcador entero: el NOMBRE del archivo es el dato.
        if (isset($piiTypes[$fieldId])) {
            $val = $esAdjunto
                ? Pii::MASK
                : Pii::maskValue((string) $val, (string) $piiTypes[$fieldId]);
            $esAdjunto = false;
        }

        // `$renderedHtml` es el HTML ya escapado de los chips (checkbox, matriz,
        // ranking); `$val` sigue siendo el texto plano para todo lo demás. Se
        // mantiene la regla de siempre: un JSON VÁLIDO y no vacío se reformatea,
        // `[]`/`{}` queda vacío y un valor que solo EMPIEZA por `[`/`{` sin ser
        // JSON (el marcador `[oculto]`, que se enmascara arriba) se deja intacto.
        $renderedHtml = null;
        if ($esAdjunto) {
            $val = Uploader::displayValue($val);
        } elseif (is_string($val) && str_starts_with($val, '[')) {
            // Lista (checkbox): un chip por valor elegido. Antes salía una sola
            // línea «a; b; c», que en una tabla ancha no se lee.
            $decoded = json_decode($val, true);
            if (is_array($decoded) && array_is_list($decoded)) {
                $chips = '';
                foreach ($decoded as $item) {
                    $chips .= '<span class="vf-chip">' . View::escape((string) $item) . '</span>';
                }
                $renderedHtml = $chips !== '' ? '<span class="vf-answer-chips">' . $chips . '</span>' : '';
            }
        } elseif (is_string($val) && str_starts_with($val, '{')) {
            // Mapa (matriz/ranking): etiqueta => valor. En ranking el valor es la
            // posición (1..N), así que se ordena por ella y se pinta el puesto.
            $decoded = json_decode($val, true);
            if (is_array($decoded)) {
                $filas = '';
                if ((string) $field['type'] === 'ranking') {
                    uasort($decoded, static fn($a, $b) => (int) $a <=> (int) $b);
                    foreach ($decoded as $item => $pos) {
                        $filas .= '<span class="vf-chip-ranking"><span class="vf-chip-pos">' . View::escape((string) $pos) . '</span><span class="vf-chip-key">' . View::escape((string) $item) . '</span></span>';
                    }
                    $clase = 'vf-answer-ranking';
                } else {
                    foreach ($decoded as $k => $v) {
                        $filas .= '<span class="vf-chip-matrix"><span class="vf-chip-key">' . View::escape((string) $k) . '</span><span class="vf-chip-val">' . View::escape((string) $v) . '</span></span>';
                    }
                    $clase = 'vf-answer-matrix';
                }
                $renderedHtml = $filas !== '' ? '<span class="' . $clase . '">' . $filas . '</span>' : '';
            }
        }
        if ($renderedHtml !== null) {
            echo $renderedHtml !== '' ? $renderedHtml : '<span class="text-muted">—</span>';
        } else {
            echo $val !== '' ? View::escape((string) $val) : '<span class="text-muted">—</span>';
        }
        echo '  </td>';
    }
    echo '  <td class="small text-muted" data-label="Fecha">' . View::datetime($submission['finished_at']) . '</td>';
    // W1 — El drill-down que el listado de respuestas ya tenía. Se enlaza
    // SIEMPRE: la PII no se resuelve aquí, la resuelve el destino con sus
    // propios guards y `Pii::canView()`, y un enlace con el mismo contenido
    // que la fila no filtra nada. Sin `$formId` (los tests que pintan este
    // partial a mano) no se pinta, para no emitir una ruta a medio construir.
    if ($formId > 0) {
        $ver = '/forms/' . $formId . '/responses/' . (int) $submission['id'];
        echo '  <td data-label="Ver"><a href="' . View::escape($ver) . '" class="btn btn-sm btn-outline-secondary" title="Ver la respuesta completa"><i class="fa-solid fa-eye me-1"></i>Ver</a></td>';
    }
    echo '</tr>';
}