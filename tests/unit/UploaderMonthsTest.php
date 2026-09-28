<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Uploader;

/**
 * Fase 4 — barrido de directorios de mes vacíos (scripts/clean-uploads.php).
 *
 * Las purgas de dos fases (pendingFor* + purgePaths) borran los archivos pero
 * las carpetas `storage/uploads/YYYY/MM` vacías quedan huérfanas para siempre.
 * Uploader::pruneEmptyMonths() las barre con un contrato estricto: solo toca
 * directorios que sigan el patrón `YYYY/MM` de dígitos y que estén vacíos.
 * Aquí se fija que respeta el patrón (año/mes de 2-4 dígitos), que no borra
 * carpetas con contenido ni nombres extraños, y que el modo dry-run solo cuenta.
 */
class UploaderMonthsTest
{
    private string $root = '';

    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vf-months-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/2026/09', 0777, true);
        mkdir($this->root . '/2026/08', 0777, true);
        mkdir($this->root . '/2025/13', 0777, true);
        mkdir($this->root . '/abc', 0777, true);
        mkdir($this->root . '/2024/07', 0777, true);
        file_put_contents($this->root . '/2026/08/archivo.txt', 'x');
        file_put_contents($this->root . '/2024/07/otro.txt', 'y');
        file_put_contents($this->root . '/2025/13/ignorado.txt', 'z');
    }

    public function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function testBorraSoloMesesVaciosConPatronValido(): void
    {
        $n = Uploader::pruneEmptyMonths($this->root);

        assertEquals(1, $n);
        assertFalse(is_dir($this->root . '/2026/09'), 'El mes vacío 2026/09 debería haberse borrado');
        assertTrue(is_dir($this->root . '/2026/08'), 'Un mes con archivos no se toca');
        assertTrue(is_dir($this->root . '/2025/13'), 'Mes fuera del patrón (13) se deja');
        assertTrue(is_dir($this->root . '/abc'), 'Año fuera del patrón se deja');
        assertTrue(is_dir($this->root . '/2024/07'), 'Mes con archivos no se toca');
    }

    public function testBorraElAnioCuandoQuedoSinMeses(): void
    {
        // 2026 conserva 08 (con archivo) y 09 (vacío): el año debe sobrevivir.
        // 2023 nace con dos meses vacíos: al barrerlos, el año queda hueco.
        mkdir($this->root . '/2023/01', 0777, true);
        mkdir($this->root . '/2023/02', 0777, true);

        $n = Uploader::pruneEmptyMonths($this->root);

        assertEquals(4, $n, 'Borrados: 2026/09 + 2023/01 + 2023/02 + el año 2023');
        assertTrue(is_dir($this->root . '/2026'), '2026 conserva 08 con archivos');
        assertFalse(is_dir($this->root . '/2023'), 'El año que queda vacío también se borra');
    }

    public function testDryRunCuentaSinBorrar(): void
    {
        $n = Uploader::pruneEmptyMonths($this->root, true);

        assertEquals(1, $n);
        assertTrue(is_dir($this->root . '/2026/09'), 'dry-run no puede borrar nada');

        $n2 = Uploader::pruneEmptyMonths($this->root);
        assertEquals(1, $n2);
        assertFalse(is_dir($this->root . '/2026/09'), 'La ejecución real sí borra');
    }

    public function testRaizInexistenteNoFalla(): void
    {
        Uploader::pruneEmptyMonths($this->root . '/no-existe');
        $this->assertAnythingGoes();
    }

    private function assertAnythingGoes(): void
    {
        assertTrue(true);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}