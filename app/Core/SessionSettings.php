<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Hardening de sesión editable desde el panel (`/admin/settings`).
 *
 * Mismo patrón que el resto de ajustes de instancia: **la fila guardada gana;
 * si no hay fila, manda el `.env`**. Nada se congela.
 *
 * Los ajustes afectan a la cookie de sesión que se cree en nuevas peticiones.
 * Cambiar `SESSION_NAME` obliga a relogin; `lifetime` se aplica también a
 * `session.gc_maxlifetime` en `Session::start()`.
 */
final class SessionSettings
{
    public const NAME = 'instance_session_name';
    public const LIFETIME = 'instance_session_lifetime';
    public const SECURE = 'instance_session_secure';
    public const HTTPONLY = 'instance_session_httponly';
    public const SAMESITE = 'instance_session_samesite';

    public const KEYS = [self::NAME, self::LIFETIME, self::SECURE, self::HTTPONLY, self::SAMESITE];

    public const DEFAULT_NAME = 'vanillaforms_session';
    public const DEFAULT_LIFETIME = 7200;
    public const DEFAULT_SECURE = false;
    public const DEFAULT_HTTPONLY = true;
    public const DEFAULT_SAMESITE = 'Lax';

    public const SAMESITE_ALLOWED = ['Lax', 'Strict', 'None'];

