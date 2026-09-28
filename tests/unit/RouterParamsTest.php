<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Router;
use App\Controllers\FormController;
use App\Controllers\FillController;

/**
 * Fase 2 — parámetros de ruta convertidos al tipo de la firma.
 *
 * Antes, `/forms/abc/builder` reventaba con un TypeError: HTTP 500 y el stack
 * trace en la respuesta, filtrando la ruta absoluta del servidor. La causa es
 * que la URL siempre da texto y las firmas piden `int`, sin coerción posible
 * porque Router.php es strict_types=1.
 *
 * El test tiene dos mitades: el comportamiento de la conversión, y un contrato
 * sobre config/routes.php que obliga a que TODO parámetro de ruta tenga un tipo
 * convertible. Esa segunda parte es la que agarra el bug para las 17 rutas con
 * `{id}` de golpe, y sigue agarrándolo cuando se añada una ruta nueva.
 */
class RouterParamsTest
{
    private function idParam(): \ReflectionParameter
    {
        return (new \ReflectionMethod(FormController::class, 'show'))->getParameters()[0];
    }

    private function slugParam(): \ReflectionParameter
    {
        return (new \ReflectionMethod(FillController::class, 'show'))->getParameters()[0];
    }

    /* ---------- conversión ---------- */

    public function testUnIdNumericoSeConvierteAInt(): void
    {
        $p = $this->idParam();
        assertEquals(5372, Router::coerceParam($p, '5372'));
        assertTrue(is_int(Router::coerceParam($p, '5372')), 'debe llegar int, no string numérico');
    }

    public function testElCeroYLosNegativosSonIntsValidos(): void
    {
        $p = $this->idParam();
        assertEquals(0, Router::coerceParam($p, '0'));
        assertEquals(-1, Router::coerceParam($p, '-1'));
    }

    public function testUnIdQueNoEsNumeroDaNullParaResponder404(): void
    {
        $p = $this->idParam();
        foreach (['abc', '5372abc', '', ' ', 'builder', '1; DROP TABLE fields'] as $bad) {
            assertNull(
                Router::coerceParam($p, $bad),
                '«' . $bad . '» no es un id: debe dar null (404), no un entero'
            );
        }
    }

    public function testRechazaLosTrucosDeParseoQueAceptanIsNumeric(): void
    {
        $p = $this->idParam();
        // is_numeric() se los traga todos y darían el id equivocado:
        // FILTER_VALIDATE_INT en cambio tolera espacios, y (string)5.0 da "5".
        foreach ([' 5', '5 ', '5.0', '1e3', '+5', '0x1A', '٣', "\t7"] as $tricky) {
            assertNull(
                Router::coerceParam($p, $tricky),
                '«' . $tricky . '» no debe convertirse en un id: es un id ambiguo o falso'
            );
        }
    }

    public function testUnIdQueDesbordaElRangoDeIntDaNull(): void
    {
        $p = $this->idParam();
        // (int)'999...9' daría PHP_INT_MAX en silencio: mejor un 404 que un id
        // inventado que apunta a otro formulario.
        assertNull(Router::coerceParam($p, '99999999999999999999'));
        assertNull(Router::coerceParam($p, '9223372036854775808'));
    }

    public function testElSlugSeQuedaComoString(): void
    {
        $p = $this->slugParam();
        foreach (['quis-example', '123', 'abc', 'con-espacios-y-puntos.1'] as $slug) {
            assertEquals($slug, Router::coerceParam($p, $slug));
        }
    }

    public function testUnParametroSinTipoDeclaradoNoSeToca(): void
    {
        $ref = new \ReflectionFunction(static function ($sinTipo) {
            return $sinTipo;
        });
        assertEquals('lo que sea', Router::coerceParam($ref->getParameters()[0], 'lo que sea'));
    }

    /* ---------- camino completo por dispatch() ---------- */

    public function testUnIdNoNumericoDa404YNoLlegaAlControlador(): void
    {
        $router = new Router();
        $llego = false;
        $router->get('/forms/{id}', static function (int $id) use (&$llego): void {
            $llego = true;
        });

        // En CLI Response no corta el proceso, así que se captura la salida
        // (que es la página 404) en vez de dejarla en el log del runner.
        ob_start();
        try {
            $router->dispatch('GET', '/forms/abc');
        } finally {
            $salida = (string) ob_get_clean();
        }

        assertFalse($llego, 'el handler no debe ejecutarse con un id que no es un id');
        assertTrue(
            str_contains($salida, 'no encontrada') || str_contains($salida, '404'),
            'se esperaba la página 404, se obtuvo: ' . substr(trim($salida), 0, 120)
        );
    }

    public function testUnIdValidoSiLlegaAlControladorComoInt(): void
    {
        $router = new Router();
        $recibido = null;
        $router->get('/forms/{id}', static function (int $id) use (&$recibido): void {
            $recibido = $id;
        });

        $router->dispatch('GET', '/forms/5372');

        assertTrue($recibido === 5372, 'debía recibir el int 5372, recibió ' . var_export($recibido, true));
    }

