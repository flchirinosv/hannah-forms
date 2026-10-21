<?php
use App\Core\View;

// P9 — i18n: el controller pasa el idioma efectivo (settings.locale > app).
$locale = $locale ?? \App\Core\I18n::resolve();

// Ambos headers/nombres se usan para "no abierto" y "cerrado" según calendario (P6).
$heading = $heading ?? \App\Core\I18n::t('status.closed_title', $locale);
$note = $note ?? \App\Core\I18n::t('status.closed_note', $locale);
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::escape($heading) ?></title>
    <?= View::favicon() ?>
    <link rel="stylesheet" href="<?= View::asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
</head>
<body class="fill-body">
<div class="fill-container">
    <div class="card border-0 shadow-sm app-fill-card text-center">
        <div class="card-body p-5">
            <div class="app-lock-icon mx-auto mb-3"><i class="fa-solid fa-lock"></i></div>
            <h1 class="h3 fw-bold mb-2"><?= View::escape($heading) ?></h1>
            <p class="text-muted mb-0"><?= \App\Core\I18n::t('ui.form', $locale) ?> <strong><?= View::escape($form['title']) ?></strong> <?= View::escape($note) ?></p>
        </div>
    </div>
</div>
</body>
</html>