    /**
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        return [
            'stored_name'       => (string) (AppSettings::get(self::NAME) ?? ''),
            'stored_lifetime'   => (string) (AppSettings::get(self::LIFETIME) ?? ''),
            'stored_secure'     => self::storedBool(self::SECURE),
            'stored_httponly'   => self::storedBool(self::HTTPONLY),
            'stored_samesite'   => (string) (AppSettings::get(self::SAMESITE) ?? ''),
            'env_name'          => self::envName(),
            'env_lifetime'      => self::envLifetime(),
            'env_secure'        => self::envBool('SESSION_SECURE', self::DEFAULT_SECURE),
            'env_httponly'      => self::envBool('SESSION_HTTPONLY', self::DEFAULT_HTTPONLY),
            'env_samesite'      => self::envSamesite(),
            'name'              => self::name(),
            'lifetime'          => self::lifetime(),
            'secure'            => self::secure(),
            'httponly'          => self::httponly(),
            'samesite'          => self::samesite(),
        ];
    }

    public static function name(): string
    {
        $stored = AppSettings::get(self::NAME);
        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }
        return self::envName();
    }

    private static function envName(): string
    {
        $v = (string) Env::get('SESSION_NAME', self::DEFAULT_NAME);
        return trim($v) === '' ? self::DEFAULT_NAME : trim($v);
    }

    public static function lifetime(): int
    {
        $stored = AppSettings::get(self::LIFETIME);
        if ($stored !== null && trim($stored) !== '' && preg_match('/^\d+$/', trim($stored)) === 1) {
            $v = (int) trim($stored);
            if ($v >= 60) {
                return $v;
            }
        }
        return self::envLifetime();
    }

    private static function envLifetime(): int
    {
        $v = (int) Env::get('SESSION_LIFETIME', (string) self::DEFAULT_LIFETIME);
        return $v >= 60 ? $v : self::DEFAULT_LIFETIME;
    }

    public static function secure(): bool
    {
        $stored = AppSettings::get(self::SECURE);
        if (is_string($stored)) {
            $t = strtolower(trim($stored));
            if ($t === '1' || $t === 'true' || $t === 'on') {
                return true;
            }
            if ($t === '0' || $t === 'false' || $t === 'off') {
                return false;
            }
        }
        return self::envBool('SESSION_SECURE', self::DEFAULT_SECURE);
    }

    public static function httponly(): bool
    {
        $stored = AppSettings::get(self::HTTPONLY);
        if (is_string($stored)) {
            $t = strtolower(trim($stored));
            if ($t === '1' || $t === 'true' || $t === 'on') {
                return true;
            }
            if ($t === '0' || $t === 'false' || $t === 'off') {
                return false;
            }
        }
        return self::envBool('SESSION_HTTPONLY', self::DEFAULT_HTTPONLY);
    }

    public static function samesite(): string
    {
        $stored = AppSettings::get(self::SAMESITE);
        if (is_string($stored)) {
            $t = ucfirst(strtolower(trim($stored)));
            if (in_array($t, self::SAMESITE_ALLOWED, true)) {
                return $t;
            }
        }
        return self::envSamesite();
    }

    private static function envSamesite(): string
    {
        $t = ucfirst(strtolower(trim((string) Env::get('SESSION_SAMESITE', self::DEFAULT_SAMESITE))));
        return in_array($t, self::SAMESITE_ALLOWED, true) ? $t : self::DEFAULT_SAMESITE;
    }

    private static function envBool(string $key, bool $default): bool
    {
        $v = Env::get($key, $default ? '1' : '0');
        if (is_bool($v)) {
            return $v;
        }
        $t = strtolower(trim((string) $v));
        if ($t === '1' || $t === 'true' || $t === 'on') {
            return true;
        }
        if ($t === '0' || $t === 'false' || $t === 'off') {
            return false;
        }
        return $default;
    }

    private static function storedBool(string $key): string
    {
        $stored = AppSettings::get($key);
        if (!is_string($stored)) {
            return '';
        }
        $t = strtolower(trim($stored));
        if ($t === '1' || $t === 'true' || $t === 'on') {
            return '1';
        }
        if ($t === '0' || $t === 'false' || $t === 'off') {
            return '0';
        }
        return '';
    }

    /**
     * @return array{ok:bool,error:?string}
     */
    public static function save(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $lifetime = trim((string) ($data['lifetime'] ?? ''));
        $samesite = trim((string) ($data['samesite'] ?? ''));

        if ($name !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            return ['ok' => false, 'error' => 'El nombre de la cookie solo puede tener letras, números y guion bajo.'];
        }
        if ($lifetime !== '' && (preg_match('/^\d+$/', $lifetime) !== 1 || (int) $lifetime < 60)) {
            return ['ok' => false, 'error' => 'La duración tiene que ser un número entero mayor o igual a 60 segundos.'];
        }
        if ($samesite !== '' && !in_array(ucfirst(strtolower($samesite)), self::SAMESITE_ALLOWED, true)) {
            return ['ok' => false, 'error' => 'SameSite debe ser Lax, Strict o None.'];
        }

        if ($samesite !== '') {
            $samesite = ucfirst(strtolower($samesite));
            if ($samesite === 'None') {
                // HTTPS es obligatorio para SameSite=None. Si está en HTTP, forzar
                // Secure puede romper la cookie, pero el panel lo avisa en UI;
                // aquí solo validamos el valor.
            }
        }

        if (!AppSettings::set(self::NAME, $name === '' ? null : $name)) {
            return ['ok' => false, 'error' => 'No se pudo guardar la configuración (¿migraciones?).'];
        }
        if (!AppSettings::set(self::LIFETIME, $lifetime === '' ? null : $lifetime)) {
            return ['ok' => false, 'error' => 'No se pudo guardar la configuración (¿migraciones?).'];
        }
        if (!AppSettings::set(self::SAMESITE, $samesite === '' ? null : $samesite)) {
            return ['ok' => false, 'error' => 'No se pudo guardar la configuración (¿migraciones?).'];
        }

        $secure = isset($data['secure']) ? '1' : '0';
        $httponly = isset($data['httponly']) ? '1' : '0';
        if (!AppSettings::set(self::SECURE, $secure)) {
            return ['ok' => false, 'error' => 'No se pudo guardar la configuración (¿migraciones?).'];
        }
        if (!AppSettings::set(self::HTTPONLY, $httponly)) {
            return ['ok' => false, 'error' => 'No se pudo guardar la configuración (¿migraciones?).'];
        }

        return ['ok' => true, 'error' => null];
    }

    public static function setForTesting(?array $values): void
    {
        AppSettings::setForTesting($values ?? []);
    }

    public static function forgetForTesting(): void
    {
        AppSettings::forgetForTesting();
    }
}
