<?php

use App\Core\View;
/**
 * Fila de acciones de la ficha (`/forms/{id}`): guardar, revisión, estado y
 * eliminar.
 *
 * Es UN partial para toda la fila, y no uno por bloque de estado, por una
 * razón de maquetación: los huecos que se actualizan tienen que ser hijos
 * DIRECTOS de la `.row` (Bootstrap solo reparte el ancho a `.row > *`, así que
 * un `<div>` alrededor se comería la línea entera). Meter cada bloque de
 * estado en su `<div id="…">` obligaba o bien a `display: contents` —Bootstrap
 * no trae utility y había que escribirla— o bien a una fila por bloque, que
 * partía los botones en varias líneas. Con la fila entera como hueco, los
 * `<div id>` quedan fuera de la `.row` y el reparto sigue siendo el de siempre.
 *
 * El otro motivo es el que importa: los cuatro bloques de la fila leen
 * `forms.status`, así que un POST de estado tiene que poder repintarlos TODOS.
 * Con un solo hueco, «se repinta todo» es una propiedad de la estructura y no
 * una lista de acordarse.
 *
 * Variables: `$form`, `$reviewRequired`, `$canReview`, `$canDelete`.
 */
$reviewRequired = $reviewRequired ?? false;
$canReview = $canReview ?? false;
$canDelete = $canDelete ?? false;
?>
<div class="row g-2 mb-4">
    <div class="col">
        <button type="submit" form="formUpdate" class="btn btn-outline-primary w-100 app-form-cta"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar</button>
    </div>
    <?php // F3 — Con revisión activa el dueño no tiene botón de publicar:
          // el que hay sería aprobarse a uno mismo. Se muestra el de «enviar a
          // revisión» en su lugar. ?>
    <?php if ($form['status'] === 'draft' && $reviewRequired): ?>
        <div class="col">
            <form method="post" action="/forms/<?= (int) $form['id'] ?>/review/send" id="formSendReview" data-vf-async="slots">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-info w-100 app-form-cta"><i class="fa-solid fa-paper-plane me-1"></i>Enviar a revisión</button>
            </form>
        </div>
    <?php endif; ?>
    <?php if ($form['status'] === 'in_review' && $canReview): ?>
        <div class="col">
            <form method="post" action="/forms/<?= (int) $form['id'] ?>/review/approve" id="formApprove" data-vf-async="slots"
                  data-confirm="¿Publicar este formulario? Aprobarlo lo deja visible para cualquiera que tenga el enlace, y ya no es un borrador."
                  data-confirm-ok="Publicar" data-confirm-tone="primary">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-success w-100 app-form-cta"><i class="fa-solid fa-check me-1"></i>Aprobar</button>
            </form>
        </div>
        <div class="col">
            <form method="post" action="/forms/<?= (int) $form['id'] ?>/review/return" id="formReturnReview" data-vf-async="slots"
                  data-confirm="¿Devolver este formulario al dueño como borrador? No se publicará."
                  data-confirm-ok="Devolver" data-confirm-tone="primary">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-outline-warning w-100 app-form-cta"><i class="fa-solid fa-rotate-left me-1"></i>Devolver</button>
            </form>
        </div>
    <?php endif; ?>
    <?php // Cuál es el hueco de la «acción principal» depende del estado: con
          // revisión activa y en borrador no la hay (el hueco lo ocupa el envío
          // a revisión), y en revisión sin permiso la acción principal es un
          // aviso, no un botón. ?>
    <?php $inReview = $form['status'] === 'in_review'; ?>
    <?php if ($inReview && !$canReview): ?>
        <div class="col">
            <div class="alert alert-info mb-0 py-2 px-3 h-100 d-flex align-items-center">
                <i class="fa-solid fa-hourglass-half me-2"></i>Esperando revisión. Volverá al borrador o se publicará.
            </div>
        </div>
    <?php endif; ?>
    <?php $mainSlot = ($form['status'] === 'draft' && !$reviewRequired) || $form['status'] === 'published' || $form['status'] === 'closed'; ?>
    <?php // Esta columna solo se emite si hay una acción principal que poner en
          // ella. Antes se emitía siempre, con `col-sm-6` o `col-sm-12` según el
          // caso, y en los tres estados que no tienen botón (en revisión con
          // permiso —que ya tiene Aprobar/Devolver—, en revisión sin permiso —que
          // tiene el aviso— y en borrador con revisión activa —que tiene «Enviar
          // a revisión») quedaba media fila en blanco. Todas las acciones de la
          // fila son `col`, de ancho igual, para que la suma sea 12 diga lo que
          // diga el número de acciones que haya. ?>
    <?php if ($mainSlot): ?>
    <div class="col">
        <?php if ($form['status'] === 'draft' && !$reviewRequired): ?>
            <form method="post" action="/forms/<?= (int) $form['id'] ?>/publish" id="formPublishActions" data-vf-async="slots">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-success w-100 app-form-cta"><i class="fa-solid fa-globe me-1"></i>Publicar</button>
            </form>
        <?php endif; ?>
        <?php if ($form['status'] === 'published'): ?>
            <form method="post" action="/forms/<?= (int) $form['id'] ?>/close" id="formClose" data-vf-async="slots">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-warning w-100 app-form-cta"><i class="fa-solid fa-lock me-1"></i>Cerrar</button>
            </form>
        <?php endif; ?>
        <?php if ($form['status'] === 'closed'): ?>
            <?php // `id` propio y no el de «Publicar» de arriba: son ramas
                  // mutuamente excluyentes, pero el id repetido era HTML
                  // inválido y cualquier cosa que lo buscara por id («el botón
                  // publicar») se quedaba con el que no era. ?>
            <form method="post" action="/forms/<?= (int) $form['id'] ?>/publish" id="formReopenActions" data-vf-async="slots">
                <?= \App\Core\Csrf::field() ?>
                <button type="submit" class="btn btn-success w-100 app-form-cta"><i class="fa-solid fa-globe me-1"></i>Reabrir</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($canDelete): ?>
    <div class="col">
        <form method="post" action="/forms/<?= (int) $form['id'] ?>/delete" id="formDelete" data-vf-async="slots"
              data-confirm="¿Eliminar este formulario y todas sus respuestas? Esta acción no se puede deshacer."
              data-confirm-ok="Eliminar formulario">
            <?= \App\Core\Csrf::field() ?>
            <button type="submit" class="btn btn-outline-danger w-100 app-form-cta"><i class="fa-solid fa-trash me-1"></i>Eliminar</button>
        </form>
    </div>
    <?php endif; ?>
</div>
