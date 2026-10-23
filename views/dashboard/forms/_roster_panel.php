<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;
use App\Models\FormParticipant;
use App\Services\RosterTemplate;

$formId = (int) ($form['id'] ?? 0);
$roster = is_array($roster ?? null) ? $roster : [];
$informe = is_array($informe ?? null) ? $informe : null;
$importMapping = is_array($importMapping ?? null) ? $importMapping : null;
$identificador = (string) ($roster['identificador'] ?? 'cedula');
?>
<div id="participantes">
    <div class="row g-4">
        <div class="col-12">
            <div id="rosterBoardCard" class="card shadow-sm mb-0">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                        <div>
                            <h2 class="h5 mb-1">Acceso por listado</h2>
                            <p class="text-muted mb-0">Gestiona el padrón, importa un archivo y reparte los códigos.</p>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-12" id="rosterStats">
                            <?= View::partial('dashboard/forms/_roster_stats', ['roster' => $roster]) ?>
                        </div>
                        <div class="col-12" id="rosterInvites">
                            <?= View::partial('dashboard/forms/_roster_invites', ['roster' => $roster, 'formId' => $formId]) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div id="rosterListCard" class="card shadow-sm">
                <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h2 class="h6 mb-0">Listado</h2>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#rosterAddModal">
                            <i class="fa-solid fa-user-plus me-1"></i>Agregar
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <form class="mb-3" data-vf-roster-search action="/forms/<?= $formId ?>/access" method="get">
                        <div class="row g-2 align-items-center">
                            <div class="col-12 col-lg-6">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                                    <input type="search" class="form-control" name="q" value="<?= View::escape((string) $roster['q']) ?>" placeholder="Buscar por cédula, nombre, correo o teléfono">
                                    <button class="btn btn-outline-secondary" type="submit">Buscar</button>
                                </div>
                            </div>
                            <div class="col-6 col-lg-3">
                                <select class="form-select form-select-sm js-auto-submit" name="sort">
                                    <option value="cedula" <?= $roster['sort'] === 'cedula' ? 'selected' : '' ?>>Ordenar por cédula</option>
                                    <option value="nombre" <?= $roster['sort'] === 'nombre' ? 'selected' : '' ?>>Ordenar por nombre</option>
                                    <option value="correo" <?= $roster['sort'] === 'correo' ? 'selected' : '' ?>>Ordenar por correo</option>
                                    <option value="telefono" <?= $roster['sort'] === 'telefono' ? 'selected' : '' ?>>Ordenar por teléfono</option>
                                    <option value="creado" <?= $roster['sort'] === 'creado' ? 'selected' : '' ?>>Ordenar por fecha</option>
                                </select>
                            </div>
                        </div>
                    </form>
                    <div id="rosterList">
                        <?= View::partial('dashboard/forms/_roster_list', ['roster' => $roster, 'formId' => $formId]) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div id="rosterImportCard" class="card shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Importar / Exportar</h2>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-lg-7">
                            <details class="vf-import-details" <?= ($informe !== null || $importMapping !== null) ? 'open' : '' ?>>
                                <summary class="vf-import-summary">
                                    <span class="fw-semibold">Subir un archivo</span>
                                    <span class="text-muted small">Importar o actualizar participantes desde .xlsx</span>
                                </summary>
                                <div class="vf-import-body">
                                    <a class="btn btn-outline-secondary btn-sm mb-3" href="/forms/<?= $formId ?>/participants/template">
                                        <i class="fa-solid fa-file-arrow-down me-1"></i>Descargar la plantilla
                                    </a>
                                    <p class="small text-muted mb-2">Plantilla con las columnas: <strong>Cédula | Nombre completo | Correo | Teléfono</strong>. El código se añade al exportar para repartirlo.</p>

                                    <form method="post" action="/forms/<?= $formId ?>/participants/import" enctype="multipart/form-data"
                                          class="mb-3" data-vf-async="slots" data-vf-error="rosterImportError">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="rosterQ" value="<?= View::escape((string) $roster['q']) ?>">
                                        <input type="hidden" name="rosterPage" value="<?= (int) $roster['page'] ?>">
                                        <input type="hidden" name="rosterSort" value="<?= View::escape((string) $roster['sort']) ?>">

                                        <div class="mb-2">
                                            <label class="form-label small mb-1" for="rosterArchivo">Archivo</label>
                                            <input type="file" name="archivo" id="rosterArchivo" accept=".xlsx" required class="form-control form-control-sm">
                                        </div>

                                        <fieldset class="mb-2">
                                            <legend class="form-label small mb-1">Si alguien ya está en la lista</legend>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="modo" value="fusionar" id="rosterModoFusionar" checked>
                                                <label class="form-check-label small" for="rosterModoFusionar">Actualizarlo con lo que dice el archivo y añadir al que no esté</label>
                                            </div>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="modo" value="reemplazar" id="rosterModoReemplazar">
                                                <label class="form-check-label small" for="rosterModoReemplazar">Borrar la lista entera y dejar solo lo que dice el archivo</label>
                                            </div>
                                        </fieldset>

                                        <button type="submit" class="btn btn-outline-primary app-form-cta">
                                            <i class="fa-solid fa-upload me-1"></i>Subir y mapear columnas
                                        </button>
                                        <p class="small mb-0 mt-1 d-none text-danger fw-semibold" id="rosterImportError" role="alert"></p>
                                    </form>

                                    <div id="rosterImportReport">
                                        <?php if (is_array($importMapping) && !empty($importMapping['path']) && is_file($importMapping['path'])): ?>
                                            <?= View::partial('dashboard/forms/_roster_import_mapping', ['importMapping' => $importMapping, 'formId' => $formId, 'roster' => $roster]) ?>
                                        <?php else: ?>
                                            <?= View::partial('dashboard/forms/_roster_import_report', ['informe' => $informe]) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </details>
                        </div>
                        <div class="col-lg-5">
                            <?= View::partial('dashboard/forms/_roster_catalog', ['roster' => $roster, 'formId' => $formId]) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="vf-import-danger">
        <hr class="my-0">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pt-3">
            <p class="small text-muted mb-0">
                <strong>Vaciar la lista</strong> quita a todas las personas. El catálogo de atributos se queda.
            </p>
            <form method="post" action="/forms/<?= $formId ?>/participants/truncate" data-confirm="¿Vaciar toda la lista de participantes? Esta acción no se puede deshacer." data-confirm-tone="danger">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-outline-danger app-form-cta">
                    <i class="fa-solid fa-trash-can me-1"></i>Vaciar la lista
                </button>
            </form>
        </div>
    </div>
</div>

<?= View::partial('dashboard/forms/_roster_add_modal', ['formId' => $formId, 'roster' => $roster]) ?>
<?= View::partial('dashboard/forms/_roster_edit_modal', ['formId' => $formId, 'roster' => $roster]) ?>
