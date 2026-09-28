<?php
use App\Core\View;
?>
<div class="text-center">
    <i class="fa-solid fa-ban text-warning mb-3" style="font-size:3rem"></i>
    <h2 class="h4 fw-bold"><?= View::escape($message ?? 'Método no permitido') ?></h2>
    <p class="text-muted">Esta ruta existe, pero la solicitud usa un método HTTP no admitido.</p>
</div>