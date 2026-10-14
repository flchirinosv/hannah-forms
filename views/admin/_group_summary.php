<?php
/**
 * Cabecera-resumen de la tarjeta de un grupo (nombre, descripción, recuentos y
 * el efecto sobre los permisos).
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
 * Variables: `$groupId`, `$groupName`, `$groupDesc`, `$roleCount`, `$memberCount`,
 * `$gPerms`.
 */
use App\Core\View;

/** Agrupa una lista plana de claves por prefijo, para legibilidad. */
$groupPermKeys = static function (array $keys): array {
    $out = [];
    foreach ($keys as $key) {
        $out[explode('.', (string) $key)[0]][] = (string) $key;
    }
    return $out;
};
?>
<div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
    <div class="me-auto">
        <h3 class="h6 fw-bold mb-0 d-flex align-items-center gap-2">
            <i class="fa-solid fa-layer-group text-primary"></i>
            <?= View::escape($groupName) ?>
        </h3>
        <?php if (trim((string) $groupDesc) !== ''): ?>
            <span class="small text-muted"><?= View::escape($groupDesc) ?></span>
        <?php endif; ?>
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

<div class="app-rbac-effect mt-3">
    <div class="app-rbac-effect-title">
        <i class="fa-solid fa-bolt me-1"></i>Lo que otorga este grupo
    </div>
    <?php if ($gPerms === []): ?>
        <p class="small text-muted mb-0">
            Ningún permiso todavía: marca un rol abajo para que el grupo sirva de algo.
        </p>
    <?php else: ?>
        <?php foreach ($groupPermKeys($gPerms) as $prefix => $keys): ?>
            <div class="app-rbac-effect-row">
                <span class="app-rbac-effect-prefix"><?= View::escape($prefix) ?>.</span>
                <span class="app-rbac-effect-keys">
                    <?php foreach ($keys as $k): ?>
                        <code><?= View::escape(substr($k, strlen($prefix) + 1)) ?></code>
                    <?php endforeach; ?>
                </span>
            </div>
        <?php endforeach; ?>
        <p class="small text-muted mb-0 mt-2">
            Se suman a los del rol propio de cada miembro, nunca se restan.
        </p>
    <?php endif; ?>
</div>
