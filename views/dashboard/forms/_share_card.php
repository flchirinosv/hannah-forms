<?php

use App\Core\View;
/**
 * «Enlace para compartir» de la ficha: enlace, QR y embed… o el botón de
 * publicar, según el estado.
 *
 * Es un partial porque TODO el bloque cuelga de `forms.status`: con el
 * formulario publicado tiene enlace, QR y código de embed; cerrado o en
 * borrador, un aviso y «Publicar ahora». Dejándolo en la vista, cerrar el
 * formulario sin recarga dejaba el QR de un formulario que ya no aceptaba
 * respuestas (y el `GET /f/{slug}` cortando con 403).
 *
 * Variables: `$form`, `$settings`, `$shareUrl`, `$embedUrl`,
 * `$publicUrlIsLocal`.
 */
$settings = $settings ?? [];
?>
<?php if ($form['status'] === 'published'): ?>
    <div class="input-group mb-2">
        <input type="text" class="form-control form-control-sm app-share-input" id="shareUrl" value="<?= View::escape($shareUrl) ?>" readonly>
        <button class="btn btn-outline-secondary btn-sm btn-copy-link" type="button" data-url="<?= View::escape($shareUrl) ?>" title="Copiar enlace"><i class="fa-solid fa-copy"></i></button>
    </div>
    <a href="<?= View::escape($shareUrl) ?>" target="_blank" class="btn btn-outline-info btn-sm w-100"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Abrir en nueva pestaña</a>

    <?php // La URL se dedujo del host de la petición y salió local: el QR solo
          // abrirá desde esta red. Ver App\Core\PublicUrl. ?>
    <?php if (!empty($publicUrlIsLocal)): ?>
        <div class="alert alert-warning d-flex gap-2 mt-3 mb-0 py-2 px-3 small app-share-hint" role="alert">
            <i class="fa-solid fa-triangle-exclamation mt-1"></i>
            <span>Este enlace apunta a una dirección local, así que el QR y el enlace solo abren en esta red. Si la app se sirve en otro dominio, define <code>APP_PUBLIC_URL</code> en el <code>.env</code>.</span>
        </div>
    <?php endif; ?>

    <?php // P7 — QR del enlace público (SVG, sin GD). ?>
    <img src="/forms/<?= (int) $form['id'] ?>/qr.svg" alt="Código QR del formulario" class="img-fluid mx-auto d-block my-3 app-qr-img" width="180" height="180">

    <?php // P7 — Código de embed cuando está habilitado. ?>
    <?php if (!empty($settings['embed']['enabled'])): ?>
        <div class="mt-2">
            <label for="embedSnippet" class="form-label small mb-1">Código para embeber (iframe)</label>
            <textarea class="form-control form-control-sm" id="embedSnippet" rows="3" readonly><iframe src="<?= View::escape($embedUrl) ?>" width="100%" height="640" frameborder="0"></iframe></textarea>
            <a href="<?= View::escape($embedUrl) ?>" target="_blank" class="small text-decoration-none d-inline-block mt-1"><i class="fa-solid fa-up-right-from-square me-1"></i>Ver página de embed</a>
        </div>
    <?php else: ?>
        <p class="text-muted small mt-3 mb-0"><i class="fa-solid fa-circle-info me-1"></i>El embed está desactivado. Actívalo en la <em>Configuración</em> del constructor para obtener el código iframe.</p>
    <?php endif; ?>
<?php else: ?>
    <p class="text-muted small mb-3">Publica el formulario para obtener el enlace de respuesta.</p>
    <form method="post" action="/forms/<?= (int) $form['id'] ?>/publish" id="formPublishSidebar" data-vf-async="slots">
        <?= \App\Core\Csrf::field() ?>
        <button type="submit" class="btn btn-success btn-sm w-100"><i class="fa-solid fa-globe me-1"></i>Publicar ahora</button>
    </form>
<?php endif; ?>
