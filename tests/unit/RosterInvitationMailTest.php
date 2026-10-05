<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;
use App\Core\PublicUrl;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Form;
use App\Models\FormParticipant;
use App\Models\User;
use App\Services\FormSettings;

/**
 * FASE 10 — el correo de invitación del listado.
 *
 * Lo que se comprueba son las cuatro cosas que se rompen en silencio y que ninguna
 * da error visible en la pantalla:
 *
 *  1. **La importación NO manda correo.** Es una promesa del plan, y promises no se
 *     comprueban leyendo el código: se cuentan los envelopes con el `Mailer` en un
 *     sumidero. Aquí se comprueba para las dos puertas (identificador `correo` y
 *     `cédula`), porque con `cédula` el botón no existe y un test que solo mirara
 *     ese caso pasaría verde con el botón mandando 400 correos.
 *  2. **Solo se invita con identificador `correo`.** Con los otros dos el enlace va
 *     en la columna «Código». La puerta está en el CONTROLADOR, y se prueba
 *     llamando a la acción, no mirando la vista.
 *  3. **`invited_at` es el que evita el reenvío.** El botón es idempotente en el
 *     sentido que importa: dos clics no son dos correos para la misma persona, y lo
 *     que se marcó la primera vez no se mueve.
 *  4. **Un envío parcial no pierde a nadie.** Los que se mandaron se marcan y los
 *     que fallaron siguen pendientes; y para eso están los tres fallos seguidos, que
 *     paran la tanda sin marcarlos.
 *
 * Los correos se cuentan, no se simulan: `Mailer::setTransport()` guarda el sobre
 * entero, así que las aserciones miran el `to`, el asunto y el cuerpo de verdad.
 */
class RosterInvitationMailTest
{
    private static ?int $ownerId = null;
    private static ?int $otroId = null;
    private static ?int $formId = null;

    /**
     * Los sobres que han salido, en orden.
     *
     * @var list<array<string,mixed>>
     */
    private static array $correos = [];

    /** Direcciones a las que el transporte simulado va a lanzar. */
    private static array $fallan = [];

    /** @var list<string> */
    private static array $temporales = [];

    public function setUp(): void
    {
        self::cleanupResiduals();

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Inv ' . $rand, 'invit-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$otroId = User::create('Inv Otro ' . $rand, 'invitotro-' . $rand . '@test.local', 'clave-larga-123', 2);
        // `Form::create()` devuelve un ID, no una fila.
        self::$formId = Form::create((int) self::$ownerId, 'Inv ' . $rand);

        self::$correos = [];
        self::$fallan = [];

        // La configuración de correo se PUESTA, no heredada. Sin esto el test
        // depende del `.env` de quien lo ejecuta: en una instancia sin SMTP
        // configurado, `Mailer::send()` lanza «Remitente SMTP inválido» ANTES de
        // llegar al transporte de prueba, y los doce tests de fase 10 fallarían a
        // la vez con «se mandaron 0 invitaciones y 2 fallaron» sin que hubiera
        // cambiado una línea del código que vigilan.
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
    // La puerta: sin correo si el identificador no es el correo
    // =====================================================================

    public function testConCedulaNoSeMandaNiUnCorreo(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'cedula');
        FormParticipant::create((int) self::$formId, 'cedula', [
            'cedula' => 'V-1',
            'nombre' => 'Ana',
            'correo' => 'ana@test.local',
        ]);

        $out = self::llamar('invitations');

        assertFalse($out['json']['ok'] ?? true, 'con cédula el envío no se hace');
        assertEquals(0, count(self::$correos), 'y no sale ni un correo');
        assertEquals(
            0,
            FormParticipant::invitedCountForForm((int) self::$formId),
            'y nadie queda marcado como invitado'
        );
        assertTrue(
            str_contains((string) ($out['json']['message'] ?? ''), 'Código'),
            'el mensaje dice dónde está el enlace: ' . (string) ($out['json']['message'] ?? '')
        );
    }

