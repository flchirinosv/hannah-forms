<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Answer;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;

/**
 * Test de integración de los métodos de analytics migrados a los modelos
 * (byFormFiltered, dailyStats, withAnswersByIds y helpers de Answer).
 * Crea datos temporales y los limpia tras cada ejecución.
 */
class SubmissionAnalyticsTest
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
            $db = \App\Core\Database::getInstance(\App\Core\Config::db());
            $db->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            self::$available = false;
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        // Limpieza de residuos de corridas previas (forms y usuarios de test)
        self::cleanupResiduals();

        // Construcción de datos de prueba idempotente por test
        $suffix = bin2hex(random_bytes(4));
        self::$userId = User::create('Test Analytics', 'analytics-' . $suffix . '@test.local', 'password');
        self::$formId = Form::create((int) self::$userId, 'Form Analítico ' . $suffix);

        // select, checkbox, number, text
        self::$fieldIds['select'] = Field::create((int) self::$formId, 'select', 'País', '', '', false, [
            ['value' => 'MX', 'label' => 'México'],
            ['value' => 'AR', 'label' => 'Argentina'],
        ]);
        self::$fieldIds['checkbox'] = Field::create((int) self::$formId, 'checkbox', 'Intereses', '', '', false, [
            ['value' => 'php', 'label' => 'PHP'],
            ['value' => 'js', 'label' => 'JS'],
        ]);
        self::$fieldIds['number'] = Field::create((int) self::$formId, 'number', 'Edad');
        self::$fieldIds['text'] = Field::create((int) self::$formId, 'text', 'Comentario');
    }

    public function testByFormFilteredSinFiltros(): void
    {
        $this->seedSubmissions();
        $rows = Submission::byFormFiltered((int) self::$formId);
        assertTrue(count($rows) >= 2, 'debería retornar las submissions sembradas');
    }

    public function testWithAnswersResuelveNmasUno(): void
    {
        $this->seedSubmissions();
        $subs = Submission::byForm((int) self::$formId);
        $ids = array_column($subs, 'id');
        $map = Submission::withAnswersByIds($ids);

        foreach ($ids as $id) {
            assertArrayHasKey((int) $id, $map);
            assertArrayHasKey('submission', $map[(int) $id]);
            assertArrayHasKey('answers', $map[(int) $id]);
            assertSame((int) $id, (int) $map[(int) $id]['submission']['id']);
        }
        // Verificar que las answers se mapearon por field_id
        $first = $map[(int) $ids[0]];
        assertArrayHasKey((int) self::$fieldIds['select'], $first['answers']);
    }

    public function testDailyStatsAgrupaPorDia(): void
    {
        $this->seedSubmissions();
        $daily = Submission::dailyStats((int) self::$formId);
        $totalDays = array_sum(array_column($daily, 'c'));
        assertTrue($totalDays >= 2, 'el total diario debe sumar las submissions');
    }

    public function testCountByFieldGrouping(): void
    {
        $this->seedSubmissions();
        $counts = Answer::countByFieldInForm((int) self::$formId, (int) self::$fieldIds['select']);
        $total = array_sum(array_column($counts, 'c'));
        assertTrue($total >= 2, 'debe contar todas las respuestas del campo select');
    }

    public function testCountsCheckboxDescomponeJson(): void
    {
        $this->seedSubmissions();
        $counts = Answer::countsCheckboxInForm((int) self::$formId, (int) self::$fieldIds['checkbox']);
        // Al menos existen opciones descompuestas
        $labels = array_column($counts, 'value');
        assertTrue(in_array('php', $labels, true) || in_array('js', $labels, true), 'checkbox descomposturas');
    }

    public function testNumberStatsCalculaPromedio(): void
    {
        $this->seedSubmissions();
        $stats = Answer::numberStatsInForm((int) self::$formId, (int) self::$fieldIds['number']);
        assertNotNull($stats, 'debería existir estadística numérica');
        assertTrue((int) $stats['answer_count'] >= 2, 'debe contar las respuestas numéricas');
        // avg = (10 + 20) / 2 = 15
        assertEquals(15, (float) $stats['avg_value']);
    }

    public function testNonEmptyCount(): void
    {
        $this->seedSubmissions();
        $c = Answer::nonEmptyInForm((int) self::$formId, (int) self::$fieldIds['text']);
        assertTrue($c >= 1, 'debe contar textos no vacíos');
    }

    /**
     * Borra formularios de test residuales (título "Form Analítico") de
     * corridas previas y sus usuarios. Se invoca al inicio de cada setUp.
     */
    private static function cleanupResiduals(): void
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());

        // Borrar a los usuarios de test en cascada junto con sus forms
        $users = $db->fetchAll("SELECT id FROM users WHERE email LIKE '%@test.local'");
        foreach ($users as $u) {
            // forms se borran en cascada via FK; borrar el user elimina sus forms
            $db->execute('DELETE FROM users WHERE id = :id', ['id' => (int) $u['id']]);
        }

        // Limpiar contadores de rate limit de tests previos
        $db->execute("DELETE FROM rate_limits WHERE scope LIKE 'test:%'");
        self::$formId = null;
        self::$userId = null;
        self::$fieldIds = [];
        self::$submissionIds = [];
    }

    private static function ensureCleanupHook(): void
    {
        if (!self::$cleanupHookRegistered) {
            self::$cleanupHookRegistered = true;
            // Limpieza final garantizada al terminar el script (tras el último test)
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

    private static function seedSubmissions(): void
    {
        // Submission 1: MX, [php,js], edad 10, comentario "hola"
        $s1 = Submission::create((int) self::$formId, '1.1.1.1', 'TestAgent');
        Answer::create($s1, (int) self::$fieldIds['select'], 'MX');
        Answer::create($s1, (int) self::$fieldIds['checkbox'], json_encode(['php', 'js']));
        Answer::create($s1, (int) self::$fieldIds['number'], '10');
        Answer::create($s1, (int) self::$fieldIds['text'], 'hola');
        self::$submissionIds[] = $s1;

        // Submission 2: AR, [js], edad 20, texto vacío
        $s2 = Submission::create((int) self::$formId, '2.2.2.2', 'TestAgent');
        Answer::create($s2, (int) self::$fieldIds['select'], 'AR');
        Answer::create($s2, (int) self::$fieldIds['checkbox'], json_encode(['js']));
        Answer::create($s2, (int) self::$fieldIds['number'], '20');
        Answer::create($s2, (int) self::$fieldIds['text'], '');
        self::$submissionIds[] = $s2;
    }
}
