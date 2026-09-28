<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Env
 *
 * Cargador de variables de entorno (.env) sin dependencias externas.
 * Implementacion propia reemplaza al paquete vlucas/phpdotenv (Composer).
 *
 * Soporta:
 * - Formato KEY=VALUE (una por linea)
 * - Comentarios con '#'
 * - Valores con comillas simples o dobles
 * - Variables de entorno ya definidas (no las sobreescribe por defecto)
 * - True/false, null, numeros como valores tipados
 */
class Env
{
    /**
     * Carga el archivo .env desde una ruta.
     *
     * @param string      $path      Ruta al archivo .env
     * @param bool        $override  Si true, sobreescribe variables ya definidas
     * @return array<string,mixed>  Variables cargadas
     */
    public static function load(string $path, bool $override = false): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $loaded = [];

        foreach ($lines as $line) {
            $parsed = self::parseLine($line);
            if ($parsed === null) {
                continue;
            }

            [$key, $value] = $parsed;

            if (!self::isValidKey($key)) {
                continue;
            }

            if (!$override && (getenv($key) !== false || !empty($_ENV[$key]) || !empty($_SERVER[$key]))) {
                // No sobreescribir variables ya existentes
                continue;
            }

            $typedValue = self::cast($value);

            putenv("{$key}={$value}");
            $_ENV[$key] = $typedValue;
            $_SERVER[$key] = $typedValue;

            $loaded[$key] = $typedValue;
        }

        return $loaded;
    }

    /**
     * Obtiene el valor de una variable de entorno.
     *
     * @param string $key      nombre de la variable
     * @param mixed  $default  valor por defecto si no existe
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return self::cast($value);
    }

    /**
     * Parsea una linea KEY=VALUE.
     *
     * @return array{0:string,1:string}|null
     */
    private static function parseLine(string $line): ?array
    {
        $line = trim($line);

        // Ignorar lineas vacias o comentarios
        if ($line === '' || $line[0] === '#') {
            return null;
        }

        // Buscar el primer '='"
        $pos = strpos($line, '=');
        if ($pos === false) {
            return null;
        }

        $key   = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        // Quitar comentarios al final del valor (no dentro de comillas)
        if ($value !== '' && !self::isQuoted($value)) {
            $hashPos = strpos($value, ' #');
            if ($hashPos !== false) {
                $value = trim(substr($value, 0, $hashPos));
            }
        }

        // Quitar comillas
        $value = self::unquote($value);

        return [$key, $value];
    }

    /**
     * Determina si el valor esta entre comillas (simples o dobles).
     */
    private static function isQuoted(string $value): bool
    {
        $len = strlen($value);
        if ($len < 2) {
            return false;
        }
        $first = $value[0];
        $last  = $value[$len - 1];
        return ($first === '"' && $last === '"') || ($first === "'" && $last === "'");
    }

    /**
     * Quita las comillas envolventes de un valor.
     */
    private static function unquote(string $value): string
    {
        if (self::isQuoted($value)) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    /**
     * Convierte valores string a tipos nativos.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function cast($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $lower = strtolower($value);

        switch ($lower) {
            case 'true':
                return true;
            case 'false':
                return false;
            case 'null':
                return null;
            case '':
                return '';
        }

        // Intentar convertir numeros enteros/float
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }

        return $value;
    }

    /**
     * Valida que la clave sea un nombre de variable valido.
     */
    private static function isValidKey(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key);
    }
}
