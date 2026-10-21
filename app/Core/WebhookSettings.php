<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ajustes de webhook editables desde el panel (`/admin/settings`).
 *
 * Mismo patrón: **la fila guardada gana; si no hay fila, manda el `.env`**.
 */
final class WebhookSettings
{
    public const ALLOW_PRIVATE_HOSTS = 'instance_webhook_allow_private_hosts';

    public const KEYS = [self::ALLOW_PRIVATE_HOSTS];

    public const DEFAULT = false;

    /**
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        return [
            'stored_allow_private_hosts' => self::storedBool(self::ALLOW_PRIVATE_HOSTS),
            'env_allow_private_hosts'    => self::envBool('WEBHOOK_ALLOW_PRIVATE_HOSTS', self::DEFAULT),
            'allow_private_hosts'        => self::allowPrivateHosts(),
        ];
    }

    public static function allowPrivateHosts(): bool
    {
        $stored = AppSettings::get(self::ALLOW_PRIVATE_HOSTS);
        if (is_string($stored)) {
            $t = strtolower(trim($stored));
            if ($t === '1' || $t === 'true' || $t === 'on') {
                return true;
            }
            if ($t === '0' || $t === 'false' || $t === 'off') {
                return false;
            }
        }
        try {
            $cfg = Config::get('webhook', []);
            if (is_array($cfg) && array_key_exists('allow_private_hosts', $cfg)) {
                $v = $cfg['allow_private_hosts'];
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
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return self::envBool('WEBHOOK_ALLOW_PRIVATE_HOSTS', self::DEFAULT);
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
        $allow = isset($data['allow_private_hosts']) && $data['allow_private_hosts'] ? '1' : '0';
        if (!AppSettings::set(self::ALLOW_PRIVATE_HOSTS, $allow)) {
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
