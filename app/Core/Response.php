<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    /** Registro de la última acción de corte (opt-in, para tests). */
    private static bool $recordHalt = false;

    /** @var array<int,array{type:string,url?:string,code?:int,status?:int,allow?:array}> */
    private static array $haltLog = [];

    /**
     * M4 — Sanea una URL de redirección: solo rutas relativas a la propia app.
     * Se rechaza cualquier cosa que un navegador pueda interpretar como una
     * salida del origen: absoluta con esquema, protocol-relative ('//host'),
     * backslashes ('/\\host'), CR/LF/control (header injection) y esquemas o
     * barra doble enmascarados con percent-encoding.
     */
    public static function sanitizeRedirectUrl(string $url): string
    {
        if ($url === '' || $url[0] !== '/') {
            return '/';
        }
        if (str_starts_with($url, '//') || str_contains($url, '\\')) {
            return '/';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return '/';
        }

        // '//'/'\\' o esquema escondidos con percent-encoding al arranque.
        $decoded = rawurldecode($url);
        if ($decoded !== $url) {
            if (str_starts_with($decoded, '//') || str_contains($decoded, '\\') || preg_match('/[\x00-\x1F\x7F]/', $decoded)) {
                return '/';
            }
            if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $decoded)) {
                return '/';
            }
        }
        return $url;
    }

    /**
     * P6 — Sanea una URL externa opt-in: solo absolutas http/https con host.
     * Rechaza CR/LF/control (header injection vía 'Location:') y credenciales
     * embebidas (userinfo). El caller (FillController) aplica ANTES la allowlist
     * de hosts; esto es solo la barrera de forma.
     */
    public static function sanitizeExternalUrl(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '/';
        }
        if ((string) parse_url($url, PHP_URL_HOST) === '') {
            return '/';
        }
        if (preg_match('/[\x00-\x20\x7F]/', $url)) {
            return '/';
        }
        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return '/';
        }
        return $url;
    }

    public static function json(array $data, int $status = 200): void
    {
        self::logHalt(['type' => 'json', 'status' => $status]);
        if (!self::isCli()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        self::halt();
    }

    public static function redirect(string $url): void
    {
        $url = self::sanitizeRedirectUrl($url);
        self::logHalt(['type' => 'redirect', 'url' => $url]);
        if (!self::isCli()) {
            header('Location: ' . $url);
        }
        self::halt();
    }

    /**
     * Redirección externa opt-in (P6): solo URLs absolutas http/https ya
     * validadas por el caller (thanks.redirect_url). Cualquier otra cosa -> '/'.
     */
    public static function redirectExternal(string $url): void
    {
        $url = self::sanitizeExternalUrl($url);
        self::logHalt(['type' => 'redirect_external', 'url' => $url]);
        if (!self::isCli()) {
            header('Location: ' . $url);
        }
        self::halt();
    }

    public static function abort(int $code = 404, string $message = 'Not Found'): void
    {
        self::logHalt(['type' => 'abort', 'code' => $code]);
        if (!self::isCli()) {
            http_response_code($code);
        }
        View::render('errors/' . $code, ['message' => $message], 'error_layout');
        self::halt();
    }

    public static function notFound(): void
    {
        self::abort(404, 'Página no encontrada');
    }

    public static function methodNotAllowed(array $allow = []): void
    {
        self::logHalt(['type' => 'method_not_allowed', 'allow' => $allow]);
        if (!self::isCli()) {
            http_response_code(405);
            if ($allow !== []) {
                header('Allow: ' . implode(', ', $allow));
            }
        }
        View::render('errors/405', ['message' => 'Método no permitido'], 'error_layout');
        self::halt();
    }

    public static function forbidden(): void
    {
        self::abort(403, 'Acceso denegado');
    }

    /**
     * En el runner de tests (PHP CLI puro) NO se emiten headers ni se hace
     * exit: así los controllers se pueden invocar y seguir probando. Bajo
     * el servidor embebido (SAPI 'cli-server') y FPM el comportamiento es el
     * real (header + exit).
     */
    private static function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    /**
     * Seam para tests: graba la última acción de corte que bajo SAPI real haría
     * header+exit. El runner (CLI) no emite cabeceras ni corta el flujo, así que
     * un test puede invocar un controller y verificar Response::lastHalt()
     * (redirect a X / abort 419 ...) siempre que el controller tenga `return;`
     * después de la llamada. Opt-in: 'false' = sin registro ni sobrecoste.
     */
    public static function setRecordHalt(bool $on): void
    {
        self::$recordHalt = $on;
        if (!$on) {
            self::$haltLog = [];
        }
    }

    public static function lastHalt(): ?array
    {
        return self::$haltLog === [] ? null : self::$haltLog[count(self::$haltLog) - 1];
    }

    private static function logHalt(array $entry): void
    {
        if (self::$recordHalt) {
            self::$haltLog[] = $entry;
        }
    }

    private static function halt(): void
    {
        if (!self::isCli()) {
            exit;
        }
    }
}