    public function testUnSlugAlfanumericoSiguePasandoSinTocarse(): void
    {
        // Regresión del otro lado: convertir no puede romper los {slug}.
        $router = new Router();
        $recibido = null;
        $router->get('/f/{slug}', static function (string $slug) use (&$recibido): void {
            $recibido = $slug;
        });

        $router->dispatch('GET', '/f/quis-example');

        assertEquals('quis-example', $recibido);
    }

    /* ---------- contrato sobre todas las rutas ---------- */

    /**
     * Extrae [método, ruta, [clase, acción]] de cada $router->get/post/... de
     * config/routes.php.
     *
     * @return array<int,array{0:string,1:string,2:array{0:class-string,1:string},3:array<string>}>
     */
    private function routes(): array
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/config/routes.php');

        // routes.php usa imports cortos (FormController::class), así que hay que
        // resolverlos con los `use` de arriba; si no, class_exists() falla y el
        // contrato pasa por alto todas las rutas.
        $imports = [];
        if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);$/m', $src, $u) > 0) {
            foreach ($u[1] as $fqcn) {
                $parts = explode('\\', $fqcn);
                $imports[end($parts)] = $fqcn;
            }
        }

        $out = [];
        $pattern = '/\$router->(get|post|put|delete)\(\s*\'([^\']+)\'\s*,\s*\[([A-Za-z0-9_\\\\]+)::class\s*,\s*\'([^\']+)\'\]/';
        preg_match_all($pattern, $src, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $class = str_contains($hit[3], '\\') ? $hit[3] : ($imports[$hit[3]] ?? $hit[3]);
            $placeholders = [];
            if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $hit[2], $ph) > 0) {
                $placeholders = $ph[1];
            }
            $out[] = [$hit[1], $hit[2], [$class, $hit[4]], $placeholders];
        }
        return $out;
    }

    public function testSeEncuentranLasRutasQueDeclaraElProyecto(): void
    {
        // Guarda contra un routes.php que se vacíe accidentalmente: sin esto el
        // contrato de abajo pasaría en verde no comprobando nada.
        $routes = $this->routes();
        assertTrue(count($routes) >= 30, 'se esperaban 30+ rutas en routes.php, se hallaron ' . count($routes));
    }

    public function testTodoParametroDeRutaTieneUnTipoQueElRouterSabeConvertir(): void
    {
        $convertibles = ['int', 'float', 'bool', 'string'];
        $problemas = [];

        foreach ($this->routes() as [$verb, $path, [$class, $action], $placeholders]) {
            if ($placeholders === []) {
                continue;
            }
            if (!class_exists($class)) {
                $problemas[] = $path . ' → la clase ' . $class . ' no existe';
                continue;
            }
            if (!method_exists($class, $action)) {
                $problemas[] = $path . ' → ' . $class . '::' . $action . ' no existe';
                continue;
            }

            $ref = new \ReflectionMethod($class, $action);
            $firmados = [];
            foreach ($ref->getParameters() as $p) {
                $firmados[] = $p->getName();
            }

            foreach ($placeholders as $ph) {
                if (!in_array($ph, $firmados, true)) {
                    // El handler no lo pide: el param se ignora, no revienta.
                    continue;
                }
                $param = $ref->getParameters()[array_search($ph, $firmados, true)];
                $type = $param->getType();
                $nombre = $type instanceof \ReflectionNamedType ? $type->getName() : 'sin tipo';
                if (!in_array($nombre, $convertibles, true)) {
                    $problemas[] = sprintf(
                        '%s %s: {%s} llega de la URL como string y %s::%s lo declara «%s», '
                            . 'que Router::coerceParam no convierte → TypeError (500) en vez de 404',
                        $verb,
                        $path,
                        $ph,
                        $class,
                        $action,
                        $nombre
                    );
                }
            }
        }

        assertTrue(
            $problemas === [],
            "Rutas con un tipo de parámetro no convertible:\n - " . implode("\n - ", $problemas)
        );
    }

    public function testLasRutasConIdDeclaranIntYNoString(): void
    {
        // El bug era exactamente este: {id} en la URL contra `int $id` en la
        // firma. Se fija por si alguien "arregla" el 500-changing el tipo a
        // string para que PHP deje de quejarse.
        $vistos = 0;
        foreach ($this->routes() as [$verb, $path, [$class, $action], $placeholders]) {
            foreach ($placeholders as $ph) {
                if ($ph !== 'id' && $ph !== 'sid') {
                    continue;
                }
                $ref = new \ReflectionMethod($class, $action);
                $param = null;
                foreach ($ref->getParameters() as $p) {
                    if ($p->getName() === $ph) {
                        $param = $p;
                    }
                }
                if ($param === null) {
                    continue;
                }
                $vistos++;
                assertEquals(
                    'int',
                    (string) $param->getType(),
                    $verb . ' ' . $path . ': {' . $ph . '} debe declararse int, no '
                        . (string) $param->getType()
                );
            }
        }
        assertTrue($vistos >= 15, 'se esperaban 15+ parámetros de id en las rutas, se hallaron ' . $vistos);
    }
}
