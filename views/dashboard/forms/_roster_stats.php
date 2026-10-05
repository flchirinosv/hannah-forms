<?php

/**
 * FASE 5 — Los números de arriba de la lista.
 *
 * ## Un hueco aparte de las filas, y por qué
 *
 * Porque los números son del **formulario entero** y las filas son de la página
 * que se está mirando: si se repintaran juntos, buscar «V-» y ver «0 de 3 han
 * respondido» sería verdad (de lo que hay filtrado) y no diría nada (de cuánto de
 * SU lista ha respondido). Es el mismo motivo por el que el listado de
 * respuestas no enseña el total de la búsqueda como total de respuestas.
 *
 * Variables: `$roster`.
 *
 * @var array $roster
 */
$total      = (int) $roster['total'];
$respondidos = (int) $roster['respondidos'];
$sinResponder = max(0, $total - $respondidos);
// Fase 10: el histórico de invitaciones. Va con los números y no en el botón de
// invitar porque es un hecho que NO cambia al mandar (el botón dice lo que falta,
// esto dice lo que ya está hecho), y porque al pintar las cifras se ve que el
// correo salió y no solo que el botón se apagó.
$invitados  = (int) ($roster['invitados'] ?? 0);
?>
<div id="rosterStats" class="d-flex flex-wrap gap-2 small mb-3">
    <span class="badge rounded-pill text-bg-secondary">
        <i class="fa-solid fa-users me-1"></i><?= $total ?> en la lista
    </span>
    <span class="badge rounded-pill <?= $respondidos > 0 ? 'text-bg-success' : 'text-bg-light text-muted' ?>">
        <i class="fa-solid fa-circle-check me-1"></i><?= $respondidos ?> respondieron
    </span>
    <span class="badge rounded-pill <?= $sinResponder > 0 ? 'text-bg-warning' : 'text-bg-light text-muted' ?>">
        <i class="fa-solid fa-clock me-1"></i><?= $sinResponder ?> sin responder
    </span>
    <?php if ($invitados > 0) { ?>
        <span class="badge rounded-pill text-bg-info">
            <i class="fa-solid fa-envelope me-1"></i><?= $invitados ?> invitada<?= $invitados === 1 ? '' : 's' ?>
        </span>
    <?php } ?>
</div>
