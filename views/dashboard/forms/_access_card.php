<?php

use App\Core\View;

/**
 * El CUERPO de la tarjeta de Acceso de la ficha (`/forms/{id}`).
 *
 * Es un partial, y no markup en la vista, por la misma razón que los cuatro
 * huecos del estado (`_status_badge`, `_review_trail`, `_status_actions`,
 * `_share_card`): lo que decide cómo se pinta es un ajuste —el modo de
 * acceso— y el guardado de ajustes va por fetch. Con el cuerpo en la vista, el
 * `POST /settings` solo podía reponer la insignia del estado y esta tarjeta se
 * quedaba diciendo «Público» con el formulario ya en «Solo la lista» (o, al
 * revés, con el formulario abierto a todo el mundo y una lista que ya no
 * protege nada). Un ajuste que se guarda bien y no se ve no da ningún error,
 * ni en el navegador ni en el log: es el peor tipo de fallo.
 *
 * El hueco (`vfSlotAccess`) va en el `card-body` y **no** en la tarjeta
 * entera, porque la tarjeta lleva lo que el JS lee del DOM (`data-csrf` y
 * `data-link-endpoint`, para el enlace de acceso): si el `id` estuviera en el
 * `<div class="card">`, repintar el hueco borraría esos atributos y el botón de
 * emitir enlace se quedaría sin destino a la mitad de la sesión.
 *
 * ## Y el cuarto modo, `roster`, no cabe en ninguno de los tres bloques
 *
 * La allowlist guarda correos; el listado guarda personas con un código, atributos
 * y un estado de «respondió». Meterlo en el `else` de abajo habría producido un
 * formulario con una casilla de correo por cada persona de una lista que no usa
 * correos. Por eso `roster` tiene su propia rama y su propio partial (fase 5),
 * y el `else` sigue siendo exactamente la allowlist de F4.
 *
 * Variables: `$form`, `$accessMode`, `$accessList`, `$accessCount`, y en modo
 * `roster` `$roster` y `$informe`.
 *
 * @var array $form
 * @var string $accessMode
 * @var array $accessList
 * @var int $accessCount
 * @var array<string,mixed>|null $roster
 * @var array<string,mixed>|null $informe
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="h6 fw-bold m-0"><i class="fa-solid fa-user-lock me-2 text-primary"></i>Acceso</h3>
    <span class="badge text-bg-light text-muted"><?= View::escape(match ($accessMode) {
        'email' => 'Por correo',
        'allowlist' => 'Solo la lista',
        'roster' => 'Solo el listado',
        default => 'Público',
    }) ?></span>
</div>

<?php if ($accessMode === 'public'): ?>
    <p class="text-muted small mb-0">
        <i class="fa-solid fa-circle-info me-1"></i>Cualquiera con el enlace puede responder.
        Cambia el modo en <em>Ajustes del formulario</em>.
    </p>
<?php elseif ($accessMode === 'email'): ?>
    <p class="text-muted small mb-0">
        <i class="fa-solid fa-circle-info me-1"></i>Cualquiera que escriba un correo recibe un
        enlace. No hay lista: cualquier correo válido entra. Cambia el modo en
        <em>Ajustes del formulario</em>.
    </p>
<?php elseif ($accessMode === 'roster'): ?>
    <?php // `$roster` puede llegar `null` con el modo ya en `roster` (por ejemplo desde
          // `FormPanel::accessSlot()` justo después de cambiar el modo): en ese caso se
          // calculan los datos aquí, en vez de pintar un hueco vacío, porque un
          // formulario que acaba de pasar a «Solo el listado» sin lista parece que no
          // tiene puerta de entrada. ?>
    <?php // La frase sigue al identificador del formulario, igual que la página pública:
          // decir solo «con el código que le tocó» en un formulario cuyo identificador
          // es la cédula escribe una puerta que no es la que se le pide al visitante. ?>
    <?php $entradaRoster = match (\App\Models\FormParticipant::identifierFor((int) $form['id'])) {
        'indicador' => 'con el indicador con el que figura en ella o con el código que le tocó',
        'correo'    => 'con el correo con el que figura en ella o con el código que le tocó',
        'codigo'    => 'con el código que le tocó',
        default     => 'con la cédula con la que figura en ella o con el código que le tocó',
    }; ?>
    <p class="text-muted small">
        Solo puede responder quien esté en esta lista, <?= View::escape($entradaRoster) ?>.
        Quitar a alguien de la lista <strong>le cierra la puerta</strong>.
    </p>

    <?= \App\Services\RosterPanel::panel(
        $roster ?? \App\Services\RosterPanel::data((int) $form['id']),
        $form,
        $informe
    ) ?>
<?php else: ?>
    <p class="text-muted small">
        Solo los correos de esta lista reciben un enlace. Quitar a alguien de la lista
        <strong>invalida los enlaces que ya se le hayan enviado</strong>.
    </p>

    <?php // El alta va por fetch con el resto de las acciones
          // (`data-vf-async="slots"`), y vuelve la lista en `html.accessList`,
          // que es este MISMO partial. `data-vf-error` dice dónde va el «ese
          // correo ya está»: junto al campo, que es donde se busca, y no además
          // flotante. `data-vf-clear` lo vacía y devuelve el foco a él para
          // apuntar el siguiente sin tabular hasta el principio. El `method`/
          // `action` siguen ahí a propósito, así que SIN JavaScript el
          // formulario funciona igual (la misma puerta responde en HTML o en
          // JSON). ?>
    <form method="post" action="/forms/<?= (int) $form['id'] ?>/access/email"
          class="mb-3" id="accessAddForm" data-role="access-add-form"
          data-vf-async="slots" data-vf-error="accessAddError" data-vf-clear="accessAddEmail">
        <?= \App\Core\Csrf::field() ?>
        <div class="input-group input-group-sm">
            <input type="email" name="email" id="accessAddEmail" class="form-control" required maxlength="190"
                   placeholder="nombre@ejemplo.com" aria-label="Correo con acceso">
            <button type="submit" class="btn btn-outline-primary" id="accessAddBtn">
                <i class="fa-solid fa-plus me-1"></i>Añadir
            </button>
        </div>
        <p class="small mb-0 mt-1 d-none text-danger fw-semibold" id="accessAddError" role="alert"></p>
    </form>

    <?php // F4 — Salida del enlace recién emitido. Se rellena por JS y se queda
          // a la vista a propósito: el token solo existe en claro en el momento
          // de emitirlo, así que si el portapapeles falla no hay forma de
          // recuperarlo y el enlace se habría perdido. ?>
    <div class="d-none mb-3" id="accessLinkOut">
        <label class="form-label small mb-1">
            Enlace para <span data-role="access-link-for"></span>
        </label>
        <div class="input-group input-group-sm">
            <input type="text" class="form-control" readonly
                   aria-label="Enlace de acceso generado">
            <?php // `access-recopy` y no el `js-copy-url` de siempre: ese
                  // prepende el origen de la página, y esta URL ya viene
                  // absoluta desde PublicUrl. ?>
            <button type="button" class="btn btn-outline-secondary"
                    data-role="access-recopy" title="Copiar otra vez"><i class="fa-solid fa-copy"></i></button>
        </div>
        <p class="small text-muted mb-0 mt-1">
        Al pedir otro enlace para el mismo correo, este deja de funcionar.</p>
    </div>

    <?php // La lista es un partial porque el alta por fetch devuelve este
          // MISMO HTML ya escapado (ver `AccessController::addEmail()`): con
          // dos copias, añadir un email y recargar mostraría listas distintas
          // según por dónde se mirara. ?>
    <div id="accessList">
        <?= View::partial('dashboard/forms/_access_list', [
            'accessList'  => $accessList,
            'accessCount' => $accessCount,
            'formId'      => (int) $form['id'],
        ]) ?>
    </div>
<?php endif; ?>