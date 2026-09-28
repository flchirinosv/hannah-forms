<?php
use App\Core\View;

$config = \App\Core\Config::all();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($message) ? View::escape($message) : 'Error' ?> — <?= View::escape($config['app']['name']) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="<?= View::asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
</head>
<body class="auth-body">
    <div class="auth-wrapper">
        <div class="text-center text-white">
            <div class="display-1 fw-bold error-code"><?= http_response_code() ?: 500 ?></div>
            <h2 class="h4 mt-2"><?= View::escape($message ?? 'Error') ?></h2>
            <a href="/" class="btn btn-light btn-lg mt-3"><i class="fa-solid fa-house me-1"></i>Volver al inicio</a>
        </div>
    </div>
</body>
</html>