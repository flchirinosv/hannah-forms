<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FillController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Field;
use App\Models\Form;
use App\Models\FormAccess;
use App\Models\FormParticipant;
use App\Models\Submission;
use App\Models\User;
use App\Services\FormSettings;
use App\Services\RosterDoor;

/**
 * Fase 4 — La puerta de `roster`: el código personal abre el formulario.
 *
 * El criterio es el de `FormAccessTest` y por la misma razón: **no se prueba que el
 * camino feliz funcione, sino que no se pueda entrar por ninguna otra vía**. Aquí la
 * lista de vías es corta y cada una es un agujero si se abre por error:
 *
 *  1. El **POST sin acceso**, que es la puerta real (el GET es una cortesía).
 *  2. El **código atado a su formulario**: sin el `form_id` en el WHERE, un código
 *     sería la llave maestra de todos los listados.
 *  3. **Borrar a alguien de la lista le cierra la puerta en la petición
 *     siguiente**, y no solo a quien aún no había entrado.
 *  4. La **respuesta uniforme**: la puerta no puede decir qué códigos existen.
 *  5. El **cupo**: 10 intentos, y los fallidos también cuentan.
 *
 * ## Por qué los tres modos anteriores van aquí
 *
 * Porque `RESTRICTED_MODES` es una lista y una lista se puede alargar a lo bruto:
 * añadir `roster` sin tocar `resolveAccess` deja el formulario **abierto** sin un
 * error en ningún log. Estos tests no comprueban que `email` y `allowlist` sigan
 * haciendo su trabajo (eso es de `FormAccessTest`), sino que siguen **cerrados**:
 * la puerta nueva no los puede aflojar.
 *
 * ## Sobre `submissions.participant_id`
 *
 * Va en la MISMA transacción que la respuesta, y ahí se comprueba que responder
 * marca `responded_at` y que `Form::pendingReviews()`-style global no aparece:
 * una fila de `submissions` sin `participant_id` en un formulario de listado es una
 * respuesta que la analítica no puede atribuir a nadie.
 */
class RosterDoorTest
{
    private static ?int $ownerId = null;
    private static ?int $formId = null;
    private static ?string $slug = null;
    private static ?int $fieldId = null;
    private static ?int $otherFormId = null;

    private static string $lastOutput = '';

    public function setUp(): void
    {
        self::cleanupResiduals();
        Response::setRecordHalt(false);
        $_GET = [];
        $_POST = [];
        $_SESSION = [];

        // El cupo de la puerta es GLOBAL y por IP, y todos los tests salen de
        // 127.0.0.1: sin limpiarlo, los diez intentos del test de rate limit
        // agotarían el de los siguientes. Los dos scopes son exclusivos de esta
        // funcionalidad, así que borrarlos no toca nada ajeno.
        Database::getInstance(Config::db())->execute(
            "DELETE FROM rate_limits WHERE scope IN ('form_code', 'form_code_codigo')"
        );

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Roster Owner ' . $rand, 'roster-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$formId = Form::create((int) self::$ownerId, 'Formulario de lista ' . $rand);
        self::$slug = (string) Form::find((int) self::$formId, false)['slug'];
        self::$fieldId = Field::create((int) self::$formId, 'text', 'Opinion', '', '', true);
        Form::setStatus((int) self::$formId, 'published');

        // Un segundo formulario de lista: sirve para las dos cosas que NO deben
        // pasar (un código suyo no abre este, y este no se cuela en el suyo).
        self::$otherFormId = Form::create((int) self::$ownerId, 'Otro formulario de lista ' . $rand);
        Form::setStatus((int) self::$otherFormId, 'published');
        $this->setMode('roster', (int) self::$formId);
        $this->setMode('roster', (int) self::$otherFormId);
        // El embed corta con 403 ANTES de la puerta cuando `embed.enabled` está
        // apagado, así que sin esto el test del embed mediría otra cosa.
        $this->enableEmbed((int) self::$formId);

        self::authAs((int) self::$ownerId);
    }

    public function tearDown(): void
    {
        Response::setRecordHalt(false);
        unset($_GET['t'], $_POST, $_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['REQUEST_METHOD'], $_SERVER['REMOTE_ADDR']);
        Auth::forgetCache();
        Session::destroy();
        self::cleanupResiduals();
    }