    public function testSinSmtpNoSeMarcaANadieYSeDicePorQue(): void
    {
        // El transporte de `setUp()` hace que `isConfigured()` sea true aunque no
        // haya SMTP: por eso el transporte se apaga explícitamente aquí, que es lo
        // que hace una instalación con `MAIL_HOST` vacío.
        Mailer::setTransport(null);
        Mailer::setConfigOverride(['host' => '', 'from_address' => '']);
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        FormParticipant::create((int) self::$formId, 'correo', ['correo' => 'ana@test.local']);

        $out = self::llamar('invitations');

        Mailer::setConfigOverride(null);

        assertFalse($out['json']['ok'] ?? true);
        assertEquals(0, FormParticipant::invitedCountForForm((int) self::$formId));
        assertTrue(
            str_contains((string) ($out['json']['message'] ?? ''), 'MAIL_HOST'),
            'el aviso nombra la variable que falta: ' . (string) ($out['json']['message'] ?? '')
        );
    }

    public function testQuienNoEsDueñoNoMandaCorreoDeLaListaDeOtro(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        FormParticipant::create((int) self::$formId, 'correo', ['correo' => 'ana@test.local']);
        Auth::assumeLoggedIn((int) self::$otroId);
        Auth::forgetCache();

        $out = self::llamar('invitations');

        assertEquals(403, $out['halt']['code'] ?? 0);
        assertEquals(0, count(self::$correos), 'ni siquiera un correo');
        assertEquals(0, FormParticipant::invitedCountForForm((int) self::$formId));
    }

    public function testUnFormularioQueNoEstaEnRosterDa404YNoMandaCorreo(): void
    {
        self::modo(FormSettings::ACCESS_PUBLIC, 'correo');
        FormParticipant::create((int) self::$formId, 'correo', ['correo' => 'ana@test.local']);

        $out = self::llamar('invitations');

        assertEquals(404, $out['halt']['code'] ?? 0);
        assertEquals(0, count(self::$correos));
    }

    // =====================================================================
    // El envío
    // =====================================================================

    public function testSeMandaUnoPorPersonaConSuEnlaceYQuedaMarcado(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        FormParticipant::create((int) self::$formId, 'correo', [
            'correo' => 'ana@test.local',
            'nombre' => 'Ana',
        ]);
        FormParticipant::create((int) self::$formId, 'correo', [
            'correo' => 'luis@test.local',
            'nombre' => 'Luis',
        ]);

        $out = self::llamar('invitations');

        assertTrue($out['json']['ok'] ?? false, (string) ($out['json']['message'] ?? ''));
        assertEquals(2, count(self::$correos), 'uno por persona, y a los dos');

        $para = array_map(static fn (array $e): string => (string) $e['to'][0], self::$correos);
        sort($para);
        assertEquals(['ana@test.local', 'luis@test.local'], $para, 'cada uno al suyo');

        $fila = FormParticipant::findByIdentifier((int) self::$formId, 'correo', 'ana@test.local');
        $code = (string) ($fila['code'] ?? '');
        $url = PublicUrl::rosterUrl((string) Form::find((int) self::$formId)['slug'], $code);

        $cuerpo = (string) self::$correos[0]['html'];
        assertTrue(str_contains($cuerpo, htmlspecialchars($url, ENT_QUOTES, 'UTF-8')), 'el correo lleva su enlace');
        assertTrue(
            str_contains((string) self::$correos[0]['data'], $url),
            'y también en la parte de texto plano, para el cliente que no pinta HTML'
        );
        assertTrue(str_contains($cuerpo, 'Ana'), 'saluda por su nombre');
        assertTrue(
            !str_contains($cuerpo, (string) Form::find((int) self::$formId)['id'] . '/participants'),
            'y el correo no lleva la pantalla de la lista'
        );

        assertEquals(2, FormParticipant::invitedCountForForm((int) self::$formId), 'los dos quedan marcados');
        assertEquals(0, FormParticipant::pendingInviteCount((int) self::$formId), 'y no queda nadie pendiente');
    }

