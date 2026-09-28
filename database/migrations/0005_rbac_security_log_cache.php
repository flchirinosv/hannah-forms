<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0005 — RBAC (permisos, roles, grupos de roles) + log de seguridad
 * + caché del dashboard (fase 6, 2026-09-28).
 *
 * Crea las tablas de RBAC:
 *
 *  - `permissions`: catálogo de permisos a nivel de acción (`forms.create`,
 *    `admin.users`, ...). La semántica de cadа clave vive en
 *    `config/permissions.php`; aquí y en `database/seed.sql` se SIEMBRA esa
 *    lista en dos copias (los doss sitios tienen que mantenerse en sync; lo
 *    verifica `PermissionSeedParityTest`).
 *  - `role_permissions`: qué permisos tiene cada rol. Los roles ya existían
 *    (admin/user/editor); con esto su significado pasa de "nombre mágico
 *    comprobado a pelo" a "conjunto de permisos".
 *  - `role_groups` / `role_group_roles` / `role_group_users`: un grupo de
 *    roles agrupa roles y usuarios. Un usuario hereda los permisos de SU rol
 *    más los permisos de los roles de los grupos a los que pertenece
 *    (`Auth::permissions()`). Es la vía para dar a un equipo no-admin una
 *    capacidad ampliada (p. ej. `forms.manage_all`) sin tocar su rol.
 *
 * Tablas nuevas de operación:
 *
 *  - `security_log`: registro de eventos de seguridad (login, CSRF, rate limit,
 *    SSRF de webhooks, subidas rechazadas, acciones admin) consultable desde
 *    `/admin/security-log`, con índice de purga por antigüedad.
 *  - `cache`: caché de consultas frecuentes (dashboard) con TTL, invalidable
 *    por clave o por patrón LIKE.
 *
 * Convención del proyecto: migración idempotente (todo IF NOT EXISTS) y SIN
 * transacción (el DDL hace commit implícito en MariaDB). El seed de permisos
 * también es idempotente (INSERT IGNORE).
 */

return static function (Database $db): void {
    $db->query(
        'CREATE TABLE IF NOT EXISTS permissions (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `key`       VARCHAR(80) NOT NULL UNIQUE,
            description VARCHAR(255) DEFAULT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    $db->query(
        'CREATE TABLE IF NOT EXISTS role_permissions (
            role_id       INT UNSIGNED NOT NULL,
            permission_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (role_id, permission_id),
            CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    $db->query(
        'CREATE TABLE IF NOT EXISTS role_groups (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(80) NOT NULL UNIQUE,
            description VARCHAR(255) DEFAULT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    $db->query(
        'CREATE TABLE IF NOT EXISTS role_group_roles (
            group_id INT UNSIGNED NOT NULL,
            role_id  INT UNSIGNED NOT NULL,
            PRIMARY KEY (group_id, role_id),
            CONSTRAINT fk_rgr_group FOREIGN KEY (group_id) REFERENCES role_groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_rgr_role  FOREIGN KEY (role_id)  REFERENCES roles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    $db->query(
        'CREATE TABLE IF NOT EXISTS role_group_users (
            group_id INT UNSIGNED NOT NULL,
            user_id  INT UNSIGNED NOT NULL,
            PRIMARY KEY (group_id, user_id),
            CONSTRAINT fk_rgu_group FOREIGN KEY (group_id) REFERENCES role_groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_rgu_user  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    $db->query(
        'CREATE TABLE IF NOT EXISTS security_log (
            id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event      VARCHAR(60) NOT NULL,
            detail     VARCHAR(500) DEFAULT NULL,
            ip         VARCHAR(45) DEFAULT NULL,
            user_id    INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_sl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB'
    );
    // Índice de purga (DELETE por antigüedad) y de consulta filtrada.
    $db->query('CREATE INDEX IF NOT EXISTS idx_security_log_created ON security_log (created_at)');
    $db->query('CREATE INDEX IF NOT EXISTS idx_security_log_event ON security_log (event, created_at)');

    $db->query(
        'CREATE TABLE IF NOT EXISTS cache (
            cache_key  VARCHAR(190) NOT NULL PRIMARY KEY,
            value      MEDIUMTEXT NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );
    // Para la limpieza de entradas caducadas y los DELETE por patrón LIKE.
    $db->query('CREATE INDEX IF NOT EXISTS idx_cache_expires ON cache (expires_at)');
    $db->query('CREATE INDEX IF NOT EXISTS idx_cache_key_like ON cache (cache_key)');

    // ---- Seed de permisos (idempotente) ----
    //
    // Lee la fuente de verdad de config/permissions.php y la traduce a filas.
    // Los roles se resuelven por nombre: si falta uno (BD sembrada a medias),
    // se salta ese rol por rol con un aviso en el log — no se puede construir
    // una matriz de permisos sobre roles que no existen.
    $catalog = require dirname(__DIR__, 2) . '/config/permissions.php';

    $roleIds = [];
    foreach ($db->fetchAll('SELECT id, name FROM roles') as $r) {
        $roleIds[(string) $r['name']] = (int) $r['id'];
    }

    foreach ($catalog as $key => [$description, $defaultRoles]) {
        $db->execute(
            'INSERT IGNORE INTO permissions (`key`, description) VALUES (:key, :desc)',
            ['key' => $key, 'desc' => $description]
        );
        $permId = (int) ($db->fetch(
            'SELECT id FROM permissions WHERE `key` = :key',
            ['key' => $key]
        )['id'] ?? 0);
        if ($permId <= 0) {
            continue;
        }

        foreach ($defaultRoles as $roleName) {
            if (!isset($roleIds[$roleName])) {
                error_log("[migracion 0005] rol no encontrado para otorgar '{$key}': {$roleName}");
                continue;
            }
            $db->execute(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role, :perm)',
                ['role' => $roleIds[$roleName], 'perm' => $permId]
            );
        }
    }
};