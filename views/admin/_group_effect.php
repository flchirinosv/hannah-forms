<?php
/**
 * «Lo que otorga este grupo»: el efecto del grupo sobre los permisos de sus
 * miembros.
 *
 * Vive aparte del resumen porque en el rediseño de `/admin/groups` pasó al
 * CUERPO de la tarjeta (columna 2/3 de la primera fila de la rejilla), mientras
 * que la cabecera-resumen —nombre, descripción, recuentos y borrado— se queda
 * en el encabezado. Son dos huecos distintos que `AdminController::groupUpdate()`
 * repinta sin recargar: `vfGroupSummary<gid>` y `vfGroupEffect<gid>`.
 *
 * Es el mismo markup en el GET en línea y en la respuesta del POST: con dos
 * copias, cambiar una sola hacía que la pantalla y el repintado divergieran.
 *
 * Variables: `$gPerms` (lista plana de claves de permiso).
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
<div class="app-rbac-effect">
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
