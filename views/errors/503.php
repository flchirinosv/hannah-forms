<?php
use App\Core\View;
?>
<div class="text-center">
    <i class="fa-solid fa-server text-warning mb-3" style="font-size:3rem"></i>
    <h2 class="h4 fw-bold"><?= View::escape($message ?? 'Servicio no disponible') ?></h2>
    <p class="text-muted">El servicio está en mantenimiento o saturado. Inténtalo de nuevo en unos minutos.</p>
</div>
