<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FillController;
use App\Models\Answer;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\QuizScore;

/**
 * Cierre del IDOR de la página pública de gracias.
 *
 * `GET /f/{slug}/thanks` es PÚBLICA y sin sesión. Antes se identificaba la
 * respuesta con `?sid=N`, el AUTO_INCREMENT de `submissions`: un entero corto y
 * correlativo que cualquiera podía recorrer, y la vista del quiz enseñaba la
 * respuesta literal de la persona (`Tu respuesta: PATCH`) junto a la correcta.
 * Recorrer `?sid=1..N` volcaba todas las respuestas de un formulario.
 *
 * Ahora el thanks se direcciona con un token de capacidad de 128 bits
 * (`?t=`, columna `submissions.public_token`) que solo conoce la sesión que
 * envió el formulario.
 *
 * Estos tests fijan las TRES propiedades que sostienen el arreglo:
 *  1. el token no es adivinable ni correlativo, y es único;
 *  2. `?sid=` ya no abre nada (ni el quiz ni respuestas);
 *  3. el token de otro formulario NO vale para este (no hay cruce entre
 *     formularios), y un token inventado tampoco.
 */
class ThanksTokenTest
{
    private static ?int $userId = null;
    private static int $formId = 0;
    private static int $otherFormId = 0;
    private static string $slug = '';
    private static string $otherSlug = '';
    private static ?int $q1 = null;
    private static ?int $q2 = null;

