<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Controllers\AnalyticsController;
use App\Models\Answer;
use App\Models\Field;
use App\Models\Form;
use App\Models\FormParticipant;
use App\Models\Submission;
use App\Models\User;

/**
 * Fase 7 — Filtrar la analítica por un atributo del listado.
 *
 * Lo que se filtra es **lo que el dueño definió al subir el `.xlsx`**, nunca la
 * identidad de nadie: el catálogo no puede contener la cédula, el nombre ni el
 * correo porque el importador trata esas cabeceras como columnas fijas, así que
 * por esta puerta no se puede filtrar por la cédula de una persona ni aunque se
 * escriba en la URL a mano. Esa es la primera property que se ata aquí, porque es la
 * que no se puede volver a comprobar más adelante.
 *
 * Y el resto de la fase es una sola pregunta repetida: **¿el filtro entra en los
 * DOS caminos?** El detalle (`byFormFilteredPaginated()`) y los agregados de los
 * gráficos (`Answer::valueCountsInForm()` / `nonEmptyCountsInForm()`) son consultas
 * distintas con el mismo filtro de fechas, y el síntoma de que uno de los dos se
 * quede atrás no es un error: es un indicador que marca «38 respuestas» debajo de
 * una tabla con 12. Por eso el test central compara los tres números —el `total`
 * del envelope, el de los agregados y el de las filas de verdad— con el filtro
 * puesto.
 */
class RosterAnalyticsFilterTest
{
    private static bool $cleanupHookRegistered = false;
    private static ?int $ownerId = null;
    private static ?int $formId = null;
    private static ?int $campoId = null;
    private static ?string $sedeKey = null;

    public function setUp(): void
    {
        self::ensureCleanupHook();
        self::cleanupResiduals();

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Roster Analytics ' . $rand, 'rosterana-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$formId  = Form::create((int) self::$ownerId, 'Roster Analytics ' . $rand);
        self::$campoId = (int) Field::create((int) self::$formId, 'text', 'Comentario', position: 0);

        // Dos sedes y tres personas: dos al Norte y una al Sur, más una respuesta
        // suelta sin participante (la de siempre, la de un formulario abierto).
        self::$sedeKey = self::crearSede((int) self::$formId, 'Sede');
        self::persona('V-1', 'Ana',  'Norte', 2);
        self::persona('V-2', 'Beto', 'Norte', 2);
        self::persona('V-3', 'Caro', 'Sur',   1);
        Submission::create((int) self::$formId, '127.0.0.1', 'sin participante');

        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
    }

    public function tearDown(): void
    {
        Auth::forgetCache();
        unset($_GET, $_POST, $_FILES);
        Session::remove('user_id');
        Response::setRecordHalt(false);
        self::cleanupResiduals();
    }

    // =====================================================================
    // 1. El filtro entra en los dos caminos, y a la vez
    // =====================================================================

    public function testElFiltroCuentaLoMismoEnElDetalleYEnLosGraficos(): void
    {
        $atr = FormParticipant::filtroAtributo((int) self::$formId, (string) self::$sedeKey, 'Norte');
        assertTrue($atr !== null, 'el filtro se resuelve contra el catálogo');

        $detalle = Submission::byFormFilteredPaginated((int) self::$formId, '', '', 1, 15, $atr);

        assertSame(4, $detalle['total'], 'el envelope cuenta las 4 respuestas del grupo Norte');
        assertSame(4, count($detalle['items']), 'y trae las cuatro');

        // Y lo que dicen los gráficos: los no vacíos del campo.
        $noVacios = Answer::nonEmptyCountsInForm((int) self::$formId, [(int) self::$campoId], '', '', $atr);
        assertSame(4, (int) ($noVacios[self::$campoId] ?? 0), 'los agregados cuentan las MISMAS cuatro');

        // Sin filtro: las cinco del formulario (la respuesta suelta no tiene
        // respuestas, así que solo aparece en el total de submissions).
        $todos = Answer::nonEmptyCountsInForm((int) self::$formId, [(int) self::$campoId]);
        assertSame(5, (int) ($todos[self::$campoId] ?? 0), 'y sin filtro, todas');
    }

