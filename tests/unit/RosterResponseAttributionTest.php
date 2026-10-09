<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Controllers\ResponsesController;
use App\Models\Answer;
use App\Models\Field;
use App\Models\Form;
use App\Models\FormParticipant;
use App\Models\Submission;
use App\Models\User;
use App\Services\FormSettings;
use App\Services\Pii;

/**
 * Fase 9 — Quién respondió, con la puerta de `responses.pii`.
 *
 * Hasta aquí la atribución se guardaba (`submissions.participant_id`, escrito en la
 * misma transacción que la respuesta) y no se veía en ninguna parte: el listado de
 * respuestas tenía #, fecha, IP y acciones, y el detalle tenía fecha, IP y
 * navegador. La ficha marcaba «respondió» por participante, así que el dato
 * existía en dos sitios pero no se podía cruzar respuesta → persona.
 *
 * Y aquí hay una tensión real con la regla que ya existe para la analítica: allí se
 * decidió que cédula, nombre y correo del participante **no aparecen ni para el
 * admin**, porque la analítica existe para agrupar. El detalle de una respuesta es
 * lo contrario: existe para leer *esa* respuesta, y saber quién la envió es parte
 * de lo que se viene a ver. Por eso esto vive en `responses.pii` y no en
 * `responses.view_own`: es el permiso que ya significa «puedes leer datos
 * personales en claro», y no uno nuevo que nadie tendría.
 *
 * Lo que se ata aquí:
 *
 *  1. **Sin permiso no hay columna, y no una columna enmascarada.** Una celda con
 *     `[oculto]` en cada fila no dice nada que el aviso no diga, y en un formulario
 *     que no es de listado la columna sería de «—» siempre. La ausencia se
 *     **explica**, porque un formulario con lista que no dice quién respondió
 *     parece un fallo y no lo es.
 *  2. **La identidad se pinta con las mismas piezas que la ficha** (identificador,
 *     nombre y correo), y con identificador `correo` el correo NO se repite debajo.
 *  3. **Una respuesta sin atribución dice «—»** en vez de dejar un hueco: la
 *     columna se pinta aunque toda la página sea de gente que respondió sin estar.
 *  4. **El `form_id` va en el WHERE** de la consulta de identidades. Los ids salen
 *     de `submissions` de un formulario, así que la condición nunca descarta nada;
 *     es la mitad de la autorización, como en `FormAccess::findValidToken()`.
 */
class RosterResponseAttributionTest
{
    private static bool $cleanupHookRegistered = false;
    private static ?int $ownerId = null;
    private static ?int $adminId = null;
    private static ?int $formId = null;
    private static ?int $campoId = null;

