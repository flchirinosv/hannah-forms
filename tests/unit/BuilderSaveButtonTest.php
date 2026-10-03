<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * M8-bis — Contrato del botón de «Guardar» del constructor.
 *
 * Regresión real que reportó un usuario: al hacer el **segundo clic** sin
 * haber cambiado nada, el botón se quedaba con el spinner «Guardando…» para
 * siempre, apagado y sin poder volver a pulsarse. Sin red, sin error en
 * consola y sin aviso.
 *
 * La causa no era el clic, era una SALIDA. `saveStructure()` apagaba el botón
 * y metía el spinner; el atajo de «no hay cambios» (el
 * `payloadJson === lastSavedJson` de M8, que evita el POST) hacía `return`
 * **antes** del `try`, y ese `return` se saltaba el `finally` que era lo
 * único que reponía el botón. El `finally` solo corre si hubo `try`.
 *
 * Por eso este test no ejercita la función —`fetch` y el resto del DOM no son
 * inyectables aquí— sino que ata el **orden de las salidas**: desde que se
 * carga el botón, ninguna salida puede saltarse su reposición. Es la forma de
 * que el próximo atajo que se escriba después no repita el fallo en silencio.
 *
 * Nota: `return` dentro de `pintarBoton()` no cuenta, porque es la función que
 * restaura y esos `return` son los suyos.
 */
class BuilderSaveButtonTest
{
    private const JS = 'public/assets/js/builder.js';

    public function testNingunaSalidaSeSaltaLaReposicionDelBoton(): void
    {
        $cuerpo = $this->saveStructureBody();
        $carga = strpos($cuerpo, 'pintarBoton(true);');
        assertTrue($carga !== false, 'saveStructure() debe apagar el botón con pintarBoton(true)');

        preg_match_all('/\breturn\b/', $cuerpo, $m, PREG_OFFSET_CAPTURE);
        assertTrue($m[0] !== [], 'saveStructure() debe conservar sus salidas');

        foreach ($m[0] as $salida) {
            $pos = (int) $salida[1];
            if ($pos < (int) $carga) {
                continue;   // el guardia `if (saving) return;`, anterior al apagado
            }
            $prefijo = substr($cuerpo, (int) $carga, $pos - (int) $carga);
            assertTrue(
                str_contains($prefijo, 'pintarBoton(false);'),
                'hay un return en saveStructure() que se salta pintarBoton(false): el botón '
                    . 'se queda en «Guardando…» para siempre (bug del segundo clic)'
            );
        }
    }

    public function testElAtajoDeSinCambiosReponeElBoton(): void
    {
        $cuerpo = $this->saveStructureBody();

        assertTrue(
            str_contains($cuerpo, 'payloadJson === lastSavedJson'),
            'debe seguir existiendo el atajo de M8 que evita el POST sin cambios'
        );

        $atajo = strpos($cuerpo, 'payloadJson === lastSavedJson');
        $salida = strpos($cuerpo, 'return', (int) $atajo);
        $ramo = substr($cuerpo, (int) $atajo, (int) $salida - (int) $atajo);

        assertTrue(
            str_contains($ramo, 'pintarBoton(false);'),
            'el atajo de «sin cambios» devuelve el botón antes de salir, no por el finally'
        );
    }

    public function testElFinallyDelGuardadoReponeElBoton(): void
    {
        $cuerpo = $this->saveStructureBody();

        $finally = strpos($cuerpo, '} finally {');
        assertTrue($finally !== false, 'saveStructure() debe tener su reposición en un finally');

        $cola = substr($cuerpo, (int) $finally, 240);
        assertTrue(
            str_contains($cola, 'pintarBoton(false);'),
            'el finally es lo que repone el botón tras el POST, incluidos el éxito y el error'
        );
    }

    public function testElBotonSeReponeDesdeUnSoloSitio(): void
    {
        $cuerpo = $this->saveStructureBody();

        // Una sola restitución = un solo sitio que auditing. Si aparece otra,
        // significa que hay dos verdades sobre el estado del botón.
        $restauraciones = preg_match_all('/saveBtn\.disabled = false/', $cuerpo);
        assertTrue(
            $restauraciones === 1,
            'el botón debe reponerse desde un único sitio (pintarBoton); se han encontrado '
                . $restauraciones
        );
    }

    /* ---------- ayudas ---------- */

    /**
     * Cuerpo de saveStructure(), sin comentarios y hasta el cierre del bloque.
     *
     * Los comentarios se quitan porque este test lee la ESTRUCTURA de las
     * salidas, y el orden dentro de un comentario no es una salida: el
     * comentario que explica este mismo bug menciona `return` y sin quitarlo
     * el test fallaría por su propia explicación.
     */
    private function saveStructureBody(): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::JS);
        $start = strpos($src, 'async function saveStructure(');
        assertTrue($start !== false, 'no se encontró saveStructure() en builder.js');
        $end = strpos($src, "\n    }", (int) $start);
        assertTrue($end !== false, 'saveStructure() parece sin cerrar');
        return $this->sinComentarios(substr($src, (int) $start, (int) $end - (int) $start));
    }

    /** Quita comentarios de bloque y de línea, sin tocar las cadenas. */
    private function sinComentarios(string $cuerpo): string
    {
        $cuerpo = preg_replace('#/\*.*?\*/#s', ' ', (string) $cuerpo);
        $lineas = array_map(
            static function (string $l): string {
                return preg_match('/^\s*(\/\/|\*)/', $l) ? '' : $l;
            },
            explode("\n", (string) $cuerpo)
        );
        return implode("\n", $lineas);
    }
}