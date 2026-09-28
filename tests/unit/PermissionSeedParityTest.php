<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * RBAC — paridad de la semilla de permisos (2026-09-28).
 *
 * La lista de permisos vive en `config/permissions.php` (fuente de verdad) y se
 * SIEMBRA en DOS copias: la migración 0005 (bases existentes, que SIEMPRE la
 * lee en runtime) y `database/seed.sql` (instalaciones limpias). Si diverge la
 * copia de seed.sql, una base nueva acabaría con una matriz distinta a la de
 * una base migrada.
 *
 * Este test NO toca la BD: lee el archivo de seed y el catálogo y compara
 * claves y roles por defecto. La migración 0005 se cubre sola porque lee
 * `config/permissions.php` en runtime; la copia estática de seed.sql es la que
 * necesita un test de pinza.
 */
class PermissionSeedParityTest
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string,array{desc:string,roles:array<int,string>}> */
    private static function catalog(): array
    {
        $catalog = require self::root() . '/config/permissions.php';
        $byKey = [];
        foreach ($catalog as $key => $v) {
            $byKey[(string) $key] = [
                'desc'  => (string) $v[0],
                'roles' => array_values(array_map('strval', (array) $v[1])),
            ];
        }
        return $byKey;
    }

    private static function seedSql(): string
    {
        $sql = (string) file_get_contents(self::root() . '/database/seed.sql');
        if ($sql === '') {
            fail('no se pudo leer database/seed.sql');
        }
        return $sql;
    }

    /** Claves de permiso que seed.sql inserta. */
    private static function seedKeys(): array
    {
        $sql = self::seedSql();
        if (!preg_match('/INSERT INTO permissions \(`key`, description\) VALUES\s*(.*?)\s*ON DUPLICATE/s', $sql, $m)) {
            fail('no se encontró el bloque INSERT INTO permissions en seed.sql');
        }
        preg_match_all("/'([a-z0-9_.]+)'/", $m[1], $mk);
        $keys = array_values(array_unique($mk[1]));
        sort($keys);
        return $keys;
    }

    /** Claves que seed.sql otorga a un rol concreto (su lista IN ...). */
    private static function seedRoleKeys(string $role): array
    {
        $sql = self::seedSql();
        if (!preg_match('/r\.name = \'' . $role . '\'\s+AND\s+p\.`key`\s+IN\s*\(([^)]*)\)/', $sql, $m)) {
            fail("no se encontró la asignación del rol '{$role}' en seed.sql");
        }
        preg_match_all("/'([a-z0-9_.]+)'/", $m[1], $mk);
        $keys = array_values(array_unique($mk[1]));
        sort($keys);
        return $keys;
    }

    public function testElSeedSqlTieneLasMismasClavesQueElCatalogo(): void
    {
        $catalogKeys = array_keys(self::catalog());
        sort($catalogKeys);
        assertSame(
            $catalogKeys,
            self::seedKeys(),
            'las claves de seed.sql deben ser EXACTAMENTE las de config/permissions.php'
        );
    }

    public function testLosRolesPorDefectoDeUserCoinciden(): void
    {
        $catalogUser = array_keys(array_filter(self::catalog(), static fn($c) => in_array('user', $c['roles'], true)));
        sort($catalogUser);
        assertSame(
            $catalogUser,
            self::seedRoleKeys('user'),
            'user debe recibir por defecto exactamente lo que declara el catálogo (crear/editar/eliminar/exportar/formularios propios y leer su analítica, respuestas y adjuntos)'
        );
    }

    public function testLosRolesPorDefectoDeEditorCoinciden(): void
    {
        $catalogEditor = array_keys(array_filter(self::catalog(), static fn($c) => in_array('editor', $c['roles'], true)));
        sort($catalogEditor);
        assertSame(
            $catalogEditor,
            self::seedRoleKeys('editor'),
            'editor debe recibir por defecto exactamente lo que declara el catálogo (igual que user pero sin forms.delete_own)'
        );
    }

    public function testAdminRecibeTodasLasClaves(): void
    {
        $sql = self::seedSql();
        assertTrue(
            str_contains($sql, "r.name = 'admin'"),
            "seed.sql debe otorgar a admin TODAS las claves (rama OR r.name = 'admin')"
        );
        $catalogKeys = array_keys(self::catalog());
        sort($catalogKeys);
        assertSame(
            $catalogKeys,
            self::seedKeys(),
            'admin cubre el catálogo completo; si el catálogo cambia esta igualdad tiene que romperse'
        );

        $adminAppearsInEveryEntry = array_filter(
            self::catalog(),
            static fn($c) => !in_array('admin', $c['roles'], true)
        );
        assertSame([], array_keys($adminAppearsInEveryEntry), 'todo permiso del catálogo debe declarar admin entre sus roles por defecto');
    }
}