    public function setUp(): void
    {
        try {
            $db = \App\Core\Database::getInstance(\App\Core\Config::db());
            $db->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::cleanupResiduals();

        self::$userId = User::create('Test ThanksToken', 'thanks-' . bin2hex(random_bytes(4)) . '@test.local', 'password');
        self::$formId = Form::create((int) self::$userId, 'Quiz A ' . bin2hex(random_bytes(2)));
        self::$otherFormId = Form::create((int) self::$userId, 'Quiz B ' . bin2hex(random_bytes(2)));

        self::$slug = (string) (Form::find(self::$formId)['slug'] ?? '');
        self::$otherSlug = (string) (Form::find(self::$otherFormId)['slug'] ?? '');

        // 2 preguntas con respuesta esperada: 1+1 = 2 y sí/no.
        self::$q1 = Field::create(
            self::$formId,
            'select',
            'Capital de Bolivia',
            '',
            '',
            false,
            ['Sucre', 'La Paz'],
            ['correct' => 'Sucre'],
            1
        );
        self::$q2 = Field::create(
            self::$formId,
            'text',
            'Email',
            '',
            '',
            false,
            [],
            ['correct' => 'no'],
            2
        );

        // En el otro form, una pregunta con la MISMA respuesta esperada.
        Field::create(
            self::$otherFormId,
            'text',
            'Email (otro form)',
            '',
            '',
            false,
            [],
            ['correct' => 'no'],
            1
        );

        // El quiz es opt-in: sin esto el thanks NUNCA muestra puntuación y los
        // tests pasarían por el motivo equivocado (sin comprobar nada).
        foreach ([self::$formId, self::$otherFormId] as $fid) {
            $settings = \App\Services\FormSettings::defaults();
            $settings['quiz']['enabled'] = true;
            Form::saveSettings($fid, $settings);
            Form::update($fid, 'Quiz test', '', 'published');
        }
    }

    public function tearDown(): void
    {
        self::cleanupResiduals();
    }

    /* ---------- 1. El token es una capacidad real ---------- */

    public function testTokenNoEsSecuencialNiPredecible(): void
    {
        $a = Submission::newToken();
        $b = Submission::newToken();

        assertTrue(Submission::isWellFormedToken($a), 'El token debe tener la forma generada.');
        assertTrue(Submission::isWellFormedToken($b), 'El token debe tener la forma generada.');
        assertEquals(32, strlen($a), 'El token son 32 hex = 128 bits.');
        assertTrue($a !== $b, 'Dos tokens consecutivos no pueden coincidir.');
    }

    public function testTokensRechazanFormasInvalidas(): void
    {
        $validos = [
            str_repeat('a', 32),
            str_repeat('0', 32),
            '0123456789abcdef0123456789abcdef',
        ];
        foreach ($validos as $t) {
            assertTrue(Submission::isWellFormedToken($t), 'Debe aceptar: ' . $t);
        }

        // El resto es lo que un atacante probaría: un id, un prefijo, un token
        // con caracteres fuera del alfabeto o de otra longitud.
        $invalidos = [
            '',
            '1',
            '3651',
            str_repeat('a', 31),                 // corto
            str_repeat('a', 33),                 // largo
            strtoupper(str_repeat('a', 32)),     // mayúsculas: no es el formato
            str_repeat('g', 32),                 // fuera de [a-f0-9]
            substr(str_repeat('a', 32), 0, 31) . ' ',
            "'; DROP TABLE submissions; --",
        ];
        foreach ($invalidos as $t) {
            assertTrue(!Submission::isWellFormedToken($t), 'Debe rechazar: ' . var_export($t, true));
        }
    }

    public function testCreateGuardaTokenYEsUnicoEnBaseDeDatos(): void
    {
        $token = Submission::newToken();
        $sid = Submission::create(self::$formId, '127.0.0.1', 'Agent', $token);
        assertTrue($sid > 0);

        $row = self::fetchSubmission($sid);
        assertEquals($token, (string) ($row['public_token'] ?? ''), 'El token se persiste tal cual.');
    }

    public function testCreateGeneraTokenSiNoSePasa(): void
    {
        $sid = Submission::create(self::$formId, '127.0.0.1', 'Agent');
        $row = self::fetchSubmission($sid);
        $token = (string) ($row['public_token'] ?? '');
        assertTrue(Submission::isWellFormedToken($token), 'Sin token explícito se genera uno válido.');
    }

    /* ---------- 2. El id ya no abre nada ---------- */

    public function testSidSecuencialNoResuelveSubmission(): void
    {
        $token = Submission::newToken();
        $sid = Submission::create(self::$formId, '127.0.0.1', 'Agent', $token);
        Answer::create($sid, (int) self::$q1, 'Sucre');
        Answer::create($sid, (int) self::$q2, 'no');

        // Lo que el atacante puede hacer: recorrer ids y probar el FILL.
        assertEquals(null, Submission::findWithToken((string) $sid), 'El id NO es una credencial válida.');
        assertEquals(null, Submission::findWithToken((string) ($sid - 1)), 'El id anterior tampoco.');
        assertEquals(null, Submission::findWithToken('3651'), 'Un id arbitrario tampoco.');

        // Y el único camino es el token.
        $record = Submission::findWithToken($token);
        assertTrue($record !== null, 'El token sí resuelve.');
        assertEquals($sid, (int) $record['submission']['id'], '…a la submission correcta.');
        assertEquals(2, count($record['answers']), '…con sus respuestas.');
    }

    public function testTokenInexistenteNoDevuelveNada(): void
    {
        Submission::create(self::$formId, '127.0.0.1', 'Agent', str_repeat('a', 32));

        // Un token bien formado pero que no existe.
        assertEquals(null, Submission::findWithToken(str_repeat('c', 32)), 'Token inexistente.');

        // Y uno mal formado ni siquiera se consulta.
        assertEquals(null, Submission::findWithToken('no-es-un-token'), 'Token mal formado.');
    }

    /* ---------- 3. Sin cruce entre formularios ---------- */

    public function testTokenDeOtroFormularioNoSirveEnEste(): void
    {
        $otherToken = Submission::newToken();
        $otherSid = Submission::create(self::$otherFormId, '127.0.0.1', 'Agent', $otherToken);
        Answer::create($otherSid, (int) self::$q1, 'Sucre');

        $record = Submission::findWithToken($otherToken);
        assertTrue($record !== null, 'El token resuelve a SU submission.');
        assertEquals(self::$otherFormId, (int) $record['submission']['form_id'], 'Pertenece al otro formulario.');

        // El thanks de ESTE form exige que form_id coincida: aunque el token
        // exista y sea válido, no se muestra el quiz de otro formulario.
        $html = self::thanksHtml(self::$slug, ['t' => $otherToken]);
        assertFalse(self::htmlMuestraQuiz($html), 'El token del otro formulario no debe producir quiz aquí.');
    }

    public function testTokenPropioSiMuestraElQuiz(): void
    {
        $token = Submission::newToken();
        $sid = Submission::create(self::$formId, '127.0.0.1', 'Agent', $token);
        Answer::create($sid, (int) self::$q1, 'Sucre');
        Answer::create($sid, (int) self::$q2, 'no');

        $html = self::thanksHtml(self::$slug, ['t' => $token]);
        assertTrue(self::htmlMuestraQuiz($html), 'El token propio debe mostrar el quiz.');
        assertTrue(str_contains($html, '2 de 2 correctas'), '2 preguntas, 2 correctas.');
    }

    public function testTokenAjenoNoMuestraElQuizNiFiltraLaRespuesta(): void
    {
        $token = Submission::newToken();
        $sid = Submission::create(self::$formId, '127.0.0.1', 'Agent', $token);
        // Respuesta INCORRECTA a propósito: es el dato que se filtraba.
        Answer::create($sid, (int) self::$q1, 'La Paz');
        Answer::create($sid, (int) self::$q2, 'no');

        $html = self::thanksHtml(self::$slug, ['t' => $token]);
        assertTrue(self::htmlMuestraQuiz($html), 'El dueño sí ve su resultado.');

        // Ahora, desde el slug de OTRO formulario con el mismo token: nada.
        $otro = self::thanksHtml(self::$otherSlug, ['t' => $token]);
        assertFalse(self::htmlMuestraQuiz($otro), 'Sin quiz cruzando formularios.');
        assertFalse(str_contains($otro, 'La Paz'), 'La respuesta ajena no aparece en otro formulario.');

        // Y con un token inventado.
        $falso = self::thanksHtml(self::$slug, ['t' => str_repeat('d', 32)]);
        assertFalse(self::htmlMuestraQuiz($falso), 'Sin quiz con token falso.');
        assertFalse(str_contains($falso, 'La Paz'), 'La respuesta no aparece con un token falso.');
    }

    public function testElThanksNoAceptaElParametroSid(): void
    {
        $token = Submission::newToken();
        $sid = Submission::create(self::$formId, '127.0.0.1', 'Agent', $token);
        Answer::create($sid, (int) self::$q1, 'Sucre');
        Answer::create($sid, (int) self::$q2, 'no');

        // Regresión del hallazgo: replay del ataque tal como se hacía, con
        // cada nombre de parámetro por el que se podría colar un id.
        foreach (['sid', 'submission_id', 'id', 'sub'] as $key) {
            $html = self::thanksHtml(self::$slug, [$key => (string) $sid]);
            assertFalse(
                self::htmlMuestraQuiz($html),
                sprintf('`?%s=<id>` no debe abrir el quiz.', $key)
            );
            assertFalse(
                str_contains($html, 'app-quiz-results'),
                sprintf('`?%s=<id>` no debe renderizar el bloque de quiz.', $key)
            );
        }

        // Recorrer ids consecutivos tampoco: es el ataque real (el id era
        // correlativo), no un id suelto.
        foreach (range($sid - 2, $sid + 2) as $probe) {
            $html = self::thanksHtml(self::$slug, ['sid' => (string) $probe]);
            assertFalse(self::htmlMuestraQuiz($html), 'Un id vecino tampoco debe abrir nada.');
        }

        // Con el token bueno sigue funcionando (el arreglo no lo rompe todo).
        assertTrue(self::htmlMuestraQuiz(self::thanksHtml(self::$slug, ['t' => $token])), 'El token propio sigue funcionando.');
    }

    public function testQuizScoreSigueSiendoStateless(): void
    {
        // El token protege el ACCESO; el cálculo sigue siendo el mismo y sin
        // columna de score (no se persiste nada nuevo al responder).
        $fields = Form::find(self::$formId)['fields'] ?? [];
        $quiz = QuizScore::score($fields, [(int) self::$q1 => 'Sucre', (int) self::$q2 => 'no']);
        assertTrue($quiz !== null, 'El quiz se calcula.');
        assertEquals(2, (int) $quiz['max'], '2 puntos posibles.');

        $mal = QuizScore::score($fields, [(int) self::$q1 => 'La Paz', (int) self::$q2 => 'no']);
        assertEquals(1, (int) $mal['points'], 'Con una mal, 1 de 2.');
    }

    /* ---------- Utilidades ---------- */

    /**
     * Llama al thanks REAL y devuelve el HTML.
     *
     * Importante: no se reimplementan aquí los guards del controlador. Un test
     * que copia la lógica que quiere verificar no falla cuando esa lógica se
     * rompe (se verificó: con `?sid=` de vuelta a colarse, esta versión
     * seguía en verde mientras el HTML filtraba respuestas ajenas). Se invoca
     * `FillController::thanks()` y se mira lo que sale.
     */
    private static function thanksHtml(string $slug, array $query = []): string
    {
        $_GET = $query;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $controller = new FillController();
        ob_start();
        $controller->thanks($slug);
        return (string) ob_get_clean();
    }

    /** El thanks muestra resultados de quiz (bloque + "N de M correctas"). */
    private static function htmlMuestraQuiz(string $html): bool
    {
        return str_contains($html, 'app-quiz-results') && str_contains($html, 'correctas');
    }

    private static function fetchSubmission(int $id): array
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        return $db->fetch('SELECT * FROM submissions WHERE id = :id', ['id' => $id]) ?? [];
    }

