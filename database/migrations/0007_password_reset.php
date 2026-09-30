<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0007: F2 — recuperación de contraseña.
 *
 * Antes, perder la contraseña era una pérdida definitiva: no había ni una vía
 * de recuperación, y como el registro es abierto (`/register` es público),
 * "perderla" y "que alguien se registre con tu email" eran la misma cosa. Un
 * usuario de RRHH con quince formularios no abandona la herramienta por eso,
 * pero tampoco se registra otra vez con su email: le pide al admin que le cree
 * la cuenta a mano, y eso es un correo por cada contraseña caducada.
 *
 * Dos piezas:
 *
 *  1. `password_resets`: un token de UN SOLO USO y caducidad corta. Se guarda
 *     el **hash** del token, no el token (como un password): si alguien lee la
 *     tabla (un dump, una inyección, un backup) no puede autenticarse con ella.
 *     Es la misma razón por la que `users.password_hash` no guarda la
 *     contraseña: la fila no es el secreto.
 *  2. `users.password_changed_at`: para **cerrar las sesiones abiertas** cuando
 *     se cambia la contraseña. Sin esto, un atacante que robó una sesión
 *     (XSS, cookie en un equipo compartido) sigue dentro aunque el dueño
 *     recupere la cuenta y ponga una contraseña nueva; y al revés, quien
 *     recupera la cuenta por email seguiría con la sesión del atacante viva.
 *     No hay tabla de sesiones en esta app (son ficheros de PHP), así que no
 *     se pueden borrar por id: se compara el instante del último cambio con el
 *     de la apertura de sesión (ver `Auth::user()`).
 *
 * `DATETIME` en UTC, no `TIMESTAMP`: el contrato horario del proyecto es que la
 * BD y PHP trabajan en UTC y la conversión a la zona del lector ocurre al
 * pintar. Un TIMESTAMP con `ON UPDATE` se movería solo con cualquier UPDATE de
 * la fila y cerraría sesiones sin motivo.
 *
 * Idempotente y sin transacción (el DDL hace commit implícito en MariaDB).
 */

return static function (Database $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
            ['t' => $table]
        );
        return ((int) ($row['c'] ?? 0)) > 0;
    };

    $columnExists = static function (string $table, string $column) use ($db): bool {
        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column]
        );
        return ((int) ($row['c'] ?? 0)) > 0;
    };

    if (!$tableExists('password_resets')) {
        $db->query(
            'CREATE TABLE IF NOT EXISTS password_resets (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id     INT UNSIGNED NOT NULL,
                -- sha256 del token en HEX (64 chars). NUNCA el token en claro: la
                -- fila no es el secreto, y por eso se compara por hash.
                token_hash  CHAR(64) NOT NULL,
                expires_at  DATETIME NOT NULL,
                used_at     DATETIME DEFAULT NULL,
                ip_address  VARCHAR(45) DEFAULT NULL,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_password_resets_token (token_hash),
                KEY idx_password_resets_user (user_id),
                CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
        echo "  [DDL] password_resets creada\n";
    }

    if (!$columnExists('users', 'password_changed_at')) {
        $db->query('ALTER TABLE users ADD COLUMN password_changed_at DATETIME DEFAULT NULL');
        echo "  [DDL] users.password_changed_at añadida\n";
    }

    // El índice de `user_id` se crea solo dentro del CREATE TABLE, pero una BD
    // a la que le faltara (por un esquema viejo) lo necesita igual: la limpieza
    // de tokens anteriores borra por `user_id`.
    $db->query('CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets (user_id)');
};
