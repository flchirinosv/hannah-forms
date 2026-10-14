<?php
/**
 * M2 — Paginador de los listados de formularios (dashboard y /admin/forms).
 *
 * Enlaces de verdad, no botones: la página vive en la URL, así que el botón de
 * atrás del navegador funciona y el enlace se puede copiar. Es lo mismo que
 * hace el roster de la ficha con su `?page=`, y lo contrario del paginador de
 * `analytics.js`, que sí es un fetch porque ahí el listado se reordena mientras
 * se mira.
 *
 * Los controles llevan `data-vf-page` con la página a la que llevan: es lo que
 * leen los tests para comprobar que el enlace existe sin seguirlo, y el gancho
 * más barato si algún día hay que decidir algo con él (por ejemplo, abrir en una
 * pestaña nueva con ctrl). `app-pager` es lo mismo: un gancho sin estilo, porque
 * la maquetación la pone el `d-flex` de Bootstrap.
 *
 * Variables esperadas: $listado (`items/total/page/pages/per_page`), $base (la
 * ruta) y $qs (el query string de los filtros, con `?` delante o vacío).
 * Opcionales: `$sujeto` (el singular de lo listado, para el recuento) y `$aria`
 * (la etiqueta del nav). Por defecto, formularios.
 */
use App\Core\View;

$listado = is_array($listado ?? null) ? $listado : ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 1];
$base = (string) ($base ?? '/dashboard');
$qs = (string) ($qs ?? '');
$sujeto = (string) ($sujeto ?? 'formulario');
$aria = (string) ($aria ?? 'Paginación de formularios');
$page = max(1, (int) $listado['page']);
$pages = max(1, (int) $listado['pages']);
$total = (int) $listado['total'];
$porPagina = max(1, (int) $listado['per_page']);

/** Un enlace del paginador: la ruta, los filtros que ya estaban y la página. */
$link = static function (int $destino) use ($base, $qs): string {
    $extra = $qs === '' ? '' : '&' . substr($qs, 1);
    return $base . '?page=' . $destino . $extra;
};
?>
<?php // Con una sola página no hay nada que decidir y un «1 / 1» deshabilitado
      // solo ocupa sitio: el bloque entero no se pinta. ?>
<?php if ($pages > 1): ?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3 app-pager">
    <span class="small text-muted">
        <?= $total ?> <?= View::escape($sujeto) ?><?= $total === 1 ? '' : 's' ?> · Página <?= $page ?> de <?= $pages ?>
    </span>
    <nav aria-label="<?= View::escape($aria) ?>">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <?php if ($page <= 1): ?>
                    <span class="page-link" aria-disabled="true"><i class="fa-solid fa-chevron-left"></i></span>
                <?php else: ?>
                    <a class="page-link" href="<?= View::escape($link($page - 1)) ?>" data-vf-page="<?= $page - 1 ?>" rel="prev" aria-label="Página anterior"><i class="fa-solid fa-chevron-left"></i></a>
                <?php endif; ?>
            </li>
            <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $pages ?></span></li>
            <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
                <?php if ($page >= $pages): ?>
                    <span class="page-link" aria-disabled="true"><i class="fa-solid fa-chevron-right"></i></span>
                <?php else: ?>
                    <a class="page-link" href="<?= View::escape($link($page + 1)) ?>" data-vf-page="<?= $page + 1 ?>" rel="next" aria-label="Página siguiente"><i class="fa-solid fa-chevron-right"></i></a>
                <?php endif; ?>
            </li>
        </ul>
    </nav>
</div>
<?php endif; ?>