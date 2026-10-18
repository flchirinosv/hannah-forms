<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Traduce el payload del builder (borrador con _uid) al formato que entiende
 * el fill (ids numéricos sintéticos 1..N).
 */
class BuilderDraftMapper
{
    /**
     * @param array $payload Payload del builder tal como se guarda en `forms.builder_draft`
     * @return array Campos normalizados para FillController/renderFill
     */
    public static function toFillFields(array $payload): array
    {
        $items = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];

        // Mapa _uid → id sintético (y también id real → sintético)
        $refToId = [];
        $synth = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $uid = (string) ($item['_uid'] ?? '');
            $id = ++$synth;
            if ($uid !== '') {
                $refToId[$uid] = $id;
            }
            $realId = $item['id'] ?? null;
            if ($realId !== null && $realId !== '') {
                $refToId[(string) $realId] = $id;
            }
        }

        $fields = [];
        $pos = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $uid = (string) ($item['_uid'] ?? '');
            $id = $refToId[$uid] ?? null;
            if ($id === null) {
                continue;
            }

            $parentUid = $item['parent_id'] ?? null;
            $parentId = ($parentUid !== null && $parentUid !== '')
                ? ($refToId[(string) $parentUid] ?? null)
                : null;

            $logic = null;
            if (is_array($item['logic'] ?? null)) {
                $rules = [];
                foreach ((array) ($item['logic']['rules'] ?? []) as $rule) {
                    if (!is_array($rule)) {
                        continue;
                    }
                    $ref = (string) ($rule['field_id'] ?? '');
                    $refId = $refToId[$ref] ?? null;
                    if ($refId === null) {
                        continue;
                    }
                    $rules[] = [
                        'field_id' => $refId,
                        'op'       => (string) ($rule['op'] ?? ''),
                        'value'    => (string) ($rule['value'] ?? ''),
                    ];
                }
                if ($rules !== []) {
                    $logic = [
                        'operator' => ($item['logic']['operator'] ?? 'all') === 'any' ? 'any' : 'all',
                        'rules'    => $rules,
                    ];
                }
            }

            $fields[] = [
                'id'          => $id,
                'type'        => (string) ($item['type'] ?? 'text'),
                'label'       => (string) ($item['label'] ?? ''),
                'placeholder' => (string) ($item['placeholder'] ?? ''),
                'help_text'   => (string) ($item['help_text'] ?? ''),
                'is_required' => (bool) ($item['is_required'] ?? false),
                'is_pii'      => (int) ($item['is_pii'] ?? 0) === 1 ? 1 : 0,
                'show_header' => (bool) ($item['show_header'] ?? true),
                'options'     => is_array($item['options'] ?? null) ? $item['options'] : [],
                'validations' => is_array($item['validations'] ?? null) ? $item['validations'] : [],
                'position'    => ++$pos,
                'parent_id'   => $parentId,
                'logic'       => $logic,
            ];
        }
        return $fields;
    }
}