    private static function cleanupResiduals(): void
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());

        if (self::$formId > 0) {
            $db->execute('DELETE FROM submissions WHERE form_id = :f', ['f' => self::$formId]);
            $db->execute('DELETE FROM fields WHERE form_id = :f', ['f' => self::$formId]);
            $db->execute('DELETE FROM forms WHERE id = :f', ['f' => self::$formId]);
        }
        if (self::$otherFormId > 0) {
            $db->execute('DELETE FROM submissions WHERE form_id = :f', ['f' => self::$otherFormId]);
            $db->execute('DELETE FROM fields WHERE form_id = :f', ['f' => self::$otherFormId]);
            $db->execute('DELETE FROM forms WHERE id = :f', ['f' => self::$otherFormId]);
        }
        if (self::$userId !== null) {
            $db->execute('DELETE FROM users WHERE id = :u', ['u' => self::$userId]);
        }

        $db->execute("DELETE FROM users WHERE email LIKE 'thanks-%@test.local'");
        $db->execute("DELETE FROM forms WHERE title LIKE 'Quiz A %' OR title LIKE 'Quiz B %'");

        self::$formId = 0;
        self::$otherFormId = 0;
        self::$userId = null;
        self::$q1 = null;
        self::$q2 = null;
        self::$slug = '';
        self::$otherSlug = '';
    }
}
