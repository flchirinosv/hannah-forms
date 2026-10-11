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

// Fase 7 — El filtro de la URL, en un solo string. Lo usan el botón del CSV y el
// `data-*` del fetcher del paginador, y son cosas distintas que tienen que llevar
// exactamente lo mismo: el botón del CSV sin el filtro descarga un archivo que no
// corresponde a la pantalla, y el fetcher sin el filtro pinta filas de otro grupo
// debajo de un contador que sí dice el grupo bueno.
$qsAnalytics = http_build_query(array_filter([
    'from'      => (string) ($from ?? ''),
    'to'        => (string) ($to ?? ''),
    'atr_key'   => (string) ($atr['key'] ?? ''),
    'atr_value' => (string) ($atr['value'] ?? ''),
], static fn(string $v): bool => $v !== ''));
?>
<div class="container-fluid px-lg-4" id="analyticsApp" data-chart="<?= View::escape($chartDataJson) ?>">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3">
        <div class="me-lg-3">
            <a href="/forms/<?= (int) $form['id'] ?>" class="text-decoration-none text-muted small"><i class="fa-solid fa-arrow-left me-1"></i>Volver</a>
            <h2 class="h4 fw-bold mb-0 mt-2"><i class="fa-solid fa-chart-column me-2 text-primary"></i>Analítica — <?= View::escape($form['title']) ?></h2>
        </div>
        <div class="d-flex flex-column flex-sm-row gap-2 align-items-start align-items-sm-center app-head-actions">
            <form method="get" action="/forms/<?= (int) $form['id'] ?>/analytics" class="analytics-filters d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group" role="group" aria-label="Presets de rango">
                    <button type="button" class="btn btn-outline-secondary" data-preset="7" title="Últimos 7 días">7d</button>
                    <button type="button" class="btn btn-outline-secondary" data-preset="30" title="Últimos 30 días">30d</button>
                    <button type="button" class="btn btn-outline-secondary" data-preset="90" title="Últimos 90 días">90d</button>
                    <button type="button" class="btn btn-outline-secondary" data-preset="month" title="Este mes">Mes</button>
                </div>
                <input type="date" class="form-control" name="from" value="<?= View::escape($from) ?>">
                <span class="text-muted small d-none d-sm-inline">a</span>
                <input type="date" class="form-control" name="to" value="<?= View::escape($to) ?>">
                <?php // Fase 7 — El filtro por atributo va DENTRO del mismo formulario
                      // GET de las fechas y no en otro: son la misma pregunta («qué
                      // respuestas estoy mirando») y el botón dice «Filtrar» una
                      // sola vez. Los dos desplegables se rellenan por el servidor
                      // (`$atributos` y `$atrValues`) para que el atributo elegido
                      // salga con SU lista de valores, y solo hay un `atr_value` en
                      // la URL: un solo valor filtrado a la vez.
                      //
                      // Y sin filtro no se pinta nada, ni un desplegable vacío: un
                      // formulario que no es de listado no tiene atributos y un
                      // «— Attributes —» que no filtra nada parece un filtro roto. ?>
                <?php if (!empty($catalogo)): ?>
                    <?php $atrActual = (string) ($atrClave['key_name'] ?? ''); ?>
                    <select class="form-select" name="atr_key" aria-label="Atributo del listado">
                        <?php if ($atrActual === ''): ?>
                            <option value="">Todo el listado</option>
                        <?php endif; ?>
                        <?php foreach ($catalogo as $atributo): ?>
                            <option value="<?= View::escape($atributo['key_name']) ?>"<?= $atrActual === $atributo['key_name'] ? ' selected' : '' ?>><?= View::escape($atributo['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php // El desplegable de VALORES sale con la clave elegida aunque
                          // todavía no haya valor, que es como se llega al filtro:
                          // se elige el atributo, se guarda, y aquí aparece el
                          // segundo. Con `atr_value` vacío el servidor no filtra
                          // (un filtro por nada no es un filtro) y `atr_value=''`
                          // vuelve en la URL, que es la primera opción del
                          // desplegable. ?>
                    <?php if ($atrClave !== null): ?>
                        <select class="form-select" name="atr_value" aria-label="Valor del atributo">
                            <?php $valorActual = (string) ($atr['value'] ?? ''); ?>
                            <option value="">Todos los valores</option>
                            <?php foreach ($atrValues as $valor): ?>
                                <option value="<?= View::escape($valor) ?>"<?= $valorActual === $valor ? ' selected' : '' ?>><?= View::escape($valor) ?></option>
                            <?php endforeach; ?>
                            <?php // Un valor que ya no está en la lista se queda
                                  // marcado en vez de desaparecer: es lo que se
                                  // está mirando (aunque salga vacío) y si no
                                  // estuviera, el desplegable mentiría sobre lo
                                  // que hay en pantalla. ?>
                            <?php if ($valorActual !== '' && !in_array($valorActual, $atrValues, true)): ?>
                                <option value="<?= View::escape($valorActual) ?>" selected><?= View::escape($valorActual) ?></option>
                            <?php endif; ?>
                        </select>
                    <?php endif; ?>
                <?php endif; ?>
                <button type="submit" class="btn btn-outline-secondary"><i class="fa-solid fa-filter me-1"></i>Filtrar</button>
                <?php if ($from !== '' || $to !== '' || $atrClave !== null): ?>
                    <a href="/forms/<?= (int) $form['id'] ?>/analytics" class="btn btn-link text-danger"><i class="fa-solid fa-xmark me-1"></i>Limpiar</a>
                <?php endif; ?>
            </form>
            <?php // El CSV sale con lo que hay en pantalla. Los dos query strings se
                  // componen aquí y en un solo sitio (`$qsAnalytics`), porque el
                  // botón y el `data-*` del fetcher tienen que llevar EXACTAMENTE lo
                  // mismo: si el botón se olvidara del filtro, el archivo traería
                  // respuestas que la pantalla está ocultando. ?>
            <a href="/forms/<?= (int) $form['id'] ?>/export.csv<?= $qsAnalytics !== '' ? '?' . $qsAnalytics : '' ?>" class="btn btn-success"><i class="fa-solid fa-file-csv me-1"></i>Exportar CSV</a>
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

        <?php /* Fase 8 — Comparar por atributo. Va antes del detalle porque es
               una lectura de la MISMA tabla de abajo en otra forma (por columnas):
               primero la vista de conjunto y luego el detalle que la respalda. */ ?>
        <?php if (!empty($agrupacion['filas'])): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="mb-3">
                        <h3 class="h6 fw-bold mb-1">Comparar por <?= View::escape($agrupacion['label']) ?></h3>
                        <span class="text-muted small">Una columna por valor del atributo del listado. «n» son las personas que lo tienen, no las respuestas.</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 app-stat-matrix app-group-table">
                            <thead>
                                <tr>
                                    <th class="small text-muted">Pregunta</th>
                                    <?php foreach ($agrupacion['grupos'] as $grupo): ?>
                                        <th class="small fw-semibold text-center"<?= $grupo['clave'] === '' ? ' title="' . View::escape((string) ($grupo['nota'] ?? '')) . '"' : '' ?>>
                                            <?= View::escape($grupo['label']) ?>
                                            <div class="text-muted fw-normal">n = <?= (int) $grupo['n'] ?></div>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($agrupacion['filas'] as $fila): ?>
                                <?php $esOpcion = $fila['tipo'] === 'select' || $fila['tipo'] === 'radio'; ?>
                                <tr>
                                    <th scope="row" class="small fw-normal text-start"><?= View::escape($fila['field']['label']) ?></th>
                                    <?php foreach ($fila['celdas'] as $celda): ?>
                                        <td class="text-center">
                                            <?php if ($esOpcion): ?>
                                                <?php if ($celda['items'] === []): ?>
                                                    <span class="text-muted">—</span>
                                                <?php else: ?>
                                                    <?php foreach ($celda['items'] as $item): ?>
                                                        <div class="small"><?= View::escape($item['label']) ?> <span class="text-muted"><?= (int) $item['c'] ?> (<?= (int) $item['pct'] ?> %)</span></div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            <?php elseif ($fila['tipo'] === 'nps'): ?>
                                                <?php if ($celda['nps'] === null): ?>
                                                    <span class="text-muted">—</span>
                                                <?php else: ?>
                                                    <div class="fw-semibold <?= $celda['nps'] >= 50 ? 'text-success' : ($celda['nps'] >= 0 ? 'text-warning' : 'text-danger') ?>"><?= (int) $celda['nps'] ?></div>
                                                    <div class="text-muted small">n = <?= (int) $celda['n'] ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($celda['avg'] === null): ?>
                                                    <span class="text-muted">—</span>
                                                <?php else: ?>
                                                    <div class="fw-semibold"><?= number_format((float) $celda['avg'], 2) ?></div>
                                                    <div class="text-muted small">n = <?= (int) $celda['n'] ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php /* Los techos no se esconden: una tabla con 8 columnas y un
                           «Resto» necesita decir que el resto existe, o parece el
                           listado entero. */ ?>
                    <p class="text-muted small mb-0 mt-3">
                        <?= count($agrupacion['filas']) ?> de <?= count($answerableFields) ?> preguntas.
                        Se comparan promedio, NPS y la respuesta más repetida; el resto está en su tarjeta.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="h6 fw-bold m-0">Detalle de respuestas <?= $total > 0 ? '(' . $total . ')' : '' ?></h3>
                        <?php /* El MISMO `$qsAnalytics` que el botón de arriba. Este
                              enlace se reconstruía a mano con `from`/`to` y se
                              olvidaba del filtro por atributo: un archivo que no
                              corresponde a lo que hay en pantalla, y solo se
                              nota al abrirlo. Los dos query strings se componen en
                              un solo sitio a propósito. */ ?>
                        <a href="/forms/<?= (int) $form['id'] ?>/export.csv<?= $qsAnalytics !== '' ? '?' . $qsAnalytics : '' ?>" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-download me-1"></i>Descargar CSV</a>
                    </div>
                    <?php if ($total === 0): ?>
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
                                        <?php // Fase 7 — Una columna por atributo del
                                              // catálogo, y SOLO si hay filtro: las
                                              // columnas son las del listado de quien
                                              // respondió, no un dato más del
                                              // formulario. ?>
                                        <?php foreach ($atributos ?? [] as $atributo): ?>
                                            <th class="small text-muted"><?= View::escape($atributo['label']) ?></th>
                                        <?php endforeach; ?>
                                        <?php foreach ($answerableFields as $field): ?>
                                            <th class="small text-muted"><?= View::escape($field['label']) ?></th>
                                        <?php endforeach; ?>
                                        <th class="small text-muted">Fecha</th>
                                    </tr>
                                </thead>
                                <tbody id="analyticsRows" data-form="<?= (int) $form['id'] ?>" data-from="<?= View::escape($from) ?>" data-to="<?= View::escape($to) ?>" data-atr-key="<?= View::escape((string) ($atr['key'] ?? '')) ?>" data-atr-value="<?= View::escape((string) ($atr['value'] ?? '')) ?>">
                                    <?= View::partial('analytics/_rows', [
                                        'submissions'      => $submissions,
                                        'answers'          => $answers,
                                        'answerableFields' => $answerableFields,
                                        'offset'           => (int) ($detail['per_page'] ?? 15) * (int) (($detail['page'] ?? 1) - 1),
                                        'piiTypes'         => $piiTypes ?? [],
                                        'atributos'        => $atributos ?? [],
                                        'attrsByPid'       => $attrsByPid ?? [],
                                    ]) ?>
                                </tbody>
                            </table>
                        </div>
                        <?= View::partial('analytics/_pagination', [
                            'total'   => $total,
                            'page'    => $detail['page'] ?? 1,
                            'pages'   => $detail['pages'] ?? 1,
                            'perPage' => $detail['per_page'] ?? 15,
                        ]) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= View::asset('js/chart.umd.min.js') ?>" defer></script>
<script src="<?= View::asset('js/analytics.js') ?>" defer></script>
