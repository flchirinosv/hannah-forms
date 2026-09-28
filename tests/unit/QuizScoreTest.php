<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FillController;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\QuizScore;

/**
 * P8 — Quiz: corrección de respuestas y puntuación.
 *
 * La puntuación es stateless: QuizScore::score() recibe los campos y las
 * respuestas ya persistidas y devuelve points/max/per. En el thanks se resuelve
 * con el ?sid= del PRG (solo si la submission pertenece al formulario).
 */
class QuizScoreTest
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
        self::$userId = User::create('Test P8-Q', 'p8q-' . $suffix . '@test.local', 'password');
        self::$formId = Form::create((int) self::$userId, 'Form Quiz ' . $suffix);
        $form = Form::find(self::$formId);
        self::$slug = (string) ($form['slug'] ?? '');
    }

    /* ---------- QuizScore::score (sin BD) ---------- */

    public function testScoreSelectYRadio(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'select', 'validations' => ['correct' => 'Madrid']],
            ['id' => 2, 'type' => 'radio', 'validations' => ['correct' => 'Perro']],
        ];
        $res = QuizScore::score($fields, [1 => 'Madrid', 2 => 'Gato']);
        assertNotNull($res);
        assertSame(1, $res['points']);
        assertSame(2, $res['max']);
        assertTrue($res['per'][1]['correct']);
        assertFalse($res['per'][2]['correct']);
        assertSame('Gato', $res['per'][2]['answer']);
    }

    public function testScoreCheckboxComparaConjuntos(): void
    {
        $fields = [
            ['id' => 3, 'type' => 'checkbox', 'validations' => ['correct' => '["a","c"]']],
        ];
        // Orden distinto al esperado → correcto (mismo conjunto).
        $res = QuizScore::score($fields, [3 => ['c', 'a']]);
        assertNotNull($res);
        assertSame(1, $res['points']);
        // Falta un elemento → incorrecto.
        $res = QuizScore::score($fields, [3 => ['a']]);
        assertSame(0, $res['points']);
        // Respuesta como string (posición select multiple) → no aplica.
        $res = QuizScore::score($fields, [3 => 'a,c']);
        assertSame(0, $res['points']);
    }

    public function testScoreCheckboxEsperadaConComa(): void
    {
        $fields = [
            ['id' => 4, 'type' => 'checkbox', 'validations' => ['correct' => 'a, c']],
        ];
        $res = QuizScore::score($fields, [4 => ['a', 'c']]);
        assertNotNull($res);
        assertSame(1, $res['points']);
    }

    public function testScoreTextoCaseInsensitiveConTrim(): void
    {
        $fields = [
            ['id' => 5, 'type' => 'text', 'validations' => ['correct' => '  Madrid ']],
            ['id' => 6, 'type' => 'email', 'validations' => ['correct' => 'A@B.com']],
        ];
        $res = QuizScore::score($fields, [5 => 'madrid', 6 => 'a@b.com']);
        assertSame(2, $res['points']);
    }

    public function testScoreRangosNumericos(): void
    {
        $fields = [
            ['id' => 7, 'type' => 'nps', 'validations' => ['correct' => '8']],
            ['id' => 8, 'type' => 'star', 'validations' => ['correct' => '5']],
            ['id' => 9, 'type' => 'scale', 'validations' => ['correct' => '3.5']],
        ];
        $res = QuizScore::score($fields, [7 => '8.0', 8 => '5', 9 => '3.50']);
        assertSame(3, $res['points']);

        $res = QuizScore::score($fields, [7 => '7']);
        assertSame(0, $res['points']);
    }

    public function testScoreExcluyeMatrixRankingSeccionesYPaginas(): void
    {
        $fields = [
            ['id' => 10, 'type' => 'matrix', 'validations' => ['correct' => 'x']],
            ['id' => 11, 'type' => 'ranking', 'validations' => ['correct' => 'x']],
            ['id' => 12, 'type' => 'section'],
            ['id' => 13, 'type' => 'page'],
            ['id' => 14, 'type' => 'radio', 'validations' => ['correct' => 'Sí']],
        ];
        $res = QuizScore::score($fields, [14 => 'Sí']);
        assertNotNull($res);
        assertSame(1, $res['max']);
        assertSame(1, $res['points']);
    }

    public function testScoreSinPreguntasPuntualesDevuelveNull(): void
    {
        $fields = [
            ['id' => 15, 'type' => 'text', 'validations' => []],
            ['id' => 16, 'type' => 'text', 'validations' => ['correct' => '']],
            ['id' => 17, 'type' => 'matrix'],
        ];
        assertNull(QuizScore::score($fields, [15 => 'a', 16 => 'b']));
    }

    public function testScoreRespuestaAusenteCuentaComoIncorrecta(): void
    {
        $fields = [
            ['id' => 18, 'type' => 'text', 'validations' => ['correct' => 'sí']],
        ];
        $res = QuizScore::score($fields, []);
        assertNotNull($res);
        assertSame(0, $res['points']);
        assertFalse($res['per'][18]['correct']);
    }

    /* ---------- Integración: submit + thanks con ?t= ---------- */

    public function testThanksMuestraPuntuacionConTokenValido(): void
    {
        $q1 = Field::create(self::$formId, 'radio', 'Capital', '', '', false, [['value' => 'Madrid'], ['value' => 'París']], ['correct' => 'Madrid'], 1);
        $q2 = Field::create(self::$formId, 'select', 'Animal', '', '', false, [['value' => 'Perro'], ['value' => 'Gato']], ['correct' => 'Perro'], 2);

        $settings = \App\Services\FormSettings::defaults();
        $settings['quiz']['enabled'] = true;
        Form::saveSettings(self::$formId, $settings);
        Form::update(self::$formId, 'Quiz Form', '', 'published');

        $this->setPostEnVio('p8q-sub', ['_vf_hp' => '', 'field_' . $q1 => 'Madrid', 'field_' . $q2 => 'Gato']);
        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();

        assertSame(1, Form::submissionCount(self::$formId));

        // El submit guarda un token de capacidad; es lo que abre el thanks.
        $record = $this->lastSubmission();
        $token = (string) ($record['submission']['public_token'] ?? '');
        assertTrue(Submission::isWellFormedToken($token), 'el submit debe persistir un token válido');

        $_GET['t'] = $token;
        ob_start();
        $controller->thanks(self::$slug);
        $html = (string) ob_get_clean();

        assertTrue(str_contains($html, '1 de 2 correctas'), 'debe mostrar la puntuación');
        assertTrue(str_contains($html, 'app-quiz-results'));
        assertTrue(str_contains($html, 'Capital'), 'etiqueta de la pregunta puntuable');
        assertTrue(str_contains($html, 'Tu respuesta'));
    }

    public function testThanksIgnoraElIdDeSubmission(): void
    {
        $q1 = Field::create(self::$formId, 'text', 'Texto', '', '', false, [], ['correct' => 'sí'], 1);
        $settings = \App\Services\FormSettings::defaults();
        $settings['quiz']['enabled'] = true;
        Form::saveSettings(self::$formId, $settings);
        Form::update(self::$formId, 'Quiz Form Sid', '', 'published');

        $this->setPostEnVio('p8q-sub-sid', ['_vf_hp' => '', 'field_' . $q1 => 'sí']);
        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();
        $sid = (int) $this->lastSubmission()['submission']['id'];

        // Regresión del IDOR: el thanks es público y sin sesión, así que con el
        // AUTO_INCREMENT (recorrible) se leían las respuestas de todos los que
        // contestaron este formulario. El id ya no es una credencial.
        $_GET = ['sid' => (string) $sid];
        ob_start();
        $controller->thanks(self::$slug);
        $html = (string) ob_get_clean();

        assertTrue(!str_contains($html, 'correctas'), '`?sid=<id>` no debe mostrar la puntuación');
        assertTrue(!str_contains($html, 'app-quiz-results'), '`?sid=<id>` no debe renderizar el bloque de quiz');
    }

    public function testThanksTokenDeOtroFormularioNoMuestraQuiz(): void
    {
        $q = Field::create(self::$formId, 'text', 'Texto', '', '', false, [], ['correct' => 'sí'], 1);
        $settings = \App\Services\FormSettings::defaults();
        $settings['quiz']['enabled'] = true;
        Form::saveSettings(self::$formId, $settings);
        Form::update(self::$formId, 'Quiz Form A', '', 'published');

        $this->setPostEnVio('p8q-sub2', ['_vf_hp' => '', 'field_' . $q => 'sí']);
        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();
        $token = (string) $this->lastSubmission()['submission']['public_token'];

        // Un slug de otro formulario que no tiene esa submission (con quiz
        // activo, para que el filtro por form_id sea el que bloquea).
        $otherId = Form::create((int) self::$userId, 'Otro');
        $otherSettings = \App\Services\FormSettings::defaults();
        $otherSettings['quiz']['enabled'] = true;
        Form::saveSettings($otherId, $otherSettings);
        $otherSlug = (string) Form::find($otherId)['slug'];

        $_GET = ['t' => $token];
        ob_start();
        $controller->thanks($otherSlug);
        $html = (string) ob_get_clean();

        assertTrue(!str_contains($html, 'correctas'), 'un token de otro form no debe mostrar la puntuación');
    }

    public function testThanksSinQuizActivadoNoMuestraPuntuacion(): void
    {
        Field::create(self::$formId, 'text', 'Texto', '', '', false, [], ['correct' => 'sí'], 1);
        Form::update(self::$formId, 'Form Sin Quiz', '', 'published');

        $this->setPostEnVio('p8q-sub3', ['_vf_hp' => '', 'field_1' => 'x']);
        // Omitimos el envío: sin quiz, el thanks no debe incluir el bloque aunque
        // reciba un token bien formado.
        $_GET = ['t' => str_repeat('a', 32)];
        $controller = new FillController();
        ob_start();
        $controller->thanks(self::$slug);
        $html = (string) ob_get_clean();

        assertTrue(!str_contains($html, 'correctas'));
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
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
        $_SERVER['HTTP_USER_AGENT'] = 'QuizScoreTest';
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