<?php

use App\Core\View;

/**
 * El CUERPO de la tarjeta de Acceso de la pantalla de participantes
 * (`/forms/{id}/access`).
 *
 * Es un partial, y no markup en la vista, por la misma razón que los cuatro
 * huecos del estado (`_status_badge`, `_review_trail`, `_status_actions`,
 * `_share_card`): lo que decide cómo se pinta es un ajuste —el modo de
 * acceso— y así se evita tener dos copias de la misma tarjeta en dos sitios.
 * Con el cuerpo en la vista, cambiar el modo solo se veía recargando a mano.
 *
 * El cuerpo vive en el `card-body` de la tarjeta `#access`, y NO en el
 * `<div class="card">` entero, porque la tarjeta lleva lo que el JS lee del DOM
 * (`data-csrf` y `data-link-endpoint`, para el enlace de acceso): si el `id`
 * estuviera en la tarjeta, repintar el cuerpo borraría esos atributos y el
 * botón de emitir enlace se quedaría sin destino a la mitad de la sesión.
 *
 * ## Y el cuarto modo, `roster`, solo pinta la cabecera
 *
 * La allowlist guarda correos; el listado guarda personas con un código, atributos
 * y un estado de «respondió». Meterlo en el `else` de abajo habría producido un
 * formulario con una casilla de correo por cada persona de una lista que no usa
 * correos. Por eso `roster` tiene su propia rama, y el `else` sigue siendo
 * exactamente la allowlist de F4.
 *
 * El LISTADO del roster (el tablero de números y la tabla de filas) ya no vive
 * aquí: salió a sus propias tarjetas (`RosterPanel::panel()`, que orquesta
 * `access.php`). Esta tarjeta es solo la cabecera —icono, chip del modo y el
 * aviso de cómo se entra—, y por eso no recibe `$roster` ni `$informe`.
 *
 * ## Cabecera, chip y callout (rediseño 2026-10-08)
 *
 * El aspecto se decide con clases `vf-access-*` (ver `app.css`), no con la
 * estructura: el título, el chip del modo y la explicación de cada rama son los
 * mismos, solo cambian el icono, el color del chip y el texto. El chip lleva el
 * punto de color por `currentColor`, así que el modo se lee de un vistazo sin
 * depender solo del texto. El contrato de clases lo fija `AccessCardDesignTest`.
 *
 * Variables: `$form`, `$accessMode`, `$accessList` y `$accessCount`.
 *
 * @var array $form
 * @var string $accessMode
 * @var array $accessList
 * @var int $accessCount
 */
$accessMode = (string) ($accessMode ?? 'public');
$modeLabel = match ($accessMode) {
    'email'     => 'Por correo',
    'allowlist' => 'Solo la lista',
    'roster'    => 'Solo el listado',
    default     => 'Público',
};
$modeChip = match ($accessMode) {
    'email'     => 'vf-mode-chip-email',
    'allowlist' => 'vf-mode-chip-allowlist',
    'roster'    => 'vf-mode-chip-roster',
    default     => 'vf-mode-chip-public',
};
?>
<div class="vf-access-head">
    <div class="vf-access-id">
        <span class="vf-access-icon" aria-hidden="true"><i class="fa-solid fa-user-lock"></i></span>
        <div>
            <h3 class="vf-access-title">Acceso</h3>
            <div class="vf-access-sub">Quién puede responder</div>
        </div>
    </div>
    <span class="vf-mode-chip <?= $modeChip ?>"><?= View::escape($modeLabel) ?></span>
</div>

<?php if ($accessMode === 'roster'): ?>
    <?php // La frase sigue al identificador del formulario, igual que la página pública:
          // decir solo «con el código que le tocó» en un formulario cuyo identificador
          // es la cédula escribe una puerta que no es la que se le pide al visitante. ?>
    <?php $entradaRoster = match (\App\Models\FormParticipant::identifierFor((int) $form['id'])) {
                'correo'    => 'con el correo con el que figura en ella o con el código que le tocó',
        'codigo'    => 'con el código que le tocó',
        default     => 'con la cédula con la que figura en ella o con el código que le tocó',
    }; ?>
    <div class="vf-callout">
        <i class="fa-solid fa-list-check" aria-hidden="true"></i>
        <div>Solo puede responder quien esté en esta lista, <?= View::escape($entradaRoster) ?>.
        Quitar a alguien de la lista <strong>le cierra la puerta</strong>.</div>
    </div>
<?php elseif ($accessMode === 'allowlist'): ?>
    <div class="vf-callout mb-3">
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
        <div>Solo los correos de esta lista reciben un enlace. Quitar a alguien de la lista
        <strong>invalida los enlaces que ya se le hayan enviado</strong>.</div>
    </div>

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
            <button type="submit" class="btn btn-sm btn-outline-primary" id="accessAddBtn">
                <i class="fa-solid fa-plus me-1"></i>Añadir
            </button>
        </div>
        <p class="small mb-0 mt-1 d-none text-danger fw-semibold" id="accessAddError" role="alert"></p>
    </form>

    <?php // F4 — Salida del enlace recién emitido. Se rellena por JS y se queda
          // a la vista a propósito: el token solo existe en claro en el momento
          // de emitirlo, así que si el portapapeles falla no hay forma de
          // recuperarlo y el enlace se habría perdido. ?>
    <div class="d-none mb-3 vf-access-linkout" id="accessLinkOut">
        <label class="form-label small mb-1">
            Enlace para <span data-role="access-link-for"></span>
        </label>
        <div class="input-group input-group-sm">
            <input type="text" class="form-control" readonly
                   aria-label="Enlace de acceso generado">
            <?php // `access-recopy` y no el `js-copy-url` de siempre: ese
                  // prepende el origen de la página, y esta URL ya viene
                  // absoluta desde PublicUrl. ?>
            <button type="button" class="btn btn-sm btn-outline-secondary"
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
<?php else: ?>
    <div class="vf-callout">
        <i class="fa-solid fa-globe" aria-hidden="true"></i>
        <div>
            <?php if ($accessMode === 'email'): ?>
                Cualquiera que escriba su correo recibe un enlace. No hay una lista que
                mantener. Cámbialo en <em>Ajustes del formulario</em>.
            <?php else: ?>
                Cualquiera con el enlace puede responder. Cámbialo en
                <em>Ajustes del formulario</em>.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
