<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Controllers\FormController;
use App\Models\Form;
use App\Models\FormParticipant;
use App\Models\User;
use App\Services\FormSettings;

/**
 * Fase 6 — Exponer `roster`, y lo que sale de exponerlo.
 *
 * Añadir un `<option>` al desplegable de la ficha parece el cambio más pequeño
 * del plan y es el que más cosas destapa, porque es la primera vez que un dueño
 * puede hacer tres cosas que hasta ahora nadie podía hacer desde la pantalla:
 *
 *  1. **Ponerse en `roster`.** Y aquí está la mitad del riesgo: la lista es la
 *     puerta. Un fallo de la fase 4 (la puerta del fill) se ha probado con
 *     fixtures, pero no se ha visto nunca desde el selector, y un selector que
 *     ofrece un modo cerrado es un formulario que alguien cree protegido.
 *  2. **Elegir con qué se identifica a cada persona**, y eso obliga a una puerta
 *     nueva: cambiar el identificador con gente en la lista reescribe
 *     `identifier_value`, que es la columna por la que la puerta busca. Si la
 *     reescritura no avise… el formulario deja de dejar entrar a nadie sin
 *     decir absolutamente nada. Es el peor fallo posible en esta tabla y por eso
 *     aquí se comprueba en las dos mitades: que el cambio **se acepte** cuando es
 *     posible y que **no** se acepte cuando dejaría a alguien fuera.
 *  3. **Volver a `public`.** Los datos de la lista se quedan (es lo que permite
 *     volver atrás sin perder nada) y el formulario se abre. Que eso siga siendo
 *     verdad es lo que sostiene el «si algo falla, pon «Público»».
 *
 * Y una cuarta cosa, que no es del fase 6 pero se comprueba aquí porque la ha
 * tocado: **los tres modos de siempre no cambian**. La regla del plan es que
 * `public`, `email` y `allowlist` quedan byte a byte intactos, y «byte a byte» se
 * comprueba con las etiquetas del desplegable y con un guardado que no manda
 * identificador.
 */
class RosterIdentifierTest
{
    private const VISTA_FICHA = __DIR__ . '/../../views/dashboard/forms/show.php';
    private const JS = __DIR__ . '/../../public/assets/js/dashboard.js';

    private static ?int $ownerId = null;
    private static ?int $formId = null;
    /** El token del POST: es `static` porque los ayudantes que llaman a la puerta lo usan. */
    private static string $csrfToken = '';

    public function setUp(): void
    {
        self::cleanupResiduals();

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Roster Id ' . $rand, 'rosterid-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$formId = Form::create((int) self::$ownerId, 'Roster Id ' . $rand);

        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();

        // La puerta es `auth, csrf` y `updateSettings()` no lo comprueba por su
        // cuenta (lo hace el middleware), así que el token tiene que estar en la
        // sesión como en cualquier POST real.
        self::$csrfToken = Csrf::token();
    }

    public function tearDown(): void
    {
        Auth::forgetCache();
        unset($_GET, $_POST);
        Response::setRecordHalt(false);
        self::cleanupResiduals();
    }

    // =====================================================================
    // 1. El plan: qué pasaría al cambiar de identificador, sin escribir
    // =====================================================================

    public function testElPlanCuentaLasPersonasQueSeQuedarianSinIdentidad(): void
    {
        self::persona(['indicador' => 'IND-1', 'nombre' => 'Ana'], 'indicador');
        self::persona(['cedula' => 'V-2', 'nombre' => 'Luis', 'correo' => 'luis@test.local']);

        $plan = FormParticipant::planDeReidentificacion((int) self::$formId, 'correo');

        assertSame(1, $plan['sin_identidad'], 'Ana no tiene correo');
        assertSame(1, $plan['a_cambiar'], 'solo cambia la de Luis');
        assertSame(0, $plan['repetidos_cuento'], 'y el correo de Luis no está repetido');
    }