    public function testSinNombreNoSaludaANobodyYElCorreoSigueSiendoUtil(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        FormParticipant::create((int) self::$formId, 'correo', ['correo' => 'sin-nombre@test.local']);

        $out = self::llamar('invitations');

        assertTrue($out['json']['ok'] ?? false);
        assertEquals(1, count(self::$correos));
        $cuerpo = (string) self::$correos[0]['html'];
        assertTrue(
            !str_contains($cuerpo, 'Hola ,'),
            '«Hola ,» es peor que no saludar'
        );
        assertTrue(str_contains($cuerpo, 'Responder el formulario'), 'y el botón sigue ahí');
    }

    public function testNoSeVuelveAMandarAQuienYaRecibioElCorreo(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        FormParticipant::create((int) self::$formId, 'correo', ['correo' => 'ana@test.local']);

        $primera = self::llamar('invitations');
        assertTrue($primera['json']['ok'] ?? false);
        assertEquals(1, count(self::$correos));

        $marcado = (string) (FormParticipant::findByIdentifier((int) self::$formId, 'correo', 'ana@test.local')['invited_at'] ?? '');

        $segunda = self::llamar('invitations');

        assertEquals(1, count(self::$correos), 'el segundo clic no manda nada: ya estaba invitado');
        assertEquals(
            $marcado,
            (string) (FormParticipant::findByIdentifier((int) self::$formId, 'correo', 'ana@test.local')['invited_at'] ?? ''),
            'y la fecha del primer envío no se mueve'
        );
        assertTrue(
            str_contains((string) ($segunda['json']['message'] ?? ''), 'No quedaba nadie'),
            'el segundo clic lo dice: ' . (string) ($segunda['json']['message'] ?? '')
        );
    }

    public function testAQuienYaRespondioNoSeLeInvita(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        FormParticipant::create((int) self::$formId, 'correo', ['correo' => 'ana@test.local']);
        FormParticipant::create((int) self::$formId, 'correo', ['correo' => 'luis@test.local']);
        $respondio = (int) (FormParticipant::findByIdentifier((int) self::$formId, 'correo', 'luis@test.local')['id'] ?? 0);
        FormParticipant::marcarRespondido($respondio);

        $out = self::llamar('invitations');

        assertTrue($out['json']['ok'] ?? false);
        assertEquals(1, count(self::$correos), 'solo a quien no respondió');
        assertEquals('ana@test.local', (string) self::$correos[0]['to'][0]);
    }

    public function testUnaFilaSinCorreoNoEntraEnElLote(): void
    {
        // El filtro `correo <> ''` de `pendingInvites()` es la segunda línea de
        // defense: la primera es `planDeReidentificacion()`, que no deja cambiar a
        // un formulario a identificador «correo» si alguna fila no lo tiene. El
        // estado que este test monta —una fila que se guardó cuando el
        // identificador era cédula, donde el correo era opcional— es por tanto
        // alcanzable en la BASE DE DATOS aunque la app no lo produzca: por eso la
        // aserción va sobre el modelo y no sobre el botón, que en ese caso sí
        // enviaría el resto.
        //
        // Lo que NO puede pasar es que esa fila se cuente como fallo: si entrara
        // en el lote, `Mailer::send()` lanzaría «Sin destinatarios válidos» y el
        // contador de fallos dejaría de significar «el servidor de correo está
        // caído», que es lo que dice el mensaje.
        self::modo(FormSettings::ACCESS_ROSTER, 'cedula');
        FormParticipant::create((int) self::$formId, 'cedula', [
            'cedula' => 'V-1',
            'correo' => 'ana@test.local',
        ]);
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-2']);

        $pendientes = FormParticipant::pendingInvites((int) self::$formId, 10);

        assertEquals(1, count($pendientes), 'la que no tiene correo no está en el lote');
        assertEquals('ana@test.local', (string) ($pendientes[0]['correo'] ?? ''));
        assertEquals(
            1,
            FormParticipant::pendingInviteCount((int) self::$formId),
            'y el contador del botón tampoco la cuenta, o diría que hay dos'
        );
    }

