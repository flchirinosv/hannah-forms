<?php

declare(strict_types=1);

namespace App\Core;

class Config
{
    private static ?array $config = null;

    public static function all(): array
    {
        if (self::$config === null) {
            self::$config = require dirname(__DIR__, 2) . '/config/config.php';
        }
        return self::$config;
    }

    /**
     * Lee un valor por clave. Soporta notación de punto para navegar el array
     * anidado: Config::get('paths.uploads') devuelve paths => uploads.
     * Una clave de primer nivel con punto literal no existe en config.php, así
     * que el recorrido es retrocompatible con los accesos planos.
     *
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $config = self::all();
        if (!str_contains($key, '.')) {
            return array_key_exists($key, $config) ? $config[$key] : $default;
        }

        $node = $config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public static function db(): array
    {
        return self::get('database', []);
    }

    public static function mail(): array
    {
        return self::get('mail', []);
    }
}