<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FillController;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;

/**
 * P8 — Lógica avanzada y salta-página.
 *
 * Cubre el lado servidor del salto de bloques de página: cuando la condición
 * del campo «page» que abre un bloque se cumple, TODO el bloque cuenta como
 * oculto (no se valida ni persiste, aunque el cliente llegue con valores).
 * La condición se evalúa en orden topológico: sus fuentes (incluso de pasos
 * anteriores) ya están resueltas cuando se decide el salto.
 */
class P8SmartTest
{
    private static ?int $userId = null;
    private static int $formId = 0;
    private static string $slug = '';
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

        self::cleanupResiduals();

        $suffix = bin2hex(random_bytes(4));
        self::$userId = User::create('Test P8', 'p8-' . $suffix . '@test.local', 'password');
        self::$formId = Form::create((int) self::$userId, 'Form P8 ' . $suffix);
        $form = Form::find(self::$formId);
        self::$slug = (string) ($form['slug'] ?? '');
    }

    /* ---------- Salta-página: submit omite el bloque ---------- */

    public function testSubmitOmitoBloqueCuandoSeCumpleLaCondicionDePagina(): void
    {
        // Paso 1: pregunta raíz. Paso 2 (page2) se omite si q1 == 'si'.
        $q1 = Field::create(self::$formId, 'text', 'Pregunta raíz', '', '', false, [], [], 1);
        $page2 = Field::create(self::$formId, 'page', 'Paso 2 (se omite si)', '', '', false, [], [], 2);
        Field::setLogic($page2, ['operator' => 'all', 'rules' => [['field_id' => $q1, 'op' => 'eq', 'value' => 'si']]]);
        // Campo obligatorio DENTRO del bloque omitible.
        $q2 = Field::create(self::$formId, 'text', 'Obligatoria del paso 2', '', '', true, [], [], 3);

        Form::update(self::$formId, 'Form Skip', '', 'published');

        // Caso A: la condición se cumple (q1 == 'si') → el bloque se omite: el
        // submit pasa sin q2 y NO persiste su bloque.
        $this->setPostEnVio('p8-skip-a', ['_vf_hp' => '', 'field_' . $q1 => 'si']);
        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();

        assertSame(1, Form::submissionCount(self::$formId));
        $sub = $this->lastSubmission();
        assertSame('si', $sub['answers'][$q1] ?? null);
        assertTrue(!array_key_exists($q2, $sub['answers']), 'q2 no debe persistirse con el bloque omitido');
    }

    public function testSubmitNoOmitoBloqueYValidaSusObligatorias(): void
    {
        $q1 = Field::create(self::$formId, 'text', 'Pregunta raíz', '', '', false, [], [], 1);
        $page2 = Field::create(self::$formId, 'page', 'Paso 2 (se omite si)', '', '', false, [], [], 2);
        Field::setLogic($page2, ['operator' => 'all', 'rules' => [['field_id' => $q1, 'op' => 'eq', 'value' => 'si']]]);
        $q2 = Field::create(self::$formId, 'text', 'Obligatoria del paso 2', '', '', true, [], [], 3);

        Form::update(self::$formId, 'Form Skip2', '', 'published');

        // Caso B: condición falsa (q1 != 'si') → la página es visible y la
        // obligatoria del bloque se exige.
        $this->setPostEnVio('p8-skip-b', ['_vf_hp' => '', 'field_' . $q1 => 'no']);
        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();

        assertSame(0, Form::submissionCount(self::$formId), 'sin q2 y con página visible debe fallar');

        // Caso C: página visible y valor correcto → persiste.
        $this->setPostEnVio('p8-skip-c', ['_vf_hp' => '', 'field_' . $q1 => 'no', 'field_' . $q2 => 'ok']);
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();

        assertSame(1, Form::submissionCount(self::$formId));
        $sub = $this->lastSubmission();
        assertSame('ok', $sub['answers'][$q2] ?? null);

        // Caso D: la condición se cumple → bloque omitido, q2 no se exige.
        $this->setPostEnVio('p8-skip-d', ['_vf_hp' => '', 'field_' . $q1 => 'si']);
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();

        assertSame(2, Form::submissionCount(self::$formId), 'con q1 == si el bloque se omite y el envío pasa');
        $sub = $this->lastSubmission();
        assertTrue(!array_key_exists($q2, $sub['answers']));
    }

    public function testSubmitIgnoraValoresEnviadosDeUnBloqueSaltado(): void
    {
        // Aunque el cliente (por glitch/ataque) envíe valores del bloque oculto,
        // el servidor no los valida, persiste ni usa como fuente.
        $q1 = Field::create(self::$formId, 'select', 'Estado', '', '', false, [['value' => 'abierto'], ['value' => 'cerrado']], [], 1);
        $page2 = Field::create(self::$formId, 'page', 'Detalle', '', '', false, [], [], 2);
        Field::setLogic($page2, ['operator' => 'all', 'rules' => [['field_id' => $q1, 'op' => 'neq', 'value' => 'abierto']]]);
        $q2 = Field::create(self::$formId, 'email', 'Correo (bloque oculto)', '', '', false, [], [], 3);

        Form::update(self::$formId, 'Form Skip3', '', 'published');

        // q1 != 'abierto' → la página se omite. Llega además un correo inválido
        // que no debe validarse ni persistirse.
        $this->setPostEnVio('p8-skip-e', ['_vf_hp' => '', 'field_' . $q1 => 'cerrado', 'field_' . $q2 => 'correo-invalido']);
        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();

        assertSame(1, Form::submissionCount(self::$formId), 'un valor inválido en bloque saltado no debe romper el envío');
        $sub = $this->lastSubmission();
        assertSame('cerrado', $sub['answers'][$q1] ?? null);
        assertTrue(!array_key_exists($q2, $sub['answers']), 'correo del bloque saltado no debe persistirse');
    }

    public function testSubmitCondicionDePaginaPuedeUsarFuenteDeFueraDelBloque(): void
    {
        // El orden topológico con extraDeps garantiza que la condición de la
        // página (que referencia a q1 de un paso anterior) se resuelva antes.
        $q1 = Field::create(self::$formId, 'text', 'Token', '', '', false, [], [], 1);
        $page2 = Field::create(self::$formId, 'page', 'Paso 2', '', '', false, [], [], 2);
        Field::setLogic($page2, ['operator' => 'all', 'rules' => [['field_id' => $q1, 'op' => 'matches', 'value' => '^AB-']]]);
        $q2 = Field::create(self::$formId, 'text', 'Obligatoria', '', '', true, [], [], 3);

        Form::update(self::$formId, 'Form Skip4', '', 'published');

        $this->setPostEnVio('p8-skip-e', ['_vf_hp' => '', 'field_' . $q1 => 'AB-123']);
        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();

        assertSame(1, Form::submissionCount(self::$formId));
        $sub = $this->lastSubmission();
        assertTrue(!array_key_exists($q2, $sub['answers']), 'con token AB- la página se omite');

        $this->setPostEnVio('p8-skip-f', ['_vf_hp' => '', 'field_' . $q1 => 'NO-coincide']);
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();

        assertSame(1, Form::submissionCount(self::$formId), 'sin token la página visible exige la obligatoria');
    }

    /* ---------- Helpers ---------- */

    private function lastSubmission(): array
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $row = $db->fetch('SELECT MAX(id) AS id FROM submissions WHERE form_id = ' . self::$formId);
        return Submission::findWithAnswers((int) $row['id']);
    }

    private function setPostEnVio(string $token, array $post): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $_SERVER['HTTP_USER_AGENT'] = 'P8SmartTest';
        $_SESSION['_csrf_token'] = $token;
        $post['_token'] = $token;
        $_POST = $post;
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