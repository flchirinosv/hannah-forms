<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Form;
use App\Models\FormParticipant;
use App\Models\User;
use App\Services\FormSettings;

/**
 * FASE 12 — el recordatorio por correo del listado.
 *
 * El recordatorio NO es «invitar otra vez», y lo que estos tests fijan es la
 * frontera entre los dos correos:
 *
 *  1. **Solo a quien fue invitado y no respondió.** Recordar a quien nunca
 *     recibió la invitación sería una invitación disfrazada, y a quien ya
 *     respondió le pediría algo que ya hizo. Los tres casos conviven en el mismo
 *     formulario para que un filtro que falte se note.
 *  2. **Es repetible, con contador.** A la misma persona se le puede recordar más
 *     de una vez (a diferencia de la invitación, que se manda una sola vez), y
 *     `reminder_count` lo dice: es el `remindercount` de LimeSurvey.
 *  3. **Rota.** La tanda empieza por quien no ha recibido ningún recordatorio y
 *     sigue por el más antiguo, para no martillar a los mismos.
 *  4. **La cita del calendario.** Si el formulario tiene fecha de cierre, el correo
 *     la dice; sin fecha no aparece un «cierra el » colgado.
 *  5. **Un envío parcial no pierde a nadie.** Los que salieron se marcan y los que
 *     fallaron siguen pendientes, con el mismo corte a los tres fallos seguidos que
 *     la invitación.
 *
 * Los correos se cuentan con `Mailer::setTransport()` (el sobre entero), no se
 * simulan.
 */
class RosterReminderMailTest
{
    private static ?int $ownerId = null;
    private static ?int $otroId = null;
    private static ?int $formId = null;

    /** @var list<array<string,mixed>> */
    private static array $correos = [];

    /** @var list<string> */
    private static array $fallan = [];

    public function setUp(): void
    {
        self::cleanupResiduals();

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Rec ' . $rand, 'record-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$otroId = User::create('Rec Otro ' . $rand, 'recordotro-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$formId = Form::create((int) self::$ownerId, 'Rec ' . $rand);

        self::$correos = [];
        self::$fallan = [];

        Mailer::setConfigOverride([
            'host'         => 'smtp.invalido.test',
            'port'         => 587,
            'encryption'   => 'tls',
            'user'         => 'pruebas',
            'password'     => 'clave-de-prueba',
            'from_address' => 'remitente@test.local',
            'from_name'    => 'Pruebas',
            'timeout'      => 5,
        ]);

        Mailer::setTransport(static function (array $env): bool {
            $para = (string) ($env['to'][0] ?? '');
            if (in_array($para, self::$fallan, true)) {
                throw new \RuntimeException('buzón lleno (simulado)');
            }
            self::$correos[] = $env;

            return true;
        });

        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
    }

    public function tearDown(): void
    {
        Auth::forgetCache();
        Mailer::setTransport(null);
        self::$correos = [];
        self::$fallan = [];
        unset($_GET, $_POST, $_FILES);
        self::cleanupResiduals();
        Mailer::setConfigOverride(null);
    }

    // =====================================================================
    // La puerta: la forma correcta de invitar/recordar
    // =====================================================================

    public function testElRecordatorioConCedulaNoSeMandaNiUnCorreo(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'cedula');
        $id = self::crear('V-1', 'ana@test.local');
        FormParticipant::markInvited($id);

        $out = self::llamar('remind');

        assertFalse($out['json']['ok'] ?? true, 'con cédula el recordatorio no se hace');
        assertEquals(0, count(self::$correos), 'y no sale ni un correo');
        assertTrue(
            str_contains((string) ($out['json']['message'] ?? ''), 'Código'),
            'el mensaje dice dónde está el enlace: ' . (string) ($out['json']['message'] ?? '')
        );
    }

    public function testElRecordatorioSinSmtpNoSeMarcaANadieYSeDicePorQue(): void
    {
        Mailer::setTransport(null);
        Mailer::setConfigOverride(['host' => '', 'from_address' => '']);
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        $id = self::crear('', 'ana@test.local');
        FormParticipant::markInvited($id);

        $out = self::llamar('remind');

        Mailer::setConfigOverride(null);

        assertFalse($out['json']['ok'] ?? true);
        assertTrue(
            str_contains((string) ($out['json']['message'] ?? ''), 'MAIL_HOST'),
            'el aviso nombra la variable que falta: ' . (string) ($out['json']['message'] ?? '')
        );
    }

