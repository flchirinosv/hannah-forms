<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View;

/**
 * M2 — La forma del listado de formularios del dashboard.
 *
 * No prueba el SQL (eso es `FormListPaginationTest`), sino el HTML y el JS que
 * dejaron de ser de cliente. Estas cuatro cosas se pueden perder sin que nada
 * se rompa en PHP y se pierden juntas:
 *
 *  - La barra de búsqueda/filtro/orden tiene que ser un formulario GET de
 *    verdad. Si vuelve a ser un `<div>` con un input suelto, el `Enter` deja de
 *    buscar sin JS y no hay forma de llegar a la página 2 por URL.
 *  - Los botones de estado tienen que ser enlaces con el filtro puesto, y no un
 *    `active` que solo existe en memoria: un estado que no está en la URL no se
 *    puede compartir ni sobrevive al «atrás» del navegador.
 *  - El «sin resultados» lo decide el servidor, y solo cuando HAY formularios
 *    en el alcance: con el `d-none` de antes, un filtro que no casa con nada y
 *    una cuenta vacía se veían igual, y la segunda con un botón de «crear tu
 *    primer formulario» que no era verdad.
 *  - El paginador conserva los filtros al paginar. Un `/dashboard?page=2` sin el
 *    `q` es la página 2 de todo lo demás.
 */
class DashboardListMarkupTest
{
    private const ROOT = __DIR__ . '/../../';

    private static function fuente(string $relativa): string
    {
        $ruta = self::ROOT . $relativa;
        assertTrue(is_file($ruta), 'no se encuentra ' . $relativa);
        return (string) file_get_contents($ruta);
    }

    /** El partial del paginador, renderizado con datos de mentira. */
    private static function paginador(int $total, int $page, int $pages, int $perPage, string $qs): string
    {
        $html = (string) View::partial('dashboard/_pagination', [
            'listado' => ['items' => [], 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage],
            'base'    => '/dashboard',
            'qs'      => $qs,
        ]);
        return $html;
    }

    public function testLaBarraDeFiltrosEsUnFormularioGetDeVerdad(): void
    {
        $vista = self::fuente('views/dashboard/index.php');

        assertTrue(
            str_contains($vista, '<form method="get" action="/dashboard"'),
            'la barra tiene que ser un GET a /dashboard: sin eso el `Enter` del buscador no busca sin JS'
        );
        assertTrue(str_contains($vista, 'name="q"'), 'el buscador necesita su `name` para viajar en la URL');
        assertTrue(
            str_contains($vista, '<input type="hidden" name="estado"')
            && str_contains($vista, '<input type="hidden" name="orden"'),
            'el filtro y el orden que están puestos viajan escondidos: si no, filtrar borra la búsqueda'
        );
        assertTrue(
            str_contains($vista, '<noscript>'),
            'sin JS el desplegable de orden no tiene a dónde ir solo: su camino es el botón del `<noscript>`'
        );
    }

    public function testLosFiltrosDeEstadoSonEnlacesConElFiltroPuestoYNoBotones(): void
    {
        $vista = self::fuente('views/dashboard/index.php');

        assertFalse(
            str_contains($vista, 'data-filter'),
            'quedó un `data-filter`: esos botones eran de cliente y ya no hacen nada'
        );
        assertFalse(
            str_contains($vista, 'data-paginate'),
            'quedó un `data-paginate`: el paginador de cliente de app.js ya no existe'
        );
        assertTrue(
            str_contains($vista, 'aria-current="true"'),
            'el estado activo se marca para el lector de pantalla, no solo con la clase'
        );
        assertTrue(
            str_contains($vista, 'href="/dashboard<?= $filtroQs('),
            'los estados son enlaces a /dashboard con query, no botones sueltos'
        );
        assertTrue(
            str_contains($vista, "'page' => null"),
            'y al cambiar de estado se vuelve a la página 1: la 3 de un filtro anterior no existe'
        );
    }

    public function testElSinResultadosSoloSaleCuandoHayFormularios(): void
    {
        $vista = self::fuente('views/dashboard/index.php');

        assertTrue(
            str_contains($vista, "(int) \$stats['total'] === 0"),
            'el estado vacío de verdad se decide por las CUENTAS del alcance: `$listado[\'total\']` viene filtrado y vale cero en los dos vacíos'
        );
        assertTrue(
            str_contains($vista, '<?php if (empty($forms)): ?>'),
            'y con la barra de filtros puesta, que es lo que quita el filtro'
        );
        assertFalse(
            str_contains($vista, 'id="dashNoResults" class="d-none"'),
            'el `d-none` era del filtrado de cliente; ahora la decisión la toma el servidor'
        );
        assertTrue(
            str_contains($vista, 'Quitar el filtro'),
            'y hay un camino de vuelta cuando el filtro fue el que vació la pantalla'
        );
    }

