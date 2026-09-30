<?php
/**
 * F3 — Aviso de que un formulario espera revisión.
 * Datos: $form (['id','title']), $owner, $url, $locale.
 *
 * El enlace va al panel, NO al enlace público: desde `/f/{slug}` se vería la
 * página de «en revisión», y el revisor no podría hacer nada con ella. Es
 * además la razón por la que el aviso es opcional: en una instalación de un
 * solo usuario el correo solo puede llegar a uno mismo.
 */
use App\Core\I18n;
use App\Core\View;

$form = is_array($form ?? null) ? $form : ['id' => 0, 'title' => ''];
$owner = (string) ($owner ?? '');
$url = (string) ($url ?? '');
$locale = (string) ($locale ?? 'es');
$t = static fn (string $key, array $params = []): string => I18n::t($key, $locale, $params);
?>
<!DOCTYPE html>
<html lang="<?= View::escape($locale) ?>">
<head>
<meta charset="utf-8">
<title><?= $t('mail.review_subject') ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#212529;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;padding:24px 0;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" style="max-width:600px;background:#ffffff;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
        <tr>
          <td style="background:#4f46e5;padding:18px 24px;">
            <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:700;"><?= $t('mail.review_title') ?></h1>
          </td>
        </tr>
        <tr>
          <td style="padding:24px;">
            <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">
              <?= $t('mail.review_body', ['owner' => $owner]) ?>
            </p>

            <p style="margin:0 0 20px;font-size:16px;line-height:1.4;font-weight:700;">
              <?= View::escape((string) $form['title']) ?>
            </p>

            <p style="margin:0 0 24px;">
              <a href="<?= View::escape($url) ?>"
                 style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-size:15px;font-weight:700;">
                <?= $t('mail.review_cta') ?>
              </a>
            </p>

            <p style="margin:0 0 24px;padding-top:16px;border-top:1px solid #e9ecef;font-size:12px;word-break:break-all;line-height:1.5;">
              <a href="<?= View::escape($url) ?>" style="color:#4f46e5;"><?= View::escape($url) ?></a>
            </p>

            <p style="margin:0;font-size:13px;color:#6c757d;line-height:1.5;">
              <?= $t('mail.review_ignore') ?>
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