    public function testElPlanDetectaLasPersonasQuePasarianACompartirIdentidad(): void
    {
        // Dos personas con el mismo correo: no es raro (una errata en el `.xlsx`,
        // una fila copiada) y con el `UNIQUE (form_id, identifier_value)` es la
        // puerta, así que aceptarlo sería reventar con un 1062 a mitad.
        self::persona(['cedula' => 'V-1', 'correo' => 'Gemela@Test.local', 'nombre' => 'Ana']);
        self::persona(['cedula' => 'V-2', 'correo' => 'gemela@test.local ', 'nombre' => 'Zule']);

        $plan = FormParticipant::planDeReidentificacion((int) self::$formId, 'correo');

        // Se cuentan las PERSONAS repetidas (2), y se avisa del VALOR repetido. Y
        // solo hay un valor: `Gemela@Test.local` y `gemela@test.local` son la
        // misma después de normalizar, que es justo lo que hace el identificador.
        assertSame(2, $plan['repetidos_cuento'], 'las dos personas repetirían la identidad');
        assertEquals(['gemela@test.local'], $plan['repetidos'], 'y es un solo valor');
        assertSame(2, $plan['a_cambiar'], 'las dos cambiarían');
    }

    public function testElPlanTopaLosValoresRepetidosQueAvisa(): void
    {
        // Con 8 parejas repetidas el mensaje tiene que seguir siendo corto: se
        // avisa de cinco y se dice cuántas son, porque una lista de ocho valores
        // en un mensaje de error no lo lee nadie.
        for ($i = 1; $i <= 16; $i++) {
            $par = (string) intdiv($i - 1, 2) + 1;
            self::persona([
                'cedula' => 'V-' . $i,
                'correo' => 'gemela' . $par . '@test.local',
                'nombre' => 'Persona ' . $i,
            ]);
        }

        $plan = FormParticipant::planDeReidentificacion((int) self::$formId, 'correo');

        assertSame(16, $plan['repetidos_cuento'], 'son 16 personas');
        assertEquals(5, count($plan['repetidos']), 'pero el aviso.topa a cinco valores');
        assertEquals(
            ['gemela1@test.local', 'gemela2@test.local', 'gemela3@test.local', 'gemela4@test.local', 'gemela5@test.local'],
            $plan['repetidos'],
            'y son los cinco primeros, ordenados'
        );
    }

    public function testUnIdentificadorDesconocidoNoHaceNada(): void
    {
        $ana = self::persona(['cedula' => 'V-1', 'correo' => 'ana@test.local']);

        $plan = FormParticipant::planDeReidentificacion((int) self::$formId, 'pasaporte');
        assertSame(0, $plan['sin_identidad']);
        assertSame(0, $plan['a_cambiar']);
        assertEquals([], $plan['repetidos']);

        assertSame(0, FormParticipant::reidentificar((int) self::$formId, 'pasaporte'), 'y no reescribe nada');
        assertSame('V-1', self::valorDe((int) $ana['id'])['identifier_value'], 'la fila sigue con su cédula');
    }

    // =====================================================================
    // 2. La reescritura: solo la denormalizada, y nunca el código
    // =====================================================================

    public function testReidentificarCambiaSoloLaColumnaDenormalizada(): void
    {
        $ana = self::persona(['cedula' => 'V-1', 'nombre' => 'Ana', 'correo' => 'ana@test.local']);
        $luis = self::persona(['cedula' => 'V-2', 'nombre' => 'Luis', 'correo' => 'luis@test.local']);

        $cambiadas = FormParticipant::reidentificar((int) self::$formId, 'correo');
        assertSame(2, $cambiadas, 'las dos filas cambian de identidad');

        $fila = self::valorDe((int) $ana['id']);
        assertSame('ana@test.local', $fila['identifier_value'], 'la identidad pasa a ser el correo');
        assertSame('V-1', $fila['cedula'], 'pero la cédula se queda guardada');
        assertSame('Ana', $fila['nombre'], 'y el nombre');
        assertSame($ana['code'], $fila['code'], 'SOBRE TODO el código: es la credencial ya repartida');

        assertSame(0, FormParticipant::reidentificar((int) self::$formId, 'correo'), 'repetirlo no cambia nada');

        $otra = self::valorDe((int) $luis['id']);
        assertSame('luis@test.local', $otra['identifier_value']);
        assertSame($luis['code'], $otra['code']);
    }

