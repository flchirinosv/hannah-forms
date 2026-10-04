<?php

declare(strict_types=1);

namespace App\Core;

class Session
{
    public static function start(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (bool) $config['secure'];
        if (!$secure && (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
            $secure = true;
        }

        session_name($config['name']);
        session_set_cookie_params([
            'lifetime' => $config['lifetime'],
            'path'     => $config['path'],
            'secure'   => $secure,
            'httponly' => $config['httponly'],
            'samesite' => $config['samesite'],
        ]);
        // Mantener la data de sesión viva el mismo tiempo que la cookie
        ini_set('session.gc_maxlifetime', (string) $config['lifetime']);
        ini_set('session.use_strict_mode', '1');
        // Sin esto PHP acepta un identificador de sesión que venga en la URL
        // (SID en GET) y lo fija: permite "secuestrar" una sesión_fixando el
        // enlace que se manda a la víctima. Con use_only_cookies el ID solo
        // viaja en la cookie, que además va httponly + samesite.
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function flash(string $key, string $message): void
    {
        $_SESSION['_flash'][$key] = $message;
    }

    public static function getFlash(string $key, string $default = ''): string
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    /**
     * Un flash que no es un texto.
     *
     * Existe por el informe de la importación del listado: el mensaje («5 filas
     * quedaron fuera») es un texto, pero **qué filas** son un array, y meter un
     * array en `flash()` lo convertiría en la cadena `Array` —que es justo el
     * caso para el que existe este método: un `json_encode` en la sesión que
     * alguien tiene que acordarse de decodificar.
     *
     * Es de un solo uso igual que el de texto (`takeValue()` borra), porque un
     * informe que se reensee en cada visita se quedaría pegado para siempre.
     *
     * @param array<string,mixed>|list<mixed>|scalar $value
     */
    public static function flashValue(string $key, $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Leer un flash de un solo uso y BORRARLO, sin fijar un tipo.
     *
     * El gemelo de `getFlash()` para lo que no es un texto. Se borra igual
     * porque el motivo es el mismo: un valor que se relee en cada visita se queda
     * pegado para siempre, y un informe de importación pegado es peor que
     * ninguno —dice que cinco filas no entraron cada vez que se guarda un ajuste.
     *
     * @return array<string,mixed>|list<mixed>|scalar|null
     */
    public static function takeValue(string $key, $default = null)
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public static function hasFlash(string $key): bool
    {
        return isset($_SESSION['_flash'][$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}