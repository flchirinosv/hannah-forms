<?php

use App\Core\View;

/**
 * Las insignias de grupos de una fila de `/admin`.
 *
 * Es un partial por la misma razón que `_user_role.php` y `_user_status.php`: la
 * columna muestra el VALOR (la pertenencia), y el guardado de grupos va por
 * fetch desde el menú de la fila. Con las insignias dentro de la vista, guardar
 * los grupos recargaba la página y se veían correctas, pero al dejar de recargar
 * la columna se quedaría diciendo «Sin grupos» sobre una persona que sí los
 * tiene. Es el mismo hueco (`vfUserGroups<uid>`) que repone el servidor.
 *
 * Solo van aquí las insignias: las casillas del desplegable las pinta la propia
 * vista y las que enviaron el POST ya son la verdad, así que repintar la columna
 * no las toca (y no cerraría el menú abierto).
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