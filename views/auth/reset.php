<?php
/**
 * F2 — Pantalla de nueva contraseña a partir del enlace del correo.
 * Datos: $token, $error, $csrf.
 *
 * El token viaja en el query (?t=) y se reenvía en un campo oculto para que el
 * POST no dependa de que el navegador mantenga la query. Es la MISMA credencial
 * en los dos sitios: por eso el servidor lo valida igual en el GET (para no
 * pintar un formulario inútil) y en el POST (que es el que manda).
 */
use App\Core\View;

$token = (string) ($token ?? '');
$error = $error ?? null;
$min = \App\Controllers\AuthController::PASSWORD_MIN;
?>
<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert">
        <i class="fa-solid fa-circle-exclamation me-1"></i><?= View::escape($error) ?>
        <a href="/forgot-password" class="alert-link ms-1">Pedir un enlace nuevo</a>
    </div>
<?php else: ?>
    <h2 class="h5 fw-bold mb-1">Elige una nueva contraseña</h2>
    <p class="text-secondary small mb-4">Mínimo <?= (int) $min ?> caracteres.</p>

    <form method="post" action="/reset-password" class="needs-validation" novalidate>
        <?= \App\Core\Csrf::field() ?>
        <input type="hidden" name="token" value="<?= View::escape($token) ?>">
        <div class="mb-3">
            <label for="password" class="form-label">Nueva contraseña</label>
            <div class="input-group">
                <span class="input-group-text app-input-icon"><i class="fa-solid fa-lock"></i></span>
                <input type="password" class="form-control" id="password" name="password" minlength="<?= (int) $min ?>" required autofocus>
            </div>
        </div>
        <div class="mb-3">
            <label for="password_confirm" class="form-label">Repite la contraseña</label>
            <div class="input-group">
                <span class="input-group-text app-input-icon"><i class="fa-solid fa-lock"></i></span>
                <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="<?= (int) $min ?>" required>
            </div>
        </div>
        <div class="d-grid gap-2 mt-4">
            <button type="submit" class="btn btn-primary btn-lg app-btn-primary"><i class="fa-solid fa-key me-1"></i>Guardar contraseña</button>
        </div>
    </form>
<?php endif; ?>
<p class="text-center text-secondary mt-4 mb-0 small">
    <a href="/login" class="text-decoration-none"><i class="fa-solid fa-arrow-left me-1"></i>Volver a iniciar sesión</a>
</p>
