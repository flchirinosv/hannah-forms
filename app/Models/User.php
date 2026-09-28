<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

class User
{
    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    public static function findByEmail(string $email): ?array
    {
        return self::db()->fetch(
            'SELECT id, role_id, name, email, password_hash, is_active FROM users WHERE email = :email LIMIT 1',
            ['email' => $email]
        );
    }

    public static function find(int $id): ?array
    {
        return self::db()->fetch(
            'SELECT id, role_id, name, email, password_hash, is_active FROM users WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public static function findWithRole(int $id): ?array
    {
        return self::db()->fetch(
            'SELECT u.id, u.role_id, u.name, u.email, u.is_active, r.name AS role_name
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public static function create(
        string $name,
        string $email,
        string $password,
        int $roleId = 2
    ): ?int {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        self::db()->execute(
            'INSERT INTO users (role_id, name, email, password_hash, is_active)
             VALUES (:role_id, :name, :email, :password_hash, 1)',
            [
                'role_id'       => $roleId,
                'name'          => $name,
                'email'         => $email,
                'password_hash' => $hash,
            ]
        );

        return self::db()->lastInsertId();
    }

    public static function all(): array
    {
        return self::db()->fetchAll(
            'SELECT u.id, u.name, u.email, u.role_id, u.is_active, u.created_at, r.name AS role_name
             FROM users u
             JOIN roles r ON r.id = u.role_id
             ORDER BY u.id'
        );
    }

    public static function updateRole(int $id, int $roleId): void
    {
        self::db()->execute('UPDATE users SET role_id = :role_id WHERE id = :id', ['role_id' => $roleId, 'id' => $id]);
    }

    /**
     * Reemplaza el hash de la contraseña (rehash oportunista en el login).
     *
     * Recibe la contraseña EN CLARO y hashea aquí: el hash solo debe existir
     * dentro de este modelo, nunca guardarse ni pasar por la sesión.
     */
    public static function updatePasswordHash(int $id, string $plainPassword): void
    {
        self::db()->execute(
            'UPDATE users SET password_hash = :hash WHERE id = :id',
            ['hash' => password_hash($plainPassword, PASSWORD_DEFAULT), 'id' => $id]
        );
    }

    public static function toggleActive(int $id): void
    {
        self::db()->execute('UPDATE users SET is_active = 1 - is_active WHERE id = :id', ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        self::db()->execute('DELETE FROM users WHERE id = :id', ['id' => $id]);
    }
}