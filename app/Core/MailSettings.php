<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Configuración SMTP editable desde el panel (`/admin/settings`).
 *
 * Mismo patrón que `Brand` y `AppSettings`: **la fila guardada gana; si no hay
 * fila, manda el `.env`** (`Config::mail()`). Nada se congela, así que una
 * instalación que nunca toca el correo desde la UI se comporta exactamente como
 * antes de AG3 (que es lo que hace que mover esta config a la tabla no rompa a
 * nadie).
 *
 * La contraseña es el único valor SENSIBLE: se guarda **cifrada** con
 * `App\Core\Secret` (AES-256-GCM, clave en `APP_KEY`). Sin `APP_KEY` el cifrado
 * está inactivo y `save()` de una contraseña falla explícitamente —no se guarda
 * en claro—: es mejor decir «genera la clave» que dejar la contraseña del SMTP
 * legible en cada backup.
 *
 * `Mailer::config()` es quien lee esto (vía `all()`), así que TODO el correo de
 * la app (avisos al dueño, confirmación al respondiente, invitaciones y
 * recordatorios del roster) usa la config del panel sin tocar una sola llamada.
 */
final class MailSettings
{
    public const HOST         = 'mail_host';
    public const PORT         = 'mail_port';
    public const ENCRYPTION   = 'mail_encryption';
    public const USER         = 'mail_user';
    public const PASSWORD     = 'mail_password';
    public const FROM_ADDRESS = 'mail_from_address';
    public const FROM_NAME    = 'mail_from_name';
    public const TIMEOUT      = 'mail_timeout';

    /** Todas las claves que gestiona esta clase. */
    public const KEYS = [
        self::HOST,
        self::PORT,
        self::ENCRYPTION,
        self::USER,
        self::PASSWORD,
        self::FROM_ADDRESS,
        self::FROM_NAME,
        self::TIMEOUT,
    ];

    /** Encriptaciones admitidas por `Mailer::smtpSession()`. */
    public const ENCRYPTIONS = ['', 'tls', 'ssl'];

    /**
     * Config SMTP resuelta, en la forma EXACTA que consume `Mailer`.
     *
     * @return array{host:string,port:int,encryption:string,user:string,password:string,from_address:string,from_name:string,timeout:int}
     */
    public static function all(): array
    {
        $env = self::fromEnv();

        $all = [
            'host'         => self::text(self::HOST, $env['host']),
            'port'         => self::entero(self::PORT, (int) $env['port']),
            'encryption'   => self::encryption(self::ENCRYPTION, (string) $env['encryption']),
            'user'         => self::text(self::USER, $env['user']),
            'password'     => self::password($env['password']),
            'from_address' => self::text(self::FROM_ADDRESS, $env['from_address']),
            'from_name'    => self::text(self::FROM_NAME, $env['from_name']),
            'timeout'      => self::entero(self::TIMEOUT, (int) $env['timeout']),
        ];

        return $all;
    }

    /** La config del `.env`, tal cual (`Config::mail()`). Base de la precedencia. */
    public static function fromEnv(): array
    {
        $mail = Config::mail();
        return [
            'host'         => (string) ($mail['host'] ?? ''),
            'port'         => (int) ($mail['port'] ?? 587),
            'encryption'   => (string) ($mail['encryption'] ?? 'tls'),
            'user'         => (string) ($mail['user'] ?? ''),
            'password'     => (string) ($mail['password'] ?? ''),
            'from_address' => (string) ($mail['from_address'] ?? ''),
            'from_name'    => (string) ($mail['from_name'] ?? ''),
            'timeout'      => (int) ($mail['timeout'] ?? 15),
        ];
    }

    /**
     * ¿Hay un SMTP configurado (host no vacío)? Igual que
     * `Mailer::isConfigured()`, pero sin transporte de test.
     */
    public static function isConfigured(): bool
    {
        return trim((string) self::all()['host']) !== '';
    }

    /**
     * ¿Hay una contraseña guardada y utilizable?
     *
     * `true` = hay fila y se descifra (o sea, `APP_KEY` presente y correcta).
     * `false` = no se ha guardado nunca, se guardó sin clave, o la clave cambió.
     * Es lo que decide la pista «(guardada)» del formulario; NUNCA se devuelve
     * el valor.
     */
    public static function passwordStored(): bool
    {
        $stored = AppSettings::get(self::PASSWORD);
        if ($stored === null || $stored === '') {
            return false;
        }
        // Si no hay APP_KEY, `decrypt()` devuelve null: no podemos servirla, así
        // que para la UI es «no guardada» (y el correo caerá al `.env`).
        return Secret::canDecrypt($stored);
    }

