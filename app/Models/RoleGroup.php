<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

/**
 * Grupos de roles (RBAC, 2026-09-28).
 *
 * Un grupo es un contenedor de ROLES y de USUARIOS: los usuarios de un grupo
 * heredan los permisos de los roles del grupo además de los de su propio rol
 * (`Auth::permissions()`). Es la vía para dar a un equipo no-admin una
 * capacidad ampliada (p. ej. `forms.manage_all` para operar formularios de
 * cualquiera) sin cambiarle el rol a nadie.
 */
class RoleGroup
{
    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    /**
     * Todos los grupos con el nº de miembros, rol(es) y descripción.
     *
     * Orden por nombre para que la UI sea estable.
     *
     * @return array<int,array>
     */
    public static function all(): array
    {
        return self::db()->fetchAll(
            'SELECT g.*,
                    (SELECT COUNT(*) FROM role_group_users u WHERE u.group_id = g.id) AS members_count,
                    (SELECT COUNT(*) FROM role_group_roles r WHERE r.group_id = g.id) AS roles_count
             FROM role_groups g
             ORDER BY g.name'
        );
    }

    public static function find(int $id): ?array
    {
        return self::db()->fetch('SELECT * FROM role_groups WHERE id = :id', ['id' => $id]);
    }

    public static function create(string $name, string $description = ''): int
    {
        self::db()->execute(
            'INSERT INTO role_groups (name, description) VALUES (:name, :desc)',
            ['name' => trim($name), 'desc' => trim($description)]
        );
        return (int) self::db()->lastInsertId();
    }

    public static function update(int $id, string $name, string $description = ''): void
    {
        self::db()->execute(
            'UPDATE role_groups SET name = :name, description = :desc WHERE id = :id',
            ['id' => $id, 'name' => trim($name), 'desc' => trim($description)]
        );
    }

    public static function delete(int $id): void
    {
        // Los vínculos (role_group_users / role_group_roles) se van por CASCADE.
        self::db()->execute('DELETE FROM role_groups WHERE id = :id', ['id' => $id]);
    }

    /** Ids de los roles que contiene un grupo. */
    public static function roleIds(int $groupId): array
    {
        return array_map(
            'intval',
            array_column(
                self::db()->fetchAll(
                    'SELECT role_id FROM role_group_roles WHERE group_id = :g',
                    ['g' => $groupId]
                ),
                'role_id'
            )
        );
    }

    public static function setRoles(int $groupId, array $roleIds): void
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        $db = self::db();
        $db->beginTransaction();
        try {
            $db->execute('DELETE FROM role_group_roles WHERE group_id = :g', ['g' => $groupId]);
            foreach ($roleIds as $roleId) {
                if ($roleId > 0) {
                    $db->execute(
                        'INSERT INTO role_group_roles (group_id, role_id) VALUES (:g, :r)',
                        ['g' => $groupId, 'r' => $roleId]
                    );
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Ids de usuarios de un grupo. */
    public static function userIds(int $groupId): array
    {
        return array_map(
            'intval',
            array_column(
                self::db()->fetchAll(
                    'SELECT user_id FROM role_group_users WHERE group_id = :g ORDER BY user_id',
                    ['g' => $groupId]
                ),
                'user_id'
            )
        );
    }

    public static function setUsers(int $groupId, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $db = self::db();
        $db->beginTransaction();
        try {
            $db->execute('DELETE FROM role_group_users WHERE group_id = :g', ['g' => $groupId]);
            foreach ($userIds as $userId) {
                if ($userId > 0) {
                    $db->execute(
                        'INSERT INTO role_group_users (group_id, user_id) VALUES (:g, :u)',
                        ['g' => $groupId, 'u' => $userId]
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