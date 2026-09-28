<?php
/**
 * Correo de confirmación al respondiente (P5).
 * Datos: $formTitle, $message (mensaje personalizado; vacío = por defecto).
 */
use App\Core\I18n;
use App\Core\View;

$formTitle = $formTitle ?? '';
$message = trim((string) ($message ?? ''));

// P9 — i18n reducido: el correo sale en el idioma del formulario.
$locale = $locale ?? 'es';
$t = static fn (string $key, array $params = []): string => I18n::t($key, $locale, $params);
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>">
<head>
<meta charset="utf-8">
<title><?= $t('mail.conf_subject') ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#212529;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;padding:24px 0;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" style="max-width:600px;background:#ffffff;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
        <tr>
          <td style="background:#198754;padding:18px 24px;">
            <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:700;"><?= $t('mail.conf_title') ?></h1>
          </td>
        </tr>
        <tr>
          <td style="padding:24px;font-size:14px;line-height:1.6;white-space:pre-wrap;">
            <?= $message !== '' ? View::escape($message) : str_replace('{title}', View::escape($formTitle), $t('mail.conf_body')) ?>
          </td>
        </tr>
        <tr>
          <td style="background:#f8f9fa;padding:12px 24px;font-size:12px;color:#adb5bd;">
            VanillaForms
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>