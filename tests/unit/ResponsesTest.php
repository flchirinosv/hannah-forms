<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Database;
use App\Models\Answer;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;

/**
 * Test de integración de la gestión de respuestas (P3): paginación
 * server-side, detalle con answers, borrado en cascada, formato de
 * display y payload de exportación JSON. Usa BD real y se auto-limpia.
 */
class ResponsesTest
{
    private static ?int $userId = null;
    private static ?int $formId = null;
    private static array $fieldIds = [];
    private static array $submissionIds = [];

    private static bool $available = true;
    private static bool $cleanupHookRegistered = false;

    public function setUp(): void
    {
        self::ensureCleanupHook();
        try {
            $db = Database::getInstance(Config::db());
            $db->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            self::$available = false;
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::cleanupResiduals();

        $suffix = bin2hex(random_bytes(4));
        self::$userId = User::create('Test Respuestas', 'responses-' . $suffix . '@test.local', 'password');
        self::$formId = Form::create((int) self::$userId, 'Form Respuestas ' . $suffix);

        self::$fieldIds['select'] = Field::create((int) self::$formId, 'select', 'País', '', '', false, [
            ['value' => 'MX', 'label' => 'México'],
            ['value' => 'AR', 'label' => 'Argentina'],
        ]);
        self::$fieldIds['checkbox'] = Field::create((int) self::$formId, 'checkbox', 'Intereses', '', '', false, [
            ['value' => 'php', 'label' => 'PHP'],
            ['value' => 'js', 'label' => 'JS'],
        ]);
        self::$fieldIds['matrix'] = Field::create((int) self::$formId, 'matrix', 'Matriz', '', '', true, [
            ['value' => 'Presentacion', 'group' => 'rows'],
            ['value' => 'Sabor', 'group' => 'rows'],
            ['value' => 'Bien', 'group' => 'cols'],
            ['value' => 'Mal', 'group' => 'cols'],
        ]);
        self::$fieldIds['text'] = Field::create((int) self::$formId, 'text', 'Comentario');
    }

    public function testByFormPaginatedDevuelveTotalYPaginas(): void
    {
        $this->seedSubmissions(5);
        $res = Submission::byFormPaginated((int) self::$formId, 1, 2);
        assertEquals(5, $res['total']);
        assertEquals(3, $res['pages']);
        assertEquals(2, count($res['items']));
        assertEquals(1, $res['page']);
    }

    public function testByFormPaginatedPaginaFueraDeRangoClampa(): void
    {
        $this->seedSubmissions(5);
        $res = Submission::byFormPaginated((int) self::$formId, 99, 2);
        assertEquals($res['pages'], $res['page'], 'debe clamp a la última página');
        assertEquals(1, count($res['items']), 'última página con el resto de filas');
    }

    public function testByFormPaginatedSinRespuestas(): void
    {
        $res = Submission::byFormPaginated((int) self::$formId, 1, 15);
        assertEquals(0, $res['total']);
        assertEquals(1, $res['pages']);
        assertEquals([], $res['items']);
    }

    public function testFindWithAnswersMapeaCampos(): void
    {
        $this->seedSubmissions(1);
        $data = Submission::findWithAnswers((int) self::$submissionIds[0]);
        assertNotNull($data, 'debería encontrar la submission');
        assertEquals('127.0.0.1', $data['submission']['ip_address']);
        assertArrayHasKey((int) self::$fieldIds['select'], $data['answers']);
        assertArrayHasKey((int) self::$fieldIds['matrix'], $data['answers']);
    }

    public function testFindWithAnswersInexistenteDevuelveNull(): void
    {
        assertNull(Submission::findWithAnswers(999999));
    }

    public function testDeleteEnCascadaBorraAnswers(): void
    {
        $this->seedSubmissions(1);
        $sid = (int) self::$submissionIds[0];
        assertTrue(count(Answer::bySubmission($sid)) >= 2, 'debe existir answers antes del borrado');

        Submission::delete($sid);
        assertEquals([], Answer::bySubmission($sid), 'FK ON DELETE CASCADE elimina las answers');
    }

    public function testDisplayValuePlano(): void
    {
        assertEquals('hola mundo', Answer::displayValue('hola mundo'));
        assertEquals('', Answer::displayValue(''));
    }

    public function testDisplayValueJsonArray(): void
    {
        assertEquals('php; js', Answer::displayValue(json_encode(['php', 'js'])));
    }

    public function testDisplayValueJsonObjeto(): void
    {
        assertEquals(
            'Presentacion: Bien; Sabor: Mal',
            Answer::displayValue(json_encode(['Presentacion' => 'Bien', 'Sabor' => 'Mal']))
        );
    }

    public function testDisplayValueJsonInvalidoDevuelveOriginal(): void
    {
        assertEquals('{esto no es json', Answer::displayValue('{esto no es json'));
    }

    public function testExportPayloadEstructuraYDecodifica(): void
    {
        $this->seedSubmissions(2);
        $subs = Submission::byForm((int) self::$formId);
        $ids = array_column($subs, 'id');
        $bySubmission = Submission::withAnswersByIds($ids);
        $payload = Submission::exportPayload(Form::find((int) self::$formId) ?? [], $bySubmission, count($ids));

        assertArrayHasKey('form', $payload);
        assertArrayHasKey('submissions', $payload);
        assertEquals(2, $payload['total']);
        assertEquals((int) self::$formId, $payload['form']['id']);
        assertTrue(count($payload['submissions']) === 2, 'debe exportar todas las submissions');

        $first = $payload['submissions'][0];
        // El valor del checkbox quedó decodificado como array.
        $checkValue = $first['answers'][(int) self::$fieldIds['checkbox']] ?? null;
        assertNotNull($checkValue);
        assertTrue(is_array($checkValue), 'checkbox debe decodificarse como array');
        // La matriz quedó decodificada como objeto (mapa fila => columna).
        $matrixValue = $first['answers'][(int) self::$fieldIds['matrix']] ?? null;
        assertTrue(is_array($matrixValue), 'matriz debe decodificarse como objeto');
        assertArrayHasKey('Presentacion', $matrixValue);
        // La metadata de la submission viaja en el JSON.
        $ips = array_column($payload['submissions'], 'ip_address');
        assertTrue(in_array('127.0.0.1', $ips, true), 'IP de la submission 1 presente');
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        $users = $db->fetchAll("SELECT id FROM users WHERE email LIKE '%@test.local'");
        foreach ($users as $u) {
            $db->execute('DELETE FROM users WHERE id = :id', ['id' => (int) $u['id']]);
        }
        self::$formId = null;
        self::$userId = null;
        self::$fieldIds = [];
        self::$submissionIds = [];
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

    private static function seedSubmissions(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $sid = Submission::create((int) self::$formId, '127.0.0.' . $i, 'TestAgent/P3');
            Answer::create($sid, (int) self::$fieldIds['select'], $i % 2 === 0 ? 'AR' : 'MX');
            Answer::create($sid, (int) self::$fieldIds['checkbox'], json_encode(['php']));
            Answer::create($sid, (int) self::$fieldIds['matrix'], json_encode([
                'Presentacion' => $i % 2 === 0 ? 'Mal' : 'Bien',
                'Sabor'        => $i % 2 === 0 ? 'Mal' : 'Bien',
            ]));
            Answer::create($sid, (int) self::$fieldIds['text'], 'hola ' . $i);
            self::$submissionIds[] = $sid;
        }
    }
}