    // =============================================================
    // El setting: la puerta existe, y solo para roster
    // =============================================================

    public function testElModoRosterEstaEntreLosRestringidos(): void
    {
        // La puerta del GET y el corte del POST comparten esta lista. Si `roster`
        // se queda fuera, `isRestricted()` dice false y el formulario se sirve
        // abierto: el fallo más caro de la fase y el que no da ningún error.
        assertTrue(FormAccess::isRestricted(['access' => ['mode' => 'roster']]), 'roster pide identificación');
        assertTrue(in_array(FormSettings::ACCESS_ROSTER, FormSettings::RESTRICTED_MODES, true), 'y está en la lista de restringidos');
    }

    public function testLosTresModosCerradosSiguenCerrados(): void
    {
        foreach (['public', 'email', 'allowlist'] as $modo) {
            assertSame(
                $modo !== 'public',
                FormAccess::isRestricted(['access' => ['mode' => $modo]]),
                'el modo ' . $modo . ' no cambia de estado'
            );
        }
    }

    // =============================================================
    // GET / embed
    // =============================================================

    public function testSinCodigoNoSeSirveElFormulario(): void
    {
        $html = $this->getFill(null);
        assertTrue(str_contains($html, 'vfAccessCode'), 'pinta la puerta con el campo de código');
        assertTrue(!str_contains($html, 'id="vfSubmit'), 'y no el formulario');
    }

    public function testElCodigoEnLaUrlAbreElFormulario(): void
    {
        $p = $this->crearParticipante();
        $html = $this->getFill((string) $p['code']);

        assertTrue(!str_contains($html, 'vfAccessCode'), 'con el código ya no hay puerta');
        assertTrue(str_contains($html, 'id="vfSubmit"'), 'y se ve el formulario');
    }

    public function testElFormularioQuedaEnLaSesionTrasPasarPorLaUrl(): void
    {
        // Sin esto, un F5 con la barra de direcciones recortada deja el formulario
        // a medias: la credencial del listado es larga y se parte al pegarla.
        $p = $this->crearParticipante();
        $this->getFill((string) $p['code']);

        $grant = $_SESSION['vf_form_access'][(int) self::$formId] ?? null;
        assertNotNull($grant, 'la puerta deja permiso en sesión');
        assertSame((int) $p['id'], (int) ($grant['id'] ?? 0), 'el permiso es el id del participante');
        assertSame('participant', (string) ($grant['kind'] ?? ''), 'y dice de qué tipo es');
        assertSame(0, (int) ($grant['exp'] ?? -1), 'sin caducidad: lo que lo cierra es la lista');
    }

    public function testElEmbedTambienPideElCodigoYLoAbre(): void
    {
        assertTrue(str_contains($this->embed(null), 'vfAccessCode'), 'el embed también pasa por la puerta');

        $p = $this->crearParticipante();
        assertTrue(str_contains($this->embed((string) $p['code']), 'id="vfSubmit"'), 'y con el código se ve');
    }

    public function testUnCodigoDeOtroFormularioNoAbreEste(): void
    {
        $p = $this->crearParticipante((int) self::$otherFormId);
        $html = $this->getFill((string) $p['code']);

        assertTrue(str_contains($html, 'vfAccessCode'), 'el código ajeno no vale aquí');
        assertTrue(!str_contains($html, 'id="vfSubmit"'), 'y el formulario sigue cerrado');
    }

    public function testUnCodigoInexistenteNoAbreYNoDiceMasDeLaCuenta(): void
    {
        $html = $this->getFill('ABCD2345ABCD2345');
        assertTrue(str_contains($html, 'vfAccessCode'), 'no entra');
        assertTrue(!str_contains($html, 'id="vfSubmit"'), 'y no se sirve el formulario');
    }

    public function testElCodigoVaNormalizadoEnLaUrl(): void
    {
        // El enlace se copia a mano y se pega con espacios y minúsculas: si la
        // puerta no normaliza, un código correcto escrito en minúsculas no entra.
        $p = $this->crearParticipante();
        $codigo = (string) $p['code'];

        assertTrue(str_contains($this->getFill(strtolower($codigo)), 'Opinion'), 'en minúsculas entra');
        assertTrue(str_contains($this->getFill(' ' . strtolower($codigo) . ' '), 'Opinion'), 'y con espacios alrededor');
    }

