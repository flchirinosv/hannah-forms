<?php

declare(strict_types=1);

namespace App\Core;

class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'main'): void
    {
        $templatePath = self::resolve($template);

        if (!is_readable($templatePath)) {
            http_response_code(500);
            echo 'Vista no encontrada: ' . htmlspecialchars($template);
            exit;
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $templatePath;
        $content = ob_get_clean();

        if ($layout === null) {
            echo $content;
            return;
        }

        $layoutPath = self::layoutPath($layout);
        if (is_readable($layoutPath)) {
            include $layoutPath;
        } else {
            echo $content;
        }
    }

    private static function resolve(string $template): string
    {
        // B3: impedir traversal de path ('..') en el nombre del template.
        // Los templates son nombres fijos pasados por los controllers, pero
        // se valida por si acaso alguna ruta llegara a admitir entrada externa.
        if (str_contains($template, '..')) {
            http_response_code(500);
            echo 'Template inválido.';
            exit;
        }
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        return $config['paths']['views'] . '/' . str_replace('.', '/', $template) . '.php';
    }

    private static function layoutPath(string $layout): string
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        return $config['paths']['views'] . '/layouts/' . $layout . '.php';
    }

    public static function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');
        // Cache-busting automático: si el archivo cambia, la URL cambia y el
        // navegador (móvil/PC) siempre recibe la versión fresca.
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $file = $config['paths']['public'] . '/assets/' . $path;
        $mtime = is_file($file) ? filemtime($file) : '';
        return '/assets/' . $path . ($mtime !== false && $mtime !== '' ? '?v=' . $mtime : '');
    }

    public static function url(string $path = '/'): string
    {
        return '/' . ltrim($path, '/');
    }
}