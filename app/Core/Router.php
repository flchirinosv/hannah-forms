<?php

declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];

    public function get(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, array|callable $handler, array $middleware): void
    {
        $this->routes[] = [
            'method'     => $method,
            'path'       => $path,
            'pattern'    => $this->compile($path),
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    private function compile(string $path): string
    {
        $path = '/' . trim($path, '/');
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        $uri    = '/' . trim($uri, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (!preg_match($route['pattern'], $uri, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $params[$key] = $value;
                }
            }

            $this->run($route, $params);
            return;
        }

        // B1: si la ruta existe pero con otro método, responder 405 con Allow
        // (en lugar de 404), para que el cliente sepa que solo falla el método.
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['pattern'], $uri)) {
                $allowed[$route['method']] = true;
            }
        }
        if ($allowed !== []) {
            Response::methodNotAllowed(array_keys($allowed));
        }

        Response::notFound();
    }

    private function run(array $route, array $params): void
    {
        // Aplicar middleware primero
        foreach ($route['middleware'] as $mw) {
            Middleware\Middleware::handle($mw, $params);
        }

        $handler = $route['handler'];

        if (is_callable($handler)) {
            $this->invokeWithNamedParams($handler, $params);
            return;
        }

        if (is_array($handler) && count($handler) === 2) {
            [$controller, $action] = $handler;
            $controllerInstance = new $controller();

            if (!method_exists($controllerInstance, $action)) {
                Response::abort(500, 'Acción no encontrada en ' . $controller);
                return;
            }

            // En PHP 8+, call_user_func_array no acepta named params para
            // controladores; reordenamos según la firma del método.
            $ref = new \ReflectionMethod($controllerInstance, $action);
            $ordered = self::orderArgs($ref, $params);

            // Ya respondió (404/500) y en CLI Response no corta el proceso.
            if ($ordered === null) {
                return;
            }

            $ref->invokeArgs($controllerInstance, $ordered);
            return;
        }
    }

    /**
     * Ordena y CONVIERTE los parámetros de la ruta según la firma del handler.
     * Devuelve null cuando ya respondió con un error, para que el llamador
     * pare: en CLI `Response::*` no corta el proceso (ver Response::halt).
     *
     * @return array<int,mixed>|null
     */
    private static function orderArgs(\ReflectionFunctionAbstract $ref, array $params): ?array
    {
        $ordered = [];
        foreach ($ref->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $params)) {
                $value = self::coerceParam($parameter, (string) $params[$name]);
                if ($value === null) {
                    // Un id que no es un id no es un error del servidor: ese
                    // recurso no existe. 404, nunca 500 con stack trace.
                    Response::notFound();
                    return null;
                }
                $ordered[] = $value;
            } elseif ($parameter->isDefaultValueAvailable()) {
                $ordered[] = $parameter->getDefaultValue();
            } else {
                Response::abort(500, 'Falta parámetro requerido: ' . $name);
                return null;
            }
        }
        return $ordered;
    }

    /**
     * Un parámetro de ruta llega como texto de la URL, pero las firmas piden
     * `int` (ids de `{id}`/`{sid}`) o `string` (`{slug}`). Como este archivo es
     * `strict_types=1`, pasar "abc" a un `int $id` es un TypeError: antes,
     * `/forms/abc/builder` devolvía HTTP 500 con el stack trace (filtrando la
     * ruta absoluta del servidor) en lugar de un 404.
     *
     * Devuelve el valor convertido, o null si no encaja con el tipo declarado.
     * null solo puede ser ambiguo con un parámetro que admita null, y para un id
     * de ruta "abc" tampoco es un id: en ambos casos la respuesta es 404.
     */
    public static function coerceParam(\ReflectionParameter $parameter, string $raw): int|float|bool|string|null
    {
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType) {
            // Sin tipo declarado: se normaliza PHP, sin intervención.
            return $raw;
        }

        return match ($type->getName()) {
            'int'   => self::toInt($raw),
            'float' => is_numeric($raw) ? (float) $raw : null,
            'bool'  => in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true),
            'string' => $raw,
            // Tipos que no llegan de la URL (array, objetos...): sin cambios.
            default => $raw,
        };
    }

    /**
     * Convierte a int solo si el texto es un entero de verdad. `is_numeric`
     * aceptaría "1e3", " 5" o "5.0", y `filter_var(FILTER_VALIDATE_INT)`
     *Tolera espacios alrededor: para un id, " 5" no es un id.
     */
    private static function toInt(string $raw): ?int
    {
        if (preg_match('/^-?\d+$/', $raw) !== 1) {
            return null;
        }
        // false además cuando el número desborda el rango de int.
        $int = filter_var($raw, FILTER_VALIDATE_INT);
        return $int === false ? null : $int;
    }

    /**
     * Invoca un callable con parámetros nombrados, reordenando según la firma.
     */
    private static function invokeWithNamedParams(callable $handler, array $params): void
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[1])) {
            $ref = new \ReflectionMethod($handler[0], $handler[1]);
        } elseif ($handler instanceof \Closure) {
            $ref = new \ReflectionFunction($handler);
        } else {
            // Funciones/otros callables: sin named params complejos
            call_user_func_array($handler, array_values($params));
            return;
        }

        $ordered = self::orderArgs($ref, $params);
        if ($ordered === null) {
            return;
        }

        $ref->invokeArgs($ordered);
    }
}