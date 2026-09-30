<?php
use App\Core\View;
?>
<form method="post" action="/login" class="needs-validation" novalidate>
    <?= \App\Core\Csrf::field() ?>
    <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <div class="input-group">
            <span class="input-group-text app-input-icon"><i class="fa-solid fa-envelope"></i></span>
            <input type="email" class="form-control" id="email" name="email" placeholder="tucorreo@ejemplo.com" required autofocus>
        </div>
    </div>
    <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center">
            <label for="password" class="form-label">Contraseña</label>
            <a href="/forgot-password" class="small text-decoration-none">¿Olvidaste tu contraseña?</a>
        </div>
        <div class="input-group">
            <span class="input-group-text app-input-icon"><i class="fa-solid fa-lock"></i></span>
            <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
        </div>
    </div>
    <div class="d-grid gap-2 mt-4">
        <button type="submit" class="btn btn-primary btn-lg app-btn-primary"><i class="fa-solid fa-right-to-bracket me-1"></i>Iniciar sesión</button>
    </div>
</form>
<?php // El enlace solo aparece si el registro está abierto: con la puerta
      // apagada, ofrecerla y llevar a un 403 es una vía muerta. El alta la
      // hace el admin desde /admin. ?>
<?php if (\App\Core\Config::get('app.registration_enabled', false)): ?>
<p class="text-center text-secondary mt-4 mb-0">
    ¿No tienes cuenta? <a href="/register" class="text-decoration-none fw-semibold">Regístrate aquí</a>
</p>
<?php endif; ?>