    // =============================================================
    // POST: el acceso del GET no es cortesía
    // =============================================================

    public function testElPostSinAccesoSeCortaCon403(): void
    {
        $antes = $this->submits();
        $halt = self::statusOf($this->postSubmit());

        assertSame(403, $halt, 'sin código ni sesión, el POST no pasa');
        assertSame($antes, $this->submits(), 'y no persiste nada');
    }

    public function testElCodigoEnLaUrlDaAccesoAlPost(): void
    {
        $p = $this->crearParticipante();
        $antes = $this->submits();

        $this->getFill((string) $p['code']);
        $halt = self::statusOf($this->postSubmit(null));

        assertSame(302, $halt, 'con el código, el POST pasa y redirige a las gracias');
        assertSame($antes + 1, $this->submits(), 'y guarda una respuesta');
    }

    public function testLaSesionSustituyeAlCodigoEnElSiguientePost(): void
    {
        $p = $this->crearParticipante();
        $this->getFill((string) $p['code']);

        $antes = $this->submits();
        $halt = self::statusOf($this->postSubmit(null));

        assertTrue($halt !== 403, 'la sesión da acceso: ' . $halt);
        assertSame($antes + 1, $this->submits(), 'guarda una respuesta');
    }

    // =============================================================
    // La respuesta queda atribuida al participante
    // =============================================================

    public function testLaRespuestaQuedaAtadaAlParticipante(): void
    {
        $p = $this->crearParticipante();
        $antes = $this->submits();

        $this->getFill((string) $p['code']);
        $this->postSubmit(null);

        $fila = Database::getInstance(Config::db())->fetch(
            'SELECT participant_id FROM submissions WHERE form_id = :f ORDER BY id DESC LIMIT 1',
            ['f' => (int) self::$formId]
        );
        assertSame((int) $p['id'], (int) ($fila['participant_id'] ?? 0), 'la submission lleva el participante');
        assertSame($antes + 1, $this->submits(), 'y solo hay una nueva');
    }

    public function testResponderMarcaLaFechadEnLaLista(): void
    {
        $p = $this->crearParticipante();
        assertTrue($p['responded_at'] === null, 'nace sin responder');

        $this->getFill((string) $p['code']);
        $this->postSubmit(null);

        $fila = FormParticipant::find((int) $p['id']);
        assertTrue($fila['responded_at'] !== null, 'responder marca la lista');
    }

    public function testResponderDosVecesNoPermiteAccesoTrasPrimeraRespuesta(): void
    {
        $p = $this->crearParticipante();
        $this->getFill((string) $p['code']);
        $this->postSubmit(null);
        $primera = (string) FormParticipant::find((int) $p['id'])['responded_at'];

        sleep(1);
        $this->getFill((string) $p['code']);
        // Tras haber respondido, el acceso debe ser rechazado y no debe enviarse otra respuesta
        $this->postSubmit(null);

        assertSame($primera, (string) FormParticipant::find((int) $p['id'])['responded_at'], 'la marca no cambia');
        assertSame(1, $this->submits(), 'no se guarda una segunda respuesta');
    }

    public function testUnFormularioNormalNoRecibeParticipantId(): void
    {
        // `participant_id` es NULL en `public`, `email` y `allowlist`: la columna
        // existe para atribuir respuestas de un listado, no para marcar «alguien
        // entró con un correo», que ya está en `form_access_tokens`.
        $this->setMode('email');
        $this->issueToken('ana@test.local');
        $this->getFill($this->ultimoToken);

        $antes = $this->submits();
        $this->postSubmit($this->ultimoToken);

        $fila = Database::getInstance(Config::db())->fetch(
            'SELECT participant_id FROM submissions WHERE form_id = :f ORDER BY id DESC LIMIT 1',
            ['f' => (int) self::$formId]
        );
        assertTrue(
            array_key_exists('participant_id', $fila) && $fila['participant_id'] === null,
            'la columna existe y es NULL'
        );
        assertSame($antes + 1, $this->submits(), 'pero la respuesta se guarda');
    }

    // =============================================================
    // Revocar: quitar a alguien de la lista cierra su puerta
    // =============================================================

