<?php
/**
 * Usuarios del panel: rol propio, grupos heredados y estado de la cuenta.
 *
 * El rol y los grupos son las dos mitades del RBAC y por eso van en columnas
 * contiguas: el rol es lo que la persona tiene, los grupos son lo que además
 * le dan sus equipos. La columna de grupos es editable fila a fila porque
 * «sacar a Ana del grupo de Soporte» es la operación del día a día, y antes
 * obligaba a entrar en Grupos de roles y a buscarla en una lista de casillas.
 */
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Controllers\AuthController;

// La MISMA constante que valida el registro público y que fija
// `views/auth/register.php`: si aquí se escribiera un número, el navegador y el
// servidor acabarían aceptando contraseñas distintas.
$min = AuthController::PASSWORD_MIN;

$grupos = $groups ?? [];
$userGroups = $userGroups ?? [];
$canEditGroups = $canEditGroups ?? false;
$roles = $roles ?? [];
?>
<div class="container-fluid px-lg-4">
    <?= View::partial('admin/_nav', ['active' => 'users']) ?>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <div>
            <h2 class="h4 fw-bold mb-0"><i class="fa-solid fa-user-gear me-2 text-primary"></i>Usuarios</h2>
            <p class="text-muted small mb-0">
                El <strong>rol</strong> es lo que tiene la persona; los <strong>grupos</strong> le suman los
                permisos de los roles del grupo. Todo se suma, nada se resta.
            </p>
        </div>
        <a href="/admin/forms" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-clipboard-list me-1"></i>Ver todos los formularios</a>
    </div>

    <?php // El alta va aquí, y no solo porque sea «lo primero»: con
          // REGISTRATION_ENABLED cerrado (el default) es la ÚNICA forma de crear
          // un `user` o un `editor`, ya que `scripts/create-admin.php` solo sabe
          // hacer administradores. Por eso lleva su propia tarjeta y no es un
          // botón escondido. ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h3 class="h6 fw-bold mb-1"><i class="fa-solid fa-user-plus me-2 text-primary"></i>Nuevo usuario</h3>
            <p class="text-muted small mb-3">
                Crea una cuenta con su rol desde aquí. No hace falta que el registro público esté
                abierto: eso solo controla el alta desde <code>/register</code>.
            </p>
            <form method="post" action="/admin/users/create">
                <?= Csrf::field() ?>
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="newUserName" class="form-label small fw-semibold">Nombre</label>
                        <input type="text" class="form-control form-control-sm" id="newUserName" name="name" placeholder="Ana Ruiz" maxlength="100" required>
                    </div>
                    <div class="col-md-3">
                        <label for="newUserEmail" class="form-label small fw-semibold">Email</label>
                        <input type="email" class="form-control form-control-sm" id="newUserEmail" name="email" placeholder="ana@ejemplo.com" maxlength="190" required>
                    </div>
                    <div class="col-md-3">
                        <label for="newUserPassword" class="form-label small fw-semibold">Contraseña</label>
                        <input type="password" class="form-control form-control-sm" id="newUserPassword" name="password" placeholder="Mínimo <?= (int) $min ?> caracteres" minlength="<?= (int) $min ?>" autocomplete="new-password" required>
                    </div>
                    <div class="col-md-3">
                        <label for="newUserPasswordConfirm" class="form-label small fw-semibold">Repetir contraseña</label>
                        <input type="password" class="form-control form-control-sm" id="newUserPasswordConfirm" name="password_confirm" minlength="<?= (int) $min ?>" autocomplete="new-password" required>
                        <div class="form-text">Si no coinciden, el alta se rechaza.</div>
                    </div>
                    <div class="col-md-3">
                        <label for="newUserRole" class="form-label small fw-semibold">Rol</label>
                        <select class="form-select form-select-sm" id="newUserRole" name="role_id" required>
                            <?php foreach ($roles as $rol): ?>
                                <?php $rid = (int) $rol['id']; ?>
                                <option value="<?= $rid ?>"<?= $rid === 2 ? ' selected' : '' ?>>
                                    <?= View::escape((string) $rol['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Lo que la persona podrá hacer. Se puede cambiar después.</div>
                    </div>
                    <div class="col-md-9 d-flex align-items-center">
                        <div class="form-text">
                            Comunícale la contraseña por un canal que no sea el correo: al darle la cuenta
                            tú, su email no está verificado.
                        </div>
                    </div>
                    <div class="col-12 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary btn-sm app-btn-primary">
                            <i class="fa-solid fa-user-plus me-1"></i>Crear cuenta
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($users)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5 text-muted">
                <i class="fa-solid fa-users fa-2x mb-2 d-block"></i>
                <p class="mb-0">Todavía no hay usuarios.</p>
            </div>
        </div>
    <?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="small text-muted">Nombre</th>
                            <th class="small text-muted">Email</th>
                            <th class="small text-muted">Rol</th>
                            <th class="small text-muted">Grupos</th>
                            <th class="small text-muted">Estado</th>
                            <th class="small text-muted">Registro</th>
                            <th class="text-end small text-muted">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <?php $uid = (int) $user['id']; ?>
                        <?php $myGroups = $userGroups[$uid] ?? []; ?>
                        <tr>
                            <td class="fw-semibold">
                                <?= View::escape($user['name']) ?>
                                <?php if ($uid === (int) Auth::id()): ?>
                                    <span class="badge text-bg-light text-muted"><i class="fa-solid fa-user-shield me-1"></i>Tú</span>
                                <?php endif; ?>
                            </td>
                            <td><?= View::escape($user['email']) ?></td>
                            <td>
                                <?php $isSelf = $uid === (int) Auth::id(); ?>
                                <?php if ($isSelf): ?>
                                    <?php
                                    // Nadie se quita su propio admin por accidente: el
                                    // servidor lo rechaza, así que tampoco se ofrece.
                                    $ownRole = null;
                                    foreach ($roles as $r) {
                                        if ((int) $r['id'] === (int) $user['role_id']) {
                                            $ownRole = $r;
                                            break;
                                        }
                                    }
                                    ?>
                                    <span class="badge text-bg-primary-subtle text-primary-emphasis">
                                        <?= View::escape($ownRole['name'] ?? '—') ?>
                                    </span>
                                <?php else: ?>
                                <form method="post" action="/admin/users/role" class="d-inline-flex align-items-center gap-2">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <label class="visually-hidden" for="role-<?= $uid ?>">Rol de <?= View::escape($user['name']) ?></label>
                                    <select name="role_id" id="role-<?= $uid ?>" class="form-select form-select-sm js-auto-submit app-rbac-select">
                                        <?php foreach ($roles as $role): ?>
                                            <option value="<?= (int) $role['id'] ?>" <?= (int) $user['role_id'] === (int) $role['id'] ? 'selected' : '' ?>><?= ucfirst(View::escape($role['name'])) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($grupos === []): ?>
                                    <span class="text-muted small">—</span>
                                <?php elseif (!$canEditGroups): ?>
                                    <?php foreach ($myGroups as $g): ?>
                                        <span class="badge text-bg-light border"><?= View::escape($g['name']) ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle app-rbac-groups-btn" type="button"
                                                data-bs-toggle="dropdown" aria-expanded="false"
                                                id="groups-btn-<?= $uid ?>">
                                            <?php if ($myGroups === []): ?>
                                                <span class="text-muted">Sin grupos</span>
                                            <?php else: ?>
                                                <?php foreach ($myGroups as $g): ?>
                                                    <span class="badge text-bg-light border"><?= View::escape($g['name']) ?></span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-end p-2" aria-labelledby="groups-btn-<?= $uid ?>">
                                            <form method="post" action="/admin/users/groups">
                                                <?= \App\Core\Csrf::field() ?>
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <div class="px-1">
                                                    <div class="app-rbac-item-meta text-uppercase fw-bold mb-1">Grupos de <?= View::escape($user['name']) ?></div>
                                                    <?php foreach ($grupos as $group): ?>
                                                        <?php $gid = (int) $group['id']; ?>
                                                        <label class="app-rbac-item">
                                                            <input class="form-check-input mt-1" type="checkbox" name="groups[]" value="<?= $gid ?>"
                                                                   <?= in_array($gid, array_column($myGroups, 'id'), true) ? 'checked' : '' ?>>
                                                            <span>
                                                                <span class="app-rbac-item-name"><?= View::escape($group['name']) ?></span>
                                                                <?php if (!empty($group['description'])): ?>
                                                                    <span class="app-rbac-item-meta"><?= View::escape($group['description']) ?></span>
                                                                <?php endif; ?>
                                                            </span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="d-grid gap-2 mt-2 px-1">
                                                    <button type="submit" class="btn btn-sm btn-primary">
                                                        <i class="fa-solid fa-floppy-disk me-1"></i>Guardar grupos
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="app-status <?= (int) $user['is_active'] === 1 ? 'app-status-published' : 'app-status-closed' ?>">
                                    <?= (int) $user['is_active'] === 1 ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?= View::date($user['created_at']) ?></td>
                            <td class="text-end">
                                <?php if (!$isSelf): ?>
                                <form method="post" action="/admin/users/toggle" class="d-inline" data-confirm="¿Cambiar el estado de este usuario?">
                                    <?= \App\Core\Csrf::field() ?>
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <button type="submit" class="btn btn-sm <?= (int) $user['is_active'] === 1 ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= (int) $user['is_active'] === 1 ? 'Desactivar' : 'Activar' ?>" aria-label="<?= (int) $user['is_active'] === 1 ? 'Desactivar' : 'Activar' ?> a <?= View::escape($user['name']) ?>">
                                        <i class="fa-solid <?= (int) $user['is_active'] === 1 ? 'fa-ban' : 'fa-check' ?>"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>