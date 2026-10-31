<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;
use App\Models\Answer;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\Pii;

/**
 * F1 — Enmascarado de datos personales.
 *
 * Dos mitades: las funciones puras (`maskIp`, `maskValue`) se pueden comprobar
 * sin BD, pero el comportamiento que importa de verdad es "¿este usuario ve el
 * dato o el marcador?", y eso depende de la matriz de permisos REAL. Por eso la
 * parte de integración crea usuarios de verdad con los roles `user` (que no
 * tiene `responses.pii`) y `admin` (que sí) y comprueba los dos lados del
 * permiso, no solo la rama que sale.
 *
 * La BD se necesita también para lo otro que se comprueba aquí: que
 * `is_pii` sobreviva el viaje de ida y vuelta por `fields` (si el flag se
 * perdiera al guardar, el enmascarado no se aplicaría y el test de pantalla
 * pasaría con datos vacíos sin que nadie se entere).
 */
class PiiMaskingTest
{
    private static ?int $userId = null;
    private static ?int $adminId = null;
    private static ?int $formId = null;
    private static ?int $plainFieldId = null;
    private static ?int $piiFieldId = null;

    public function setUp(): void
    {
        self::cleanupResiduals();

        $suffix = bin2hex(random_bytes(4));
        self::$userId = User::create('PII User', 'pii-user-' . $suffix . '@test.local', 'password', 2);
        self::$adminId = User::create('PII Admin', 'pii-admin-' . $suffix . '@test.local', 'password', 1);

        self::$formId = Form::create((int) self::$userId, 'PII Test Form');
        self::$plainFieldId = Field::create((int) self::$formId, 'text', '¿Qué opinas del servicio?');
        self::$piiFieldId = Field::create((int) self::$formId, 'text', 'Nombre completo', '', '', false, [], [], 0, null, null, true);
    }

    public function tearDown(): void
    {
        $db = Database::getInstance(\App\Core\Config::db());
        foreach ([self::$formId, self::$userId, self::$adminId] as $id) {
            if ($id !== null) {
                $db->execute('DELETE FROM forms WHERE id = :id', ['id' => $id]);
                $db->execute('DELETE FROM users WHERE id = :id', ['id' => $id]);
            }
        }
        Auth::forgetCache();
        Session::destroy();
        self::$userId = null;
        self::$adminId = null;
        self::$formId = null;
        self::$plainFieldId = null;
        self::$piiFieldId = null;
    }

    // ---- Funciones puras: la política de enmascarado ----

    public function testLaIpConservaSoloLosOctetosDeGeolocalizacion(): void
    {
        assertSame('192.168.1.x', Pii::maskIp('192.168.1.50'));
        assertSame('10.0.0.x', Pii::maskIp('10.0.0.7'));
        assertSame('2001:db8::x', Pii::maskIp('2001:db8:85a3::8a2e:370:7334'));
    }

    public function testUnaIpVaciaOInvalidaNoSeConvierteEnMarcador(): void
    {
        // No hay dato, no hay nada que ocultar: convertir un NULL en "[oculto]"
        // haría creer que se ocultó algo que nunca se recogió.
        assertNull(Pii::maskIp(null));
        assertSame('', Pii::maskIp(''));
        // Una IP que no es IP sí se oculta: no se sabe qué es, y lo que no se
        // sabe no se enseña.
        assertSame(Pii::MASK, Pii::maskIp('no-es-una-ip'));
    }

    public function testElUserAgentSeOcultaEntero(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        assertSame(Pii::MASK, Pii::maskUserAgent($ua));
        assertNull(Pii::maskUserAgent(null));
    }

    public function testElEmailConservaLoNoIdentificativoYOcultaElResto(): void
    {
        assertSame('j***@e***.com', Pii::maskValue('juan.perez@ejemplo.com', 'email'));
        // Dos emails del mismo dominio NO deben seguir siendo distinguibles por
        // el dominio completo.
        assertSame('a***@e***.org', Pii::maskValue('ana@ejemplo.org', 'email'));
    }

