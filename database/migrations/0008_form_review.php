<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0008: F3 — revisión/aprobación antes de publicar.
 *
 * Añade el estado `in_review` a `forms.status` y las tres columnas que cuentan
 * qué pasó con la revisión (`review_requested_at`, `reviewed_at`, `reviewed_by`).
 *
 * Por qué un ENUM y no el estado en `settings`: `status` YA es un ENUM de tres
 * valores y hay un índice `idx_forms_user_status (user_id, status)` que el
 * dashboard usa para contar. Añadir un cuarto valor es cambiar el ENUM; mover el
 * estado a un JSON obligaría a reindexar y a reescribir el conteo del panel.
 *
 * Por qué `reviewed_by` es una FK con `ON DELETE SET NULL` y no un entero suelto:
 * si se borra el usuario que aprobó, el formulario ya publicado tiene que seguir
 * siendo published (no puede volver a "in_review" por un borrado de cuenta), pero
 * la traza de quién lo aprobó se queda sin nombre en vez de apuntar a una fila
 * que ya no existe. Con `ON DELETE CASCADE` el formulario desaparecería.
 *
 * `review_requested_at` se guarda aparte de `reviewed_at` a propósito: son
 * instantes distintos y la pregunta útil es "¿cuánto lleva esperando?", que con
 * un solo `reviewed_at` no se puede responder.
 *
 * El estado `in_review` NO es un estado que se sirva: el gate de
 * `FillController` lo trata como "en revisión" (página informativa, no 404) y el
 * POST sigue exigiendo `published` como antes.
 *
 * Idempotente: las columnas se comprueban en information_schema antes de
 * crearse, el `MODIFY COLUMN` del ENUM es idempotente por naturaleza (volver a
 * declarar el mismo conjunto de valores no falla), y el bloque de permisos relee
 * `config/permissions.php` con `INSERT IGNORE` como hizo la 0006.
 */

return static function (Database $db): void {
    $columnaExiste = static function (string $table, string $column) use ($db): bool {
        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column]
        );
        return ((int) ($row['c'] ?? 0)) > 0;
    };

    // ---- forms.status: añadir 'in_review' ----
    //
    // MODIFY y no DROP+ADD: en MariaDB el ENUM se reescribe internamente, pero
    // DROP+ADD pierde el orden de los valores y obliga a reconstruir. Además
    // MODIFY sobre un ENUM que YA tiene el valor es un no-op, así que repetir
    // la migración no hace nada (que es lo que se busca).
    $db->query(
        "ALTER TABLE forms
         MODIFY COLUMN status ENUM('draft','in_review','published','closed') NOT NULL DEFAULT 'draft'"
    );

    // ---- Traza de la revisión ----
    if (!$columnaExiste('forms', 'review_requested_at')) {
        $db->query(
            'ALTER TABLE forms
             ADD COLUMN review_requested_at DATETIME DEFAULT NULL
             COMMENT \'Instante UTC en que se envió a revisión\''
        );
        echo "  [DDL] forms.review_requested_at añadida\n";
    }

    if (!$columnaExiste('forms', 'reviewed_at')) {
        $db->query(
            'ALTER TABLE forms
             ADD COLUMN reviewed_at DATETIME DEFAULT NULL
             COMMENT \'Instante UTC de la aprobación\''
        );
        echo "  [DDL] forms.reviewed_at añadida\n";
    }

    if (!$columnaExiste('forms', 'reviewed_by')) {
        $db->query(
            'ALTER TABLE forms
             ADD COLUMN reviewed_by INT UNSIGNED DEFAULT NULL
             COMMENT \'Usuario con forms.review que aprobó\''
        );
        echo "  [DDL] forms.reviewed_by añadida\n";
    }

    // La FK se añade aparte y solo si no existe: `ADD CONSTRAINT` no admite
    // `IF NOT EXISTS`, y el nombre estable (`fk_forms_reviewed_by`) es lo que
    // permite comprobarla en information_schema antes de crearla.
    $fkExiste = ((int) ($db->fetch(
        'SELECT COUNT(*) AS c FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = \'forms\'
           AND CONSTRAINT_NAME = \'fk_forms_reviewed_by\'',
    )['c'] ?? 0)) > 0;

    if (!$fkExiste) {
        $db->query(
            'ALTER TABLE forms
             ADD CONSTRAINT fk_forms_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL'
        );
        echo "  [DDL] FK forms.reviewed_by añadida\n";
    }

    // Índice para el panel de revisores: «formularios esperando mi revisión».
    // El que ya existe es (user_id, status) y no sirve para esto.
    $db->query('CREATE INDEX IF NOT EXISTS idx_forms_status_review ON forms (status, review_requested_at)');

    // ---- Seed del permiso `forms.review` ----
    //
    // Igual que la 0006 y por el mismo motivo: la 0005 no vuelve a correr en una
    // BD que ya la aplicó, así que sin este bloque `forms.review` no existiría y
    // `Auth::can()` daría false para todos — el panel de revisión aparecería
    // desactivado sin un solo error visible.
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
                error_log("[migracion 0008] rol no encontrado para otorgar '{$key}': {$roleName}");
                continue;
            }
            $db->execute(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role, :perm)',
                ['role' => $roleIds[$roleName], 'perm' => $permId]
            );
        }
    }
};
