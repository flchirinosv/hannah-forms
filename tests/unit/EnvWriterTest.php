<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Env;
use App\Core\EnvWriter;

/**
 * Escritor de `.env` (AG11): preserva comentarios, no duplica claves y escribe
 * con permisos 0600.
 */
class EnvWriterTest
{
    private string $dir;
    private string $path;

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vf_envwriter_' . bin2hex(random_bytes(4));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('No se pudo crear el tmpdir de prueba');
        }
        $this->path = $this->dir . '/.env';
    }

    public function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testCreaUnEnvNuevoYPreservaElOrdenDeInserccion(): void
    {
        (new EnvWriter($this->path))
            ->set('DB_NAME', 'vanilla')
            ->set('DB_USER', 'root')
            ->save();

        $lineas = file($this->path, FILE_IGNORE_NEW_LINES);
        assertEquals('DB_NAME=vanilla', $lineas[0]);
        assertEquals('DB_USER=root', $lineas[1]);
    }

    public function testReemplazaLaClaveSinDuplicarYPreservaComentarios(): void
    {
        file_put_contents($this->path, "# cabecera\nDB_NAME=viejo\n# nota\nDB_USER=u\n");

        (new EnvWriter($this->path))->set('DB_NAME', 'nuevo')->save();

        $texto = (string) file_get_contents($this->path);
        assertEquals(1, substr_count($texto, 'DB_NAME='));
        assertTrue(str_contains($texto, '# cabecera'), 'preserva el comentario de cabecera');
        assertTrue(str_contains($texto, '# nota'), 'preserva el comentario intermedio');
        assertTrue(str_contains($texto, 'DB_NAME=nuevo'), 'el valor nuevo');
        assertFalse(str_contains($texto, 'DB_NAME=viejo'), 'el valor viejo desaparece');
    }

    public function testAnadeLaClaveAusenteAlFinalSinTocarLaExistente(): void
    {
        file_put_contents($this->path, "DB_NAME=vanilla\n");

        (new EnvWriter($this->path))->set('DB_USER', 'root')->save();

        $lineas = file($this->path, FILE_IGNORE_NEW_LINES);
        assertEquals('DB_NAME=vanilla', $lineas[0]);
        assertEquals('DB_USER=root', $lineas[1]);
    }

    public function testEliminaDuplicadosPreexistentes(): void
    {
        file_put_contents($this->path, "DB_NAME=uno\nDB_USER=u\nDB_NAME=dos\n");

        (new EnvWriter($this->path))->set('DB_NAME', 'tres')->save();

        $texto = (string) file_get_contents($this->path);
        assertEquals(1, substr_count($texto, 'DB_NAME='));
        assertTrue(str_contains($texto, 'DB_NAME=tres'));
    }

    public function testLosValoresConEspaciosSeRecarganIgual(): void
    {
        $csp = "default-src 'self'; frame-ancestors 'none'";
        (new EnvWriter($this->path))->set('VFTEST_CSP_POLICY', $csp)->save();

        // El parser real debe devolver el mismo valor.
        $cargado = Env::load($this->path, true);

        assertSame($csp, $cargado['VFTEST_CSP_POLICY']);
    }

    public function testUnValorConHashNoSeCortaComoComentario(): void
    {
        $valor = 'clave con # almohadilla';
        (new EnvWriter($this->path))->set('VFTEST_PASSWORD', $valor)->save();

        $cargado = Env::load($this->path, true);
        assertSame($valor, $cargado['VFTEST_PASSWORD']);
    }

    public function testEscribeConPermisos0600(): void
    {
        (new EnvWriter($this->path))->set('APP_KEY', 'abc123')->save();

        $perms = fileperms($this->path) & 0777;
        assertSame(0600, $perms, 'el .env solo lo lee su dueño');
    }

    public function testNoDejaTemporalesTrasGuardar(): void
    {
        (new EnvWriter($this->path))->set('APP_ENV', 'production')->save();

        $restos = glob($this->dir . '/.env.tmp.*') ?: [];
        assertEquals(0, count($restos));
    }

    public function testSetManyPoneVariasClaves(): void
    {
        (new EnvWriter($this->path))
            ->setMany(['APP_ENV' => 'production', 'DB_NAME' => 'vf', 'DB_PASSWORD' => ''])
            ->save();

        $cargado = Env::load($this->path, true);
        assertSame('production', $cargado['APP_ENV']);
        assertSame('vf', $cargado['DB_NAME']);
        assertSame('', $cargado['DB_PASSWORD']);
    }
}
