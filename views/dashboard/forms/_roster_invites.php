<?php

use App\Core\Csrf;
use App\Core\Mailer;
use App\Core\View;
use App\Models\FormParticipant;
use App\Services\RosterPanel;

/**
 * FASE 10 — Invitar por correo a quien todavía no lo ha recibido.
 *
 * ## Tres estados y ninguno se esconde
 *
 * El botón depende de dos cosas que no son de la lista: de que el identificador del
 * formulario sea el correo y de que haya SMTP configurado. Los dos casos se
 * DICEN con su texto, en vez de desaparecer: un hueco vacío no se sabe si es que
 * no hay a quién escribir o si el botón se ha roto. Es el mismo criterio que
 * `pageHeaderHint()` en el builder.
 *
 * - Con `cedula` o `indicador` no hay botón: el enlace va en la columna «Código» y
 *   lo reparte quien gestiona la lista. Aquí se dice eso, que es la información
 *   que le falta al dueño para entender por qué no aparece.
 * - Sin SMTP (`MAIL_HOST` vacío) se dice, y se recuerda que los códigos siguen
 *   funcionando. Un ajuste que parece roto es peor que uno que avisa de cuándo es
 *   inaplicable.
 * - Con cero pendientes se dice, y el botón desaparece (no queda un botón apagado
 *   que pida trabajo).
 *
 * ## El aviso del resultado NO va aquí
 *
 * Ni `data-vf-error`, ni un `<p>` de error dentro de este hueco:
 * un envío parcial (`ok:false` con `invites` en `$cambia`) repinta este HTML, y
 * `app.js` resuelve el nodo del error ANTES de `vfApplySlots()`, así que el aviso
 * se escribiría en un nodo que ya no está en el documento. Un mensaje invisible es
 * peor que un aviso flotante, y este botón no tiene un campo al que el aviso pueda
 * ser adyacente: es un botón y nada más. Por eso el resultado llega como `vfToast`,
 * el camino que ya usan las otras acciones de la pestaña.
 *
 * ## El `data-confirm` dice cuántos
 *
 * El tope por tanda son 50 y el dueño puede tener 800 pendientes: sin el número en
 * la pregunta, «¿Enviar invitaciones?» no dice qué se acepta. Y va en el `<form>`,
 * que es donde lo lee el delegado de `app.js`. Los dos números salen de
 * `RosterPanel::INVITE_POR_TANDA`, que es la misma constante que usa el `LIMIT` del
 * lote: un texto que anuncia otra cifra que la que se manda es un ajuste roto.
 *
 * Variables: `$roster`, `$formId`.
 *
 * @var array $roster
 * @var int $formId
 */
$identificador = (string) ($roster['identificador'] ?? 'cedula');
$porInvitar = (int) ($roster['porInvitar'] ?? 0);
$esCorreo = $identificador === FormParticipant::IDENT_CORREO;
$haySmtp = Mailer::isConfigured();
?>
<div id="rosterInvites">
    <?php if (!$esCorreo) { ?>
        <p class="small text-muted mb-0">
            <i class="fa-solid fa-envelope me-1"></i>
            No hay invitaciones por correo: este formulario se identifica por
            <?= $identificador === 'indicador' ? 'indicador' : 'cédula' ?>.
            El enlace de cada persona está en la columna «Código» de la lista.
        </p>
    <?php } else { ?>
        <p class="small text-muted mb-1">
            <i class="fa-solid fa-envelope me-1"></i>
            Invitar por correo manda el enlace a quien aún no lo ha recibido.
            Importar la lista <strong>no</strong> manda nada: es un botón aparte.
        </p>

        <?php if (!$haySmtp) { ?>
            <p class="small mb-0 text-warning-emphasis">
                <i class="fa-solid fa-triangle-exclamation me-1"></i>
                No hay servidor de correo configurado (<code>MAIL_HOST</code> vacío),
                así que el envío está apagado. Los códigos de la columna «Código»
                siguen funcionando.
            </p>
        <?php } elseif ($porInvitar === 0) { ?>
            <p class="small mb-0 text-muted">
                <i class="fa-solid fa-circle-check me-1"></i>
                No queda nadie pendiente de invitación.
            </p>
        <?php } else { ?>
            <form method="post" action="/forms/<?= (int) $formId ?>/participants/invitations"
                  data-vf-async="slots"
                  data-confirm="¿Enviar la invitación a <?= (int) $porInvitar ?> persona(s)? Esta tanda manda como mucho <?= (int) RosterPanel::INVITE_POR_TANDA ?>; a los que ya recibieron el enlace no se les vuelve a escribir."
                  data-confirm-ok="Sí, enviar" data-confirm-tone="primary">
                <?= Csrf::field() ?>
                <input type="hidden" name="rosterQ" value="<?= View::escape((string) ($roster['q'] ?? '')) ?>">
                <input type="hidden" name="rosterPage" value="<?= (int) ($roster['page'] ?? 1) ?>">
                <input type="hidden" name="rosterSort" value="<?= View::escape((string) ($roster['sort'] ?? 'cedula')) ?>">
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <i class="fa-solid fa-paper-plane me-1"></i>Enviar invitaciones
                    (<?= (int) $porInvitar ?>)
                </button>
                <span class="small text-muted ms-1">
                    <?= $porInvitar > RosterPanel::INVITE_POR_TANDA
                        ? 'esta tanda manda ' . (int) RosterPanel::INVITE_POR_TANDA
                        : 'a todos los que quedan' ?>
                </span>
            </form>
        <?php } ?>
    <?php } ?>
</div>
