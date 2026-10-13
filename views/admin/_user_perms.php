<?php

use App\Core\View;

/**
 * Las insignias de permisos DIRECTOS de una fila de `/admin`.
 *
 * Es un partial por la misma razón que `_user_groups.php`: el guardado va por
 * fetch y el botón de la celda se repinta sin recargar. Con las insignias dentro
 * de la vista, el botón se quedaría diciendo «Sin permisos extra» sobre alguien a
 * quien se le acaba de conceder uno.
 *
 * Solo van aquí las insignias: las casillas del desplegable las pinta la propia
 * vista y las que enviaron el POST ya son la verdad, así que repintar el botón no
 * las toca (y no cierra el desplegable abierto).
 *
 * Variables: `$myPerms` (lista de claves del catálogo).
 *
 * @var array $myPerms
 */
?>
<?php if ($myPerms === []): ?>
    <span class="text-muted">Sin permisos extra</span>
<?php else: ?>
    <?php foreach ($myPerms as $key): ?>
        <code class="app-perm-chip" title="<?= View::escape((string) $key) ?>"><?= View::escape((string) $key) ?></code>
    <?php endforeach; ?>
<?php endif; ?>