    public function testElTelefonoConservaElPrefijoYEnmascaraElNumero(): void
    {
        // Los dos primeros dígitos son el prefijo (+58, 04), que es lo único
        // que no identifica a la persona. Todo lo demás se enmascara, incluidos
        // los últimos: "los 4 últimos" bastan para localizar a alguien en un
        // directorio, y es justo el truco que se usa para eluxar el filtro.
        assertSame('+58 ***-***-****', Pii::maskValue('+58 412-123-4567', 'phone'));
        assertSame('04**-*******', Pii::maskValue('0412-1234567', 'phone'));
    }

    public function testUnTelefonoSinDigitosSeOcultaEntero(): void
    {
        // Un campo "teléfono" que en realidad guarda texto libre no tiene
        // estructura que preservar, y enmascarar "por posiciones" no protegería
        // nada: se oculta entero.
        assertSame(Pii::MASK, Pii::maskValue('llamar por las tardes', 'phone'));
    }

    public function testUnTextoLibreSeOcultaEntero(): void
    {
        // El tipo `text` no tiene estructura que preservar: cualquier subcadena
        // de un nombre completo identifica a la persona.
        assertSame(Pii::MASK, Pii::maskValue('Juan Pérez', 'text'));
        assertSame(Pii::MASK, Pii::maskValue('Mimidt', 'text'));
    }

    public function testUnValorVacioNoSeConvierteEnMarcador(): void
    {
        assertSame('', Pii::maskValue('   ', 'text'));
    }

    // ---- El flag en la base de datos ----

    public function testElFlagIsPiiSobreviveElGuardadoYLaLectura(): void
    {
        $fields = Field::byForm((int) self::$formId);
        $porId = [];
        foreach ($fields as $f) {
            $porId[(int) $f['id']] = $f;
        }

        assertTrue(isset($porId[(int) self::$piiFieldId]), 'el campo PII debe existir');
        assertTrue(isset($porId[(int) self::$plainFieldId]), 'el campo normal debe existir');
        assertSame(1, (int) $porId[(int) self::$piiFieldId]['is_pii']);
        assertSame(0, (int) $porId[(int) self::$plainFieldId]['is_pii']);
    }

    public function testActualizarUnCampoLimpiaSuMarcaDePii(): void
    {
        // El builder manda SIEMPRE el estado completo del campo, así que quitar
        // la marca en la interfaz tiene que llegar a la BD. Si `updateField`
        // ignorara el parámetro, la marca se quedaría pegada para siempre.
        Field::updateField(
            (int) self::$piiFieldId,
            'text',
            'Nombre completo',
            '',
            '',
            false,
            [],
            [],
            0,
            null,
            null,
            false
        );

        $found = Field::find((int) self::$piiFieldId);
        assertNotNull($found);
        assertSame(0, (int) $found['is_pii']);
    }

    // ---- El permiso decide qué se ve ----

    public function testElRolUserNoTieneElPermisoYVeMarcadores(): void
    {
        self::asUser((int) self::$userId);
        assertFalse(Pii::canView(), 'el rol `user` no debe tener responses.pii por defecto');

        $fields = Field::byForm((int) self::$formId);
        $respuestas = [
            (int) self::$plainFieldId => 'Muy buen servicio',
            (int) self::$piiFieldId    => 'Juan Pérez',
        ];

        $mapeado = Pii::maybeMaskAnswers($respuestas, $fields);

        assertSame('Muy buen servicio', $mapeado[(int) self::$plainFieldId], 'un campo no marcado NO se toca');
        assertSame(Pii::MASK, $mapeado[(int) self::$piiFieldId], 'un campo marcado se oculta entero');
    }

