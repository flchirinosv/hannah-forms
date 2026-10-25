<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Models\Answer;
use App\Models\Field;
use App\Models\Form;
use App\Models\FormParticipant;
use App\Models\Submission;
use App\Models\User;
use App\Services\FormSettings;

/**
 * Fase 3 — `FormParticipant`.
 *
 * Lo que se comprueba aquí no es «el alta funciona», sino las cinco cosas que
 * convierten esta tabla en una lista de personas y no en una tabla más. Cada una
 * tiene un modo de romperse **en silencio**, que es el que importa:
 *
 *  1. **`identifier_value` desnormalizada.** Es la columna que busca la puerta del
 *     fill, y existe para que la puerta no tenga que elegir entre tres columnas.
 *     Si al guardar se normaliza distinto que al buscar, la fila entra y luego
 *     «V1» no la encuentra: el participante tiene código y no puede entrar.
 *  2. **El `code` no se toca.** Es la credencial repartida a mano. Regenerarlo deja
 *     sin acceso a quien ya lo tiene escrito, y no da ningún error.
 *  3. **El `UNIQUE (form_id, identifier_value)`** es lo que impide dos filas de la
 *     misma persona. Sin esto, importar dos veces el mismo archivo dobla la lista.
 *  4. **La frontera del catálogo**: un `key_name` que no está en
 *     `form_participant_fields` no se escribe. Es lo que impide abrir un atributo
 *     por cada clave arbitraria que llegue en el POST.
 *  5. **Borrar no borra respuestas.** `submissions.participant_id` es `SET NULL` y no
 *     `CASCADE`: quitar a alguien de la lista es dejar de invitarlo, no anular lo
 *     que ya contestó.
 */
class FormParticipantTest
{
    private static ?int $ownerId = null;
    private static ?int $formId = null;
    /** @var list<string> */
    private static array $temporales = [];

    public function setUp(): void
    {
        self::cleanupResiduals();

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Roster Owner ' . $rand, 'roster-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$formId = Form::create((int) self::$ownerId, 'Listado ' . $rand);

        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
    }

    public function tearDown(): void
    {
        Auth::forgetCache();
        unset($_GET, $_POST);
        self::cleanupResiduals();
        foreach (self::$temporales as $ruta) {
            @unlink($ruta);
        }
        self::$temporales = [];
    }

    // =============================================================
    // El identificador
    // =============================================================

    public function testElIdentificadorPorDefectoEsLaCedulaYSeGuardaAunqueElModoNoSeaRoster(): void
    {
        assertSame('cedula', FormSettings::defaults()['access']['identifier']);
        assertSame('cedula', FormParticipant::identifierFor((int) self::$formId));
    }

    public function testUnIdentificadorDesconocidoCaeEnCedulaYNoEnElModo(): void
    {
        // El mismo criterio que el modo: una fila corrupta se deja en el valor por
        // defecto en vez de inventarse un cuarto identificador que no tiene columna.
        assertSame(
            'cedula',
            FormParticipant::identifierOf(['access' => ['identifier' => 'pasaporte']]),
            'lo desconocido no se guarda'
        );
        assertSame('correo', FormParticipant::identifierOf(['access' => ['identifier' => 'correo']]));
    }

    public function testElCorreoDelIdentificadorVaEnMinusculasYLosDemasNo(): void
    {
        // El correo es la clave de la puerta: `Ana@X.com` y `ana@x.com` tienen que
        // ser la misma fila. La cédula NO se baja, porque `V-1` y `v-1` pueden ser
        // dos personas y la cédula es lo que el dueño escribió.
        assertSame('ana@x.com', FormParticipant::identifierValue('correo', '', '  Ana@X.com '));
        assertSame('V-1', FormParticipant::identifierValue('cedula', ' V-1 ', ''));
    }

    public function testLosEspaciosInternosSeColapsanParaQueLaFilaSeEncuentreAlBuscar(): void
    {
        // La collation de la tabla NO ignora los espacios: 'V 1' y 'V1' serían dos
        // participantes. Y lo que no se colapse al guardar no se encuentra al buscar.
        assertSame('V 1', FormParticipant::normalizar("V \t  1"));
        assertSame('Caracas Norte', FormParticipant::normalizar("Caracas   Norte\n"));
    }

    public function testLosCaracteresDeControlDelXlsxNoSeQuedanEnElNombre(): void
    {
        // Un \x01 pegado en una celda no es un dato, y además no se puede buscar.
        $sucio = "An\x01a \x1BPérez";
        assertSame('Ana Pérez', FormParticipant::normalizar($sucio));
    }

