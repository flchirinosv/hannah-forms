<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\BuilderController;
use App\Controllers\FillController;
use App\Models\Answer;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\LogicEvaluator;

/**
 * P4 — Multi-paso: tipo de campo «page» (salto de paso).
 *
 * Cubre el lado servidor: orden de lectura con páginas (Field::byForm),
 * evaluación de dependencias con páginas (LogicEvaluator) y el submit que
 * ignora los valores que pudieran llegar para un campo tipo página.
 */
class MultiStepTest
{
    private static ?int $userId = null;
    private static int $formId = 0;
    private static string $slug = '';
    private static bool $cleanupHookRegistered = false;

    public function setUp(): void
    {
        self::ensureCleanupHook();

        try {
            $db = \App\Core\Database::getInstance(\App\Core\Config::db());
            $db->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::cleanupResiduals();

        $suffix = bin2hex(random_bytes(4));
        self::$userId = User::create('Test MultiPaso', 'multistep-' . $suffix . '@test.local', 'password');
        self::$formId = Form::create((int) self::$userId, 'Form MultiPaso ' . $suffix);
        $form = Form::find(self::$formId);
        self::$slug = (string) ($form['slug'] ?? '');
    }

    /* ---------- Field::byForm ---------- */

    public function testByFormOrdenaPaginasSeccionesYPreguntas(): void
    {
        $page1 = Field::create(self::$formId, 'page', 'Paso 1', '', '', false, [], [], 1);
        $q1 = Field::create(self::$formId, 'text', 'Pregunta raíz', '', '', true, [], [], 2);
        $s1 = Field::create(self::$formId, 'section', 'Sección', '', '', false, [], [], 3);
        $q2 = Field::create(self::$formId, 'text', 'Pregunta de sección', '', '', false, [], [], 1, $s1);
        $page2 = Field::create(self::$formId, 'page', 'Paso 2', '', '', false, [], [], 4);
        $q3 = Field::create(self::$formId, 'text', 'Última pregunta', '', '', false, [], [], 5);

        $order = Field::byForm(self::$formId);
        assertSame(
            ['page', 'text', 'section', 'text', 'page', 'text'],
            array_map(static fn($f) => $f['type'], $order)
        );
        assertSame([$page1, $q1, $s1, $q2, $page2, $q3], array_map(static fn($f) => (int) $f['id'], $order));

        // Las páginas nunca tienen contenedor (son raíz).
        $pages = array_values(array_filter($order, static fn($f) => $f['type'] === 'page'));
        assertTrue($pages[0]['parent_id'] === null && $pages[1]['parent_id'] === null);
    }

    public function testByFormContenidoPreviosinPaginasSeMantienePlano(): void
    {
        Field::create(self::$formId, 'text', 'A', '', '', false, [], [], 1);
        $order = Field::byForm(self::$formId);
        assertSame(['text'], array_map(static fn($f) => $f['type'], $order));
    }

    /* ---------- LogicEvaluator ---------- */

    public function testDependencyOrderConPaginas(): void
    {
        Field::create(self::$formId, 'page', 'P1', '', '', false, [], [], 1);
        $q1 = Field::create(self::$formId, 'text', 'Q1', '', '', false, [], [], 2);
        $page2 = Field::create(self::$formId, 'page', 'P2', '', '', false, [], [], 3);
        $q2 = Field::create(self::$formId, 'text', 'Q2', '', '', true, [], [], 4);
        Field::setLogic($q2, ['operator' => 'all', 'rules' => [['field_id' => $q1, 'op' => 'filled']]]);

        $fields = Field::byForm(self::$formId);
        $order = LogicEvaluator::dependencyOrder($fields);

        assertTrue($order !== null);
        assertSame(4, count($order));
        // Q2 (que condiciona a Q1 de un paso anterior) debe venir después de Q1.
        assertTrue(array_search($q1, array_map('intval', $order), true) < array_search($q2, array_map('intval', $order), true));
    }

    public function testValidateStructureAceptaPaginas(): void
    {
        Field::create(self::$formId, 'page', 'P1', '', '', false, [], [], 1);
        Field::create(self::$formId, 'text', 'Q1', '', '', false, [], [], 2);

        $fields = Field::byForm(self::$formId);
        $allowed = ['text', 'page'];
        $answerable = ['text'];
        $error = LogicEvaluator::validateStructure($fields, $allowed, $answerable);
        assertTrue($error === null);
    }

    /* ---------- FillController@submit ignora páginas ---------- */

    public function testSubmitIgnoraValoresDeCamposPage(): void
    {
        $page = Field::create(self::$formId, 'page', 'Paso 1', '', '', false, [], [], 1);
        $q = Field::create(self::$formId, 'text', 'Pregunta', '', '', true, [], [], 2);
        Form::update(self::$formId, 'Form MultiPaso', '', 'published');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '4.3.2.1';
        $_SERVER['HTTP_USER_AGENT'] = 'MultiStepTest';
        $_SESSION['_csrf_token'] = 'multi-step-token';
        $_POST = [
            '_token'            => 'multi-step-token',
            'field_' . $page    => 'esto debe ignorarse',
            'field_' . $q       => 'respuesta válida',
        ];

        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();

        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $subs = $db->fetchAll('SELECT id FROM submissions WHERE form_id = ' . self::$formId);
        assertSame(1, count($subs));

        $sid = (int) $subs[0]['id'];
        $answers = $db->fetchAll('SELECT field_id, value FROM answers WHERE submission_id = ' . $sid);
        assertSame(1, count($answers));
        assertSame($q, (int) $answers[0]['field_id']);
        assertSame('respuesta válida', $answers[0]['value']);

        // La submission queda contada en el formulario.
        $sub = Submission::findWithAnswers($sid);
        assertTrue($sub !== null);
    }

    public function testSubmitErrorNoCreaSubmissions(): void
    {
        Field::create(self::$formId, 'page', 'Paso 1', '', '', false, [], [], 1);
        Field::create(self::$formId, 'text', 'Obligatoria', '', '', true, [], [], 2);
        Form::update(self::$formId, 'Form MultiPaso', '', 'published');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '6.7.8.9';
        $_SERVER['HTTP_USER_AGENT'] = 'MultiStepTest';
        $_SESSION['_csrf_token'] = 'multi-step-token-2';
        // Se deja vacío el campo obligatorio a propósito.
        $_POST = ['_token' => 'multi-step-token-2', 'field_999999' => ''] ;

        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();

        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $subs = $db->fetchAll('SELECT id FROM submissions WHERE form_id = ' . self::$formId);
        assertSame(0, count($subs));
    }

    /**
     * `fields.show_header` (0013): el salto de página decide si publica su
     * cabecera, y apagarla NO desaparece el paso.
     *
     * Se mira el HTML renderizado y no el estado en memoria porque lo que importa
     * es lo que ve quien responde: la caja con icono y título. El bloque, su
     * `aria-label` y la insignia del pie se siguen pintando, así que el paso
     * cuenta igual — que es justo lo que ata la segunda aserción.
     */
    public function testElSaltoDePaginaPuedeOcultarSuCabecera(): void
    {
        Field::create(self::$formId, 'page', 'Paso 1', '', '', false, [], [], 1);
        Field::create(self::$formId, 'text', 'Primera', '', '', false, [], [], 2);
        $page2 = Field::create(self::$formId, 'page', 'Paso 2', '', '', false, [], [], 3, null, null, false, false);
        Field::create(self::$formId, 'text', 'Segunda', '', '', false, [], [], 4);
        Form::update(self::$formId, 'Form Cabecera', '', 'published');

        assertSame(0, (int) (Field::find($page2)['show_header'] ?? -1), 'show_header debe volver como 0');

        $html = $this->renderShow();
        // Se cuenta la CAJA, no el texto: `aria-label="Paso 2 de 2"` también
        // contiene «Paso 2», y buscar la cadena daba verde con la cabecera
        // pintada y por el motivo equivocado.
        assertSame(1, substr_count($html, 'class="vf-page-head"'), 'solo el paso 1 publica su caja');
        assertTrue(
            str_contains($html, 'aria-label="Paso 2 de 2"'),
            'el paso sigue existiendo y nombrándose aunque su cabecera esté apagada'
        );

        // Y al revés: el flag es lo único que decide, no el contenido.
        Field::updateField($page2, 'page', 'Paso 2', '', '', false, [], [], 3, null, null, false, true);
        $html = $this->renderShow();
        assertSame(2, substr_count($html, 'class="vf-page-head"'), 'con show_header=1 la caja vuelve');
    }

    /**
     * La otra mitad del ajuste, y la que no se veía: el interruptor NO decide
     * qué dice la cabecera, solo si se pinta. Un salto de página nace SIN
     * título, así que «encendido y sin escribir nada» es el estado por defecto de
     * cualquiera que active el interruptor y no escriba nada — y ahí no hay nada
     * que pintar. La caja con el icono y el cuerpo vacío no dice de qué paso se
     * trata, cuando el pie ya lleva «Paso 2 de 3» y el `aria-label` del bloque lo
     * nombra igual.
     *
     * Se fija por qué NO se pinta una cabecera vacía y no solo que el flag manda:
     * la tentación de «que el interruptor siempre se note» es pintarla igual, y
     * entonces un constructor con el preview lleno de iconos enseñaría algo que
     * el formulario publicado no tiene.
     */
    public function testLaCabeceraDelPasoNoSePintaSinTituloNiDescripcion(): void
    {
        Field::create(self::$formId, 'page', 'Paso 1', '', '', false, [], [], 1);
        Field::create(self::$formId, 'text', 'Primera', '', '', false, [], [], 2);
        // Sin título y sin descripción, pero con el interruptor ENCENDIDO.
        $page2 = Field::create(self::$formId, 'page', '', '', '', false, [], [], 3, null, null, false, true);
        Field::create(self::$formId, 'text', 'Segunda', '', '', false, [], [], 4);
        Form::update(self::$formId, 'Form Cabecera', '', 'published');

        assertSame(1, (int) (Field::find($page2)['show_header'] ?? -1), 'el interruptor está encendido');
        $html = self::renderShow();
        assertSame(1, substr_count($html, 'class="vf-page-head"'), 'sin contenido no se pinta una caja vacía');
        assertTrue(
            str_contains($html, 'aria-label="Paso 2 de 2"'),
            'y el paso sigue existiendo: lo que falta es la cabecera, no el salto'
        );

        // Con descripción sí sale, aunque el título siga vacío: el interruptor
        // permite cabecera con una sola de las dos cosas.
        Field::updateField($page2, 'page', '', '', 'Solo la descripción', false, [], [], 3, null, null, false, true);
        $html = self::renderShow();
        assertSame(2, substr_count($html, 'class="vf-page-head"'), 'una descripción basta para que la caja salga');
        assertTrue(str_contains($html, 'Solo la descripción'), 'y sale el texto de verdad');

        // Y una etiqueta de solo espacios es un título vacío: el fill recorta, y
        // el preview tiene que recortar igual (lo ata `BuilderPreviewParityTest`).
        $page3 = Field::create(self::$formId, 'page', '   ', '', '', false, [], [], 5, null, null, false, true);
        Field::create(self::$formId, 'text', 'Tercera', '', '', false, [], [], 6);
        Form::update(self::$formId, 'Form Cabecera', '', 'published');
        $html = self::renderShow();
        assertSame(2, substr_count($html, 'class="vf-page-head"'), 'espacios no son un título');
        unset($page3);
    }

    /**
 * La otra mitad del ajuste: el payload del constructor → la columna.
 *
 * `show_header` no cabe en `validations` ni en `options`, así que viaja por su
 * propia columna (0013), y el servidor tiene que distinguir quién la consulta:
 * el salto de página sí, el resto de tipos no (para un texto no hay cabecera que
 * apagar, y guardar el flag ahí sería un dato que nada lee).
 */
    public function testElAjusteDeCabeceraLlegaPorElGuardadoDelConstructor(): void
    {
        \App\Core\Auth::assumeLoggedIn((int) self::$userId);
        $token = 'multi-step-cabecera';
        \App\Core\Session::set('_csrf_token', $token);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];
        \App\Core\Request::setRequestBodyForTests(json_encode([
            'title'  => 'Form Cabecera',
            'status' => 'draft',
            'fields' => [
                ['_uid' => 'field-1', 'type' => 'page', 'label' => 'Paso 1', 'show_header' => false],
                ['_uid' => 'field-2', 'type' => 'text', 'label' => 'Pregunta', 'show_header' => false],
            ],
        ]));
        \App\Core\Request::resetJsonState();

        \App\Core\Response::setRecordHalt(true);
        ob_start();
        try {
            (new BuilderController())->saveStructure((int) self::$formId);
        } finally {
            $out = (string) ob_get_clean();
            \App\Core\Response::setRecordHalt(false);
        }
        $json = json_decode($out, true);
        assertTrue((bool) ($json['ok'] ?? false), 'el guardado debe salir bien: ' . substr($out, 0, 160));

        $rows = Field::byForm((int) self::$formId);
        $porTipo = [];
        foreach ($rows as $r) {
            $porTipo[(string) $r['type']] = (int) ($r['show_header'] ?? -1);
        }
        assertSame(0, $porTipo['page'] ?? -2, 'el salto de página guarda el ajuste');
        assertSame(1, $porTipo['text'] ?? -2, 'un texto lo ignora: siempre con cabecera');

        \App\Core\Auth::forgetCache();
        \App\Core\Session::destroy();
    }