    public function testElAdminVeLaPiiEnClaro(): void
    {
        self::asUser((int) self::$adminId);
        assertTrue(Pii::canView(), 'el rol `admin` debe tener responses.pii por defecto');

        $fields = Field::byForm((int) self::$formId);
        $respuestas = [(int) self::$piiFieldId => 'Juan Pérez'];

        $mapeado = Pii::maybeMaskAnswers($respuestas, $fields);

        assertSame('Juan Pérez', $mapeado[(int) self::$piiFieldId], 'con el permiso, el valor sale intacto');
    }

    public function testSinSesionTodoSaleEnmascarado(): void
    {
        Session::destroy();
        Auth::forgetCache();
        assertFalse(Pii::canView(), 'sin usuario no se ve PII');

        $row = ['id' => 1, 'ip_address' => '192.168.1.50', 'user_agent' => 'Chrome/120', 'finished_at' => '2026-01-01 00:00:00'];
        $mapeado = Pii::maybeMaskSubmission($row);

        assertSame('192.168.1.x', $mapeado['ip_address']);
        assertSame(Pii::MASK, $mapeado['user_agent']);
        assertSame('2026-01-01 00:00:00', $mapeado['finished_at'], 'la fecha no identifica y no se toca');
        assertSame(1, (int) $mapeado['id'], 'el id no se toca');
    }

    public function testConElPermisoLaMetadataNoSeToca(): void
    {
        self::asUser((int) self::$adminId);
        $row = ['id' => 1, 'ip_address' => '192.168.1.50', 'user_agent' => 'Chrome/120'];
        assertSame($row, Pii::maybeMaskSubmission($row));
    }

    public function testUnCampoMarcadoComoAdjuntoSeOcultaEntero(): void
    {
        self::asUser((int) self::$userId);

        // El NOMBRE del archivo es el dato (una cédula escaneada se llama
        // "cedula-juan-perez.pdf"), así que un campo file marcado no se
        // enmascara "parcialmente": se oculta entero.
        $json = (string) \App\Services\Uploader::encode([
            ['name' => 'cedula-juan-perez.pdf', 'size' => 1234, 'mime' => 'application/pdf', 'stored' => 'x/y.pdf'],
        ]);
        $fields = [['id' => 7, 'type' => 'file', 'is_pii' => 1]];
        $mapeado = Pii::maybeMaskAnswers([7 => $json], $fields);

        assertSame(Pii::MASK, $mapeado[7], 'un adjunto personal no debe filtrar su nombre');
        assertTrue(
            !str_contains((string) $mapeado[7], 'juan'),
            'el nombre del archivo debe desaparecer por completo'
        );
    }

    // ---- La analítica también es un canal de salida ----
    //
    // Regresión real: `AnalyticsController` solo llamaba a `Pii::` DENTRO de
    // `exportCsv()`, así que el HTML —la carga inicial y el lazy-load del
    // pager— imprimía los valores crudos de los campos marcados. El listado de
    // respuestas sí los tapaba: se veía el mismo dato tapado en una pantalla y en
    // claro en la de al lado, y `PiiMaskingTest` no lo-notaba porque probaba los
    // otros tres canales.

    public function testElDetalleDeLaAnaliticaEnmascaraLosCamposMarcados(): void
    {
        self::asUser((int) self::$userId);
        assertFalse(Pii::canView());

        $sid = Submission::create((int) self::$formId);
        Answer::create($sid, (int) self::$piiFieldId, 'Juan Pérez');
        Answer::create($sid, (int) self::$plainFieldId, 'Muy buen servicio');

        $html = $this->renderDetalle((int) $sid);

        assertTrue(
            !str_contains($html, 'Juan Pérez'),
            'el detalle de la analítica no debe mostrar el valor de un campo marcado'
        );
        assertTrue(str_contains($html, Pii::MASK), 'debe mostrar el marcador: ' . $html);
        assertTrue(
            str_contains($html, 'Muy buen servicio'),
            'un campo NO marcado se sigue viendo en claro'
        );
    }