    public function testElPlanAgrupaLosRepetidosComoElIndiceYNoComoPhp(): void
    {
        // Estas dos SÍ pueden coexistir: la primera está identificada por cédula y
        // la segunda por indicador, así que sus `identifier_value` son distintas.
        // Al cambiar a cédula, las dos quieren lo mismo… y «V-1» y «v-1» son **la
        // misma clave** para el `UNIQUE (form_id, identifier_value)`, porque la
        // tabla es `utf8mb4_unicode_ci`. Un plan que las contara en PHP (con un
        // array asociativo, que distingue mayúsculas) diría «no hay repetidos» y el
        // `UPDATE` se comería un error de servidor en vez de un mensaje.
        $una = FormParticipant::create((int) self::$formId, 'cedula', ['cedula' => 'V-1', 'nombre' => 'Ana']);
        assertTrue($una['ok'], 'la primera entra');
        $otra = FormParticipant::create(
            (int) self::$formId,
            'indicador',
            ['indicador' => 'IND-2', 'cedula' => 'v-1', 'nombre' => 'Zule']
        );
        assertTrue($otra['ok'], 'la segunda también, con su propia identidad');

        $plan = FormParticipant::planDeReidentificacion((int) self::$formId, 'cedula');

        assertSame(2, $plan['repetidos_cuento'], 'las dos pasarían a compartir la identidad');
        assertEquals(1, count($plan['repetidos']), 'y es un solo valor, aunque se escriba de dos formas');
        assertSame(
            0,
            strcasecmp('V-1', $plan['repetidos'][0]),
            'la grafía que trae el grupo da igual: «V-1» o «v-1», es el mismo para el índice'
        );
    }

    // =====================================================================
    // 3. La puerta: se acepta cuando se puede, y no cuando dejaría fuera a alguien
    // =====================================================================

    public function testCambiarElIdentificadorConGenteQueNoLoTieneNoSeGuarda(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'cedula');
        $ana = self::persona(['cedula' => 'V-1', 'nombre' => 'Ana']); // sin correo
        self::persona(['indicador' => 'IND-9', 'nombre' => 'Luis'], 'indicador'); // sin cédula

        $r = self::guardar(['access_mode' => 'roster', 'access_identifier' => 'correo']);

