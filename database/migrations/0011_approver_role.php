<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0011: rol del sistema `approver`.
 *
 * Dos cosas, y la segunda es la que hace que la primera sirva de algo:
 *
 * (1) El rol `approver`, con `forms.review` de fábrica. Es el rol que hace que
 * la revisión de F3 sea utilizable sin ser `admin`: con el catálogo anterior
 * (2026-10-01) el único rol que podía aprobar era `admin`, así que «mandar a
 * revisión» era en la práctica «que lo mire el que ya puede hacer todo». Con
 * este rol, quien aprueba no puede tocar los formularios de la persona que se
 * los envía, y la revisión deja de ser un trámite.
 *
 * Por qué `INSERT IGNORE` y no `ON DUPLICATE KEY UPDATE`: el nombre es la clave
 * única y `approver` es un rol CONCRETO que además va protegido contra el
 * borrado (`Role::SYSTEM_ROLES`). Si alguien lo renombró en la descripción
 * —que es lo único que se puede tocar— `ON DUPLICATE` se lo devolvería, y un
 * `INSERT IGNORE` lo deja como el operador lo dejó.
 *
 * (2) Re-siembra del catálogo de permisos LEYÉNDOLO de `config/permissions.php`,
 * igual que hizo la 0006 y por el mismo motivo: esta migración es la que
 * introduce `approver` como rol por defecto de `forms.review`, y la que lo
 * escribió necesita que el catálogo esté en la BD. Se siembran todas las claves
 * y el `INSERT IGNORE` deja intactas las que ya estaban, así que añadir otra
 * clave después no requiere un segundo bloque.
 *
 * OJO: `approver` nace con `forms.review` y `dashboard.view`, y con NADA más. La
 * segunda clave no es un privilege creep, es lo contrario: `DashboardController`
 * la exige, así que sin ella el rol que solo puede aprobar se encuentra con un
 * 403 en la puerta de entrada y `/review` no tiene forma de alcanzarse desde la
 * navegación. Lo que NO se le da es `forms.edit_own` ni ninguna otra de
 * formularios: aprobar es un acto de lectura y decisión sobre el estado de un
 * formulario ajeno, y darle también edición convertiría el rol en un dueño a
 * medio camino. El resto se decide en la matriz de `/admin/roles`.
 *
 * Y una segunda cosa que no va aquí, pero que esta migración hace posible:
 * `Role::SYSTEM_ROLES` incluye `approver`, de modo que el botón de borrar de
 * `/admin/roles` no aparece para él. Un rol del sistema que se puede borrar y
 * volver a crear pierde sus permisos y deja a la revisión sin nadie.
 *
 * Idempotente y sin transacción (el DDL de MariaDB hace commit implícito,
 * aunque aquí solo haya DML).
 */
return static function (Database $db): void {
    $db->execute(
        "INSERT IGNORE INTO roles (name, description)
         VALUES ('approver', 'Revisa y aprueba los formularios que le envían')"
    );

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
                error_log("[migracion 0011] rol no encontrado para otorgar '{$key}': {$roleName}");
                continue;
            }
            $db->execute(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role, :perm)',
                ['role' => $roleIds[$roleName], 'perm' => $permId]
            );
        }
    }

    if (!isset($roleIds['approver'])) {
        error_log('[migracion 0011] el rol approver no se pudo crear');
    } else {
        echo "  [seed] rol approver listo (forms.review, dashboard.view)\n";
    }
};
