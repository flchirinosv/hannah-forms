<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0017: permisos DIRECTOS por usuario (`user_permissions`).
 *
 * Hasta ahora el RBAC tenía tres fuentes: el rol propio y los roles de los
 * grupos (`Auth::permissions()`). Dar UN permiso a UNA persona obligaba a
 * cambiarle el rol —y con él todo lo demás— o a inventar un grupo de una sola
 * persona. Esta tabla añade la cuarta fuente: la concesión individual, que se
 * SUMA a las otras dos y nunca se resta.
 *
 * Es una tabla nueva y no una columna en `users` porque un usuario puede tener
 * varios permisos directos: es una N:M con el catálogo, igual que
 * `role_permissions`.
 *
 * NO se re-siembra el catálogo: la puerta de esta función es el permiso ya
 * existente `admin.users`, así que no hay ninguna clave nueva que sembrar.
 *
 * Idempotente y sin transacción: el DDL de MariaDB hace commit implícito y
 * `database/migrate.php` registra la migración solo tras el éxito.
 */
return static function (Database $db): void {
    $db->query(
        'CREATE TABLE IF NOT EXISTS user_permissions (
            user_id       INT UNSIGNED NOT NULL,
            permission_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, permission_id),
            CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_up_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    echo "  [tabla] user_permissions lista\n";
};
