<?php

declare(strict_types=1);

namespace App\Core;

class Config
{
    private static ?array $config = null;

    /**
     * Overrides para tests, indexados por clave CON puntos
     * (`app.registration_enabled`).
     *
     * `Config` no tenía setter a propósito: la configuración se lee una vez de
     * `.env` y no debe cambiar bajo los pies de la app. Un interruptor como
     * `REGISTRATION_ENABLED` sí necesita poder probarse en los dos estados, y
     * para eso no vale reescribir la variable de entorno: el runner llama a
     * `Env::load()` una vez y `all()` congela el array en la primera lectura, así
     * que cambiar el entorno después no llega a nada.
     *
     * Por eso el override vive AQUÍ y no en un `set()` general: solo existe para
     * los tests, se aplica antes de la lectura congelada, y `forgetForTesting()`
     * lo devuelve al estado real. La app no lo llama nunca.
     *
     * @var array<string, mixed>
     */
    private static array $overrides = [];

    public static function all(): array
    {
        if (self::$config === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
            foreach (self::$overrides as $key => $value) {
                $node = &$config;
                $segments = explode('.', $key);
                $last = array_pop($segments);
                foreach ($segments as $segment) {
                    if (!isset($node[$segment]) || !is_array($node[$segment])) {
                        $node[$segment] = [];
                    }
                    $node = &$node[$segment];
                }
                $node[$last] = $value;
                unset($node);
            }
            self::$config = $config;
        }
        return self::$config;
    }

    /**
     * Fija un override de configuración para el test en curso. Llama a
     * `forgetForTesting()` en el tearDown.
     */
    public static function setForTesting(string $key, $value): void
    {
        self::$overrides[$key] = $value;
        self::$config = null;
    }

    /**
     * Quita los overrides y vuelve a leer la configuración real. Sin esto el
     * array congelado de `all()` se llevaría el valor del test al siguiente.
     */
    public static function forgetForTesting(): void
    {
        self::$overrides = [];
        self::$config = null;
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