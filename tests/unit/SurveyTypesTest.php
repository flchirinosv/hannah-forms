<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FillController;
use App\Models\Answer;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;

/**
 * P2 — Tipos de encuesta: matriz/Likert, NPS, estrellas y ranking.
 * Valida las reglas de FillController (validation + normalización) y las
 * estadísticas de analytics (matrixStats/rankingStats/npsStats).
 */
class SurveyTypesTest
{
    private FillController $controller;

    private static ?int $userId = null;
    private static ?int $formId = null;
    private static array $fieldIds = [];
    private static bool $envLoaded = false;

    public function setUp(): void
    {
        self::loadEnv();

        $this->controller = new FillController();
        try {
            $db = \App\Core\Database::getInstance(\App\Core\Config::db());
            $db->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::cleanupResiduals();

        $suffix = bin2hex(random_bytes(4));
        self::$userId = User::create('Test SurveyTypes', 'surveytypes-' . $suffix . '@test.local', 'password');
        self::$formId = Form::create((int) self::$userId, 'Form Tipos ' . $suffix);

        self::$fieldIds['matrix'] = Field::create((int) self::$formId, 'matrix', 'Satisfacción', '', '', false, [
            ['group' => 'rows', 'value' => 'Sabor'],
            ['group' => 'rows', 'value' => 'Precio'],
            ['group' => 'cols', 'value' => 'Bien'],
            ['group' => 'cols', 'value' => 'Mal'],
        ]);
        self::$fieldIds['ranking'] = Field::create((int) self::$formId, 'ranking', 'Ranking', '', '', false, [
            ['value' => 'A'],
            ['value' => 'B'],
            ['value' => 'C'],
        ]);
        self::$fieldIds['nps'] = Field::create((int) self::$formId, 'nps', 'NPS', '', '', false, [], ['min' => 0, 'max' => 10]);
        self::$fieldIds['star'] = Field::create((int) self::$formId, 'star', 'Estrellas', '', '', false, [], ['max' => 5]);
    }

    /* ---------- Validación (FillController::validateField) ---------- */

    public function testValidateMatrixCompletaPasa(): void
    {
        $field = $this->matrixField(false);
        $result = $this->invoke('validateField', $field, ['Sabor' => 'Bien', 'Precio' => 'Mal']);
        assertTrue($result === true);
    }

    public function testValidateMatrixObligatoriaIncompletaFalla(): void
    {
        $field = $this->matrixField(true);
        $result = $this->invoke('validateField', $field, ['Sabor' => 'Bien', 'Precio' => '']);
        assertTrue(is_string($result) && str_contains($result, 'todas las filas'));
    }

    public function testValidateMatrixColumnaDesconocidaFalla(): void
    {
        $field = $this->matrixField(false);
        $result = $this->invoke('validateField', $field, ['Sabor' => 'X', 'Precio' => 'Bien']);
        assertTrue(is_string($result) && str_contains($result, 'Selección inválida'));
    }

    public function testValidateRankingValidoPasa(): void
    {
        $field = $this->rankingField();
        $result = $this->invoke('validateField', $field, ['A' => '1', 'B' => '2', 'C' => '3']);
        assertTrue($result === true);
    }

    public function testValidateRankingIncompletoFalla(): void
    {
        $field = $this->rankingField();
        $result = $this->invoke('validateField', $field, ['A' => '1', 'B' => '', 'C' => '3']);
        assertTrue(is_string($result) && str_contains($result, 'todas las opciones'));
    }

    public function testValidateRankingDuplicadoFalla(): void
    {
        $field = $this->rankingField();
        $result = $this->invoke('validateField', $field, ['A' => '1', 'B' => '1', 'C' => '2']);
        assertTrue(is_string($result) && str_contains($result, 'no pueden repetirse'));
    }

    public function testValidateRankingFueraDeRangoFalla(): void
    {
        $field = $this->rankingField();
        $result = $this->invoke('validateField', $field, ['A' => '1', 'B' => '2', 'C' => '4']);
        assertTrue(is_string($result) && str_contains($result, 'entre 1 y 3'));
    }

    public function testValidateNpsRango(): void
    {
        $field = $this->npsField();
        assertTrue($this->invoke('validateField', $field, '0') === true);
        assertTrue($this->invoke('validateField', $field, '10') === true);
        $result = $this->invoke('validateField', $field, '11');
        assertTrue(is_string($result) && str_contains($result, 'entre 0 y 10'));
    }

    public function testValidateStarRango(): void
    {
        $field = $this->starField();
        assertTrue($this->invoke('validateField', $field, '1') === true);
        assertTrue($this->invoke('validateField', $field, '5') === true);
        assertTrue(is_string($this->invoke('validateField', $field, '6')));
        assertTrue(is_string($this->invoke('validateField', $field, '0')));
    }

    /* ---------- Normalización (FillController::normalizeFieldValue) ---------- */

    public function testNormalizeMatrixConstruyeMapaPorEtiqueta(): void
    {
        $field = $this->matrixField(false);
        $normalized = $this->invoke('normalizeFieldValue', $field, ['Bien', '']);
        assertEquals(['Sabor' => 'Bien', 'Precio' => ''], $normalized);
    }

    public function testNormalizeRankingConstruyeMapaPorOpcion(): void
    {
        $field = $this->rankingField();
        $normalized = $this->invoke('normalizeFieldValue', $field, ['1', '2', '']);
        assertEquals(['A' => '1', 'B' => '2', 'C' => ''], $normalized);
    }

    public function testNormalizeOtrosTiposSinCambios(): void
    {
        $normalized = $this->invoke('normalizeFieldValue', ['type' => 'text'], 'hola');
        assertEquals('hola', $normalized);
    }

    /* ---------- Stringify (preserva claves) ---------- */

    public function testStringifyPreservaClavesDeMapas(): void
    {
        $json = $this->invoke('stringify', ['Sabor' => 'Bien', 'Precio' => '']);
        assertEquals('{"Sabor":"Bien","Precio":""}', $json);
    }

    public function testStringifyArraysSinClavesMantieneLista(): void
    {
        $json = $this->invoke('stringify', ['a', 'b']);
        assertEquals('["a","b"]', $json);
    }

    /* ---------- Analytics (Answer::*StatsInForm) ---------- */

    public function testMatrixStatsDescomponeFilasYColumnas(): void
    {
        $this->seedSubmissions();
        $stats = Answer::matrixStatsInForm(
            (int) self::$formId,
            (int) self::$fieldIds['matrix'],
            ['Sabor', 'Precio'],
            ['Bien', 'Mal']
        );

        assertSame(2, $stats['total']);
        assertSame(2, $stats['matrix']['Sabor']['Bien']);
        assertSame(2, $stats['matrix']['Sabor']['_total']);
        assertSame(1, $stats['matrix']['Precio']['Mal']);
        assertSame(1, $stats['matrix']['Precio']['Bien']);
        assertSame(3, $stats['colCounts']['Bien']);
        assertSame(1, $stats['colCounts']['Mal']);
    }

    public function testRankingStatsPromediaOrdenes(): void
    {
        $this->seedSubmissions();
        $stats = Answer::rankingStatsInForm((int) self::$formId, (int) self::$fieldIds['ranking'], ['A', 'B', 'C']);
        $byOpt = [];
        foreach ($stats as $row) {
            $byOpt[$row['value']] = $row;
        }

        assertSame(2.0, $byOpt['A']['avg']);
        assertSame(1.5, $byOpt['B']['avg']);
        assertSame(2.5, $byOpt['C']['avg']);
        assertSame(2, $byOpt['A']['c']);
    }

    public function testNpsStatsCalculaScore(): void
    {
        $this->seedSubmissions();
        $stats = Answer::npsStatsInForm((int) self::$formId, (int) self::$fieldIds['nps']);

        assertSame(3, $stats['answer_count']);
        assertSame(1, $stats['promoters']);
        assertSame(0, $stats['passives']);
        assertSame(2, $stats['detractors']);
        assertSame(-33, $stats['nps']);
    }

    /* ---------- Helpers ---------- */

    private function matrixField(bool $required): array
    {
        return [
            'type' => 'matrix',
            'label' => 'M',
            'is_required' => $required,
            'options' => [
                ['group' => 'rows', 'value' => 'Sabor'],
                ['group' => 'rows', 'value' => 'Precio'],
                ['group' => 'cols', 'value' => 'Bien'],
                ['group' => 'cols', 'value' => 'Mal'],
            ],
            'validations' => [],
        ];
    }

    private function rankingField(): array
    {
        return [
            'type' => 'ranking',
            'label' => 'R',
            'is_required' => false,
            'options' => [['value' => 'A'], ['value' => 'B'], ['value' => 'C']],
            'validations' => [],
        ];
    }

    private function npsField(): array
    {
        return ['type' => 'nps', 'label' => 'N', 'is_required' => false, 'options' => [], 'validations' => ['min' => 0, 'max' => 10]];
    }

    private function starField(): array
    {
        return ['type' => 'star', 'label' => 'S', 'is_required' => false, 'options' => [], 'validations' => ['max' => 5]];
    }

    private function invoke(string $method, ...$args)
    {
        $ref = new \ReflectionMethod($this->controller, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->controller, ...$args);
    }

    private static function seedSubmissions(): void
    {
        $s1 = Submission::create((int) self::$formId, '1.1.1.1', 'TestAgent');
        Answer::create($s1, (int) self::$fieldIds['matrix'], '{"Sabor":"Bien","Precio":"Mal"}');
        Answer::create($s1, (int) self::$fieldIds['ranking'], '{"A":"1","B":"2","C":"3"}');
        Answer::create($s1, (int) self::$fieldIds['nps'], '9');
        Answer::create($s1, (int) self::$fieldIds['star'], '5');

        $s2 = Submission::create((int) self::$formId, '2.2.2.2', 'TestAgent');
        Answer::create($s2, (int) self::$fieldIds['matrix'], '{"Sabor":"Bien","Precio":"Bien"}');
        Answer::create($s2, (int) self::$fieldIds['ranking'], '{"A":"3","B":"1","C":"2"}');
        Answer::create($s2, (int) self::$fieldIds['nps'], '5');
        Answer::create($s2, (int) self::$fieldIds['star'], '3');

        $s3 = Submission::create((int) self::$formId, '3.3.3.3', 'TestAgent');
        Answer::create($s3, (int) self::$fieldIds['nps'], '2');
        Answer::create($s3, (int) self::$fieldIds['star'], '1');
    }

    private static function cleanupResiduals(): void
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $users = $db->fetchAll("SELECT id FROM users WHERE email LIKE '%@test.local'");
        foreach ($users as $u) {
            $db->execute('DELETE FROM users WHERE id = :id', ['id' => (int) $u['id']]);
        }
        $db->execute("DELETE FROM rate_limits WHERE scope LIKE 'test:%'");
        self::$formId = null;
        self::$userId = null;
        self::$fieldIds = [];
    }

    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            \App\Core\Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
            register_shutdown_function(static function () {
                self::cleanupResiduals();
            });
        }
    }
}