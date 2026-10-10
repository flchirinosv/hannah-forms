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
 * ## Tira de métricas (rediseño 2026-10-08)
 *
 * Antes eran cuatro `badge rounded-pill` en una fila; ahora son tarjetas
 * `.vf-stat` con el número grande y el rótulo debajo, para que la cifra se lea
 * de un vistazo. El color sigue codificando el estado (verde respondidos, ámbar
 * pendientes, azul invitados) y el color neutro del total lo deja destacar sin
 * competir. El `id="rosterStats"` no se mueve.
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
// esto dice lo que ya está hecho).
$invitados  = (int) ($roster['invitados'] ?? 0);
?>
<div id="rosterStats" class="vf-stat-strip">
    <div class="vf-stat">
        <div class="vf-stat-value"><i class="fa-solid fa-users" aria-hidden="true"></i><?= $total ?></div>
        <div class="vf-stat-label">En la lista</div>
    </div>
    <div class="vf-stat <?= $respondidos > 0 ? 'vf-stat-success' : 'vf-stat-muted' ?>">
        <div class="vf-stat-value"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><?= $respondidos ?></div>
        <div class="vf-stat-label">Respondieron</div>
    </div>
    <div class="vf-stat <?= $sinResponder > 0 ? 'vf-stat-warning' : 'vf-stat-muted' ?>">
        <div class="vf-stat-value"><i class="fa-solid fa-clock" aria-hidden="true"></i><?= $sinResponder ?></div>
        <div class="vf-stat-label">Sin responder</div>
    </div>
    <?php if ($invitados > 0) { ?>
        <div class="vf-stat vf-stat-info">
            <div class="vf-stat-value"><i class="fa-solid fa-envelope" aria-hidden="true"></i><?= $invitados ?></div>
            <div class="vf-stat-label">Invitada<?= $invitados === 1 ? '' : 's' ?></div>
        </div>
    <?php } ?>
</div>
