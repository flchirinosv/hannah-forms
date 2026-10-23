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
use App\Models\Field;
use App\Models\Form;
use App\Models\FormParticipant;
use App\Models\Submission;
use App\Models\User;
use App\Services\FormSettings;
use App\Services\ParticipantImporter;
use App\Services\RosterTemplate;

/**
 * Fase 3 — `ParticipantController`.
 *
 * Lo que se comprueba aquí es la puerta y el contrato con el navegador, no la
 * importación (esa está en `ParticipantImporterTest`). Cuatro cosas, y las cuatro
 * se rompen en silencio:
 *
 *  1. **La autorización.** `rosterForm()` reutiliza `Access::canEdit()`, el mismo
 *     permiso que la allowlist. Un `can()` nuevo pondría «puede gestionar la lista»
 *     solo aquí, y en cuanto saliera otro sitio quetocara la lista se olvidaría del
 *     permiso.
 *  2. **El modo `roster` es condición de existencia, no de autorización.** En los
 *     otros tres modos la lista no es la puerta: editar una lista que no manda no
 *     cambia nada, y una lista que parece vigente y no lo es es peor que no
 *     tenerla. Por eso responde **404** y no 403: quién puede ver el formulario ya
 *     lo sabe, si no que ver la respuesta.
 *  3. **`form_id` en el WHERE.** Un POST con el id de participante de OTRO
 *     formulario no edita a nadie. Es la mitad de la autorización, igual que el
 *     `form_id` de `FormAccess::findValidToken()`.
 *  4. **La importación no manda correo.** No es una promesa: se cuenta con el
 *     `Mailer` apontando a un sumidero y se mira que no haya envelopes.
 */
class ParticipantControllerTest
{
    private static ?int $ownerId = null;
    private static ?int $formId = null;
    private static ?int $otroId = null;
    /** @var list<string> */
    private static array $temporales = [];
    /** @var list<string> */
    private static array $correos = [];

