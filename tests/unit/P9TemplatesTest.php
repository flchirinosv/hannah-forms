<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Field;
use App\Models\Form;
use App\Models\User;
use App\Services\TemplateService;

/**
 * P9 — Plantillas JSON, export de estructura y vista imprimible.
 *
 * Cubre el servicio de plantillas (listado, carga segura de keys, normalización
 * del formato declarativo con refs, aplicación transaccional a un formulario,
 * export round-trip) y el flujo de alta desde plantilla en FormController.
 */
class P9TemplatesTest
{
    private static ?int $userId = null;
    private static bool $envLoaded = false;

    public function setUp(): void
    {
        self::loadEnv();

        try {
            $db = \App\Core\Database::getInstance(\App\Core\Config::db());
            $db->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        if (self::$userId === null) {
            // Limpieza SOLO la primera vez: después el usuario vive en toda la
            // clase (volver a borrarlo rompería los forms ya creados).
            self::cleanupResiduals();
            $suffix = bin2hex(random_bytes(4));
            self::$userId = User::create('Test P9', 'p9-' . $suffix . '@test.local', 'password');
        }
    }

    /* ---------- Listado y carga ---------- */

    public function testListDevuelveLasSeisPlantillasInstaladas(): void
    {
        $list = TemplateService::list();
        assertTrue(count($list) >= 6);

        $keys = array_column($list, 'key');
        foreach (['encuesta-satisfaccion', 'evento-inscripcion', 'quiz-conocimiento', 'sondeo-rapido'] as $key) {
            assertTrue(in_array($key, $keys, true), 'Falta la plantilla ' . $key);
        }
        foreach ($list as $entry) {
            assertArrayHasKey('name', $entry);
            assertArrayHasKey('description', $entry);
            assertTrue($entry['questions'] > 0);
        }
    }

    public function testTodasLasPlantillasInstaladasNormalizan(): void
    {
        // Regresión: cualquier .json que se agregue a database/templates tiene
        // que pasar la validación declarativa (refs, tipos, padres, lógica).
        foreach (TemplateService::list() as $entry) {
            $tpl = TemplateService::load((string) $entry['key']);
            assertTrue($tpl !== null, 'No se pudo cargar ' . $entry['key']);
            $normalized = TemplateService::normalizeStructure((array) $tpl['fields']);
            assertSame(null, $normalized['error'], 'Plantilla ' . $entry['key'] . ': ' . $normalized['error']);
            assertSame(count((array) $tpl['fields']), count($normalized['items']));
        }
    }

    public function testLoadRechazaKeysInvalidas(): void
    {
        assertSame(null, TemplateService::load(''));
        assertSame(null, TemplateService::load('no-existe'));
        // Path traversal y cosas raras: la key se filtra por patrón.
        assertSame(null, TemplateService::load('../../config/config'));
        assertSame(null, TemplateService::load('con espacio'));
    }

    /* ---------- Normalización ---------- */

    public function testNormalizeStructureResuelveRefsAUids(): void
    {
        $normalized = TemplateService::normalizeStructure([
            ['ref' => 1, 'type' => 'section', 'label' => 'Sección'],
            ['ref' => 2, 'type' => 'nps', 'label' => 'NPS', 'parent' => 1, 'is_required' => true],
            ['ref' => 3, 'type' => 'text', 'label' => 'Comentario', 'parent' => 1, 'logic' => [
                'operator' => 'all',
                'rules' => [['field_id' => 2, 'op' => 'lte', 'value' => '6']],
            ]],
        ]);

        assertSame(null, $normalized['error']);
        $items = $normalized['items'];
        assertSame(3, count($items));

        assertSame('tpl-1', $items[0]['_uid']);
        assertSame(null, $items[0]['parent_id']);

        assertSame('tpl-1', $items[1]['parent_id']);
        assertTrue($items[1]['is_required']);

        // La lógica pasa de refs a _uid de origen.
        assertSame('tpl-2', $items[2]['logic']['rules'][0]['field_id']);
        assertSame('lte', $items[2]['logic']['rules'][0]['op']);
    }

    public function testNormalizeConvierteOpcionesDeStringsYObjetos(): void
    {
        $normalized = TemplateService::normalizeStructure([
            ['ref' => 1, 'type' => 'radio', 'label' => 'Elige', 'options' => ['rojo', '', 'azul']],
            ['ref' => 2, 'type' => 'matrix', 'label' => 'Matriz', 'options' => [
                ['value' => 'Fila 1', 'group' => 'rows'],
                ['value' => 'Sí', 'group' => 'cols'],
            ]],
        ]);

        assertSame(null, $normalized['error']);
        assertSame(
            [['value' => 'rojo'], ['value' => 'azul']],
            $normalized['items'][0]['options']
        );
        assertSame('rows', $normalized['items'][1]['options'][0]['group']);
    }

    public function testNormalizeRechazaErroresDeEstructura(): void
    {
        // Ref duplicado.
        $r = TemplateService::normalizeStructure([
            ['ref' => 1, 'type' => 'text', 'label' => 'A'],
            ['ref' => 1, 'type' => 'text', 'label' => 'B'],
        ]);
        assertTrue($r['error'] !== null);

        // Tipo fuera de la whitelist.
        $r = TemplateService::normalizeStructure([['ref' => 1, 'type' => 'script', 'label' => 'X']]);
        assertTrue($r['error'] !== null);

        // Pregunta colgando de algo que no es section.
        $r = TemplateService::normalizeStructure([
            ['ref' => 1, 'type' => 'text', 'label' => 'Raíz'],
            ['ref' => 2, 'type' => 'text', 'label' => 'Hija', 'parent' => 1],
        ]);
        assertTrue($r['error'] !== null);

        // Sección declarada DESPUÉS de su pregunta.
        $r = TemplateService::normalizeStructure([
            ['ref' => 1, 'type' => 'text', 'label' => 'Hija', 'parent' => 2],
            ['ref' => 2, 'type' => 'section', 'label' => 'Sección'],
        ]);
        assertTrue($r['error'] !== null);

        // Condición a sí misma.
        $r = TemplateService::normalizeStructure([
            ['ref' => 1, 'type' => 'text', 'label' => 'A', 'logic' => [
                'rules' => [['field_id' => 1, 'op' => 'filled', 'value' => '']],
            ]],
        ]);
        assertTrue($r['error'] !== null);

        // Condición apuntando a una section.
        $r = TemplateService::normalizeStructure([
            ['ref' => 1, 'type' => 'section', 'label' => 'Sección'],
            ['ref' => 2, 'type' => 'text', 'label' => 'A', 'logic' => [
                'rules' => [['field_id' => 1, 'op' => 'filled', 'value' => '']],
            ]],
        ]);
        assertTrue($r['error'] !== null);
    }

    /* ---------- Apply ---------- */

    public function testApplyCreaEstructuraRespetandoSeccionesYLogica(): void
    {
        $tpl = TemplateService::load('sondeo-rapido');
        assertTrue($tpl !== null);

        $formId = Form::create((int) self::$userId, 'P9 sondeo');
        $normalized = TemplateService::normalizeStructure((array) $tpl['fields']);
        assertSame(null, $normalized['error']);
        assertSame(null, TemplateService::apply($formId, $normalized['items']));

        $fields = Field::byForm($formId);
        assertSame(3, count($fields));
        assertSame('radio', $fields[0]['type']);
        assertSame('star', $fields[1]['type']);

        // La lógica quedó persistida con ids reales de la BD.
        $logic = $fields[2]['logic'];
        assertTrue(is_array($logic));
        assertSame((int) $fields[1]['id'], (int) $logic['rules'][0]['field_id']);
        assertSame('lte', $logic['rules'][0]['op']);
    }

    public function testApplyAplicaSettingsDeLaPlantilla(): void
    {
        $tpl = TemplateService::load('evento-inscripcion');
        assertTrue($tpl !== null);
        assertTrue(!empty($tpl['settings']['quota']['max']));

        $formId = Form::create((int) self::$userId, 'P9 evento');
        Form::saveSettings($formId, (array) $tpl['settings']);
        $normalized = TemplateService::normalizeStructure((array) $tpl['fields']);
        assertSame(null, TemplateService::apply($formId, $normalized['items']));

        $settings = Form::settings($formId);
        assertTrue($settings['quota']['enabled']);
        assertSame(150, $settings['quota']['max']);

        // Las secciones anidan: la tercera field_ es hija de la primera section.
        $fields = Field::byForm($formId);
        $sections = array_values(array_filter($fields, static fn($f) => $f['type'] === 'section'));
        assertTrue(count($sections) >= 2);
        assertTrue($fields[1]['parent_id'] !== null);
        assertSame((int) $sections[0]['id'], (int) $fields[1]['parent_id']);
    }

    public function testApplyRechazaCiclosYNoDejaCampos(): void
    {
        $formId = Form::create((int) self::$userId, 'P9 ciclo');
        $items = [
            [
                '_uid'    => 'tpl-1',
                'type'    => 'text',
                'label'   => 'A',
                'options' => [],
                'validations' => [],
                'parent_id' => null,
                'logic'   => ['operator' => 'all', 'rules' => [['field_id' => 'tpl-2', 'op' => 'filled', 'value' => '']]],
            ],
            [
                '_uid'    => 'tpl-2',
                'type'    => 'text',
                'label'   => 'B',
                'options' => [],
                'validations' => [],
                'parent_id' => null,
                'logic'   => ['operator' => 'all', 'rules' => [['field_id' => 'tpl-1', 'op' => 'filled', 'value' => '']]],
            ],
        ];

        $error = TemplateService::apply($formId, $items);
        assertTrue($error !== null);
        // Rollback: no quedó ni un campo a medias.
        assertSame(0, count(Field::byForm($formId)));
    }

    /* ---------- Export round-trip ---------- */

    public function testExportPayloadEsRoundTrippable(): void
    {
        $tpl = TemplateService::load('encuesta-satisfaccion');
        $formId = Form::create((int) self::$userId, 'P9 export');
        $normalized = TemplateService::normalizeStructure((array) $tpl['fields']);
        assertSame(null, TemplateService::apply($formId, $normalized['items']));

        $payload = TemplateService::exportPayload(Form::find($formId), Form::settings($formId));
        assertSame('vanilla-forms', $payload['app']);
        assertSame(1, $payload['version']);
        assertSame(7, count($payload['fields']));

        // Los refs son 1..N en orden de lectura.
        assertSame(range(1, 7), array_column($payload['fields'], 'ref'));
        // Las secciones viajan con su parent resuelto a ref.
        $first = $payload['fields'][0];
        assertSame('section', $first['type']);
        assertSame(1, $first['ref']);
        assertSame(1, $payload['fields'][1]['parent']);

        // Y el export vuelve a normalizar sin errores (o sea: es una plantilla).
        $again = TemplateService::normalizeStructure($payload['fields']);
        assertSame(null, $again['error']);
        assertSame(7, count($again['items']));

        // La lógica conserva la condición en refs.
        $withLogic = array_values(array_filter($payload['fields'], static fn($f) => isset($f['logic'])));
        assertTrue(count($withLogic) >= 2);
        assertTrue(is_int($withLogic[0]['logic']['rules'][0]['field_id']));
    }

    public function testExportStructureControllerEmiteJson(): void
    {
        $formId = Form::create((int) self::$userId, 'P9 export ctrl');
        Field::create($formId, 'text', 'Nombre', '', '', true, [], [], 1);

        $user = $this->loginAsOwner($formId);
        assertTrue($user !== null);

        ob_start();
        (new \App\Controllers\FormController())->exportStructure($formId);
        $json = (string) ob_get_clean();

        $decoded = json_decode($json, true);
        assertTrue(is_array($decoded));
        assertSame('vanilla-forms', $decoded['app']);
        assertSame(1, count($decoded['fields']));
        assertSame('Nombre', $decoded['fields'][0]['label']);
        assertArrayHasKey('settings', $decoded);
    }

    public function testPrintFormRenderizaLaHojaImprimible(): void
    {
        $formId = Form::create((int) self::$userId, 'P9 print');
        Field::create($formId, 'section', 'Sección', '', '', false, [], [], 1);
        Field::create($formId, 'radio', 'Elige', '', 'Ayuda', true, [['value' => 'a'], ['value' => 'b']], [], 1, 0);
        $this->loginAsOwner($formId);

        ob_start();
        (new \App\Controllers\FormController())->printForm($formId);
        $html = (string) ob_get_clean();

        assertTrue(str_contains($html, 'P9 print'));
        assertTrue(str_contains($html, 'Elige'));
        assertTrue(str_contains($html, '@media print'));
        // El botón de imprimir NO lleva onclick inline: con `script-src 'self'`
        // (CSP con default seguro) un handler en línea no se ejecuta. Queda
        // como atributo de datos + print.js externo.
        assertTrue(str_contains($html, 'data-print'), 'el botón debe llevar data-print');
        assertTrue(!str_contains($html, 'onclick'), 'sin handlers inline en la vista imprimible');
        assertTrue(str_contains($html, 'js/print.js'), 'debe cargar print.js');
        $printJs = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/print.js');
        assertTrue(str_contains($printJs, 'window.print()'), 'print.js es quien llama a window.print()');
        // La section es estructural: no cuenta como pregunta.
        assertTrue(!str_contains($html, '>Sección</span>'));
    }

    /* ---------- Alta desde plantilla (FormController::create) ---------- */

    public function testCreateDesdePlantillaUsaTituloYEstructuraDeLaPlantilla(): void
    {
        $this->setPostEnVio(['title' => '', 'template' => 'sondeo-rapido']);

        ob_start();
        (new \App\Controllers\FormController())->create();
        ob_end_clean();

        $form = $this->lastFormCreated('Sondeo rápido');
        assertTrue($form !== null);
        // Sin título en el POST → se usa el nombre de la plantilla.
        assertSame('Sondeo rápido', (string) $form['title']);
        assertTrue(stripos((string) $form['description'], 'tres preguntas') !== false);

        $fields = Field::byForm((int) $form['id']);
        assertSame(3, count($fields));

        // La plantilla trae quota 200 → settings aplicados.
        $settings = Form::settings((int) $form['id']);
        assertTrue($settings['quota']['enabled']);
        assertSame(200, $settings['quota']['max']);
    }

    public function testCreateSinPlantillaSigueCreandoEnBlanco(): void
    {
        $this->setPostEnVio(['title' => 'Formulario en blanco P9', 'description' => 'x']);

        ob_start();
        (new \App\Controllers\FormController())->create();
        ob_end_clean();

        $form = $this->lastFormCreated('Formulario en blanco P9');
        assertTrue($form !== null);
        assertSame(0, count(Field::byForm((int) $form['id'])));
    }

    public function testCreateConPlantillaInexistenteNoCreaFormulario(): void
    {
        $before = count($this->allFormIds());
        $this->setPostEnVio(['title' => 'Con plantilla fantasma', 'template' => 'no-existe']);

        ob_start();
        (new \App\Controllers\FormController())->create();
        ob_end_clean();

        assertSame($before, count($this->allFormIds()));
    }

    /* ---------- Helpers ---------- */

    private function setPostEnVio(array $post): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $_SERVER['HTTP_USER_AGENT'] = 'P9TemplatesTest';
        $token = 'p9-token';
        $_SESSION['_csrf_token'] = $token;
        $post['_token'] = $token;
        $_POST = $post;

        // Sesión de prueba: usuario dueño de los formularios que se crean.
        // `assumeLoggedIn()` y no `$_SESSION['user_id']` a pelo: desde F2 una
        // sesión SIN `auth_at` se cierra (no hay forma de comprobar de qué
        // credencial viene), así que poner solo `user_id` funcionaba únicamente si otro
        // test había dejado un `auth_at` suelto en `$_SESSION`.
        \App\Core\Auth::assumeLoggedIn((int) self::$userId);
        \App\Core\Auth::forgetCache();
    }