    public function testElRecordatorioNoSeMandaEnLaListaDeOtro(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        $id = self::crear('', 'ana@test.local');
        FormParticipant::markInvited($id);
        Auth::assumeLoggedIn((int) self::$otroId);
        Auth::forgetCache();

        $out = self::llamar('remind');

        assertEquals(403, $out['halt']['code'] ?? 0);
        assertEquals(0, count(self::$correos), 'ni siquiera un correo');
    }

    public function testElRecordatorioEnUnFormularioFueraDeRosterDa404(): void
    {
        self::modo(FormSettings::ACCESS_PUBLIC, 'correo');
        $id = self::crear('', 'ana@test.local');
        FormParticipant::markInvited($id);

        $out = self::llamar('remind');

        assertEquals(404, $out['halt']['code'] ?? 0);
        assertEquals(0, count(self::$correos));
    }

    // =====================================================================
    // La frontera: invitado, sin responder
    // =====================================================================

    public function testSoloSeRecuerdaAQuienFueInvitadoYNoRespondio(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');

        // A: invitada, sin responder → SÍ recibe recordatorio.
        $a = self::crear('', 'ana@test.local');
        FormParticipant::markInvited($a);

        // B: sin invitación (el botón de invitar es su camino, no este).
        self::crear('', 'sin-invitar@test.local');

        // C: invitada pero ya respondió.
        $c = self::crear('', 'lluis@test.local');
        FormParticipant::markInvited($c);
        FormParticipant::marcarRespondido($c);

        $out = self::llamar('remind');

        assertTrue($out['json']['ok'] ?? false, (string) ($out['json']['message'] ?? ''));
        assertEquals(1, count(self::$correos), 'solo a la invitada que no ha respondido');
        assertEquals('ana@test.local', (string) self::$correos[0]['to'][0]);

        $cuerpo = (string) self::$correos[0]['html'];
        assertTrue(str_contains($cuerpo, 'Recordatorio'), 'el asunto/título dice que es un recordatorio');
        assertTrue(
            !str_contains($cuerpo, '/participants'),
            'y el correo no lleva la pantalla de la lista'
        );

        assertEquals(1, FormParticipant::pendingReminderCount((int) self::$formId), 'solo la invitada queda por recordar');
    }

    public function testAQuienRespondeDejaDeRecordarsele(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        $a = self::crear('', 'ana@test.local');
        FormParticipant::markInvited($a);
        FormParticipant::marcarRespondido($a);

        $out = self::llamar('remind');

        assertTrue($out['json']['ok'] ?? false, 'no es un fallo: no hay a quién');
        assertEquals(0, count(self::$correos), 'a quien respondió no se le escribe');
        assertTrue(
            str_contains((string) ($out['json']['message'] ?? ''), 'No quedaba nadie'),
            'y lo dice: ' . (string) ($out['json']['message'] ?? '')
        );
    }

    // =====================================================================
    // Repetible, con contador
    // =====================================================================

    public function testElRecordatorioEsRepetibleYSumaElContador(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        $a = self::crear('', 'ana@test.local');
        FormParticipant::markInvited($a);

        $primera = self::llamar('remind');
        assertTrue($primera['json']['ok'] ?? false, (string) ($primera['json']['message'] ?? ''));
        assertEquals(1, count(self::$correos), 'el primero sale');
        $fila = self::fila('ana@test.local');
        assertNotNull($fila['reminded_at'] ?? null, 'y queda marcado: reminded_at no es NULL');
        assertEquals(1, (int) ($fila['reminder_count'] ?? 0), 'con el contador a uno');
        $primeraFecha = (string) $fila['reminded_at'];

        // A diferencia de la invitación, el segundo clic SÍ vuelve a escribir.
        $segunda = self::llamar('remind');
        assertTrue($segunda['json']['ok'] ?? false, (string) ($segunda['json']['message'] ?? ''));
        assertEquals(2, count(self::$correos), 'el segundo recordatorio también sale');

        $fila = self::fila('ana@test.local');
        assertEquals(2, (int) ($fila['reminder_count'] ?? 0), 'y el contador sube a dos');
        assertTrue(
            (string) $fila['reminded_at'] >= $primeraFecha,
            'la fecha del último envío avanza (o se queda, si cayeron en el mismo segundo)'
        );
    }

