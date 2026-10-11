<?php
/**
 * Grupos de roles (RBAC).
 *
 * Un grupo es un contenedor de ROLES y USUARIOS: sus miembros heredan los
 * permisos de los roles del grupo, además de los de su propio rol.
 *
 * Dos decisiones de UI que no son estéticas:
 * - Los roles y los miembros son **checkboxes**, no `<select multiple>`: un
 *   multiselect obliga a mantener pulsado Ctrl para elegir más de uno, y no
 *   hay nada en la pantalla que lo diga. Aquí se marca y se guarda.
 * - Se pinta el **efecto** (los permisos que otorga el grupo), no solo el
 *   cableado: la pregunta de quien administra es qué pasa si saco a esta
 *   persona del grupo, no qué roles tiene el grupo.
 *
 * OJO: este formulario tiene que mandar `name` y `description`. Un POST sin
 * ellos se interpreta como "no los toques" (ver AdminController::groupUpdate),
 * pero mandarlos mantiene el alta y la edición en el mismo camino.
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
<div class="container-fluid px-lg-4">
    <?= View::partial('admin/_nav', ['active' => 'groups']) ?>

    <div class="mb-4">
        <h2 class="h4 fw-bold mb-1"><i class="fa-solid fa-layer-group me-2 text-primary"></i>Grupos de roles</h2>
        <p class="text-muted small mb-0">
            Un grupo reúne usuarios y roles. Quien está en el grupo hereda los permisos de esos roles
            <strong>además</strong> de los de su propio rol: es la forma de dar a un equipo una capacidad
            extra (operar formularios de cualquiera, revisar, ver el log) sin cambiarle el rol a nadie.
        </p>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6 fw-bold mb-3"><i class="fa-solid fa-plus me-1 text-primary"></i>Nuevo grupo</h3>
                    <form method="post" action="/admin/groups/create">
                        <?= \App\Core\Csrf::field() ?>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="groupName">Nombre</label>
                            <input type="text" class="form-control form-control-sm" id="groupName" name="name" required maxlength="80"
                                   placeholder="Soporte">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="groupDesc">Descripción</label>
                            <input type="text" class="form-control form-control-sm" id="groupDesc" name="description" maxlength="255"
                                   placeholder="Quiénes son y por qué existen">
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary w-100 app-btn-mobile-icon">
                            <i class="fa-solid fa-plus me-1"></i>Crear grupo vacío
                        </button>
                    </form>
                    <p class="form-text mt-2 mb-0">
                        Se crea vacío. En cuanto exista aparece su tarjeta a la derecha, y ahí se le marcan
                        los roles y los miembros.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <?php if (empty($groups)): ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="fa-solid fa-layer-group fa-2x mb-2 d-block"></i>
                        <p class="mb-0">Sin grupos todavía. Créalo a la izquierda y asígnale roles.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($groups as $group): ?>
                <?php
                $gid = (int) $group['id'];
                $gRoles = $groupRoles[$gid] ?? [];
                $gMembers = $groupMembers[$gid] ?? [];
                $gPerms = $groupPerms[$gid] ?? [];
                ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <div>
                                <h3 class="h6 fw-bold mb-0 d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-layer-group text-primary"></i>
                                    <?= View::escape($group['name']) ?>
                                </h3>
                                <?php if (!empty($group['description'])): ?>
                                    <span class="small text-muted"><?= View::escape($group['description']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge text-bg-light border">
                                    <i class="fa-solid fa-user-group me-1"></i><?= count($gMembers) ?>
                                    <?= count($gMembers) === 1 ? 'miembro' : 'miembros' ?>
                                </span>
                                <form method="post" action="/admin/groups/delete" class="d-inline"
                                      data-confirm="¿Eliminar el grupo '<?= View::escape($group['name']) ?>'? Se desvincula de sus usuarios, sin borrarlos."
                                      data-confirm-ok="Eliminar grupo">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="group_id" value="<?= $gid ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar grupo" aria-label="Eliminar grupo <?= View::escape($group['name']) ?>">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <form method="post" action="/admin/groups/update" class="row g-3 align-items-start">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="group_id" value="<?= $gid ?>">

                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-1" for="gname-<?= $gid ?>">Nombre</label>
                                <input type="text" class="form-control form-control-sm" id="gname-<?= $gid ?>" name="name"
                                       value="<?= View::escape($group['name']) ?>" maxlength="80">

                                <label class="form-label small text-muted mb-1 mt-3" for="gdesc-<?= $gid ?>">Descripción</label>
                                <input type="text" class="form-control form-control-sm" id="gdesc-<?= $gid ?>" name="description"
                                       value="<?= View::escape($group['description'] ?? '') ?>" maxlength="255">

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
                            </div>

                            <div class="col-md-8">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted mb-1" for="groles-<?= $gid ?>">
                                            Roles del grupo
                                        </label>
                                        <div class="app-rbac-list" id="groles-<?= $gid ?>" role="group"
                                             aria-label="Roles del grupo <?= View::escape($group['name']) ?>">
                                            <?php foreach ($roles as $role): ?>
                                                <?php $rid = (int) $role['id']; ?>
                                                <label class="app-rbac-item">
                                                    <input class="form-check-input mt-1" type="checkbox"
                                                           name="roles[]" value="<?= $rid ?>"
                                                           <?= in_array($rid, $gRoles, true) ? 'checked' : '' ?>>
                                                    <span>
                                                        <span class="app-rbac-item-name"><?= ucfirst(View::escape($role['name'])) ?></span>
                                                        <span class="app-rbac-item-meta">
                                                            <?= count($role['permissions']) ?> <?= count($role['permissions']) === 1 ? 'permiso' : 'permisos' ?>
                                                        </span>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label small text-muted mb-1" for="gusers-<?= $gid ?>">
                                            Miembros
                                        </label>
                                        <div class="app-rbac-list" id="gusers-<?= $gid ?>" role="group"
                                             aria-label="Miembros del grupo <?= View::escape($group['name']) ?>">
                                            <?php if (empty($users)): ?>
                                                <p class="small text-muted mb-0 px-2 py-2">No hay usuarios en la instalación.</p>
                                            <?php endif; ?>
                                            <?php foreach ($users as $member): ?>
                                                <?php $mid = (int) $member['id']; ?>
                                                <label class="app-rbac-item">
                                                    <input class="form-check-input mt-1" type="checkbox"
                                                           name="users[]" value="<?= $mid ?>"
                                                           <?= in_array($mid, $gMembers, true) ? 'checked' : '' ?>>
                                                    <span>
                                                        <span class="app-rbac-item-name"><?= View::escape($member['name']) ?></span>
                                                        <span class="app-rbac-item-meta"><?= View::escape($member['email']) ?></span>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end mt-3">
                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                        <i class="fa-solid fa-floppy-disk me-1"></i>Guardar grupo
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
