<?php
/**
 * F2 — Cambio de contraseña desde la sesión.
 * Datos: $user, $csrf.
 *
 * Exige la contraseña ACTUAL a propósito: sin ella, un XSS o un equipo
 * compartido del que alguien se marcha con la sesión abierta permiten dejar
 * la cuenta atrapada con una contraseña que solo el atacante conoce.
 */
use App\Core\View;

$user = is_array($user ?? null) ? $user : [];
$min = \App\Controllers\AuthController::PASSWORD_MIN;
?>
<div class="container px-lg-4" style="max-width: 720px;">
    <div class="mb-4">
        <h2 class="h4 fw-bold mb-0"><i class="fa-solid fa-user-shield me-2 text-primary"></i>Seguridad de la cuenta</h2>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h3 class="h6 fw-bold mb-3">Cambiar contraseña</h3>
            <form method="post" action="/account/password" class="needs-validation" novalidate>
                <?= \App\Core\Csrf::field() ?>
                <div class="mb-3">
                    <label for="current_password" class="form-label">Contraseña actual</label>
                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                    <div class="form-text">Se pide para confirmar que eres tú: con la sesión abierta basta para cambiar el correo, no la contraseña.</div>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Nueva contraseña</label>
                    <input type="password" class="form-control" id="password" name="password" minlength="<?= (int) $min ?>" required>
                    <div class="form-text">Mínimo <?= (int) $min ?> caracteres, y distinta de la actual.</div>
                </div>
                <div class="mb-3">
                    <label for="password_confirm" class="form-label">Repite la nueva contraseña</label>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="<?= (int) $min ?>" required>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-key me-1"></i>Cambiar contraseña</button>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body small text-secondary">
            <h3 class="h6 fw-bold mb-2"><i class="fa-solid fa-circle-info me-1"></i>Al cambiarla</h3>
            <ul class="mb-0 ps-3">
                <li>Se cierran todas las demás sesiones abiertas, en este y en otros dispositivos.</li>
                <li>Se invalidan los enlaces de recuperación que tuvieras pedido antes.</li>
            </ul>
        </div>
    </div>
</div>