    public function testLaTandaRotaYEmpiezaPorQuienNuncaHaRecibidoNinguno(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        foreach (['a', 'b', 'c'] as $quien) {
            $id = self::crear('', $quien . '@test.local');
            FormParticipant::markInvited($id);
        }

        self::llamar('remind');
        assertEquals(3, count(self::$correos), 'la primera tanda va a los tres');

        // Aparece alguien nuevo: entra primero, porque es el único sin recordar.
        $d = self::crear('', 'd@test.local');
        FormParticipant::markInvited($d);

        self::llamar('remind');

        // El recordatorio es repetible: la segunda tanda vuelve a los tres que ya
        // llevaban uno (siguen sin responder) y además al nuevo, o sea 3 + 4 = 7.
        assertEquals(7, count(self::$correos), 'correos=' . json_encode(array_map(static fn(array $e) => $e['to'][0] ?? '?', self::$correos)));
        assertEquals(
            'd@test.local',
            (string) self::$correos[3]['to'][0],
            'el recién llegado va primero: reminded_at IS NULL ordena delante'
        );

        foreach (['a', 'b', 'c'] as $quien) {
            assertEquals(
                2,
                (int) (self::fila($quien . '@test.local')['reminder_count'] ?? 0),
                'los que ya estaban siguen: ' . $quien . ' se anota el segundo'
            );
        }
        assertEquals(
            1,
            (int) (self::fila('d@test.local')['reminder_count'] ?? 0),
            'd solo lleva uno: su primera tanda ES la segunda, y el contador cuenta envíos, no tandas'
        );
    }

    // =====================================================================
    // El calendario del formulario, si existe
    // =====================================================================

    public function testElCorreoCitaLaFechaDeCierreCuandoExiste(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        self::ajuste('schedule', ['open_at' => null, 'close_at' => '2026-12-31 23:59']);
        $id = self::crear('', 'ana@test.local');
        FormParticipant::markInvited($id);

        self::llamar('remind');

        assertEquals(1, count(self::$correos));
        assertTrue(
            str_contains((string) self::$correos[0]['html'], '31/12/2026 23:59'),
            'el correo cita la fecha de cierre en la zona de la app'
        );
    }

    public function testSinFechaDeCierreNoApareceUnAvisoColgado(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        $id = self::crear('', 'ana@test.local');
        FormParticipant::markInvited($id);

        self::llamar('remind');

        assertEquals(1, count(self::$correos));
        assertTrue(
            !str_contains((string) self::$correos[0]['html'], 'cierra el'),
            'sin fecha no se pinta la línea del cierre'
        );
    }

    // =====================================================================
    // El tope y los fallos
    // =====================================================================

    public function testLaTandaDeRecordatoriosSeCortaEnCincuentaYElBotonDiceCuantosQuedan(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        for ($i = 1; $i <= 60; $i++) {
            $id = self::crear('', 'p' . $i . '@test.local');
            FormParticipant::markInvited($id);
        }

        $out = self::llamar('remind');

        assertEquals(50, count(self::$correos), 'una tanda, no la lista entera');
        // `pendingReminderCount` NO baja: el recordatorio es repetible, así que los
        // 50 enviados siguen siendo no-respondidos y siguen «por recordar». Lo que
        // mide el «quedan 10» del mensaje es otra cosa: a cuántos no se llegó en
        // ESTA tanda.
        assertEquals(
            60,
            FormParticipant::pendingReminderCount((int) self::$formId),
            'los 60 siguen sin responder; el marcado no los saca de la lista'
        );
        assertTrue(
            str_contains((string) ($out['json']['message'] ?? ''), 'Quedan 10'),
            'el mensaje dice a cuántos no se llegó en esta tanda: ' . (string) ($out['json']['message'] ?? '')
        );
        assertTrue(
            isset($out['json']['html']['rosterInvites']),
            'y el hueco se repone con el contador nuevo'
        );
    }

    public function testLosRecordatoriosSeParanTrasTresFallosSeguidosYLosFallidosSiguenPendientes(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        for ($i = 1; $i <= 10; $i++) {
            $id = self::crear('', 'p' . $i . '@test.local');
            FormParticipant::markInvited($id);
        }
        self::$fallan = ['p1@test.local', 'p2@test.local', 'p3@test.local', 'p4@test.local', 'p5@test.local'];

        $out = self::llamar('remind');

        assertFalse($out['json']['ok'] ?? true, 'un envío que no sale no es un éxito');
        assertEquals(0, count(self::$correos), 'y se para antes de marear al servidor');
        assertEquals(
            10,
            FormParticipant::pendingReminderCount((int) self::$formId),
            'los diez siguen pendientes: la tanda que viene los recoge'
        );
        assertTrue(
            str_contains((string) ($out['json']['message'] ?? ''), '3 fallos'),
            'el mensaje dice cuántos fallos y por qué se paró: ' . (string) ($out['json']['message'] ?? '')
        );
    }