    public function testBorrarAlParticipanteCierraLaPuertaEnLaPeticionSiguiente(): void
    {
        $p = $this->crearParticipante();
        $this->getFill((string) $p['code']);
        assertTrue(!str_contains($this->getFill(null), 'vfAccessCode'), 'con el permiso de sesión se entra');

        FormParticipant::delete((int) $p['id']);

        $html = $this->getFill(null);
        assertTrue(str_contains($html, 'vfAccessCode'), 'ya no se entra aunque el permiso siga en sesión');
        assertTrue(!isset($_SESSION['vf_form_access'][(int) self::$formId]), 'y el permiso se retira');
    }

    public function testElCodigoDeAlguienBorradoTampocoAbre(): void
    {
        $p = $this->crearParticipante();
        FormParticipant::delete((int) $p['id']);

        assertTrue(str_contains($this->getFill((string) $p['code']), 'vfAccessCode'), 'el código muerto no abre');
    }

    // =============================================================
    // La página del código (POST /f/{slug}/code)
    // =============================================================

    public function testElCodigoCorrectoCanjeaElAccesoYDevuelveLaUrlConElCodigo(): void
    {
        $p = $this->crearParticipante();
        $halt = $this->postCode((string) $p['code']);

        assertSame(302, $halt['status'] === 302 ? 302 : 0, 'redirige');
        assertTrue(str_contains((string) ($halt['to'] ?? ''), '/f/' . self::$slug . '?t=' . $p['code']), 'a la puerta con el código');
        assertSame('participant', (string) ($_SESSION['vf_form_access'][(int) self::$formId]['kind'] ?? ''), 'y deja permiso');
    }

    public function testElCodigoEnMinusculasSeCanjeaIgual(): void
    {
        $p = $this->crearParticipante();
        $halt = $this->postCode(strtolower((string) $p['code']) . ' ');

        assertSame(302, $halt['status'] === 302 ? 302 : 0, 'normaliza antes de buscar');
    }

    public function testUnCodigoMaloSeContestaIgualQueUnoInexistente(): void
    {
        // Si estos dos mensajes difieren, la página es un oráculo de qué códigos
        // existen, y el rate limit deja de ser la defensa.
        $inexistente = $this->postCode('ABCD2345ABCD2345');
        $ajeno = $this->postCode((string) $this->crearParticipante((int) self::$otherFormId)['code']);
        // Tiene que PARECER un código (16 caracteres del alfabeto): la elección del
        // mensaje es por forma, y una cadena de 15 se contestaría con el texto del
        // identificador y este test estaría midiendo otra cosa.
        $tecleado = $this->postCode('ABCD2345ABCD234X');

        assertSame('error', (string) ($inexistente['flash'] ?? ''), 'el inexistente avisa');
        assertSame($inexistente['mensaje'], $ajeno['mensaje'], 'y el de otro formulario dice exactamente lo mismo');
        assertSame($inexistente['mensaje'], $tecleado['mensaje'], 'igual que uno mal tecleado');

        // Comparar solo entre ellos NO basta: los tres salen del mismo `if`, así
        // que serían iguales con cualquier texto. Lo que ata el test es que el texto
        // sea **el del código**: el formulario pide la cédula, pero lo que parece
        // código se contesta como código exista o no.
        assertSame(I18n::t('access.invalid_codigo'), $inexistente['mensaje'], 'el mensaje es el del código, no el del identificador');
        assertTrue(!isset($_SESSION['vf_form_access'][(int) self::$formId]), 'ninguno abre la puerta');
    }

    public function testLoQueNoPareceCodigoSeContestaConElDelIdentificador(): void
    {
        // La otra mitad de la regla anterior: quien escribe el dato que la página
        // pide (la cédula) tiene que recibir un mensaje que nombre ese dato, o el
        // «Ese código no es válido» no le dice nada de lo que tiene que corregir.
        $this->setIdentifier('cedula');
        $mal = $this->postCode('V-999999');

        assertSame('error', (string) ($mal['flash'] ?? ''), 'avisa');
        assertSame(I18n::t('access.invalid_cedula'), $mal['mensaje'], 'el mensaje nombra la cédula');
        assertTrue(!isset($_SESSION['vf_form_access'][(int) self::$formId]), 'y no abre');
    }