    public function setUp(): void
    {
        self::cleanupResiduals();

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Roster Ctl ' . $rand, 'rosterctl-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$otroId  = User::create('Roster Otro ' . $rand, 'rosterotro-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$formId  = Form::create((int) self::$ownerId, 'Roster Ctl ' . $rand);

        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
    }

    public function tearDown(): void
    {
        Auth::forgetCache();
        Mailer::setTransport(null);
        self::$correos = [];
        unset($_GET, $_POST, $_FILES);
        self::cleanupResiduals();
        foreach (self::$temporales as $ruta) {
            @unlink($ruta);
        }
        self::$temporales = [];
    }

    // =====================================================================
    // La puerta
    // =====================================================================

    public function testSinSesionNoSeLlegaALaLista(): void
    {
        Session::remove('user_id');
        Auth::forgetCache();

        $out = self::llamar('index', (int) self::$formId, []);

        assertEquals('/login', $out['halt']['url'] ?? '', 'sin sesión, a iniciar sesión');
    }

    public function testElQueNoEsDueñoNoPuedeNiVerLaLista(): void
    {
        Auth::assumeLoggedIn((int) self::$otroId);
        Auth::forgetCache();

        $out = self::llamar('index', (int) self::$formId, []);

        assertEquals(403, $out['halt']['code'] ?? 0, 'es de otro: 403 y a otra parte');
    }

    public function testElDueñoTampocoPuedeEnUnFormularioQueNoEsSuyo(): void
    {
        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
        // `Form::create()` devuelve un id, no una fila: leer `$otro['id']` de un
        // entero da null y la llamada acaba en `/forms/0`, que responde 404 y hace
        // pasar este test probando otra cosa.
        $ajeno = Form::create((int) self::$otroId, 'Ajeno ' . bin2hex(random_bytes(3)));

        $out = self::llamar('index', $ajeno, []);

        assertEquals(403, $out['halt']['code'] ?? 0);
        assertEquals(
            0,
            FormParticipant::countForForm($ajeno),
            'y ni siquiera escribe en la lista ajena'
        );
    }

    public function testUnFormularioQueNoEstaEnModoRosterDa404(): void
    {
        // No es 403 porque no es un problema de permisos: es que la lista no existe
        // para ese formulario. Con `public` la lista se puede guardar y se puede
        // consultar por la BD, y editarla no cambia a quién puede responder.
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1']);

        $out = self::llamar('index', (int) self::$formId, []);

        assertEquals(404, $out['halt']['code'] ?? 0);
    }

    public function testCambiarAModoPublicoNoBorraLaLista(): void
    {
        self::enRoster();
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana']);
        self::modo('public');

        assertEquals(
            1,
            FormParticipant::countForForm((int) self::$formId),
            'volver atrás es un cambio de ajustes, no un deploy: los datos se quedan'
        );
        assertEquals(404, (self::llamar('index', (int) self::$formId, []))['halt']['code'] ?? 0);
    }

    // =====================================================================
    // El listado
    // =====================================================================

    public function testElListadoTraeLaGenteElCatalogoYElTotal(): void
    {
        self::enRoster();
        FormParticipant::ensureAttribute((int) self::$formId, 'Sede');
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana'], ['sede' => 'Caracas']);
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-2', 'nombre' => 'Luis']);

        $out = self::llamar('index', (int) self::$formId, []);
        $d = $out['json'];

        assertTrue($d['ok']);
        assertEquals('cedula', $d['identifier'], 'dice con qué se identifica la gente en ESTE formulario');
        assertEquals(2, $d['total']);
        assertEquals(2, count($d['participantes']), 'sin paginar, todos');
        assertEquals(
            'Luis',
            (string) $d['participantes'][1]['nombre'],
            'el de menor identificador va primero'
        );
        assertEquals('Caracas', $d['participantes'][0]['data']['sede'] ?? '', 'los atributos vienen dentro');
        assertEquals(1, count($d['atributos']));

        // Los motivos van traducidos en la respuesta y no como códigos: quien pinta
        // el informe de la importación los muestra tal cual, y un `identificador_vacio`
        // en pantalla no le dice nada a nadie.
        foreach ([FormParticipant::MOTIVO_VACIO, FormParticipant::MOTIVO_DUPLICADO, FormParticipant::MOTIVO_CORREO] as $motivo) {
            assertTrue(
                isset($d['motivos'][$motivo]) && !str_contains((string) $d['motivos'][$motivo], '_'),
                'el motivo ' . $motivo . ' llega traducido'
            );
        }
    }

    public function testElListadoBuscaYPagina(): void
    {
        self::enRoster();
        for ($i = 1; $i <= 5; $i++) {
            FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-' . $i, 'nombre' => 'P' . $i]);
        }

        $d = self::llamar('index', (int) self::$formId, [], ['q' => 'V-3'])['json'];
        assertEquals(1, $d['total'], 'la búsqueda y el total tienen que contar lo mismo');
        assertEquals(1, count($d['participantes']));

        // El tamaño de página NO se lee del query: el servidor lo fija. Si el
        // cliente lo pudiera elegir, un `per_page=100000` se come la tabla entera
        // en memoria y el `total` deja de cuadrar con lo que se pinta.
        $d = self::llamar('index', (int) self::$formId, [], ['per_page' => 2])['json'];
        assertEquals(50, $d['per_page'], 'el cliente no elige el tamaño de página');
        assertEquals(1, $d['pages']);
        assertEquals(5, count($d['participantes']));

        for ($i = 6; $i <= 51; $i++) {
            FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-' . $i, 'nombre' => 'P' . $i]);
        }

        $d = self::llamar('index', (int) self::$formId, [], ['page' => 2])['json'];
        assertEquals(2, $d['page']);
        assertEquals(51, $d['total'], 'el total es de la lista entera, no de la página');
        assertEquals(2, $d['pages']);
        assertEquals(1, count($d['participantes']), 'la segunda página trae la que sobra');
        // Y es `V-9`, no `V-51`: el orden es por identificador COMO TEXTO, que es
        // lo que se guarda y lo que se busca. Con `V-1`…`V-51` eso pone `V-9` el
        // último de todos. Si algún día se ordena como número natural, esta línea
        // es la que se cae.
        assertEquals('V-9', (string) $d['participantes'][0]['identifier_value'], 'el orden es textual');
        assertEquals(50, count(self::llamar('index', (int) self::$formId, [])['json']['participantes']), 'la primera página trae 50');
    }

    // =====================================================================
    // Escritura de uno
    // =====================================================================

    public function testElAltaManualDaDeAltaYDevuelveElCodigo(): void
    {
        self::enRoster();

        $out = self::llamar('store', (int) self::$formId, [
            'cedula'    => 'V-1',
            'nombre'    => 'Ana',
            'correo'    => 'ana@test.local',
        ]);

        assertTrue($out['json']['ok'], (string) ($out['json']['error'] ?? $out['json']['message'] ?? ''));
        $fila = FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-1');
        assertNotNull($fila);
        assertTrue(FormParticipant::codeValido((string) $fila['code']), 'y se lleva su código');
    }

    public function testUnDuplicadoEnElAltaDiceAlgoYNoRepite(): void
    {
        self::enRoster();
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1']);

        $out = self::llamar('store', (int) self::$formId, ['cedula' => 'V-1']);

        assertFalse($out['json']['ok']);
        assertTrue(
            str_contains((string) $out['json']['message'], 'V-1'),
            'el mensaje tiene que decir qué se repitió: ' . (string) $out['json']['message']
        );
        assertEquals(1, FormParticipant::countForForm((int) self::$formId));
    }

    public function testUnaClaveDeAtributoQueNoEstaEnElCatalogoNoSeEscribe(): void
    {
        // La frontera de seguridad del POST. Sin esto, cada etiqueta que se mande
        // crearía una columna nueva y el filtro de la analítica se llenaría de
        // columnas que nadie pidió.
        self::enRoster();

        $out = self::llamar('store', (int) self::$formId, [
            'cedula' => 'V-1',
            'data'   => ['inventado' => 'lo que sea', 'otro' => ['anidado']],
        ]);

        assertTrue($out['json']['ok']);
        $fila = FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-1');
        assertNotNull($fila);
        assertEquals([], FormParticipant::attributesOf((int) $fila['id']), 'no se guardó ningún atributo');
        assertEquals(0, count(FormParticipant::catalogFor((int) self::$formId)), 'ni se creó ninguna columna');
    }

    public function testElPostDeOtroFormularioNoEditaANadie(): void
    {
        self::enRoster();
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana']);

        // El id es de una lista de OTRO formulario, y quien lo manda es el dueño de
        // las dos. Es justo el caso que un `WHERE id = ?` a secas deja pasar.
        $formAjeno = Form::create((int) self::$ownerId, 'Segunda lista ' . bin2hex(random_bytes(3)));
        $ajeno = FormParticipant::create($formAjeno, 'cedula', ['cedula' => 'V-2', 'nombre' => 'Luis']);

        $out = self::llamar('update', (int) self::$formId, [
            'participant_id' => (string) $ajeno['id'],
            'cedula'         => 'V-9',
            'nombre'         => 'Hackeado',
        ]);

        assertFalse($out['json']['ok']);
        $sigue = FormParticipant::find((int) $ajeno['id']);
        assertNotNull($sigue, 'sigue existiendo');
        assertEquals($formAjeno, (int) $sigue['form_id'], 'y en su propio formulario');
        assertEquals('Luis', (string) $sigue['nombre'], 'el `form_id` en el WHERE es media autorización');
        assertEquals('V-2', (string) $sigue['cedula']);
    }

    public function testEditarNoCambiaElCodigo(): void
    {
        self::enRoster();
        $creada = FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana']);
        assertTrue($creada['ok']);
        // `create()` devuelve `ok/id/motivo`, no la fila: el código hay que leerlo.
        $fila = FormParticipant::find((int) $creada['id']);
        assertNotNull($fila);
        assertEquals(16, strlen((string) $fila['code']), 'y el código son 16 caracteres');

        self::llamar('update', (int) self::$formId, [
            'participant_id' => (string) $fila['id'],
            'cedula'         => 'V-1',
            'nombre'         => 'Ana María',
        ]);

        $despues = FormParticipant::find((int) $fila['id']);
        assertNotNull($despues);
        assertEquals('Ana María', (string) $despues['nombre'], 'el nombre sí cambia');
        assertEquals((string) $fila['code'], (string) $despues['code'], 'el código no: ya está repartido');
    }

    public function testElBorradoDeOtraListaNoBorraANadie(): void
    {
        self::enRoster();
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana']);

        $formAjeno = Form::create((int) self::$ownerId, 'Segunda lista ' . bin2hex(random_bytes(3)));
        $ajeno = FormParticipant::create($formAjeno, 'cedula', ['cedula' => 'V-9', 'nombre' => 'Luis']);

        $out = self::llamar('destroy', (int) self::$formId, ['participant_id' => (string) $ajeno['id']]);

        assertFalse($out['json']['ok'], 'un id de otra lista no se borra desde aquí');
        $sigue = FormParticipant::find((int) $ajeno['id']);
        assertNotNull($sigue, 'sigue existiendo');
        assertEquals('Luis', (string) $sigue['nombre']);
        assertEquals($formAjeno, (int) $sigue['form_id']);
    }

    public function testBorrarNoBorraLasRespuestas(): void
    {
        self::enRoster();
        $fila = FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1']);
        $campo = Field::create((int) self::$formId, 'text', 'Pregunta', '', '', false);
        $submision = Submission::create((int) self::$formId, '198.51.100.7');
        $db = Database::getInstance(Config::db());
        $db->execute(
            'UPDATE submissions SET participant_id = :pid WHERE id = :id',
            ['pid' => (int) $fila['id'], 'id' => $submision]
        );

        $out = self::llamar('destroy', (int) self::$formId, ['participant_id' => (string) $fila['id']]);

        assertTrue($out['json']['ok']);
        assertNull(FormParticipant::find((int) $fila['id']), 'la persona ya no está en la lista');
        $row = $db->fetch('SELECT participant_id FROM submissions WHERE id = :id', ['id' => $submision]);
        assertNotNull($row, 'pero lo que contestó sigue ahí');
        assertNull($row['participant_id'], 'y sin participante');
    }

    // =====================================================================
    // Importar y vaciar
    // =====================================================================

    public function testLaImportacionLeeElArchivoDelPOSTYLoDice(): void
    {
        self::enRoster();
        self::montarFichero();

        $out = self::llamar('import', (int) self::$formId, ['modo' => ParticipantImporter::MODO_FUSIONAR]);

        assertTrue($out['json']['ok'], (string) ($out['json']['message'] ?? ''));
        assertEquals(1, $out['json']['insertados']);
        assertTrue(FormParticipant::findByIdentifier((int) self::$formId, 'cedula', 'V-1') !== null);
    }

    public function testSinArchivoSeDiceQueNoHayArchivo(): void
    {
        self::enRoster();

        $out = self::llamar('import', (int) self::$formId, []);

        assertFalse($out['json']['ok']);
        assertTrue(
            str_contains((string) $out['json']['message'], 'archivo'),
            (string) $out['json']['message']
        );
    }

    public function testLaImportacionNoDisparaCorreo(): void
    {
        // La regla es «la importación nunca envía correo» y no se comprueba leyendo
        // el código: se cuenta. Un `Mailer::send()` colado dentro del bucle de filas
        // mandaría 1 correo con 1 fila y pasaría una revisión a ojo.
        self::enRoster();
        self::$correos = [];
        Mailer::setTransport(function (array $env): bool {
            self::$correos[] = (string) ($env['to'][0] ?? '');

            return true;
        });

        $xlsx = self::rejilla(
            ['Cédula', 'Nombre', 'Correo', 'Indicador'],
            [
                ['V-1', 'Ana', 'ana@test.local', 'IND-1'],
                ['V-2', 'Luis', 'luis@test.local', 'IND-2'],
                ['V-3', 'Marta', 'marta@test.local', 'IND-3'],
            ]
        );

        $_FILES['archivo'] = [
            'name'     => 'lista.xlsx',
            'type'     => '',
            'tmp_name' => $xlsx,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) filesize($xlsx),
        ];

        self::llamar('import', (int) self::$formId, ['modo' => ParticipantImporter::MODO_FUSIONAR]);

        assertEquals(3, FormParticipant::countForForm((int) self::$formId), 'los tres entraron');
        assertEquals(0, count(self::$correos), 'y no salió ni un correo');
    }

