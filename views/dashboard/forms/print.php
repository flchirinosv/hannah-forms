<?php
/**
 * Vista imprimible (P9) — maquetación de las preguntas para imprimir o
 * guardar como PDF desde el navegador. Se sirve con layout null (standalone).
 * NO incluye respuestas: solo la estructura del formulario.
 */
use App\Core\View;

/** @var array $form  fila del formulario con 'fields' (orden de lectura) */
/** @var array $settings */

$fields = (array) ($form['fields'] ?? []);

// Preguntas reales: secciones y páginas son elementos estructurales.
$questions = array_values(array_filter(
    $fields,
    static fn ($f) => !in_array((string) ($f['type'] ?? ''), ['section', 'page'], true)
));

$byId = [];
foreach ($fields as $f) {
    $byId[(int) ($f['id'] ?? 0)] = $f;
}

// Etiquetas de los tipos, para la columna "tipo" de la tabla.
$typeLabels = [
    'text' => 'Texto', 'textarea' => 'Texto largo', 'email' => 'Email', 'url' => 'URL',
    'tel' => 'Teléfono', 'number' => 'Número', 'date' => 'Fecha', 'time' => 'Hora',
    'datetime-local' => 'Fecha y hora', 'select' => 'Selección', 'radio' => 'Opción única',
    'checkbox' => 'Opción múltiple', 'scale' => 'Escala', 'range' => 'Rango',
    'matrix' => 'Matriz', 'nps' => 'NPS', 'star' => 'Estrellas', 'ranking' => 'Orden',
];

$optionText = static function ($f): string {
    $options = (array) ($f['options'] ?? []);
    if ($options === []) {
        return '—';
    }
    $values = [];
    foreach ($options as $o) {
        $value = is_array($o) ? (string) ($o['value'] ?? '') : (string) $o;
        if ($value !== '') {
            $values[] = $value;
        }
    }
    return $values === [] ? '—' : implode(' · ', $values);
};

$conditionText = static function (array $f) use ($byId): string {
    $logic = $f['logic'] ?? null;
    if (!is_array($logic) || empty($logic['rules']) || !is_array($logic['rules'])) {
        return '';
    }
    $opLabels = [
        'eq' => '=', 'neq' => '≠', 'contains' => 'contiene', 'not_contains' => 'no contiene',
        'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤',
        'filled' => 'tiene valor', 'empty' => 'está vacío', 'matches' => 'coincide con',
    ];
    $join = ($logic['operator'] ?? 'all') === 'any' ? ' o ' : ' y ';
    $parts = [];
    foreach ($logic['rules'] as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $src = $byId[(int) ($rule['field_id'] ?? 0)] ?? null;
        $op = $opLabels[$rule['op'] ?? ''] ?? (string) ($rule['op'] ?? '');
        $val = (string) ($rule['value'] ?? '');
        $parts[] = trim(($src['label'] ?? 'campo') . ' ' . $op . ($val !== '' && !in_array($rule['op'] ?? '', ['filled', 'empty'], true) ? ' "' . $val . '"' : ''));
    }
    return $parts === [] ? '' : 'Se muestra si: ' . implode($join, $parts);
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::escape($form['title']) ?> — versión imprimible</title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="<?= View::asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/all.min.css') ?>">
    <style>
        body { background: #f4f6f8; }
        .print-sheet { max-width: 860px; margin: 0 auto; background: #fff; padding: 2.5rem; }
        .print-sheet h1 { font-size: 1.6rem; }
        table { font-size: .9rem; }
        th, td { vertical-align: top; }
        .no-print { position: sticky; top: 0; z-index: 5; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .print-sheet { max-width: none; padding: 0; }
            .table { font-size: .8rem; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; }
            a[href]:after { content: ""; }
        }
    </style>
    <script src="<?= View::asset('js/print.js') ?>" defer></script>
</head>
<body>
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
        <a href="<?= View::escape($volverUrl ?? ('/forms/' . (int) $form['id'])) ?>" class="btn btn-sm btn-light"><i class="fa-solid fa-arrow-left me-1"></i><?= View::escape($volverLabel ?? 'Volver al formulario') ?></a>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-primary" data-print><i class="fa-solid fa-print me-1"></i>Imprimir / Guardar PDF</button>
        </div>
    </div>

    <div class="print-sheet shadow-sm rounded">
        <header class="mb-4 pb-3 border-bottom">
            <h1 class="fw-bold mb-1"><?= View::escape($form['title']) ?></h1>
            <?php if (($form['description'] ?? '') !== ''): ?>
            <p class="text-muted mb-2"><?= nl2br(View::escape((string) $form['description'])) ?></p>
            <?php endif; ?>
            <p class="small text-muted mb-0">
                <strong><?= count($questions) ?></strong> preguntas ·
                <!-- `View::now()` y no `date()`: PHP corre en UTC (ver config/bootstrap.php),
                     así que `date()` imprimiría la hora de Greenwich en una hoja que lee
                     alguien en Caracas. No hay ningún instante de la BD que convertir
                     aquí, pero la conversión a la zona del lector sigue siendo la misma. -->
                Impreso el <?= View::now('d/m/Y H:i') ?> ·
                Estado: <?= View::escape($form['status'] ?? '') ?>
            </p>
        </header>

        <?php if ($questions === []): ?>
        <p class="text-muted">Este formulario todavía no tiene preguntas.</p>
        <?php else: ?>
        <table class="table table-sm table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:2.5rem">#</th>
                    <th>Pregunta</th>
                    <th style="width:9rem">Tipo</th>
                    <th style="width:12rem">Opciones</th>
                    <th style="width:4.5rem">Oblig.</th>
                </tr>
            </thead>
            <tbody>
                <?php $n = 0; ?>
                <?php foreach ($questions as $f): ?>
                <?php
                $n++;
                $type = (string) ($f['type'] ?? '');
                $cond = $conditionText($f);
                ?>
                <tr>
                    <td class="text-muted"><?= $n ?></td>
                    <td>
                        <span class="fw-semibold"><?= View::escape((string) ($f['label'] ?? '')) ?></span>
                        <?php if (($f['help_text'] ?? '') !== ''): ?>
                            <div class="small text-muted"><?= View::escape((string) $f['help_text']) ?></div>
                        <?php endif; ?>
                        <?php if ($cond !== ''): ?>
                            <div class="small text-muted"><em><?= View::escape($cond) ?></em></div>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= View::escape($typeLabels[$type] ?? $type) ?></td>
                    <td class="small"><?= View::escape($optionText($f)) ?></td>
                    <td class="text-center"><?= !empty($f['is_required']) ? 'Sí' : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
