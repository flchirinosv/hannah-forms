<?php
use App\Core\View;
?>
<div class="text-center">
    <i class="fa-solid fa-file-circle-xmark text-warning mb-3" style="font-size:3rem"></i>
    <h2 class="h4 fw-bold"><?= View::escape($message ?? 'Envío demasiado grande') ?></h2>
    <p class="text-muted">El envío supera el tamaño máximo permitido por el servidor. Intenta con archivos más pequeños.</p>
</div>
