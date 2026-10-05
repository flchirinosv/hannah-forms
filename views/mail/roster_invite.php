<?php
/**
 * Fase 10 — Invitación al formulario de un participante del listado.
 *
 * Datos: $form (['id','slug','title']), $url, $nombre, $locale.
 *
 * ## Por qué no dice «se te ha invitado a un formulario»
 *
 * El correo de F4 (`form_access.php`) tiene el mismo cuidado: el texto no dice que
 * hay una lista detrás porque el enlace ES la credencial y el que lo recibe no
 * tiene por qué saber cuántos son. Con un participante del listado la tentación es
 * el doble, porque el dueño de la lista sí sabe cuántos son y el texto sale de SU
 * pantalla: un «Has sido invitado al proceso de selección» convertiría un enlace
 * en un correo que además dice de qué va la lista y para qué sirve la respuesta.
 *
 * ## `$nombre` puede venir vacío y NO es un error
 *
 * Una fila del listado puede tener cédula y ningún nombre. El saludo lo decide la
 * plantilla (`$greeting`), no el que la llama: escribir «Hola ,» es peor que no
 * escribir saludo, y quien no ha dado su nombre tampoco espera que se le llame
 * por uno.
 *
 * Lo que NO viaja aquí es el identificador del formulario. Se comprueba en el
 * controlador (que solo ofrece esto en modo `correo`) y en el correo no aportaría
 * nada: quien lo recibe ya sabe cuál es el suyo, y ponerlo convertiría el mensaje
 * en la ficha técnica de la lista.
 */
use App\Core\I18n;
use App\Core\View;

$form = is_array($form ?? null) ? $form : ['id' => 0, 'slug' => '', 'title' => ''];
$url = (string) ($url ?? '');
$nombre = trim((string) ($nombre ?? ''));
$locale = (string) ($locale ?? 'es');
$t = static fn (string $key, array $params = []): string => I18n::t($key, $locale, $params);

$greeting = $nombre === ''
    ? ''
    : '<p style="margin:0 0 12px;font-size:14px;line-height:1.5;font-weight:700;">'
        . View::escape($t('mail.invite_greeting', ['nombre' => $nombre]))
        . '</p>';
?>
<!DOCTYPE html>
<html lang="<?= View::escape($locale) ?>">
<head>
<meta charset="utf-8">
<title><?= $t('mail.invite_subject') ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#212529;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;padding:24px 0;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" style="max-width:600px;background:#ffffff;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
        <tr>
          <td style="background:#198754;padding:18px 24px;">
            <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:700;"><?= $t('mail.invite_title') ?></h1>
          </td>
        </tr>
        <tr>
          <td style="padding:24px;">
            <?= $greeting ?>

            <p style="margin:0 0 16px;font-size:14px;line-height:1.5;">
              <?= $t('mail.invite_body') ?>
            </p>

            <p style="margin:0 0 20px;font-size:16px;line-height:1.4;font-weight:700;">
              <?= View::escape((string) $form['title']) ?>
            </p>

            <p style="margin:0 0 24px;">
              <a href="<?= View::escape($url) ?>"
                 style="display:inline-block;background:#198754;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-size:15px;font-weight:700;">
                <?= $t('mail.invite_cta') ?>
              </a>
            </p>

            <p style="margin:0 0 24px;padding-top:16px;border-top:1px solid #e9ecef;font-size:12px;word-break:break-all;line-height:1.5;">
              <a href="<?= View::escape($url) ?>" style="color:#198754;"><?= View::escape($url) ?></a>
            </p>

            <p style="margin:0;font-size:13px;color:#6c757d;line-height:1.5;">
              <?= $t('mail.invite_ignore') ?>
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
