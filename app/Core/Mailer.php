<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Mailer
 *
 * Cliente SMTP propio (sin librerías externas) para las notificaciones de
 * respuestas (P5): aviso al dueño y confirmación al respondiente.
 *
 * Soporte:
 * - Conexión directa o cifrada: 'ssl' (implícita) y 'tls' (STARTTLS).
 * - Autenticación AUTH LOGIN con fallback a AUTH PLAIN.
 * - Mensaje multipart/alternative (texto plano + HTML), charset UTF-8.
 * - Encabezados UTF-8 con encoded-words (RFC 2047) y sanitización contra
 *   inyección de cabeceras (CR/LF nunca entran en los headers).
 * - Timeouts acotados; ante cualquier fallo se lanza \RuntimeException y el
 *   llamador (Notifier) decide qué hacer (nunca rompe el envío de respuestas).
 *
 * Testeable sin tocar la red: Mailer::setTransport() inyecta un callable que
 * recibe el sobre construido (envelope) y la config usada. Mailer::setConfig()
 * permite inyectar config sin pasar por .env.
 */
class Mailer
{
    /** @var callable|null (array $envelope, array $config): void */
    private static $transport = null;

    /** @var array<string,mixed>|null Config de override (tests) */
    private static ?array $configOverride = null;

    /**
     * Inyecta un transporte de grabación para tests. null restaura el real.
     *
     * @param callable|null $fn (array $envelope, array $config): void
     */
    public static function setTransport(?callable $fn): void
    {
        self::$transport = $fn;
    }

    /**
     * Inyecta config para tests (sin leer .env). null restaura la real.
     */
    public static function setConfigOverride(?array $config): void
    {
        self::$configOverride = $config;
    }

    /**
     * Config de correo (override de tests, o la del panel/`.env`).
     *
     * Pasa por `MailSettings::all()` para que el SMTP editable desde
     * `/admin/settings` (AG3) lo use TODO el correo de la app sin tocar cada
     * llamada: la fila de `app_settings` gana y, sin fila, manda el `.env`.
     */
    public static function config(): array
    {
        return self::$configOverride ?? MailSettings::all();
    }

    /**
     * True si hay un SMTP configurado (o un transporte de prueba inyectado).
     */
    public static function isConfigured(?array $config = null): bool
    {
        if (self::$transport !== null) {
            return true;
        }
        $cfg = $config ?? self::config();
        return trim((string) ($cfg['host'] ?? '')) !== '';
    }

