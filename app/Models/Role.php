<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

class Role
{
    /**
     * Roles del SISTEMA: los que la app da por buenos y nadie borra.
     *
     * Vive en el código y no en una columna de `roles` a propósito. Una columna
     * `is_system` parecería más limpia, pero no se puede rellenar en el DDL: el
     * conjunto depende de lo que la aplicación sepa hacer (el `approver` existe
     * por la revisión de F3), y sembrarla desde la migración obligaría a una
     * migración nueva cada vez que se añadiera un rol, además de a un
     * `UPDATE` para los que ya estuvieran. Con la lista en el código, la
     * comparación es la fuente y no hay dos verdades que sincronizar.
     *
     * Por qué importa: los tres primeros son los que el resto del código
     * asume. Sin `admin` no hay panel; sin `user` no hay rol por defecto en el
     * alta de cuentas; sin `editor` el editor de F5.2 se queda sin su persona.
     * Y `approver` sin `forms.review` es un rol que no puede hacer nada, y
     * borrarlo deja la revisión de F3 sin destino.
     */
    public const SYSTEM_ROLES = ['admin', 'user', 'editor', 'approver'];

    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    /** ¿Es un rol del sistema (no se borra desde la UI)? */
    public static function isSystem(?string $name): bool
    {
        return $name !== null && in_array(mb_strtolower(trim($name)), self::SYSTEM_ROLES, true);
    }

    public static function all(): array
    {
        return self::db()->fetchAll('SELECT id, name, description FROM roles ORDER BY id');
    }

    /**
     * Roles con las claves de permiso que tienen asignadas (para la matriz
     * `/admin/roles`).
     *
     * @return array<int,array>
     */
    public static function allWithPermissions(): array
    {
        $roles = self::all();
        $rows = self::db()->fetchAll(
            'SELECT rp.role_id, p.`key`
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             ORDER BY p.`key`'
        );
        $byRole = [];
        foreach ($rows as $r) {
            $byRole[(int) $r['role_id']][] = $r['key'];
        }
        foreach ($roles as &$role) {
            $role['permissions'] = $byRole[(int) $role['id']] ?? [];
        }
        return $roles;
    }

    /** Claves de permiso de un rol concreto. */
    public static function permissionKeys(int $roleId): array
    {
        return array_column(
            self::db()->fetchAll(
                'SELECT p.`key`
                 FROM permissions p
                 JOIN role_permissions rp ON rp.permission_id = p.id
                 WHERE rp.role_id = :role
                 ORDER BY p.`key`',
                ['role' => $roleId]
            ),
            'key'
        );
    }

    /**
     * Alta de un rol nuevo.
     *
     * Nace **sin permisos**: la matriz de `/admin/roles` es lo que decide qué
     * puede hacer, y un rol con la lista ya puesta sería una puerta que el autor
     * no eligió. Quien lo cree tiene que marcar sus permisos explícitamente.
     *
     * El nombre se guarda en minúsculas porque es lo que espera el resto del
     * código (la matriz compara `admin` en minúsculas para conservar
     * `admin.access`, y así se pinta en la UI). Ojo: la UNICIDAD no la aporta
     * esto —`roles.name` es `utf8mb4_unicode_ci`, así que el índice único ya
     * rechaza «Admin» junto a «admin» por ser case-insensitive— sino el
     * `findByName()` previo, que devuelve el mismo nombre para ambas
     * grafías.
     *
     * @return int|null id del rol nuevo, o null si el nombre ya existe o es inválido
     */
    public static function create(string $name, string $description = ''): ?int
    {
        $name = mb_strtolower(trim($name));
        if ($name === '' || mb_strlen($name) > 50 || preg_match('/^[a-z0-9._-]+$/', $name) !== 1) {
            return null;
        }
        if (self::findByName($name) !== null) {
            return null;
        }

        self::db()->execute(
            'INSERT INTO roles (name, description) VALUES (:name, :desc)',
            ['name' => $name, 'desc' => trim($description)]
        );
        return (int) self::db()->lastInsertId();
    }

    /** Rol por id, o null. */
    public static function find(int $id): ?array
    {
        return self::db()->fetch('SELECT * FROM roles WHERE id = :id', ['id' => $id]);
    }

    /** Rol por nombre exacto, o null. */
    public static function findByName(string $name): ?array
    {
        return self::db()->fetch('SELECT * FROM roles WHERE name = :name', ['name' => $name]);
    }

