<?php
use App\Core\View;

// P9 — i18n reducido
$locale = $locale ?? \App\Core\I18n::resolve();
$t = static fn (string $key, array $params = []): string => \App\Core\I18n::t($key, $locale, $params);
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $t('status.draft_title') ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="<?= View::asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
</head>
<body class="fill-body">
<div class="fill-container">
    <div class="card border-0 shadow-sm app-fill-card text-center">
        <div class="card-body p-5">
            <div class="app-lock-icon mx-auto mb-3"><i class="fa-solid fa-clock"></i></div>
            <h1 class="h3 fw-bold mb-2"><?= $t('status.draft_title') ?></h1>
            <p class="text-muted mb-0"><?= $t('status.draft_note') ?></p>
        </div>
    </div>
</div>
</body>
</html>