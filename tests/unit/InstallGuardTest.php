<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\InstallGuard;

/**
 * Guarda del instalador (AG11): marcador de «ya instalado», escritura del lock
 * y comprobación de requisitos.
 */
class InstallGuardTest
{
    private string $dir;
    private string $raiz;

    public function setUp(): void
    {
        $this->raiz = dirname(__DIR__, 2);
        $this->dir = sys_get_temp_dir() . '/vf_installguard_' . bin2hex(random_bytes(4));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('No se pudo crear el tmpdir de prueba');
        }
        InstallGuard::setLockPathForTesting($this->dir . '/installed.lock');
    }

    public function tearDown(): void
    {
        InstallGuard::setLockPathForTesting(null);
        InstallGuard::desmarcarParaTesting();
        @rmdir($this->dir);
    }

    public function testSinMarcadorNoEstaInstalado(): void
    {
        assertFalse(InstallGuard::estaInstalado());
    }

    public function testMarcarYCerrarElInstalador(): void
    {
        InstallGuard::marcarInstalado('admin@test.local');

        assertTrue(InstallGuard::estaInstalado(), 'el marcador existe tras marcar');
        assertTrue(is_file($this->dir . '/installed.lock'));

        $contenido = (string) file_get_contents($this->dir . '/installed.lock');
        assertTrue(str_contains($contenido, 'admin@test.local'), 'guarda el email del admin');
        assertFalse(str_contains($contenido, 'password'), 'nunca guarda la contraseña');
    }

    public function testElMarcadorSeEscribe0600(): void
    {
        InstallGuard::marcarInstalado('admin@test.local');

        $perms = fileperms($this->dir . '/installed.lock') & 0777;
        assertSame(0600, $perms);
    }

    public function testDesmarcarBorraElMarcador(): void
    {
        InstallGuard::marcarInstalado('admin@test.local');
        InstallGuard::desmarcarParaTesting();

        assertFalse(InstallGuard::estaInstalado());
    }

    public function testElInstaladorRealNoEstaMarcadoEnElRepo(): void
    {
        // Si el repo trajera el marcador, nadie podría instalar la app con este
        // fichero. (En una instalación real ya existirá; aquí comprobamos que no
        // lo versionamos por error.)
        assertFalse(is_file($this->raiz . '/storage/installed.lock'), 'el repo no trae installed.lock');
    }

    public function testRequisitosSobreLaRaizRealNoDanErrores(): void
    {
        $problemas = InstallGuard::revisarRequisitos($this->raiz);

        assertFalse(InstallGuard::hayErrores($problemas), 'este entorno cumple los requisitos');
    }

    public function testRequisitosDetectanUnDirectorioQueFalta(): void
    {
        $problemas = InstallGuard::revisarRequisitos('/ruta/que/no/existe');

        assertTrue(InstallGuard::hayErrores($problemas), 'una raíz inexistente da errores');
    }
}