    // =====================================================================
    // El tope y los fallos
    // =====================================================================

    public function testLaTandaSeCortaEnCincuentaYElBotonDiceCuantosQuedan(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        for ($i = 1; $i <= 60; $i++) {
            FormParticipant::create((int) self::$formId, 'correo', [
                'correo' => 'p' . $i . '@test.local',
                'nombre' => 'P' . $i,
            ]);
        }

        $out = self::llamar('invitations');

        assertEquals(50, count(self::$correos), 'una tanda, no la lista entera');
        assertEquals(10, FormParticipant::pendingInviteCount((int) self::$formId), 'y los que quedan son ciertos');
        assertTrue(
            str_contains((string) ($out['json']['message'] ?? ''), 'Quedan 10'),
            'el mensaje dice cuántos quedan: ' . (string) ($out['json']['message'] ?? '')
        );
        assertTrue(
            isset($out['json']['html']['rosterInvites']),
            'y el botón se repone con el contador nuevo'
        );
        assertTrue(
            str_contains((string) $out['json']['html']['rosterInvites'], '(10)'),
            'que en el botón es el número que se ve'
        );

        // Y el ALWAYS-first: el lote es el principio de la lista, no una muestra al
        // azar, para que quien recibe el correo sea siempre el primero de la cola.
        $para = array_map(static fn (array $e): string => (string) $e['to'][0], self::$correos);
        assertEquals('p1@test.local', $para[0], 'el primero de la lista va primero');
        assertEquals('p50@test.local', $para[49], 'y el corte es por identificador, no alfabético');
    }

    public function testSeParaTrasTresFallosSegidosYLosFallidosSiguenPendientes(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        for ($i = 1; $i <= 10; $i++) {
            FormParticipant::create((int) self::$formId, 'correo', ['correo' => 'p' . $i . '@test.local']);
        }
        self::$fallan = ['p1@test.local', 'p2@test.local', 'p3@test.local', 'p4@test.local', 'p5@test.local'];

        $out = self::llamar('invitations');

        assertFalse($out['json']['ok'] ?? true, 'un envío que no sale no es un éxito');
        assertEquals(0, count(self::$correos), 'y se para antes de marear al servidor');
        assertEquals(0, FormParticipant::invitedCountForForm((int) self::$formId), 'nadie marcado');
        assertEquals(
            10,
            FormParticipant::pendingInviteCount((int) self::$formId),
            'los diez siguen pendientes: la tanda que viene los recoge'
        );
        assertTrue(
            str_contains((string) ($out['json']['message'] ?? ''), '3 fallos'),
            'el mensaje dice cuántos fallos y por qué se paró: ' . (string) ($out['json']['message'] ?? '')
        );
    }

    public function testUnFalloSueltoNoFallaLaTandaYElQueFalloVuelveALaCola(): void
    {
        // Un buzón lleno es normal en un envío en lote y no dice nada del servidor:
        // por eso el corte es por fallos SEGUIDOS y no por número de fallos.
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        for ($i = 1; $i <= 5; $i++) {
            FormParticipant::create((int) self::$formId, 'correo', ['correo' => 'p' . $i . '@test.local']);
        }
        self::$fallan = ['p1@test.local', 'p3@test.local'];

        $out = self::llamar('invitations');

        assertFalse($out['json']['ok'] ?? true, 'el aviso dice que algo falló');
        assertEquals(3, count(self::$correos), 'los otros tres salieron');
        assertEquals(3, FormParticipant::invitedCountForForm((int) self::$formId), 'y solo se marcan los que salieron');
        assertEquals(
            2,
            FormParticipant::pendingInviteCount((int) self::$formId),
            'los dos que fallaron vuelven a estar pendientes: un reintento los coge'
        );
        assertTrue(
            str_contains((string) ($out['json']['message'] ?? ''), '2 fallaron'),
            'y el mensaje cuenta los fallos: ' . (string) ($out['json']['message'] ?? '')
        );
    }

