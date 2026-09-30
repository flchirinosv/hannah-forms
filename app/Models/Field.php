<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

class Field
{
    private static function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    public static function byForm(int $formId): array
    {
        $rows = self::db()->fetchAll(
            'SELECT * FROM fields WHERE form_id = :form_id ORDER BY position, id',
            ['form_id' => $formId]
        );

        // Normalizar datos decodificados y tipos escalares.
        foreach ($rows as &$field) {
            $field['id'] = (int) $field['id'];
            $field['options'] = json_decode($field['options'] ?? 'null', true) ?: [];
            $field['validations'] = json_decode($field['validations'] ?? 'null', true) ?: [];
            $field['logic'] = json_decode($field['logic'] ?? 'null', true);
            $field['logic'] = is_array($field['logic']) ? $field['logic'] : null;
            $field['parent_id'] = self::normalizeParentId($field['parent_id']);
            $field['is_pii'] = (int) ($field['is_pii'] ?? 0) === 1 ? 1 : 0;
        }
        unset($field);

        // Construir el ORDEN DE LECTURA: nivel superior por posición y, justo
        // después de cada sección contenedora, sus hijos en orden.
        $sections = [];
        $byParent = [];
        $roots = [];
        foreach ($rows as $f) {
            if ($f['type'] === 'section') {
                $sections[$f['id']] = true;
            }
        }
        foreach ($rows as $f) {
            if ($f['parent_id'] !== null && isset($sections[$f['parent_id']])) {
                if (!isset($byParent[$f['parent_id']])) {
                    $byParent[$f['parent_id']] = [];
                }
                $byParent[$f['parent_id']][] = $f;
            } else {
                // Hijos huérfanos (sección eliminada): quedan a nivel superior.
                $roots[] = $f;
            }
        }
        foreach ($byParent as &$list) {
            usort($list, static fn($a, $b) => $a['position'] <=> $b['position']);
        }
        unset($list);

        $ordered = [];
        foreach ($roots as $f) {
            $ordered[] = $f;
            if ($f['type'] === 'section' && isset($byParent[$f['id']])) {
                foreach ($byParent[$f['id']] as $child) {
                    $ordered[] = $child;
                }
            }
        }

        return $ordered;
    }

    public static function find(int $id): ?array
    {
        return self::db()->fetch('SELECT * FROM fields WHERE id = :id', ['id' => $id]);
    }

    /**
     * Normaliza `parent_id` en la frontera de la columna.
     *
     * "Sin sección contenedora" es NULL, no 0: la columna está declarada
     * `INT UNSIGNED DEFAULT NULL` y la migración 0004 le puso la FK
     * `fk_fields_parent`. Con la FK activa, un `parent_id = 0` no es "campo
     * suelto": es una violación de integridad, porque el id 0 no puede existir en
     * un AUTO_INCREMENT. Y aun así la app lo escribía (`Field::create(..., 0)`
     * desde el builder y los tests), así que guardar la estructura de un
     * formulario con preguntas sueltas reventaba con un 1452.
     *
     * Un 0 nunca puede ser una sección real, así que mapearlo a NULL no pierde
     * información: es el mismo significado con el que la columna lo describe.
     */
    private static function normalizeParentId($value): ?int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    public static function create(
        int $formId,
        string $type,
        string $label,
        string $placeholder = '',
        string $helpText = '',
        bool $required = false,
        array $options = [],
        array $validations = [],
        int $position = 0,
        ?int $parentId = null,
        ?array $logic = null,
        bool $isPii = false
    ): int {
        self::db()->execute(
            'INSERT INTO fields (form_id, type, label, placeholder, help_text, is_required, is_pii, options, validations, position, parent_id, logic)
             VALUES (:form_id, :type, :label, :placeholder, :help_text, :is_required, :is_pii, :options, :validations, :position, :parent_id, :logic)',
            [
                'form_id'     => $formId,
                'type'        => $type,
                'label'       => $label,
                'placeholder' => $placeholder,
                'help_text'   => $helpText,
                'is_required' => $required ? 1 : 0,
                'is_pii'      => $isPii ? 1 : 0,
                'options'     => json_encode($options),
                'validations' => json_encode($validations),
                'position'    => $position,
                'parent_id'   => self::normalizeParentId($parentId),
                'logic'       => $logic !== null && $logic !== [] ? json_encode($logic) : null,
            ]
        );

        return self::db()->lastInsertId();
    }

    public static function updateField(
        int $id,
        string $type,
        string $label,
        string $placeholder = '',
        string $helpText = '',
        bool $required = false,
        array $options = [],
        array $validations = [],
        int $position = 0,
        ?int $parentId = null,
        ?array $logic = null,
        bool $isPii = false
    ): void {
        self::db()->execute(
            'UPDATE fields
             SET type = :type, label = :label, placeholder = :placeholder, help_text = :help_text,
                 is_required = :is_required, is_pii = :is_pii, options = :options, validations = :validations,
                 position = :position, parent_id = :parent_id, logic = :logic
             WHERE id = :id',
            [
                'id'          => $id,
                'type'        => $type,
                'label'       => $label,
                'placeholder' => $placeholder,
                'help_text'   => $helpText,
                'is_required' => $required ? 1 : 0,
                'is_pii'      => $isPii ? 1 : 0,
                'options'     => json_encode($options),
                'validations' => json_encode($validations),
                'position'    => $position,
                'parent_id'   => self::normalizeParentId($parentId),
                'logic'       => $logic !== null && $logic !== [] ? json_encode($logic) : null,
            ]
        );
    }

    public static function setLogic(int $id, ?array $logic): void
    {
        self::db()->execute(
            'UPDATE fields SET logic = :logic WHERE id = :id',
            ['id' => $id, 'logic' => $logic !== null && $logic !== [] ? json_encode($logic) : null]
        );
    }

    public static function deleteField(int $id): void
    {
        self::db()->execute('DELETE FROM fields WHERE id = :id', ['id' => $id]);
    }
}