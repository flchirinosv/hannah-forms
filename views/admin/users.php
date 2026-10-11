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
          // botón escondido.

          // El alta y el interruptor comparten un `row` y no van apilados a ancho
          // completo: son dos tarjetas de la MISMA pantalla y de menos de la mitad
          // de alto cada una de ese modo. `col-lg-8` / `col-lg-4` es el mismo
          // reparto que usa la ficha del formulario, así que el panel tiene una
          // sola retícula. ?>
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <?php // Plegada por defecto, y el panel llega ABIERTO del
                          // servidor a propósito: con el registro público cerrado
                          // este formulario es la única forma de crear un `user`
                          // o un `editor`, así que sin JS tiene que poder usarse.
                          // Lo pliega `dashboard.js` leyendo
                          // `data-vf-group-default="closed"`, no el servidor. ?>
                    <fieldset class="app-settings-group mb-0"
                              data-vf-group-key="admin-usuarios" data-vf-group-default="closed">
                        <legend class="app-settings-group-title">
                            <button type="button" class="app-group-toggle" data-bs-toggle="collapse" data-bs-target="#grp-nuevo-usuario"
                                    aria-expanded="true" aria-controls="grp-nuevo-usuario">
                                <span><i class="fa-solid fa-user-plus me-1"></i>Nuevo usuario</span>
                                <i class="fa-solid fa-chevron-down app-group-caret ms-auto"></i>
                            </button>
                        </legend>
                        <div class="collapse show" id="grp-nuevo-usuario">
                            <p class="text-muted small mb-3">
                                Crea una cuenta con su rol desde aquí. Funciona con el registro público abierto o
                                cerrado: eso solo controla el alta desde <code>/register</code>.
                            </p>
                            <form method="post" action="/admin/users/create">
                                <?= Csrf::field() ?>
                                <?php // 3+2 y no cuatro en fila: dentro de `col-lg-8` un
                                      // `col-md-3` deja el campo de contraseña tan
                                      // estrecho que el marcador de posición se corta. ?>
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-4">
                                        <label for="newUserName" class="form-label small fw-semibold">Nombre</label>
                                        <input type="text" class="form-control form-control-sm" id="newUserName" name="name" placeholder="Ana Ruiz" maxlength="100" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="newUserEmail" class="form-label small fw-semibold">Email</label>
                                        <input type="email" class="form-control form-control-sm" id="newUserEmail" name="email" placeholder="ana@ejemplo.com" maxlength="190" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="newUserPassword" class="form-label small fw-semibold">Contraseña</label>
                                        <input type="password" class="form-control form-control-sm" id="newUserPassword" name="password" placeholder="Mínimo <?= (int) $min ?> caracteres" minlength="<?= (int) $min ?>" autocomplete="new-password" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="newUserPasswordConfirm" class="form-label small fw-semibold">Repetir contraseña</label>
                                        <input type="password" class="form-control form-control-sm" id="newUserPasswordConfirm" name="password_confirm" minlength="<?= (int) $min ?>" autocomplete="new-password" required>
                                        <div class="form-text">Si no coinciden, el alta se rechaza.</div>
                                    </div>
                                    <div class="col-md-4">
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
                                    <div class="col-12">
                                        <p class="form-text mb-0">
                                            Comunícale la contraseña por un canal que no sea el correo: al darle la cuenta
                                            tú, su email no está verificado.
                                        </p>
                                    </div>
                                    <div class="col-12 d-flex justify-content-end">
                                        <button type="submit" class="btn btn-primary btn-sm app-btn-primary">
                                            <i class="fa-solid fa-user-plus me-1"></i>Crear cuenta
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </fieldset>
                </div>
            </div>
        </div>

        <?php // El interruptor de registro es de la INSTALACIÓN, así que va fuera de
              // la tabla de usuarios y con su propio permiso (`admin.access`): quien
              // gestiona la instalación lo abre, pero no necesita poder tocar la
              // cuenta de nadie. Solo se pinta con ese permiso, y no por ser admin,
              // porque el panel entero ya lo exige.

              // La columna no se emite vacía si no hay tarjeta, para que el alta se
              // quede en `col-lg-8` de todos modos y la retícula no dé un salto al
              // desaparecer. ?>
        <?php if ($canManageInstance ?? false): ?>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100" id="instance"
                 data-csrf="<?= View::escape(Csrf::token()) ?>"
                 data-registration-endpoint="/admin/settings/registration">
                <div class="card-body">
                    <h3 class="h6 fw-bold mb-1"><i class="fa-solid fa-globe me-2 text-primary"></i>Acceso de cualquiera</h3>
                    <p class="text-muted small mb-3">
                        El registro público es la puerta de <code>/register</code>. Con él cerrado, las cuentas
                        se crean solo desde esta pantalla.
                    </p>
                    <div class="form-check form-switch d-flex align-items-center gap-2">
                        <input type="checkbox" role="switch" class="form-check-input" id="registrationToggle"
                               <?= ($registrationEnabled ?? false) ? 'checked' : '' ?>
                               aria-describedby="registrationHint">
                        <label class="form-check-label fw-semibold" for="registrationToggle">
                            <span data-role="registration-state">
                                <?= ($registrationEnabled ?? false) ? 'Registro público abierto' : 'Registro público cerrado' ?>
                            </span>
                        </label>
                    </div>
                    <p class="form-text mb-0" id="registrationHint" role="status"></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
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
                                <?php // El `data-vf-async="slots"` sin ningún hueco que
                                      // reponer: cambiar el rol no cambia nada de lo
                                      // que se ve en la fila (el `select` ya está en el
                                      // valor nuevo), así que aquí lo que hace falta es
                                      // que NO se recargue —el dropdown de grupos y la
                                      // fila entera se iban con ella— y que el POST por
                                      // fetch avise. Ojo con `js-auto-submit`: hace
                                      // `requestSubmit()`, no `submit()`, porque
                                      // `submit()` no dispara el evento `submit` y el
                                      // delegado nunca se enteraría (eso es lo que hacía
                                      // que esta fila recargara). ?>
                                <form method="post" action="/admin/users/role" class="d-inline-flex align-items-center gap-2"
                                      data-vf-async="slots">
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
                                    <?php // Sin `admin.groups` se ven, pero no se editan: las
                                          // insignias son el MISMO partial de la celda editable,
                                          // para que las dos ramas no se separen. ?>
                                    <?= View::partial('admin/_user_groups', ['myGroups' => $myGroups]) ?>
                                <?php else: ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle app-rbac-groups-btn" type="button"
                                                data-bs-toggle="dropdown" aria-expanded="false"
                                                id="groups-btn-<?= $uid ?>">
                                            <?php // El icono hace que en móvil la regla de «solo icono»
                                                  // enganche al botón; las insignias se reponen a su
                                                  // tamaño por CSS para no perder la pertenencia. ?>
                                            <i class="fa-solid fa-users me-1"></i>
                                            <?php // El hueco (`vfUserGroups<uid>`) envuelve a las
                                                  // insignias y es un partial, porque el guardado
                                                  // de grupos va por fetch: sin él el botón se
                                                  // queda diciendo «Sin grupos» sobre alguien que
                                                  // acaba de entrar en un grupo. ?>
                                            <span id="vfUserGroups<?= $uid ?>">
                                                <?= View::partial('admin/_user_groups', ['myGroups' => $myGroups]) ?>
                                            </span>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-end p-2" aria-labelledby="groups-btn-<?= $uid ?>">
                                            <?php // `data-vf-async="slots"`: guarda y repone las
                                                  // insignias del botón (`vfUserGroups<uid>`)
                                                  // sin sacar al que edita el desplegable ni
                                                  // perder lo que había marcado. El
                                                  // `method`/`action` siguen ahí, así que sin
                                                  // JavaScript funciona igual. ?>
                                            <form method="post" action="/admin/users/groups" data-vf-async="slots"
                                                  data-vf-error="groupsError<?= $uid ?>">
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
                                                    <?php // `role="alert"` y con `d-none`: el aviso
                                                          // del `ok:false` se pinta aquí, dentro del
                                                          // desplegable que se está editando, y no
                                                          // flotando arriba donde no se ve. ?>
                                                    <p class="small mb-0 d-none text-danger fw-semibold" id="groupsError<?= $uid ?>" role="alert"></p>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php // Dos huecos por fila para el toggle sin recarga: la
                                      // insignia y el botón son lo único que cambia con
                                      // `is_active`, y son partials porque el estado
                                      // objetivo (`active`) puede no ser el inverso del
                                      // que había —un reintento, un doble clic— y sin
                                      // repintarlos el panel seguía diciendo «Activo» a
                                      // un usuario recién desactivado. El resto de la
                                      // fila (rol, grupos, alta) no depende de esto. ?>
                                <span id="vfUserStatus<?= $uid ?>">
                                    <?= View::partial('admin/_user_status', ['user' => $user]) ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?= View::date($user['created_at']) ?></td>
                            <td class="text-end">
                                <?php if (!$isSelf): ?>
                                    <?php // El envoltorio ES el hueco (`vfUserToggle<uid>`)
                                          // y el partial pinta el `<form>` de dentro: el JS
                                          // sustituye el `innerHTML` de esta caja, no el del
                                          // formulario. ?>
                                    <span id="vfUserToggle<?= $uid ?>" class="d-inline">
                                        <?= View::partial('admin/_user_toggle', ['user' => $user, 'uid' => $uid]) ?>
                                    </span>
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

<?php // `dashboard.js` con `defer`, igual que en el resto del panel: sus bloques
      // se guardan uno detrás de otro y aquí solo vive el del interruptor. ?>
<script src="<?= View::asset('js/dashboard.js') ?>" defer></script>
