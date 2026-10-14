<?php
/**
 * Cabecera compartida de las pantallas de /admin.
 *
 * Emite las tres piezas que todas repiten y que, copiadas en cada vista, ya
 * habían divergido: la sub-navegación (`admin/_nav`), el bloque título+subtítulo
 * y la fila de acciones (`.app-head-actions`). Al vivir aquí, el reparto
 * título/acciones cambia en las cinco a la vez, y `FormFichaLayoutTest` puede
 * exigir la clase en un solo sitio en vez de en cada archivo.
 *
 * Variables esperadas:
 *   - `$active`   clave de la pestaña activa (ver `admin/_nav.php`).
 *   - `$icon`     icono FontAwesome del título, sin el prefijo `fa-solid`.
 *   - `$title`    título de la pantalla (se escapa).
 *   - `$subtitle` HTML de apoyo bajo el título. Es contenido de la PROPIA vista
 *                 (literales con `<strong>`/`<a>`, nunca datos de usuario), por
 *                 eso se pinta tal cual; si algún día llevara un dato, habría
 *                 que escaparlo en el llamante.
 *   - `$actions`  HTML opcional de la fila de acciones (botones o el formulario
 *                 de filtro). Vacío = sin fila.
 */
use App\Core\View;

$active   = (string) ($active ?? '');
$icon     = (string) ($icon ?? '');
$title    = (string) ($title ?? '');
$subtitle = (string) ($subtitle ?? '');
$actions  = (string) ($actions ?? '');
?>
<?= View::partial('admin/_nav', ['active' => $active]) ?>
<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-4 app-page-head">
    <div class="app-page-head-text">
        <h2 class="h4 fw-bold mb-1">
            <?php if ($icon !== ''): ?><i class="fa-solid <?= View::escape($icon) ?> me-2 text-primary"></i><?php endif; ?><?= View::escape($title) ?>
        </h2>
        <?php if ($subtitle !== ''): ?>
            <p class="text-muted small mb-0"><?= $subtitle ?></p>
        <?php endif; ?>
    </div>
    <?php if ($actions !== ''): ?>
        <div class="app-head-actions"><?= $actions ?></div>
    <?php endif; ?>
</div>