    private function loginAsOwner(int $formId): ?array
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $_SERVER['HTTP_USER_AGENT'] = 'P9TemplatesTest';
        // Ver `setPostEnVio()`: hace falta `assumeLoggedIn()` (que fija también
        // `auth_at`), no solo `user_id`.
        \App\Core\Auth::assumeLoggedIn((int) self::$userId);
        \App\Core\Auth::forgetCache();
        return \App\Core\Auth::user();
    }

    private function lastFormCreated(string $title): ?array
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $row = $db->fetch(
            'SELECT * FROM forms WHERE user_id = :uid ORDER BY id DESC LIMIT 1',
            ['uid' => (int) self::$userId]
        );
        if ($row === null || (string) $row['title'] !== $title) {
            return null;
        }
        return $row;
    }

    /** @return array<int, int> */
    private function allFormIds(): array
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $rows = $db->fetchAll('SELECT id FROM forms WHERE user_id = :uid', ['uid' => (int) self::$userId]);
        return array_map(static fn($r) => (int) $r['id'], $rows);
    }

    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }
        if (!class_exists(\App\Core\Config::class)) {
            require dirname(__DIR__, 2) . '/config/bootstrap.php';
        }
        self::$envLoaded = true;
    }

    private static function cleanupResiduals(): void
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $users = $db->fetchAll("SELECT id FROM users WHERE email LIKE '%@test.local'");
        foreach ($users as $u) {
            $forms = $db->fetchAll('SELECT id FROM forms WHERE user_id = ' . (int) $u['id']);
            foreach ($forms as $f) {
                $fields = $db->fetchAll('SELECT id FROM fields WHERE form_id = ' . (int) $f['id']);
                foreach ($fields as $fl) {
                    $db->execute('DELETE FROM answers WHERE field_id = ' . (int) $fl['id']);
                }
                $db->execute('DELETE FROM fields WHERE form_id = ' . (int) $f['id']);
                $subs = $db->fetchAll('SELECT id FROM submissions WHERE form_id = ' . (int) $f['id']);
                foreach ($subs as $s) {
                    $db->execute('DELETE FROM answers WHERE submission_id = ' . (int) $s['id']);
                }
                $db->execute('DELETE FROM submissions WHERE form_id = ' . (int) $f['id']);
            }
            $db->execute('DELETE FROM forms WHERE user_id = ' . (int) $u['id']);
        }
        $db->execute("DELETE FROM users WHERE email LIKE '%@test.local'");
    }
}