    /**
     * Guarda la config SMTP. Devuelve `['ok' => bool, 'error' => ?string]`.
     *
     * Semántica de cada campo:
     * - Texto (`host`, `user`, `from_*`): valor vacío BORRA la fila (= vuelve al
     *   `.env`), como en la marca.
     * - `port`/`timeout`: se validan como enteros en rango; fuera de rango → error
     *   (no se guarda nada).
     * - `encryption`: lista cerrada ('' | tls | ssl).
     * - `password`: **campo de solo escritura**. `null` = no lo he tocado (se
     *   conserva); `''` con `clear_password` = borra la fila; cualquier otro
     *   valor = se cifra y se guarda. Vacío sin `clear` = no lo he tocado.
     *
     * @param array<string, mixed> $data
     * @return array{ok:bool, error:?string}
     */
    public static function save(array $data): array
    {
        $host = trim((string) ($data['host'] ?? ''));
        $user = trim((string) ($data['user'] ?? ''));
        $fromAddress = trim((string) ($data['from_address'] ?? ''));
        $fromName = trim((string) ($data['from_name'] ?? ''));
        $encryption = strtolower(trim((string) ($data['encryption'] ?? '')));
        $port = (int) ($data['port'] ?? 0);
        $timeout = (int) ($data['timeout'] ?? 0);

        if (!in_array($encryption, self::ENCRYPTIONS, true)) {
            return ['ok' => false, 'error' => 'Cifrado no válido: usa TLS, SSL o ninguno.'];
        }
        if ($port < 1 || $port > 65535) {
            return ['ok' => false, 'error' => 'El puerto tiene que estar entre 1 y 65535.'];
        }
        if ($timeout < 1 || $timeout > 120) {
            return ['ok' => false, 'error' => 'El tiempo de espera tiene que estar entre 1 y 120 segundos.'];
        }
        if ($fromAddress !== '' && filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'El remitente no es una dirección de correo válida.'];
        }

        $writes = [
            self::HOST         => $host === '' ? null : $host,
            self::USER         => $user === '' ? null : $user,
            self::FROM_ADDRESS => $fromAddress === '' ? null : $fromAddress,
            self::FROM_NAME    => $fromName === '' ? null : $fromName,
            // El puerto/cifrado/tiempo se guardan siempre: son parte del «modo»
            // del SMTP y tienen default, no un «vacío» significativo.
            self::PORT         => (string) $port,
            self::ENCRYPTION   => $encryption,
            self::TIMEOUT      => (string) $timeout,
        ];

        // La contraseña se trata aparte porque es la única cifrada.
        $clear = !empty($data['clear_password']);
        $newPassword = $data['password'] ?? null;

        if ($clear) {
            $writes[self::PASSWORD] = null; // borrar
        } elseif (is_string($newPassword) && $newPassword !== '') {
            if (!Secret::isAvailable()) {
                return [
                    'ok' => false,
                    'error' => 'No hay APP_KEY: genera una con `php scripts/generate-key.php --write` para guardar la contraseña cifrada.',
                ];
            }
            $cifrado = Secret::encrypt($newPassword);
            if ($cifrado === null) {
                return ['ok' => false, 'error' => 'No se pudo cifrar la contraseña.'];
            }
            $writes[self::PASSWORD] = $cifrado;
        }
        // Si no viene contraseña y no se pide borrar: no se toca la fila.

        foreach ($writes as $key => $value) {
            if (!AppSettings::set($key, $value)) {
                return ['ok' => false, 'error' => 'No se pudo guardar la configuración (¿migraciones?).'];
            }
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Fija las filas para un test (sin tocar la BD). Delega en el seam de
     * `AppSettings`, que es de donde lee `all()`.
     */
    public static function setForTesting(?array $values): void
    {
        AppSettings::setForTesting($values ?? []);
    }

    public static function forgetForTesting(): void
    {
        AppSettings::forgetForTesting();
    }

    /* ------------------------------------------------------------------ */

    private static function text(string $key, string $fallback): string
    {
        $stored = AppSettings::get($key);
        if ($stored !== null && trim($stored) !== '') {
            return trim($stored);
        }
        return $fallback;
    }

    private static function entero(string $key, int $fallback): int
    {
        $stored = AppSettings::get($key);
        if ($stored !== null && $stored !== '' && preg_match('/^\d+$/', trim($stored)) === 1) {
            return (int) trim($stored);
        }
        return $fallback;
    }

    private static function encryption(string $key, string $fallback): string
    {
        $stored = AppSettings::get($key);
        if ($stored !== null) {
            $norm = strtolower(trim($stored));
            if (in_array($norm, self::ENCRYPTIONS, true)) {
                return $norm;
            }
        }
        return $fallback;
    }

    /**
     * La contraseña resuelta: la fila cifrada si se puede descifrar; si no, la
     * del `.env`. Un `APP_KEY` cambiado NO borra el acceso al SMTP mientras el
     * `.env` siga teniendo la contraseña vieja.
     */
    private static function password(string $envPassword): string
    {
        $stored = AppSettings::get(self::PASSWORD);
        if ($stored !== null && $stored !== '') {
            $plain = Secret::decrypt($stored);
            if ($plain !== null) {
                return $plain;
            }
        }
        return $envPassword;
    }
}