    public function testSinElFiltroSeQuedanFueraLasRespuestasQueNoSonDeParticipante(): void
    {
        // Una respuesta sin `participant_id` (la de siempre) no puede estar en el
        // grupo de nadie: con filtro desaparece, sin filtro está. Es la diferencia
        // entre «filtré por Norte» y «todo».
        $atr = FormParticipant::filtroAtributo((int) self::$formId, (string) self::$sedeKey, 'Norte');
        $conFiltro = Submission::byFormFilteredPaginated((int) self::$formId, '', '', 1, 15, $atr);
        $sinFiltro = Submission::byFormFilteredPaginated((int) self::$formId, '', '', 1, 15);

        assertSame(4, $conFiltro['total'], 'con filtro, las cuatro del grupo');
        assertSame(6, $sinFiltro['total'], 'sin filtro, las seis del formulario');

        foreach ($conFiltro['items'] as $item) {
            assertTrue(($item['participant_id'] ?? null) !== null, 'con filtro no sale ninguna sin participante');
        }
    }

    public function testLaDistribucionDeUnCampoCuentaElGrupoYNoElFormularioEntero(): void
    {
        $atr = FormParticipant::filtroAtributo((int) self::$formId, (string) self::$sedeKey, 'Norte');

        $distribucion = Answer::valueCountsInForm((int) self::$formId, [(int) self::$campoId], '', '', $atr);
        $cuenta = 0;
        foreach ($distribucion[(int) self::$campoId] ?? [] as $fila) {
            $cuenta += (int) ($fila['c'] ?? 0);
        }
        assertSame(4, $cuenta, 'las barras suman las respuestas del grupo');
    }

    public function testElGraficoDeDiasCuentaElGrupoYNoElFormularioEntero(): void
    {
        $atr = FormParticipant::filtroAtributo((int) self::$formId, (string) self::$sedeKey, 'Sur');

        $dias = Submission::dailyStats((int) self::$formId, '', '', $atr);
        assertSame(1, array_sum(array_map('intval', array_column($dias, 'c'))), 'el gráfico de días cuenta el grupo');

        $todos = array_sum(array_map('intval', array_column(Submission::dailyStats((int) self::$formId), 'c')));
        assertSame(6, $todos, 'sin filtro, las seis');
    }

    public function testLaExportacionSaleConElMismoFiltroQueLaPantalla(): void
    {
        $atr = FormParticipant::filtroAtributo((int) self::$formId, (string) self::$sedeKey, 'Sur');

        assertSame(
            1,
            Submission::countByFiltered((int) self::$formId, '', '', $atr),
            'el `total` del envelope del CSV es el del grupo'
        );

        $salidas = [];
        foreach (Submission::exportBatches((int) self::$formId, '', '', 0, $atr) as [$filas]) {
            foreach ($filas as $fila) {
                $salidas[] = (int) $fila['id'];
            }
        }
        assertSame(1, count($salidas), 'y el cuerpo sale con las mismas respuestas que la tabla');
    }

    // =====================================================================
    // 2. Solo se filtra por lo que el dueño definió
    // =====================================================================

    public function testLaIdentidadDeUnaPersonaNoSePuedeFiltrar(): void
    {
        assertNull(
            FormParticipant::filtroAtributo((int) self::$formId, 'cedula', 'V-1'),
            'la cédula no es un atributo: es la identidad, y eso no se filtra'
        );
        assertNull(
            FormParticipant::filtroAtributo((int) self::$formId, 'nombre', 'Ana'),
            'ni el nombre'
        );
        assertNull(
            FormParticipant::filtroAtributo((int) self::$formId, 'correo', 'ana@test.local'),
            'ni el correo'
        );
    }