    public function testElPaginadorEsDeEnlacesYConservaLosFiltros(): void
    {
        $html = self::paginador(40, 2, 4, 12, '?q=norte&estado=published');

        assertTrue(
            str_contains($html, 'href="/dashboard?page=3&amp;q=norte&amp;estado=published"')
            || str_contains($html, 'href="/dashboard?page=3&q=norte&estado=published"'),
            'el enlace «siguiente» se lleva los filtros puestos, no solo la página'
        );
        assertTrue(
            str_contains($html, 'href="/dashboard?page=1'),
            'y el de «anterior» también existe en la página 2'
        );
        assertFalse(
            str_contains($html, 'data-bs-target'),
            'el paginador navega; no es un diálogo ni un fetch'
        );
        assertTrue(str_contains($html, '40 formularios · Página 2 de 4'), 'dice cuántas hay y en cuál se está');
    }

    public function testElPaginadorNoSePintaConUnaSolaPagina(): void
    {
        $html = self::paginador(5, 1, 1, 12, '');

        assertFalse(
            str_contains($html, 'pagination'),
            'con una sola página un «1 / 1» deshabilitado solo ocupa sitio'
        );
    }

    public function testLosControlesDeshabilitadosNoSonEnlaces(): void
    {
        $primera = self::paginador(40, 1, 4, 12, '');
        assertFalse(
            str_contains($primera, 'href="/dashboard?page=0'),
            'no hay enlace a la página 0 en la primera'
        );
        assertTrue(str_contains($primera, 'aria-disabled="true"'), 'el control apagado lo dice igual');

        $ultima = self::paginador(40, 4, 4, 12, '');
        assertFalse(
            str_contains($ultima, 'href="/dashboard?page=5'),
            'ni a la página 5 en la última'
        );
    }

    public function testElJsMandaElFormularioYNoFiltraTarjetas(): void
    {
        $js = self::fuente('public/assets/js/dashboard.js');

        assertFalse(
            str_contains($js, "getElementById('dashGrid')"),
            'dashboard.js ya no recorre el grid: los filtros son del servidor'
        );
        foreach (['visibleCards', 'vf:refilter', 'dataset.status', '.app-dash-card-col'] as $resto) {
            assertFalse(
                str_contains($js, $resto),
                'quedó «' . $resto . '» en dashboard.js: era la otra mitad del filtrado de cliente'
            );
        }
        assertTrue(
            str_contains($js, 'requestSubmit()'),
            'el buscador y el orden envían el GET con `requestSubmit()`, que relanza el evento submit'
        );
        assertTrue(
            str_contains($js, "document.execCommand('copy');"),
            'y el copiador de enlace se quedó como estaba'
        );
    }

    /**
     * El select de orden mide lo mismo que el buscador, sin trucos de especificidad.
     *
     * El buscador vive en un `input-group-sm`; el select es suelto con
     * `form-select-sm`. Que midan lo mismo depende de que app.css **no** vuelva a
     * declarar el bloque global `.form-control, .form-select` que hubo: iba
     * después de Bootstrap y con la misma especificidad que los `-sm`, así que
     * los dejaba sin efecto y el select de orden salía ~11px más alto que el
     * buscador. El parche `.app-dash-sort` solo existía para ganarle por
     * especificidad; retirado el bloque, el `-sm` llega intacto y sobraba.
     */
    public function testElSelectDeOrdenMideLoMismoQueElBuscador(): void
    {
        $vista = self::fuente('views/dashboard/index.php');
        assertTrue(
            str_contains($vista, 'form-select-sm app-dash-sort'),
            'el select de orden lleva `form-select-sm` y su gancho `app-dash-sort`'
        );

        // Sin los comentarios de bloque: la prosa que explica el cambio nombra el
        // selector, y buscarlo en crudo lo daría por presente.
        $css = preg_replace('#/\*.*?\*/#s', '', self::fuente('public/assets/css/app.css'));
        $css = is_string($css) ? $css : '';

        assertFalse(
            str_contains($css, '.form-control, .form-select'),
            'app.css no debe volver a declarar el bloque global `.form-control, .form-select`: anularía los `-sm` y el select de orden saldría más alto que el buscador'
        );
        assertFalse(
            str_contains($css, '.form-select, .form-control'),
            'ni en el orden inverso'
        );

        $pos = strpos($css, '.form-select.app-dash-sort {');
        assertTrue($pos !== false, 'app.css conserva el gancho del select de orden');
        $bloque = substr($css, (int) $pos, (int) strpos($css, '}', (int) $pos) - (int) $pos);
        assertFalse(
            str_contains($bloque, 'padding'),
            'el gancho del select ya no reescribe el padding: el `-sm` de Bootstrap basta'
        );
    }
}