    public function testElDetalleDeLaAnaliticaEnClaroConElPermiso(): void
    {
        self::asUser((int) self::$adminId);

        $sid = Submission::create((int) self::$formId);
        Answer::create($sid, (int) self::$piiFieldId, 'Juan Pérez');

        $html = $this->renderDetalle((int) $sid);

        assertTrue(str_contains($html, 'Juan Pérez'), 'con el permiso, el detalle va en claro: ' . $html);
    }

    public function testElMarcadorNoSeReinterpretaComoUnaListaJson(): void
    {
        // `[oculto]` empieza por `[`, que es justo lo que el bloque de JSON de
        // `_rows.php` mira para dar formato a las respuestas de checkbox. Si el
        // enmascarado se hiciera después de ese bloque, `json_decode` fallaría y
        // el valor se imprimiría vacío o sin el marcador. Este test ata que el
        // orden es el correcto.
        self::asUser((int) self::$userId);

        $sid = Submission::create((int) self::$formId);
        Answer::create($sid, (int) self::$piiFieldId, 'Juan Pérez');

        $html = $this->renderDetalle((int) $sid);

        assertTrue(str_contains($html, Pii::MASK), 'el marcador tiene que llegar entero a la celda');
    }

    public function testLosGraficosFundenLasRespuestasPersonalesEnUnaSolaBarra(): void
    {
        // El agregado se calcula en SQL agrupando por `(field_id, value)`, así que
        // a la hora de enmascarar hay 30 valores distintos. Sin fundirlos saldrían
        // 30 barras `[oculto]` de un 1 cada una, que parece un dato real.
        self::asUser((int) self::$userId);

        $selectId = Field::create(
            (int) self::$formId,
            'select',
            'Enfermedad de base',
            '',
            '',
            false,
            [['value' => 'diabetes', 'label' => 'Diabetes'], ['value' => 'ninguna', 'label' => 'Ninguna']],
            [],
            10,
            null,
            null,
            true
        );

        $valores = ['diabetes', 'hipertension', 'asma', 'diabetes', 'ninguna', 'hipertension', 'epilepsia'];
        foreach ($valores as $valor) {
            Answer::create(Submission::create((int) self::$formId), $selectId, $valor);
        }

        $stat = $this->agregadoDe($selectId, true);
        $values = $stat['values'];

        assertSame(1, count($values), 'todas las respuestas personales deben fundirse en un solo valor');
        assertSame(Pii::MASK, (string) $values[0]['value'], 'la etiqueta del gráfico no debe llevar el dato');
        assertSame(count($valores), (int) $values[0]['c'], 'el total sí debe conservarse: es información útil');
    }

    public function testUnPromedioPersonalSaleVacioYNoInventado(): void
    {
        // Un campo numérico marcado enmascarado no puede devolver un promedio:
        // `numerico()` filtra lo que no es número con una regex, así que `[oculto]`
        // no entra. Lo que importa es que NO se muestre un promedio calculado
        // sobre datos que el usuario no puede mirar.
        self::asUser((int) self::$userId);

        $numId = Field::create((int) self::$formId, 'number', 'Ingresos mensuales', '', '', false, [], [], 11, null, null, true);
        foreach ([1200, 3400, 5100] as $valor) {
            Answer::create(Submission::create((int) self::$formId), $numId, (string) $valor);
        }

        $stat = $this->agregadoDe($numId, true);

        assertNull($stat['values']['avg_value'], 'no se puede promediar sobre valores que no se pueden ver');
        assertNull($stat['values']['min_value']);
        assertNull($stat['values']['max_value']);
        assertSame(0, (int) $stat['values']['answer_count']);
    }