    public function testUnaClaveQueNoEsDelCatalogoNoFiltraNada(): void
    {
        // Ni una clave inventada ni una de otro formulario: el catálogo es la lista
        // blanca. Descartar el filtro (y no «filtrar por algo que no existe») es lo
        // que evita que una URL copiada con el catálogo cambiado se quede en blanco
        // sin explicar por qué.
        assertNull(FormParticipant::filtroAtributo((int) self::$formId, 'inventada', 'Norte'));
        assertNull(
            FormParticipant::filtroAtributo((int) self::$formId, (string) self::$sedeKey, ''),
            'un valor vacío no es un filtro'
        );
        assertNull(
            FormParticipant::atributoDeCatalogo((int) self::$formId, 'inventada'),
            'ni hay fila de catálogo para una clave que no existe'
        );
    }

    public function testLosValoresDelDesplegableSonLosDeEseAtributo(): void
    {
        assertEquals(
            ['Norte', 'Sur'],
            FormParticipant::valuesOf((int) self::$formId, (string) self::$sedeKey),
            'los valores de «Sede», del más frecuente al menos'
        );
        assertEquals([], FormParticipant::valuesOf((int) self::$formId, 'inexistente'));
    }

    public function testElValorInexistenteDaUnaPantallaVaciaYNoUnError(): void
    {
        // Puede ser un valor que se borró al quitar una columna del catálogo: la
        // respuesta honesta es «no hay nada», no un 500.
        $atr = FormParticipant::filtroAtributo((int) self::$formId, (string) self::$sedeKey, 'Oeste');
        assertTrue($atr !== null, 'el filtro existe aunque el valor no');

        $detalle = Submission::byFormFilteredPaginated((int) self::$formId, '', '', 1, 15, $atr);
        assertSame(0, $detalle['total'], 'y la pantalla sale vacía');
    }

    // =====================================================================
    // 3. Lo que ve el dueño
    // =====================================================================

    public function testElDetallePintaUnaColumnaPorAtributoYNoLaIdentidad(): void
    {
        $atr = FormParticipant::filtroAtributo((int) self::$formId, (string) self::$sedeKey, 'Norte');
        $detalle = Submission::byFormFilteredPaginated((int) self::$formId, '', '', 1, 15, $atr);

        $html = View::partial('analytics/_rows', [
            'submissions'      => $detalle['items'],
            'answers'          => Submission::withAnswersByIds(array_column($detalle['items'], 'id')),
            'answerableFields' => [['id' => (int) self::$campoId, 'label' => 'Comentario', 'type' => 'text']],
            'offset'           => 1,
            'piiTypes'         => [],
            'atributos'        => FormParticipant::catalogFor((int) self::$formId),
            'attrsByPid'       => FormParticipant::atributosDe(array_map(
                static fn(array $s): int => (int) ($s['participant_id'] ?? 0),
                $detalle['items']
            )),
        ]);

        assertTrue(str_contains($html, 'Norte'), 'sale el valor del atributo');
        assertTrue(str_contains($html, 'data-label="Sede"'), 'con su cabecera en la celda (es la de móvil)');
        assertTrue(
            !str_contains($html, 'Ana') && !str_contains($html, 'V-1'),
            'pero no sale el nombre ni la cédula de quien respondió'
        );
    }

    public function testUnAtributoSinValorSePintaComoUnGuion(): void
    {
        // Un hueco vacío en un `table-hover` no se lee, y no se distingue «no lo
        // rellenó» de «se olvidó la columna».
        $atr = FormParticipant::filtroAtributo((int) self::$formId, (string) self::$sedeKey, 'Norte');
        $detalle = Submission::byFormFilteredPaginated((int) self::$formId, '', '', 1, 15, $atr);

        $html = View::partial('analytics/_rows', [
            'submissions'      => $detalle['items'],
            'answers'          => [],
            'answerableFields' => [],
            'offset'           => 1,
            'piiTypes'         => [],
            'atributos'        => [['key_name' => 'sede', 'label' => 'Sede']],
            'attrsByPid'       => [0 => []],
        ]);

        assertTrue(str_contains($html, 'data-label="Sede"'), 'la columna está');
        assertTrue(str_contains($html, '>—<'), 'y el valor que falta es un guion, no un hueco');
    }

