<?php
use App\Core\View;
$daily = is_array($daily) ? $daily : [];
$dailyLabels = array_column($daily, 'day');
$dailyCounts = array_column($daily, 'c');
// Datos de los gráficos embebidos como atributo data-* (CSP: sin <script> inline).
$chartData = [
    'daily' => [
        'labels' => $dailyLabels,
        'counts' => array_map('intval', $dailyCounts),
    ],
    'fields' => array_values(array_map(static function ($stat) {
        return [
            'id'     => (int) $stat['field']['id'],
            'type'   => $stat['field']['type'],
            'values' => $stat['values'],
        ];
    }, $fieldStats)),
    'colors' => ['#6366f1', '#22c55e', '#f59e0b', '#0ea5e9', '#ef4444', '#8b5cf6', '#14b8a6', '#f97316', '#ec4899', '#64748b'],
];
$chartDataJson = json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="container-fluid px-lg-4" id="analyticsApp" data-chart="<?= View::escape($chartDataJson) ?>">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3">
        <div class="me-lg-3">
            <a href="/forms/<?= (int) $form['id'] ?>" class="text-decoration-none text-muted small"><i class="fa-solid fa-arrow-left me-1"></i>Volver</a>
            <h2 class="h4 fw-bold mb-0 mt-2"><i class="fa-solid fa-chart-column me-2 text-primary"></i>Analítica — <?= View::escape($form['title']) ?></h2>
        </div>
        <div class="d-flex flex-column flex-sm-row gap-2 align-items-start align-items-sm-center">
            <form method="get" action="/forms/<?= (int) $form['id'] ?>/analytics" class="analytics-filters d-flex flex-wrap flex-sm-nowrap gap-2 align-items-center">
                <input type="date" class="form-control form-control-sm" name="from" value="<?= View::escape($from) ?>">
                <span class="text-muted small d-none d-sm-inline">a</span>
                <input type="date" class="form-control form-control-sm" name="to" value="<?= View::escape($to) ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-filter me-1"></i>Filtrar</button>
                <?php if ($from !== '' || $to !== ''): ?>
                    <a href="/forms/<?= (int) $form['id'] ?>/analytics" class="btn btn-sm btn-link text-danger"><i class="fa-solid fa-xmark me-1"></i>Limpiar</a>
                <?php endif; ?>
            </form>
            <a href="/forms/<?= (int) $form['id'] ?>/export.csv<?= $from !== '' || $to !== '' ? '?from=' . urlencode($from) . '&to=' . urlencode($to) : '' ?>" class="btn btn-sm btn-success w-100 w-sm-auto"><i class="fa-solid fa-file-csv me-1"></i>Exportar CSV</a>
        </div>
    </div>

    <?php
    $answerableFields = array_values(array_filter($form['fields'], static fn($f) => ($f['type'] ?? '') !== 'section'));
    $cards = [
        ['label' => 'Respuestas', 'value' => $total, 'icon' => 'fa-file-lines', 'color' => 'primary'],
        ['label' => 'Última respuesta', 'value' => !empty($submissions) ? View::datetime($submissions[0]['finished_at']) : '—', 'icon' => 'fa-clock', 'color' => 'info'],
        ['label' => 'Preguntas', 'value' => count($answerableFields), 'icon' => 'fa-question', 'color' => 'warning'],
    ];
    ?>
    <div class="row g-3 mb-4">
        <?php foreach ($cards as $card): ?>
        <div class="col-sm-4">
            <div class="card border-0 shadow-sm app-stat-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="app-stat-icon bg-<?= $card['color'] ?>-subtle text-<?= $card['color'] ?>"><i class="fa-solid <?= $card['icon'] ?>"></i></div>
                    <div>
                        <div class="app-stat-value"><?= View::escape($card['value']) ?></div>
                        <div class="app-stat-label text-muted small"><?= View::escape($card['label']) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <?php if (!empty($dailyCounts)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6 fw-bold mb-3">Respuestas por día</h3>
                    <div class="chart-box"><canvas id="dailyChart"></canvas></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php foreach ($fieldStats as $stat): ?>
            <?php $f = $stat['field']; ?>
            <?php if ($f['type'] === 'text' || $f['type'] === 'textarea' || $f['type'] === 'email' || $f['type'] === 'date'): ?>
                <!-- No gráfica para texto libre -->
            <?php elseif ($f['type'] === 'number' || $f['type'] === 'scale' || $f['type'] === 'range' || $f['type'] === 'nps' || $f['type'] === 'star'): ?>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h4 class="h6 fw-bold mb-0"><?= View::escape($f['label']) ?></h4>
                                    <span class="text-muted small">Respuesta numérica</span>
                                </div>
                                <?php if ($f['type'] === 'nps' && !empty($stat['nps'])): ?>
                                    <?php $nps = $stat['nps']; ?>
                                    <div class="text-end">
                                        <div class="h4 fw-bold mb-0 <?= ($nps['nps'] ?? 0) >= 50 ? 'text-success' : (($nps['nps'] ?? 0) >= 0 ? 'text-warning' : 'text-danger') ?>"><?= $nps['nps'] === null ? '—' : View::escape((string) $nps['nps']) ?></div>
                                        <span class="small text-muted">NPS</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($stat['values'] && $stat['values']['avg_value'] !== null): ?>
                             <div class="row g-2 mt-3 text-center">
                                 <div class="col-4"><div class="app-mini-stat bg-primary-subtle text-primary"><div class="h5 m-0 fw-bold"><?= number_format((float) $stat['values']['avg_value'], 2) ?></div><div class="small">Promedio</div></div></div>
                                 <div class="col-4"><div class="app-mini-stat bg-success-subtle text-success"><div class="h5 m-0 fw-bold"><?= View::escape($stat['values']['min_value']) ?></div><div class="small">Mínimo</div></div></div>
                                 <div class="col-4"><div class="app-mini-stat bg-warning-subtle text-warning"><div class="h5 m-0 fw-bold"><?= View::escape($stat['values']['max_value']) ?></div><div class="small">Máximo</div></div></div>
                             </div>
                             <?php if ($f['type'] === 'nps' && !empty($stat['nps'])): ?>
                                 <div class="d-flex flex-wrap gap-2 mt-3 small">
                                     <span class="badge text-bg-danger-subtle border border-danger-subtle">Detractores (0-6): <?= (int) $stat['nps']['detractors'] ?></span>
                                     <span class="badge text-bg-secondary-subtle border border-secondary-subtle">Pasivos (7-8): <?= (int) $stat['nps']['passives'] ?></span>
                                     <span class="badge text-bg-success-subtle border border-success-subtle">Promotores (9-10): <?= (int) $stat['nps']['promoters'] ?></span>
                                 </div>
                             <?php endif; ?>
                             <div class="number-chart-box mt-3"><canvas class="number-chart" data-field="<?= (int) $f['id'] ?>"></canvas></div>
                            <?php else: ?>
                                <p class="text-muted small mt-3">Sin respuestas numéricas válidas.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php elseif ($f['type'] === 'matrix' && !empty($stat['values']['matrix'])): ?>
                <?php $mv = $stat['values']; ?>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h4 class="h6 fw-bold mb-1"><?= View::escape($f['label']) ?></h4>
                            <span class="text-muted small">Matriz — <?= (int) $mv['total'] ?> respuestas</span>
                            <div class="table-responsive mt-3">
                                <table class="table table-sm align-middle mb-0 app-stat-matrix">
                                    <thead>
                                        <tr>
                                            <th class="small text-muted"></th>
                                            <?php foreach ($mv['cols'] as $col): ?>
                                                <th class="small fw-semibold text-center"><?= View::escape($col) ?></th>
                                            <?php endforeach; ?>
                                            <th class="small fw-semibold text-center">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mv['rows'] as $row): ?>
                                            <?php $rc = $mv['matrix'][$row] ?? []; ?>
                                            <tr>
                                                <th scope="row" class="small fw-normal text-start"><?= View::escape($row) ?></th>
                                                <?php foreach ($mv['cols'] as $col): ?>
                                                    <td class="text-center"><?= (int) ($rc[$col] ?? 0) ?></td>
                                                <?php endforeach; ?>
                                                <td class="text-center fw-semibold"><?= (int) ($rc['_total'] ?? 0) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($f['type'] === 'ranking' && !empty($stat['values'])): ?>
                <?php $rv = $stat['values']; ?>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h4 class="h6 fw-bold mb-1"><?= View::escape($f['label']) ?></h4>
                            <span class="text-muted small">Ranking (promedio de orden)</span>
                            <div class="table-responsive mt-3">
                                <table class="table table-sm align-middle mb-0 app-stat-ranking">
                                    <thead>
                                        <tr>
                                            <th class="small text-muted">Opción</th>
                                            <th class="small text-muted">Promedio</th>
                                            <th class="small text-muted text-end">Veces</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rv as $rr): ?>
                                            <tr>
                                                <td class="small"><?= View::escape($rr['value']) ?></td>
                                                <td><?= $rr['avg'] === null ? '—' : View::escape((string) $rr['avg']) ?></td>
                                                <td class="text-end"><?= (int) $rr['c'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif (in_array($f['type'], ['select', 'radio', 'checkbox'], true) && !empty($stat['values'])): ?>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h4 class="h6 fw-bold mb-1"><?= View::escape($f['label']) ?></h4>
                            <span class="text-muted small"><?= $stat['non_empty'] ?> respuestas</span>
                            <div class="choice-chart-box mt-3"><canvas class="choice-chart" data-field="<?= (int) $f['id'] ?>" data-type="<?= View::escape($f['type']) ?>"></canvas></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="h6 fw-bold m-0">Detalle de respuestas <?= $total > 0 ? '(' . $total . ')' : '' ?></h3>
                        <a href="/forms/<?= (int) $form['id'] ?>/export.csv<?= $from !== '' || $to !== '' ? '?from=' . urlencode($from) . '&to=' . urlencode($to) : '' ?>" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-download me-1"></i>Descargar CSV</a>
                    </div>
                    <?php if (empty($submissions)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                            <span>Sin respuestas para este filtro.</span>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive app-table-wrap app-table-scroll">
                            <table class="table table-hover align-middle app-detail-table mb-0">
                                <thead>
                                    <tr>
                                        <th class="small text-muted" style="width: 60px;">#</th>
                                        <?php foreach ($answerableFields as $field): ?>
                                            <th class="small text-muted"><?= View::escape($field['label']) ?></th>
                                        <?php endforeach; ?>
                                        <th class="small text-muted">Fecha</th>
                                    </tr>
                                </thead>
                                <tbody data-paginate data-rows="15">
                                    <?php $idx = 1; foreach ($submissions as $submission): ?>
                                        <?php $row = $answers[$submission['id']]['answers'] ?? []; ?>
                                        <tr class="paginate-item">
                                            <td class="text-muted" data-label="#"><?= $idx++ ?></td>
                                            <?php foreach ($answerableFields as $field): ?>
                                                <td class="app-answer-cell" data-label="<?= View::escape($field['label']) ?>">
                                                    <?php
                                                    $val = $row[(int) $field['id']] ?? '';
                                                    // P10: los adjuntos se muestran como "nombre (tamaño)".
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
                                                    ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="small text-muted" data-label="Fecha"><?= View::datetime($submission['finished_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= View::asset('js/chart.umd.min.js') ?>"></script>
<script src="<?= View::asset('js/analytics.js') ?>"></script>