    public function testConIdentificadorCodigoLaIdentidadDeLaFilaEsElCodigo(): void
    {
        // La puerta busca por `identifier_value`, y un formulario cuyo identificador
        // es el código busca el código: si esta columna guardara la cédula, el code
        // del participante no encontraría a nadie y la lista cerraría la puerta a
        // todo el mundo sin un solo error.
        $res = FormParticipant::create((int) self::$formId, 'codigo', [
            'cedula' => 'V-777',
            'nombre' => 'Persona con código',
        ]);
        assertTrue((bool) $res['ok'], 'el participante se crea: ' . (string) $res['motivo']);

        $fila = FormParticipant::find((int) $res['id']);
        assertNotNull($fila, 'y se lee');
        assertSame((string) $fila['code'], (string) $fila['identifier_value'], 'la identidad es el código, no la cédula');

        $reenc = FormParticipant::findByIdentifier((int) self::$formId, 'codigo', (string) $fila['code']);
        assertNotNull($reenc, 'y se reencuentra por él');
        assertSame((int) $fila['id'], (int) $reenc['id'], 'es la misma fila');
        assertNull(
            FormParticipant::findByIdentifier((int) self::$formId, 'codigo', 'V-777'),
            'la cédula no es la puerta cuando se pide el código'
        );
    }

    public function testElCodigoNoTieneColumnaEnElArchivo(): void
    {
        // `identifierValue()` convierte las columnas del archivo en el valor de la
        // puerta, y el código no viene de ahí: se genera al alta. Devolver la cédula
        // aquí encubriría el error dentro del importador, que deduplicaría y
        // buscaría por el dato equivocado. Lo que sí dice es con qué columna se
        // lee el archivo.
        assertSame('', FormParticipant::identifierValue('codigo', 'V-1', 'ana@test.local'));
        assertSame('cedula', FormParticipant::identificadorDeArchivo('codigo'));
        assertSame('cedula', FormParticipant::identificadorDeArchivo('cedula'));
        assertSame('correo', FormParticipant::identificadorDeArchivo('correo'));
        assertSame('cedula', FormParticipant::identificadorDeArchivo('indicador'));
    }

    public function testCambiarElIdentificadorSeBloqueaSiDejaALaGenteSinIdentidad(): void
    {
        // Sin cédula: cambiar a `cedula` dejaría la lista entera
        // sin forma de identificar a nadie, y es exactamente lo que hay que decir
        // antes de guardar el ajuste en vez de aceptarlo en silencio.
        $vacio = FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-88', 'nombre' => 'Ana']);
        assertTrue($vacio['ok']);
        $vacio2 = FormParticipant::create((int) self::$formId, 'correo', ['correo' => 'otro@test.local', 'nombre' => 'Luis']);
        assertTrue($vacio2['ok']);

        assertSame(1, FormParticipant::sinIdentificadorPara((int) self::$formId, 'cedula'), 'uno no tiene cédula');
        assertSame(1, FormParticipant::sinIdentificadorPara((int) self::$formId, 'correo'), 'uno no tiene correo');
    }

    // =============================================================
    // El código: la credencial
    // =============================================================

    public function testElCodigoSeTecleaSinLosCaracteresQueSeConfunden(): void
    {
        $r = FormParticipant::create((int) self::$formId, 'V-1', '', '', '');
        assertTrue($r['ok']);
        $fila = FormParticipant::find($r['id']);
        assertNotNull($fila);

        $code = (string) $fila['code'];
        assertSame(16, strlen($code), 'dieciséis caracteres: se reparte y se teclea');
        // 0/O y 1/I/L son los pares que una lista se equivoca al transcribir, y 4 de
        // 31 es quitarlos para siempre.
        assertTrue(
            preg_match('/^[23456789ABCDEFGHJKMNPQRSTWXYZ]{16}$/', $code) === 1,
            'el código no puede llevar ' . $code
        );
    }

    public function testElCodigoEsUnicoEnTodaLaInstalacionYNoSoloEnElFormulario(): void
    {
        // El código se teclea en la puerta sin decir de qué formulario es, así que
        // el `UNIQUE` es GLOBAL a propósito: dos participantes de formularios
        // distintos no pueden compartir credencial.
        $otro = Form::create((int) self::$ownerId, 'Otro listado');
        $a = FormParticipant::create((int) self::$formId, 'V-1', '', '', '');
        $b = FormParticipant::create((int) $otro, 'V-1', '', '', '');
        assertTrue($a['ok']);
        assertTrue($b['ok']);
        assertNull(FormParticipant::findByCode('este-no-existe'), 'un código que no existe no devuelve fila');
        $codigoA = (string) FormParticipant::find($a['id'])['code'];
        $codigoB = (string) FormParticipant::find($b['id'])['code'];
        assertTrue($codigoA !== $codigoB, 'dos listas no comparten códigos');
    }

