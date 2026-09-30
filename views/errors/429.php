<?php
use App\Core\View;
?>
<div class="text-center">
    <i class="fa-solid fa-hourglass-half text-warning mb-3" style="font-size:3rem"></i>
    <h2 class="h4 fw-bold"><?= View::escape($message ?? 'Demasiadas peticiones') ?></h2>
    <p class="text-muted">Has alcanzado el límite de peticiones por hora. Intenta más tarde.</p>
</div>
