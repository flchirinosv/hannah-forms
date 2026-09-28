<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    public static function json(array $data, int $status = 200): void
    {
        if (!self::isCli()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        self::halt();
    }

    public static function redirect(string $url): void
    {
        // M4: permitir solo rutas relativas para evitar open redirect
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
            $url = '/';
        }

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
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($url === '' || !in_array($scheme, ['http', 'https'], true)) {
            $url = '/';
        }

        if (!self::isCli()) {
            header('Location: ' . $url);
        }
        self::halt();
    }

    public static function abort(int $code = 404, string $message = 'Not Found'): void
    {
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

    private static function halt(): void
    {
        if (!self::isCli()) {
            exit;
        }
    }
}