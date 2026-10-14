<?php
/**
 * Usuarios del panel: rol propio, grupos heredados y estado de la cuenta.
 *
 * Las tres fuentes del RBAC van en columnas contiguas: el rol es lo que la
 * persona tiene, los grupos son lo que además le dan sus equipos, y los
 * permisos extra son la concesión individual (un permiso suelto sin cambiarle el
 * rol ni meterla en un grupo). Los grupos y los permisos son editables fila a
 * fila porque «sacar a Ana del grupo de Soporte» y «dejar que Ana publique sin
 * revisión» son operaciones del día a día, y antes obligaban a entrar en Grupos
 * de roles o en la matriz.
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
$userPermissions = $userPermissions ?? [];
$userEffective = $userEffective ?? [];
$permissionCatalog = $permissionCatalog ?? [];
$canEditGroups = $canEditGroups ?? false;
$canEditPermissions = $canEditPermissions ?? false;
$roles = $roles ?? [];

// Fase 2 — El listado llega paginado y filtrado desde el servidor. La barra de
// filtros viaja en la URL (`?q=&rol=&estado=&page=`), así que el paginador tiene
// que reponer los tres filtros y quitar `page`.
$filtros = $filtros ?? ['q' => '', 'rol' => '', 'estado' => ''];
$listado = $listado ?? ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 25];
$hayFiltros = $filtros['q'] !== '' || $filtros['rol'] !== '' || $filtros['estado'] !== '';
$qsFiltros = array_filter(
    ['q' => $filtros['q'], 'rol' => $filtros['rol'], 'estado' => $filtros['estado']],
    static fn ($v): bool => $v !== ''
);
$qsFiltros = $qsFiltros === [] ? '' : '?' . http_build_query($qsFiltros);
?>
<div class="container-fluid px-lg-4">
    <?= View::partial('admin/_page_head', [
        'active'   => 'users',
        'icon'     => 'fa-user-gear',
        'title'    => 'Usuarios',
        'subtitle' => 'El <strong>rol</strong> es lo que tiene la persona; los <strong>grupos</strong> y los'
            . ' <strong>permisos extra</strong> le suman más. Todo se suma, nada se resta.',
        'actions'  => '<a href="/admin/forms" class="btn btn-outline-secondary"><i class="fa-solid fa-clipboard-list me-1"></i>Ver todos los formularios</a>',
    ]) ?>

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

        <?php // Los ajustes de la INSTALACIÓN (registro y revisión) son de la
              // instalación, así que van fuera de la tabla de usuarios y con su
              // propio permiso (`admin.access`): quien la gestiona los cambia,
              // pero no necesita poder tocar la cuenta de nadie. Se pintan solo
              // con ese permiso, y no por ser admin, porque el panel entero ya
              // lo exige.

              // Los dos van apilados en la MISMA `col-lg-4`, no en dos columnas:
              // el reparto es 8 (alta) + 4 (instalación) y una tercera columna
              // rompería la retícula. La columna no se emite vacía si no hay
              // tarjeta, para que el alta se quede en `col-lg-8` de todos modos y
              // la retícula no dé un salto al desaparecer. ?>
        <?php if ($canManageInstance ?? false): ?>
        <div class="col-lg-4">
            <div class="d-flex flex-column gap-3 h-100">
                <div class="card border-0 shadow-sm" id="instance"
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

                <?php // F3 (revisada) — La política de revisión es de la INSTANCIA y
                      // no del formulario. Un POST NATIVO (no fetch): la pantalla
                      // no repinta huecos, así que el PRG de siempre es el camino
                      // único, con o sin JavaScript. ?>
                <div class="card border-0 shadow-sm">
                    <form method="post" action="/admin/settings/review">
                        <?= Csrf::field() ?>
                        <div class="card-body">
                            <h3 class="h6 fw-bold mb-1"><i class="fa-solid fa-user-check me-2 text-primary"></i>Revisión de formularios</h3>
                            <p class="text-muted small mb-3">
                                Con la revisión activa, un formulario pasa por la bandeja de revisión antes de publicarse.
                            </p>
                            <div class="form-check form-switch mb-1">
                                <?php // El `hidden` con el MISMO `name` va delante: sin él, desmarcar la
                                      // casilla no enviaría nada. El handler trata el ausente como
                                      // apagado, pero el oculto deja el apagado explícito. ?>
                                <input type="hidden" name="review_required" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="reviewRequiredToggle"
                                       name="review_required" value="1"<?= ($reviewRequired ?? false) ? ' checked' : '' ?>>
                                <label class="form-check-label small fw-semibold" for="reviewRequiredToggle">Exigir revisión a todos</label>
                            </div>
                            <div class="form-check form-switch">
                                <input type="hidden" name="review_notify_email" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="reviewNotifyToggle"
                                       name="review_notify_email" value="1"<?= ($reviewNotifyEmail ?? false) ? ' checked' : '' ?>>
                                <label class="form-check-label small fw-semibold" for="reviewNotifyToggle">Avisar por correo a los revisores</label>
                            </div>
                            <p class="form-text mb-0">
                                Quien tenga el permiso <code>forms.publish_without_review</code> queda exento; se concede
                                en <a href="/admin/roles">Roles y permisos</a> (a un rol) o como
                                <strong>permiso extra</strong> de una persona, en la tabla de abajo.
                            </p>
                        </div>
                        <div class="card-footer bg-white border-0 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-sm app-btn-primary">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Guardar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php // La búsqueda y los filtros se resuelven en SQL y viajan en la URL:
          // con el `LIMIT` ya aplicado, un filtro de cliente solo ocultaría las
          // filas de la página. Los dos desplegables se envían solos al cambiar
          // (`js-auto-submit`); el buscador, con `Enter`. Sin JS, el `noscript`
          // da el botón de aplicar. ?>
    <form method="get" action="/admin" class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <div class="input-group input-group-sm app-dash-search">
            <span class="input-group-text app-dash-search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
            <input type="search" name="q" value="<?= View::escape($filtros['q']) ?>" class="form-control form-control-sm"
                   placeholder="Buscar por nombre o email…" aria-label="Buscar usuario">
        </div>
        <select name="rol" class="form-select form-select-sm w-auto js-auto-submit" aria-label="Filtrar por rol">
            <option value="">Todos los roles</option>
            <?php foreach ($roles as $rol): ?>
                <option value="<?= (int) $rol['id'] ?>"<?= $filtros['rol'] === (string) $rol['id'] ? ' selected' : '' ?>>
                    <?= ucfirst(View::escape((string) $rol['name'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="estado" class="form-select form-select-sm w-auto js-auto-submit" aria-label="Filtrar por estado">
            <option value="">Todos los estados</option>
            <option value="activos"<?= $filtros['estado'] === 'activos' ? ' selected' : '' ?>>Activos</option>
            <option value="inactivos"<?= $filtros['estado'] === 'inactivos' ? ' selected' : '' ?>>Inactivos</option>
        </select>
        <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Aplicar</button></noscript>
        <?php if ($hayFiltros): ?>
            <a href="/admin" class="btn btn-sm btn-link text-muted text-decoration-none">
                <i class="fa-solid fa-rotate-left me-1"></i>Quitar
            </a>
        <?php endif; ?>
    </form>

    <?php if (empty($users)): ?>
        <?php if ($hayFiltros): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <div class="app-empty-icon mb-3"><i class="fa-solid fa-magnifying-glass"></i></div>
                    <h4 class="h6 fw-bold">Sin resultados</h4>
                    <p class="text-muted small mb-3">Ningún usuario coincide con tu búsqueda o filtro.</p>
                    <a href="/admin" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-rotate-left me-1"></i>Quitar el filtro</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                    <i class="fa-solid fa-users fa-2x mb-2 d-block"></i>
                    <p class="mb-0">Todavía no hay usuarios.</p>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 app-admin-table">
                    <thead class="table-light">
                        <tr>
                            <th class="small text-muted">Nombre</th>
                            <th class="small text-muted">Email</th>
                            <th class="small text-muted">Rol</th>
                            <th class="small text-muted">Grupos</th>
                            <th class="small text-muted">Permisos</th>
                            <th class="small text-muted">Estado</th>
                            <th class="small text-muted">Registro</th>
                            <th class="text-end small text-muted">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <?php $uid = (int) $user['id']; ?>
                        <?php $myGroups = $userGroups[$uid] ?? []; ?>
                        <?php $myPerms = $userPermissions[$uid] ?? []; ?>
                        <?php $isSelf = $uid === (int) Auth::id(); ?>
                        <?php
                        // El nombre del rol, resuelto una vez: lo usan tanto la
                        // insignia de la columna como el partial que el servidor
                        // devuelve al cambiar el rol por fetch.
                        $roleName = null;
                        foreach ($roles as $r) {
                            if ((int) $r['id'] === (int) $user['role_id']) {
                                $roleName = (string) $r['name'];
                                break;
                            }
                        }
                        ?>
                        <tr data-vf-row>
                            <td class="fw-semibold" data-label="Usuario">
                                <?= View::escape($user['name']) ?>
                                <?php if ($isSelf): ?>
                                    <span class="badge text-bg-light text-muted"><i class="fa-solid fa-user-shield me-1"></i>Tú</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Email"><?= View::escape($user['email']) ?></td>
                            <?php // La columna muestra el VALOR; editarlo vive en el menú
                                  // de la fila (una sola puerta por fila). La insignia
                                  // es un hueco porque el cambio de rol va por fetch. ?>
                            <td data-label="Rol">
                                <span id="vfUserRole<?= $uid ?>">
                                    <?= View::partial('admin/_user_role', ['roleName' => $roleName ?? '—']) ?>
                                </span>
                            </td>
                            <?php // Solo las insignias (el valor). La edición vive en el
                                  // menú de la fila, así que aquí no hay botón: las
                                  // insignias quedan a la vista en vez de esconderse tras
                                  // un desplegable. El partial es el mismo que repone el
                                  // fetch al guardar. ?>
                            <td data-label="Grupos">
                                <span id="vfUserGroups<?= $uid ?>">
                                    <?= View::partial('admin/_user_groups', ['myGroups' => $myGroups]) ?>
                                </span>
                            </td>
                            <?php // Permisos DIRECTOS: la cuarta fuente del RBAC. Se SUMAN a
                                  // rol y grupos, nunca se restan, así que el menú solo
                                  // ofrece «extra» (lo que no viene ya de ellos). Igual que
                                  // grupos: la columna muestra el valor y el partial es el
                                  // que el fetch repone al guardar. ?>
                            <td data-label="Permisos extra">
                                <span id="vfUserPerms<?= $uid ?>">
                                    <?= View::partial('admin/_user_perms', ['myPerms' => $myPerms]) ?>
                                </span>
                            </td>
                            <?php // La insignia de estado. El botón que la cambia vive en
                                  // el menú de la fila; los dos son partials porque
                                  // `is_active` cambia por fetch y el estado OBJETIVO puede
                                  // no ser el inverso del que había (un reintento). ?>
                            <td data-label="Estado">
                                <span id="vfUserStatus<?= $uid ?>">
                                    <?= View::partial('admin/_user_status', ['user' => $user]) ?>
                                </span>
                            </td>
                            <td class="small text-muted" data-label="Registro"><?= View::date($user['created_at']) ?></td>
                            <td class="text-end" data-label="Acciones">
                                <?php if ($isSelf): ?>
                                    <span class="text-muted small" title="No puedes cambiar tu propia cuenta desde aquí.">—</span>
                                <?php else: ?>
                                <?php // UN punto de entrada por fila: rol, grupos, permisos
                                      // extra y activar/desactivar viven aquí en vez de ser
                                      // cuatro controles sueltos por la fila. Cada formulario
                                      // conserva su `method`/`action`/`data-vf-async`, así que
                                      // sin JavaScript se envía igual (recargando).
                                      // `data-bs-auto-close="outside"`: marcar casillas o
                                      // cambiar el rol no cierra el menú a medias. ?>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-secondary app-row-menu-btn"
                                            data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                                            id="rowmenu-<?= $uid ?>" aria-label="Acciones para <?= View::escape($user['name']) ?>">
                                        <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-end p-2 app-row-menu" aria-labelledby="rowmenu-<?= $uid ?>">

                                        <?php // ROL: se envía solo al cambiar (`js-auto-submit`),
                                              // por eso el formulario no lleva botón. ?>
                                        <form method="post" action="/admin/users/role" data-vf-async="slots">
                                            <?= \App\Core\Csrf::field() ?>
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <div class="app-rbac-item-meta text-uppercase fw-bold mb-1">Rol</div>
                                            <label class="visually-hidden" for="role-<?= $uid ?>">Rol de <?= View::escape($user['name']) ?></label>
                                            <select name="role_id" id="role-<?= $uid ?>" class="form-select form-select-sm js-auto-submit">
                                                <?php foreach ($roles as $role): ?>
                                                    <option value="<?= (int) $role['id'] ?>" <?= (int) $user['role_id'] === (int) $role['id'] ? 'selected' : '' ?>><?= ucfirst(View::escape($role['name'])) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>

                                        <?php if ($canEditGroups): ?>
                                            <hr class="my-3">
                                            <?php // `data-vf-async="slots"`: guarda y repone las
                                                  // insignias de la columna (`vfUserGroups<uid>`)
                                                  // sin cerrar el menú ni perder lo marcado. ?>
                                            <form method="post" action="/admin/users/groups" data-vf-async="slots"
                                                  data-vf-error="groupsError<?= $uid ?>">
                                                <?= \App\Core\Csrf::field() ?>
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <div class="app-rbac-item-meta text-uppercase fw-bold mb-1">Grupos de <?= View::escape($user['name']) ?></div>
                                                <?php if ($grupos === []): ?>
                                                    <p class="small text-muted mb-0">No hay grupos creados todavía.</p>
                                                <?php else: ?>
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
                                                <?php endif; ?>
                                                <div class="d-grid gap-2 mt-2">
                                                    <button type="submit" class="btn btn-sm btn-primary">
                                                        <i class="fa-solid fa-floppy-disk me-1"></i>Guardar grupos
                                                    </button>
                                                    <?php // `role="alert"` y con `d-none`: el aviso del
                                                          // `ok:false` se pinta aquí, junto a lo que se
                                                          // está editando, y no flotando donde no se ve. ?>
                                                    <p class="small mb-0 d-none text-danger fw-semibold" id="groupsError<?= $uid ?>" role="alert"></p>
                                                </div>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($canEditPermissions && $permissionCatalog !== []): ?>
                                            <hr class="my-3">
                                            <?php $eff = $userEffective[$uid] ?? ['rol' => [], 'grupos' => [], 'extra' => []]; ?>
                                            <?php // AUDITORÍA: de dónde le viene cada permiso a
                                                  // esta persona. El desglose es lo que hace
                                                  // visible una concesión directa; sin él, un
                                                  // permiso suelto sería indistinguible de uno
                                                  // heredado. ?>
                                            <div class="app-rbac-effect mb-2">
                                                <div class="app-rbac-effect-title">
                                                    <i class="fa-solid fa-bolt me-1"></i>Permisos efectivos
                                                </div>
                                                <?php foreach (['rol' => 'Rol', 'grupos' => 'Grupos', 'extra' => 'Extra'] as $origen => $etiqueta): ?>
                                                    <div class="app-rbac-effect-row">
                                                        <span class="app-rbac-effect-prefix"><?= $etiqueta ?></span>
                                                        <span class="app-rbac-effect-keys">
                                                            <?php if (($eff[$origen] ?? []) === []): ?>
                                                                <span class="text-muted">—</span>
                                                            <?php else: ?>
                                                                <?php foreach ($eff[$origen] as $k): ?>
                                                                    <code><?= View::escape((string) $k) ?></code>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <form method="post" action="/admin/users/permissions" data-vf-async="slots"
                                                  data-vf-error="permsError<?= $uid ?>">
                                                <?= \App\Core\Csrf::field() ?>
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <div class="app-rbac-item-meta text-uppercase fw-bold mb-1">Permisos extra de <?= View::escape($user['name']) ?></div>
                                                <?php
                                                // Agrupados por prefijo («forms.», «admin.»…) para
                                                // que el menú sea legible con el catálogo entero.
                                                $porPrefijo = [];
                                                foreach ($permissionCatalog as $key => $desc) {
                                                    $porPrefijo[explode('.', (string) $key, 2)[0]][(string) $key] = (string) $desc;
                                                }
                                                ksort($porPrefijo);
                                                ?>
                                                <div class="app-rbac-list">
                                                    <?php foreach ($porPrefijo as $prefijo => $claves): ?>
                                                        <div class="app-rbac-item-meta text-uppercase fw-bold px-2 pt-2"><?= View::escape((string) $prefijo) ?></div>
                                                        <?php foreach ($claves as $key => $desc): ?>
                                                            <label class="app-rbac-item" title="<?= View::escape($desc) ?>">
                                                                <input class="form-check-input mt-1" type="checkbox" name="permissions[]" value="<?= View::escape($key) ?>"
                                                                       <?= in_array($key, $myPerms, true) ? 'checked' : '' ?>>
                                                                <span>
                                                                    <span class="app-rbac-item-name"><?= View::escape($key) ?></span>
                                                                    <span class="app-rbac-item-meta"><?= View::escape($desc) ?></span>
                                                                </span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="d-grid gap-2 mt-2">
                                                    <button type="submit" class="btn btn-sm btn-primary">
                                                        <i class="fa-solid fa-floppy-disk me-1"></i>Guardar permisos
                                                    </button>
                                                    <p class="small mb-0 d-none text-danger fw-semibold" id="permsError<?= $uid ?>" role="alert"></p>
                                                </div>
                                            </form>
                                        <?php endif; ?>

                                        <hr class="my-3">
                                        <div class="app-rbac-item-meta text-uppercase fw-bold mb-1">Estado de la cuenta</div>
                                        <?php // El envoltorio ES el hueco (`vfUserToggle<uid>`) y el
                                              // partial pinta el `<form>` de dentro: el JS
                                              // sustituye el `innerHTML` de esta caja, no el del
                                              // formulario (un `<form>` dentro de otro lo descarta
                                              // el parser). ?>
                                        <span id="vfUserToggle<?= $uid ?>" class="d-block">
                                            <?= View::partial('admin/_user_toggle', ['user' => $user, 'uid' => $uid]) ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php // El paginador repone los filtros (el `page` lo pone él): la página
          // vive en la URL, así que el enlace se copia y el «atrás» funciona. ?>
    <?= View::partial('dashboard/_pagination', [
        'listado' => $listado,
        'base'    => '/admin',
        'qs'      => $qsFiltros,
        'sujeto'  => 'usuario',
        'aria'    => 'Paginación de usuarios',
    ]) ?>
    <?php endif; ?>
</div>

<?php // `dashboard.js` con `defer`, igual que en el resto del panel: sus bloques
      // se guardan uno detrás de otro y aquí solo vive el del interruptor. ?>
<script src="<?= View::asset('js/dashboard.js') ?>" defer></script>
