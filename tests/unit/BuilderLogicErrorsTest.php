<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\BuilderController;

/**
 * F3 — Causas distinguibles al guardar una condición.
 *
 * `resolveLogic()` devolvía `null` para cuatro fallos distintos y el
 * controlador respondía siempre con el mismo texto («hace referencia a un
 * campo inexistente, a sí misma o a una sección»), así que el autor no podía
 * saber cuál era. Estos tests fijan el código de error y que el mensaje diga
 * algo accionable.
 *
 * No necesita BD: se invocan los métodos privados por reflexión con mapas
 * sintéticos, igual que `FillControllerTest` con `validateField()`.
 */
class BuilderLogicErrorsTest
{
    private BuilderController $controller;

    public function setUp(): void
    {
        $this->controller = new BuilderController();
    }

    public function testAutoReferenciaSeDistingue(): void
    {
        $maps = $this->maps();
        $res = $this->resolve('field-2', [
            'operator' => 'all',
            'rules'    => [['field_id' => 'field-2', 'op' => 'filled', 'value' => '']],
        ], $maps);

        assertEquals('self', $res['error'] ?? null);
        $msg = $this->message($res['error'], 'Edad');
        assertTrue(str_contains($msg, '«Edad»'), 'el mensaje debe nombrar el elemento');
        assertTrue(str_contains($msg, 'su propia respuesta'), 'el mensaje debe explicar la auto-referencia');
    }

    public function testSeccionComoFuenteSeDistingue(): void
    {
        $maps = $this->maps();
        $res = $this->resolve('field-3', [
            'operator' => 'all',
            'rules'    => [['field_id' => 'field-1', 'op' => 'filled', 'value' => '']],
        ], $maps);

        assertEquals('section', $res['error'] ?? null);
        $msg = $this->message($res['error'], 'Pais');
        assertTrue(str_contains($msg, 'no reciben respuesta'), 'debe explicar por qué no vale');
    }

    public function testPaginaComoFuenteTambienSeDistingue(): void
    {
        $maps = $this->maps();
        $res = $this->resolve('field-3', [
            'operator' => 'all',
            'rules'    => [['field_id' => 'field-4', 'op' => 'filled', 'value' => '']],
        ], $maps);

        assertEquals('section', $res['error'] ?? null);
    }

    public function testReferenciaInexistenteSeDistingue(): void
    {
        $maps = $this->maps();
        $res = $this->resolve('field-2', [
            'operator' => 'all',
            'rules'    => [['field_id' => 'field-99', 'op' => 'filled', 'value' => '']],
        ], $maps);

        assertEquals('missing', $res['error'] ?? null);
        assertTrue(str_contains($this->message($res['error'], 'Edad'), 'ya no existe'));
    }

    public function testOperadorDesconocidoSeDistingue(): void
    {
        $maps = $this->maps();
        $res = $this->resolve('field-2', [
            'operator' => 'all',
            'rules'    => [['field_id' => 'field-3', 'op' => 'inventado', 'value' => '']],
        ], $maps);

        assertEquals('invalid', $res['error'] ?? null);
    }

    public function testReglasVaciasNoSonUnErrorDeReferencia(): void
    {
        // El cliente nunca envía reglas vacías (translateLogic() devuelve
        // null), pero si llegaran deben caer en 'invalid', no inventarse
        // una referencia inexistente.
        $maps = $this->maps();
        $res = $this->resolve('field-2', ['operator' => 'all', 'rules' => []], $maps);

        assertEquals('invalid', $res['error'] ?? null);
    }

    public function testCondicionValidaSeResuelveAIdsDeBd(): void
    {
        $maps = $this->maps();
        $res = $this->resolve('field-2', [
            'operator' => 'all',
            'rules'    => [['field_id' => 'field-3', 'op' => 'eq', 'value' => 'si']],
        ], $maps);

        assertTrue(!isset($res['error']), 'una condición válida no debe fallar');
        assertEquals('all', $res['logic']['operator'] ?? null);
        assertEquals(303, $res['logic']['rules'][0]['field_id'] ?? null, 'la referencia se resuelve a id de BD');
        assertEquals('si', $res['logic']['rules'][0]['value'] ?? null);
    }

    public function testOperadorSinValorDescartaElValor(): void
    {
        // 'filled'/'empty' no admiten valor: se guarda vacío aunque el cliente
        // mande algo (mismo criterio que el resto de validaciones).
        $maps = $this->maps();
        $res = $this->resolve('field-2', [
            'operator' => 'all',
            'rules'    => [['field_id' => 'field-3', 'op' => 'filled', 'value' => 'basura']],
        ], $maps);

        assertEquals('', $res['logic']['rules'][0]['value'] ?? null);
    }

    public function testCombinadorAnySeConserva(): void
    {
        $maps = $this->maps();
        $res = $this->resolve('field-2', [
            'operator' => 'any',
            'rules'    => [['field_id' => 'field-3', 'op' => 'eq', 'value' => 'si']],
        ], $maps);

        assertEquals('any', $res['logic']['operator'] ?? null);
    }

    public function testMensajeSinEtiquetaNoRompe(): void
    {
        // Si el campo viene sin etiqueta, el mensaje sigue siendo texto válido.
        $msg = $this->message('self', '');
        assertTrue(str_contains($msg, 'este elemento'));
        assertTrue(!str_contains($msg, '«'), 'no debe dejar comillas sin cerrar');
    }

    /* ---------- ayudas ---------- */

    /**
     * uid => id de BD y su inversa, con los tipos que usa `resolveLogic()`.
     * field-1 = sección, field-4 = página (ninguno puede ser fuente),
     * field-2/field-3 = preguntas contestables.
     */
    private function maps(): array
    {
        $uidToId = ['field-1' => 301, 'field-2' => 302, 'field-3' => 303, 'field-4' => 304];
        $idToUid = array_flip($uidToId);
        $typeOf = array_fill_keys(array_keys($uidToId), 'text');
        $typeOf['field-1'] = 'section';
        $typeOf['field-4'] = 'page';
        $payloadByUid = [
            'field-1' => ['type' => 'section', 'label' => 'Bloque'],
            'field-2' => ['type' => 'text', 'label' => 'Edad'],
            'field-3' => ['type' => 'radio', 'label' => 'Pais'],
            'field-4' => ['type' => 'page', 'label' => 'Paso 2'],
        ];
        return [$uidToId, $idToUid, $typeOf, $payloadByUid];
    }

    private function resolve(string $uid, array $logicRaw, array $maps): array
    {
        [$uidToId, $idToUid, $typeOf, $payloadByUid] = $maps;
        $ref = new \ReflectionMethod($this->controller, 'resolveLogic');
        $ref->setAccessible(true);
        return $ref->invoke($this->controller, $uid, $logicRaw, $uidToId, $idToUid, $typeOf, $payloadByUid);
    }

    private function message(string $code, string $label): string
    {
        $ref = new \ReflectionMethod($this->controller, 'logicErrorMessage');
        $ref->setAccessible(true);
        return (string) $ref->invoke($this->controller, $code, $label);
    }
}
