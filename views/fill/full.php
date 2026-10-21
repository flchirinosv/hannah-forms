<?php
use App\Core\View;

// P9 — i18n reducido (estado "cupo agotado").
$locale = $locale ?? \App\Core\I18n::resolve();
$t = static fn (string $key, array $params = []): string => \App\Core\I18n::t($key, $locale, $params);
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $t('status.full_title') ?></title>
    <?= View::favicon() ?>
    <link rel="stylesheet" href="<?= View::asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
</head>
<body class="fill-body">
<div class="fill-container">
    <div class="card border-0 shadow-sm app-fill-card text-center">
        <div class="card-body p-5">
            <div class="app-lock-icon mx-auto mb-3"><i class="fa-solid fa-ban"></i></div>
            <h1 class="h3 fw-bold mb-2"><?= $t('status.full_title') ?></h1>
            <p class="text-muted mb-0"><?= $t('ui.form') ?> <strong><?= View::escape($form['title']) ?></strong> <?= $t('status.full_note') ?></p>
        </div>
    </div>
</div>
</body>
</html>