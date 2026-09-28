<?php
/**
 * Correo de notificación al dueño del formulario (P5).
 * Datos: $formTitle, $submissionId, $submittedAt, $rows (listas [label, value]).
 */
use App\Core\I18n;
use App\Core\View;

$formTitle = $formTitle ?? '';
$submissionId = $submissionId ?? '';
$submittedAt = $submittedAt ?? '';
$rows = $rows ?? [];

// P9 — i18n reducido: el correo sale en el idioma del formulario.
$locale = $locale ?? 'es';
$t = static fn (string $key, array $params = []): string => I18n::t($key, $locale, $params);
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>">
<head>
<meta charset="utf-8">
<title><?= $t('mail.notif_subject') ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#212529;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;padding:24px 0;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" style="max-width:600px;background:#ffffff;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
        <tr>
          <td style="background:#0d6efd;padding:18px 24px;">
            <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:700;"><?= $t('mail.notif_title') ?></h1>
          </td>
        </tr>
        <tr>
          <td style="padding:24px;">
            <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">
              <?= str_replace('{title}', '<strong>' . View::escape($formTitle) . '</strong>', $t('mail.notif_intro')) ?>
            </p>
            <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
              <tr>
                <td style="border-bottom:1px solid #e9ecef;color:#6c757d;width:180px;"><?= $t('mail.response_id') ?></td>
                <td style="border-bottom:1px solid #e9ecef;"><strong><?= View::escape((string) $submissionId) ?></strong></td>
              </tr>
              <tr>
                <td style="border-bottom:1px solid #e9ecef;color:#6c757d;"><?= $t('mail.date') ?></td>
                <td style="border-bottom:1px solid #e9ecef;"><?= View::escape($submittedAt) ?></td>
              </tr>
            </table>

            <?php if ($rows !== []): ?>
            <table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin-top:16px;">
              <?php foreach ($rows as $row): ?>
              <tr>
                <td style="border-top:1px solid #e9ecef;color:#6c757d;width:180px;vertical-align:top;">
                  <?= View::escape((string) ($row['label'] ?? '')) ?>
                </td>
                <td style="border-top:1px solid #e9ecef;vertical-align:top;white-space:pre-wrap;">
                  <?= View::escape((string) ($row['value'] ?? '')) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </table>
            <?php endif; ?>
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