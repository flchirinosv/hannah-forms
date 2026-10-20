<?php

use App\Core\View;

/**
 * La insignia del rol de una fila de `/admin`.
 *
 * Partial porque el rol se cambia desde el menú de la fila por fetch: la
 * insignia de la columna tiene que reflejarlo sin recargar. Es el mismo par que
 * `_user_status.php` (el `<select>` ya viene con el valor nuevo, pero lo que se
 * VE en la fila es esta insignia).
 *
 * Variable: `$roleName` (string ya resuelto por el controlador o la vista).
 *
 * @var string $roleName
 */
$roleName = (string) ($roleName ?? '—');
?>
<span class="badge bg-primary-subtle text-primary-emphasis"><?= View::escape($roleName) ?></span>
