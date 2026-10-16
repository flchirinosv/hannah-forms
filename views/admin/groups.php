<?php
/**
 * Grupos de roles (RBAC).
 *
 * Un grupo es un contenedor de ROLES y USUARIOS: sus miembros heredan los
 * permisos de los roles del grupo, además de los de su propio rol.
 *
 * Decisiones de UI que no son estéticas:
 * - Los roles y los miembros son **checkboxes**, no `<select multiple>`: un
 *   multiselect obliga a mantener pulsado Ctrl para elegir más de uno, y no
 *   hay nada en la pantalla que lo diga. Aquí se marca y se guarda.
 * - Cada tarjeta es un grupo **plegable** (cabecera-resumen + cuerpo) y su
 *   estado lo recuerda `dashboard.js` por pantalla. El panel llega ABIERTO del
 *   servidor: plegar es una comodidad con JS, nunca la única forma de ver los
 *   roles y los miembros.
 * - El alta vive en un modal, pero el formulario se sirve EN LÍNEA y el JS lo
 *   mueve al diálogo al arrancar (ver `initAdminModals` en `dashboard.js`): no
 *   hay dos copias del formulario, y sin JS sigue estando a la vista.
 * - Se pinta el **efecto** (los permisos que otorga el grupo) en el cuerpo, no
 *   solo el cableado: la pregunta de quien administra es qué pasa si saco a
 *   esta persona del grupo, no qué roles tiene el grupo.
 * - El buscador de miembros es de **cliente** y solo ESCONDE filas: la
 *   pertenencia es el POST completo (`users[]`), así que esconder a alguien no
 *   puede desmarcarlo — filtrar en servidor lo sacaría del grupo al guardar.
 *
 * OJO: este formulario tiene que mandar `name` y `description`. Un POST sin
 * ellos se interpreta como "no los toques" (ver AdminController::groupUpdate),
 * pero mandarlos mantiene el alta y la edición en el mismo camino.
 */