    public function testUnFalloSueltoNoFallaLaTandaDeRecordatorios(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        for ($i = 1; $i <= 5; $i++) {
            $id = self::crear('', 'p' . $i . '@test.local');
            FormParticipant::markInvited($id);
        }
        self::$fallan = ['p1@test.local', 'p3@test.local'];

        $out = self::llamar('remind');

        assertFalse($out['json']['ok'] ?? true, 'el aviso dice que algo falló');
        assertEquals(3, count(self::$correos), 'los otros tres salieron');
        // Lo que de verdad importa del envío parcial: los que salieron quedan
        // marcados (contador 1) y los que fallaron NO (contador 0), para que el
        // reintento los recoja. Un `markReminded()` antes del `send()` marcaría los
        // cinco y este test lo vería.
        assertEquals(0, (int) (self::fila('p1@test.local')['reminder_count'] ?? 0), 'el que falló no se marca');
        assertEquals(1, (int) (self::fila('p2@test.local')['reminder_count'] ?? 0), 'el que salió sí');
        assertEquals(0, (int) (self::fila('p3@test.local')['reminder_count'] ?? 0), 'el otro que falló tampoco');
        assertEquals(1, (int) (self::fila('p4@test.local')['reminder_count'] ?? 0));
        assertEquals(1, (int) (self::fila('p5@test.local')['reminder_count'] ?? 0));
        assertTrue(
            str_contains((string) ($out['json']['message'] ?? ''), '2 fallaron'),
            'y el mensaje cuenta los fallos: ' . (string) ($out['json']['message'] ?? '')
        );
    }

    // =====================================================================
    // Utilidades
    // =====================================================================

    private static function crear(string $cedula, string $correo): int
    {
        $res = FormParticipant::create((int) self::$formId, 'correo', [
            'cedula' => $cedula,
            'correo' => $correo,
            'nombre' => 'N',
        ]);

        return (int) ($res['id'] ?? 0);
    }

    private static function fila(string $correo): array
    {
        $fila = Database::getInstance(Config::db())->fetch(
            'SELECT reminded_at, reminder_count FROM form_participants
              WHERE form_id = :f AND correo = :c',
            ['f' => (int) self::$formId, 'c' => $correo]
        );

        return is_array($fila) ? $fila : [];
    }

    private static function modo(string $modo, string $identificador): void
    {
        self::ajuste('access', ['mode' => $modo, 'identifier' => $identificador]);
    }

    /** Escribe un bloque del `settings` JSON del formulario, sin pisar el resto. */
    private static function ajuste(string $bloque, array $valor): void
    {
        $db = Database::getInstance(Config::db());
        $fila = $db->fetch('SELECT settings FROM forms WHERE id = :id', ['id' => (int) self::$formId]);
        $ajustes = $fila !== null && is_string($fila['settings']) ? (json_decode($fila['settings'], true) ?: []) : [];
        $ajustes[$bloque] = $valor;
        $db->execute(
            'UPDATE forms SET settings = :s WHERE id = :id',
            ['s' => json_encode($ajustes), 'id' => (int) self::$formId]
        );
    }

    private static function llamar(string $accion, array $post = []): array
    {
        $_POST = $post;
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        Session::set('_csrf_token', 'token-de-prueba');
        if (!array_key_exists('_token', $_POST)) {
            $_POST['_token'] = 'token-de-prueba';
        }
        Request::setRequestBodyForTests(null);
        Request::resetJsonState();
        Response::setRecordHalt(false);
        Response::setRecordHalt(true);

        $controlador = new \App\Controllers\ParticipantController();
        ob_start();
        try {
            $controlador->{$accion}((int) self::$formId);
        } finally {
            $html = (string) ob_get_clean();
        }

        $halt = Response::lastHalt();
        Response::setRecordHalt(false);

        $json = [];
        if ($html !== '' && str_starts_with(ltrim($html), '{')) {
            $decodificado = json_decode(ltrim($html), true);
            if (is_array($decodificado)) {
                $json = $decodificado;
            }
        }

        return ['json' => $json, 'halt' => $halt, 'html' => $html];
    }

    private static function cleanupResiduals(): void
    {
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'record-%'");
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'recordotro-%'");
        self::$ownerId = null;
        self::$otroId = null;
        self::$formId = null;
    }
}
