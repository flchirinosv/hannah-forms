<?php
/**
 * Lista de la allowlist (F4) de la ficha del formulario.
 *
 * Vive en un partial y no en la vista porque se pinta en DOS sitios: en el GET
 * de `/forms/{id}` y en la respuesta JSON de `POST /forms/{id}/access/email`,
 * que devuelve este mismo HTML ya escapado para que el `<ul>` se sustituya sin
 * recargar. Con dos copias, añadir un email por fetch y recargar la página
 * mostraría una lista distinta según por dónde se mirara.
 *
 * Espera `$accessList` (array), `$accessCount` (int) y `$formId` (int).
 */
use App\Core\View;

$items = $accessList ?? [];
$total = (int) ($accessCount ?? 0);
$formId = (int) ($formId ?? 0);
?>
<?php if ($total === 0): ?>
    <div class="alert alert-warning d-flex gap-2 mb-0 py-2 px-3 small" role="alert">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <span>La lista está vacía: ahora mismo <strong>nadie</strong> puede responder a este
        formulario, ni siquiera quien ya tenía un enlace.</span>
    </div>
<?php else: ?>
    <ul class="list-group list-group-flush app-field-list mb-0">
        <?php foreach ($items as $entry): ?>
        <li class="list-group-item d-flex align-items-center justify-content-between px-0">
            <span class="small app-truncate-1"><?= View::escape($entry['email']) ?></span>
            <span class="d-flex gap-1">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        data-role="access-link" data-email="<?= View::escape($entry['email']) ?>"
                        title="Generar y copiar el enlace de acceso para este correo"
                        aria-label="Generar y copiar el enlace de acceso para <?= View::escape($entry['email']) ?>">
                    <i class="fa-solid fa-link"></i>
                </button>
                <?php // `d-inline` es lo que hace que el botón se alinee con
                      // el de al lado: sin él, este <form> es un bloque dentro
                      // del `d-flex` de la fila y el botón se queda arriba con
                      // el interlineado del form debajo, un píxel o dos más
                      // bajo que su hermano. ?>
                <?php // `data-vf-async="slots"`: el borrado va por fetch y la
                      // respuesta trae la lista entera en `html.accessList`
                      // (mismo partial, misma salida que el alta). Y
                      // `data-confirm` va en el FORM, no en el botón: el
                      // delegado de `app.js` lo lee del formulario (como en
                      // `admin/roles.php`), y puesto en el botón la pregunta
                      // NUNCA salía — se quitaba el email y se revocaban sus
                      // enlaces sin confirmar nada, con un `title` que
                      // prometía lo contrario. ?>
                <form method="post" action="/forms/<?= $formId ?>/access/email/remove" class="d-inline" data-vf-async="slots"
                      data-confirm="¿Quitar <?= View::escape($entry['email']) ?> de la lista? Los enlaces que ya se le hayan enviado dejarán de funcionar."
                      data-confirm-ok="Quitar">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="email" value="<?= View::escape($entry['email']) ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm"
                            title="Quitar de la lista y revocar sus enlaces"
                            aria-label="Quitar <?= View::escape($entry['email']) ?> de la lista">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </form>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($total > count($items)): ?>
        <p class="small text-muted mb-0 mt-2">y <?= $total - count($items) ?> más…</p>
    <?php endif; ?>
<?php endif; ?>