    public function testLosGraficosVanEnClaroConElPermiso(): void
    {
        self::asUser((int) self::$adminId);

        $selectId = Field::create(
            (int) self::$formId,
            'select',
            'Enfermedad de base',
            '',
            '',
            false,
            [['value' => 'diabetes', 'label' => 'Diabetes']],
            [],
            12,
            null,
            null,
            true
        );
        foreach (['diabetes', 'diabetes', 'ninguna'] as $valor) {
            Answer::create(Submission::create((int) self::$formId), $selectId, $valor);
        }

        $stat = $this->agregadoDe($selectId, false);
        $values = $stat['values'];

        assertTrue(count($values) >= 2, 'con el permiso los valores se agrupan por su valor real: ' . json_encode($values));
        $etiquetas = array_map(static fn($v) => (string) $v['value'], $values);
        assertTrue(in_array('diabetes', $etiquetas, true), 'debe verse la etiqueta real');
    }

    /**
     * Renderiza `analytics/_rows` con los mismos datos y la misma decisión que
     * el controlador: la lista de tipos llega YA filtrada por el permiso.
     */
    private function renderDetalle(int $submissionId): string
    {
        $fields = Field::byForm((int) self::$formId);

        return (string) \App\Core\View::partial('analytics/_rows', [
            'submissions'      => [['id' => $submissionId, 'finished_at' => '2026-01-01 10:00:00']],
            'answers'          => Submission::withAnswersByIds([$submissionId]),
            'answerableFields' => $fields,
            'offset'           => 1,
            'piiTypes'         => Pii::canView() ? [] : Pii::piiFieldTypes($fields),
        ]);
    }

    /**
     * El agregado de un campo tal como lo pinta la analítica.
     *
     * @param bool $enmascarar qué hacer con la marca de `is_pii`
     */
    private function agregadoDe(int $fieldId, bool $enmascarar): array
    {
        $fields = Field::byForm((int) self::$formId);

        $stats = \App\Services\FieldAggregator::forForm(
            (int) self::$formId,
            $fields,
            [$fieldId],
            99,
            '',
            '',
            $enmascarar ? Pii::piiFieldTypes($fields) : []
        );

        assertArrayHasKey($fieldId, $stats, 'el agregado debe traer el campo pedido');
        return $stats[$fieldId];
    }

    // ---- El permiso existe en la matriz ----

    public function testElPermisoResponsesPiiEstaEnLaMatrizDeAdmin(): void
    {
        // Guarda contra la regresión que importa: si `responses.pii` no llega a
        // la tabla `permissions` (porque alguien añadió la clave al catálogo y
        // olvidó la migración, o al revés), `Auth::can()` da false para TODOS y
        // la app funciona "bien" con todo enmascarado, sin ningún error visible.
        $db = Database::getInstance(\App\Core\Config::db());
        $fila = $db->fetch(
            'SELECT p.id FROM permissions p WHERE p.`key` = :k',
            ['k' => 'responses.pii']
        );
        assertNotNull($fila, 'el permiso responses.pii debe existir en la tabla permissions (¿migración 0006 aplicada?)');

        $otorgado = $db->fetch(
            "SELECT rp.role_id FROM role_permissions rp
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE p.`key` = 'responses.pii' AND rp.role_id = 1"
        );
        assertNotNull($otorgado, 'responses.pii debe estar otorgado al rol admin (id 1) por la migración');
    }

    // ---- El payload de exportación declara qué campos son personales ----

    public function testElPayloadJsonDeclaraQueCamposSonPersonales(): void
    {
        self::asUser((int) self::$adminId);

        $submissionId = Submission::create((int) self::$formId, '192.168.1.50', 'Chrome/120');
        Answer::create($submissionId, (int) self::$plainFieldId, 'Muy buen servicio');
        Answer::create($submissionId, (int) self::$piiFieldId, 'Juan Pérez');

        $bySubmission = Submission::withAnswersByIds([$submissionId]);
        $form = Form::find((int) self::$formId);
        $payload = Submission::exportPayload($form, $bySubmission, 1);

        $porCampo = [];
        foreach ($payload['form']['fields'] as $f) {
            $porCampo[(int) $f['id']] = $f;
        }

        assertSame(1, (int) $porCampo[(int) self::$piiFieldId]['is_pii']);
        assertSame(0, (int) $porCampo[(int) self::$plainFieldId]['is_pii']);
    }

