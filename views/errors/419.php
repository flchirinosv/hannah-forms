<?php
use App\Core\View;
?>
<div class="text-center">
    <i class="fa-solid fa-clock-rotate-left text-warning mb-3" style="font-size:3rem"></i>
    <h2 class="h4 fw-bold"><?= View::escape($message ?? 'Sesión expirada') ?></h2>
    <p class="text-muted">Recarga la página y vuelve a intentarlo. Si se repite, entra de nuevo.</p>
</div>