    /* ---------- Helpers ---------- */

    private static function renderShow(): string
    {
        $controller = new FillController();
        ob_start();
        $controller->show(self::$slug);
        return (string) ob_get_clean();
    }

    private static function cleanupResiduals(): void
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $users = $db->fetchAll("SELECT id FROM users WHERE email LIKE '%@test.local'");
        foreach ($users as $u) {
            $db->execute('DELETE FROM users WHERE id = :id', ['id' => (int) $u['id']]);
        }
        // Limpiar rate limits de envíos de formularios de prueba.
        if (self::$formId > 0) {
            $db->execute('DELETE FROM rate_limits WHERE scope = :scope', ['scope' => 'submit:' . self::$formId]);
        }
        self::$userId = null;
        self::$formId = 0;
        self::$slug = '';
    }

    private static function ensureCleanupHook(): void
    {
        if (!self::$cleanupHookRegistered) {
            self::$cleanupHookRegistered = true;
            // Un cleanup de salida NO puede matar el proceso: este hook corre
            // al terminar, y un `getInstance()` fallido (mysqld parado, socket,
            // credenciales) lanzaría PDOException y se llevaría por delante el
            // informe entero del runner, que es justo cuando más falta hace.
            // Se traga el fallo a propósito: si no se pudo limpiar, se limpia
            // a mano. OJO: la config ya NO es una causa posible de ese fallo —
            // el runner carga el `.env` (ver `scripts/test.php`).
            register_shutdown_function(static function () {
                try {
                    self::cleanupResiduals();
                } catch (\Throwable $e) {
                    // Sin conexión no hay residuales que borrar.
                }
            });
        }
    }
}