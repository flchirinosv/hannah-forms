<?php
use App\Core\View;
?>
<div class="text-center">
    <i class="fa-solid fa-bug text-danger mb-3" style="font-size:3rem"></i>
    <h2 class="h4 fw-bold"><?= View::escape($message ?? 'Error del servidor') ?></h2>
    <p class="text-muted">Vuelve a intentarlo. Si el error se repite, avisa a quien administre la instalación.</p>
</div>
