<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Contrast;

/**
 * Paridad del contraste automático entre PHP y JS (AG12).
 *
 * Cuando el editor deja el texto del degradado en «Automático», el constructor
 * (`textoAuto()` de `builder.js`) y el formulario publicado
 * (`App\Core\Contrast`) tienen que decidir lo mismo. Si uno dice blanco y el
 * otro tinta oscura, el mismo formulario se ve de dos maneras y no falla nada
 * en ningún sitio: no hay error, ni en PHP ni en el navegador.
 *
 * Hay dos capas, como en `FillProgressTest`:
 *
 *   · El MISMO cuadro de colores se pasa por `Contrast::inkFor()` aquí y por la
 *     función REAL extraída del JS en `tests/js/contrast.js`. Cambiar el cálculo
 *     en un solo lado rompe uno de los dos.
 *   · El arnés de node se ejecuta desde este test; sin `node` avisa y pasa (el
 *     runner trata un SKIP en `setUp()` como error, y un fallo inventado en una
 *     máquina sin node no dice nada).
 *
 * La tabla está DUPLICADA a propósito: es el contrato entre dos lenguajes, y
 * una sola fuente en PHP no se podría leer desde node sin montar la app.
 */
class ContrastParityTest
{
    private const JS = 'public/assets/js/builder.js';
    private const HARNESS = 'tests/js/contrast.js';

    /**
     * Columnas: color de fondo, tinta esperada. Copia literal de la tabla de
     * `tests/js/contrast.js`; si cambia una, cambia la otra.
     *
     * @return list<array{0:string,1:string}>
     */
    private function tabla(): array
    {
        return [
            ['#000000', '#ffffff'],
            ['#ffffff', '#1e293b'],
            ['#ff0000', '#ffffff'],
            ['#00ff00', '#1e293b'],
            ['#ffff00', '#1e293b'],
            ['#fff59d', '#1e293b'],
            ['#123456', '#ffffff'],
            ['#777777', '#ffffff'],
            ['#eeeeee', '#1e293b'],
            ['#EEE', '#1e293b'],
            ['#FFFFFF', '#1e293b'],
            ['', '#ffffff'],
            ['rojo', '#ffffff'],
            ['#12345', '#ffffff'],
            ['#11223344', '#ffffff'],
        ];
    }

    /* ---------- contrato (siempre) ---------- */

    public function testPhpDecideLoTablaIgualQueElJs(): void
    {
        foreach ($this->tabla() as [$color, $esperado]) {
            assertSame(
                $esperado,
                Contrast::inkFor($color),
                'Contrast::inkFor(' . var_export($color, true) . ') debe dar ' . $esperado
            );
        }
    }

    /* ---------- comportamiento (necesita node) ---------- */

    public function testElJsDecideLoTablaIgualQuePhp(): void
    {
        if (!$this->nodeAvailable()) {
            printf("        (sin node: la paridad del contraste JS no se ejecuta; %s)\n", self::HARNESS);
            return;
        }

        $output = [];
        $code = 0;
        $command = escapeshellcmd('node') . ' ' . escapeshellarg($this->path(self::HARNESS)) . ' 2>&1';
        @exec($command, $output, $code);

        $text = implode("\n", $output);
        assertEquals(
            0,
            $code,
            'el arnés de contraste falló (código ' . $code . "):\n" . $text
        );
        assertTrue(
            str_contains($text, 'Fallidos: 0'),
            'el arnés de contraste no llegó al final o tuvo fallos:' . "\n" . $text
        );
    }

    /* ---------- ayudas ---------- */

    private function nodeAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $out = [];
        $code = 0;
        @exec('node --version 2>&1', $out, $code);
        return $code === 0;
    }

    private function path(string $rel): string
    {
        return dirname(__DIR__, 2) . '/' . $rel;
    }
}
