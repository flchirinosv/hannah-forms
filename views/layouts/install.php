<?php
use App\Core\View;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación · VanillaForms</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="<?= View::asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
</head>
<body class="auth-body">
    <div class="auth-wrapper">
        <div class="auth-card card shadow-lg border-0" style="max-width: 640px;">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <i class="fa-solid fa-wand-magic-sparkles fa-2x app-install-icon" aria-hidden="true"></i>
                    <h1 class="h3 fw-bold app-brand-title mt-2">Instalación de VanillaForms</h1>
                    <p class="text-secondary mb-0 small">Configura tu instancia sin tocar la terminal.</p>
                </div>

                <?= $content ?>
            </div>
        </div>
        <p class="text-center text-secondary mt-3 small">Vanilla PHP · MySQL · nginx</p>
    </div>
</body>
</html>