use App\Core\View;
?>
<div class="container-fluid px-lg-4">
    <?= View::partial('admin/_page_head', [
        'active'   => 'groups',
        'icon'     => 'fa-layer-group',
        'title'    => 'Grupos de roles',
        'subtitle' => 'Un grupo reúne usuarios y roles. Quien está en el grupo hereda los permisos de esos'
            . ' roles <strong>además</strong> de los de su propio rol: es la forma de dar a un equipo una'
            . ' capacidad extra (operar formularios de cualquiera, revisar, ver el log) sin cambiarle el rol a nadie.',
        'actions'  => '<button type="button" class="btn btn-primary app-btn-primary" data-bs-toggle="modal" data-bs-target="#newGroupModal">'
            . '<i class="fa-solid fa-plus me-1"></i>Nuevo grupo</button>',
    ]) ?>

    <?php // El formulario de alta se sirve EN LÍNEA (camino sin JS) y
          // `initAdminModals()` lo mueve al `#newGroupModal` en cuanto puede. El
          // contenedor entero se oculta tras moverlo, así que de lo que se ve
          // sin JS a lo que se ve con JS no hay markup duplicado: es el mismo
          // nodo en otro sitio. ?>
    <div class="row g-3 mb-3" id="newGroupFormMount" data-vf-modal-move="#newGroupModal">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6 fw-bold mb-3"><i class="fa-solid fa-plus me-1 text-primary"></i>Nuevo grupo</h3>
                    <form method="post" action="/admin/groups/create" class="row g-3" data-vf-modal-form>
                        <?= \App\Core\Csrf::field() ?>
                        <div class="col-12">
                            <p class="form-text mt-0 mb-1">
                                Se crea vacío. En cuanto exista aparece su tarjeta y ahí se le marcan los roles y los miembros.
                            </p>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="groupName">Nombre</label>
                            <input type="text" class="form-control form-control-sm" id="groupName" name="name" required maxlength="80"
                                   placeholder="Soporte">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="groupDesc">Descripción</label>
                            <textarea class="form-control form-control-sm" id="groupDesc" name="description" maxlength="255"
                                      rows="3" placeholder="Quiénes son y por qué existen"></textarea>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary app-btn-primary app-btn-mobile-icon">
                                <i class="fa-solid fa-plus me-1"></i>Crear
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($groups)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5 text-muted">
                <i class="fa-solid fa-layer-group fa-2x mb-2 d-block"></i>
                <p class="mb-0">Sin grupos todavía. Créalo arriba y asígnale roles.</p>
            </div>
        </div>
    <?php else: ?>
        <?php // Un solo contenedor de grupos: `dashboard.js` recuerda el plegado
              // de cada tarjeta bajo una clave de PANTALLA (`vf:grp:admin-grupos`),
              // no una por grupo, para que reordenar o añadir grupos no haga
              // que el estado guardado apunte a otro. ?>
        <div class="d-flex flex-column gap-3" data-vf-group-key="admin-grupos">
            <?php foreach ($groups as $group): ?>
            <?php
            $gid = (int) $group['id'];
            $gRoles = $groupRoles[$gid] ?? [];
            $gMembers = $groupMembers[$gid] ?? [];
            $gPerms = $groupPerms[$gid] ?? [];
            $panelId = 'grp-grupo-' . $gid;
            ?>
            <div class="card border-0 shadow-sm" data-vf-row>
                <div class="card-body pb-2">
                    <div id="vfGroupSummary<?= $gid ?>">
                        <?= View::partial('admin/_group_summary', [
                            'groupId'     => $gid,
                            'groupName'   => (string) $group['name'],
                            'groupDesc'   => (string) ($group['description'] ?? ''),
                            'roleCount'   => count($gRoles),
                            'memberCount' => count($gMembers),
                        ]) ?>
                    </div>
                </div>

                <div class="collapse show" id="<?= $panelId ?>">
                    <div class="card-body pt-0">
                        <form method="post" action="/admin/groups/update" class="row g-3 align-items-start"
                              data-vf-async="slots">
                            <?= \App\Core\Csrf::field() ?>
                            <input type="hidden" name="group_id" value="<?= $gid ?>">

                            <div class="col-lg-6">
                                <label class="form-label small text-muted mb-1" for="gname-<?= $gid ?>">Nombre</label>
                                <input type="text" class="form-control form-control-sm" id="gname-<?= $gid ?>" name="name"
                                       value="<?= View::escape($group['name']) ?>" maxlength="80">

                                <label class="form-label small text-muted mb-1 mt-3" for="gdesc-<?= $gid ?>">Descripción</label>
                                <textarea class="form-control form-control-sm" id="gdesc-<?= $gid ?>" name="description"
                                          maxlength="255" rows="3"><?= View::escape($group['description'] ?? '') ?></textarea>
                            </div>

                            <?php // Los ROLES del grupo van junto al nombre (arriba a la
                                  // derecha): son lo que se edita. El efecto —lo que el
                                  // grupo otorga— baja debajo, con los miembros, porque es
                                  // la consecuencia de lo marcado, no su control. ?>
                            <div class="col-lg-6">
                                <label class="form-label small text-muted mb-1" for="groles-<?= $gid ?>">
                                    Roles del grupo
                                </label>
                                <div class="app-rbac-list" id="groles-<?= $gid ?>" role="group"
                                     aria-label="Roles del grupo <?= View::escape($group['name']) ?>">
                                    <?php foreach ($roles as $role): ?>
                                        <?php $rid = (int) $role['id']; ?>
                                        <label class="app-rbac-item app-rbac-item-line">
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

                            <?php // El efecto baja aquí: es la CONSECUENCIA de marcar roles,
                                  // no un control. Quien administra pregunta «¿qué gana
                                  // alguien por estar aquí?», y eso se responde al lado de
                                  // lo que acaba de marcar. Es el mismo hueco que repinta
                                  // `groupUpdate()` (`vfGroupEffect<gid>`). ?>
                            <div class="col-lg-6">
                                <div id="vfGroupEffect<?= $gid ?>">
                                    <?= View::partial('admin/_group_effect', ['gPerms' => $gPerms]) ?>
                                </div>
                            </div>

                            <div class="col-lg-6" data-vf-list>
                                <label class="form-label small text-muted mb-1" for="gusers-search-<?= $gid ?>">
                                    Miembros
                                </label>
                                <?php if (!empty($users)): ?>
                                    <input type="search" class="form-control form-control-sm mb-2"
                                           id="gusers-search-<?= $gid ?>" data-vf-filter
                                           placeholder="Buscar por nombre o correo"
                                           aria-label="Buscar miembro del grupo <?= View::escape($group['name']) ?>">
                                <?php endif; ?>
                                <div class="app-rbac-list" id="gusers-<?= $gid ?>" role="group"
                                     aria-label="Miembros del grupo <?= View::escape($group['name']) ?>">
                                    <?php if (empty($users)): ?>
                                        <p class="small text-muted mb-0 px-2 py-2">No hay usuarios en la instalación.</p>
                                    <?php else: ?>
                                        <p class="app-filter-empty small text-muted mb-0 px-2 py-2" hidden>
                                            Ningún miembro coincide con la búsqueda.
                                        </p>
                                        <?php foreach ($users as $member): ?>
                                            <?php $mid = (int) $member['id']; ?>
                                            <label class="app-rbac-item app-rbac-item-line">
                                                <input class="form-check-input mt-1" type="checkbox"
                                                       name="users[]" value="<?= $mid ?>"
                                                       <?= in_array($mid, $gMembers, true) ? 'checked' : '' ?>>
                                                <span>
                                                    <span class="app-rbac-item-name"><?= View::escape($member['name']) ?></span>
                                                    <span class="app-rbac-item-meta"><?= View::escape($member['email']) ?></span>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-12 d-flex justify-content-end">
                                <button type="submit" class="btn btn-outline-success app-form-cta">
                                    <i class="fa-solid fa-floppy-disk me-1"></i>Guardar grupo
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php // Modal del alta. Su cuerpo se rellena con `initAdminModals()` moviendo el
      // formulario servido en línea; aquí no hay campos. Va a nivel superior del
      // fragmento (fuera de `.container-fluid`), como exige `ViewStructureTest`:
      // un `.modal` dentro de otro elemento oculto no se puede ni abrir ni cerrar. ?>
<div class="modal fade" id="newGroupModal" tabindex="-1" aria-labelledby="newGroupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header app-navbar text-white border-0 py-2">
                <div class="d-flex align-items-center gap-2 text-white">
                    <span class="app-logo-sm"><i class="fa-solid fa-layer-group"></i></span>
                    <span class="fw-semibold" id="newGroupModalLabel">Nuevo grupo</span>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-4" data-vf-modal-body></div>
        </div>
    </div>
</div>

<?php // `dashboard.js` con `defer`, igual que en el resto del panel: aquí viven el
      // plegado de las tarjetas y el traslado del alta al modal. ?>
<script src="<?= View::asset('js/dashboard.js') ?>" defer></script>
