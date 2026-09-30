<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FillController;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\FormSettings;
use App\Services\QrCode;

/**
 * P7 — Honeypot, clonar formularios y QR SVG.
 *
 * Cubre el lado servidor: la trampa honeypot que atrapa bots fingiendo éxito
 * sin persistir, el duplicado de formularios (con campos, opciones, lógica y
 * settings) y el render SVG del código QR sin GD.
 */
class P7ShareTest
{
    private static ?int $userId = null;
    private static int $formId = 0;
    private static string $slug = '';

    public function setUp(): void
    {
        try {
            $db = \App\Core\Database::getInstance(\App\Core\Config::db());
            $db->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::cleanupResiduals();

        $suffix = bin2hex(random_bytes(4));
        self::$userId = User::create('Test P7', 'p7-' . $suffix . '@test.local', 'password');
        self::$formId = Form::create((int) self::$userId, 'Form P7 ' . $suffix);
        $form = Form::find(self::$formId);
        self::$slug = (string) ($form['slug'] ?? '');
    }

    /* ---------- Honeypot (P7) ---------- */

    public function testShowRenderIncluyeTemaYHoneypot(): void
    {
        Field::create(self::$formId, 'text', 'Tu nombre', '', '', true, [], [], 1);
        $settings = FormSettings::defaults();
        $settings['theme']['primary'] = '#123456';
        Form::saveSettings(self::$formId, $settings);
        Form::update(self::$formId, 'Form P7', '', 'published');

        ob_start();
        \App\Core\View::render('fill/show', [
            'form'     => Form::find(self::$formId),
            'fields'   => Field::byForm(self::$formId),
            'csrf'     => 'token-x',
            'settings' => Form::settings(self::$formId),
        ], null);
        $html = (string) ob_get_clean();

        // Honeypot activo por defecto → el campo trampa está en el form.
        assertTrue(str_contains($html, 'vf-honeypot'));
        assertTrue(str_contains($html, 'name="_vf_hp"'));

        // Tema inline presente.
        assertTrue(str_contains($html, '<style>:root{--vf-primary:#123456;'));
    }

    public function testHoneypotLlenoAtrapaBotSinPersistir(): void
    {
        $q = Field::create(self::$formId, 'text', 'Tu nombre', '', '', true, [], [], 1);
        Form::update(self::$formId, 'Form P7', '', 'published');

        // Honeypot habilitado por defecto (FormSettings::defaults).
        $settings = Form::settings(self::$formId);
        assertTrue(!empty($settings['honeypot']['enabled']));

        $this->setPostEnVio('p7-honeypot-bot', ['_vf_hp' => 'soy-un-bot', 'field_' . $q => 'Valor normal']);
        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();

        assertSame(0, Form::submissionCount(self::$formId));
    }

    public function testHoneypotVacioPermiteEnvioNormal(): void
    {
        $q = Field::create(self::$formId, 'text', 'Tu nombre', '', '', true, [], [], 1);
        Form::update(self::$formId, 'Form P7', '', 'published');

        $this->setPostEnVio('p7-honeypot-ok', ['_vf_hp' => '', 'field_' . $q => 'Adrian']);
        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();

        assertSame(1, Form::submissionCount(self::$formId));
        $sub = Submission::findWithAnswers((int) \App\Core\Database::getInstance(\App\Core\Config::db())->fetch('SELECT id FROM submissions WHERE form_id = ' . self::$formId)['id']);
        assertSame('Adrian', $sub['answers'][$q] ?? null);
    }

    public function testHoneypotDesactivadoNoFiltraNiRastreaTrampa(): void
    {
        $q = Field::create(self::$formId, 'text', 'Tu nombre', '', '', true, [], [], 1);
        $settings = FormSettings::defaults();
        $settings['honeypot']['enabled'] = false;
        Form::saveSettings(self::$formId, $settings);
        Form::update(self::$formId, 'Form P7', '', 'published');

        // Aunque llegue la trampa rellenada, con honeypot off no se filtra:
        // el valor se ignora y la respuesta se guarda (sin rastro de _vf_hp).
        $this->setPostEnVio('p7-honeypot-off', ['_vf_hp' => 'x', 'field_' . $q => 'Valido']);
        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();

        assertSame(1, Form::submissionCount(self::$formId));
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $sid = (int) $db->fetch('SELECT id FROM submissions WHERE form_id = ' . self::$formId)['id'];
        $answers = $db->fetchAll('SELECT value FROM answers WHERE submission_id = ' . $sid);
        assertSame(1, count($answers));
        assertSame('Valido', $answers[0]['value']);
    }

    /* ---------- Duplicar formulario (P7) ---------- */

    public function testDuplicateCopiaFormConCamposYSettings(): void
    {
        $select = Field::create(
            self::$formId,
            'select',
            'Color favorito',
            '',
            '',
            false,
            [['value' => 'rojo'], ['value' => 'azul']],
            [],
            1
        );
        $section = Field::create(self::$formId, 'section', 'Sección', '', '', false, [], [], 2);
        $child = Field::create(
            self::$formId,
            'text',
            'Detalle',
            'Escribe algo',
            'Ayuda',
            true,
            [],
            ['max_length' => 30],
            1,
            $section,
            ['operator' => 'all', 'rules' => [['field_id' => $select, 'op' => 'equals', 'value' => 'azul']]]
        );

        $settings = FormSettings::defaults();
        $settings['theme']['primary'] = '#123456';
        $settings['thanks']['message'] = '¡Gracias de prueba!';
        Form::saveSettings(self::$formId, $settings);
        Form::update(self::$formId, 'Encuesta Original', 'Descripción original', 'published');

        $originalSlug = self::$slug;
        $originalId = self::$formId;

        $newId = Form::duplicate($originalId);
        $copy = Form::find($newId);
        assertTrue($copy !== null);
        assertSame('draft', $copy['status']);
        assertSame('Encuesta Original (copia)', $copy['title']);
        assertSame('Descripción original', $copy['description']);
        assertTrue($copy['slug'] !== $originalSlug);
        assertTrue($copy['slug'] !== '');

        $copySettings = Form::settings($newId);
        assertSame('#123456', $copySettings['theme']['primary']);
        assertSame('¡Gracias de prueba!', $copySettings['thanks']['message']);

        $copyFields = array_values($copy['fields']);
        assertSame(3, count($copyFields));

        $byType = [];
        foreach ($copyFields as $f) {
            $byType[$f['type']] = $f;
        }
        // El padre (section) se remapea al id nuevo del campo hijo.
        assertTrue($byType['section']['parent_id'] === null);
        assertSame($byType['section']['id'], $byType['text']['parent_id']);
        assertSame('Escribe algo', $byType['text']['placeholder']);
        assertTrue((bool) $byType['text']['is_required']);
        assertSame(['max_length' => 30], $byType['text']['validations']);
        assertSame('azul', $byType['text']['logic']['rules'][0]['value']);
        assertSame(
            ['rojo', 'azul'],
            array_column($byType['select']['options'], 'value')
        );

        // La copia no tiene respuestas ni comparte submissions con el original.
        assertSame(0, Form::submissionCount($newId));

        // La original queda intacta.
        $original = Form::find($originalId);
        assertSame('published', $original['status']);
        assertSame($originalSlug, $original['slug']);
    }

    /* ---------- QR (P7) ---------- */

    public function testQrSvgValidoDeterministico(): void
    {
        $svg = QrCode::svg('https://ejemplo.com/f/encuesta', 4, 2);
        assertTrue(str_starts_with($svg, '<svg '));
        assertTrue(str_ends_with($svg, '</svg>'));
        assertTrue(str_contains($svg, 'fill="#000"'));
        assertTrue(str_contains($svg, 'width="'));

        // El mismo dato produce exactamente el mismo SVG.
        assertSame($svg, QrCode::svg('https://ejemplo.com/f/encuesta', 4, 2));
        // Datos distintos → QR distinto.
        assertTrue($svg !== QrCode::svg('https://ejemplo.com/f/otra', 4, 2));
    }

    public function testQrSvgRechazaDatosVacios(): void
    {
        try {
            QrCode::svg('');
            fail('Debe lanzar excepción con dato vacío.');
        } catch (\InvalidArgumentException $e) {
            assertTrue(true);
        }
    }

    /* ---------- Helpers ---------- */

    private function setPostEnVio(string $token, array $post): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_USER_AGENT'] = 'P7ShareTest';
        $_SESSION['_csrf_token'] = $token;
        $post['_token'] = $token;
        $_POST = $post;
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