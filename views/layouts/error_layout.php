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
            <?php // `View::render` ya deja el HTML de `views/errors/{code}.php` en
                  // `$content` (ver View::render). Este layout lo imprimía solo
                  // `$message`, así que las ocho vistas de error eran markup
                  // muerto: sus iconos y sus textos explicativos no salían nunca,
                  // y una llamada sin mensaje pintaba literalmente «Error».
                  // Cada error es dueño de su título y de su párrafo; aquí solo
                  // va el código grande y el botón de vuelta. ?>
            <?php if (isset($content) && trim($content) !== ''): ?>
                <?= $content ?>
            <?php else: ?>
                <h2 class="h4 mt-2"><?= View::escape($message ?? 'Error') ?></h2>
            <?php endif; ?>
            <a href="/" class="btn btn-light btn-lg mt-3"><i class="fa-solid fa-house me-1"></i>Volver al inicio</a>
        </div>
    </div>
</body>
</html>