<?php
use App\Core\View;
?>
<form method="post" action="/forgot-password" class="needs-validation" novalidate>
    <?= \App\Core\Csrf::field() ?>
    <p class="text-secondary small mb-4">
        Escribe el email con el que te registraste y te enviamos un enlace para
        cambiar la contraseña. El enlace caduca en <?= \App\Models\PasswordReset::TTL_HOURS ?> hora<?= \App\Models\PasswordReset::TTL_HOURS === 1 ? '' : 's' ?>.
    </p>
    <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <div class="input-group">
            <span class="input-group-text app-input-icon"><i class="fa-solid fa-envelope"></i></span>
            <input type="email" class="form-control" id="email" name="email" placeholder="tucorreo@ejemplo.com" required autofocus>
        </div>
    </div>
    <div class="d-grid gap-2 mt-4">
        <button type="submit" class="btn btn-primary btn-lg app-btn-primary"><i class="fa-solid fa-paper-plane me-1"></i>Enviar enlace</button>
    </div>
</form>
<p class="text-center text-secondary mt-4 mb-0 small">
    <a href="/login" class="text-decoration-none"><i class="fa-solid fa-arrow-left me-1"></i>Volver a iniciar sesión</a>
</p>
