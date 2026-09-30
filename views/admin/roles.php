<?php
/**
 * Matriz de permisos por rol.
 *
 * Los permisos van agrupados por prefijo (admin.*, forms.*, responses.*...) con
 * una cabecera por grupo: la lista plana de 30 claves强迫aba a recorrerla entera
 * para encontrar una sola, y lo que se quiere es «darle a este rol lo de
 * formularios», que son cinco casillas contiguas.
 *
 * Cada columna empieza con un checkbox de "todo" por sección: sin él, marcar
 * veinte permisos son veinte clics, y ese es exactamente el trabajo que hace un
 * admin al crear un rol nuevo.
 */
use App\Core\View;

/** Prefijo -> rótulo legible, para no dejar el nombre crudo como único guía. */
$sectionLabels = [
    'admin'      => 'Administración',
    'forms'      => 'Formularios',
    'responses'  => 'Respuestas',
    'analytics'  => 'Analítica',
    'attachments'=> 'Adjuntos',
    'logs'       => 'Log de seguridad',
    'webhooks'   => 'Webhooks',
];
$sectionIcons = [
    'admin'       => 'fa-user-shield',
    'forms'       => 'fa-clipboard-list',
    'responses'   => 'fa-inbox',
    'analytics'   => 'fa-chart-simple',
    'attachments' => 'fa-paperclip',
    'logs'        => 'fa-shield-halved',
    'webhooks'    => 'fa-bolt',
];

$sections = [];
foreach ($permissions as $key => $description) {
    $sections[explode('.', (string) $key)[0]][(string) $key] = $description;
}

// Un prefijo que no esté en la lista de arriba se muestra con su propio
// nombre. OJO: aquí NO se fusiona `$sections` en `$sectionLabels` (con `+=`
// acabaría un ARRAY como valor y `escape()` lo volcaría como
// «Array to string conversion» en medio de la matriz).
$sectionTitle = static fn(string $prefix): string => (string) ($sectionLabels[$prefix] ?? $prefix);
?>
<div class="container-fluid px-lg-4">
    <?= View::partial('admin/_nav', ['active' => 'roles']) ?>

    <div class="mb-4">
        <h2 class="h4 fw-bold mb-1"><i class="fa-solid fa-key me-2 text-primary"></i>Permisos por rol</h2>
        <p class="text-muted small mb-0">
            Cada rol tiene una lista de permisos; cada persona hereda los de su rol
            <strong>y</strong> los de los roles de sus <a href="/admin/groups">grupos</a>. Los permisos se suman,
            nunca se restan: nadie pierde lo que su rol propio le da.
        </p>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6 fw-bold mb-3"><i class="fa-solid fa-plus me-1 text-primary"></i>Nuevo rol</h3>
                    <form method="post" action="/admin/roles/create">
                        <?= \App\Core\Csrf::field() ?>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="roleName">Nombre</label>
                            <input type="text" class="form-control form-control-sm" id="roleName" name="name"
                                   required maxlength="50" placeholder="revisor">
                            <div class="form-text">
                                Sin espacios: letras, números, <code>.</code>, <code>-</code> y <code>_</code>. Se guarda en minúsculas.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="roleDesc">Descripción</label>
                            <input type="text" class="form-control form-control-sm" id="roleDesc" name="description"
                                   maxlength="255" placeholder="Para qué existe este rol">
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary w-100">
                            <i class="fa-solid fa-plus me-1"></i>Crear rol
                        </button>
                    </form>
                    <p class="form-text mt-2 mb-0">
                        Nace <strong>sin ningún permiso</strong>: se le marcan abajo en la matriz. Un rol recién
                        creado no hace nada hasta entonces.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <form method="post" action="/admin/roles/save">
                <?= \App\Core\Csrf::field() ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 app-rbac-matrix">
                                <thead class="table-light">
                                    <tr>
                                        <th class="px-4 small text-muted" style="min-width:240px;">Permiso</th>
                                        <?php foreach ($roles as $role): ?>
                                            <th class="small text-center">
                                                <div class="fw-semibold text-body text-capitalize"><?= View::escape($role['name']) ?></div>
                                                <div class="text-muted fw-normal app-rbac-count">
                                                    <?= count($role['permissions']) ?>/<?= count($permissions) ?>
                                                </div>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <?php foreach ($sections as $prefix => $keys): ?>
                                    <tbody>
                                        <tr class="app-rbac-section">
                                            <th colspan="<?= count($roles) + 1 ?>" class="px-4">
                                                <i class="fa-solid <?= $sectionIcons[$prefix] ?? 'fa-circle' ?> me-2"></i>
                                                <?= View::escape($sectionTitle($prefix)) ?>
                                                <span class="text-muted fw-normal small">· <?= count($keys) ?></span>
                                                <?php if (count($roles) > 0): ?>
                                                    <span class="app-rbac-bulk">
                                                        <label class="form-check-label small fw-normal">
                                                            <input class="form-check-input" type="checkbox"
                                                                   data-vf-bulk-all="<?= View::escape($prefix) ?>"
                                                                   aria-label="Marcar toda la sección <?= View::escape($sectionTitle($prefix)) ?>">
                                                            Marcar toda la sección
                                                        </label>
                                                    </span>
                                                <?php endif; ?>
                                            </th>
                                        </tr>
                                        <?php foreach ($keys as $key => $description): ?>
                                            <tr>
                                                <td class="px-4">
                                                    <div class="fw-semibold small text-monospace"><?= View::escape($key) ?></div>
                                                    <div class="small text-muted"><?= View::escape($description) ?></div>
                                                </td>
                                                <?php foreach ($roles as $role): ?>
                                                    <?php $rid = (int) $role['id']; ?>
                                                    <?php
                                                    // Misma regla que `updateRoleMatrix()`: el rol
                                                    // admin conserva `admin.access` o el panel se
                                                    // queda sin quien lo pueda arreglar. Se marca
                                                    // siempre en la UI porque una casilla
                                                    // deshabilitada sin marcar sería una mentira
                                                    // (no se puede pulsar para corregirla).
                                                    $locked = strtolower((string) $role['name']) === 'admin' && $key === 'admin.access';
                                                    $checked = $locked || in_array($key, $role['permissions'], true);
                                                    ?>
                                                    <td class="text-center">
                                                        <input class="form-check-input js-perm" type="checkbox"
                                                               data-vf-section="<?= View::escape($prefix) ?>"
                                                               name="perms[<?= $rid ?>][]" value="<?= View::escape($key) ?>"
                                                               <?= $checked ? 'checked' : '' ?>
                                                               <?= $locked ? 'disabled title="El rol admin siempre conserva admin.access"' : '' ?>>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent border-0 d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <span class="small text-muted">
                            Un rol sin permisos deja a sus usuarios con lo que les dé su grupo o su rol propio.
                        </span>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-floppy-disk me-1"></i>Guardar matriz
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
