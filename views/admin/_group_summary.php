<?php
/**
 * Cabecera-resumen de la tarjeta de un grupo: nombre, descripción, recuentos y
 * el botón de borrar. El plegado de la tarjeta también vive aquí (el caret que
 * abre y cierra el panel del cuerpo).
 *
 * Es la parte que `AdminController::groupUpdate()` repinta sin recargar
 * (`vfGroupSummary<gid>`), así que vive en su propio partial: con el GET
 * pintándola en línea y el POST devolviendo otra copia, las dos divergirían en
 * cuanto alguien tocara solo una.
 *
 * El botón de borrar va DENTRO del resumen para que un renombrado sin recargar
 * (que repinta este hueco) actualice también el nombre que la confirmación
 * menciona. No es un `<form>` anidado: el hueco que repinta el JS es un `<div>`
 * que no está dentro de ningún otro formulario.
 *
 * El caret es un `<button type="button">` con `data-bs-toggle="collapse"` que
 * apunta al panel `grp-grupo-<gid>` del cuerpo. `type="button"` es obligatorio:
 * sin él, pulsarlo enviaría el formulario que tiene al lado. Lo recuerda
 * `dashboard.js` por pantalla (`data-vf-group-key="admin-grupos"` en el
 * contenedor de las tarjetas), y el panel llega ABIERTO del servidor para que
 * sin JS se pueda usar.
 *
 * El efecto del grupo («lo que otorga») NO va aquí: mudó al cuerpo de la
 * tarjeta y tiene su propio partial (`admin/_group_effect.php`) y su propio
 * hueco de repintado (`vfGroupEffect<gid>`).
 *
 * Variables: `$groupId`, `$groupName`, `$groupDesc`, `$roleCount`, `$memberCount`.
 */
use App\Core\View;

$panelId = 'grp-grupo-' . (int) $groupId;
?>
<div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
    <div class="me-auto d-flex align-items-start gap-2">
        <button type="button" class="app-group-toggle"
                data-bs-toggle="collapse" data-bs-target="#<?= View::escape($panelId) ?>"
                aria-expanded="true" aria-controls="<?= View::escape($panelId) ?>"
                aria-label="Mostrar u ocultar el grupo <?= View::escape($groupName) ?>">
            <i class="fa-solid fa-chevron-down app-group-caret"></i>
        </button>
        <div>
            <h3 class="h6 fw-bold mb-0 d-flex align-items-center gap-2">
                <i class="fa-solid fa-layer-group text-primary"></i>
                <?= View::escape($groupName) ?>
            </h3>
            <?php if (trim((string) $groupDesc) !== ''): ?>
                <span class="small text-muted"><?= View::escape($groupDesc) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge text-bg-light border">
            <i class="fa-solid fa-user-shield me-1"></i><?= $roleCount ?> <?= $roleCount === 1 ? 'rol' : 'roles' ?>
        </span>
        <span class="badge text-bg-light border">
            <i class="fa-solid fa-user-group me-1"></i><?= $memberCount ?> <?= $memberCount === 1 ? 'miembro' : 'miembros' ?>
        </span>
        <form method="post" action="/admin/groups/delete" class="d-inline"
              data-vf-async="remove"
              data-confirm="¿Eliminar el grupo '<?= View::escape($groupName) ?>'? Se desvincula de sus usuarios, sin borrarlos."
              data-confirm-ok="Eliminar grupo">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar grupo" aria-label="Eliminar grupo <?= View::escape($groupName) ?>">
                <i class="fa-solid fa-trash"></i>
            </button>
        </form>
    </div>
</div>