    public function testLaCedulaDelParticipanteAbreElFormularioYLaUrlQuedaConSuCodigo(): void
    {
        // El identificador por defecto es la cédula, y la puerta acepta el dato con
        // el que la persona figura en la lista además de su código. Lo que queda en
        // la URL es el CÓDIGO, porque es la credencial con la que se propaga el
        // resto del formulario (y la que el QR y el correo traen).
        $p = $this->crearParticipante();
        $halt = $this->postCode((string) $p['cedula']);

        assertSame(302, $halt['status'] === 302 ? 302 : 0, 'redirige');
        assertTrue(str_contains((string) ($halt['to'] ?? ''), '/f/' . self::$slug . '?t=' . $p['code']), 'a la puerta con el código');
        assertSame('participant', (string) ($_SESSION['vf_form_access'][(int) self::$formId]['kind'] ?? ''), 'y deja permiso');
    }

    public function testSoloEntraElIdentificadorQueElFormularioPide(): void
    {
        // La lista vieja probaba cédula, indicador y correo sin importar el ajuste,
        // y eso hacía que la puerta dejara entrar por un dato que la página ni siquiera
        // pide. Aquí el formulario pide el correo: la cédula de un participante real
        // tiene que quedar fuera.
        $p = $this->crearParticipante();
        $this->setIdentifier('correo');

        $porCedula = $this->postCode((string) $p['cedula']);
        assertSame('error', (string) ($porCedula['flash'] ?? ''), 'la cédula no es el identificador que este formulario pide');
        assertSame(I18n::t('access.invalid_correo'), $porCedula['mensaje'], 'y el mensaje nombra el campo que sí pide');
        assertTrue(!isset($_SESSION['vf_form_access'][(int) self::$formId]), 'no abre');

        $conCorreo = FormParticipant::create((int) self::$formId, 'correo', [
            'cedula' => 'V-' . bin2hex(random_bytes(3)),
            'correo' => 'puerta-' . bin2hex(random_bytes(3)) . '@test.local',
        ]);
        assertTrue((bool) $conCorreo['ok'], 'el participante se crea: ' . (string) $conCorreo['motivo']);
        $fila = FormParticipant::find((int) $conCorreo['id']);
        assertNotNull($fila, 'y se lee');

        $halt = $this->postCode((string) $fila['correo']);
        assertSame(302, $halt['status'] === 302 ? 302 : 0, 'con el correo sí entra');
        assertTrue(str_contains((string) ($halt['to'] ?? ''), '?t=' . $fila['code']), 'y sale con la URL del código');
    }

    public function testUnFormularioPorCodigoNoAbreNiConLaCedulaNiConElCorreo(): void
    {
        // Con `identifier = codigo` la única puerta es el código: no hay segunda
        // vuelta buscando la cédula o el correo de al lado, porque entonces la
        // página pediría una cosa y la puerta abriría otra.
        $p = $this->crearParticipante();
        $this->setIdentifier('codigo');

        assertTrue(str_contains($this->getFill((string) $p['cedula']), 'vfAccessCode'), 'la cédula sigue en la puerta');
        assertTrue(str_contains($this->getFill((string) $p['code']), 'id="vfSubmit"'), 'y el código entra');
    }

    public function testLaPaginaDeAccesoPideElIdentificadorQueToca(): void
    {
        // El texto genérico («tu código personal o identificador») no le dice a
        // nadie qué escribir, y el placeholder y el teclado tienen que corresponder
        // al dato. Esto ata la página al `access.identifier` del formulario.
        $html = $this->getFill(null);
        assertTrue(str_contains($html, I18n::t('access.label_cedula')), 'pide la cédula, que es su identificador');
        assertTrue(str_contains($html, I18n::t('access.placeholder_cedula')), 'con el placeholder de la cédula');
        assertTrue(!str_contains($html, I18n::t('access.label_codigo')), 'y no el del código, que sería otra puerta');
        assertTrue(str_contains($html, 'name="code"'), 'el campo sigue llamándose code: es el contrato de verifyCode()');

        $this->setIdentifier('codigo');
        $html = $this->getFill(null);
        assertTrue(str_contains($html, I18n::t('access.label_codigo')), 'con identificador código, pide el código');
        assertTrue(str_contains($html, I18n::t('access.placeholder_codigo')), 'y su placeholder');

        $this->setIdentifier('correo');
        $html = $this->getFill(null);
        assertTrue(str_contains($html, I18n::t('access.label_correo')), 'con correo, pide el correo');
        assertTrue(str_contains($html, 'type="email"'), 'y con el teclado de correo');
    }

