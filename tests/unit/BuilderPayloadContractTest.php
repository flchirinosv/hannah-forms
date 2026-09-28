<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * F3-bis — Contrato de la frontera builder.js ⇄ BuilderController.
 *
 * Regresión real: `buildPayload()` mandaba `fields` en crudo, con la clave
 * `parentId` (camelCase del estado en memoria), mientras el servidor lee
 * `parent_id`. La clave llegaba `undefined`, el servidor guardaba todos los
 * campos como sueltos y al reabrir el builder la sección aparecía vacía con
 * sus preguntas fuera. No había ningún test que lo atrapara porque el
 * fallo está en el emparejamiento de NOMBRES entre dos archivos.
 *
 * Por eso este test no ejercita el controller (su cuerpo viene de
 * `php://input`, no inyectable en CLI) sino que compara, literalmente, las
 * claves que el servidor lee con las que el cliente serializa.
 */
class BuilderPayloadContractTest
{
    private const JS = 'public/assets/js/builder.js';
    private const CTRL = 'app/Controllers/BuilderController.php';

    public function testElPayloadSerializaTodasLasClavesQueElServidorLee(): void
    {
        $sent = $this->keysSentByClient();
        $read = $this->keysReadByServer();

        assertTrue($read !== [], 'el controlador debe seguir leyendo claves de $item');

        $missing = array_values(array_diff($read, $sent));
        assertTrue(
            $missing === [],
            'buildPayload() no serializa: ' . implode(', ', $missing)
                . ' (el servidor las lee de $item; si no llegan, se pierden en silencio)'
        );
    }

    public function testLaJerarquiaSeEnviaComoParentIdSnakeCase(): void
    {
        $body = $this->buildPayloadBody();

        assertTrue(str_contains($body, 'parent_id:'), 'debe enviar la clave parent_id');
        assertTrue(
            !str_contains($body, 'parentId:'),
            'no debe enviar parentId: el estado en memoria es camelCase pero el contrato es snake_case'
        );
    }

    public function testElPayloadMapeaLosCamposEnCrudoEstaProhibido(): void
    {
        $body = $this->buildPayloadBody();

        assertTrue(
            !str_contains($body, 'fields: fields,'),
            'mandar `fields` en crudo reintroduce el bug: la jerarquía se pierde al recargar'
        );
        assertTrue(str_contains($body, '_uid:'), 'debe enviar el _uid que usa el servidor para resolver refs');
    }

    public function testLaReglaDeLogiaConservaElNombreDeLaReferencia(): void
    {
        $body = $this->buildPayloadBody();

        // El servidor resuelve $rule['field_id'] contra los uid, no contra ids.
        assertTrue(str_contains($body, 'field_id:'), 'las reglas deben enviar field_id');
    }

    /* ---------- ayudas ---------- */

    /** Claves que aparecen como `clave:` dentro del mapeo de fields. */
    private function keysSentByClient(): array
    {
        $body = $this->buildPayloadBody();
        preg_match_all('/^\s{16}([a-z_]+):/m', $body, $m);
        return array_values(array_unique($m[1]));
    }

    /** Claves que el controlador lee de cada item ($item['x']). */
    private function keysReadByServer(): array
    {
        $src = (string) file_get_contents($this->path(self::CTRL));
        preg_match_all("/\\\$item\['([a-z_]+)'\]/", $src, $m);
        return array_values(array_unique($m[1]));
    }

    /** Cuerpo de la función buildPayload(), entre su firma y el cierre del bloque. */
    private function buildPayloadBody(): string
    {
        $src = (string) file_get_contents($this->path(self::JS));
        $start = strpos($src, 'function buildPayload(');
        assertTrue($start !== false, 'no se encontró buildPayload() en builder.js');
        $end = strpos($src, "\n    }", (int) $start);
        assertTrue($end !== false, 'buildPayload() parece sin cerrar');
        return substr($src, (int) $start, (int) $end - (int) $start);
    }

    private function path(string $rel): string
    {
        return dirname(__DIR__, 2) . '/' . $rel;
    }
}
