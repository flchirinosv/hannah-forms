<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\DashboardController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Response;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;

/**
 * M2 (auditoría 2026-09-25) — El listado de formularios pagina en el servidor.
 *
 * Lo que se comprueba, y por qué cada cosa es una trampa:
 *
 *  - `Form::page()` devuelve UNA página y un `total`, y las dos cosas tienen que
 *    cuadrar entre sí. Un `total` que cuenta el alcance y una página que pinta
 *    otra cosa es un paginador que miente.
 *  - La página de la URL por encima del final se recorta a la última, no pinta
 *    un grid vacío: con un filtro puesto es el camino normal, porque filtrar
 *    deja menos filas que las que había.
 *  - Los filtros (`q`, `estado`) van en la consulta. Antes eran de cliente sobre
 *    el listado entero, así que con el `LIMIT` en SQL un buscador que solo
 *    mirase la página habría pasado estos tests si los filtros no se probaran
 *    con más filas que una página.
 *  - `counts()` son datos del alcance ENTERO y sin filtros: derivarlos de la
 *    página hacía que en la segunda el contador de formularios dijera 12 con
 *    doce tarjetas y solo las de la primera. Por eso es una consulta aparte.
 *  - El orden es estable: `updated_at` se empata entre formularios creados en el
 *    mismo segundo, y sin un desempate por `id` un formulario puede salir en dos
 *    páginas o en ninguna.
 *  - El alcance decide: `scopeAll = false` solo ve los suyos.
 */
class FormListPaginationTest
{
    private const POR_PAGINA = 12;

    private static bool $cleanupHookRegistered = false;
    private static ?int $userId = null;
    private static ?int $otroUserId = null;

    public function setUp(): void
    {
        self::ensureCleanupHook();
        self::cleanupResiduals();
        self::$userId = User::create('Pagin User', 'pag-' . bin2hex(random_bytes(4)) . '@test.local', 'password', 2);
        self::$otroUserId = User::create('Pagin Otro', 'pag-otro-' . bin2hex(random_bytes(4)) . '@test.local', 'password', 2);
    }

    public function tearDown(): void
    {
        self::cleanupResiduals();
    }

    /**
     * Un hook de cierre, no uno por test: los usuarios son de `@test.local` y
     * borrarlos en cada `setUp`/`tearDown` es lo que deja la base limpia, pero
     * registrar el hook una vez por clase evita apilar dieciséis. El guard es
     * el que impide que se registre en cada test.
     */
    private static function ensureCleanupHook(): void
    {
        if (self::$cleanupHookRegistered) {
            return;
        }
        self::$cleanupHookRegistered = true;
        register_shutdown_function(static function (): void {
            try {
                self::cleanupResiduals();
            } catch (\Throwable $e) {
                // Un cierre que revienta no puede tapar el resultado del test.
            }
        });
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        // Los formularios caen en cascada con su usuario (`fk_forms_user`).
        $db->execute("DELETE FROM users WHERE email LIKE '%@test.local'");
        // Las pruebas que pasan por el controlador dejan la fila de las cuentas
        // en la cache; sin esta limpieza se acumulan una por usuario y por
        // corrida, y `CacheDashboardTest` mide lo que queda.
        $db->execute("DELETE FROM cache WHERE cache_key LIKE 'dash:stats:%'");
        self::$userId = null;
        self::$otroUserId = null;
    }

    /**
     * `n` formularios del usuario de este test, con `updated_at` escalonado para
     * que el orden por fecha sea inequívoco.
     *
     * @return int[] los ids, del más antiguo al más reciente
     */
    private static function formularios(int $n, string $prefijo = 'Form'): array
    {
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $id = Form::create((int) self::$userId, sprintf('%s %02d', $prefijo, $i), 'descripción ' . $i);
            $db = Database::getInstance(Config::db());
            $db->execute(
                "UPDATE forms SET updated_at = DATE_SUB(NOW(), INTERVAL :min MINUTE) WHERE id = :id",
                ['min' => $n - $i, 'id' => $id]
            );
            $ids[] = $id;
        }