    public function testUnCodigoNoBienFormadoNoSeBuscaNiSeEncuentra(): void
    {
        // Antes de tocar la BD: un `code` con la longitud o el alfabeto que no es no
        // puede ser un código, así que no se consulta. En una lista de miles esto
        // también evita un barrido por un `LIKE` que no hace falta.
        assertFalse(FormParticipant::codeValido(''));
        assertFalse(FormParticipant::codeValido('Corta'));
        assertFalse(FormParticipant::codeValido('0123456789ABCDEF'), 'el cero no está en el alfabeto');
        assertFalse(FormParticipant::codeValido('ABCDEFGHJKMNPQR'), 'son quince');
        assertTrue(FormParticipant::codeValido('ABCDEFGHJKMNPQRS'), 'dieciséis sí');
        assertTrue(FormParticipant::codeValido('23456789ABCDEFGH'));
    }

    public function testElCodigoDeOtroFormularioNoExisteParaEstaPuerta(): void
    {
        // La mitad de la autorización va en el WHERE, no en un filtro de PHP: igual
        // que en `FormAccess::findValidToken()`.
        $otro = Form::create((int) self::$ownerId, 'Otro listado');
        $r = FormParticipant::create((int) $otro, 'V-1', '', '', '');
        $code = (string) FormParticipant::find($r['id'])['code'];

        assertNotNull(FormParticipant::findByCode($code), 'el código existe en la tabla');
        assertNull(FormParticipant::findByCodeInForm((int) self::$formId, $code), 'pero no para este formulario');
        assertNotNull(FormParticipant::findByCodeInForm((int) $otro, $code));
    }

    public function testElCodigoNoCambiaAlEditarAlParticipante(): void
    {
        // Es la credencial que ya se repartió a mano. Regenerarla deja sin acceso a
        // quien la tiene escrita, y no da ningún error.
        $r = FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana']);
        $antes = (string) FormParticipant::find($r['id'])['code'];

        $u = FormParticipant::update($r['id'], (int)self::$formId, 'cedula', ['cedula' => 'V-2', 'nombre' => 'Ana Pérez']);
        assertTrue($u['ok']);

        $despues = FormParticipant::find($r['id']);
        assertSame($antes, (string) $despues['code'], 'el código no se toca ni aunque venga en el POST');
        assertSame('V-2', (string) $despues['cedula'], 'lo demás sí se actualiza');
    }

    // =============================================================
    // Duplicados y validación
    // =============================================================

    public function testDosPersonasConLaMismaIdentidadNoSeGuardan(): void
    {
        $a = FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1']);
        $b = FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1']);

        assertTrue($a['ok']);
        assertFalse($b['ok'], 'el duplicado es un error, no un no-op silencioso');
        assertSame(FormParticipant::MOTIVO_DUPLICADO, $b['motivo'], 'y hay que poder decir por qué');
        assertSame(1, FormParticipant::countForForm((int) self::$formId), 'la lista tiene una persona, no dos');
    }

    public function testLaMismaCedulaEnDosFormulariosSiEsPersonasDistintas(): void
    {
        $otro = Form::create((int) self::$ownerId, 'Otro listado');
        assertTrue(FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1'])['ok']);
        assertTrue(
            FormParticipant::create((int) $otro, 'cedula', ['cedula' => 'V-1'])['ok'],
            'el UNIQUE es (form_id, identifier_value): la lista es por formulario'
        );
    }

    public function testSinIdentificadorNoEntraYSeDiceCualFalta(): void
    {
        $r = FormParticipant::create((int) self::$formId, 'correo', ['cedula' => 'V-1']);
        assertFalse($r['ok']);
        assertSame(FormParticipant::MOTIVO_VACIO, $r['motivo'], 'no es un error de MariaDB, es un motivo dicho');
        assertSame(0, FormParticipant::countForForm((int) self::$formId));

        $conCorreo = FormParticipant::create((int) self::$formId, 'correo', ['correo' => 'Ana@X.com']);
        assertTrue($conCorreo['ok'], 'con el identificador que sí tiene');
    }

    public function testUnCorreoMalEscritoSeRechazaAunqueNoSeaElIdentificador(): void
    {
        // El correo es lo único que se manda por correo: una dirección mala es un
        // enlace que no llega, aunque el identificador sea la cédula.
        $r = FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'correo' => 'no-es-un-correo']);
        assertFalse($r['ok']);
        assertSame('correo_invalido', $r['motivo']);

