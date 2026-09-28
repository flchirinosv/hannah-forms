<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Migración 0002: secciones contenedoras + lógica condicional.
 *
 * Agrega a `fields`:
 *   - `parent_id`: referencia al campo sección que CONTIENE la pregunta.
 *     NULL = nivel superior (pregunta suelta o sección). Las secciones son
 *     contenedores de 1 nivel (no anidables); su orden de lectura es
 *     "sección seguida de sus hijos".
 *   - `logic`: JSON con la condición de visibilidad:
 *       { "operator": "all|any", "rules": [ { "field_id": <id>, "op": "...", "value": "..." } ] }
 *
 * Es idempotente (ADD COLUMN solo si no existe, vía information_schema) y
 * hace un backfill UNA sola vez: los formularios existentes tenían las
 * secciones como "cabeceras" sin hijos; el backfill agrupa bajo cada sección
 * las preguntas que la seguían hasta la siguiente sección (el patrón visual
 * que tenían). Las preguntas anteriores a la primera sección quedan a nivel
 * superior. MaríaDB hace commit implícito en DDL; por eso el script verifica
 * y no se envuelve en transacción (convención del proyecto).
 */

return static function (Database $db): void {
    $columnExists = static function (string $column) use ($db): bool {
        $row = $db->fetch(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => 'fields', 'c' => $column]
        );
        return (int) ($row['c'] ?? 0) > 0;
    };

    $parentAdded = false;
    if (!$columnExists('parent_id')) {
        $db->query('ALTER TABLE fields ADD COLUMN parent_id INT UNSIGNED DEFAULT NULL AFTER position');
        $parentAdded = true;
    }
    if (!$columnExists('logic')) {
        $db->query('ALTER TABLE fields ADD COLUMN logic JSON DEFAULT NULL AFTER parent_id');
    }

    // Backfill único: agrupar preguntas bajo su sección cabecera.
    if ($parentAdded) {
        $fields = $db->fetchAll('SELECT id, form_id, type, position FROM fields ORDER BY form_id, position, id');
        $currentForm = null;
        $currentSection = null;

        foreach ($fields as $field) {
            $formId = (int) $field['form_id'];

            if ($currentForm === null || $currentForm !== $formId) {
                $currentForm = $formId;
                $currentSection = null;
            }

            if ($field['type'] === 'section') {
                $currentSection = (int) $field['id'];
                continue;
            }

            if ($currentSection !== null) {
                $db->execute('UPDATE fields SET parent_id = :parent_id WHERE id = :id', [
                    'parent_id' => $currentSection,
                    'id'        => (int) $field['id'],
                ]);
            }
        }
    }
};