    /**
     * Deja al usuario `userId` como el de la sesión.
     *
     * No se usa `Auth::login()` a propósito: es la puerta de producción, y el
     * seam de test es `Auth::assumeLoggedIn()`.
     * `Auth::id()` lee `Session::get('user_id')` y los permisos se cachean por
     * request, así que con `forgetCache()` el estado es idéntico.
     */
    private static function asUser(int $userId): void
    {
        Auth::assumeLoggedIn($userId);
        Auth::forgetCache();
    }

    // ---- La marca no se pierde por el camino ----

    public function testDuplicarUnFormularioCopiaLaMarcaDePii(): void
    {
        // Regresión real: `Form::duplicate()` reinsertaba los campos con una
        // lista fija de argumentos y, al no pasar `is_pii`, la copia quedaba
        // SIN marcar. El dueño ve un formulario idéntico que filtra IP,
        // user-agent y respuestas personales, sin ningún aviso.
        $dupId = Form::duplicate((int) self::$formId);
        assertTrue($dupId > 0, 'duplicate() debe devolver el id del nuevo formulario');

        $campos = [];
        foreach (Field::byForm($dupId) as $f) {
            $campos[$f['label']] = (int) $f['is_pii'];
        }

        assertSame(1, $campos['Nombre completo'] ?? -1, 'el campo personal debe seguir marcado en la copia');
        assertSame(0, $campos['¿Qué opinas del servicio?'] ?? -1, 'el campo normal debe seguir sin marcar');

        Database::getInstance(\App\Core\Config::db())->execute('DELETE FROM forms WHERE id = :id', ['id' => $dupId]);
    }

    public function testLaPlantillaExportaEImportaLaMarcaDePii(): void
    {
        $form = Form::find((int) self::$formId);
        $payload = \App\Services\TemplateService::exportPayload($form, Form::settingsFrom($form));

        $porEtiqueta = [];
        foreach ($payload['fields'] as $f) {
            $porEtiqueta[(string) $f['label']] = (bool) ($f['is_pii'] ?? false);
        }
        assertTrue($porEtiqueta['Nombre completo'] ?? false, 'la plantilla debe exportar la marca del campo personal');
        assertFalse($porEtiqueta['¿Qué opinas del servicio?'] ?? true, 'la plantilla no debe marcar campos normales');

        // Importarla en un formulario nuevo debe conservar la marca. Mismo
        // camino que el controlador: normalizeStructure() + apply().
        $nuevoId = Form::create((int) self::$userId, 'PII Test Form Importado');
        $normalized = \App\Services\TemplateService::normalizeStructure($payload['fields']);
        assertNull($normalized['error'], 'la plantilla debe normalizar sin error: ' . (string) ($normalized['error'] ?? ''));
        assertNull(
            \App\Services\TemplateService::apply($nuevoId, $normalized['items']),
            'la plantilla debe aplicarse sin error'
        );

        $campos = [];
        foreach (Field::byForm($nuevoId) as $f) {
            $campos[$f['label']] = (int) $f['is_pii'];
        }
        assertSame(1, $campos['Nombre completo'] ?? -1, 'el campo importado debe conservar la marca');

        Database::getInstance(\App\Core\Config::db())->execute('DELETE FROM forms WHERE id = :id', ['id' => $nuevoId]);
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(\App\Core\Config::db());
        $db->execute("DELETE FROM forms WHERE title = 'PII Test Form'");
        $db->execute("DELETE FROM users WHERE email LIKE '%@test.local'");
    }
}