    public function testSinFiltroLaTablaNoGanaColumnas(): void
    {
        $detalle = Submission::byFormFilteredPaginated((int) self::$formId, '', '', 1, 15);

        $html = View::partial('analytics/_rows', [
            'submissions'      => $detalle['items'],
            'answers'          => Submission::withAnswersByIds(array_column($detalle['items'], 'id')),
            'answerableFields' => [],
            'offset'           => 1,
            'piiTypes'         => [],
            'atributos'        => [],
            'attrsByPid'       => [],
        ]);

        assertTrue(
            !str_contains($html, 'data-label="Sede"'),
            'un formulario sin filtro se queda con las columnas que tenía'
        );
    }

    // =====================================================================
    // 4. La URL: la barra, el CSV, el paginador y la página N
    // =====================================================================

    public function testLaPantallaTraeLosDosDesplegablesYElCsvYCargaInicial(): void
    {
        $_GET['atr_key'] = (string) self::$sedeKey;
        $_GET['atr_value'] = 'Norte';

        $html = self::renderAnalytics();

        assertTrue(
            str_contains($html, 'name="atr_key"') && str_contains($html, 'name="atr_value"'),
            'la barra de filtros trae los dos desplegables'
        );
        assertTrue(
            str_contains($html, 'data-atr-key="' . self::$sedeKey . '"') && str_contains($html, 'data-atr-value="Norte"'),
            'y el tbody lleva el filtro para el fetcher del paginador'
        );
        assertTrue(
            str_contains($html, 'export.csv?') && str_contains($html, 'atr_key=' . self::$sedeKey) && str_contains($html, 'atr_value=Norte'),
            'el botón del CSV se lleva el filtro, o descarga lo que la pantalla esconde'
        );
        assertTrue(
            substr_count($html, '<option value="Norte" selected>') === 1,
            'el valor elegido sale marcado en su desplegable'
        );
    }

    public function testElegirElAtributoSinValorNoBorraLaEleccion(): void
    {
        // El segundo paso del filtro: se elige «Sede», se guarda, y el desplegable
        // de valores tiene que salir YA. Con la clave sola no hay filtro (filtrar
        // por nada no es filtrar), así que si la vista leyera el filtro resuelto se
        // volvería a «Todo el listado» y no habría forma de elegir el valor.
        $_GET['atr_key'] = (string) self::$sedeKey;

        $html = self::renderAnalytics();

        assertTrue(
            str_contains($html, 'name="atr_value"'),
            'con la clave elegida el desplegable de valores se pinta'
        );
        assertTrue(
            str_contains($html, '<option value="' . self::$sedeKey . '" selected>Sede</option>'),
            'y el atributo sigue marcado'
        );
        assertTrue(
            str_contains($html, '<option value="">Todos los valores</option>'),
            'con «Todos los valores» como primera opción'
        );
    }

    public function testUnFormularioSinListadoNoPintaFiltro(): void
    {
        // El filtro es del listado: un formulario normal no tiene catálogo y no gana
        // un desplegable que no puede filtrar nada.
        $html = self::renderAnalyticsDeOtroFormulario();

        assertTrue(!str_contains($html, 'name="atr_key"'), 'sin catálogo no hay filtro');
    }

