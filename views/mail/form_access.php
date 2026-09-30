<?php
/**
 * F4 — Enlace de acceso a un formulario restringido.
 * Datos: $form (['id','slug','title']), $url, $ttl (días), $locale.
 *
 * El enlace es la credencial: llega por correo precisamente para que quien lo
 * recibe sea quien abre el formulario. Por eso el texto no dice «has sido
 * invitado» ni nada que dé por hecho que hay una lista detrás.
 *
 * `$ttl` se pasa como dato y no se escribe en el texto a propósito: el plazo
 * vive en `FormAccess::TTL_DAYS`. Con el «30» escrito aquí, cambiar la constante
 * dejaría de correo que promete 30 días y concede 7.
 */
use App\Core\I18n;
use App\Core\View;

$form = is_array($form ?? null) ? $form : ['id' => 0, 'slug' => '', 'title' => ''];
$url = (string) ($url ?? '');
$ttl = (int) ($ttl ?? 0);
$locale = (string) ($locale ?? 'es');
$t = static fn (string $key, array $params = []): string => I18n::t($key, $locale, $params);
?>
<!DOCTYPE html>
<html lang="<?= View::escape($locale) ?>">
<head>
<meta charset="utf-8">
<title><?= $t('mail.access_subject') ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#212529;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;padding:24px 0;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" style="max-width:600px;background:#ffffff;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
        <tr>
          <td style="background:#0d6efd;padding:18px 24px;">
            <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:700;"><?= $t('mail.access_title') ?></h1>
          </td>
        </tr>
        <tr>
          <td style="padding:24px;">
            <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">
              <?= $t('mail.access_body') ?>
            </p>

            <p style="margin:0 0 20px;font-size:16px;line-height:1.4;font-weight:700;">
              <?= View::escape((string) $form['title']) ?>
            </p>

            <p style="margin:0 0 24px;">
              <a href="<?= View::escape($url) ?>"
                 style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-size:15px;font-weight:700;">
                <?= $t('mail.access_cta') ?>
              </a>
            </p>

            <p style="margin:0 0 24px;padding-top:16px;border-top:1px solid #e9ecef;font-size:12px;word-break:break-all;line-height:1.5;">
              <a href="<?= View::escape($url) ?>" style="color:#0d6efd;"><?= View::escape($url) ?></a>
            </p>

            <p style="margin:0;font-size:13px;color:#6c757d;line-height:1.5;">
              <?= $t('mail.access_ttl', ['ttl' => $ttl]) ?>
              <br>
              <?= $t('mail.access_ignore') ?>
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
