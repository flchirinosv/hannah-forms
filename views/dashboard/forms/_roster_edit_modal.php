<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;
use App\Models\FormParticipant;

$roster = is_array($roster ?? null) ? $roster : [];
$formId = (int) ($formId ?? 0);
$identificador = (string) ($roster['identificador'] ?? 'cedula');
$atributos = is_array($roster['atributos'] ?? null) ? $roster['atributos'] : [];
$columnaIdent = FormParticipant::COLUMNA_IDENTIFICADOR[$identificador] ?? 'cedula';
?>
<div class="modal fade" id="rosterEditModal" tabindex="-1" aria-labelledby="rosterEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header app-navbar text-white border-0 py-2">
                <div class="d-flex align-items-center gap-2">
                    <img src="<?= View::brandLogo() ?>" alt="" class="app-logo-sm">
                    <h5 class="modal-title mb-0" id="rosterEditModalLabel">Editar participante</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <form method="post" action="/forms/<?= $formId ?>/participants/update" id="rosterEditForm" data-vf-error="rosterEditError">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="participant_id" id="rosterEditId" value="">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label small mb-1" for="rosterEditIdent"><?= $identificador === 'correo' ? 'Correo' : 'Cédula' ?></label>
                            <input type="text" class="form-control form-control-sm" id="rosterEditIdent" name="<?= View::escape($columnaIdent) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1" for="rosterEditNombre">Nombre completo</label>
                            <input type="text" class="form-control form-control-sm" id="rosterEditNombre" name="nombre">
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1" for="rosterEditCorreo">Correo</label>
                            <input type="email" class="form-control form-control-sm" id="rosterEditCorreo" name="correo">
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1" for="rosterEditTelefono">Teléfono</label>
                            <input type="text" class="form-control form-control-sm" id="rosterEditTelefono" name="telefono">
                        </div>
                        <?php foreach ($atributos as $atributo): 
                            $key = (string) ($atributo['key_name'] ?? '');
                            $label = (string) ($atributo['label'] ?? $key);
                        ?>
                            <div class="col-12">
                                <label class="form-label small mb-1" for="rosterEditAttr<?= View::escape($key) ?>"><?= View::escape($label) ?></label>
                                <input type="text" class="form-control form-control-sm" id="rosterEditAttr<?= View::escape($key) ?>" name="attr_<?= View::escape($key) ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="small mb-0 mt-2 d-none text-danger fw-semibold" id="rosterEditError" role="alert"></p>
                </form>
            </div>
            <div class="modal-footer border-0">
                <form method="post" action="/forms/<?= $formId ?>/participants/delete" data-confirm="¿Quitar este participante de la lista?" data-confirm-tone="danger" class="me-auto">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="participant_id" id="rosterEditDeleteId" value="">
                    <button type="submit" class="btn btn-outline-danger app-form-cta">Quitar</button>
                </form>
                <button type="button" class="btn btn-outline-secondary app-form-cta" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary app-form-cta" form="rosterEditForm">Guardar</button>
            </div>
        </div>
    </div>
</div>
