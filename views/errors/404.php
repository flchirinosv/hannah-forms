<?php
use App\Core\View;
?>
<div class="text-center">
    <i class="fa-solid fa-circle-exclamation text-danger mb-3" style="font-size:3rem"></i>
    <h2 class="h4 fw-bold"><?= View::escape($message ?? 'Página no encontrada') ?></h2>
    <p class="text-muted">La página que buscas no existe o fue movida.</p>
</div>