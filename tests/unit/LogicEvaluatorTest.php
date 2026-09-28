<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LogicEvaluator;

/**
 * Tests unitarios (sin BD) del motor de lógica condicional: semántica de las
 * reglas, orden topológico (Kahn) y validación de estructura/ciclos.
 */
class LogicEvaluatorTest
{
    private const ALLOWED = ['text', 'email', 'number', 'select', 'radio', 'checkbox', 'scale', 'range', 'section'];
    private const ANSWERABLE = ['text', 'email', 'number', 'select', 'radio', 'checkbox', 'scale', 'range'];

    public function testSinLogicaOConReglasVaciasEsVisible(): void
    {
        assertTrue(LogicEvaluator::evaluate(null, []), 'sin condición debe mostrarse');
        assertTrue(LogicEvaluator::evaluate(['operator' => 'all', 'rules' => []], []), 'reglas vacías muestran');
        assertTrue(LogicEvaluator::evaluate(['operator' => 'all'], ['1' => 'x']), 'sin reglas muestra');
    }

    public function testAfirmacionesConFuenteVaciaSonFalsas(): void
    {
        $rule = ['field_id' => 1, 'op' => 'eq', 'value' => 'sí'];
        assertFalse(LogicEvaluator::evaluateRule($rule, []), 'eq sin respuesta debe ser false');
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'contains', 'value' => 'x'], ['1' => '']), 'contains con vacío false');
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'filled', 'value' => ''], ['1' => '']), 'filled con vacío false');
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 9, 'op' => 'gt', 'value' => '3'], ['1' => '5']), 'fuente desconocida false');
    }

    public function testNegacionesConFuenteVaciaSonVerdaderas(): void
    {
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'neq', 'value' => 'sí'], []), 'neq sin respuesta debe ser true');
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'not_contains', 'value' => 'x'], []), 'not_contains sin respuesta true');
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'empty', 'value' => ''], ['1' => '']), 'empty con vacío true');
    }

    public function testEqComparaValor(): void
    {
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'eq', 'value' => 'sí'], ['1' => 'sí']));
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'eq', 'value' => 'sí'], ['1' => 'no']));
    }

    public function testContainsEnCheckboxEsOpcionMarcada(): void
    {
        $answers = ['5' => ['Opc A', 'Opc C']];
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 5, 'op' => 'contains', 'value' => 'Opc C'], $answers));
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 5, 'op' => 'contains', 'value' => 'Opc B'], $answers));
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 5, 'op' => 'eq', 'value' => 'Opc A'], $answers));
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 5, 'op' => 'not_contains', 'value' => 'Opc B'], $answers));
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 5, 'op' => 'filled', 'value' => ''], $answers));
    }

    public function testCheckboxVacioEsOcultante(): void
    {
        $answers = ['5' => []];
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 5, 'op' => 'contains', 'value' => 'Opc A'], $answers), 'checkbox vacío: opción no marcada');
    }

    public function testComparadoresNumericos(): void
    {
        $a = ['1' => '7'];
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'gt', 'value' => '6'], $a));
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'gte', 'value' => '7'], $a));
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'lte', 'value' => '6'], $a));
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'lt', 'value' => '8'], $a));
    }

    public function testCombinadorAllYAny(): void
    {
        $logic = [
            'operator' => 'all',
            'rules' => [
                ['field_id' => 1, 'op' => 'eq', 'value' => 'sí'],
                ['field_id' => 2, 'op' => 'eq', 'value' => 'no'],
            ],
        ];
        assertFalse(LogicEvaluator::evaluate($logic, ['1' => 'sí', '2' => 'sí']), 'all con una regla falsa oculta');

        $logic['operator'] = 'any';
        assertTrue(LogicEvaluator::evaluate($logic, ['1' => 'sí', '2' => 'sí']), 'any con una regla verdadera muestra');

        assertTrue(LogicEvaluator::evaluate(['operator' => 'all', 'rules' => [
            ['field_id' => 1, 'op' => 'eq', 'value' => 'sí'],
            ['field_id' => 2, 'op' => 'eq', 'value' => 'no'],
        ]], ['1' => 'sí', '2' => 'no']), 'all con todas las reglas verdaderas muestra');
    }

    public function testOperadorNoPermitidoDevuelveFalse(): void
    {
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'regex', 'value' => 'x'], ['1' => 'x']));
    }

    /* ---------- P8: op matches (regex) ---------- */

    public function testMatchesRegexCoincide(): void
    {
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'matches', 'value' => '^[a-c]+$'], ['1' => 'abc']));
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'matches', 'value' => '^[a-c]+$'], ['1' => 'xyz']));
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'matches', 'value' => '^madrid$'], ['1' => 'MADRID']), 'case-insensitive (i)');
    }

    public function testMatchesRegexEnCheckboxBastaUnaCoincidencia(): void
    {
        $answers = ['5' => ['abc', 'xyz']];
        assertTrue(LogicEvaluator::evaluateRule(['field_id' => 5, 'op' => 'matches', 'value' => '^xyz$'], $answers));
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 5, 'op' => 'matches', 'value' => '^foo$'], $answers));
        // Con checkbox vacío es una afirmación → false.
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 5, 'op' => 'matches', 'value' => '^xyz$'], ['5' => []]));
    }

    public function testMatchesRegexPatronInvalidoEsFailSafe(): void
    {
        // Patrón mal formado (paréntesis sin cerrar): false y no lanza warning.
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'matches', 'value' => '('], ['1' => 'abc']));
        // Sin fuente → afirmación false.
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'matches', 'value' => '^a'], []));
        // Patrón vacío → false.
        assertFalse(LogicEvaluator::evaluateRule(['field_id' => 1, 'op' => 'matches', 'value' => ''], ['1' => 'a']));
    }

    public function testDependencyOrderEncadenaDependencias(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'parent_id' => null, 'logic' => null],
            ['id' => 2, 'type' => 'text', 'parent_id' => null, 'logic' => ['operator' => 'all', 'rules' => [['field_id' => 1, 'op' => 'eq', 'value' => 'x']]]],
        ];
        assertSame([1, 2], LogicEvaluator::dependencyOrder($fields), 'la fuente debe evaluarse antes que el condicionado');
    }

    /* ---------- P8: extraDeps (soporte salta-página) ---------- */

    public function testDependencyOrderConExtraDepsColocaPaginaAntesDeSusPreguntas(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'parent_id' => null, 'logic' => null],
            ['id' => 2, 'type' => 'page', 'parent_id' => null, 'logic' => ['operator' => 'all', 'rules' => [['field_id' => 1, 'op' => 'eq', 'value' => 'si']]]],
            ['id' => 3, 'type' => 'text', 'parent_id' => null, 'logic' => null],
        ];
        // La pregunta del bloque depende del «page» que la abre.
        $order = LogicEvaluator::dependencyOrder($fields, [3 => [2]]);
        assertNotNull($order);
        // q1 es fuente de la página; la página va antes que su pregunta q3.
        assertTrue(array_search(1, $order, true) < array_search(2, $order, true));
        assertTrue(array_search(2, $order, true) < array_search(3, $order, true));
    }

    public function testDependencyOrderExtraDepsIgnoraIdInexistente(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'parent_id' => null, 'logic' => null],
        ];
        $order = LogicEvaluator::dependencyOrder($fields, [1 => [999]]);
        assertNotNull($order);
        assertSame([1], $order);
    }

    public function testDependencyOrderExtraDepsAPaginaMismaCreaCiclo(): void
    {
        // Una regla de página que referencia a una pregunta de SU bloque
        // (extraDeps: pregunta → página) forma un ciclo → se rechaza al guardar
        // (caveat P8 documentado) y dependencyOrder devuelve null.
        $fields = [
            ['id' => 2, 'type' => 'page', 'parent_id' => null, 'logic' => ['operator' => 'all', 'rules' => [['field_id' => 3, 'op' => 'eq', 'value' => 'x']]]],
            ['id' => 3, 'type' => 'text', 'parent_id' => null, 'logic' => null],
        ];
        assertNull(LogicEvaluator::dependencyOrder($fields, [3 => [2]]), 'referencia de página a su propio bloque crea ciclo');
    }

    public function testDependencyOrderIncluyeLaSeccionPadre(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'section', 'parent_id' => null, 'logic' => null],
            ['id' => 2, 'type' => 'text', 'parent_id' => 1, 'logic' => null],
        ];
        assertSame([1, 2], LogicEvaluator::dependencyOrder($fields), 'la sección precede a sus hijos');
    }

    public function testDependencyOrderRespetaReferenciasAnterioresYPosteriores(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'parent_id' => null, 'logic' => ['operator' => 'all', 'rules' => [['field_id' => 3, 'op' => 'eq', 'value' => 'x']]]],
            ['id' => 2, 'type' => 'text', 'parent_id' => null, 'logic' => null],
            ['id' => 3, 'type' => 'text', 'parent_id' => null, 'logic' => null],
        ];
        $order = LogicEvaluator::dependencyOrder($fields);
        assertNotNull($order, 'debe existir orden');
        assertSame([2, 3, 1], $order, 'campo posterior referenciado se evalúa antes');
    }

    public function testDependencyOrderCicloDevuelveNull(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'parent_id' => null, 'logic' => ['operator' => 'all', 'rules' => [['field_id' => 2, 'op' => 'eq', 'value' => 'x']]]],
            ['id' => 2, 'type' => 'text', 'parent_id' => null, 'logic' => ['operator' => 'all', 'rules' => [['field_id' => 1, 'op' => 'eq', 'value' => 'x']]]],
        ];
        assertNull(LogicEvaluator::dependencyOrder($fields), 'un ciclo debe devolver null');
    }

    public function testDependencyOrderCicloConSeccionDevuelveNull(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'section', 'parent_id' => null, 'logic' => ['operator' => 'all', 'rules' => [['field_id' => 2, 'op' => 'filled', 'value' => '']]]],
            ['id' => 2, 'type' => 'text', 'parent_id' => 1, 'logic' => null],
        ];
        assertNull(LogicEvaluator::dependencyOrder($fields), 'sección condicionada por su propio hijo crea ciclo');
    }

    public function testReferenciaAInexistenteSeIgnoraEnOrden(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'parent_id' => null, 'logic' => ['operator' => 'all', 'rules' => [['field_id' => 99, 'op' => 'eq', 'value' => 'x']]]],
            ['id' => 2, 'type' => 'text', 'parent_id' => null, 'logic' => null],
        ];
        $order = LogicEvaluator::dependencyOrder($fields);
        assertNotNull($order, 'ref a id inexistente no debe romper el orden');
        assertSame(2, count($order));
    }

    public function testValidateStructureAceptaEstructuraValida(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'section', 'parent_id' => null, 'logic' => null],
            ['id' => 2, 'type' => 'radio', 'parent_id' => 1, 'logic' => ['operator' => 'all', 'rules' => [['field_id' => 3, 'op' => 'eq', 'value' => 'sí']]]],
            ['id' => 3, 'type' => 'select', 'parent_id' => null, 'logic' => null],
        ];
        assertNull(LogicEvaluator::validateStructure($fields, self::ALLOWED, self::ANSWERABLE));
    }

    public function testValidateStructureDetectaCiclo(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'parent_id' => null, 'logic' => ['operator' => 'all', 'rules' => [['field_id' => 2, 'op' => 'eq', 'value' => 'x']]]],
            ['id' => 2, 'type' => 'text', 'parent_id' => null, 'logic' => ['operator' => 'all', 'rules' => [['field_id' => 1, 'op' => 'eq', 'value' => 'x']]]],
        ];
        $err = LogicEvaluator::validateStructure($fields, self::ALLOWED, self::ANSWERABLE);
        assertNotNull($err, 'un ciclo debe rechazarse al guardar');
        assertTrue(stripos((string) $err, 'ciclo') !== false, 'el error debe mencionar el ciclo: ' . $err);
    }

    public function testValidateStructureRechazaTipoNoPermitido(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'file', 'parent_id' => null, 'logic' => null],
        ];
        $err = LogicEvaluator::validateStructure($fields, self::ALLOWED, self::ANSWERABLE);
        assertNotNull($err);
        assertTrue(stripos((string) $err, 'Tipo de campo no permitido') === 0);
    }

    public function testValidateStructureRechazaCombinadorInvalido(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'parent_id' => null, 'logic' => ['operator' => 'xor', 'rules' => [['field_id' => 2, 'op' => 'eq', 'value' => 'x']]]],
            ['id' => 2, 'type' => 'text', 'parent_id' => null, 'logic' => null],
        ];
        $err = LogicEvaluator::validateStructure($fields, self::ALLOWED, self::ANSWERABLE);
        assertTrue($err !== null && stripos($err, 'Combinador') !== false, 'debe rechazar operador inválido');
    }

    public function testValidateStructureRechazaReglaSinEstructura(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'parent_id' => null, 'logic' => 'no-es-array'],
        ];
        assertNotNull(LogicEvaluator::validateStructure($fields, self::ALLOWED, self::ANSWERABLE));
    }

    public function testValidateStructureRechazaDemasiadasReglas(): void
    {
        $rules = [];
        for ($i = 0; $i < 21; $i++) {
            $rules[] = ['field_id' => 2, 'op' => 'eq', 'value' => (string) $i];
        }
        $fields = [
            ['id' => 1, 'type' => 'text', 'parent_id' => null, 'logic' => ['operator' => 'all', 'rules' => $rules]],
            ['id' => 2, 'type' => 'text', 'parent_id' => null, 'logic' => null],
        ];
        $err = LogicEvaluator::validateStructure($fields, self::ALLOWED, self::ANSWERABLE);
        assertNotNull($err);
        assertTrue(stripos((string) $err, 'Demasiadas') !== false);
    }
}