        assertTrue($r['json']['ok'] === false, 'el POST tiene que fallar');
        assertTrue(
            str_contains((string) ($r['json']['error'] ?? ''), 'correo'),
            'y el error dice qué campo falta: ' . (string) ($r['json']['error'] ?? '')
        );
        assertSame('cedula', FormParticipant::identifierFor((int) self::$formId), 'el identificador sigue siendo el de antes');
        assertSame('V-1', self::valorDe((int) $ana['id'])['identifier_value'], 'y la lista no se ha tocado');
    }

    public function testCambiarElIdentificadorConRepetidosNoSeGuarda(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'cedula');
        self::persona(['cedula' => 'V-1', 'correo' => 'gemela@test.local', 'nombre' => 'Ana']);
        self::persona(['cedula' => 'V-2', 'correo' => 'Gemela@Test.local', 'nombre' => 'Zule']);

        $r = self::guardar(['access_mode' => 'roster', 'access_identifier' => 'correo']);

        assertTrue($r['json']['ok'] === false, 'no se acepta un cambio que rompe el UNIQUE de la puerta');
        assertTrue(
            str_contains((string) ($r['json']['error'] ?? ''), 'gemela@test.local'),
            'y el error dice qué identidad está repetida: ' . (string) ($r['json']['error'] ?? '')
        );
        assertSame('cedula', FormParticipant::identifierFor((int) self::$formId));
    }

    public function testCambiarElIdentificadorReescribeLaListaYConservaLosCodigos(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'cedula');
        $ana = self::persona(['cedula' => 'V-1', 'nombre' => 'Ana', 'correo' => 'ana@test.local']);
        $luis = self::persona(['cedula' => 'V-2', 'nombre' => 'Luis', 'correo' => 'luis@test.local']);

        $r = self::guardar(['access_mode' => 'roster', 'access_identifier' => 'correo']);

        assertTrue($r['json']['ok'] === true, 'el cambio se acepta: ' . (string) ($r['json']['error'] ?? ''));
        assertSame('correo', FormParticipant::identifierFor((int) self::$formId), 'el ajuste dice «correo»');

        // Las DOS mitades de la misma decisión: el ajuste guardado y la lista
        // reescrita. Con la lista sin reescribir, el formulario no deja entrar a
        // nadie (la puerta busca por `identifier_value`) y no da ningún error.
        assertSame('ana@test.local', self::valorDe((int) $ana['id'])['identifier_value']);
        assertSame('luis@test.local', self::valorDe((int) $luis['id'])['identifier_value']);
        assertSame($ana['code'], self::valorDe((int) $ana['id'])['code'], 'y nadie se queda sin su código');
        assertSame($luis['code'], self::valorDe((int) $luis['id'])['code']);

        // Y la puerta de verdad: el correo es ahora la credencial que se teclea.
        $porCorreo = FormParticipant::findByIdentifier((int) self::$formId, 'correo', 'luis@test.local');
        assertTrue($porCorreo !== null, 'el correo encuentra a la persona');
        assertSame($luis['code'], (string) ($porCorreo['code'] ?? ''), 'con el mismo código de siempre');
        assertSame(
            'luis@test.local',
            (string) ($porCorreo['identifier_value'] ?? ''),
            'y su identidad es ahora el correo'
        );
        assertNull(
            FormParticipant::findByIdentifier((int) self::$formId, 'correo', 'V-1'),
            'la cédula ya no encuentra a nadie, que es lo que hay que avisar'
        );
    }

    public function testCambiarElIdentificadorACodigoConvierteLaIdentidadEnElCodigo(): void
    {
        // El cuarto identificador es el único cuyo valor NO viene de la lista: lo
        // genera el servidor. Por eso el plan lo cuenta con la columna `code`
        // (nadie se queda sin identidad) y `reidentificar()` copia esa misma
        // columna: una mitad sin la otra deja la lista con un `identifier_value`
        // que la puerta no busca, que es exactamente el fallo silencioso que el
        // plan existe para evitar.
        self::modo(FormSettings::ACCESS_ROSTER, 'cedula');
        $ana = self::persona(['cedula' => 'V-1', 'nombre' => 'Ana']);
        $luis = self::persona(['cedula' => 'V-2', 'nombre' => 'Luis']);

        $plan = FormParticipant::planDeReidentificacion((int) self::$formId, 'codigo');
        assertSame(0, $plan['sin_identidad'], 'todos tienen código: no falta nadie');
        assertSame(2, $plan['a_cambiar'], 'las dos cambiarían de identidad');
        assertSame(0, $plan['repetidos_cuento'], 'los códigos no se repiten');

        $r = self::guardar(['access_mode' => 'roster', 'access_identifier' => 'codigo']);

        assertTrue($r['json']['ok'] === true, 'el cambio se acepta: ' . (string) ($r['json']['error'] ?? ''));
        assertSame('codigo', FormParticipant::identifierFor((int) self::$formId), 'el ajuste dice «codigo»');

        assertSame($ana['code'], self::valorDe((int) $ana['id'])['identifier_value'], 'la identidad de Ana es su código');
        assertSame($luis['code'], self::valorDe((int) $luis['id'])['identifier_value']);
        assertSame($ana['code'], self::valorDe((int) $ana['id'])['code'], 'y su código no cambió');
        assertSame($luis['code'], self::valorDe((int) $luis['id'])['code']);

        // La puerta de verdad: se busca por el código, y la cédula ya no es la
        // identidad (aunque sigue en su columna, que es la del archivo).
        assertNotNull(
            FormParticipant::findByIdentifier((int) self::$formId, 'codigo', (string) $ana['code']),
            'el código encuentra a la persona'
        );
        assertSame('V-1', (string) self::valorDe((int) $ana['id'])['cedula'], 'la cédula sigue donde estaba');
    }

    public function testSinGenteEnLaListaSePuedeCambiarElIdentificador(): void
    {
        self::modo(FormSettings::ACCESS_ROSTER, 'cedula');

        $r = self::guardar(['access_mode' => 'roster', 'access_identifier' => 'indicador']);

        assertTrue($r['json']['ok'] === true, 'sin lista no hay a quién dejar fuera');
        assertSame('indicador', FormParticipant::identifierFor((int) self::$formId));
    }

    public function testUnPostSinIdentificadorNoTocaElQueEstabaGuardado(): void
    {
        // El caso del `disabled`: el `<select>` del identificador no viaja cuando el
        // modo no es `roster`, y un POST que no lo manda **no puede** cambiarlo. Si
        // lo cambiara, cualquier guardado de cualquier ajuste se comería el
        // identificador elegido en su día.
        self::modo(FormSettings::ACCESS_ROSTER, 'correo');

        self::guardar(['access_mode' => 'roster'] + self::restoDeFormulario());

        assertSame('correo', FormParticipant::identifierFor((int) self::$formId), 'sigue siendo «correo»');
    }

    public function testLosTresModosDeSiempreSiguenGuardandoLoMismo(): void
    {
        foreach ([
            FormSettings::ACCESS_PUBLIC,
            FormSettings::ACCESS_EMAIL,
            FormSettings::ACCESS_ALLOWLIST,
        ] as $modo) {
            self::modo($modo, 'correo');

            $r = self::guardar(['access_mode' => $modo] + self::restoDeFormulario());

            assertTrue($r['json']['ok'] === true, 'el modo ' . $modo . ' se sigue guardando: ' . (string) ($r['json']['error'] ?? ''));
            assertSame($modo, (string) Form::settings((int) self::$formId)['access']['mode'], 'el modo es ' . $modo);
            assertSame('correo', FormParticipant::identifierFor((int) self::$formId), 'y el identificador no se mueve solo');
        }
    }

    // =====================================================================
    // 4. El interruptor: la opción y el identificador que solo tiene sentido con lista
    // =====================================================================

    public function testElDesplegableTieneLasCuatroOpcionesYLaDeRosterEsLaUltima(): void
    {
        $opciones = self::opcionesDelDesplegable();

        assertEquals(
            ['public', 'email', 'allowlist', 'roster'],
            $opciones,
            'las cuatro, y en este orden: la nueva es la última, que es lo último que se enseña'
        );
    }

    public function testLasTresEtiquetasDeSiempreNoSeHanTocado(): void
    {
        // «Byte a byte» se comprueba así: si alguien reescribe la etiqueta de un
        // modo que llevan años en pantalla, este test se pone rojo.
        $html = (string) file_get_contents(self::VISTA_FICHA);

        foreach ([
            'Cualquiera con el enlace',
            'Cualquiera que verifique su correo',
            'Solo los correos de la lista',
        ] as $etiqueta) {
            assertTrue(str_contains($html, '>' . $etiqueta . '</option>'), 'la etiqueta «' . $etiqueta . '» sigue igual');
        }
    }

    public function testElIdentificadorSoloSeVeEnRosterYVieneDesactivadoCuandoNo(): void
    {
        self::modo(FormSettings::ACCESS_PUBLIC, 'cedula');
        $publico = self::ficha();

        assertTrue(str_contains($publico, 'id="accessIdentifierBox" hidden'), 'en «Público» la caja de identidad viene escondida');
        assertTrue(
            preg_match('/id="access_identifier"[^>]*\sdisabled/', $publico) === 1,
            'y el selector DESACTIVADO: escondido sin `disabled` seguiría mandando «cédula» en cada guardado'
        );

        self::modo(FormSettings::ACCESS_ROSTER, 'indicador');
        $roster = self::ficha();

        assertTrue(!str_contains($roster, 'id="accessIdentifierBox" hidden'), 'en «Solo el listado» se ve');
        assertTrue(
            preg_match('/<option value="indicador" selected>/', $roster) === 1,
            'con lo que está guardado, que es lo que el dueño eligió'
        );
    }

    public function testLaFraseDelRosterEsSuyaYNoLaDeLaAllowlist(): void
    {
        $js = self::sinComentarios((string) file_get_contents(self::JS));

        // El `else` final sigue siendo el de la allowlist: es el modo que no tiene
        // nombre propio y el que más fácil es cambiar sin querer.
        assertTrue(
            str_contains($js, "} else if (accessSel.value === 'roster') {"),
            'roster tiene su propia rama y no cae en el `else`'
        );
        assertTrue(
            str_contains($js, "Solo entran las personas del listado"),
            'y su frase explica el código y dónde se gestiona la lista'
        );
        assertTrue(
            str_contains($js, "Solo los correos de la lista reciben un enlace."),
            'la frase de la allowlist sigue ahí'
        );
    }

    public function testElJsEnseñaYEscondeElIdentificadorConHiddenYDisabledJuntos(): void
    {
        $js = self::sinComentarios((string) file_get_contents(self::JS));

        assertTrue(str_contains($js, "accessSel.value === 'roster'"), 'el modo decide si el identificador se ve');
        // Los dos atributos, y no solo `hidden`: sin `disabled` el selector sigue
        // mandando su valor y el identificador guardado se pierde en el siguiente
        // guardado de cualquier ajuste.
        assertTrue(str_contains($js, "sel.removeAttribute('disabled');"), 'al enseñarlo se habilita');
        assertTrue(str_contains($js, "sel.setAttribute('disabled', '');"), 'y al esconderlo se deshabilita');
        assertTrue(
            str_contains($js, "updateAccessHint();\n        updateIdentifierBox();"),
            'las dos van en el MISMO `change`: separarlas es la forma de que una se quede atrás'
        );
    }

    // =====================================================================
    // Ayudas
    // =====================================================================

    /**
     * Una persona en la lista.
     *
     * El identificador es un argumento y no «cédula» fijo porque el modelo no deja
     * entrar a nadie sin identidad: una persona creada con identificador `cedula` y
     * sin cédula se rechaza (`identificador_vacio`), que es justo lo que la fase 3
     * decidió. Para tener a alguien «sin cédula» hay que meterla por `indicador`.
     *
     * @param array<string,string> $campos
     */
    private static function persona(array $campos, string $identificador = 'cedula'): array
    {
        $r = FormParticipant::create((int) self::$formId, $identificador, $campos);
        assertTrue($r['ok'], 'el alta tiene que entrar: ' . (string) $r['motivo']);

        return self::valorDe((int) $r['id']);
    }

    private static function valorDe(int $id): array
    {
        $fila = FormParticipant::find($id);
        assertTrue($fila !== null, 'la fila ' . $id . ' existe');

        return $fila;
    }

    private static function modo(string $modo, string $identificador): void
    {
        $ajustes = Form::settings((int) self::$formId);
        $ajustes['access'] = ['mode' => $modo, 'identifier' => $identificador];
        Form::saveSettings((int) self::$formId, $ajustes);
    }

    /**
     * El resto de los campos del formulario de ajustes.
     *
     * Se mandan porque el formulario los manda: sin ellos el `merge()` se come
     * valores que ya estaban y la prueba mediría otra cosa.
     */
    private static function restoDeFormulario(): array
    {
        return [
            'quota_enabled' => '0',
            'notify_enabled' => '0',
        ];
    }

    /** @return array{json:array<string,mixed>} */
    private static function guardar(array $post): array
    {
        $_POST = $post + ['_token' => self::$csrfToken];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        Request::setRequestBodyForTests(null);
        Request::resetJsonState();
        Response::setRecordHalt(false);
        Response::setRecordHalt(true);

        ob_start();
        try {
            (new FormController())->updateSettings((int) self::$formId);
        } finally {
            $salida = (string) ob_get_clean();
        }

        Response::setRecordHalt(false);

        $json = [];
        if ($salida !== '' && str_starts_with(ltrim($salida), '{')) {
            $decodificado = json_decode(ltrim($salida), true);
            if (is_array($decodificado)) {
                $json = $decodificado;
            }
        }

        return ['json' => $json];
    }

    /** @return list<string> */
    /**
     * Las `<option>` del desplegable de acceso, por su `id`.
     *
     * Con un regex global sobre la ficha entera salían también las de los otros
     * desplegables de la página —revisión, zona horaria— y la comparación era falsa:
     * una prueba que pasa mirando el markup equivocado es como se comprueba «se
     * arregló» en una superficie y no en la otra.
     *
     * @return list<string>
     */
    private static function opcionesDelDesplegable(string $id = 'access_mode'): array
    {
        $html = self::ficha();
        preg_match('/<select[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>(.*?)<\/select>/s', $html, $m);
        preg_match_all('/<option value="([^"]*)"/', $m[1] ?? '', $coincidencias);

        return $coincidencias[1];
    }

    private static function ficha(): string
    {
        self::modo(
            (string) (Form::settings((int) self::$formId)['access']['mode'] ?? 'public'),
            (string) (Form::settings((int) self::$formId)['access']['identifier'] ?? 'cedula')
        );

        $form = Form::find((int) self::$formId);
        assertTrue($form !== null, 'el formulario existe');

        ob_start();
        try {
            (new FormController())->show((int) self::$formId);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** El JS sin comentarios: un `str_contains` sobre el archivo pasa por el comentario. */
    private static function sinComentarios(string $js): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $js);
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute("DELETE FROM forms WHERE title LIKE 'Roster Id %'");
        $db->execute("DELETE FROM users WHERE email LIKE 'rosterid-%@test.local'");
        Session::destroy();
        self::$ownerId = null;
        self::$formId = null;
    }
}