<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Response;
use App\Core\Router;

/**
 * Tests unitarios de Router. No requiere base de datos.
 */
class RouterTest
{
    private Router $router;

    public function setUp(): void
    {
        $this->router = new Router();
    }

    public function testResuelveRutaGet(): void
    {
        $captured = '';
        $this->router->get('/ping', static function () use (&$captured): void {
            $captured = 'pong';
        });

        $this->dispatch('GET', '/ping');
        assertSame('pong', $captured);
    }

    public function testResuelveRutaPost(): void
    {
        $captured = '';
        $this->router->post('/echo', static function () use (&$captured): void {
            $captured = 'posted';
        });

        $this->dispatch('POST', '/echo');
        assertSame('posted', $captured);
    }

    public function testCapturaParametrosDeRuta(): void
    {
        $captured = '';
        $this->router->get('/users/{id}', static function (string $id) use (&$captured): void {
            $captured = $id;
        });

        $this->dispatch('GET', '/users/42');
        assertSame('42', $captured);
    }

    public function testCapturaParametrosAlfanumericos(): void
    {
        $captured = '';
        $this->router->get('/items/{itemId}', static function (string $itemId) use (&$captured): void {
            $captured = $itemId;
        });

        $this->dispatch('GET', '/items/abc');
        assertSame('abc', $captured);
    }

    public function testNoMatchRutaInexistenteNoLlamaHandler(): void
    {
        $ran = false;
        $this->router->get('/existe', static function () use (&$ran): void {
            $ran = true;
        });

        // Registrar DELETE para que dispatch no caiga en Response::notFound()
        $this->router->delete('/existe', static function (): void {
        });

        $this->dispatch('DELETE', '/existe');
        assertFalse($ran, 'El handler GET no debería ejecutarse con method DELETE');
    }

    public function testMiddlewareSeRegistra(): void
    {
        $this->router->get('/mw', static function (): void {
        }, ['auth']);

        $ref = new \ReflectionClass($this->router);
        $prop = $ref->getProperty('routes');
        $routes = $prop->getValue($this->router);
        assertTrue(isset($routes[0]['middleware']), 'middleware debería estar registrado');
        assertSame(['auth'], $routes[0]['middleware']);
    }

    private function dispatch(string $method, string $uri): void
    {
        // Solo usamos dispatch para rutas existentes; evitamos Response::notFound()
        $this->router->dispatch($method, $uri);
    }
}
