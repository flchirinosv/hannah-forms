<?php
use App\Core\View;
?>
<div class="container-fluid px-lg-4">
    <?= View::partial('admin/_nav', ['active' => 'users']) ?>

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h2 class="h4 fw-bold mb-0"><i class="fa-solid fa-user-gear me-2 text-primary"></i>Usuarios</h2>
            <p class="text-muted small mb-0">Gestiona roles, grupos y estado de las cuentas.</p>
        </div>
        <a href="/admin/forms" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-clipboard-list me-1"></i>Ver todos los formularios</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="px-4 small text-muted">#</th>
                            <th class="small text-muted">Nombre</th>
                            <th class="small text-muted">Email</th>
                            <th class="small text-muted">Rol</th>
                            <th class="small text-muted">Grupos</th>
                            <th class="small text-muted">Estado</th>
                            <th class="small text-muted">Registro</th>
                            <th class="text-end px-4 small text-muted">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td class="px-4 text-muted"><?= (int) $user['id'] ?></td>
                            <td class="fw-semibold"><?= View::escape($user['name']) ?></td>
                            <td><?= View::escape($user['email']) ?></td>
                            <td>
                                <form method="post" action="/admin/users/role" class="d-inline-flex align-items-center gap-2">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                    <select name="role_id" class="form-select form-select-sm js-auto-submit" style="width:auto">
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?= (int) $role['id'] ?>" <?= (int) $user['role_id'] === (int) $role['id'] ? 'selected' : '' ?>><?= ucfirst(View::escape($role['name'])) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <?php
                                $userGroups = \App\Models\User::groups((int) $user['id']);
                                if ($userGroups === []): ?>
                                    <span class="text-muted small">—</span>
                                <?php else: ?>
                                    <?php foreach ($userGroups as $g): ?>
                                        <span class="badge text-bg-light border"><?= View::escape($g['name']) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="app-status <?= (int) $user['is_active'] === 1 ? 'app-status-published' : 'app-status-closed' ?>">
                                    <?= (int) $user['is_active'] === 1 ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?= View::date($user['created_at']) ?></td>
                            <td class="text-end px-4">
                                <?php if ((int) $user['id'] !== (int) \App\Core\Auth::id()): ?>
                                <form method="post" action="/admin/users/toggle" class="d-inline" data-confirm="¿Cambiar el estado de este usuario?">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= (int) $user['is_active'] === 1 ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= (int) $user['is_active'] === 1 ? 'Desactivar' : 'Activar' ?>">
                                        <i class="fa-solid <?= (int) $user['is_active'] === 1 ? 'fa-ban' : 'fa-check' ?>"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                    <span class="badge text-bg-light text-muted"><i class="fa-solid fa-user-shield me-1"></i>Tú</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>