    public function testDiezIntentosFallidosCierranLaPuertaYElCodigoBuenoTambien(): void
    {
        $p = $this->crearParticipante();
        for ($i = 0; $i < RosterDoor::MAX_INTENTOS; $i++) {
            $this->postCode('ABCD2345ABCD234' . $i);
        }

        $bloqueado = $this->postCode((string) $p['code']);
        assertSame('error', (string) ($bloqueado['flash'] ?? ''), 'el onceavo intento no entra');
        assertTrue(!isset($_SESSION['vf_form_access'][(int) self::$formId]), 'ni con el código correcto');
        assertTrue(!str_contains((string) ($bloqueado['to'] ?? ''), '?t='), 'y no redirige al formulario');
    }

    public function testLaPaginaDelCodigoNoExisteEnUnFormularioSinListado(): void
    {
        $this->setMode('public');
        $halt = $this->postCode('ABCD2345ABCD2345');

        assertSame(404, (int) ($halt['status'] ?? 0), '404 y no 403: si el formulario tiene puerta, es información');
    }

    // =============================================================
    // Utilidades
    // =============================================================

    private ?string $ultimoToken = null;

    private function crearParticipante(?int $formId = null): array
    {
        $rand = bin2hex(random_bytes(3));
        $res = FormParticipant::create($formId ?? (int) self::$formId, 'cedula', [
            'cedula' => 'V-' . $rand,
            'nombre' => 'Persona ' . $rand,
        ]);

        assertTrue((bool) $res['ok'], 'el participante se crea: ' . (string) $res['motivo']);
        $fila = FormParticipant::find((int) $res['id']);
        assertNotNull($fila, 'y se lee');

        return $fila;
    }

    /**
     * Cambia el modo de acceso **sin perder el resto de los ajustes**.
     *
     * Con un `UPDATE ... settings = json` a pelo se pisarían `embed.enabled` y
     * todo lo demás, y el test del embed se entonces por un 403 «este formulario
     * no permite ser embebido» que no tiene nada que ver con la puerta.
     */
    private function setMode(string $mode, ?int $formId = null): void
    {
        $id = $formId ?? (int) self::$formId;
        $db = Database::getInstance(Config::db());
        $row = $db->fetch('SELECT settings FROM forms WHERE id = :id', ['id' => $id]);
        $ajustes = json_decode((string) ($row['settings'] ?? '{}'), true);
        $ajustes = is_array($ajustes) ? $ajustes : [];
        $ajustes['access'] = ['mode' => $mode] + (is_array($ajustes['access'] ?? null) ? $ajustes['access'] : []);

        $db->execute('UPDATE forms SET settings = :s WHERE id = :id', ['s' => json_encode($ajustes), 'id' => $id]);
    }

    /**
     * Cambia SOLO el identificador del formulario, sin tocar el modo.
     *
     * Es el ajuste que decide qué le pide la puerta a la persona, y los tests de
     * credencial necesitan poder moverlo sin reimplementar el merge de ajustes que
     * ya hace `setMode()`.
     */
    private function setIdentifier(string $identificador, ?int $formId = null): void
    {
        $id = $formId ?? (int) self::$formId;
        $db = Database::getInstance(Config::db());
        $row = $db->fetch('SELECT settings FROM forms WHERE id = :id', ['id' => $id]);
        $ajustes = json_decode((string) ($row['settings'] ?? '{}'), true);
        $ajustes = is_array($ajustes) ? $ajustes : [];
        $acceso = is_array($ajustes['access'] ?? null) ? $ajustes['access'] : [];
        $acceso['identifier'] = $identificador;
        $ajustes['access'] = $acceso;

        $db->execute('UPDATE forms SET settings = :s WHERE id = :id', ['s' => json_encode($ajustes), 'id' => $id]);
    }

