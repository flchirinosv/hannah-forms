<?php
use App\Core\View;
?>
<div class="container-fluid px-lg-4">
    <?= View::partial('admin/_nav', ['active' => 'groups']) ?>

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="h4 fw-bold mb-0"><i class="fa-solid fa-layer-group me-2 text-primary"></i>Grupos de roles</h2>
            <p class="text-muted small mb-0">Un grupo junta roles y usuarios: quien está en un grupo hereda los permisos de los roles del grupo, además de los de su propio rol.</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6 fw-bold mb-3">Nuevo grupo</h3>
                    <form method="post" action="/admin/groups/create">
                        <?= \App\Core\Csrf::field() ?>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="groupName">Nombre</label>
                            <input type="text" class="form-control form-control-sm" id="groupName" name="name" required maxlength="80">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="groupDesc">Descripción</label>
                            <input type="text" class="form-control form-control-sm" id="groupDesc" name="description" maxlength="255">
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary">Crear grupo</button>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body">
                    <h3 class="h6 fw-bold mb-2"><i class="fa-solid fa-circle-info me-1 text-primary"></i>Cómo funciona</h3>
                    <p class="small text-muted mb-0">
                        Ejemplo: crea un grupo «Soporte» con el rol <strong>user</strong> y añade
                        <code>forms.manage_all</code> al rol. Todos los miembros del grupo podrán
                        operar los formularios de cualquiera sin dejar de ser usuarios normales.
                        La matriz de permisos está en <a href="/admin/roles">Permisos</a>.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <?php if (empty($groups)): ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="fa-solid fa-layer-group fa-2x mb-2 d-block"></i>
                        <span>Sin grupos todavía. Crea el primero a la izquierda.</span>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($groups as $group): ?>
                <?php $gRoles = $groupRoles[(int) $group['id']] ?? []; ?>
                <?php $gMembers = $groupMembers[(int) $group['id']] ?? []; ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                            <div>
                                <h3 class="h6 fw-bold mb-0"><?= View::escape($group['name']) ?></h3>
                                <?php if (!empty($group['description'])): ?>
                                    <span class="small text-muted"><?= View::escape($group['description']) ?></span>
                                <?php endif; ?>
                            </div>
                            <form method="post" action="/admin/groups/delete" class="d-inline" data-confirm="¿Eliminar el grupo '<?= View::escape($group['name']) ?>'? Se desvincula de sus usuarios, sin borrarlos.">
                                <?= \App\Core\Csrf::field() ?>
                                <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar grupo"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>

                        <form method="post" action="/admin/groups/update" class="row g-2 align-items-end">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                            <div class="col-sm-3">
                                <label class="form-label small text-muted">Roles del grupo</label>
                                <select name="roles[]" multiple size="4" class="form-select form-select-sm">
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= (int) $role['id'] ?>" <?= in_array((int) $role['id'], $gRoles, true) ? 'selected' : '' ?>><?= ucfirst(View::escape($role['name'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small text-muted">Miembros (<?= count($gMembers) ?>)</label>
                                <select name="users[]" multiple size="4" class="form-select form-select-sm">
                                    <?php foreach ($users as $member): ?>
                                        <option value="<?= (int) $member['id'] ?>" <?= in_array((int) $member['id'], $gMembers, true) ? 'selected' : '' ?>><?= View::escape($member['name']) ?> (<?= View::escape($member['email']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-3">
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar</button>
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