    /**
     * Envía un correo.
     *
     * @param string[] $to      Destinatarios (solo direcciones válidas se envían)
     * @param string   $subject Asunto (se codifica UTF-8 automáticamente)
     * @param string   $html    Cuerpo HTML
     * @param string   $alt     Versión texto plano
     * @param array|null $config Config (por defecto la global)
     * @throws \RuntimeException si el SMTP falla o falta config
     */
    public static function send(array $to, string $subject, string $html, string $alt, ?array $config = null): bool
    {
        $cfg = $config ?? self::config();

        if (trim((string) ($cfg['host'] ?? '')) === '' && self::$transport === null) {
            throw new \RuntimeException('SMTP no configurado (MAIL_HOST vacío).');
        }

        $fromAddress = trim((string) ($cfg['from_address'] ?? ''));
        if ($fromAddress === '' || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Remitente SMTP inválido (MAIL_FROM_ADDRESS).');
        }
        $fromName = trim((string) ($cfg['from_name'] ?? '')) ?: $fromAddress;

        $recipients = [];
        foreach ((array) $to as $addr) {
            $addr = trim((string) $addr);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL) !== false) {
                $recipients[] = $addr;
            }
        }
        if ($recipients === []) {
            throw new \RuntimeException('Sin destinatarios válidos.');
        }

        $envelope = self::buildEnvelope($fromAddress, $fromName, $recipients, $subject, $html, $alt);

        if (self::$transport !== null) {
            // Transporte de prueba: registrar el sobre sin tocar la red.
            (self::$transport)($envelope, $cfg);
            return true;
        }

        return self::smtpSession($cfg, $envelope);
    }

    /**
     * Construye el sobre (envelope) completo: cabeceras seguras + cuerpo
     * multipart/alternative con puntos-espejo (dot-stuffing) aplicado.
     *
     * @return array{from:string, to:string[], subject:string, headers:string, text:string, html:string, data:string}
     */
    public static function buildEnvelope(
        string $fromAddress,
        string $fromName,
        array $to,
        string $subject,
        string $html,
        string $alt
    ): array {
        $fromNameSafe = self::sanitizeHeaderValue($fromName);
        $subjectHeader = self::encodeHeader($subject);

        $host = (string) (self::config()['host'] ?? 'localhost');
        $messageId = '<' . bin2hex(random_bytes(16)) . '@' . preg_replace('/[^a-zA-Z0-9.-]/', '', $host) . '>';

        $fromHeader = ($fromNameSafe !== '' && $fromNameSafe !== $fromAddress)
            ? self::encodeHeader($fromNameSafe) . ' <' . $fromAddress . '>'
            : $fromAddress;

        $toHeaders = array_map(static fn (string $addr): string => self::sanitizeHeaderValue($addr), $to);

        $boundary = 'vf_' . bin2hex(random_bytes(12));

        $message = '';

        $message .= 'From: ' . $fromHeader . "\r\n";
        $message .= 'To: ' . implode(', ', $toHeaders) . "\r\n";
        $message .= 'Subject: ' . $subjectHeader . "\r\n";
        $message .= 'Date: ' . date('r') . "\r\n";
        $message .= 'Message-ID: ' . $messageId . "\r\n";
        $message .= 'MIME-Version: 1.0' . "\r\n";
        $message .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n";
        $message .= 'X-Mailer: VanillaForms' . "\r\n";
        $message .= "\r\n";

        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n";
        $message .= "\r\n";
        $message .= $alt . "\r\n";

        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n";
        $message .= "\r\n";
        $message .= $html . "\r\n";

        $message .= '--' . $boundary . "--\r\n";

        // Las líneas que comienzan con '.' requieren espejo (dot-stuffing).
        $data = preg_replace('/^\./m', '..', $message) ?? $message;

        return [
            'from'    => $fromAddress,
            'to'      => $to,
            'subject' => $subject,
            'headers' => $message,
            'text'    => $alt,
            'html'    => $html,
            'data'    => $data,
        ];
    }

    /**
     * Sesión SMTP real sobre un socket.
     */
    private static function smtpSession(array $cfg, array $envelope): bool
    {
        $host = (string) $cfg['host'];
        $port = (int) ($cfg['port'] ?? 587);
        $timeout = (int) ($cfg['timeout'] ?? 15);
        $enc = strtolower((string) ($cfg['encryption'] ?? 'tls'));
        $user = (string) ($cfg['user'] ?? '');
        $pass = (string) ($cfg['password'] ?? '');

        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if ($fp === false) {
            throw new \RuntimeException("SMTP: fallo al conectar con {$remote} ({$errno} {$errstr}).");
        }
        stream_set_timeout($fp, $timeout);

        try {
            self::expect(self::readReply($fp), [220], 'saludo');

            $ehlo = self::cmd($fp, 'EHLO ' . self::smtpHostname());
            self::expect($ehlo, [250], 'EHLO');

            if ($enc === 'tls') {
                self::expect(self::cmd($fp, 'STARTTLS'), [220], 'STARTTLS');
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP: fallo al negociar TLS.');
                }
                self::expect(self::cmd($fp, 'EHLO ' . self::smtpHostname()), [250], 'EHLO (post-TLS)');
            }

            if ($user !== '') {
                if (!self::authLogin($fp, $user, $pass) && !self::authPlain($fp, $user, $pass)) {
                    throw new \RuntimeException('SMTP: autenticación rechazada (LOGIN/PLAIN).');
                }
            }

            self::expect(self::cmd($fp, 'MAIL FROM:<' . $envelope['from'] . '>'), [250], 'MAIL FROM');
            foreach ($envelope['to'] as $rcpt) {
                self::expect(self::cmd($fp, 'RCPT TO:<' . $rcpt . '>'), [250, 251], 'RCPT TO');
            }

            self::expect(self::cmd($fp, 'DATA'), [354], 'DATA');
            fwrite($fp, $envelope['data']);
            fwrite($fp, ".\r\n");
            self::expect(self::readReply($fp), [250], 'finalización DATA');

            return true;
        } finally {
            self::cmd($fp, 'QUIT');
            fclose($fp);
        }
    }

    private static function authLogin($fp, string $user, string $pass): bool
    {
        if (substr(self::cmd($fp, 'AUTH LOGIN'), 0, 3) !== '334') {
            return false;
        }
        if (substr(self::cmd($fp, base64_encode($user)), 0, 3) !== '334') {
            return false;
        }
        return substr(self::cmd($fp, base64_encode($pass)), 0, 3) === '235';
    }

    private static function authPlain($fp, string $user, string $pass): bool
    {
        $line = 'AUTH PLAIN ' . base64_encode("\0{$user}\0{$pass}");
        return substr(self::cmd($fp, $line), 0, 3) === '235';
    }

    /**
     * Envía una línea SMTP y lee la respuesta.
     */
    private static function cmd($fp, string $line): string
    {
        fwrite($fp, $line . "\r\n");
        return self::readReply($fp);
    }

    private static function readReply($fp): string
    {
        $last = '';
        while (true) {
            $raw = fgets($fp, 4096);
            if ($raw === false) {
                return $last !== '' ? $last : 'EOF';
            }
            $line = rtrim($raw, "\r\n");
            $last = $line === '' ? $last : $line;
            // Completar solo en la última línea de una respuesta multi-línea ("250-…\n250 …").
            if (strlen($line) >= 4 && is_numeric(substr($line, 0, 3)) && $line[3] === ' ') {
                return $line;
            }
        }
    }

    private static function expect(string $reply, array $codes, string $what): void
    {
        // El código se compara como ENTERO: `substr()` devuelve string y las
        // listas de llamadas son `[220]`, `[250]`… Comparar `"220"` contra
        // `220` con `in_array(..., true)` daba false SIEMPRE, así que la
        // primera respuesta de cualquier SMTP real se rechazaba como
        // «inesperada» (el transporte de los tests nunca pasa por aquí).
        if (strlen($reply) < 3 || !in_array((int) substr($reply, 0, 3), $codes, true)) {
            throw new \RuntimeException("SMTP: {$what} inesperado ('{$reply}').");
        }
    }

    private static function smtpHostname(): string
    {
        $h = @gethostname();
        return preg_replace('/[^a-zA-Z0-9.-]/', '', $h !== false ? $h : 'localhost') ?: 'localhost';
    }

    /**
     * Neutraliza CR/LF (inyección de cabeceras) en valores de headers.
     */
    private static function sanitizeHeaderValue(string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }

    /**
     * Codifica un header arbitrario en encoded-words UTF-8 (RFC 2047) y
     * sanitiza saltos de línea. Solo codifica si hay caracteres no-ASCII.
     */
    private static function encodeHeader(string $value): string
    {
        $value = self::sanitizeHeaderValue($value);
        if (!preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }
        $encoded = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n")
            : '=?UTF-8?B?' . base64_encode($value) . '?=';
        return (string) $encoded;
    }
}