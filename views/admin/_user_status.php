<?php

use App\Core\View;
/**
 * La insignia de estado (Activo/Inactivo) de una fila de `/admin`.
 *
 * Partial porque el botón de la misma fila va por fetch (`AdminController::
 * toggleActive()` devuelve este HTML y el del botón), y con la insignia dentro
 * de la vista el panel seguía mostrando «Activo» sobre un usuario que el POST
 * acababa de desactivar: el texto solo depende de `is_active`.
 *
 * Variable: `$user`.
 */
?>
<span class="app-status <?= (int) $user['is_active'] === 1 ? 'app-status-published' : 'app-status-closed' ?>">
    <?= (int) $user['is_active'] === 1 ? 'Activo' : 'Inactivo' ?>
</span>
