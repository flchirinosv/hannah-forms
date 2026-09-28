<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\I18n;
use App\Core\Mailer;
use App\Core\View;

/**
 * Notifier
 *
 * Dispara las notificaciones post-envío de una respuesta (P5):
 * - Aviso por correo al/los dueño(s): settings.notify.emails[].
 * - Confirmación al respondiente: settings.notify.confirmation (usa el valor
 *   del campo indicado en to_field como destinatario).
 *
 * Reglas de robustez:
 * - Nunca lanza excepciones hacia FillController (las captura y registra).
 * - Si el SMTP no está configurado, omite el envío y lo registra.
 * - Cualquier fallo de SMTP por destinatario se aísla.
 */
final class Notifier
{
    /**
     * Punto de entrada post-commit (FASE 3 de FillController::submit).
     *
     * @param array            $form         Fila completas del formulario (id, title, slug, fields)
     * @param array            $settings     Settings normalizados (FormSettings::normalize)
     * @param int              $submissionId ID de la respuesta persistida
     * @param array            $validated    Campos validados (id_campo => valor string)
     * @param string           $submittedAt  Fecha/hora del envío (Y-m-d H:i:s)
     */
    public static function afterSubmit(
        array $form,
        array $settings,
        int $submissionId,
        array $validated,
        string $submittedAt
    ): void {
        $enabled = !empty($settings['notify']['enabled'])
            || !empty($settings['notify']['confirmation']['enabled']);

        if (!$enabled) {
            return;
        }

        // P9 — i18n reducido: los correos salen en el idioma del formulario.
        $locale = I18n::resolve($settings);

        self::notifyOwner($form, $settings, $submissionId, $validated, $submittedAt, $locale);
        self::sendConfirmation($form, $settings, $validated, $submittedAt, $locale);
    }

    private static function notifyOwner(
        array $form,
        array $settings,
        int $submissionId,
        array $validated,
        string $submittedAt,
        string $locale = 'es'
    ): void {
        $notify = $settings['notify'] ?? [];
        if (empty($notify['enabled'])) {
            return;
        }

        $emails = array_values(array_filter(array_map(
            static fn ($e) => trim((string) $e),
            (array) ($notify['emails'] ?? [])
        ), static fn (string $e): bool => $e !== ''));

        if ($emails === []) {
            return;
        }
        if (!Mailer::isConfigured()) {
            self::log('Notificación omitida: SMTP no configurado.');
            return;
        }

        $formTitle = (string) ($form['title'] ?? '');
        $subject = I18n::t('mail.notif_subject', $locale) . ($formTitle !== '' ? ': ' . $formTitle : '');
        $subject = self::truncate($subject, 190);

        $html = self::renderMail('mail.notification', [
            'locale'       => $locale,
            'formTitle'    => $formTitle,
            'submissionId' => $submissionId,
            'submittedAt'  => $submittedAt,
            'rows'         => self::answerRows($form, $validated),
        ]);

        $alt = self::altSummary($formTitle, $submissionId, $submittedAt, self::answerRows($form, $validated), $locale);

        foreach ($emails as $email) {
            try {
                Mailer::send([$email], $subject, $html, $alt);
            } catch (\Throwable $e) {
                self::log("Notificación a {$email} falló: " . $e->getMessage());
            }
        }
    }