    // =====================================================================
    // La importación, que no es lo mismo
    // =====================================================================

    public function testImportarNoMandaCorreoNiConIdentificadorCorreo(): void
    {
        // El caso fuerte: si el botón de invitar no existiera por ser el
        // identificador `correo`, este test pasaría verde sin comprobar nada. La
        // puerta hay que probarla con el identificador que SÍ tiene invitaciones.
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');
        $xlsx = self::rejilla(
            ['Correo', 'Nombre'],
            [
                ['ana@test.local', 'Ana'],
                ['luis@test.local', 'Luis'],
            ]
        );
        $_FILES['archivo'] = [
            'name'     => 'lista.xlsx',
            'type'     => '',
            'tmp_name' => $xlsx,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) filesize($xlsx),
        ];

        $out = self::llamar('import', ['modo' => \App\Services\ParticipantImporter::MODO_FUSIONAR]);

        assertTrue($out['json']['ok'] ?? false, (string) ($out['json']['message'] ?? ''));
        assertEquals(2, FormParticipant::countForForm((int) self::$formId), 'los dos entraron');
        assertEquals(0, count(self::$correos), 'y no salió NI UN correo');
        assertEquals(
            0,
            FormParticipant::invitedCountForForm((int) self::$formId),
            'y nadie quedó marcado: importar no es invitar'
        );
        assertEquals(
            2,
            FormParticipant::pendingInviteCount((int) self::$formId),
            'los dos quedan pendientes, que es lo que el botón va a contar'
        );
    }

    // =====================================================================
    // Utilidades
    // =====================================================================

    private static function modo(string $modo, string $identificador): void
    {
        $db = Database::getInstance(Config::db());
        $fila = $db->fetch('SELECT settings FROM forms WHERE id = :id', ['id' => (int) self::$formId]);
        $ajustes = $fila !== null && is_string($fila['settings']) ? (json_decode($fila['settings'], true) ?: []) : [];
        $ajustes['access'] = ['mode' => $modo, 'identifier' => $identificador];
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

    private static function rejilla(array $cabeceras, array $filas): string
    {
        $letras = ['A', 'B', 'C', 'D', 'E', 'F'];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $n = 1;
        foreach (array_merge([$cabeceras], $filas) as $fila) {
            $celdas = '';
            foreach (array_values($fila) as $i => $valor) {
                if ($valor === '') {
                    continue;
                }
                $celdas .= '<c r="' . ($letras[$i] ?? 'Z') . $n . '" t="inlineStr"><is><t>'
                    . htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                    . '</t></is></c>';
            }
            $xml .= '<row r="' . $n . '">' . $celdas . '</row>';
            $n++;
        }
        $xml .= '</sheetData></worksheet>';

        $ruta = sys_get_temp_dir() . '/vf-roster-inv-' . bin2hex(random_bytes(6)) . '.xlsx';
        self::$temporales[] = $ruta;
        $zip = new \ZipArchive();
        if ($zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            fail('no se pudo crear el .xlsx de prueba');
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->addFromString(
            'xl/workbook.xml',
            '<?xml version="1.0"?>'
            . '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Lista" sheetId="1" r:id="rId1"/></sheets></workbook>'
        );
        $zip->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>'
        );
        $zip->close();

        return $ruta;
    }

    private static function cleanupResiduals(): void
    {
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'invit-%'");
        self::$ownerId = null;
        self::$otroId = null;
        self::$formId = null;
        foreach (self::$temporales as $ruta) {
            @unlink($ruta);
        }
        self::$temporales = [];
    }
}
