<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Database;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;

/**
 * Tests de integración de Form. Usa base de datos real y se auto-limpia.
 */
class FormTest
{
    /** @var int|null */
    private $formId = null;

    /** @var int|null */
    private $userId = null;

    public function setUp(): void
    {
        self::cleanupResiduals();

        $this->userId = User::create('Form Test', 'formtest-' . bin2hex(random_bytes(4)) . '@test.local', 'password', 2);
        $this->formId = Form::create((int) $this->userId, 'Mi formulario de prueba');
    }

    public function testByUserIncluyeConteoDeSubmissions(): void
    {
        Submission::create((int) $this->formId, '127.0.0.1');
        Submission::create((int) $this->formId, '127.0.0.1');

        $forms = Form::byUser((int) $this->userId);
        assertSame(1, count($forms), 'debería existir 1 formulario para el usuario');
        assertSame('2', (string) ($forms[0]['submissions_count'] ?? '0'), 'submissions_count debería ser 2');
    }

    public function testAllDevuelveListaConConteo(): void
    {
        Submission::create((int) $this->formId, '127.0.0.1');

        $rows = Form::all();
        assertTrue(count($rows) > 0, 'Form::all() debería devolver al menos el formulario creado');
        assertArrayHasKey('submissions_count', $rows[0]);
    }

    public function testByFormOrdenaSeccionYSusHijos(): void
    {
        $sectionId = Field::create((int) $this->formId, 'section', 'Datos personales', position: 0);
        $childA = Field::create((int) $this->formId, 'text', 'WhatsApp', position: 0, parentId: $sectionId);
        $childB = Field::create((int) $this->formId, 'text', 'Correo', position: 1, parentId: $sectionId);
        $rootC = Field::create((int) $this->formId, 'radio', 'Sexo', position: 5, options: [['value' => 'M'], ['value' => 'F']]);

        $ordered = Field::byForm((int) $this->formId);
        assertSame(
            [$sectionId, $childA, $childB, $rootC],
            array_map(static fn($f) => $f['id'], $ordered),
            'orden de lectura: sección y luego sus hijos; el resto por posición'
        );

        $childRow = $ordered[1];
        assertSame($sectionId, $childRow['parent_id'], 'el hijo debe decodificar su parent_id');
        assertSame(0, $childRow['position'], 'la posición se resetea por padre');
    }

    public function testLogicRoundTripPorSetLogicYUpdate(): void
    {
        $sourceId = Field::create((int) $this->formId, 'select', 'Estado', options: [['value' => 'Activo'], ['value' => 'Inactivo']]);
        $targetId = Field::create((int) $this->formId, 'text', 'Comentario solo si Activo');

        Field::setLogic($targetId, ['operator' => 'all', 'rules' => [['field_id' => $sourceId, 'op' => 'contains', 'value' => 'Activ']]]);

        $byId = [];
        foreach (Field::byForm((int) $this->formId) as $f) {
            $byId[$f['id']] = $f;
        }
        $decoded = $byId[$targetId]['logic'];
        assertTrue(is_array($decoded), 'setLogic debe persistir y decodificarse');
        assertEquals('contains', $decoded['rules'][0]['op']);
        assertSame($sourceId, (int) $decoded['rules'][0]['field_id'], 'la regla debe apuntar al campo fuente resuelto');

        Field::updateField(
            $targetId,
            'text',
            'Comentario solo si Activo',
            logic: ['operator' => 'any', 'rules' => [
                ['field_id' => $sourceId, 'op' => 'eq', 'value' => 'Activo'],
                ['field_id' => $sourceId, 'op' => 'filled', 'value' => ''],
            ]]
        );
        $reloaded = Field::byForm((int) $this->formId);
        $updated = null;
        foreach ($reloaded as $f) {
            if ($f['id'] === $targetId) {
                $updated = $f;
            }
        }
        assertNotNull($updated);
        assertEquals('any', $updated['logic']['operator']);
        assertSame(2, count($updated['logic']['rules']));
    }

    public function tearDown(): void
    {
        if ($this->userId !== null) {
            Database::getInstance(Config::db())->execute('DELETE FROM users WHERE id = :id', ['id' => $this->userId]);
        }
        $this->userId = null;
        $this->formId = null;
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        foreach ($db->fetchAll("SELECT id FROM users WHERE email LIKE '%@test.local'") as $u) {
            $db->execute('DELETE FROM users WHERE id = :id', ['id' => (int) $u['id']]);
        }
        $db->execute("DELETE FROM forms WHERE title LIKE 'Mi formulario de prueba%'");
    }
}