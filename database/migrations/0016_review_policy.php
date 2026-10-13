<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0016: política de revisión de la instancia.
 *
 * F3 nació con la revisión como opt-in POR FORMULARIO (`settings.review.enabled`):
 * cada dueño decidía si su encuesta pasaba por la bandeja. Eso convierte la
 * revisión en un pacto con uno mismo —quien la activa se autoaprueba— y no deja
 * exigirla de verdad a quien opera la instalación. Ahora la decide un
 * administrador con dos ajustes de `app_settings` (`review_required` y
 * `review_notify_email`) y se exime a los dueños que tengan el permiso
 * `forms.publish_without_review`.
 *
 * Dos cosas, y ninguna sobra:
 *
 * (1) Re-siembra del catálogo de permisos LEYÉNDOLO de `config/permissions.php`,
 * igual que hicieron la 0006 y la 0011. Esta migración introduce la clave
 * `forms.publish_without_review` (default: solo `admin`), y la migración que la
 * siembra tiene que ser una NUEVA: la que escribió el catálogo anterior ya no
 * vuelve a correr. `INSERT IGNORE` deja intactas las claves que ya estaban, así
 * que re-sembrar el catálogo entero no pisa nada.
 *
 * (2) Conservar el estado de las instalaciones que YA usaban la revisión por
 * formulario. Sin esto, actualizar APAGARÍA la revisión de quien la tenía
 * puesta: sus formularios publicados y en cola seguirían en el mismo estado,
 * pero al editarlos ya no volverían a la cola y el siguiente borrador se
 * publicaría sin que nadie lo mirase — justo lo que esa persona había pedido
 * evitar. Se lee el `settings` JSON de cada formulario y, si alguno tenía la
 * revisión encendida, se enciende la política de la instancia. `INSERT IGNORE`
 * y no `ON DUPLICATE`: si una fila ya existe, alguien la puso a propósito con
 * esta misma función y no la sobreescribimos.
 *
 * NO se toca `forms.status`: un formulario ya publicado sigue publicado hasta
 * que alguien lo edite, y entonces la política decidirá si vuelve a la cola.
 *
 * Idempotente y sin transacción (el DDL de MariaDB hace commit implícito,
 * aunque aquí solo haya DML).
 */
return static function (Database $db): void {
    // (1) Catálogo de permisos + asignación por defecto.
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
                error_log("[migracion 0016] rol no encontrado para otorgar '{$key}': {$roleName}");
                continue;
            }
            $db->execute(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role, :perm)',
                ['role' => $roleIds[$roleName], 'perm' => $permId]
            );
        }
    }

    if (isset($roleIds['admin'])) {
        echo "  [seed] permiso forms.publish_without_review listo (admin)\n";
    }

    // (2) Preservar la revisión por formulario que ya estuviera encendida.
    $anyRequired = false;
    $anyNotify   = false;
    foreach ($db->fetchAll('SELECT settings FROM forms') as $row) {
        $settings = json_decode((string) $row['settings'], true);
        if (!is_array($settings)) {
            continue;
        }
        $review = $settings['review'] ?? null;
        if (!is_array($review)) {
            continue;
        }
        if (!empty($review['enabled'])) {
            $anyRequired = true;
        }
        if (!empty($review['notify_email'])) {
            $anyNotify = true;
        }
    }

    if ($anyRequired) {
        $db->execute(
            "INSERT IGNORE INTO app_settings (key_name, value) VALUES ('review_required', '1')"
        );
        echo "  [seed] review_required = 1 (había formularios con revisión propia)\n";
    }
    if ($anyNotify) {
        $db->execute(
            "INSERT IGNORE INTO app_settings (key_name, value) VALUES ('review_notify_email', '1')"
        );
        echo "  [seed] review_notify_email = 1 (había avisos de revisión encendidos)\n";
    }
};
