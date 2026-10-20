<?php
use App\Core\View;

/**
 * W3 — El conmutador de vistas de una tarjeta de analítica.
 *
 * `$views` son las claves de `FieldAggregator::VISTAS` que esta familia admite
 * y `$active` la que llega puesta del servidor. El estado no viaja a la BD ni a
 * la URL: `analytics.js` repinta el mismo lienzo y se pierde al recargar o al
 * cambiar de filtro, a propósito (es una lente, no una preferencia guardada).
 *
 * Las etiquetas y los iconos viven aquí y no en el servicio porque son texto de
 * pantalla; el servicio decide QUÉ se puede ver, no cómo se llama el botón.
 */
$etiquetas = [
    'bars'      => ['Barras', 'fa-chart-simple'],
    'doughnut'  => ['Anillo', 'fa-chart-pie'],
    'table'     => ['Tabla', 'fa-table-list'],
    'histogram' => ['Histograma', 'fa-chart-column'],
    'summary'   => ['Resumen', 'fa-list-ol'],
];
?>
<div class="btn-group btn-group-sm vf-view-switch" role="group" aria-label="Vista del gráfico">
    <?php foreach ($views as $vista): ?>
        <?php [$texto, $icono] = $etiquetas[$vista]; ?>
        <button type="button"
                class="btn btn-outline-secondary<?= $vista === $active ? ' active' : '' ?>"
                data-vf-view="<?= View::escape($vista) ?>"
                aria-pressed="<?= $vista === $active ? 'true' : 'false' ?>"
                title="<?= View::escape($texto) ?>">
            <i class="fa-solid <?= View::escape($icono) ?>"></i><span class="visually-hidden"><?= View::escape($texto) ?></span>
        </button>
    <?php endforeach; ?>
</div>
