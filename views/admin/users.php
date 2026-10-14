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
        'actions'  => '<a href="/admin/forms" class="btn btn-outline-secondary me-2"><i class="fa-solid fa-clipboard-list me-1"></i>Ver todos los formularios</a>'
            . '<button type="button" class="btn btn-primary app-btn-primary" data-bs-toggle="modal" data-bs-target="#newUserModal">'
            . '<i class="fa-solid fa-user-plus me-1"></i>Nuevo usuario</button>',
    ]) ?>

    <?php // El alta se sirve EN LÍNEA (camino sin JS) y `initAdminModals()` la
          // mueve al `#newUserModal` cuando puede. Es la ÚNICA forma de crear un
          // `user` o un `editor` con el registro público cerrado (el default), así
          // que sin JS tiene que poder usarse tal cual: el contenedor se oculta
          // solo después de haber movido el formulario al diálogo. ?>
    <div class="row g-3 mb-3" id="newUserFormMount" data-vf-modal-move="#newUserModal">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6 fw-bold mb-1"><i class="fa-solid fa-user-plus me-1 text-primary"></i>Nuevo usuario</h3>
                    <?php // El encabezado se queda en la tarjeta (el modal trae el suyo) y
                          // esto —la nota y el formulario— es lo que viaja al diálogo. ?>
                    <div data-vf-modal-form>
                    <p class="text-muted small mb-3">
                        Crea una cuenta con su rol desde aquí. Funciona con el registro público abierto o
                        cerrado: eso solo controla el alta desde <code>/register</code>.
                    </p>
                    <form method="post" action="/admin/users/create">
                        <?= Csrf::field() ?>
                        <?php // Una sola columna: el alta tiene cinco campos y la
                              // pila vertical deja cada uno a todo el ancho del
                              // diálogo, que es donde el marcador de posición de la
                              // contraseña ya no se corta. ?>
                        <div class="row g-3 align-items-end">
                            <div class="col-12">
                                <label for="newUserName" class="form-label small fw-semibold">Nombre</label>
                                <input type="text" class="form-control form-control-sm" id="newUserName" name="name" placeholder="Ana Ruiz" maxlength="100" required>
                            </div>
                            <div class="col-12">
                                <label for="newUserEmail" class="form-label small fw-semibold">Email</label>
                                <input type="email" class="form-control form-control-sm" id="newUserEmail" name="email" placeholder="ana@ejemplo.com" maxlength="190" required>
                            </div>
                            <div class="col-12">
                                <label for="newUserPassword" class="form-label small fw-semibold">Contraseña</label>
                                <input type="password" class="form-control form-control-sm" id="newUserPassword" name="password" placeholder="Mínimo <?= (int) $min ?> caracteres" minlength="<?= (int) $min ?>" autocomplete="new-password" required>
                            </div>
                            <div class="col-12">
                                <label for="newUserPasswordConfirm" class="form-label small fw-semibold">Repetir contraseña</label>
                                <input type="password" class="form-control form-control-sm" id="newUserPasswordConfirm" name="password_confirm" minlength="<?= (int) $min ?>" autocomplete="new-password" required>
                                <div class="form-text">Si no coinciden, el alta se rechaza.</div>
                            </div>
                            <div class="col-12">
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
                </div>
            </div>
        </div>
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
                                      // extra y activar/desactivar viven en un modal, no en
                                      // cuatro controles sueltos por la fila. El `⋯` solo
                                      // DISPARA: `initTablaAcciones()` mueve el panel de ESTA
                                      // fila al `#userActionsModal` al abrir y lo devuelve al
                                      // cerrar, así que los formularios, los `id` de los huecos
                                      // y los avisos siguen siendo los de esta fila y no hay un
                                      // modal por persona (sería clonar el panel N veces). Cada
                                      // formulario conserva su `method`/`action`/`data-vf-async`. ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary app-row-menu-btn"
                                        data-vf-user="<?= $uid ?>" data-vf-user-name="<?= View::escape($user['name']) ?>"
                                        aria-label="Acciones para <?= View::escape($user['name']) ?>">
                                    <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
                                </button>
                                <?php // El panel se sirve EN LA FILA y oculto: el JS lo mueve al
                                      // modal. El `<span hidden>` no desactiva los formularios de
                                      // dentro (solo los deja sin pintar), así que el mismo nodo
                                      // vale para las dos superficies. ?>
                                <span id="rowactions-<?= $uid ?>" hidden>
                                    <div class="p-2 app-row-menu">
                                        <?php // Tres columnas, una por fuente de permisos: a la
                                              // IZQUIERDA la auditoría (de dónde le viene cada
                                              // permiso, de solo lectura), en MEDIO la identidad
                                              // (rol y grupos, que cambian el conjunto heredado) y
                                              // a la DERECHA la concesión directa. Así la columna
                                              // del medio es la que explica a las otras dos. ?>
                                        <div class="row g-3">
                                            <?php $tieneEfectivos = $canEditPermissions && $permissionCatalog !== []; ?>
                                            <?php if ($tieneEfectivos): ?>
                                                <?php $eff = $userEffective[$uid] ?? ['rol' => [], 'grupos' => [], 'extra' => []]; ?>
                                                <?php // AUDITORÍA: de dónde le viene cada permiso a
                                                      // esta persona. El desglose es lo que hace
                                                      // visible una concesión directa; sin él, un
                                                      // permiso suelto sería indistinguible de uno
                                                      // heredado. ?>
                                                <div class="col-12 col-lg-4">
                                                    <div class="app-rbac-effect">
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
                                                </div>
                                            <?php endif; ?>

                                            <div class="col-12 <?= $tieneEfectivos ? 'col-lg-4' : 'col-lg-6' ?>">
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
                                            </div>

                                        <?php if ($tieneEfectivos): ?>
                                            <div class="col-12 col-lg-4">
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
                                            </div>
                                        <?php endif; ?>
                                        </div>

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