        return $ids;
    }

    public function testUnaPaginaTraeDoceYElTotalEsDelAlcance(): void
    {
        self::formularios(20);

        $p1 = Form::page((int) self::$userId, false, [], 1);
        assertSame(self::POR_PAGINA, count($p1['items']), 'la primera página trae una pantalla completa');
        assertSame(20, (int) $p1['total'], 'el total es del alcance entero, no de la página');
        assertSame(2, (int) $p1['pages'], '20 formularios son dos páginas de doce');
        assertSame(1, (int) $p1['page']);

        $p2 = Form::page((int) self::$userId, false, [], 2);
        assertSame(8, count($p2['items']), 'la segunda se queda con lo que sobra');
        assertSame(2, (int) $p2['page']);

        $ids1 = array_column($p1['items'], 'id');
        $ids2 = array_column($p2['items'], 'id');
        assertSame(
            0,
            count(array_intersect($ids1, $ids2)),
            'ningún formulario puede estar en dos páginas a la vez'
        );
        assertSame(20, count(array_unique(array_merge($ids1, $ids2))), 'entre las dos páginas están los veinte');
    }

    public function testLaPaginaDeLaUrlSeRecortaALaUltima(): void
    {
        self::formularios(14);

        $lejos = Form::page((int) self::$userId, false, [], 99);
        assertSame(2, (int) $lejos['page'], 'la página 99 de 2 se recorta a la 2, no a un grid vacío');
        assertSame(2, count($lejos['items']));

        $cero = Form::page((int) self::$userId, false, [], 0);
        assertSame(1, (int) $cero['page'], 'la página 0 es la primera');

        $negativa = Form::page((int) self::$userId, false, [], -5);
        assertSame(1, (int) $negativa['page'], 'una página negativa es la primera');
    }

    public function testLaPaginaVaciaNoRompeYNoHayFilas(): void
    {
        $vacia = Form::page((int) self::$userId, false, [], 1);
        assertSame([], $vacia['items'], 'sin formularios la página viene vacía');
        assertSame(0, (int) $vacia['total']);
        assertSame(1, (int) $vacia['pages'], 'cero formularios es una página de cero, no cero páginas');
    }

    public function testElFiltroDeTextoBuscaMasAllaDeLaPrimeraPagina(): void
    {
        $ids = self::formularios(20, 'Sede');
        $db = Database::getInstance(Config::db());
        // El más antiguo es el último de la lista por fecha: sin el `LIMIT` en
        // SQL el buscador lo encuentra; con él, solo si la búsqueda va en la
        // consulta y no se queda en la página.
        $db->execute('UPDATE forms SET title = :t WHERE id = :id', [
            't' => 'Sede Norte citotoxidad',
            'id' => (int) $ids[0],
        ]);

        $encontrado = Form::page((int) self::$userId, false, ['q' => 'citotoxidad'], 1);
        assertSame(1, (int) $encontrado['total'], 'el buscador encuentra el que está fuera de la primera página');
        assertSame((int) $ids[0], (int) $encontrado['items'][0]['id']);

        $porDescripcion = Form::page((int) self::$userId, false, ['q' => 'descripción 20'], 1);
        assertSame(
            1,
            (int) $porDescripcion['total'],
            'busca también en la descripción, como el buscador de antes'
        );
    }

    public function testElPorcentajeEsTextoYNohaceDeComodin(): void
    {
        self::formularios(3);
        $db = Database::getInstance(Config::db());
        $primero = Form::page((int) self::$userId, false, [], 1)['items'][0]['id'];
        $db->execute("UPDATE forms SET title = 'Descuento 100%' WHERE id = :id", ['id' => (int) $primero]);

        $conPorcentaje = Form::page((int) self::$userId, false, ['q' => '100%'], 1);
        assertSame(1, (int) $conPorcentaje['total'], 'buscar «100%» encuentra el que tiene el porcentaje');

        $conComodin = Form::page((int) self::$userId, false, ['q' => '%'], 1);
        assertSame(
            1,
            (int) $conComodin['total'],
            'un «%» a secas busca un porcentaje de verdad: solo trae el que lo tiene, no los tres'
        );

        $guionBajo = Form::page((int) self::$userId, false, ['q' => '_'], 1);
        assertSame(0, (int) $guionBajo['total'], 'y un «_» tampoco es un comodín de un carácter');
    }

    public function testElFiltroDeEstadoYElDeTextoSeCombinan(): void
    {
        self::formularios(5, 'Mixto');
        $porTitulo = [];
        foreach (Form::page((int) self::$userId, false, ['orden' => 'alpha'], 1)['items'] as $fila) {
            $porTitulo[(string) $fila['title']] = (int) $fila['id'];
        }
        $publicado = $porTitulo['Mixto 02'];
        $cerrado = $porTitulo['Mixto 03'];
        Form::setStatus($publicado, 'published');
        Form::setStatus($cerrado, 'closed');

        $publicados = Form::page((int) self::$userId, false, ['estado' => 'published'], 1);
        assertSame(1, (int) $publicados['total']);
        assertSame($publicado, (int) $publicados['items'][0]['id']);

        $cerradosYTexto = Form::page((int) self::$userId, false, ['estado' => 'closed', 'q' => 'Mixto 03'], 1);
        assertSame(1, (int) $cerradosYTexto['total'], 'los dos filtros se aplican a la vez');
        assertSame($cerrado, (int) $cerradosYTexto['items'][0]['id']);

        $nada = Form::page((int) self::$userId, false, ['estado' => 'closed', 'q' => 'Mixto 02'], 1);
        assertSame(0, (int) $nada['total'], 'un estado y un texto que no casan dan cero, no el estado entero');

        $inventado = Form::page((int) self::$userId, false, ['estado' => 'inventado'], 1);
        assertSame(5, (int) $inventado['total'], 'un estado que no existe se ignora y no filtra a la nada');
    }

    public function testLosCuatroOrdenesPonenLoQuePidieron(): void
    {
        $ids = self::formularios(4, 'Orden');
        $db = Database::getInstance(Config::db());

        // Respuestas: `Orden 01` es el único con respuestas.
        for ($k = 0; $k < 3; $k++) {
            Submission::create((int) $ids[0], '127.0.0.1');
        }
        $db->execute('UPDATE forms SET title = :t WHERE id = :id', ['t' => 'Zeta', 'id' => (int) $ids[3]]);

        $porFecha = array_column(Form::page((int) self::$userId, false, ['orden' => 'updated'], 1)['items'], 'id');
        assertSame(
            [(int) $ids[3], (int) $ids[2], (int) $ids[1], (int) $ids[0]],
            array_map('intval', $porFecha),
            '«más recientes» pone el más reciente primero'
        );

        $porAlfa = array_column(Form::page((int) self::$userId, false, ['orden' => 'alpha'], 1)['items'], 'id');
        assertSame(
            [(int) $ids[0], (int) $ids[1], (int) $ids[2], (int) $ids[3]],
            array_map('intval', $porAlfa),
            '«alfabético» pone Zeta el último'
        );

        $porSubs = Form::page((int) self::$userId, false, ['orden' => 'subs'], 1)['items'];
        assertSame((int) $ids[0], (int) $porSubs[0]['id'], '«más respuestas» pone primero el que más tiene');
        assertSame('3', (string) $porSubs[0]['submissions_count'], 'y su tarjeta lo dice');

        $inventado = Form::page((int) self::$userId, false, ['orden' => 'inventado'], 1);
        assertSame(4, (int) $inventado['total'], 'un orden que no existe cae en el predeterminado');
        assertSame(
            [(int) $ids[3], (int) $ids[2], (int) $ids[1], (int) $ids[0]],
            array_map('intval', array_column($inventado['items'], 'id')),
            'que es «más recientes», no un SQL roto'
        );

        $porActividad = Form::page((int) self::$userId, false, ['orden' => 'activity'], 1);
        assertSame(4, (int) $porActividad['total'], '«última actividad» con un formulario sin respuestas no se rompe');
    }

    public function testElOrdenNoReparteUnFormularioEnDosPaginasAunqueSeEmpaten(): void
    {
        // Todos con la MISMA fecha: es el caso que hace indefinida la frontera
        // entre páginas si el `ORDER BY` no desempata por `id`.
        $ids = self::formularios(15, 'Empate');
        $db = Database::getInstance(Config::db());
        $db->execute('UPDATE forms SET updated_at = NOW()');
        foreach ($ids as $id) {
            $db->execute('UPDATE forms SET title = :t WHERE id = :id', ['t' => 'Igual', 'id' => $id]);
        }

        $p1 = array_column(Form::page((int) self::$userId, false, ['orden' => 'updated'], 1)['items'], 'id');
        $p2 = array_column(Form::page((int) self::$userId, false, ['orden' => 'updated'], 2)['items'], 'id');

        assertSame(0, count(array_intersect($p1, $p2)), 'con la fecha empatada, las páginas no se solapan');
        assertSame(15, count(array_unique(array_merge($p1, $p2))), 'y entre las dos están los quince');

        $a1 = array_column(Form::page((int) self::$userId, false, ['orden' => 'alpha'], 1)['items'], 'id');
        $a2 = array_column(Form::page((int) self::$userId, false, ['orden' => 'alpha'], 2)['items'], 'id');
        assertSame(0, count(array_intersect($a1, $a2)), 'con el título empatado tampoco se solapan');
    }

    public function testLasCuentasSonDelAlcanceEnteroYNoDeLaPagina(): void
    {
        $ids = self::formularios(20);
        $db = Database::getInstance(Config::db());
        Form::setStatus((int) $ids[0], 'published');
        Form::setStatus((int) $ids[1], 'in_review');
        Submission::create((int) $ids[0], '127.0.0.1');
        Submission::create((int) $ids[0], '127.0.0.1');

        $cuentas = Form::counts((int) self::$userId, false);
        assertSame(20, (int) $cuentas['total'], 'las veinte, no las doce de la página');
        assertSame(1, (int) $cuentas['published']);
        assertSame(1, (int) $cuentas['in_review']);
        assertSame(18, (int) $cuentas['draft']);
        assertSame(2, (int) $cuentas['submissions'], 'las respuestas del alcance entero');

        // Con filtro las cuentas NO cambian: son datos de la instalación.
        $filtrado = Form::page((int) self::$userId, false, ['estado' => 'published'], 1);
        assertSame(1, (int) $filtrado['total'], 'el listado filtrado trae uno');
        assertSame(20, (int) Form::counts((int) self::$userId, false)['total'], 'y las tarjetas siguen diciendo veinte');
    }

    public function testLasCuentasDeUnAlcanceVacioSonCerosYNoNull(): void
    {
        $cuentas = Form::counts((int) self::$userId, false);
        foreach (['total', 'draft', 'in_review', 'published', 'closed', 'submissions'] as $clave) {
            assertArrayHasKey($clave, $cuentas, 'la tarjeta ' . $clave . ' no puede faltar: la vista la lee siempre');
            assertSame(0, (int) $cuentas[$clave], 'un usuario sin formularios tiene ' . $clave . ' a cero');
        }
    }

    public function testElAlcanceDecideLoQueSeVe(): void
    {
        self::formularios(2, 'Mio');
        Form::create((int) self::$otroUserId, 'Ajeno', 'de otra persona');

        $mio = Form::page((int) self::$userId, false, [], 1);
        assertSame(2, (int) $mio['total'], 'sin `manage_all` solo se ven los propios');

        $todo = Form::page(0, true, [], 1);
        $ajeno = false;
        foreach ($todo['items'] as $fila) {
            if ((string) $fila['title'] === 'Ajeno') {
                $ajeno = true;
            }
        }
        assertTrue($ajeno, 'con `manage_all` el ajeno sale');
    }

    public function testElPorDePaginaSePisaYSeACota(): void
    {
        self::formularios(20);

        assertSame(5, count(Form::page((int) self::$userId, false, [], 1, 5)['items']), 'un tamaño propio manda');
        assertSame(1, count(Form::page((int) self::$userId, false, [], 1, 0)['items']), 'un tamaño de cero se sube a uno');
        assertSame(
            20,
            count(Form::page((int) self::$userId, false, [], 1, 9999)['items']),
            'un tamaño enorme se topa y no se come la tabla entera'
        );
    }

    /**
     * El mismo recorrido por el controlador de verdad, porque el modelo puede
     * estar bien y el GET leer mal la URL: aquí se mira el HTML que sale.
     */
    public function testLaUrlMandaYLasTarjetasNoCambianConElFiltro(): void
    {
        self::authAs();
        $ids = self::formularios(14, 'Pantalla');
        $porTitulo = [];
        foreach (Form::page((int) self::$userId, false, ['orden' => 'alpha'], 1)['items'] as $fila) {
            $porTitulo[(string) $fila['title']] = (int) $fila['id'];
        }
        Form::setStatus($porTitulo['Pantalla 03'], 'published');

        $html = self::dashboard(['estado' => 'published']);
        assertTrue(str_contains($html, 'Pantalla 03'), 'el publicado sale con el filtro puesto');
        assertFalse(str_contains($html, 'Pantalla 04'), 'y los demás no, que el filtro es del servidor');
        assertTrue(
            str_contains($html, 'Sin resultados') === false,
            'con un formulario que casa no se pinta el estado vacío'
        );

        $html = self::dashboard(['estado' => 'closed']);
        assertTrue(
            str_contains($html, 'Ningún formulario coincide'),
            'un filtro que no casa con nada lo dice, en vez de un grid mudo'
        );

        // Las cuentas son del alcance, así que con el filtro puesto el «14» sigue
        // en la tarjeta de arriba: si se calcularan de la página, diría cero.
        assertTrue(
            (bool) preg_match('#app-stat-value">14<#', $html),
            'las tarjetas cuentan el alcance entero aunque el filtro no traiga nada'
        );

        $html = self::dashboard(['page' => '99']);
        assertTrue(
            str_contains($html, 'Página 2 de 2'),
            'una página de la URL por encima del final aterriza en la última, con su rótulo'
        );
        assertTrue(
            !str_contains($html, 'Ningún formulario coincide'),
            'y no se confunde con «no hay nada»'
        );

        $idsFinales = self::formsVistos($html);
        assertSame(
            count($idsFinales),
            count(array_unique($idsFinales)),
            'ninguna tarjeta sale duplicada en la página'
        );
        assertTrue(count($idsFinales) >= 2, 'la última página trae lo que sobra');
        assertTrue(
            in_array($idsFinales[0], array_map('intval', $ids), true),
            'y son formularios de este test'
        );
    }

    private static function authAs(): void
    {
        Auth::assumeLoggedIn((int) self::$userId);
        Auth::forgetCache();
    }

    /** GET a /dashboard con el query dado, y el HTML que devuelve. */
    private static function dashboard(array $query): string
    {
        $antes = $_GET;
        $_GET = array_map('strval', $query);
        Response::setRecordHalt(true);
        ob_start();
        try {
            (new DashboardController())->index();
        } finally {
            $html = (string) ob_get_clean();
            $_GET = $antes;
            Response::setRecordHalt(false);
            Auth::forgetCache();
        }

        return $html;
    }

    /** Los ids de formulario que aparecen en el HTML, en orden. */
    private static function formsVistos(string $html): array
    {
        preg_match_all('#/forms/(\d+)#', $html, $m);
        return array_values(array_unique(array_map('intval', $m[1] ?? [])));
    }
}