    /**
     * Permisos efectivos de una persona: los del rol + los de los roles de los
     * grupos a los que pertenece (RBAC).
     *
     * Es la semilla de `Auth::can()`: una comprobación de un controlador solo
     * debe depender de lo que devuelva esto, nunca de `role_name` pintado.
     *
     * @param int   $roleId   rol propio del usuario
     * @param array $groupIds ids de los grupos a los que pertenece
     * @return array<string> claves de permiso
     */
    public static function effectivePermissionKeys(int $roleId, array $groupIds): array
    {
        $roleIds = [(int) $roleId];
        $groupIds = array_values(array_filter(array_map('intval', $groupIds), static fn($g) => $g > 0));

        if ($groupIds !== []) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            foreach (self::db()->fetchAll(
                "SELECT DISTINCT role_id FROM role_group_roles WHERE group_id IN ($placeholders)",
                $groupIds
            ) as $r) {
                $roleIds[] = (int) $r['role_id'];
            }
        }

        $roleIds = array_values(array_unique($roleIds));
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));

        return array_column(
            self::db()->fetchAll(
                "SELECT DISTINCT p.`key`
                 FROM permissions p
                 JOIN role_permissions rp ON rp.permission_id = p.id
                 WHERE rp.role_id IN ($placeholders)
                 ORDER BY p.`key`",
                $roleIds
            ),
            'key'
        );
    }

    /**
     * Cuántas personas llevan este rol.
     *
     * Es el dato que decide si un rol se puede borrar, y va en su propia
     * consulta (y no como un `COUNT` en `all()`) porque solo lo necesita la
     * pantalla de la matriz, que tiene una fila por rol.
     */
    public static function userCount(int $roleId): int
    {
        return (int) (self::db()->fetch(
            'SELECT COUNT(*) AS c FROM users WHERE role_id = :role',
            ['role' => $roleId]
        )['c'] ?? 0);
    }

    /**
     * Baja de un rol propio y sin usuarios.
     *
     * Las dos condiciones NO son la misma y por eso se comprueban por
     * separado, para poder decir cuál falló:
     *
     * - `users.role_id` es `ON DELETE RESTRICT`, así que la base ya impide
     *   borrar un rol con gente; aquí se comprueba antes para poder responder
     *   «tiene N personas» en vez de un error de integridad.
     * - Los roles del sistema no se borran aunque estén vacíos. Borrar `admin`
     *   dejaría la instalación sin panel, y borrar `approver` dejaría la
     *   revisión sin nadie que apruebe.
     *
     * Los `role_permissions` y `role_group_roles` se van por CASCADE, que es lo
     * correcto: un rol que ya no existe no puede tener permisos.
     *
     * @return bool `false` con el motivo en el segundo parámetro
     */
    public static function delete(int $roleId, ?string &$motivo = null): bool
    {
        $rol = self::find($roleId);
        if ($rol === null) {
            $motivo = 'El rol no existe.';
            return false;
        }
        if (self::isSystem((string) $rol['name'])) {
            $motivo = 'Es un rol del sistema y no se puede borrar.';
            return false;
        }
        $usuarios = self::userCount($roleId);
        if ($usuarios > 0) {
            $motivo = 'Hay ' . $usuarios . ($usuarios === 1 ? ' persona' : ' personas') . ' con ese rol.';
            return false;
        }

        self::db()->execute('DELETE FROM roles WHERE id = :id', ['id' => $roleId]);

        return true;
    }

    /**
     * Reemplaza la matriz de permisos de un rol por la lista dada.
     *
     * Si no hay roles definidos (copia vacía), se deja sin permisos: la matriz
     * es lo que decide qué puede hacer el rol. Excepción: `admin.access` no se
     * puede quitar del rol admin desde la UI de `/admin/roles` (se valida en el
     * controlador), para que el propio panel no sirva para auto-encarcelar al
     * único recurso que puede arreglar la matriz.
     */
    public static function setPermissions(int $roleId, array $keys): void
    {
        $keys = array_values(array_unique(array_map('strval', $keys)));
        $db = self::db();
        $db->beginTransaction();
        try {
            $db->execute('DELETE FROM role_permissions WHERE role_id = :role', ['role' => $roleId]);
            if ($keys !== []) {
                $ph = implode(',', array_fill(0, count($keys), '?'));
                $existing = array_column(
                    $db->fetchAll("SELECT id, `key` FROM permissions WHERE `key` IN ($ph)", $keys),
                    'id',
                    'key'
                );
                foreach ($existing as $key => $permId) {
                    $db->execute(
                        'INSERT INTO role_permissions (role_id, permission_id) VALUES (:role, :perm)',
                        ['role' => $roleId, 'perm' => (int) $permId]
                    );
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}