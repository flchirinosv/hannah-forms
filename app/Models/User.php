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
     * los grupos + permisos directos) en UNA consulta con tres `EXISTS`, y no
     * con un bucle que llame a `Role::effectivePermissionKeys()` por usuario: con
     * 200 usuarios eso son 600 consultas en cada envío de aviso, y el aviso se
     * dispara en el camino de un POST normal del dueño.
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
                   OR EXISTS (
                       SELECT 1
                       FROM user_permissions up
                       JOIN permissions p ON p.id = up.permission_id
                       WHERE up.user_id = u.id AND p.`key` = 'forms.review'
                   )
               )
             ORDER BY u.id"
        );
    }

    /**
     * ¿Tiene un usuario concreto este permiso efectivo (rol propio + roles de
     * sus grupos + permisos directos)?
     *
     * Es la regla de `Auth::can()` pero para OTRO usuario: la exención de la
     * revisión de la instancia se decide sobre el DUEÑO del formulario, no
     * sobre quien actúa. Una sola consulta con tres `EXISTS` —igual que
     * `reviewers()`— para que `ReviewPolicy::requiredFor()` no haga tres viajes
     * por cada formulario del listado.
     *
     * Los tres marcadores llevan el mismo valor porque `Database` va con
     * `ATTR_EMULATE_PREPARES = false` y repetir `:k` lanzaría HY093.
     */
    public static function hasPermission(int $id, string $permissionKey): bool
    {
        $row = self::db()->fetch(
            "SELECT 1
             FROM users u
             WHERE u.id = :id
               AND (
                   EXISTS (
                       SELECT 1
                       FROM role_permissions rp
                       JOIN permissions p ON p.id = rp.permission_id
                       WHERE rp.role_id = u.role_id AND p.`key` = :k
                   )
                   OR EXISTS (
                       SELECT 1
                       FROM role_group_users rgu
                       JOIN role_group_roles rgr ON rgr.group_id = rgu.group_id
                       JOIN role_permissions rp ON rp.role_id = rgr.role_id
                       JOIN permissions p ON p.id = rp.permission_id
                       WHERE rgu.user_id = u.id AND p.`key` = :k2
                   )
                   OR EXISTS (
                       SELECT 1
                       FROM user_permissions up
                       JOIN permissions p ON p.id = up.permission_id
                       WHERE up.user_id = u.id AND p.`key` = :k3
                   )
               )",
            ['id' => $id, 'k' => $permissionKey, 'k2' => $permissionKey, 'k3' => $permissionKey]
        );

        return $row !== null;
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

    /**
     * Deja al usuario en el estado pedido. Devuelve si hubo cambio.
     *
     * Es «estado objetivo» y no «invertir» porque es lo que necesita un botón
     * que se manda por fetch: dos clics sobre «Desactivar» (o un reintento
     * después de un tiempo de espera) con un POST de «al revés» se activan y
     * desactivan la misma cuenta y el panel dice lo contrario de lo que pasó.
     * Con `1 - is_active` no hay forma de decir «déjalo como está»; aquí sí, y
     * por eso el `WHERE is_active <> :igual` (donde `:igual` es el ESTADO
     * PEDIDO, no el contrario) devuelve 0 filas cuando ya estaba en ese estado:
     * eso es lo que hace idempotente el botón.
     *
     * @return bool `true` si la fila cambió
     */
    public static function setActive(int $id, bool $active): bool
    {
        // Dos marcadores con el MISMO valor y no uno repetido: `Database` va
        // con ATTR_EMULATE_PREPARES = false, donde reutilizar `:nuevo` dos
        // veces lanza HY093. Y `execute()` devuelve YA el rowCount (no un
        // PDOStatement).
        $valor = $active ? 1 : 0;

        return self::db()->execute(
            'UPDATE users SET is_active = :nuevo WHERE id = :id AND is_active <> :igual',
            ['nuevo' => $valor, 'id' => $id, 'igual' => $valor]
        ) > 0;
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
     * Pertenencia de TODOS los usuarios en una consulta: user_id => grupos.
     *
     * Antes la columna «Grupos» de la vista de usuarios pintaba las badges
     * llamando a un `groups()` por fila, o sea un viaje a la BD por cada
     * usuario de la instalación (con 50 usuarios, 50 consultas para pintar una
     * tabla). No hay un `groups()` de un solo usuario porque quien lo necesita
     * es la tabla entera, y lo que se necesita al guardar es el inverso:
     * `setGroups()`.
     *
     * @return array<int, list<array{id:int, name:string}>>
     */
    public static function groupsMap(): array
    {
        $rows = self::db()->fetchAll(
            'SELECT u.user_id, g.id, g.name
             FROM role_group_users u
             JOIN role_groups g ON g.id = u.group_id
             ORDER BY g.name'
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['user_id']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }
        return $map;
    }

    /**
     * Deja a un usuario en exactamente estos grupos (los vacíos se quitan).
     *
     * Es el inverso de `RoleGroup::setUsers()`: ese reescribe la pertenencia de
     * un grupo entero, este la de una persona. Hace falta porque el panel
     * gestiona los grupos desde la fila del usuario, y `role_group_users` es una
     * N:M — si el checkbox no viene marcado es que se quita, no que "no se
     * ha tocado".
     */
    public static function setGroups(int $userId, array $groupIds): void
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        $db = self::db();
        $db->beginTransaction();
        try {
            $db->execute('DELETE FROM role_group_users WHERE user_id = :u', ['u' => $userId]);
            foreach ($groupIds as $groupId) {
                if ($groupId > 0) {
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

    /**
     * Permisos DIRECTOS de un usuario (RBAC, cuarta fuente). Vacío si no tiene.
     *
     * @return list<string>
     */
    public static function permissionKeys(int $userId): array
    {
        return array_column(
            self::db()->fetchAll(
                'SELECT p.`key`
                 FROM permissions p
                 JOIN user_permissions up ON up.permission_id = p.id
                 WHERE up.user_id = :u
                 ORDER BY p.`key`',
                ['u' => $userId]
            ),
            'key'
        );
    }

    /**
     * Reemplaza los permisos DIRECTOS de un usuario por la lista dada.
     *
     * Es la lista COMPLETA (N:M), como `setGroups()`: una clave que no venga es
     * que se quita, no que «no se ha tocado». Lista vacía = sin permisos
     * directos. Filtra contra el catálogo (`permissions`) igual que
     * `Role::setPermissions()`, así que una clave inventada se ignora sola.
     */
    public static function setPermissions(int $userId, array $keys): void
    {
        $keys = array_values(array_unique(array_map('strval', $keys)));
        $db = self::db();
        $db->beginTransaction();
        try {
            $db->execute('DELETE FROM user_permissions WHERE user_id = :u', ['u' => $userId]);
            if ($keys !== []) {
                $ph = implode(',', array_fill(0, count($keys), '?'));
                $existing = array_column(
                    $db->fetchAll("SELECT id, `key` FROM permissions WHERE `key` IN ($ph)", $keys),
                    'id',
                    'key'
                );
                foreach ($existing as $key => $permId) {
                    $db->execute(
                        'INSERT INTO user_permissions (user_id, permission_id) VALUES (:u, :p)',
                        ['u' => $userId, 'p' => (int) $permId]
                    );
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Permisos DIRECTOS de TODOS los usuarios: user_id => [claves].
     *
     * En una sola consulta, como `groupsMap()`: la tabla de `/admin` pinta la
     * columna de permisos de cada fila, y un `permissionKeys()` por fila sería
     * un viaje a la base por usuario.
     *
     * @return array<int, list<string>>
     */
    public static function permissionsMap(): array
    {
        $rows = self::db()->fetchAll(
            'SELECT up.user_id, p.`key`
             FROM user_permissions up
             JOIN permissions p ON p.id = up.permission_id
             ORDER BY p.`key`'
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['user_id']][] = (string) $row['key'];
        }
        return $map;
    }

    /**
     * Permisos EFECTIVOS por usuario, desglosados por ORIGEN.
     *
     * Devuelve, por id de usuario:
     *   ['rol' => [...], 'grupos' => [...], 'extra' => [...], 'total' => [...]]
     *
     * `total` es la unión de los tres y es exactamente lo que evalúa
     * `Auth::can()`; el desglose es lo que hace auditable una concesión directa
     * (de dónde le viene cada permiso a esta persona). Se calcula en memoria a
     * partir de unos pocos mapas, no con una consulta por usuario, porque lo
     * consume la tabla de `/admin`, que tiene una fila por persona.
     *
     * @return array<int, array{rol:list<string>, grupos:list<string>, extra:list<string>, total:list<string>}>
     */
    public static function effectivePermissionsMap(): array
    {
        $db = self::db();

        // role_id => claves de sus permisos.
        $rolePerms = [];
        foreach ($db->fetchAll(
            'SELECT rp.role_id, p.`key`
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             ORDER BY p.`key`'
        ) as $r) {
            $rolePerms[(int) $r['role_id']][] = (string) $r['key'];
        }

        // group_id => role_ids.
        $groupRoles = [];
        foreach ($db->fetchAll('SELECT group_id, role_id FROM role_group_roles') as $r) {
            $groupRoles[(int) $r['group_id']][] = (int) $r['role_id'];
        }

        // user_id => group_ids.
        $userGroups = [];
        foreach ($db->fetchAll('SELECT user_id, group_id FROM role_group_users') as $r) {
            $userGroups[(int) $r['user_id']][] = (int) $r['group_id'];
        }

        $direct = self::permissionsMap();

        $out = [];
        foreach ($db->fetchAll('SELECT id, role_id FROM users') as $u) {
            $uid = (int) $u['id'];
            $rol = $rolePerms[(int) $u['role_id']] ?? [];

            $grupos = [];
            foreach ($userGroups[$uid] ?? [] as $gid) {
                foreach ($groupRoles[$gid] ?? [] as $rid) {
                    foreach ($rolePerms[$rid] ?? [] as $k) {
                        $grupos[$k] = true;
                    }
                }
            }
            $grupos = array_keys($grupos);
            sort($grupos);

            $extra = $direct[$uid] ?? [];
            $total = array_values(array_unique(array_merge($rol, $grupos, $extra)));
            sort($total);

            $out[$uid] = [
                'rol'    => array_values($rol),
                'grupos' => $grupos,
                'extra'  => array_values($extra),
                'total'  => $total,
            ];
        }

        return $out;
    }
}