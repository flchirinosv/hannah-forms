<?php
/**
 * P1 (2026-09-28) — Paginador server-side de la tabla de detalle de
 * respuestas. Los controles llevan `data-paginate-page` y `analytics.js` hace
 * el fetch; la etiqueta central muestra «Página X de Y».
 *
 * Variables esperadas: $total, $page, $pages, $perPage.
 */
use App\Core\View;
$total = isset($total) ? (int) $total : 0;
$page = isset($page) ? (int) $page : 1;
$pages = isset($pages) ? (int) $pages : 1;
$perPage = isset($perPage) ? (int) $perPage : 15;
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3 analytics-pager">
    <span class="small text-muted"><?= $total ?> respuesta<?= $total === 1 ? '' : 's' ?> · Página <?= $page ?> de <?= $pages ?> (<?= $perPage ?> por página)</span>
    <nav aria-label="Paginación de respuestas">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <button type="button" class="page-link" data-paginate-page="<?= $page - 1 ?>" <?= $page <= 1 ? 'disabled' : '' ?>><i class="fa-solid fa-chevron-left"></i></button>
            </li>
            <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $pages ?></span></li>
            <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
                <button type="button" class="page-link" data-paginate-page="<?= $page + 1 ?>" <?= $page >= $pages ? 'disabled' : '' ?>><i class="fa-solid fa-chevron-right"></i></button>
            </li>
        </ul>
    </nav>
</div>