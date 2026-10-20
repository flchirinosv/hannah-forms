<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AnalyticsController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Models\Answer;
use App\Models\Field;
use App\Models\Form;
use App\Models\FormParticipant;
use App\Models\Submission;
use App\Models\User;
use App\Services\FormSettings;

/**
 * W4 — La cabecera, los filtros con etiqueta, el índice de preguntas, los
 * estados vacíos y la tasa de respuesta del listado.
 *
 * Es la fase menos vistosa de la auditoría y por eso la que más fácil se queda a
 * medias sin que nada falle: un `<label>` que falta solo se nota con lector de
 * pantalla, un estado vacío que no existe es una pantalla de ceros, y una tarjeta
 * de KPI que aparece en un formulario que no toca es un número que nadie pidió.
 *
 * Toca la BD real (crea formularios temporales) y los limpia.
 */
class AnalyticsLayoutTest
{
    private static bool $cleanupHookRegistered = false;
    private static ?int $ownerId = null;

    public function setUp(): void
    {
        self::ensureCleanupHook();
        self::cleanupResiduals();

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Analytics Layout ' . $rand, 'anallay-' . $rand . '@test.local', 'clave-larga-123', 2);

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

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private static function formulario(array $settings = []): int
    {
        $formId = Form::create((int) self::$ownerId, 'Layout ' . bin2hex(random_bytes(3)));
        if ($settings !== []) {
            Form::saveSettings($formId, $settings);
        }
        return $formId;
    }

    private static function campoRespuestas(): int
    {
        $formId = self::formulario();
        $campo = (int) Field::create($formId, 'text', 'Comentario', position: 0);
        for ($i = 0; $i < 2; $i++) {
            $sid = Submission::create($formId);
            Answer::create($sid, $campo, 'Respuesta ' . $i);
        }
        return $formId;
    }

    private static function render(int $formId, array $query = []): string
    {
        $_GET = $query;
        Auth::forgetCache();
        Response::setRecordHalt(true);
        ob_start();
        (new AnalyticsController())->show($formId);
        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------
    // W4.1 — Cabecera
    // ---------------------------------------------------------------

    /**
     * La vista usa la cabecera común (`.app-page-head`) y la barra de filtros
     * vive en su PROPIA fila, no dentro de `.app-head-actions`: en la misma fila,
     * con poco ancho, la barra empujaba el botón de exportar.
     */
    public function testLaCabeceraEsLaComunYLosFiltrosTienenSuFila(): void
    {
        $fuente = (string) file_get_contents(dirname(__DIR__, 2) . '/views/analytics/show.php');

        assertTrue(str_contains($fuente, 'app-page-head'), 'la cabecera usa la clase común');
        // La barra de filtros NO va dentro de `.app-head-actions`.
        $filtrosFuera = strpos($fuente, 'analytics-filters');
        $headActions = strpos($fuente, 'app-head-actions');
        assertTrue($filtrosFuera !== false && $headActions !== false, 'están las dos piezas');
        assertTrue($filtrosFuera > $headActions, 'los filtros vienen DESPUÉS de la fila de acciones');
    }

    // ---------------------------------------------------------------
    // W4.2 — Filtros
    // ---------------------------------------------------------------

    /**
     * Los dos campos de fecha llevan su `<label>` (oculto): sin él, un lector de
     * pantalla anuncia «campo de fecha» dos veces sin distinguir desde de hasta.
     * Y el `for` apunta al `id` real del input.
     */
    public function testLosCamposDeFechaLlevanSuEtiquetaOculta(): void
    {
        $html = self::render(self::campoRespuestas());

        assertTrue(str_contains($html, 'for="analyticsFrom"'), 'el label de «desde» apunta al campo');
        assertTrue(str_contains($html, 'id="analyticsFrom"'), 'y el campo tiene ese id');
        assertTrue(str_contains($html, 'for="analyticsTo"'), 'el label de «hasta» apunta a su campo');
        assertTrue(str_contains($html, 'id="analyticsTo"'), 'y el campo tiene ese id');
        assertTrue(substr_count($html, 'visually-hidden') >= 2, 'los dos labels van ocultos');
    }

    /**
     * El selector de atributo (Fase 7) conserva su `aria-label` con la cabecera
     * nueva: es un desplegable sin `<label>` visible, así que su nombre accesible
     * es lo único que lo describe. Solo aparece en un formulario de listado, que
     * es donde hay catálogo.
     */
    public function testElSelectorDeAtributoMantieneSuNombreAccesible(): void
    {
        $roster = self::formulario(['access' => ['mode' => FormSettings::ACCESS_ROSTER, 'identifier' => 'cedula']]);
        FormParticipant::create($roster, 'cedula', ['cedula' => 'V-1'], []);
        FormParticipant::ensureAttribute($roster, 'Sede');

        $html = self::render($roster);

        assertTrue(str_contains($html, 'aria-label="Atributo del listado"'), 'el selector de atributo sigue etiquetado');
    }

    // ---------------------------------------------------------------
    // W4.3 — Índice de preguntas
    // ---------------------------------------------------------------

    /**
     * Con más de tres preguntas la vista ofrece un índice: anclas reales en
     * escritorio (funcionan sin JS) y un desplegable en móvil. Cada tarjeta es un
     * destino con su `id`, o las anclas no llevan a ningún sitio.
     */
    public function testElIndiceApuntaAlasTarjetasYSoloConVariasPreguntas(): void
    {
        // Un formulario con una sola pregunta: no hay índice (no tiene sentido).
        $uno = self::formulario();
        Field::create($uno, 'text', 'Única', position: 0);
        $htmlUno = self::render($uno);
        assertFalse(str_contains($htmlUno, 'analyticsIndexSelect'), 'con una pregunta no hay desplegable');

        // Cuatro preguntas: aparece el índice y sus destinos existen.
        $formId = self::formulario();
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = (int) Field::create($formId, 'text', 'Pregunta ' . $i, position: $i);
            $sid = Submission::create($formId);
            Answer::create($sid, $ids[$i], 'algo');
        }
        $html = self::render($formId);

        assertTrue(str_contains($html, 'analyticsIndexSelect'), 'con varias preguntas hay desplegable móvil');
        foreach ($ids as $id) {
            assertTrue(str_contains($html, 'id="campo-' . $id . '"'), "la tarjeta de $id es un destino");
            assertTrue(str_contains($html, 'href="#campo-' . $id . '"'), "el índice enlaza a $id");
        }
    }

    // ---------------------------------------------------------------
    // G6 — El resumen de una tarjeta numérica tiene que verse
    // ---------------------------------------------------------------

    /**
     * La vista «Resumen» de una tarjeta numérica oculta el lienzo y deja a la
     * vista las mini-stats y, en NPS, las insignias Detractores/Pasivos/
     * Promotores. El resaltado sutil de esas insignias usa la pareja correcta de
     * Bootstrap (`bg-<color>-subtle` + `text-<color>-emphasis`).
     *
     * La trampa que se comió el color: `text-bg-danger-subtle` (y sus hermanas)
     * NO existe en el Bootstrap vendorizado, así que la clase no pinta nada y el
     * `.badge` se queda con su `color: #fff` encima de un fondo transparente: el
     * texto se leía blanco sobre blanco y la tarjeta en «Resumen» parecía vacía.
     * Un `grep` del CSS de Bootstrap es lo único que lo delata, así que el test
     * lo comprueba contra el propio fichero.
     */
    public function testElResumenNumericoSeVeYLasInsigniasNpsTienenColor(): void
    {
        $formId = self::formulario();
        $nps = (int) Field::create($formId, 'nps', 'Recomendaría', position: 0);
        $num = (int) Field::create($formId, 'number', 'Asistentes', position: 1);
        foreach ([['nps' => '10', 'num' => '10'], ['nps' => '3', 'num' => '30'], ['nps' => '8', 'num' => '20']] as $vals) {
            $sid = Submission::create($formId);
            Answer::create($sid, $nps, $vals['nps']);
            Answer::create($sid, $num, $vals['num']);
        }

        $html = self::render($formId);

        // El resumen existe: las mini-stats y las tres insignias del NPS.
        assertTrue(str_contains($html, 'app-mini-stat'), 'el resumen pinta las mini-stats');
        foreach (['Promedio', 'Mínimo', 'Máximo'] as $rotulo) {
            assertTrue(str_contains($html, $rotulo), "el resumen dice «{$rotulo}»");
        }
        foreach (['Detractores (0-6)', 'Pasivos (7-8)', 'Promotores (9-10)'] as $rotulo) {
            assertTrue(str_contains($html, $rotulo), "la insignia «{$rotulo}» está en el resumen");
        }

        // Las clases de resaltado sutil tienen que existir de verdad en el CSS.
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/bootstrap.min.css');
        foreach (['bg-danger-subtle', 'bg-secondary-subtle', 'bg-success-subtle', 'text-danger-emphasis', 'text-secondary-emphasis', 'text-success-emphasis'] as $clase) {
            assertTrue(str_contains($bootstrap, '.' . $clase), "Bootstrap define .{$clase}");
        }
        assertFalse(str_contains($html, 'text-bg-danger-subtle'), 'no se usa la clase inexistente text-bg-danger-subtle');
        assertFalse(str_contains($html, 'text-bg-secondary-subtle'), 'no se usa la clase inexistente text-bg-secondary-subtle');
        assertFalse(str_contains($html, 'text-bg-success-subtle'), 'no se usa la clase inexistente text-bg-success-subtle');
    }

    // ---------------------------------------------------------------
    // W4.4 — Estados vacíos
    // ---------------------------------------------------------------

    /**
     * Un formulario sin respuestas enseña un estado con una acción (compartir),
     * no un panel de ceros. El «Limpiar» del filtro no cambia eso cuando no hay
     * filtro puesto.
     */
    public function testUnFormularioSinRespuestasEnseniaElEstadoVacioConAccion(): void
    {
        $formId = self::formulario();
        Field::create($formId, 'text', 'Comentario', position: 0);

        $html = self::render($formId);

        assertTrue(str_contains($html, 'Este formulario aún no ha recibido ninguna respuesta'), 'hay un estado vacío con palabras');
        assertFalse(str_contains($html, 'Detalle de respuestas'), 'no se pinta la tabla vacía');
        assertTrue(str_contains($html, 'Ir a compartir'), 'y una acción para salir del estado');
    }

    /**
     * Un filtro (fechas o atributo) que no casa con nada NO es el mismo vacío: el
     * formulario SÍ tiene respuestas, solo que ninguna cae en lo que se pidió. El
     * mensaje del detalle lo distingue.
     */
    public function testUnFiltroSinResultadosNoEsElMismoVacio(): void
    {
        $formId = self::campoRespuestas();

        // Solo la página de fechas vacías no basta: se fuerza un rango imposible.
        $html = self::render($formId, ['from' => '2000-01-01', 'to' => '2000-01-02']);

        assertFalse(str_contains($html, 'Este formulario aún no ha recibido ninguna respuesta'), 'no es «sin respuestas»: las hay, fuera del rango');
        assertTrue(str_contains($html, 'Sin respuestas para este filtro'), 'el vacío del detalle lo dice');
    }

    // ---------------------------------------------------------------
    // W4.5 — Tasa de respuesta (modo roster)
    // ---------------------------------------------------------------

    /**
     * La tarjeta «Tasa de respuesta» aparece SOLO en un formulario de listado: es
     * el único que tiene padrón del que sacar el denominador. Y el número sale de
     * `respondedCountForForm()` sobre el total del padrón, no del filtro.
     */
    public function testLaTasaDeRespuestaApareceSoloEnUnFormularioDeListado(): void
    {
        // Un formulario normal con una respuesta: sin tarjeta.
        $normal = self::campoRespuestas();
        $htmlNormal = self::render($normal);
        assertFalse(str_contains($htmlNormal, 'Tasa de respuesta'), 'sin listado no hay tasa de respuesta');

        // Un formulario de listado con 4 personas de las que 2 responden.
        $roster = self::formulario(['access' => ['mode' => FormSettings::ACCESS_ROSTER, 'identifier' => 'cedula']]);
        $campo = (int) Field::create($roster, 'text', 'Comentario', position: 0);
        foreach ([['V-1', true], ['V-2', true], ['V-3', false], ['V-4', false]] as [$ced, $responde]) {
            $alta = FormParticipant::create($roster, 'cedula', ['cedula' => $ced], []);
            assertTrue(($alta['ok'] ?? false) === true, 'alta de ' . $ced);
            if ($responde) {
                $sid = Submission::create($roster, '127.0.0.1', 'test', null, (int) $alta['id']);
                Answer::create($sid, $campo, 'Sí');
                // En la app, el mismo POST del fill marca `responded_at` en la
                // misma transacción. Aquí se hace a mano con el MISMO método,
                // porque sin la marca el padrón diría que nadie respondió.
                FormParticipant::marcarRespondido((int) $alta['id']);
            }
        }

        $html = self::render($roster);
        assertTrue(str_contains($html, 'Tasa de respuesta'), 'con listado sí aparece');
        assertTrue(str_contains($html, '2 de 4'), 'cuenta los que respondieron sobre el padrón entero');
    }

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
                // Sin conexión no hay residuales que borrar.
            }
        });
    }

    private static function cleanupResiduals(): void
    {
        try {
            Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'anallay-%'");
        } catch (\Throwable $e) {
            // sin BD no hay nada que limpiar
        }
    }
}
