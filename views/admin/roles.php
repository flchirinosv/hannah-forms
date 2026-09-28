<?php
use App\Core\View;
?>
<div class="container-fluid px-lg-4">
    <?= View::partial('admin/_nav', ['active' => 'roles']) ?>

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="h4 fw-bold mb-0"><i class="fa-solid fa-key me-2 text-primary"></i>Permisos</h2>
            <p class="text-muted small mb-0">Matriz de permisos por rol. Cada usuario hereda los permisos de su rol + los de los roles de sus <a href="/admin/groups">grupos</a>.</p>
        </div>
    </div>

    <form method="post" action="/admin/roles/save" id="roleMatrixForm">
        <?= \App\Core\Csrf::field() ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4 small text-muted" style="min-width:220px;">Permiso</th>
                                <?php foreach ($roles as $role): ?>
                                    <th class="small text-muted text-center"><?= ucfirst(View::escape($role['name'])) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($permissions as $key => $description): ?>
                            <tr>
                                <td class="px-4">
                                    <div class="fw-semibold small"><?= View::escape($key) ?></div>
                                    <div class="small text-muted"><?= View::escape($description) ?></div>
                                </td>
                                <?php foreach ($roles as $role): ?>
                                    <?php $checked = in_array($key, $role['permissions'], true); ?>
                                    <td class="text-center">
                                        <input class="form-check-input" type="checkbox" name="perms[<?= (int) $role['id'] ?>][]" value="<?= View::escape($key) ?>"
                                            <?= $checked ? 'checked' : '' ?>
                                            <?= strtolower((string) $role['name']) === 'admin' && $key === 'admin.access' ? 'disabled title="El rol admin siempre conserva admin.access"' : '' ?>>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-end mt-3">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar matriz</button>
        </div>
    </form>
</div>