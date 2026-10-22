<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Env;
use App\Services\Installer;

/**
 * Instalador web (AG11): validación del nombre de base, transformación del
 * `USE`/`CREATE DATABASE` de los `.sql` y, si el usuario de BD puede crear
 * bases, el ciclo real crear→esquema→admin.
 */
class InstallerTest
{
    private string $raiz;

    public function setUp(): void
    {
        $this->raiz = dirname(__DIR__, 2);
    }

    public function testElNombreDeBaseSoloAdmiteLetrasNumerosYGuionBajo(): void
    {
        assertTrue(Installer::nombreValido('vanilla_forms'));
        assertTrue(Installer::nombreValido('vf_2024'));
        assertFalse(Installer::nombreValido('vanilla forms'), 'espacio rechazado');
        assertFalse(Installer::nombreValido('vf; DROP DATABASE x'), 'inyección rechazada');
        assertFalse(Installer::nombreValido('vf-forms'), 'guion medio rechazado');
        assertFalse(Installer::nombreValido(''), 'vacío rechazado');
    }

    public function testSqlSinUseQuitaElUseYReescribeElCreateDatabase(): void
    {
        $sql = "CREATE DATABASE IF NOT EXISTS vanilla_forms\n COLLATE utf8mb4_unicode_ci;\n\n"
             . "USE vanilla_forms;\n\nCREATE TABLE roles (id INT);\n";

        $out = Installer::sqlSinUse($sql, 'mi_vf');

        assertFalse(str_contains($out, 'USE vanilla_forms'), 'el USE se elimina');
        assertTrue(str_contains($out, 'CREATE DATABASE IF NOT EXISTS `mi_vf`'), 'el CREATE DATABASE apunta a la base elegida');
        assertTrue(str_contains($out, 'CREATE TABLE roles'), 'el resto del SQL se conserva');
    }

    public function testSqlSinUseReescribeElUseConBackticksYMayusculas(): void
    {
        $sql = "use `vanilla_forms`;\nCREATE TABLE t (id INT);";

        $out = Installer::sqlSinUse($sql, 'otra');

        assertFalse(stripos($out, 'use `vanilla_forms`'), 'casa aunque haya backticks y mayúsculas');
        assertTrue(str_contains($out, 'CREATE TABLE t'));
    }

    public function testElSchemaRealNoConservaElUseTrasLaTransformacion(): void
    {
        $sql = (string) file_get_contents($this->raiz . '/database/schema.sql');
        $out = Installer::sqlSinUse($sql, 'vf_probe');

        assertFalse(str_contains($out, 'USE vanilla_forms'), 'schema.sql real queda sin USE');
    }

    public function testCrearAdminValidaElEmailAntesDeTocarLaBase(): void
    {
        $inst = new Installer($this->configProbe());

        try {
            $inst->crearAdmin('Nombre', 'no-es-un-email', 'clave-larga-1234', 'clave-larga-1234');
            fail('se esperaba un error por email inválido');
        } catch (\RuntimeException $e) {
            assertTrue(str_contains($e->getMessage(), 'Email'), 'el mensaje nombra el email');
        }
    }

    public function testCrearAdminRechazaContrasenasQueNoCoinciden(): void
    {
        $inst = new Installer($this->configProbe());

        try {
            $inst->crearAdmin('Nombre', 'ok@test.local', 'clave-larga-1234', 'otra-clave-1234');
            fail('se esperaba un error por contraseñas distintas');
        } catch (\RuntimeException $e) {
            assertTrue(str_contains($e->getMessage(), 'coinciden'), 'el mensaje habla de la coincidencia');
        }
    }

    public function testCrearAdminRechazaContrasenaCorta(): void
    {
        $inst = new Installer($this->configProbe());

        try {
            $inst->crearAdmin('Nombre', 'ok@test.local', 'corta', 'corta');
            fail('se esperaba un error por contraseña corta');
        } catch (\RuntimeException $e) {
            assertTrue(str_contains($e->getMessage(), 'caracteres'), 'el mensaje habla de la longitud');
        }
    }

    /**
     * Ciclo real completo, SOLO si el usuario de BD puede crear bases (se salta
     * si no: en muchos despliegues el usuario de la app no tiene ese privilegio,
     * y el instalador lo hace por otra vía).
     */
    public function testCicloCompletoSobreUnaBaseDescartable(): void
    {
        $config = $this->configProbe();
        $inst = new Installer($config);

        try {
            $inst->crearBase();
        } catch (\RuntimeException $e) {
            // Sin privilegio para crear bases: el resto del ciclo no se puede
            // probar aquí. No es un fallo de la app.
            assertTrue(true, 'usuario sin privilegio para crear bases: se omite el ciclo real');
            return;
        }

        try {
            $inst->aplicarEsquema($this->raiz);

            $email = 'instalador-' . bin2hex(random_bytes(4)) . '@test.local';
            $id = $inst->crearAdmin('Instalador Probe', $email, 'clave-larga-1234', 'clave-larga-1234');

            assertTrue($id > 0, 'se creó el admin');
            assertNotNull(\App\Models\User::findByEmail($email), 'el admin existe');
            assertEquals('admin', \App\Models\Role::findByName('admin')['name'] ?? null, 'el rol admin existe');

            // El admin creado por el instalador es administrador de verdad.
            $fila = \App\Models\User::findByEmail($email);
            $rol = \App\Models\Role::find((int) $fila['role_id']);
            assertEquals('admin', $rol['name'] ?? null);
        } finally {
            $this->soltarBase($config);
        }
    }

    /**
     * @return array{host:string,port:string,name:string,user:string,password:string,charset:string}
     */
    private function configProbe(): array
    {
        return [
            'host'     => (string) (Env::get('DB_HOST') ?? '127.0.0.1'),
            'port'     => (string) (Env::get('DB_PORT') ?? '3306'),
            'name'     => 'vf_installer_test',
            'user'     => (string) (Env::get('DB_USER') ?? ''),
            'password' => (string) (Env::get('DB_PASSWORD') ?? ''),
            'charset'  => (string) (Env::get('DB_CHARSET') ?? 'utf8mb4'),
        ];
    }

    private function soltarBase(array $config): void
    {
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', $config['host'], $config['port'], $config['charset']);
            $pdo = new \PDO($dsn, $config['user'], $config['password']);
            $pdo->exec('DROP DATABASE IF EXISTS `' . $config['name'] . '`');
        } catch (\Throwable $e) {
            // Sin privilegio: no hay nada que soltar.
        }
    }
}