    private function enableEmbed(?int $formId = null): void
    {
        $id = $formId ?? (int) self::$formId;
        $db = Database::getInstance(Config::db());
        $row = $db->fetch('SELECT settings FROM forms WHERE id = :id', ['id' => $id]);
        $ajustes = json_decode((string) ($row['settings'] ?? '{}'), true);
        $ajustes = is_array($ajustes) ? $ajustes : [];
        $ajustes['embed'] = ['enabled' => true] + (is_array($ajustes['embed'] ?? null) ? $ajustes['embed'] : []);

        $db->execute('UPDATE forms SET settings = :s WHERE id = :id', ['s' => json_encode($ajustes), 'id' => $id]);
    }

    private function issueToken(string $email): void
    {
        $this->ultimoToken = FormAccess::issue((int) self::$formId, $email, '127.0.0.1');
    }

    private function getFill(?string $codigo, ?string $slug = null): string
    {
        $this->render(function () use ($slug): void {
            (new FillController())->show($slug ?? (string) self::$slug);
        }, $codigo, null);

        return self::$lastOutput;
    }

    private function embed(?string $codigo): string
    {
        $this->render(function (): void {
            (new FillController())->embed((string) self::$slug);
        }, $codigo, null);

        return self::$lastOutput;
    }

    private function postSubmit(?string $codigo = null): array
    {
        $_POST = [
            '_token' => Csrf::token(),
            '_vf_hp' => '',
            'lang'   => 'es',
            'field_' . (int) self::$fieldId => 'Mi respuesta',
        ];

        return $this->render(function (): void {
            (new FillController())->submit((string) self::$slug);
        }, $codigo, 'POST');
    }

    private function postCode(string $codigo): array
    {
        $_POST = ['_token' => Csrf::token(), 'lang' => 'es', 'code' => $codigo];

        return $this->render(function (): void {
            (new FillController())->verifyCode((string) self::$slug);
        }, null, 'POST');
    }

    /**
     * Ejecuta una acción del `FillController` y devuelve su `halt`.
     *
     * El HTML pintado se queda en `self::$lastOutput`, que es lo que miran los
     * tests de puerta: `Response::redirect()` corta la salida con `exit`, así que
     * en un GET que redirige no hay nada que leer.
     */
    private function render(callable $fn, ?string $codigo, ?string $method): array
    {
        $_GET = $codigo === null ? [] : ['t' => $codigo];
        $_SERVER['REQUEST_METHOD'] = $method ?? 'GET';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Request::setRequestBodyForTests(null);

        Response::setRecordHalt(true);
        ob_start();
        try {
            $fn();
        } finally {
            self::$lastOutput = (string) ob_get_clean();
        }
        $halt = Response::lastHalt() ?? [];
        Response::setRecordHalt(false);

        // El flash se consume en la vista, así que se lee del propio `$_SESSION`
        // antes de que lo borre la siguiente llamada.
        // El flash se lee aquí, antes de que lo consuma la siguiente vista, y se
        // guarda el TEXTO: con el tipo (`error`) dos mensajes distintos serían
        // indistinguibles y la uniformidad no estaría comprobada.
        $mensaje = Session::getFlash('error');
        $halt['flash'] = $mensaje !== '' ? 'error' : '';
        $halt['mensaje'] = $mensaje;

        $halt['status'] = self::statusOf($halt);
        $halt['to'] = (string) ($halt['url'] ?? '');

        return $halt;
    }

    private function submits(): int
    {
        $row = Database::getInstance(Config::db())->fetch(
            'SELECT COUNT(*) AS c FROM submissions WHERE form_id = :f',
            ['f' => (int) self::$formId]
        );

        return (int) ($row['c'] ?? 0);
    }

    private static function statusOf(?array $halt): int
    {
        if ($halt === null) {
            return 0;
        }

        return match ((string) ($halt['type'] ?? '')) {
            'abort' => (int) ($halt['code'] ?? 0),
            'json'  => (int) ($halt['status'] ?? 0),
            'redirect', 'redirect_external' => 302,
            default => 0,
        };
    }

    private static function authAs(int $userId): void
    {
        Auth::assumeLoggedIn($userId);
        Auth::forgetCache();
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());

        $db->execute("DELETE FROM users WHERE email LIKE 'roster-%'");
        $db->execute("DELETE FROM forms WHERE title LIKE 'Formulario de lista %'");
        $db->execute("DELETE FROM forms WHERE title LIKE 'Otro formulario de lista %'");

        self::$ownerId = null;
        self::$formId = null;
        self::$slug = null;
        self::$fieldId = null;
        self::$otherFormId = null;
    }
}