    public function setUp(): void
    {
        self::ensureCleanupHook();
        self::cleanupResiduals();

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Roster Attribution ' . $rand, 'rosteratr-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$formId  = Form::create((int) self::$ownerId, 'Roster Attribution ' . $rand);
        self::$campoId = (int) Field::create((int) self::$formId, 'text', 'Comentario', '', '', false, [], [], 0, null, null, false);

        self::modo(FormSettings::ACCESS_ROSTER, 'cedula');
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
    // 1. La puerta
    // =====================================================================

    public function testSinElPermisoNoHayColumnaDeParticipanteNiSeExplicaQueFalta(): void
    {
        self::responder('V-1', 'Ana Ruiz', 'ana@test.local');

        $html = self::listar(self::$ownerId);

        assertFalse(str_contains($html, 'Participante'), 'sin `responses.pii` no se pinta la columna');
        assertFalse(str_contains($html, 'ana@test.local'), 'ni el correo de la persona');
        assertFalse(str_contains($html, 'V-1'), 'ni su identificador');
        // Y sí se explica por qué no está, que es lo que hace que la ausencia
        // parezca una decisión y no un fallo.
        assertTrue(str_contains($html, 'responses.pii'), 'el aviso nombra el permiso que falta');
    }

    public function testConElPermisoLaColumnaDiceQuienRespondio(): void
    {
        self::responder('V-1', 'Ana Ruiz', 'ana@test.local');

        $html = self::listar(self::adminId());

        assertTrue(str_contains($html, 'Participante'), 'la columna se pinta');
        assertTrue(str_contains($html, 'V-1'), 'con el identificador del formulario');
        assertTrue(str_contains($html, 'Ana Ruiz'), 'y el nombre');
        assertTrue(str_contains($html, 'ana@test.local'), 'y el correo');
        assertFalse(str_contains($html, 'en la lista no dice'), 'con permiso no hace falta el aviso de ausencia');
    }

    public function testElRolUserNoVeLaIdentidadYElAdminSi(): void
    {
        self::responder('V-1', 'Ana Ruiz', 'ana@test.local');
        self::$ownerId = (int) self::$ownerId; // el dueño es un `user`: sin permiso

        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
        assertFalse(Pii::canView(), 'el dueño (rol user) no tiene responses.pii por defecto');

        $html = self::listar(self::$ownerId);
        assertFalse(str_contains($html, 'Ana Ruiz'), 'el dueño no la ve');

        $htmlAdmin = self::listar(self::adminId());
        assertTrue(str_contains($htmlAdmin, 'Ana Ruiz'), 'el admin sí, y sin cambiar nada más');
    }

    // =====================================================================
    // 2. Un formulario que NO es de listado no gana columna
    // =====================================================================

    public function testUnFormularioSinListadoNoPintaColumnaAunqueHayaPermiso(): void
    {
        self::responder('V-1', 'Ana Ruiz', 'ana@test.local');
        self::modo(FormSettings::ACCESS_PUBLIC, 'cedula');

        $html = self::listar(self::adminId());

        assertFalse(
            str_contains($html, 'Participante'),
            'en un formulario normal nadie está atribuido: la columna sería de «—» en todas las filas'
        );
        assertFalse(str_contains($html, 'Ana Ruiz'), 'y no sale la identidad que hubiera en la lista');
    }

    // =====================================================================
    // 3. La columna se pinta aunque no haya atribución
    // =====================================================================

    public function testUnaRespuestaSinAtribucionDiceGuionYLaColumnaSigueAhí(): void
    {
        // Nadie en la lista: la submission se crea sin `participant_id`, como la
        // que entraría por un formulario público antes de que hubiera lista.
        $s1 = Submission::create((int) self::$formId, '127.0.0.1', 'test', null, null);
        Answer::create($s1, (int) self::$campoId, 'sin lista');
        self::responder('V-1', 'Ana Ruiz', 'ana@test.local');

        $html = self::listar(self::adminId());
        $filas = self::filas($html);

        assertSame(2, count($filas), 'las dos respuestas de la página');
        assertTrue(str_contains($html, 'Participante'), 'la columna se pinta: hay respuestas atribuidas en la página');
        assertTrue(str_contains($html, 'V-1'), 'la atribuida sale con su identificador');

        // La fila sin atribución se comprueba por ESTRUCTURA, que es como se
        // rompen estas cosas: el `<td>` está en el encabezado y no en la fila, y
        // la tabla sale más corta en esa columna sin que nada se note.
        foreach ($filas as $i => $fila) {
            assertSame(5, self::celdas($fila), 'la fila ' . $i . ' tiene cinco celdas, como el encabezado');
        }
        $sinAtribuir = str_contains($filas[0], 'V-1') ? $filas[1] : $filas[0];
        assertFalse(str_contains($sinAtribuir, 'V-1'), 'una de las dos no está atribuida');
        assertTrue(str_contains($sinAtribuir, '—'), 'y su celda de participante dice «—», no queda vacía');
    }

    public function testUnaPaginaEnteraSinAtribucionIgualPintaLaColumna(): void
    {
        $s1 = Submission::create((int) self::$formId, '127.0.0.1', 'test', null, null);
        Answer::create($s1, (int) self::$campoId, 'uno');

        $html = self::listar(self::adminId());

        // La columna se decide por el FORMULARIO y el permiso, no por si esta
        // página casó con alguien: si no, al paginar una lista de mil la columna
        // aparecería y desaparecería por casualidad.
        assertTrue(str_contains($html, 'Participante'), 'la columna se decide por el formulario, no por la página');
        assertSame(5, self::celdas(self::filas($html)[0]), 'y la celda existe en la fila aunque no haya nadie');
    }

    // =====================================================================
    // 4. El detalle de una respuesta
    // =====================================================================

    public function testElDetalleNombraAlParticipanteConPermisoYNoSinEl(): void
    {
        $sid = self::responder('V-1', 'Ana Ruiz', 'ana@test.local');

        $html = self::detalle($sid, self::adminId());
        assertTrue(str_contains($html, 'Participante'), 'el detalle también lo dice');
        assertTrue(str_contains($html, 'V-1'), 'con el identificador');
        assertTrue(str_contains($html, 'Ana Ruiz'), 'y el nombre');

        $htmlUser = self::detalle($sid, self::$ownerId);
        assertFalse(str_contains($htmlUser, 'Ana Ruiz'), 'sin permiso, el detalle tampoco');
        assertFalse(str_contains($htmlUser, 'V-1'), 'ni el identificador');
        assertFalse(str_contains($htmlUser, '>Participante<'), 'ni siquiera el rótulo de una casilla vacía');
    }

    public function testElDetalleDeUnaRespuestaSinAtribucionNoRompeLaFicha(): void
    {
        $sid = Submission::create((int) self::$formId, '127.0.0.1', 'test', null, null);
        Answer::create($sid, (int) self::$campoId, 'sin lista');

        $html = self::detalle($sid, self::adminId());

        assertFalse(str_contains($html, '>Participante<'), 'sin fila en la lista no hay casilla que pintar');
        assertTrue(str_contains($html, 'sin lista'), 'la respuesta se lee igual');
    }

    // =====================================================================
    // 5. Con identificador `correo` el correo no se repite
    // =====================================================================

    public function testConIdentificadorCorreoLaCeldaNoRepiteLaDireccion(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        self::responder('V-1', 'Ana Ruiz', 'ana@test.local');

        $html = self::listar(self::adminId());

        assertTrue(str_contains($html, 'ana@test.local'), 'el correo sale');
        assertSame(
            1,
            substr_count($html, 'ana@test.local'),
            'y solo una vez: el `identifier_value` ya es el correo, así que la fila de debajo lo repetiría'
        );
    }

    // =====================================================================
    // 6. La consulta: form_id en el WHERE, y sin N+1
    // =====================================================================

    public function testLasIdentidadesDeOtroFormularioNoSeResuelven(): void
    {
        $otro = Form::create((int) self::$ownerId, 'Roster Attribution Otro');
        $s1 = Submission::create((int) self::$formId, '127.0.0.1', 'test', null, null);
        Answer::create($s1, (int) self::$campoId, 'uno');

        // Un participante de OTRO formulario, en la misma posición que el id que
        // se le pasa. Sin el `form_id` en el WHERE esto saldría con nombre, que
        // es exactamente el fallo que el WHERE evita.
        $ajeno = FormParticipant::create($otro, 'cedula', ['cedula' => 'V-9', 'nombre' => 'Ajena'], []);
        assertTrue(($ajeno['ok'] ?? false) === true, 'alta del participante ajeno');
        $pidAjeno = (int) $ajeno['id'];

        $identidades = FormParticipant::identidadesDe((int) self::$formId, [$pidAjeno, 999999]);

        assertSame([], $identidades, 'un id de otro formulario no resuelve identidad, aunque exista la fila');

        $propias = FormParticipant::identidadesDe((int) self::$formId, [(int) self::$formId]);
        assertSame([], $propias, 'y un id inexistente tampoco inventa nada');
    }

    public function testIdentidadesDeTraeCadaFilaIndexadaYSoloLasDelFormulario(): void
    {
        self::responder('V-1', 'Ana Ruiz', 'ana@test.local');
        self::responder('V-2', 'Beto Paz', 'beto@test.local');

        $ids = self::idsDeParticipantes();
        $mapa = FormParticipant::identidadesDe((int) self::$formId, [$ids[0], $ids[1], $ids[0], null]);

        assertSame(2, count($mapa), 'los repetidos se colapsan y el null no cuenta');
        assertSame('V-1', $mapa[$ids[0]]['identifier_value']);
        assertSame('Beto Paz', $mapa[$ids[1]]['nombre']);
    }

    public function testIdentidadDeRepiteElCorreoSoloCuandoNoEsElIdentificador(): void
    {
        $fila = ['identifier_value' => 'V-1', 'nombre' => 'Ana Ruiz', 'correo' => 'ana@test.local'];

        $porCedula = FormParticipant::identidadDe($fila, 'cedula');
        assertSame('V-1', $porCedula['principal']);
        assertSame('Ana Ruiz', $porCedula['nombre']);
        assertSame('ana@test.local', $porCedula['correo'], 'con cédula el correo es dato aparte');

        $porCorreo = FormParticipant::identidadDe($fila, 'correo');
        assertSame('', $porCorreo['correo'], 'con correo como identificador, el correo ya es el principal');

        // Y sin correo ni nombre no aparece «null» en pantalla.
        $vacia = FormParticipant::identidadDe(['identifier_value' => 'V-3'], 'cedula');
        assertSame('', $vacia['nombre']);
        assertSame('', $vacia['correo']);
    }

    // =====================================================================
    // 7. Lo que NO cambia: los archivos exportados
    // =====================================================================

    public function testLosExportsNoLlevanLaIdentidadDelParticipante(): void
    {
        $sid = self::responder('V-1', 'Ana Ruiz', 'ana@test.local');

        Auth::assumeLoggedIn(self::adminId());
        Auth::forgetCache();
        Response::setRecordHalt(true);
        ob_start();
        (new ResponsesController())->exportJson((int) self::$formId);
        $json = (string) ob_get_clean();

        assertFalse(str_contains($json, 'V-1'), 'el JSON no lleva el identificador');
        assertFalse(str_contains($json, 'Ana Ruiz'), 'ni el nombre');
        assertFalse(str_contains($json, 'participant_id'), 'ni siquiera la clave de unión');
        assertTrue(str_contains($json, '"total": 1'), 'pero sí las respuestas');

        // El `participant_id` tampoco se cuela por el `answers`: es metadata de la
        // fila, no un campo del formulario, y `exportSubmissionRow()` solo mete
        // las columnas que ya había (id, fecha, IP, user agent, answers).
        $decodificado = json_decode($json, true);
        assertArrayHasKey('finished_at', $decodificado['submissions'][0] ?? []);
        assertArrayHasKey('answers', $decodificado['submissions'][0] ?? []);
        unset($sid);
    }

    // =====================================================================
    // 8. Borrar la respuesta suelta la marca de «ya respondió»
    // =====================================================================

    public function testBorrarLaRespuestaSueltaLaMarcaYDejaVolverAEntrar(): void
    {
        $sid = self::responder('V-1', 'Ana Ruiz', 'ana@test.local');
        $pid = self::idsDeParticipantes()[0];

        assertSame(1, FormParticipant::respondedCountForForm((int) self::$formId), 'antes: la tarjeta la cuenta como respondida');
        assertFalse(FormParticipant::existeEnFormulario((int) self::$formId, $pid), 'y la puerta ya no la deja pasar');

        self::borrar($sid);

        assertSame(0, FormParticipant::respondedCountForForm((int) self::$formId), 'después: el número de la tarjeta baja');
        $fila = FormParticipant::find($pid);
        assertNotNull($fila);
        assertNull($fila['responded_at'], 'la fila queda sin la marca');
        assertTrue(
            FormParticipant::existeEnFormulario((int) self::$formId, $pid),
            'y la puerta la reconoce otra vez: puede volver a responder'
        );
    }

    public function testSiLeQuedaOtraRespuestaNoSeSueltaLaMarca(): void
    {
        $primera = self::responder('V-1', 'Ana Ruiz', 'ana@test.local');
        $pid = self::idsDeParticipantes()[0];

        // `roster` no es «una respuesta por persona»: la misma persona puede
        // tener dos filas (p. ej. dos envíos antes de que la puerta cerrara). Al
        // borrar una, sigue siendo cierto que respondió.
        $segunda = Submission::create((int) self::$formId, '127.0.0.2', 'test', null, $pid);
        Answer::create($segunda, (int) self::$campoId, 'otra');

        self::borrar($primera);

        assertSame(1, FormParticipant::respondedCountForForm((int) self::$formId), 'sigue contando como respondida');
        $fila = FormParticipant::find($pid);
        assertNotNull($fila);
        assertNotNull($fila['responded_at'], 'y la marca se mantiene mientras le quede una respuesta');
        unset($segunda);
    }

    // =====================================================================
    // Ayudas
    // =====================================================================
    /**
     * Persona en la lista + una respuesta suya, y el id de la submission.
     *
     * El alta usa el identificador que tiene el formulario AHORA, no un `cedula`
     * fijo: con identificador `correo` la fila guarda el correo en
     * `identifier_value` y un `cedula` fijo dejaría la celda mostrando una cédula
     * que el formulario no usa, que es un fixture que miente.
     */
    private static function responder(string $cedula, string $nombre, string $correo): int
    {
        $identificador = FormParticipant::identifierOf(Form::settings((int) self::$formId));
        $alta = FormParticipant::create(
            (int) self::$formId,
            $identificador,
            ['cedula' => $cedula, 'nombre' => $nombre, 'correo' => $correo],
            []
        );
        assertTrue(($alta['ok'] ?? false) === true, 'alta de ' . $cedula . ': ' . (string) ($alta['motivo'] ?? ''));
        $pid = (int) $alta['id'];
        FormParticipant::marcarRespondido($pid);

        $sid = Submission::create((int) self::$formId, '127.0.0.1', 'test', null, $pid);
        Answer::create($sid, (int) self::$campoId, 'hola');

        return $sid;
    }

    /**
     * Las filas de datos del listado, una por `<tr data-vf-row>`.
     *
     * Se trocea por el `<tr>` porque el HTML que emite una vista es estructura y
     * nada más lo comprueba: una columna en el encabezado que no llega a las
     * filas se ve «casi bien».
     *
     * @return list<string>
     */
    private static function filas(string $html): array
    {
        $partes = explode('<tr data-vf-row>', $html);
        array_shift($partes);

        return $partes;
    }

    private static function celdas(string $fila): int
    {
        return substr_count($fila, '<td');
    }

    /** @return int[] los ids de participante de la lista del formulario */
    private static function idsDeParticipantes(): array
    {
        $ids = [];
        foreach (FormParticipant::allForForm((int) self::$formId, 50, 0) as $fila) {
            $ids[] = (int) $fila['id'];
        }

        return $ids;
    }

    private static function modo(string $modo, string $identificador): void
    {
        $ajustes = Form::settings((int) self::$formId);
        $ajustes['access'] = ['mode' => $modo, 'identifier' => $identificador];
        Form::saveSettings((int) self::$formId, $ajustes);
    }

    private static function adminId(): int
    {
        if (self::$adminId === null) {
            self::$adminId = (int) User::create(
                'Roster Attribution Admin',
                'rosteratra-' . bin2hex(random_bytes(4)) . '@test.local',
                'clave-larga-123',
                1
            );
        }

        return self::$adminId;
    }

    private static function listar(?int $como): string
    {
        return self::render(static function () use ($como): void {
            (new ResponsesController())->index((int) self::$formId);
        }, $como);
    }

    private static function detalle(int $sid, ?int $como): string
    {
        return self::render(static function () use ($sid): void {
            (new ResponsesController())->show((int) self::$formId, $sid);
        }, $como);
    }

    /**
     * Borra una respuesta por el controlador real, como el POST sin recarga.
     *
     * El dueño tiene que estar en sesión (`canAccess` lo comprueba) y el POST
     * llevar su token CSRF; el halt se registra en vez de cortar porque estamos
     * en CLI.
     */
    private static function borrar(int $sid): void
    {
        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
        $_POST['_token'] = Csrf::token();
        Response::setRecordHalt(true);
        (new ResponsesController())->delete((int) self::$formId, $sid);
        unset($_POST['_token']);
    }

    private static function render(callable $accion, ?int $como): string
    {
        if ($como !== null) {
            Auth::assumeLoggedIn((int) $como);
            Auth::forgetCache();
        }
        Response::setRecordHalt(true);
        ob_start();
        $accion();

        return (string) ob_get_clean();
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
        // Sin guion: el admin es `rosteratra-…` y el dueño `rosteratr-…`, y un
        // patrón con guion en medio solo cogía a uno de los dos (el bug que
        // arrastró el test de la fase 8).
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'rosteratr%'");
        self::$ownerId = null;
        self::$adminId = null;
        self::$formId = null;
        self::$campoId = null;
    }
}