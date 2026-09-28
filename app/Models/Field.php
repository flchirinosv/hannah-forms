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
            $field['parent_id'] = $field['parent_id'] !== null && $field['parent_id'] !== ''
                ? (int) $field['parent_id'] : null;
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
        ?array $logic = null
    ): int {
        self::db()->execute(
            'INSERT INTO fields (form_id, type, label, placeholder, help_text, is_required, options, validations, position, parent_id, logic)
             VALUES (:form_id, :type, :label, :placeholder, :help_text, :is_required, :options, :validations, :position, :parent_id, :logic)',
            [
                'form_id'     => $formId,
                'type'        => $type,
                'label'       => $label,
                'placeholder' => $placeholder,
                'help_text'   => $helpText,
                'is_required' => $required ? 1 : 0,
                'options'     => json_encode($options),
                'validations' => json_encode($validations),
                'position'    => $position,
                'parent_id'   => $parentId,
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
        ?array $logic = null
    ): void {
        self::db()->execute(
            'UPDATE fields
             SET type = :type, label = :label, placeholder = :placeholder, help_text = :help_text,
                 is_required = :is_required, options = :options, validations = :validations,
                 position = :position, parent_id = :parent_id, logic = :logic
             WHERE id = :id',
            [
                'id'          => $id,
                'type'        => $type,
                'label'       => $label,
                'placeholder' => $placeholder,
                'help_text'   => $helpText,
                'is_required' => $required ? 1 : 0,
                'options'     => json_encode($options),
                'validations' => json_encode($validations),
                'position'    => $position,
                'parent_id'   => $parentId,
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

    public static function deleteByForm(int $formId): void
    {
        self::db()->execute('DELETE FROM fields WHERE form_id = :form_id', ['form_id' => $formId]);
    }
}