<?php // Modales de la pantalla. Van a nivel superior del fragmento (fuera de
      // `.container-fluid`), como exige `ViewStructureTest`: un `.modal` dentro de
      // otro elemento oculto no se puede ni abrir ni cerrar.
      //
      // Los dos reutilizan el marcado servido en línea: `initAdminModals()` mueve
      // el formulario de alta a `#newUserModal` y el panel de la fila a
      // `#userActionsModal`. Aquí no hay ningún campo, solo los cuerpos vacíos. ?>
<div class="modal fade" id="newUserModal" tabindex="-1" aria-labelledby="newUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0">
            <div class="modal-header app-navbar text-white border-0 py-2">
                <div class="d-flex align-items-center gap-2 text-white">
                    <span class="app-logo-sm"><i class="fa-solid fa-user-plus"></i></span>
                    <span class="fw-semibold" id="newUserModalLabel">Nuevo usuario</span>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-4" data-vf-modal-body></div>
        </div>
    </div>
</div>

<div class="modal fade" id="userActionsModal" tabindex="-1" aria-labelledby="userActionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0">
            <div class="modal-header app-navbar text-white border-0 py-2">
                <div class="d-flex align-items-center gap-2 text-white">
                    <span class="app-logo-sm"><i class="fa-solid fa-user-gear"></i></span>
                    <span class="fw-semibold" id="userActionsModalLabel">Acciones de <span data-vf-modal-title></span></span>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-2" data-vf-modal-body></div>
        </div>
    </div>
</div>

<?php // `dashboard.js` con `defer`, igual que en el resto del panel: aquí viven el
      // interruptor de registro (en `/admin/settings`), el plegado de grupos y el
      // traslado de los formularios a los modales de esta pantalla. ?>
<script src="<?= View::asset('js/dashboard.js') ?>" defer></script>
