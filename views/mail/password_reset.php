<?php
/**
 * F2 — Correo de recuperación de contraseña.
 * Datos: $name, $url, $ttl, $locale.
 *
 * Plantilla de correo: nada de `style` en un atributo `<a>` no es problema aquí
 * porque los correos no llevan la CSP de la app (solo las respuestas web), pero
 * sí usa estilos EN LÍNEA en el `<style>` de la cabecera, que es lo que permite
 * que un correo se pinte en Outlook.
 */
use App\Core\I18n;
use App\Core\View;

$name = (string) ($name ?? '');
$url = (string) ($url ?? '');
$ttl = (int) ($ttl ?? 1);
$locale = $locale ?? 'es';
$t = static fn (string $key, array $params = []): string => I18n::t($key, $locale, $params);
?>
<!DOCTYPE html>
<html lang="<?= View::escape((string) $locale) ?>">
<head>
<meta charset="utf-8">
<title><?= $t('mail.reset_subject') ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#212529;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;padding:24px 0;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" style="max-width:600px;background:#ffffff;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
        <tr>
          <td style="background:#0d6efd;padding:18px 24px;">
            <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:700;"><?= $t('mail.reset_title') ?></h1>
          </td>
        </tr>
        <tr>
          <td style="padding:24px;">
            <p style="margin:0 0 16px;font-size:14px;line-height:1.5;"><?= $t('mail.reset_hello', ['name' => $name]) ?></p>

            <p style="margin:0 0 20px;font-size:14px;line-height:1.5;">
              <?= str_replace('{ttl}', (string) $ttl, $t('mail.reset_body')) ?>
            </p>

            <p style="margin:0 0 24px;">
              <a href="<?= View::escape($url) ?>"
                 style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-size:15px;font-weight:700;">
                <?= $t('mail.reset_cta') ?>
              </a>
            </p>

            <p style="margin:0 0 8px;font-size:13px;color:#6c757d;line-height:1.5;"><?= $t('mail.reset_fallback') ?></p>
            <p style="margin:0 0 24px;font-size:12px;word-break:break-all;line-height:1.5;">
              <a href="<?= View::escape($url) ?>" style="color:#0d6efd;"><?= View::escape($url) ?></a>
            </p>

            <p style="margin:0;padding-top:16px;border-top:1px solid #e9ecef;font-size:13px;color:#6c757d;line-height:1.5;">
              <?= $t('mail.reset_ignore') ?>
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
