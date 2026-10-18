<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BuilderDraftMapper;

class BuilderDraftMapperTest
{
    public function testTraduceEstructuraSimple(): void
    {
        $payload = [
            'fields' => [
                [
                    '_uid' => 'a1',
                    'type' => 'text',
                    'label' => 'Nombre',
                    'is_required' => true,
                    'parent_id' => null,
                    'logic' => null,
                ],
                [
                    '_uid' => 'b2',
                    'type' => 'email',
                    'label' => 'Correo',
                    'is_required' => false,
                    'parent_id' => 'a1',
                    'logic' => null,
                ],
            ],
        ];
        $fields = BuilderDraftMapper::toFillFields($payload);
        assertSame(2, count($fields));
        assertSame('text', $fields[0]['type']);
        assertSame(1, $fields[0]['id']);
        assertNull($fields[0]['parent_id']);
        assertSame('email', $fields[1]['type']);
        assertSame(2, $fields[1]['id']);
        assertSame(1, $fields[1]['parent_id']);
    }

    public function testResuelveLogicaPorUid(): void
    {
        $payload = [
            'fields' => [
                [
                    '_uid' => 'q1',
                    'type' => 'radio',
                    'label' => 'Sí/No',
                    'options' => ['Sí', 'No'],
                    'parent_id' => null,
                    'logic' => null,
                ],
                [
                    '_uid' => 'q2',
                    'type' => 'text',
                    'label' => '¿Por qué?',
                    'parent_id' => null,
                    'logic' => [
                        'operator' => 'all',
                        'rules' => [
                            ['field_id' => 'q1', 'op' => 'eq', 'value' => 'Sí'],
                        ],
                    ],
                ],
            ],
        ];
        $fields = BuilderDraftMapper::toFillFields($payload);
        assertSame(1, $fields[1]['logic']['rules'][0]['field_id']);
        assertSame('Sí', $fields[1]['logic']['rules'][0]['value']);
    }

    public function testIgnoraReglasSinReferenciaValida(): void
    {
        $payload = [
            'fields' => [
                [
                    '_uid' => 'q1',
                    'type' => 'text',
                    'label' => 'A',
                    'logic' => [
                        'operator' => 'all',
                        'rules' => [
                            ['field_id' => 'inexistente', 'op' => 'eq', 'value' => 'x'],
                        ],
                    ],
                ],
            ],
        ];
        $fields = BuilderDraftMapper::toFillFields($payload);
        assertNull($fields[0]['logic']);
    }

    public function testNormalizaFlags(): void
    {
        $payload = [
            'fields' => [
                [
                    '_uid' => 'q1',
                    'type' => 'text',
                    'label' => 'Tel',
                    'is_pii' => 1,
                    'show_header' => false,
                    'is_required' => true,
                    'parent_id' => null,
                ],
            ],
        ];
        $fields = BuilderDraftMapper::toFillFields($payload);
        assertSame(1, $fields[0]['is_pii']);
        assertSame(false, $fields[0]['show_header']);
        assertSame(true, $fields[0]['is_required']);
    }
}