    private static function sendConfirmation(
        array $form,
        array $settings,
        array $validated,
        string $submittedAt,
        string $locale = 'es'
    ): void {
        $conf = $settings['notify']['confirmation'] ?? [];
        if (empty($conf['enabled'])) {
            return;
        }
        if (!Mailer::isConfigured()) {
            self::log('Confirmación omitida: SMTP no configurado.');
            return;
        }

        $toField = trim((string) ($conf['to_field'] ?? ''));
        if ($toField === '') {
            return;
        }

        $fieldId = self::fieldIdByLabel($form, $toField);
        if ($fieldId === null) {
            self::log("Confirmación omitida: campo '{$toField}' no existe.");
            return;
        }

        $email = trim((string) ($validated[$fieldId] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            self::log("Confirmación omitida: campo '{$toField}' no contiene un email válido.");
            return;
        }

        $formTitle = (string) ($form['title'] ?? '');
        $subject = trim((string) ($conf['subject'] ?? ''));
        if ($subject === '') {
            $subject = I18n::t('mail.conf_subject', $locale) . ($formTitle !== '' ? ': ' . $formTitle : '');
        }
        $subject = self::truncate($subject, 190);

        $message = trim((string) ($conf['message'] ?? ''));
        $html = self::renderMail('mail.confirmation', [
            'locale'    => $locale,
            'formTitle' => $formTitle,
            'message'   => $message,
        ]);
        // El texto plano alterno usa el catálogo cuando el autor no escribió uno.
        $alt = $message !== '' ? $message : self::altConfirmation($formTitle, $locale);

        try {
            Mailer::send([$email], $subject, $html, $alt);
        } catch (\Throwable $e) {
            self::log('Confirmación falló: ' . $e->getMessage());
        }
    }

    /**
     * Fila [id, label, value] por cada campo con respuesta, con el valor
     * formateado para humanos (listas y mapas se despliegan legibles).
     */
    private static function answerRows(array $form, array $validated): array
    {
        $fields = (array) ($form['fields'] ?? []);
        $byId = [];
        foreach ($fields as $field) {
            $byId[(string) ($field['id'] ?? '')] = (string) ($field['label'] ?? (string) ($field['id'] ?? ''));
        }

        $rows = [];
        foreach ($validated as $id => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $rows[] = [
                'id'    => (string) $id,
                'label' => $byId[(string) $id] ?? (string) $id,
                'value' => self::humanize($value),
            ];
        }
        return $rows;
    }

    private static function fieldIdByLabel(array $form, string $label): ?string
    {
        $needle = mb_strtolower(trim($label));
        foreach ((array) ($form['fields'] ?? []) as $field) {
            $fieldLabel = mb_strtolower(trim((string) ($field['label'] ?? '')));
            if ($fieldLabel === $needle) {
                return (string) ($field['id'] ?? '');
            }
        }
        return null;
    }

    /**
     * Convierte un valor almacenado (string o JSON stringificado) en texto.
     */
    private static function humanize($value): string
    {
        // P10: los adjuntos se muestran como "nombre.pdf (1,2 MB)"; el correo
        // solo lleva el texto (los binarios NUNCA se adjuntan al mensaje).
        if (is_string($value) && Uploader::isAttachmentJson($value)) {
            return Uploader::displayValue($value);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return implode('; ', array_map([self::class, 'humanize'], $value));
            }
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = $k . ': ' . (is_array($v) ? (string) json_encode($v, JSON_UNESCAPED_UNICODE) : self::humanize($v));
            }
            return implode(' · ', $parts);
        }

        return (string) $value;
    }

    private static function altSummary(string $formTitle, int $submissionId, string $submittedAt, array $rows, string $locale = 'es'): string
    {
        $lines = [
            I18n::t('mail.alt_line1', $locale)
                . ($formTitle !== '' ? I18n::t('mail.alt_in', $locale, ['t' => $formTitle]) : I18n::t('mail.alt_dot', $locale)),
            '',
        ];
        $lines[] = I18n::t('mail.response_id_line', $locale, ['id' => $submissionId]);
        $lines[] = I18n::t('mail.date_line', $locale, ['date' => $submittedAt]);
        foreach ($rows as $row) {
            $lines[] = '';
            $lines[] = $row['label'] . ':';
            $lines[] = $row['value'];
        }
        return implode("\n", $lines);
    }

    /**
     * Texto plano alterno de la confirmación (P9, sin autor: usa el catálogo).
     */
    private static function altConfirmation(string $formTitle, string $locale): string
    {
        $alt = I18n::t('mail.conf_alt_base', $locale);
        if ($formTitle !== '') {
            $alt .= I18n::t('mail.conf_alt_in', $locale, ['t' => $formTitle]);
        } else {
            $alt .= I18n::t('mail.conf_dot', $locale);
        }
        return $alt;
    }

    private static function renderMail(string $template, array $data): string
    {
        ob_start();
        View::render($template, $data, null);
        return (string) ob_get_clean();
    }

    private static function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '…' : $value;
    }

    private static function log(string $message): void
    {
        error_log('[Notifier] ' . $message);
    }
}