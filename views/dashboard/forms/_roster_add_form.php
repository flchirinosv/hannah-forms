<?php

use App\Core\Csrf;
use App\Core\View;

/**
 * FASE 5 — El alta de una persona a mano.
 *
 * ## Los inputs se llaman como las columnas, no como el identificador
 *
 * `cedula`, `indicador` o `correo`, según lo que tenga el formulario, porque es
 * lo que lee `ParticipantController::store()` y lo que trae la plantilla. El
 * campo «correo» **no se pinta cuando el identificador es el correo**: serían
 * dos casillas para lo mismo y el dueño rellenaría una y se quedaría sin la
 * otra, con un `err_correo` en un campo que no ha mirado.
 *
 * ## Un input por atributo del catálogo, y por eso este partial existe
 *
 * Los atributos son columnas que crea la importación. Si el alta viviera en el
 * panel, importing aparecerían atributos nuevos que **su alta no tendría**, y el
 * dueño acabaría rellenando una columna que solo existe si vuelve a la
 * pestaña. Por eso el alta es un hueco (`rosterAddForm`) y la importación lo
 * repone: es el caso en que de verdad cambia lo que el formulario tiene.
 *
 * Variables: `$roster`, `$formId`.
 *
 * @var array $roster
 * @var int $formId
 */
$identificador = (string) $roster['identificador'];
$columnaId = match ($identificador) {
    'indicador' => 'indicador',
    'correo'    => 'correo',
    default     => 'cedula',
};
$rotuloId = match ($identificador) {
    'indicador' => 'Indicador',
    'correo'    => 'Correo',
    default     => 'Cédula',
};
?>
<form method="post" action="/forms/<?= (int) $formId ?>/participants"
      data-vf-async="slots" data-vf-error="rosterAddError" data-vf-clear="rosterAddId">
    <?= Csrf::field() ?>
    <input type="hidden" name="rosterQ" value="<?= View::escape((string) $roster['q']) ?>">
    <input type="hidden" name="rosterPage" value="<?= (int) $roster['page'] ?>">
    <input type="hidden" name="rosterSort" value="<?= View::escape((string) $roster['sort']) ?>">

    <div class="row g-2">
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1" for="rosterAddId"><?= $rotuloId ?></label>
            <input type="<?= $identificador === 'correo' ? 'email' : 'text' ?>" name="<?= $columnaId ?>"
                   id="rosterAddId" class="form-control form-control-sm" maxlength="190" required>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1" for="rosterAddNombre">Nombre (opcional)</label>
            <input type="text" name="nombre" id="rosterAddNombre" class="form-control form-control-sm" maxlength="190">
        </div>
        <?php if ($identificador !== 'correo') { ?>
            <div class="col-12 col-md-4">
                <label class="form-label small mb-1" for="rosterAddCorreo">Correo (opcional)</label>
                <input type="email" name="correo" id="rosterAddCorreo" class="form-control form-control-sm" maxlength="190">
            </div>
        <?php } ?>

        <?php foreach ($roster['atributos'] as $campo) {
            $clave = (string) $campo['key_name']; ?>
            <div class="col-12 col-md-4">
                <label class="form-label small mb-1" for="rosterAddAttr<?= View::escape($clave) ?>">
                    <?= View::escape((string) $campo['label']) ?>
                </label>
                <input type="text" name="data[<?= View::escape($clave) ?>]"
                       id="rosterAddAttr<?= View::escape($clave) ?>"
                       class="form-control form-control-sm" maxlength="255">
            </div>
        <?php } ?>
    </div>

    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">
        <i class="fa-solid fa-plus me-1"></i>Añadir a la lista
    </button>
    <p class="small mb-0 mt-1 d-none text-danger fw-semibold" id="rosterAddError" role="alert"></p>
</form>