    public function testLaPaginaNDelDetalleRespetaElFiltro(): void
    {
        self::authAs();
        Response::setRecordHalt(true);

        ob_start();
        (new AnalyticsController())->rows((int) self::$formId);
        $json = json_decode((string) ob_get_clean(), true);
        assertSame(6, (int) ($json['total'] ?? 0), 'sin filtro, las seis respuestas');

        $_GET['atr_key'] = (string) self::$sedeKey;
        $_GET['atr_value'] = 'Norte';
        ob_start();
        (new AnalyticsController())->rows((int) self::$formId);
        $json = json_decode((string) ob_get_clean(), true);

        assertTrue(($json['ok'] ?? false) === true, 'rows() responde JSON');
        assertSame(4, (int) ($json['total'] ?? 0), 'con filtro, las del grupo');
        assertTrue(
            str_contains((string) ($json['html'] ?? ''), 'data-label="Sede"'),
            'y el html de las filas trae la columna del atributo, como la primera página'
        );
    }

    public function testElJsDelPaginadorMandaElFiltro(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../public/assets/js/analytics.js');

        assertTrue(
            str_contains($js, "getAttribute('data-atr-key')") && str_contains($js, "getAttribute('data-atr-value')"),
            'el fetcher lee el filtro del tbody'
        );
        assertTrue(
            str_contains($js, "'&atr_key=' + encodeURIComponent(atrKey)") &&
            str_contains($js, "'&atr_value=' + encodeURIComponent(atrValue)"),
            'y lo manda en la URL de cada página'
        );
    }

    // =====================================================================
    // Utilidades
    // =====================================================================

    /**
     * Una persona en la lista con su sede, y una respuesta por cada una.
     *
     * El atributo se crea por `ensureAttribute()` —la misma puerta que el
     * importador— y no a mano, porque el `key_name` es un slug que sale de la
     * cabecera: si el test escribiera la clave a pelo, estaría probando un
     * catálogo que la aplicación no puede llegar a tener.
     */
    private static function persona(string $cedula, string $nombre, string $sede, int $respuestas): void
    {
        $formId = (int) self::$formId;
        $alta = FormParticipant::create(
            $formId,
            'cedula',
            ['cedula' => $cedula, 'nombre' => $nombre],
            [(string) self::$sedeKey => $sede]
        );
        assertTrue(($alta['ok'] ?? false) === true, 'alta de ' . $cedula . ': ' . (string) ($alta['motivo'] ?? ''));

        for ($i = 0; $i < $respuestas; $i++) {
            $submissionId = Submission::create($formId, '127.0.0.1', 'test', null, (int) $alta['id']);
            Answer::create($submissionId, (int) self::$campoId, 'Nota ' . ($i + 1));
        }
    }

    /** Crea la columna del catálogo por su cabecera y devuelve la `key_name`. */
    private static function crearSede(int $formId, string $cabecera): string
    {
        $key = FormParticipant::ensureAttribute($formId, $cabecera);
        assertTrue($key !== null, 'el catálogo se crea desde la cabecera del dueño');
        return (string) $key;
    }

    private static function renderAnalytics(): string
    {
        self::authAs();
        Response::setRecordHalt(true);
        ob_start();
        (new AnalyticsController())->show((int) self::$formId);
        return (string) ob_get_clean();
    }

    /** Un formulario del mismo dueño sin nadie en la lista: no tiene catálogo. */
    private static function renderAnalyticsDeOtroFormulario(): string
    {
        $formId = Form::create((int) self::$ownerId, 'Sin listado ' . bin2hex(random_bytes(3)));
        Field::create($formId, 'text', 'Comentario', position: 0);

        self::authAs();
        Response::setRecordHalt(true);
        ob_start();
        (new AnalyticsController())->show($formId);
        return (string) ob_get_clean();
    }

    private static function authAs(): void
    {
        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
    }

    private static function ensureCleanupHook(): void
    {
        if (self::$cleanupHookRegistered) {
            return;
        }
        self::$cleanupHookRegistered = true;
        register_shutdown_function(static function (): void {
            self::cleanupResiduals();
        });
    }

    private static function cleanupResiduals(): void
    {
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'rosterana-%'");
        self::$ownerId = null;
        self::$formId = null;
        self::$campoId = null;
        self::$sedeKey = null;
    }
}