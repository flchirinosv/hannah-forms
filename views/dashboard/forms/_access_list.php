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
 * El aspecto (avatar, fila, vacío) es el del rediseño de la tarjeta de Acceso
 * (2026-10-08), con las clases `vf-access-*` de `app.css`. La fila conserva
 * `data-role="access-link"` en el botón de emitir enlace: lo lee `dashboard.js`
 * para saber a qué correo pertenece.
 *
 * Espera `$accessList` (array), `$accessCount` (int) y `$formId` (int).
 */
use App\Core\View;

$items = $accessList ?? [];
$total = (int) ($accessCount ?? 0);
$formId = (int) ($formId ?? 0);
$inicial = static function (string $email): string {
    $email = trim($email);

    return $email === '' ? '?' : mb_strtoupper(mb_substr($email, 0, 1));
};
?>
<?php if ($total === 0): ?>
    <div class="vf-access-empty" role="alert">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <div>La lista está vacía: ahora mismo <strong>nadie</strong> puede responder a este
        formulario, ni siquiera quien ya tenía un enlace.</div>
    </div>
<?php else: ?>
    <ul class="vf-access-list mb-0">
        <?php foreach ($items as $entry):
            $mail = (string) $entry['email']; ?>
        <li class="vf-access-row">
            <span class="vf-access-avatar" aria-hidden="true"><?= View::escape($inicial($mail)) ?></span>
            <span class="vf-access-mail">
                <span class="vf-access-mail-name"><?= View::escape($mail) ?></span>
            </span>
            <span class="vf-access-row-actions">
                <button type="button" class="btn btn-outline-secondary btn-sm"
                        data-role="access-link" data-email="<?= View::escape($mail) ?>"
                        title="Generar y copiar el enlace de acceso para este correo"
                        aria-label="Generar y copiar el enlace de acceso para <?= View::escape($mail) ?>">
                    <i class="fa-solid fa-link"></i>
                </button>
                <?php // `data-vf-async="slots"`: el borrado va por fetch y la
                      // respuesta trae la lista entera en `html.accessList`
                      // (mismo partial, misma salida que el alta). Y
                      // `data-confirm` va en el FORM, no en el botón: el
                      // delegado de `app.js` lo lee del formulario, y puesto en
                      // el botón la pregunta NUNCA salía. ?>
                <form method="post" action="/forms/<?= $formId ?>/access/email/remove" class="d-inline" data-vf-async="slots"
                      data-confirm="¿Quitar <?= View::escape($mail) ?> de la lista? Los enlaces que ya se le hayan enviado dejarán de funcionar."
                      data-confirm-ok="Quitar">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="email" value="<?= View::escape($mail) ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm"
                            title="Quitar de la lista y revocar sus enlaces"
                            aria-label="Quitar <?= View::escape($mail) ?> de la lista">
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
