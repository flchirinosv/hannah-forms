<?php
use App\Core\View;
?>
<div class="text-center">
    <i class="fa-solid fa-shield-halved text-warning mb-3" style="font-size:3rem"></i>
    <h2 class="h4 fw-bold"><?= View::escape($message ?? '403 Error') ?></h2>
    <p class="text-muted"><?= View::escape($message ?? 'Ocurrió un error.') ?></p>
</div>
