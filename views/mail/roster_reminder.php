<?php

declare(strict_types=1);

/**
 * Fase 12 — Recordatorio por correo a quien recibió la invitación y no ha respondido.
 *
 * Datos: $form (['id','slug','title']), $url, $nombre, $locale, $deadline (string, '' si no hay).
 *
 * El recordatorio es repetible: se manda a quien ya fue invitado y no ha respondido.
 * No dice «se te ha invitado a un formulario», sigue el mismo criterio que la
 * invitación (Fase 10): el enlace es la credencial y no se revela la lista.
 * `$deadline` se cita si existe, en la hora de la zona de la app.
 */
use App\Core\I18n;
use App\Core\View;

$form = is_array($form ?? null) ? $form : ['id' => 0, 'slug' => '', 'title' => ''];
$url = (string) ($url ?? '');
$nombre = trim((string) ($nombre ?? ''));
$locale = (string) ($locale ?? 'es');
$deadline = trim((string) ($deadline ?? ''));
$t = static fn (string $key, array $params = []): string => I18n::t($key, $locale, $params);

$greeting = $nombre === ''
    ? ''
    : '<p style="margin:0 0 12px;font-size:14px;line-height:1.5;font-weight:700;">'
        . View::escape($t('mail.remind_greeting', ['nombre' => $nombre]))
        . '</p>';

$deadlineBlock = $deadline === ''
    ? ''
    : '<p style="margin:0 0 16px;font-size:13px;line-height:1.5;color:#495057;">'
        . View::escape($t('mail.remind_deadline', ['fecha' => $deadline]))
        . '</p>';
?>
<!DOCTYPE html>
<html lang="<?= View::escape($locale) ?>">
<head>
<meta charset="utf-8">
<title><?= $t('mail.remind_subject') ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#212529;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;padding:24px 0;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" style="max-width:600px;background:#ffffff;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
        <tr>
          <td style="background:#0d6efd;padding:18px 24px;">
            <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:700;"><?= $t('mail.remind_title') ?></h1>
          </td>
        </tr>
        <tr>
          <td style="padding:24px;">
            <?= $greeting ?>

            <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">
              <?= $t('mail.remind_body') ?>
            </p>

            <?= $deadlineBlock ?>

            <p style="margin:0 0 20px;font-size:16px;line-height:1.4;font-weight:700;">
              <?= View::escape((string) $form['title']) ?>
            </p>

            <p style="margin:0 0 24px;">
              <a href="<?= View::escape($url) ?>"
                 style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-size:15px;font-weight:700;">
                <?= $t('mail.remind_cta') ?>
              </a>
            </p>

            <p style="margin:0 0 24px;padding-top:16px;border-top:1px solid #e9ecef;font-size:12px;word-break:break-all;line-height:1.5;">
              <a href="<?= View::escape($url) ?>" style="color:#0d6efd;"><?= View::escape($url) ?></a>
            </p>

            <p style="margin:0;font-size:13px;color:#6c757d;line-height:1.5;">
              <?= $t('mail.remind_ignore') ?>
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>