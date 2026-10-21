<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ajustes de red y cabeceras de seguridad editables desde el panel (`/admin/settings`).
 *
 * Mismo patrón que el resto: **la fila guardada gana; si no hay fila, manda el `.env`**.
 * Nada se congela.
 *
 * Gestiona:
 * - `TRUST_PROXY`: confía en `X-Forwarded-For/X-Real-IP/X-Forwarded-Proto`.
 * - `APP_REDIRECT_ALLOWLIST`: hosts permitidos para `thanks.redirect_url` (separados por coma).
 * - `FRAME_OPTIONS`: `X-Frame-Options` global (`DENY`/`SAMEORIGIN`/vacío).
 * - `app.csp_policy`: política CSP global (vacío = sin cabecera global).
 */
final class NetworkSettings
{
    public const TRUST_PROXY = 'instance_trust_proxy';
    public const REDIRECT_ALLOWLIST = 'instance_redirect_allowlist';
    public const FRAME_OPTIONS = 'instance_frame_options';
    public const CSP_POLICY = 'instance_csp_policy';

    public const KEYS = [self::TRUST_PROXY, self::REDIRECT_ALLOWLIST, self::FRAME_OPTIONS, self::CSP_POLICY];

    public const DEFAULT_FRAME_OPTIONS = 'SAMEORIGIN';
    public const DEFAULT_CSP_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";

    /**
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        return [
            'stored_trust_proxy'        => self::storedBool(self::TRUST_PROXY),
            'stored_redirect_allowlist' => (string) (AppSettings::get(self::REDIRECT_ALLOWLIST) ?? ''),
            'stored_frame_options'      => (string) (AppSettings::get(self::FRAME_OPTIONS) ?? ''),
            'stored_csp_policy'         => (string) (AppSettings::get(self::CSP_POLICY) ?? ''),
            'env_trust_proxy'           => self::envBool('TRUST_PROXY', false),
            'env_redirect_allowlist'    => self::envRedirectAllowlistRaw(),
            'env_frame_options'         => self::envFrameOptions(),
            'env_csp_policy'            => self::envCspPolicy(),
            'trust_proxy'               => self::trustProxy(),
            'redirect_allowlist'        => self::redirectAllowlist(),
            'frame_options'             => self::frameOptions(),
            'csp_policy'                => self::cspPolicy(),
        ];
    }

    public static function trustProxy(): bool
    {
        $stored = AppSettings::get(self::TRUST_PROXY);
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
            $cfg = Config::get('app.security', []);
            if (is_array($cfg) && array_key_exists('trust_proxy', $cfg)) {
                $v = $cfg['trust_proxy'];
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
            $cfg2 = Config::get('app', []);
            if (is_array($cfg2) && array_key_exists('trust_proxy', $cfg2)) {
                $v = $cfg2['trust_proxy'];
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
        return self::envBool('TRUST_PROXY', false);
    }

    /**
     * Lista de hosts permitidos (en minúsculas, sin puertos).
     * @return array<int,string>
     */
    public static function redirectAllowlist(): array
    {
        $stored = AppSettings::get(self::REDIRECT_ALLOWLIST);
        if (is_string($stored) && trim($stored) !== '') {
            return self::parseAllowlist($stored);
        }
        try {
            $raw = Config::get('app.redirect_allowlist', null);
            if (is_array($raw)) {
                $list = array_map(function ($p) {
                    if (is_string($p) && $p !== '') {
                        return strtolower(preg_replace('/:\d+$/', '', trim($p)));
                    }
                    return null;
                }, $raw);
                $list = array_values(array_filter($list, function ($x) { return is_string($x) && $x !== ''; }));
                if (!empty($list)) {
                    return $list;
                }
            }
            if (is_string($raw) && trim($raw) !== '') {
                return self::parseAllowlist($raw);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return self::envRedirectAllowlist();
    }

    private static function envRedirectAllowlistRaw(): string
    {
        return trim((string) Env::get('APP_REDIRECT_ALLOWLIST', ''));
    }

    private static function envRedirectAllowlist(): array
    {
        return self::parseAllowlist(self::envRedirectAllowlistRaw());
    }

    /**
     * @return array<int,string>
     */
    private static function parseAllowlist(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $raw));
        $out = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            $host = strtolower(preg_replace('/:\d+$/', '', $p));
            if ($host === '') {
                continue;
            }
            $out[$host] = true;
        }
        return array_keys($out);
    }

    public static function frameOptions(): string
    {
        $stored = AppSettings::get(self::FRAME_OPTIONS);
        if (is_string($stored)) {
            $v = strtoupper(trim($stored));
            if ($v === 'DENY' || $v === 'SAMEORIGIN') {
                return $v;
            }
            if ($v === '' || $v === 'OFF' || $v === 'NONE') {
                return '';
            }
        }
        return self::envFrameOptions();
    }

    private static function envFrameOptions(): string
    {
        $v = strtoupper(trim((string) Env::get('FRAME_OPTIONS', self::DEFAULT_FRAME_OPTIONS)));
        if ($v === 'DENY' || $v === 'SAMEORIGIN') {
            return $v;
        }
        if ($v === '' || $v === 'OFF' || $v === 'NONE') {
            return '';
        }
        return self::DEFAULT_FRAME_OPTIONS;
    }

    public static function cspPolicy(): string
    {
        $stored = AppSettings::get(self::CSP_POLICY);
        if (is_string($stored)) {
            return trim($stored);
        }
        try {
            $cfg = Config::get('app.csp_policy', null);
            if (is_string($cfg)) {
                $t = trim($cfg);
                if ($t !== '') {
                    return $t;
                }
            }
            $app = Config::get('app', []);
            if (is_array($app) && isset($app['csp_policy']) && is_string($app['csp_policy'])) {
                $t = trim($app['csp_policy']);
                if ($t !== '') {
                    return $t;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return self::envCspPolicy();
    }

    private static function envCspPolicy(): string
    {
        // En Config hay app.csp_policy con default; si no hay, usar el mismo
        // default seguro que existe en config.
        try {
            $cfg = Config::get('app.csp_policy', null);
            if (is_string($cfg)) {
                return trim($cfg);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return self::DEFAULT_CSP_POLICY;
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
        $allowlist = trim((string) ($data['redirect_allowlist'] ?? ''));
        $frame = strtoupper(trim((string) ($data['frame_options'] ?? '')));
        $csp = trim((string) ($data['csp_policy'] ?? ''));

        if ($frame !== '' && $frame !== 'DENY' && $frame !== 'SAMEORIGIN') {
            return ['ok' => false, 'error' => 'X-Frame-Options debe ser DENY, SAMEORIGIN o vacío.'];
        }

        // TRUST_PROXY: checkbox -> 1/0
        $trust = isset($data['trust_proxy']) && $data['trust_proxy'] ? '1' : '0';
        if (!AppSettings::set(self::TRUST_PROXY, $trust)) {
            return ['ok' => false, 'error' => 'No se pudo guardar la configuración (¿migraciones?).'];
        }
        if (!AppSettings::set(self::REDIRECT_ALLOWLIST, $allowlist === '' ? null : $allowlist)) {
            return ['ok' => false, 'error' => 'No se pudo guardar la configuración (¿migraciones?).'];
        }
        if (!AppSettings::set(self::FRAME_OPTIONS, $frame === '' ? null : $frame)) {
            return ['ok' => false, 'error' => 'No se pudo guardar la configuración (¿migraciones?).'];
        }
        if (!AppSettings::set(self::CSP_POLICY, $csp === '' ? null : $csp)) {
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
