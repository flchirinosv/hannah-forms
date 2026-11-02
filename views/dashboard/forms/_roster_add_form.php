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
<form method="post" action="/forms/<?= $formId ?>/participants" data-vf-modal-form data-vf-async="slots" data-vf-error="rosterAddError" data-vf-clear="rosterAddId">
    <?= Csrf::field() ?>
    <div class="row g-2">
        <div class="col-12">
            <label class="form-label small mb-1" for="rosterAddId"><?= $identificador === 'correo' ? 'Correo' : 'Cédula' ?></label>
            <input type="text" class="form-control form-control-sm" id="rosterAddId" name="<?= View::escape($columnaIdent) ?>" required>
        </div>
        <div class="col-12">
            <label class="form-label small mb-1" for="rosterAddNombre">Nombre completo</label>
            <input type="text" class="form-control form-control-sm" id="rosterAddNombre" name="nombre">
        </div>
        <?php if ($identificador !== FormParticipant::IDENT_CORREO): ?>
            <div class="col-12">
                <label class="form-label small mb-1" for="rosterAddCorreo">Correo</label>
                <input type="email" class="form-control form-control-sm" id="rosterAddCorreo" name="correo">
            </div>
        <?php endif; ?>
        <div class="col-12">
            <label class="form-label small mb-1" for="rosterAddTelefono">Teléfono</label>
            <input type="text" class="form-control form-control-sm" id="rosterAddTelefono" name="telefono">
        </div>
        <?php foreach ($atributos as $atributo): 
            $key = (string) ($atributo['key_name'] ?? '');
            $label = (string) ($atributo['label'] ?? $key);
        ?>
            <div class="col-12">
                <label class="form-label small mb-1" for="rosterAddAttr<?= View::escape($key) ?>"><?= View::escape($label) ?></label>
                <input type="text" class="form-control form-control-sm" id="rosterAddAttr<?= View::escape($key) ?>" name="data[<?= View::escape($key) ?>]">
            </div>
        <?php endforeach; ?>
    </div>
    <div class="d-flex justify-content-end mt-3">
        <button type="submit" class="btn btn-primary app-form-cta">
            <i class="fa-solid fa-plus me-1"></i>Añadir a la lista
        </button>
    </div>
    <p class="small mb-0 mt-2 d-none text-danger fw-semibold" id="rosterAddError" role="alert"></p>
</form>
