<?php
use App\Core\View;
?>
<div class="text-center">
    <i class="fa-solid fa-lock text-warning mb-3" style="font-size:3rem"></i>
    <h2 class="h4 fw-bold"><?= View::escape($message ?? 'Acceso denegado') ?></h2>
    <p class="text-muted">Si crees que deberías poder verlo, pide acceso a quien administre la instalación.</p>
</div>
