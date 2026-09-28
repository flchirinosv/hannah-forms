<?php
use App\Core\View;
?>
<form method="post" action="/register" class="needs-validation" novalidate>
    <?= \App\Core\Csrf::field() ?>
    <div class="mb-3">
        <label for="name" class="form-label">Nombre completo</label>
        <div class="input-group">
            <span class="input-group-text app-input-icon"><i class="fa-solid fa-user"></i></span>
            <input type="text" class="form-control" id="name" name="name" placeholder="Juan Pérez" required>
        </div>
    </div>
    <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <div class="input-group">
            <span class="input-group-text app-input-icon"><i class="fa-solid fa-envelope"></i></span>
            <input type="email" class="form-control" id="email" name="email" placeholder="tucorreo@ejemplo.com" required>
        </div>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Contraseña</label>
        <div class="input-group">
            <span class="input-group-text app-input-icon"><i class="fa-solid fa-lock"></i></span>
            <input type="password" class="form-control" id="password" name="password" placeholder="Mínimo 10 caracteres" required minlength="10">
        </div>
    </div>
    <div class="mb-3">
        <label for="password_confirm" class="form-label">Confirmar contraseña</label>
        <div class="input-group">
            <span class="input-group-text app-input-icon"><i class="fa-solid fa-lock"></i></span>
            <input type="password" class="form-control" id="password_confirm" name="password_confirm" placeholder="Repite la contraseña" required>
        </div>
    </div>
    <div class="d-grid gap-2 mt-4">
        <button type="submit" class="btn btn-primary btn-lg app-btn-primary"><i class="fa-solid fa-user-plus me-1"></i>Crear cuenta</button>
    </div>
</form>
<p class="text-center text-secondary mt-4 mb-0">
    ¿Ya tienes cuenta? <a href="/login" class="text-decoration-none fw-semibold">Inicia sesión</a>
</p>