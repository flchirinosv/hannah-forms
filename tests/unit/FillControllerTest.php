<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FillController;

/**
 * Tests de validación de FillController (M8: checkbox whitelist).
 */
class FillControllerTest
{
    private FillController $controller;

    public function setUp(): void
    {
        $this->controller = new FillController();
    }

    public function testCheckboxValidoPasaValidacion(): void
    {
        $field = [
            'type' => 'checkbox',
            'label' => 'Intereses',
            'options' => [
                ['value' => 'php', 'label' => 'PHP'],
                ['value' => 'js', 'label' => 'JS'],
            ],
        ];
        $result = $this->invokeValidateField($field, ['php', 'js']);
        assertSame(true, $result);
    }

    public function testCheckboxValorInvalidoFallaValidacion(): void
    {
        $field = [
            'type' => 'checkbox',
            'label' => 'Intereses',
            'options' => [
                ['value' => 'php', 'label' => 'PHP'],
                ['value' => 'js', 'label' => 'JS'],
            ],
        ];
        $result = $this->invokeValidateField($field, ['php', 'ruby']);
        assertTrue(is_string($result) && str_contains($result, 'Selección inválida'));
    }

    private function invokeValidateField(array $field, $value)
    {
        $ref = new \ReflectionMethod($this->controller, 'validateField');
        $ref->setAccessible(true);
        return $ref->invoke($this->controller, $field, $value);
    }

    /* ---------- Gap sin JavaScript: visibilidad inicial del render ----------
     * El GET rendereaba todos los campos y delegaba el ocultado a fill.js, así
     * que sin JS se veían preguntas con la condición en falso. Estos tests
     * fijan la semántica servidor: sección oculta → hijas ocultas (AND), y el
     * bloque de una página omitida tampoco se muestra. */

    public function testInitialVisibilityOcultaHijaDeSeccionConCondicionFalsa(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'label' => '¿Eres mayor?', 'parent_id' => null],
            ['id' => 2, 'type' => 'section', 'label' => 'Sección', 'parent_id' => null, 'logic' => [
                'operator' => 'all',
                'rules' => [['field_id' => 1, 'op' => 'eq', 'value' => 'sí']],
            ]],
            ['id' => 3, 'type' => 'text', 'label' => 'Hija sin lógica', 'parent_id' => 2],
            ['id' => 4, 'type' => 'text', 'label' => 'Hija con lógica propia', 'parent_id' => 2, 'logic' => [
                'operator' => 'all',
                'rules' => [['field_id' => 1, 'op' => 'filled', 'value' => '']],
            ]],
        ];
        // Sin respuestas, la afirmación eq → FALSE: la sección y sus dos hijas
        // (con y sin condición propia) van ocultas.
        $v = FillController::initialVisibility($fields);
        assertSame(false, $v[2]);
        assertSame(false, $v[3]);
        assertSame(false, $v[4]);
        assertSame(true, $v[1]);
    }

    public function testInitialVisibilityMuestraHijaCuandoLaSeccionSeCumple(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'label' => 'Edad', 'parent_id' => null],
            ['id' => 2, 'type' => 'section', 'label' => 'Sección', 'parent_id' => null, 'logic' => [
                'operator' => 'all',
                'rules' => [['field_id' => 1, 'op' => 'gt', 'value' => '18']],
            ]],
            ['id' => 3, 'type' => 'text', 'label' => 'Hija', 'parent_id' => 2],
            ['id' => 4, 'type' => 'text', 'label' => 'Hija con negated propia', 'parent_id' => 2, 'logic' => [
                'operator' => 'all',
                'rules' => [['field_id' => 1, 'op' => 'filled', 'value' => '']],
            ]],
        ];
        // Re-render de errores: la respuesta enviada cumple la sección, así que
        // la hija sin lógica aparece y la que pide «tiene valor» también.
        $v = FillController::initialVisibility($fields, ['field_1' => '30']);
        assertSame(true, $v[2]);
        assertSame(true, $v[3]);
        assertSame(true, $v[4]);
    }

    public function testInitialVisibilityRespetaLaPropiaCondicionDeLaHija(): void
    {
        // AND: la sección se cumple pero la hija NO → la hija sigue oculta.
        // Es el modelo de la industria (regla de grupo AND regla propia).
        $fields = [
            ['id' => 1, 'type' => 'text', 'label' => 'Pais', 'parent_id' => null],
            ['id' => 2, 'type' => 'section', 'label' => 'Sección', 'parent_id' => null, 'logic' => [
                'operator' => 'all',
                'rules' => [['field_id' => 1, 'op' => 'filled', 'value' => '']],
            ]],
            ['id' => 3, 'type' => 'text', 'label' => 'Hija condicional', 'parent_id' => 2, 'logic' => [
                'operator' => 'all',
                'rules' => [['field_id' => 1, 'op' => 'eq', 'value' => 'otro']],
            ]],
        ];
        $v = FillController::initialVisibility($fields, ['field_1' => 'MX']);
        assertSame(true, $v[2]);
        assertSame(false, $v[3]);
    }

    public function testInitialVisibilityOcultaBloqueDePaginaOmitido(): void
    {
        // Orden de lectura: el campo del paso 1 va ANTES del «page»; el que va
        // después pertenece al bloque que ese «page» abre.
        $fields = [
            ['id' => 1, 'type' => 'text', 'label' => 'Fase', 'parent_id' => null],
            ['id' => 2, 'type' => 'text', 'label' => 'Del paso 1', 'parent_id' => null],
            ['id' => 3, 'type' => 'page', 'label' => 'Paso 2', 'parent_id' => null, 'logic' => [
                'operator' => 'all',
                'rules' => [['field_id' => 1, 'op' => 'neq', 'value' => 'final']],
            ]],
            ['id' => 4, 'type' => 'text', 'label' => 'Del paso 2', 'parent_id' => null],
        ];
        // neq con fuente vacía → TRUE → el paso se OMITE (semántica invertida:
        // aquí la condición del «page» verdadera significa saltar), así que su
        // bloque entero no se muestra. El paso 1 sí.
        $v = FillController::initialVisibility($fields);
        assertSame(false, $v[4]);
        assertSame(true, $v[2]);
        assertSame(true, $v[1]);
    }

    public function testInitialVisibilityNoOcultaNadaSinCondiciones(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'label' => 'A', 'parent_id' => null],
            ['id' => 2, 'type' => 'section', 'label' => 'S', 'parent_id' => null],
            ['id' => 3, 'type' => 'text', 'label' => 'B', 'parent_id' => 2],
        ];
        $v = FillController::initialVisibility($fields);
        assertSame([1 => true, 2 => true, 3 => true], $v);
    }

    public function testInitialVisibilityIgnoraReferenciasACamposInexistentes(): void
    {
        // Fail-open: una regla que apunta a un campo borrado no debe ocultar
        // una pregunta obligatoria (misma política que el submit).
        $fields = [
            ['id' => 1, 'type' => 'text', 'label' => 'A', 'parent_id' => null, 'logic' => [
                'operator' => 'all',
                'rules' => [['field_id' => 999, 'op' => 'eq', 'value' => 'x']],
            ]],
        ];
        $v = FillController::initialVisibility($fields);
        assertSame(true, $v[1]);
    }
}
