<?php

use App\Core\View;

/**
 * Las insignias de grupos de una fila de `/admin`.
 *
 * Es un partial por la misma razón que `_user_status.php`: el botón de la misma
 * celda va por fetch, y lo que el botón pinta depende de la pertenencia, que
 * acaba de cambiar. Con las insignias dentro de la vista, guardar los grupos
 * recargaba la página y se veían correctas — pero si mañana el guardado deja de
 * recargar (que es justo lo que se hizo), el botón se quedaría diciendo «Sin
 * grupos» sobre una persona que sí los tiene.
 *
 * Solo van aquí las insignias: las casillas del desplegable las pinta la propia
 * vista y las que enviaron el POST ya son la verdad, así que repintar el botón
 * no las toca (y tocaría el desplegable abierto).
 *
 * Variables: `$myGroups`.
 *
 * @var array $myGroups
 */
?>
<?php if ($myGroups === []): ?>
    <span class="text-muted">Sin grupos</span>
<?php else: ?>
    <?php foreach ($myGroups as $g): ?>
        <span class="badge text-bg-light border"><?= View::escape($g['name']) ?></span>
    <?php endforeach; ?>
<?php endif; ?>