        assertTrue(FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'correo' => 'a@b.com'])['ok']);
    }

    public function testUnaIdentidadMasLargaQueLaColumnaSeDiceYNoSeRecorta(): void
    {
        // `identifier_value` es VARCHAR(190). Recortar una cédula de 200 caracteres
        // guardaría una identidad que no es la que se escribió, sin avisar.
        $largo = str_repeat('9', 191);
        $r = FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => $largo]);
        assertFalse($r['ok']);
        assertSame(FormParticipant::MOTIVO_LARGO, $r['motivo']);
        assertSame(0, FormParticipant::countForForm((int) self::$formId), 'y no queda media cédula guardada');
    }

    // =============================================================
    // El catálogo de atributos
    // =============================================================

    public function testUnaCabeceraSeConvierteEnAtributoYUnaIgualSeReusa(): void
    {
        // El UNIQUE de `label` hace que «Gerencia» y «Gerencia » no puedan ser dos
        // atributos. La segunda columna tiene que reusar la primera o el INSERT
        // revienta con un duplicado EN MITAD de una importación.
        $primera = FormParticipant::ensureAttribute((int) self::$formId, 'Sede');
        assertSame('sede', $primera);

        $otra = FormParticipant::ensureAttribute((int) self::$formId, 'Sede');
        assertSame($primera, $otra, 'la misma columna reusa la clave');

        $conEspacio = FormParticipant::ensureAttribute((int) self::$formId, ' Sede ');
        assertSame($primera, $conEspacio, 'y con espacios alrededor es la misma');

        assertSame(1, count(FormParticipant::catalogFor((int) self::$formId)));
    }

    public function testUnAcentoNoCreaUnAtributoNuevoPorqueLaCollationLosIguala(): void
    {
        // La collation de la tabla (`utf8mb4_unicode_ci`) NO distingue acentos ni
        // mayúsculas, y el `UNIQUE` de `label` tampoco: para el esquema «Diseño» y
        // «Diseno» son la MISMA etiqueta. Reusar no es un capricho de la aplicación,
        // es lo que el propio `UNIQUE` obliga, y tratar de crear las dos daría un
        // error de clave duplicada a mitad de una importación.
        $a = FormParticipant::ensureAttribute((int) self::$formId, 'Diseño');
        $b = FormParticipant::ensureAttribute((int) self::$formId, 'Diseno');

        assertSame($a, $b, 'la segunda reusa la primera');
        assertSame(1, count(FormParticipant::catalogFor((int) self::$formId)));

        // Y la etiqueta que se guarda es la que se escribió PRIMERO: es con esa
        // con la que se pinta el filtro, y reescribirla cada vez que alguien quita
        // el acento haría que el nombre del grupo en la analítica cambiara solo.
        $catalogo = FormParticipant::catalogFor((int) self::$formId);
        assertSame('Diseño', (string) $catalogo[0]['label']);
    }

    public function testDosEtiquetasQueDanLaMismaClaveRecibenClavesDistintas(): void
    {
        // Aquí la collation NO ayuda: «Sede-Norte» y «Sede Norte» son etiquetas
        // distintas y las dos caben. Pero el slug de las dos es el mismo, y si el
        // valor del segundo se guardara en la fila del primero, la analítica
        // mezclaría a dos grupos en uno sin decir nada.
        $a = FormParticipant::ensureAttribute((int) self::$formId, 'Sede-Norte');
        $b = FormParticipant::ensureAttribute((int) self::$formId, 'Sede Norte');

        assertNotNull($a);
        assertNotNull($b);
        assertTrue($a !== $b, 'dos etiquetas, dos claves: ' . $a . ' / ' . $b);
        assertSame('sede_norte', $a, 'la primera se queda con el slug pelado');
        assertSame('sede_norte_2', $b, 'la segunda recibe el sufijo');
        assertSame(2, count(FormParticipant::catalogFor((int) self::$formId)));
    }

    public function testDosEtiquetasQueSoloSeDiferencianEnEspaciosInternosReusanElAtributo(): void
    {
        $a = FormParticipant::ensureAttribute((int) self::$formId, 'Gerencia X');
        $b = FormParticipant::ensureAttribute((int) self::$formId, 'Gerencia    X');
        assertSame($a, $b, 'la collation no ignora los espacios, pero el modelo sí los colapsa antes');
    }

    public function testUnaEtiquetaQueEmpiezaPorDigitoNoEmpiezaLaClavePorDigito(): void
    {
        $clave = FormParticipant::ensureAttribute((int) self::$formId, '2024 turno');
        assertNotNull($clave);
        assertTrue(
            preg_match('/^[A-Za-z_]/', $clave) === 1,
            'una clave que empieza por dígito no es un identificador cómodo: ' . $clave
        );
    }

    public function testUnAtributoQueNoEstaEnElCatalogoNoSeEscribe(): void
    {
        // La frontera de seguridad: el SQL recibe la clave como VALOR enlazado, pero
        // solo se le pasa una que el dueño ya creó. Sin esto, cualquier clave del
        // POST abriría un atributo nuevo.
        $r = FormParticipant::create(
            (int) self::$formId,
            'cedula',
            ['cedula' => 'V-1'],
            ['inventada' => 'lo que sea']
        );
        assertFalse($r['ok']);
        assertSame(FormParticipant::MOTIVO_ATRIBUTO, $r['motivo']);
        assertSame(0, FormParticipant::countForForm((int) self::$formId));
    }

    public function testUnValorDeAtributoMasLargoQueLaColumnaSeDice(): void
    {
        FormParticipant::ensureAttribute((int) self::$formId, 'Sede');
        $r = FormParticipant::create(
            (int) self::$formId,
            'cedula',
            ['cedula' => 'V-1'],
            ['sede' => str_repeat('a', FormParticipant::MAX_VALOR_ATRIBUTO + 1)]
        );
        assertFalse($r['ok']);
        assertSame(FormParticipant::MOTIVO_VALOR_LARGO, $r['motivo']);
    }

    public function testUnAtributoVacioNoCreaRenglonNiColumnaDelCatalogo(): void
    {
        // Si el vacío se guardara, el catálogo crecería con columnas que nadie
        // rellenó y existiría un grupo de gente «vacío» que en realidad no es nadie.
        FormParticipant::ensureAttribute((int) self::$formId, 'Sede');
        $r = FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1'], ['sede' => '']);

        assertTrue($r['ok']);
        $atributos = FormParticipant::attributesOf($r['id']);
        assertSame(0, count($atributos), 'un valor vacío no es un renglón');
    }

    public function testBorrarUnAtributoSeLlevaSusValores(): void
    {
        $key = FormParticipant::ensureAttribute((int) self::$formId, 'Sede');
        $r = FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1'], [$key => 'Caracas']);
        assertTrue($r['ok']);

        FormParticipant::deleteAttribute((int) self::$formId, $key);

        assertSame(0, count(FormParticipant::catalogFor((int) self::$formId)));
        $db = Database::getInstance(Config::db());
        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM form_participant_attrs WHERE form_id = :f AND key_name = :k',
            ['f' => (int) self::$formId, 'k' => $key]
        );
        assertSame(0, (int) $row['c'], 'los valores se van con el atributo');
    }

    // =============================================================
    // Listado
    // =============================================================

    public function testElListadoOrdenaPorCedulaYBuscaPorCualquieraDeLosTres(): void
    {
        FormParticipant::create((int) self::$formId, 'cedula', [
            'cedula' => 'V-2', 'nombre' => 'Luis', 'correo' => 'luis@test.local',
        ]);
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana']);

        $todos = FormParticipant::allForForm((int) self::$formId);
        assertSame(2, count($todos));
        assertSame('V-1', (string) $todos[0]['cedula'], 'por cédula, que es la plantilla');

        assertSame(1, count(FormParticipant::allForForm((int) self::$formId, 200, 0, 'luis@')), 'por correo');
        assertSame(1, count(FormParticipant::allForForm((int) self::$formId, 200, 0, 'Luis')), 'por nombre');
        assertSame(1, FormParticipant::countForForm((int) self::$formId, 'luis@'), 'y el contador cuenta lo mismo');
    }

    public function testLosComodinesDelBuscadorNoSeTomanComoComodin(): void
    {
        // Sin escapar, buscar «%» devuelve la lista entera: el buscador de un panel
        // se convierte en una superficie para LIKE caro.
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana']);
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-2', 'nombre' => 'Luis']);

        assertSame(0, FormParticipant::countForForm((int) self::$formId, '%'), 'el % es un literal');
        assertSame(0, FormParticipant::countForForm((int) self::$formId, '_-1'), 'el _ es un literal');
        assertSame(1, FormParticipant::countForForm((int) self::$formId, 'V-1'), 'y lo de verdad se encuentra');
    }

    public function testLaPaginaNoSeSaleDelFormularioNiRepiteFilas(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-' . $i]);
        }
        $otro = Form::create((int) self::$ownerId, 'Otro listado');
        FormParticipant::create((int) $otro, 'cedula', ['cedula' => 'V-9']);

        $primera = FormParticipant::allForForm((int) self::$formId, 2, 0);
        $segunda = FormParticipant::allForForm((int) self::$formId, 2, 2);

        assertSame(2, count($primera));
        assertSame(2, count($segunda));
        assertSame(5, FormParticipant::countForForm((int) self::$formId), 'el total es del formulario, no de la instalación');

        $ids = array_merge(
            array_map(static fn(array $p): int => (int) $p['id'], $primera),
            array_map(static fn(array $p): int => (int) $p['id'], $segunda)
        );
        assertSame(4, count(array_unique($ids)), 'las dos páginas no se solapan');
    }

    // =============================================================
    // Borrar: la regla que más caro sale si se cambia
    // =============================================================

    public function testBorrarUnParticipanteNoBorraSuRespuesta(): void
    {
        // `ON DELETE SET NULL`, no `CASCADE`. Quitar a alguien de la lista es dejar
        // de invitarlo; con `CASCADE` su respuesta desaparecería y no habría forma de
        // recuperarla.
        $r = FormParticipant::create((int) self::$formId, 'V-1', '', '', '');
        $field = Field::create((int) self::$formId, 'text', 'Nombre', '', '', true);
        $sub = Submission::create((int) self::$formId);
        Answer::create((int) $sub, (int) $field, 'Ana');
        self::vincular((int) $sub, $r['id']);

        assertTrue(FormParticipant::delete($r['id']));

        $row = Database::getInstance(Config::db())->fetch(
            'SELECT participant_id FROM submissions WHERE id = :id',
            ['id' => $sub]
        );
        assertNotNull($row, 'la respuesta sigue ahí');
        assertNull($row['participant_id'], 'pero ya sin participante');
    }

    public function testVaciarLaListaTampocoBorraRespuestas(): void
    {
        $r = FormParticipant::create((int) self::$formId, 'V-1', '', '', '');
        $field = Field::create((int) self::$formId, 'text', 'Nombre', '', '', true);
        $sub = Submission::create((int) self::$formId);
        Answer::create((int) $sub, (int) $field, 'Ana');
        self::vincular((int) $sub, $r['id']);

        assertSame(1, FormParticipant::truncate((int) self::$formId));
        assertSame(0, FormParticipant::countForForm((int) self::$formId));

        $row = Database::getInstance(Config::db())->fetch(
            'SELECT participant_id FROM submissions WHERE id = :id',
            ['id' => $sub]
        );
        assertNotNull($row);
        assertNull($row['participant_id']);
    }

    public function testBorrarElFormularioSeLlevaLaLista(): void
    {
        FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1']);
        Form::delete((int) self::$formId);

        $db = Database::getInstance(Config::db());
        $row = $db->fetch('SELECT COUNT(*) AS c FROM form_participants WHERE form_id = :f', ['f' => (int) self::$formId]);
        assertSame(0, (int) $row['c']);
    }

    // =============================================================
    // Utilidades
    // =============================================================

    private static function vincular(int $submissionId, int $participantId): void
    {
        // `Submission::create()` todavía no escribe `participant_id` (eso es la
        // fase 9, cuando exista la puerta), así que aquí se enlaza a mano para poder
        // comprobar la regla del `SET NULL`, que es del ESQUEMA y no del modelo.
        Database::getInstance(Config::db())->execute(
            'UPDATE submissions SET participant_id = :pid WHERE id = :id',
            ['pid' => $participantId, 'id' => $submissionId]
        );
    }

    /**
     * Residuos.
     *
     * El orden importa: los usuarios van antes que los formularios porque su
     * cascada se lleva `forms`, y con ellos `form_participants`,
     * `form_participant_fields` y `form_participant_attrs`.
     */
    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute("DELETE FROM users WHERE email LIKE 'roster-%'");
        self::$ownerId = null;
        self::$formId = null;
    }
}