<?php
use App\Core\Brand;
use App\Core\View;

$appName = Brand::name();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::escape($appName) ?></title>
    <?= View::favicon() ?>
    <link rel="stylesheet" href="<?= View::asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
    <?= View::brandStyle() ?>
</head>
<body class="auth-body">
    <div class="auth-wrapper">
        <div class="auth-card card shadow-lg border-0">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <a href="/" class="d-inline-block mb-3">
                        <?= View::brandLogo('app-logo-lg') ?>
                    </a>
                    <h1 class="h3 fw-bold app-brand-title"><?= View::escape($appName) ?></h1>
                </div>

                <?php if (\App\Core\Session::hasFlash('success')): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= View::escape(\App\Core\Session::getFlash('success')) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if (\App\Core\Session::hasFlash('error')): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= View::escape(\App\Core\Session::getFlash('error')) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?= $content ?>
            </div>
        </div>
        <p class="text-center text-secondary mt-3 small">Vanilla PHP · MySQL · nginx</p>
    </div>

<script src="<?= View::asset('js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>