    public function testVaciarLaListaPideElModoDelFormularioYNoElDelPost(): void
    {
        // `truncate` no mira el modo de importación: es una acción aparte, porque
        // «reemplazar con un archivo vacío» se rechaza y vaciar tiene que poder
        // existir igual. Lo que sí cambia es que se vacía de verdad.
        self::enRoster();
        FormParticipant::ensureAttribute((int) self::$formId, 'Sede');
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1'], ['sede' => 'Caracas']);
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-2'], ['sede' => 'Valencia']);

        $out = self::llamar('truncate', (int) self::$formId, ['modo' => ParticipantImporter::MODO_REEMPLAZAR]);

        assertTrue($out['json']['ok']);
        assertEquals(0, FormParticipant::countForForm((int) self::$formId));
        // El catálogo se conserva: son las COLUMNAS del próximo archivo, no sus
        // filas. Si se fuera con la gente, vaciar la lista destruiría el formato
        // que el editor ya tenía y que va a volver a subir.
        assertEquals(1, count(FormParticipant::catalogFor((int) self::$formId)), 'las columnas siguen ahí');
    }

    // =====================================================================
    // Utilidades
    // =====================================================================

    private static function enRoster(string $identificador = 'cedula'): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, $identificador);
    }

    private static function modo(string $modo, string $identificador = 'cedula'): void
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

    private static function montarFichero(): void
    {
        $ruta = self::rejilla(
            ['Cédula', 'Nombre', 'Correo', 'Indicador'],
            [['V-1', 'Ana', 'ana@test.local', 'IND-1']]
        );
        // `$_FILES` a mano porque el runner no sube nada: `move_uploaded_file()`
        // fallaría fuera de una petición real y lo que se prueba aquí es que el
        // controlador lee `tmp_name` y no `uploaded_file()`.
        $_FILES['archivo'] = [
            'name'     => 'lista.xlsx',
            'type'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'tmp_name' => $ruta,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) filesize($ruta),
        ];
    }

    /**
     * @param list<string>       $cabeceras
     * @param list<list<string>> $filas
     */
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

        $ruta = sys_get_temp_dir() . '/vf-roster-ctl-' . bin2hex(random_bytes(6)) . '.xlsx';
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

    /**
     * Llama a una acción y recoge lo que respondió.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $query
     *
     * @return array{json:array<string,mixed>,halt:?array<string,mixed>,html:string}
     */
    private static function llamar(string $accion, int $id, array $post, array $query = []): array
    {
        $_POST = $post;
        $_GET = $query;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';
        // `X-Requested-With` es lo único que mira `Response::wantsJson()`, y es lo que
        // pone `vfPost()` en el navegador: sin él, las acciones responden con un 302
        // y los tests no podrían mirar el cuerpo.
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
            $controlador->{$accion}($id);
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
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'rosterctl-%' OR email LIKE 'rosterotro-%'");
        self::$ownerId = null;
        self::$formId = null;
        self::$otroId = null;
    }
}