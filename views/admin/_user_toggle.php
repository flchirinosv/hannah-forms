<?php

use App\Core\View;
/**
 * El botón de activar/desactivar de una fila de `/admin`.
 *
 * Es el otro medio del par con `_user_status.php`: los dos van por fetch y los
 * dos dependen de `is_active` —el texto del botón («Desactivar»/«Activar»), su
 * icono y el `hidden` con el estado objetivo— así que se repintan juntos.
 *
 * El hueco (`vfUserToggle<uid>`) lo pone el ENVOLTORIO de
 * `views/admin/users.php`, no este `<form>`, y no es un detalle: el JS repone el
 * hueco con `innerHTML`, así que si el `id` estuviera en el formulario se
 * metería un `<form>` dentro de otro al repintarlo. El parser del navegador
 * descarta el anidado, así que «funcionaría» por casualidad y perdiendo
 * contenido según el navegador; el envoltorio es lo que hace que lo que se
 * sustituye sea una caja y no una etiqueta.
 *
 * El `data-vf-async="slots"` es lo que hace que el POST vaya por fetch en vez de
 * recargar. Sin él, el botón seguía funcionando pero la fila entera se recargaba
 * por una palabra.
 *
 * Variables: `$user`, `$uid`.
 */
$uid = (int) ($uid ?? 0);
/* Lo que va a pasar, no «cambiar el estado»: el diálogo de confirmación
 * (`views/layouts/main.php`) pinta la etiqueta de su botón con esto, y un
 * «Sí, continuar» sobre «¿Cambiar el estado?» no dice si se va a expulsar a
 * alguien o a devolverle el acceso. El tono sigue al gesto por lo mismo:
 * desactivar es lo que tiene que verse en rojo. */
$vaActivar = (int) ($user['is_active'] ?? 1) === 1;
$accion = $vaActivar ? 'Desactivar' : 'Activar';
?>
<form method="post" action="/admin/users/toggle" class="d-block"
      data-confirm="¿<?= $accion ?> la cuenta de <?= View::escape($user['name']) ?>?<?= $vaActivar ? ' No podrá entrar hasta que se la vuelva a activar.' : '' ?>"
      data-confirm-ok="<?= $accion ?>" data-confirm-tone="<?= $vaActivar ? 'danger' : 'primary' ?>" data-vf-async="slots">
    <?= \App\Core\Csrf::field() ?>
    <input type="hidden" name="user_id" value="<?= $uid ?>">
    <?php // El estado OBJETIVO, no «invertir»: es lo que hace idempotente el
          // POST (ver `User::setActive()`). ?>
    <input type="hidden" name="active" value="<?= (int) $user['is_active'] === 1 ? 0 : 1 ?>">
    <?php // `w-100` a propósito: vive dentro del menú de la fila, donde un botón
          // de ancho completo se lee mejor que un icono suelto, y además queda
          // fuera de la regla de «solo icono» en móvil (esa regla excluye los
          // `w-100`), así que conserva su texto en todas las franjas. ?>
    <button type="submit" class="btn w-100 app-form-cta <?= (int) $user['is_active'] === 1 ? 'btn-outline-warning' : 'btn-outline-success' ?>">
        <i class="fa-solid <?= (int) $user['is_active'] === 1 ? 'fa-ban' : 'fa-check' ?> me-1"></i><?= $accion ?> la cuenta
    </button>
</form>
