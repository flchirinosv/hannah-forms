<?php
/**
 * F4 — Identificación previa de un formulario restringido.
 *
 * Datos: $form, $locale, $csrf, $mode ('email'|'allowlist'|'roster'), $embed,
 * $sent (bool), $sentMessage (string), $errorMessage (?string), $oldEmail (?string).
 *
 * La tercera rama (fase 4, `roster`) pide un **código** en vez de un correo. Las
 * dos primeras no se tocan ni un carácter: el texto, el campo y el destino del
 * formulario siguen siendo exactamente los de antes, porque la regla del proyecto
 * es que las ramas de acceso existentes quedan byte a byte intactas.
 *
 * Esta página NO es el formulario: es la puerta. Por eso no lleva `fill.js`
 * (no hay nada que avanzar) ni los scripts de la barra de progreso, y por eso el
 * `<form>` es un POST normal contra `/f/{slug}/access`, no el `vfForm`.
 *
 * El texto depende del modo y no se unifica a propósito: el respondiente SÍ tiene
 * que saber si el formulario es "para cualquiera con un correo" o "solo para los
 * que están en la lista", porque de eso depende si tiene que esperar un correo o
 * escribir a quien le dígitos. Lo que NO se dice nunca es si un email concreto
 * está en la lista: esa respuesta la da el correo, uniformemente para todos
 * (ver `AccessController::request()`).
 */
use App\Core\I18n;
use App\Core\View;
use App\Services\FormSettings;

// Cualquier modo que no exista cae en `email`, que es lo que hacía esta línea antes
// de que `roster` existiera. La comprobación es contra `ACCESS_MODES` (los cuatro)
// y no contra los que la puerta entiende: un modo futuro que se guarde sin puerta
// tiene que seguir siendo servible, no dejar la vista sin `$mode` reconocible.
$mode = in_array((string) ($mode ?? ''), FormSettings::ACCESS_MODES, true)
    ? (string) $mode
    : FormSettings::ACCESS_EMAIL;
$embed = !empty($embed);
$isAllowlist = $mode === FormSettings::ACCESS_ALLOWLIST;
$isRoster = $mode === FormSettings::ACCESS_ROSTER;
$t = static fn (string $key, array $params = []): string => I18n::t($key, $locale, $params);
?>
<!DOCTYPE html>
<html lang="<?= View::escape($locale) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::escape($t('access.title')) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="<?= View::asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
</head>
<body class="fill-body">
<div class="fill-container">
    <div class="card border-0 shadow-sm app-fill-card">
        <div class="card-body p-4 p-md-5">

            <div class="text-center mb-4">
                <div class="app-lock-icon mx-auto mb-3">
                    <i class="fa-solid <?= $isRoster ? 'fa-ticket' : 'fa-envelope' ?>"></i>
                </div>
                <h1 class="h4 fw-bold mb-2"><?= View::escape($t('access.title')) ?></h1>
                <p class="text-muted mb-0">
                    <strong><?= View::escape((string) $form['title']) ?></strong>
                </p>
            </div>

            <?php if (!empty($sent)): ?>
                <div class="alert alert-success border-0 shadow-sm app-fill-alert" role="alert">
                    <h2 class="h6 fw-bold mb-1"><i class="fa-solid fa-paper-plane me-1"></i><?= View::escape($t('access.sent_title')) ?></h2>
                    <p class="mb-0 small"><?= View::escape($sentMessage) ?></p>
                </div>
            <?php else: ?>
                <p class="small text-muted mb-3">
                    <?= View::escape($isRoster
                        ? $t('access.intro_roster')
                        : ($isAllowlist ? $t('access.intro_allowlist') : $t('access.intro_email'))) ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger border-0 shadow-sm app-fill-alert" role="alert">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <?php // Tras un envío correcto se oculta el formulario: volver a escribir
                  // el mismo correo no añade nada y sí gasta el rate limit del
                  // propio usuario, que es el que va a necesitar para reintentar
                  // si el correo se pierde. ?>
            <?php if (empty($sent)): ?>
            <?php /* El destino depende del modo y solo del modo: el código se
                     canjea en `/f/{slug}/code`, que es un PRG que redirige a la
                     puerta con `?t=`. En un embed NO hay variante con `/embed`
                     porque el canje redirige el frame entero, que es justo lo que
                     se quiere. */ ?>
            <form method="post"
                  action="/f/<?= View::escape((string) $form['slug']) ?><?= $isRoster
                      ? '/code'
                      : '/access' . ($embed ? '/embed' : '') ?>"
                  id="vfAccessForm" novalidate>
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="lang" value="<?= View::escape($locale) ?>">
                <?php if ($isRoster): ?>
                <div class="mb-3">
                    <label for="vfAccessCode" class="form-label fw-semibold small">
                        <?= View::escape($t('access.code_label')) ?>
                    </label>
                    <input type="text" name="code" id="vfAccessCode" class="form-control"
                           required autofocus autocomplete="off" autocapitalize="characters"
                           spellcheck="false" maxlength="32"
                           value="<?= View::escape((string) ($oldCode ?? '')) ?>"
                           placeholder="<?= View::escape($t('access.code_placeholder')) ?>">
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fa-solid fa-ticket me-2"></i><?= View::escape($t('access.cta_code')) ?>
                </button>
                <?php else: ?>
                <div class="mb-3">
                    <label for="vfAccessEmail" class="form-label fw-semibold small">
                        <?= View::escape($t('access.email_label')) ?>
                    </label>
                    <input type="email" name="email" id="vfAccessEmail" class="form-control"
                           required autocomplete="email" inputmode="email" autofocus
                           maxlength="190"
                           value="<?= View::escape((string) ($oldEmail ?? '')) ?>"
                           placeholder="<?= View::escape($t('access.email_placeholder')) ?>">
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fa-solid fa-paper-plane me-2"></i><?= View::escape($t('access.cta')) ?>
                </button>
                <?php endif; ?>
            </form>
            <?php endif; ?>

            <p class="small text-muted mt-3 mb-0">
                <?= View::escape($isRoster
                    ? $t('access.note_roster')
                    : ($isAllowlist ? $t('access.note_allowlist') : $t('access.note_email'))) ?>
            </p>

        </div>
    </div>
</div>
</body>
</html>
