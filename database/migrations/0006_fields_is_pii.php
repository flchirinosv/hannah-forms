<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0006: F1 — marca de campo personal (`fields.is_pii`) + el permiso
 * `responses.pii`.
 *
 * El problema que resuelve: la app guardaba la IP y el user-agent de cada
 * respuesta y los pintaba en claro, y además mostraba/exportaba CUALQUIER
 * respuesta sin distincón. Para una encuesta de recursos humanos eso no es
 * "información técnica": es un dato personal del respondiente, y el caso de
 * uso que más se da (evaluaciones de desempeño, encuestas de clima, filtros
 * de RRHH) se rompe con el primer CERN que lo lee.
 *
 * El diseño es de DOS capas, porque "marcar un campo" solo no basta:
 *
 *  1. `fields.is_pii` marca los campos que el dueño del formulario declara
 *     personales (un campo de texto libre no se puede clasificar solo). Es
 *     metadata del formulario, no del respondiente.
 *  2. `responses.pii` es la CAPACIDAD de leer lo personal sin enmascarar. La
 *     aplica `App\Services\Pii` en el punto de salida (vistas, JSON, CSV,
 *     descarga de adjuntos). Sin el permiso, la IP y el user-agent salen
 *     truncados y los campos marcados salen como `***`.
 *
 * Por qué una columna y no una clave en `settings`: `is_pii` se consulta por
 * SQL (enmascarar respuestas pide los ids marcados del formulario) y tiene que
 * ser un hecho de la fila, igual que `is_required`; esconderlo en un JSON
 * obligaría a traerse todos los campos y filtrar en PHP en cada exportación.
 *
 * Falla CERRADO a propósito: `Auth::can('responses.pii')` da false si la clave
 * no está en la matriz, así que un despliegue que se saltara esta migración
 * muestra TODO enmascarado en vez de filtrar.
 *
 * Idempotente (la columna se comprueba antes de crearse) y sin transacción,
 * porque el DDL hace commit implícito en MariaDB (convención del proyecto).
 */

return static function (Database $db): void {
    // ---- fields.is_pii ----
    //
    // Se comprueba en information_schema y no con un try/catch del ALTER: un
    // `ADD COLUMN` repetido revienta con 1060 y el rollback de MariaDB para DDL
    // no existe, así que un error a mitad dejaría la migración sin registrar
    // pero con la columna ya puesta (re-ejecutarla, entonces, sí fallaría).
    $columnaExiste = static function (string $table, string $column) use ($db): bool {
        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column]
        );
        return ((int) ($row['c'] ?? 0)) > 0;
    };

    if (!$columnaExiste('fields', 'is_pii')) {
        $db->query(
            'ALTER TABLE fields
             ADD COLUMN is_pii TINYINT(1) NOT NULL DEFAULT 0
             COMMENT \'Campo con datos personales: se enmascara sin el permiso responses.pii\''
        );
        echo "  [DDL] fields.is_pii añadida\n";
    }

    // ---- Seed del permiso `responses.pii` ----
    //
    // La migración 0005 ya sembró el catálogo de aquel momento, pero NO vuelve a
    // correr en una BD que ya la aplicó: sin este bloque, `responses.pii` no
    // existiría en `permissions` y el permiso sería imposible de otorgar
    // (aunque se pintara en `/admin/roles`). Se relee la MISMA fuente de verdad
    // (config/permissions.php) en vez de hardcodear la fila, para que añadir
    // otra clave después no requiera un segundo bloque: se siembran todas, y
    // `INSERT IGNORE` deja intactas las que ya estaban.
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
                error_log("[migracion 0006] rol no encontrado para otorgar '{$key}': {$roleName}");
                continue;
            }
            $db->execute(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role, :perm)',
                ['role' => $roleIds[$roleName], 'perm' => $permId]
            );
        }
    }
};
