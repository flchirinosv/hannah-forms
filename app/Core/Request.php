<?php

declare(strict_types=1);

namespace App\Core;

class Request
{
    /**
     * Límite máximo (bytes) de cuerpo JSON aceptado por json().
     * Evita que un cuerpo arbitrariamente grande agote la memoria del
     * proceso (A1 de la auditoría 2026-09-25).
     */
    private static ?bool $jsonTooLarge = null;

    /** Cuerpo JSON inyectable en tests (php://input no es legible en CLI puro). */
    private static ?string $testRequestBody = null;

    /** Seam de tests para ip(): null = leer la config (TRUST_PROXY). */
    private static ?bool $trustProxyForTesting = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function get(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    public static function post(string $key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }

    public static function all(): array
    {
        return array_merge($_GET, $_POST);
    }

    public static function input(string $key, $default = null)
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public static function json(): array
    {
        self::$jsonTooLarge = false;

        // A1: rechazar sin leer el cuerpo si Content-Length supera el límite.
        $app = Config::get('app', []);
        $maxBytes = (int) ($app['max_json_bytes'] ?? 2097152);
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBytes) {
            self::$jsonTooLarge = true;
            return [];
        }

        $raw = self::$testRequestBody ?? file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }

        if (strlen($raw) > $maxBytes) {
            self::$jsonTooLarge = true;
            return [];
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (!str_contains(strtolower($contentType), 'application/json')) {
            return [];
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * P10 — Convierte un valor php.ini con sufijo (8M, 512K, 1G) a bytes.
     * 0 significa "sin límite".
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $num  = (int) $value;
        $unit = strtolower(substr($value, -1));
        return match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }

    /**
     * P10 — El cuerpo del POST superó `post_max_size`: PHP descarta `$_POST` y
     * `$_FILES` por completo, así que el error real NO es un token CSRF inválido
     * sino un 413. Se detecta por Content-Length + cuerpo vacío.
     */
    public static function bodyTooLarge(): bool
    {
        if (!self::isPost()) {
            return false;
        }
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len <= 0) {
            return false;
        }
        $limit = self::iniBytes((string) ini_get('post_max_size'));
        if ($limit <= 0) {
            return false;   // 0 = sin límite
        }
        return $len > $limit && $_POST === [] && $_FILES === [];
    }

    /**
     * Indica si el último json() fue rechazado por exceder el límite de tamaño.
     * Permite responder 413 en lugar de 422 con estructura inválida.
     */
    public static function jsonTooLarge(): bool
    {
        return self::$jsonTooLarge ?? false;
    }

    /**
     * Reinicia el estado de la bandera jsonTooLarge (útil en tests).
     */
    public static function resetJsonState(): void
    {
        self::$jsonTooLarge = null;
    }

    /**
     * Seam para tests: fija (o limpia con null) el cuerpo JSON que leerá json(),
     * porque php://input no es legible bajo el runner CLI puro.
     */
    public static function setRequestBodyForTests(?string $body): void
    {
        self::$testRequestBody = $body;
        self::$jsonTooLarge = null;
    }

    /**
     * Seam para tests: fija el valor de trust_proxy sin tocar la config
     * (Config no tiene setter). null = se lee de la config; los tests que lo
     * usen deben devolverlo a null en setUp/tearDown.
     */
    public static function setTrustProxyForTesting(?bool $value): void
    {
        self::$trustProxyForTesting = $value;
    }

    public static function clean(string $value): string
    {
        return trim($value);
    }

    public static function sanitize(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $clean[$key] = self::sanitize($value);
            } else {
                $clean[$key] = self::clean((string) $value);
            }
        }
        return $clean;
    }

    /**
     * ¿Hay un proxy de confianza delante (config `app.trust_proxy`)?
     *
     * Lo leen `ip()` (para los headers de reenvío) y `PublicUrl` (para el
     * esquema de `X-Forwarded-Proto`), así que la decisión vive aquí y no
     * duplicada: si divergieran, el rate limit y el enlace público estarían
     * leyendo valores distintos del mismo interruptor.
     */
    public static function trustsProxy(): bool
    {
        $appCfg = Config::get('app', []);
        return self::$trustProxyForTesting ?? (bool) ($appCfg['trust_proxy'] ?? false);
    }

    public static function ip(): string
    {
        // Solo confiar en X-Forwarded-For/X-Real-IP si hay un proxy de
        // confianza (config.trust_proxy). En caso contrario, cualquier
        // cliente podría falsificarlos (bypass de rate limiting, IPs falsas).
        $trustProxy = self::trustsProxy();

        if ($trustProxy) {
            $headers = [
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_REAL_IP',
                'REMOTE_ADDR',
            ];

            foreach ($headers as $header) {
                $value = $_SERVER[$header] ?? '';
                if ($value === '') {
                    continue;
                }

                // X-Forwarded-For puede contener múltiples IPs: client, proxy1, proxy2...
                $ips = array_map('trim', explode(',', (string) $value));
                foreach ($ips as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        return $ip;
                    }
                }
            }

            return '0.0.0.0';
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }

    public static function userAgent(): string
    {
        // F1.4: `substr()` corta por BYTES, y la columna es VARCHAR(255) en
        // utf8mb4 con STRICT_TRANS_TABLES: un multibyte partido en el byte 255
        // dejaba la cadena inválida y `Submission::create()` fallaba con
        // ERROR 1366, devolviendo un 500 en CADA envío de ese cliente. Es una
        // cabecera, así que el guardián `post-max` no lo detiene.
        // mb_substr() recorta por caracteres y nunca parte un multibyte.
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            $cut = mb_substr($ua, 0, 255, 'UTF-8');
            // Si aun así no es UTF-8 válido (bytes sueltos enviados a propósito),
            // se descarta: preferimos un user_agent ausente a un 500.
            if (function_exists('mb_check_encoding') && !mb_check_encoding($cut, 'UTF-8')) {
                return '';
            }
            return $cut;
        }
        return substr($ua, 0, 255);
    }

    public static function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }
}