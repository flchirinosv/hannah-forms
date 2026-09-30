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
        // F2: `password_changed_at` va aquí porque `Auth::login()` lo necesita
        // para fechar su propia sesión. Falta, el `auth_at` se queda en
        // `time()` a secas y un login hecho en el MISMO segundo que un cambio
        // de contraseña se cierra a sí mismo (el usuario entra y ve que le
        // echan). No es que sobre: se lee con `?? null` en todas partes.
        return self::db()->fetch(
            'SELECT id, role_id, name, email, password_hash, is_active, password_changed_at FROM users WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public static function findWithRole(int $id): ?array
    {
        return self::db()->fetch(
            'SELECT u.id, u.role_id, u.name, u.email, u.is_active, u.password_changed_at, r.name AS role_name
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

    /**
     * F3 — Usuarios con el permiso `forms.review`, para el aviso de revisión.
     *
     * Reproduce la regla de permisos efectivos de `Auth` (rol propio + roles de
     * los grupos) en UNA consulta con dos `EXISTS`, y no con un bucle que llame
     * a `Role::effectivePermissionKeys()` por usuario: con 200 usuarios eso son
     * 600 consultas en cada envío de aviso, y el aviso se dispara en el camino
     * de un POST normal del dueño.
     *
     * Filtra `is_active = 1` porque avisar a una cuenta desactivada no sirve de
     * nada y deja la dirección en manos de alguien que ya no entra.
     *
     * @return array<int,array{id:int,name:string,email:string}>
     */
    public static function reviewers(): array
    {
        return self::db()->fetchAll(
            "SELECT u.id, u.name, u.email
             FROM users u
             WHERE u.is_active = 1
               AND (
                   EXISTS (
                       SELECT 1
                       FROM role_permissions rp
                       JOIN permissions p ON p.id = rp.permission_id
                       WHERE rp.role_id = u.role_id AND p.`key` = 'forms.review'
                   )
                   OR EXISTS (
                       SELECT 1
                       FROM role_group_users rgu
                       JOIN role_group_roles rgr ON rgr.group_id = rgu.group_id
                       JOIN role_permissions rp ON rp.role_id = rgr.role_id
                       JOIN permissions p ON p.id = rp.permission_id
                       WHERE rgu.user_id = u.id AND p.`key` = 'forms.review'
                   )
               )
             ORDER BY u.id"
        );
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

    /**
     * F2: cambia la contraseña y anota CUÁNDO, en una sola sentencia.
     *
     * Las dos columnas van juntas a propósito: si el hash se guardara y el
     * `password_changed_at` fallara, las sesiones antiguas seguirían vivas
     * (que es justo lo que este método existe para cerrar) sin que nada lo
     * dijera. El rehash oportunista del login (`updatePasswordHash`) NO toca
     * `password_changed_at`: rehashear con el mismo secreto no es cambiar la
     * contraseña, y si lo hiciera cerraría la sesión del usuario que acaba de
     * entrar.
     *
     * Devuelve el `password_changed_at` que quedó escrito (string UTC o null si
     * el usuario no existía) para que quien cambia SU PROPIA contraseña pueda
     * refrescar su sesión con el dato de la base y no con el reloj de PHP: si
     * los dos relojes no coincidieran al segundo, se expulsaría del panel a sí
     * mismo en la página siguiente.
     */
    public static function updatePassword(int $id, string $plainPassword): ?string
    {
        self::db()->execute(
            'UPDATE users SET password_hash = :hash, password_changed_at = UTC_TIMESTAMP() WHERE id = :id',
            ['hash' => password_hash($plainPassword, PASSWORD_DEFAULT), 'id' => $id]
        );

        $row = self::db()->fetch('SELECT password_changed_at FROM users WHERE id = :id', ['id' => $id]);
        $value = $row['password_changed_at'] ?? null;

        return $value === null ? null : (string) $value;
    }

    public static function toggleActive(int $id): void
    {
        self::db()->execute('UPDATE users SET is_active = 1 - is_active WHERE id = :id', ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        self::db()->execute('DELETE FROM users WHERE id = :id', ['id' => $id]);
    }

    /**
     * Ids de los grupos de roles a los que pertenece el usuario (RBAC).
     *
     * @return array<int>
     */
    public static function groupIds(int $id): array
    {
        return array_map(
            'intval',
            array_column(
                self::db()->fetchAll(
                    'SELECT group_id FROM role_group_users WHERE user_id = :u ORDER BY group_id',
                    ['u' => $id]
                ),
                'group_id'
            )
        );
    }

    /**
     * Grupos de un usuario (con nombre), para la columna de la UI de admin.
     *
     * @return array<int,array>
     */
    public static function groups(int $id): array
    {
        return self::db()->fetchAll(
            'SELECT g.id, g.name FROM role_groups g
             JOIN role_group_users u ON u.group_id = g.id
             WHERE u.user_id = :u
             ORDER BY g.name',
            ['u' => $id]
        );
    }
}