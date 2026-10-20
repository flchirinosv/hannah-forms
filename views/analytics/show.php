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
            // W3 — El histograma numérico sale del agregado ya hecho (misma
            // consulta): el servidor no vuelve a preguntar y el navegador no
            // recalcula desde las respuestas, que no las tiene.
            'dist'   => $stat['dist'] ?? [],
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
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-3 app-page-head">
        <div>
            <a href="/forms/<?= (int) $form['id'] ?>" class="text-decoration-none text-muted small"><i class="fa-solid fa-arrow-left me-1"></i>Volver</a>
            <h2 class="h4 fw-bold mb-0 mt-2"><i class="fa-solid fa-chart-column me-2 text-primary"></i>Analítica — <?= View::escape($form['title']) ?></h2>
        </div>
        <div class="d-flex flex-wrap gap-2 app-head-actions">
            <a href="/forms/<?= (int) $form['id'] ?>/export.csv<?= $qsAnalytics !== '' ? '?' . $qsAnalytics : '' ?>" class="btn btn-success"><i class="fa-solid fa-file-csv me-1"></i>Exportar CSV</a>
        </div>
    </div>

    <?php /* W4 — La barra de filtros va en su PROPIA fila y no dentro de
           `.app-head-actions`: comparte fila con las acciones y con un ancho
           estrecho empujaba el botón de exportar. Los `input[type=date]` llevan
           además su `<label>` oculto, que hoy no tienen: sin él, un lector de
           pantalla anuncia «campo de fecha» dos veces sin distinguir «desde» de
           «hasta». */ ?>
    <form method="get" action="/forms/<?= (int) $form['id'] ?>/analytics" class="analytics-filters d-flex flex-wrap gap-2 align-items-center mb-4">
        <div class="btn-group" role="group" aria-label="Presets de rango">
            <button type="button" class="btn btn-outline-secondary" data-preset="7" title="Últimos 7 días">7d</button>
            <button type="button" class="btn btn-outline-secondary" data-preset="30" title="Últimos 30 días">30d</button>
            <button type="button" class="btn btn-outline-secondary" data-preset="90" title="Últimos 90 días">90d</button>
            <button type="button" class="btn btn-outline-secondary" data-preset="month" title="Este mes">Mes</button>
        </div>
        <label class="visually-hidden" for="analyticsFrom">Desde</label>
        <input type="date" class="form-control" id="analyticsFrom" name="from" value="<?= View::escape($from) ?>">
        <span class="text-muted small d-none d-sm-inline">a</span>
        <label class="visually-hidden" for="analyticsTo">Hasta</label>
        <input type="date" class="form-control" id="analyticsTo" name="to" value="<?= View::escape($to) ?>">
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

    <?php
    $answerableFields = array_values(array_filter($form['fields'], static fn($f) => ($f['type'] ?? '') !== 'section'));
    $cards = [
        ['label' => 'Respuestas', 'value' => $total, 'icon' => 'fa-file-lines', 'color' => 'primary'],
        ['label' => 'Última respuesta', 'value' => !empty($submissions) ? View::datetime($submissions[0]['finished_at']) : '—', 'icon' => 'fa-clock', 'color' => 'info'],
        ['label' => 'Preguntas', 'value' => count($answerableFields), 'icon' => 'fa-question', 'color' => 'warning'],
    ];
    // W4 — Tasa de respuesta del listado. Solo en un formulario de tipo
    // `roster`: es el único con padrón del que sacar el denominador. Es global
    // (no respeta `from`/`to`) y la etiqueta lo dice, porque el `responded_at`
    // no se filtra por fecha y un «18 de 40» junto a un «12 respuestas» del
    // filtro sería una contradicción sin explicación.
    if (!empty($participacion) && $participacion['total'] > 0) {
        $pctPatron = (int) round(100 * $participacion['respondidos'] / $participacion['total']);
        $cards[] = [
            'label' => 'Tasa de respuesta',
            'value' => $participacion['respondidos'] . ' de ' . $participacion['total'] . ' (' . $pctPatron . '%)',
            'icon'  => 'fa-users',
            'color' => 'success',
        ];
    }
    ?>
    <div class="row g-3 mb-4">
        <?php foreach ($cards as $card): ?>
        <div class="<?= count($cards) > 3 ? 'col-sm-6 col-xxl-3' : 'col-sm-4' ?>">
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

    <?php /* W4 — Un formulario publicado pero sin respuestas no es «un panel con
           todo a cero»: es un momento con una acción clara (compartirlo) y una
           forma de ver que está vivo. Se corta aquí y no se pintan tarjetas
           vacías que parecen una señal de que algo se rompió. */ ?>
    <?php if ($total === 0 && $from === '' && $to === '' && $atr === null): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fa-solid fa-chart-column fa-2x text-muted mb-3 d-block"></i>
                <h3 class="h5 fw-bold mb-1">Sin respuestas todavía</h3>
                <p class="text-muted mb-4">Este formulario aún no ha recibido ninguna respuesta. Compártelo para empezar a recogerlas.</p>
                <a href="/forms/<?= (int) $form['id'] ?>" class="btn app-btn-primary"><i class="fa-solid fa-share-nodes me-1"></i>Ir a compartir</a>
            </div>
        </div>
    <?php else: ?>

    <?php
    // W4 — Índice de preguntas. Solo en escritorio (la columna sticky no cabe
    // en móvil); en el desplegable de móvil el `scrollIntoView` lo hace
    // `analytics.js`. Las anclas `<a href="#campo-N">` funcionan SIN JS: son
    // navegación de documento de toda la vida.
    $indiceCampos = array_values(array_filter($fieldStats, static fn($s) => !in_array($s['field']['type'], ['section'], true)));
    ?>
    <div class="row g-4">
        <?php if (count($indiceCampos) > 3): ?>
        <div class="col-lg-3 d-none d-lg-block">
            <div class="card border-0 shadow-sm app-analytics-index">
                <div class="card-body">
                    <h3 class="h6 fw-bold mb-3">Preguntas</h3>
                    <nav class="nav flex-column gap-1">
                        <?php foreach ($indiceCampos as $s): ?>
                            <a class="nav-link px-0 py-1 small text-truncate" href="#campo-<?= (int) $s['field']['id'] ?>" title="<?= View::escape($s['field']['label']) ?>"><?= View::escape($s['field']['label']) ?></a>
                        <?php endforeach; ?>
                    </nav>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="<?= count($indiceCampos) > 3 ? 'col-lg-9' : 'col-12' ?>">
            <?php if (count($indiceCampos) > 3): ?>
                <?php // W4 — En móvil la columna del índice no cabe; el desplegable
                      // hace el mismo salto con `scrollIntoView` y su etiqueta es
                      // visible (no oculta): es la única pista de que existe. ?>
                <div class="d-lg-none mb-3">
                    <label class="form-label small text-muted mb-1" for="analyticsIndexSelect">Ir a una pregunta</label>
                    <select class="form-select" id="analyticsIndexSelect">
                        <option value="">Elegir pregunta…</option>
                        <?php foreach ($indiceCampos as $s): ?>
                            <option value="campo-<?= (int) $s['field']['id'] ?>"><?= View::escape($s['field']['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
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
            <?php /* W4 — Cada tarjeta es un destino del índice. El ancla no
                   depende del JS y el `scroll-margin-top` la deja libre del
                   navbar al saltar. */ ?>
            <?php if (in_array($f['type'], \App\Services\FieldAggregator::TIPOS_ABIERTOS, true)): ?>
                <?php /* W2 — Texto libre, email, url, teléfono, hora, fecha y
                       adjuntos. La rama vieja era un comentario y cinco tipos
                       ni siquiera llegaban aquí: se caían fuera de TODAS las
                       ramas y la pregunta desaparecía de la pantalla sin que
                       nada fallara. Ahora la tarjeta lista las últimas
                       respuestas, cada una abrible a su ficha. Va en su
                       partial para que el markup sea comprobable: dentro de
                       `show.php` solo se puede leer como texto. */ ?>
                <?= View::partial('analytics/_open', ['f' => $f, 'stat' => $stat, 'form' => $form]) ?>
            <?php elseif (in_array($f['type'], \App\Services\FieldAggregator::TIPOS_NUMERICOS, true)): ?>
                <?php
                // W3 — Todas las tarjetas numéricas comparten familia de vista:
                // histograma (por defecto) o resumen (oculta el lienzo y deja
                // las mini-stats y el score NPS, que ya pinta el servidor).
                $familiaNumerica = \App\Services\FieldAggregator::familiaDe($f['type']) ?? 'numeric';
                $hayNumerico = $stat['values'] && $stat['values']['avg_value'] !== null;
                ?>
                <div class="col-md-6 vf-analytics-field" id="campo-<?= (int) $f['id'] ?>">
                    <div class="card border-0 shadow-sm h-100 vf-chart-card" data-vf-family="<?= View::escape($familiaNumerica) ?>">
                        <div class="card-body">
                            <div class="vf-card-head d-flex align-items-start justify-content-between gap-2">
                                <div class="vf-card-title">
                                    <h4 class="h6 fw-bold mb-0"><?= View::escape($f['label']) ?></h4>
                                    <span class="text-muted small">Respuesta numérica</span>
                                </div>
                                <div class="vf-card-actions d-flex align-items-center gap-2">
                                    <?php if ($f['type'] === 'nps' && !empty($stat['nps'])): ?>
                                        <?php $nps = $stat['nps']; ?>
                                        <div class="text-end">
                                            <div class="h4 fw-bold mb-0 <?= ($nps['nps'] ?? 0) >= 50 ? 'text-success' : (($nps['nps'] ?? 0) >= 0 ? 'text-warning' : 'text-danger') ?>"><?= $nps['nps'] === null ? '—' : View::escape((string) $nps['nps']) ?></div>
                                            <span class="small text-muted">NPS</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($hayNumerico): ?>
                                        <?= View::partial('analytics/_views', [
                                            'views'  => \App\Services\FieldAggregator::VISTAS[$familiaNumerica],
                                            'active' => 'histogram',
                                        ]) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($hayNumerico): ?>
                             <div class="row g-2 mt-3 text-center">
                                 <div class="col-4"><div class="app-mini-stat bg-primary-subtle text-primary"><div class="h5 m-0 fw-bold"><?= number_format((float) $stat['values']['avg_value'], 2) ?></div><div class="small">Promedio</div></div></div>
                                 <div class="col-4"><div class="app-mini-stat bg-success-subtle text-success"><div class="h5 m-0 fw-bold"><?= View::escape($stat['values']['min_value']) ?></div><div class="small">Mínimo</div></div></div>
                                 <div class="col-4"><div class="app-mini-stat bg-warning-subtle text-warning"><div class="h5 m-0 fw-bold"><?= View::escape($stat['values']['max_value']) ?></div><div class="small">Máximo</div></div></div>
                             </div>
                             <?php if ($f['type'] === 'nps' && !empty($stat['nps'])): ?>
                                 <div class="d-flex flex-wrap gap-2 mt-3 small">
                                     <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Detractores (0-6): <?= (int) $stat['nps']['detractors'] ?></span>
                                     <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Pasivos (7-8): <?= (int) $stat['nps']['passives'] ?></span>
                                     <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Promotores (9-10): <?= (int) $stat['nps']['promoters'] ?></span>
                                 </div>
                             <?php endif; ?>
                             <div class="number-chart-box mt-3" data-vf-chart-box><canvas class="number-chart" data-field="<?= (int) $f['id'] ?>" data-type="<?= View::escape($f['type']) ?>"></canvas></div>
                            <?php else: ?>
                                <p class="text-muted small mt-3">Sin respuestas numéricas válidas.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php elseif ($f['type'] === 'matrix' && !empty($stat['values']['matrix'])): ?>
                <?php $mv = $stat['values']; ?>
                <div class="col-md-6 vf-analytics-field" id="campo-<?= (int) $f['id'] ?>">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="vf-card-head">
                                <h4 class="h6 fw-bold mb-1"><?= View::escape($f['label']) ?></h4>
                                <span class="text-muted small">Matriz — <?= (int) $mv['total'] ?> respuestas</span>
                            </div>
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
                                                    <?php
                                                    // W3 — Un mapa de calor por fila: cada celda se
                                                    // tiñe según su peso en el total de esa fila, de
                                                    // modo que se ve de un golpe qué columna domina.
                                                    // El número sigue siendo el dato; el color solo
                                                    // acompaña (nunca es la única señal).
                                                    $celda = (int) ($rc[$col] ?? 0);
                                                    $filaTotal = (int) ($rc['_total'] ?? 0);
                                                    $intensidad = $filaTotal > 0 ? $celda / $filaTotal : 0;
                                                    ?>
                                                    <td class="text-center"<?= $intensidad > 0 ? ' style="background: rgba(99,102,241,' . number_format(0.05 + 0.25 * $intensidad, 2, '.', '') . ')"' : '' ?>><?= $celda ?></td>
                                                <?php endforeach; ?>
                                                <td class="text-center fw-semibold"><?= (int) ($rc['_total'] ?? 0) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <?php // W3 — Los totales por columna ya se calculaban en el
                                          // agregado (`colCounts`) y no los pintaba nadie: la tabla
                                          // enseñaba las filas y obligaba a sumar a mano. El total
                                          // general es la suma de las celdas (coincide con la suma
                                          // de los totales de fila), no `$mv['total']`, que es el
                                          // número de respuestas. ?>
                                    <tfoot>
                                        <tr class="border-top">
                                            <th scope="row" class="small fw-semibold text-start">Total</th>
                                            <?php foreach ($mv['cols'] as $col): ?>
                                                <td class="text-center fw-semibold"><?= (int) ($mv['colCounts'][$col] ?? 0) ?></td>
                                            <?php endforeach; ?>
                                            <td class="text-center fw-semibold"><?= (int) array_sum($mv['colCounts']) ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($f['type'] === 'ranking' && !empty($stat['values'])): ?>
                <?php $rv = $stat['values']; ?>
                <div class="col-md-6 vf-analytics-field" id="campo-<?= (int) $f['id'] ?>">
                    <div class="card border-0 shadow-sm h-100 vf-chart-card" data-vf-family="ranking">
                        <div class="card-body">
                            <div class="vf-card-head d-flex align-items-start justify-content-between gap-2">
                                <div class="vf-card-title">
                                    <h4 class="h6 fw-bold mb-0"><?= View::escape($f['label']) ?></h4>
                                    <span class="text-muted small">Ranking (promedio de orden)</span>
                                </div>
                                <?php // W3 — La tabla es la vista por defecto (es el dato
                                      // exacto); las barras de posición media son la lectura
                                      // de un golpe. La lista blanca no ofrece doughnut: un
                                      // promedio de orden no es una parte de un todo. ?>
                                <div class="vf-card-actions">
                                <?= View::partial('analytics/_views', [
                                    'views'  => \App\Services\FieldAggregator::VISTAS['ranking'],
                                    'active' => 'table',
                                ]) ?>
                                </div>
                            </div>
                            <div class="table-responsive mt-3" data-vf-ranking-table>
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
                            <div class="ranking-chart-box mt-3 d-none" data-vf-chart-box><canvas class="ranking-chart" data-field="<?= (int) $f['id'] ?>"></canvas></div>
                        </div>
                    </div>
                </div>
            <?php elseif (in_array($f['type'], ['select', 'radio', 'checkbox'], true) && !empty($stat['values'])): ?>
                <div class="col-md-6 vf-analytics-field" id="campo-<?= (int) $f['id'] ?>">
                    <div class="card border-0 shadow-sm h-100 vf-chart-card" data-vf-family="choice">
                        <div class="card-body">
                            <div class="vf-card-head d-flex align-items-start justify-content-between gap-2">
                                <div class="vf-card-title">
                                    <h4 class="h6 fw-bold mb-0"><?= View::escape($f['label']) ?></h4>
                                    <span class="text-muted small"><?= $stat['non_empty'] ?> respuestas</span>
                                </div>
                                <?php // W3 — Barras por defecto: las opciones de un select no
                                      // son porciones de un todo (alguien puede no responder) y
                                      // el anillo se vuelve ilegible con muchas. El anillo se
                                      // ofrece como lectura alternativa, no como default. ?>
                                <div class="vf-card-actions">
                                <?= View::partial('analytics/_views', [
                                    'views'  => \App\Services\FieldAggregator::VISTAS['choice'],
                                    'active' => 'bars',
                                ]) ?>
                                </div>
                            </div>
                            <div class="choice-chart-box mt-3" data-vf-chart-box><canvas class="choice-chart" data-field="<?= (int) $f['id'] ?>" data-type="<?= View::escape($f['type']) ?>"></canvas></div>
                            <div class="vf-choice-table d-none mt-3"></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

            </div><?php // cierra .row.g-4 de las tarjetas por pregunta ?>
        </div><?php // cierra la columna (col-lg-9 / col-12) ?>

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
                                        <th class="small text-muted">Ver</th>
                                    </tr>
                                </thead>
                                <tbody id="analyticsRows" data-form="<?= (int) $form['id'] ?>" data-from="<?= View::escape($from) ?>" data-to="<?= View::escape($to) ?>" data-atr-key="<?= View::escape((string) ($atr['key'] ?? '')) ?>" data-atr-value="<?= View::escape((string) ($atr['value'] ?? '')) ?>">
                                    <?= View::partial('analytics/_rows', [
                                        'submissions'      => $submissions,
                                        'answers'          => $answers,
                                        'answerableFields' => $answerableFields,
                                        'offset'           => (int) ($detail['per_page'] ?? 15) * (int) (($detail['page'] ?? 1) - 1) + 1,
                                        'formId'           => (int) $form['id'],
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
    </div><?php // cierra .row.g-4 exterior ?>
    <?php endif; // total === 0 + sin filtro ?>
</div>

<script src="<?= View::asset('js/chart.umd.min.js') ?>" defer></script>
<script src="<?= View::asset('js/analytics.js') ?>" defer></script>
