<?php

use App\Core\View;
/**
 * Traza de la revisión de la ficha: quién la pidió y quién la aprobó.
 *
 * F3 — Es lo que distingue «revisado» de «nadie miró esto», así que solo se
 * pinta si hay algo que contar. Es un partial por lo mismo que la insignia:
 * aprobar o devolver sin recarga cambia sus dos líneas, y si el bloque fuera
 * de la vista se quedaría pintando la petición de una revisión que ya se
 * resolvió (o no aparecería la de la que se acaba de pedir).
 *
 * Variables: `$reviewTrail` (array o `null`).
 */
$reviewTrail = $reviewTrail ?? null;
?>
<?php if (!empty($reviewTrail) && ($reviewTrail['solicitado'] !== null || $reviewTrail['aprobado'] !== null)): ?>
    <div class="alert alert-light border small mb-4">
        <div class="fw-semibold mb-1"><i class="fa-solid fa-clipboard-check me-1 text-primary"></i>Revisión</div>
        <ul class="list-unstyled mb-0 small text-muted">
            <?php if ($reviewTrail['solicitado'] !== null): ?>
                <li><i class="fa-regular fa-clock me-1"></i>Enviado a revisión el <?= View::escape(View::datetime($reviewTrail['solicitado'])) ?></li>
            <?php endif; ?>
            <?php if ($reviewTrail['aprobado'] !== null): ?>
                <li><i class="fa-solid fa-check me-1 text-success"></i>Aprobado<?= $reviewTrail['aprobador'] !== null ? ' por ' . View::escape($reviewTrail['aprobador']) : '' ?> el <?= View::escape(View::datetime($reviewTrail['aprobado'])) ?></li>
            <?php endif; ?>
        </ul>
    </div>
<?php endif; ?>
