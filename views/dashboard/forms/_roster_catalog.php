<?php

use App\Core\Csrf;
use App\Core\View;

/**
 * FASE 5 — El catálogo de atributos.
 *
 * ## Lo pinta la importación, y quitarlos es lo que hay que poder hacer
 *
 * Un atributo es una columna del `.xlsx` del dueño. Si el archivo trae una columna
 * que no esperaba, se crea el atributo (es lo que hace el listado, no este
 * partial), y a veces se equivoca: una columna «Sede Norte» que era una prueba.
 * Quitarlo borra sus valores de **todos** los participantes, y por eso el botón
 * pregunta antes y el diálogo lo dice con esas palabras.
 *
 * ## Vaciar la lista NO borra el catálogo
 *
 * `FormParticipant::truncate()` quita filas y deja las columnas: vaciar destruye
 * la gente, y las columnas son el formato del próximo archivo. Es la razón de que
 * «Vaciar» y «Quitar atributo» sean dos botones con dos diálogos distintos.
 *
 * ## No hay forma de CREAR un atributo desde aquí, y es una decisión
 *
 * Un atributo nace de una columna del archivo, que es de donde salían los datos
 * que lo rellenan. Crear una columna vacía «a mano» produciría un atributo que
 * nunca tiene valor y una analítica con un filtro que siempre sale vacío.
 *
 * Variables: `$roster`, `$formId`.
 *
 * @var array $roster
 * @var int $formId
 */
?>
<div id="rosterCatalog">
    <h4 class="h6 small fw-bold mb-1">
        <i class="fa-solid fa-table-columns me-1"></i>Atributos
    </h4>

    <?php if ($roster['atributos'] === []) { ?>
        <p class="small text-muted mb-2">
            No hay atributos. Salen de las columnas del archivo que subas, y se usan
            para filtrar y agrupar las respuestas.
        </p>
    <?php } else { ?>
        <ul class="list-group list-group-flush mb-2">
            <?php foreach ($roster['atributos'] as $campo) {
                $clave = (string) $campo['key_name']; ?>
                <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                    <span class="small">
                        <?= View::escape((string) $campo['label']) ?>
                        <code class="small text-muted"><?= View::escape($clave) ?></code>
                    </span>
                    <form method="post" action="/forms/<?= (int) $formId ?>/participants/attribute"
                          class="d-inline-block" data-vf-async="slots"
                          data-confirm="¿Quitar el atributo «<?= View::escape((string) $campo['label']) ?>»? Se borra su valor en todas las personas de la lista y no se puede deshacer."
                          data-confirm-ok="Sí, quitar el atributo" data-confirm-tone="danger">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="key_name" value="<?= View::escape($clave) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Quitar el atributo <?= View::escape((string) $campo['label']) ?>">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </form>
                </li>
            <?php } ?>
        </ul>
    <?php } ?>
</div>
