<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0004: índices y FK que `schema.sql` declara y una BD migrada no tiene.
 *
 * Este es el hallazgo D5, y es de la misma familia que el bug de `parentId`/
 * `parent_id`: un contrato implícito entre el esquema y la aplicación que nadie
 * verifica. `schema.sql:63` declara `fk_fields_parent` y `schema.sql:70`
 * declara `idx_forms_user_status`, pero una base creada con las migraciones
 * 0001-0003 (o migrada antes de que existieran) se queda sin ellos, y nada
 * avisa: el INSERT sigue funcionando, el dashboard sigue funcionando, y el
 * borrado de una sección deja hijos colgando.
 *
 * Consecuencia concreta de la FK que falta: `parent_id` no tenía ni índice ni
 * FK, así que `DELETE FROM fields WHERE id = <sección>` no localiza a sus hijas.
 * El builder borra la sección y sus preguntas quedan como raíces huérfanas: el
 * formulario se desordena y las preguntas aparecen sueltas, que es exactamente
 * el síntoma del bug D1 pero por el camino de datos en vez de por el de la
 * vista. Con la FK, el `ON DELETE CASCADE` las borra con su padre.
 *
 * Antes de añadir la FK se comprueba que no haya filas colgando: si las hubiera,
 * la propia migración fallaría con la FK y no podría arreglar sola el drift.
 * En la base de este proyecto hay 0.
 *
 * Se añaden también dos índices que no son de drift sino de rendimiento, y que
 * `schema.sql` todavía no declara:
 *
 *  - `answers (submission_id, field_id)`: `withAnswersByIds()` filtra por
 *    `submission_id IN (...)` y agrupa por `field_id` en PHP. Con dos índices
 *    simples MariaDB puede usar uno y tiene que filtrar el otro; el compuesto
 *    cubre el filtro y cubre la clave del mapa.
 *  - `idx_rate_window (window_start)`: `RateLimiter::cleanExpired()` borra por
 *    `window_start < NOW() - INTERVAL n SECOND`, o sea que solo puede usar la
 *    columna **leading**. El índice que sí hay es `(identifier, window_start)`,
 *    que para ese filtro no sirve: la columna por la que se empieza (window_start)
 *    no es la primera.
 *
 * Idempotente (todo se comprueba antes de crearse) y sin transacción, porque el
 * DDL hace commit implícito en MariaDB (convención del proyecto).
 */

return static function (Database $db): void {
    $indexExists = static function (string $table, string $index) use ($db): bool {
        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i',
            ['t' => $table, 'i' => $index]
        );
        return (int) ($row['c'] ?? 0) > 0;
    };

    $fkeyExists = static function (string $table, string $constraint) use ($db): bool {
        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = :k
               AND CONSTRAINT_TYPE = :tipo',
            ['t' => $table, 'k' => $constraint, 'tipo' => 'FOREIGN KEY']
        );
        return (int) ($row['c'] ?? 0) > 0;
    };

    // ---- Drift con schema.sql ----

    if (!$indexExists('forms', 'idx_forms_user_status')) {
        $db->query('CREATE INDEX idx_forms_user_status ON forms (user_id, status)');
    }

    if (!$fkeyExists('fields', 'fk_fields_parent')) {
        // Sin índice previo no se puede validar: la FK necesita que parent_id
        // sea clave ajena, y el orden de la sentencia no importa pero el estado
        // del sí. MariaDB crea el índice él mismo si no existe, pero lo
        // declaramos para que el nombre coincida con schema.sql.
        if (!$indexExists('fields', 'idx_fields_parent')) {
            $db->query('CREATE INDEX idx_fields_parent ON fields (parent_id)');
        }

        $colgadas = (int) ($db->fetch(
            'SELECT COUNT(*) AS c
             FROM fields f
             LEFT JOIN fields p ON p.id = f.parent_id
             WHERE f.parent_id IS NOT NULL AND p.id IS NULL'
        )['c'] ?? 0);

        if ($colgadas > 0) {
            // No se arregla sola: una reparación de datos necesita criterio del
            // autor (¿a la raíz? ¿se borran?). Se dice cuál es en vez de dejar
            // que la ALTER falle con un error de MariaDB.
            throw new RuntimeException(
                "No se puede crear fk_fields_parent: hay {$colgadas} filas con parent_id "
                . 'apuntando a una sección que no existe. Rehállelas antes (o revierte el índice).'
            );
        }

        $db->query(
            'ALTER TABLE fields
             ADD CONSTRAINT fk_fields_parent FOREIGN KEY (parent_id)
             REFERENCES fields(id) ON DELETE CASCADE'
        );
    }

    // ---- Índices de rendimiento ----

    if (!$indexExists('answers', 'idx_answers_submission_field')) {
        $db->query('CREATE INDEX idx_answers_submission_field ON answers (submission_id, field_id)');
    }

    if (!$indexExists('rate_limits', 'idx_rate_window')) {
        $db->query('CREATE INDEX idx_rate_window ON rate_limits (window_start)');
    }
};
