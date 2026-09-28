<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Models\Field;

/**
 * TemplateService (P9) — arranque de formularios desde plantillas JSON.
 *
 * Las plantillas viven en database/templates/*.json y son DECLARATIVAS: no
 * dependen de ids de la base (las referencias entre campos usan `ref`, un
 * entero único y estable dentro del archivo). Ejemplo mínimo:
 *
 *   {
 *     "key": "encuesta-satisfaccion",
 *     "name": "Encuesta de satisfacción",
 *     "description": "NPS,azepción y comentarios",
 *     "settings": { "quiz": {"enabled": false} },
 *     "fields": [
 *       {"ref": 1, "type": "section", "label": "Tu opinión"},
 *       {"ref": 2, "type": "nps", "label": "¿Nos recomendarías?", "parent": 1},
 *       {"ref": 3, "type": "text", "label": "Comentario", "parent": 1,
 *        "logic": {"operator": "all", "rules": [{"field_id": 2, "op": "lte", "value": "6"}]}}
 *     ]
 *   }
 *
 * Flujo: FormController::create → load() → normalizeStructure() → apply().
 * normalizeStructure() traduce el formato declarativo al mismo contrato "wire"
 * que usa el builder (_uid / parent_id / logic.field_id como _uid), así que
 * apply() crea los campos con la misma lógica del builder (secciones padre,
 * posiciones por padre, validación de ciclos con LogicEvaluator).
 *
 * La validación de la plantilla (tipos whitelist, padre = section anterior,
 * fuentes de condición contestables, aciclicidad) se replica aquí porque este
 * servicio no depende del controller.
 */
final class TemplateService
{
    /** Tipos permitidos (debe coincidir con la whitelist de BuilderController). */
    public const ALLOWED_TYPES = [
        'text', 'textarea', 'email', 'number', 'date', 'select', 'radio', 'checkbox', 'scale',
        'url', 'tel', 'time', 'datetime-local', 'range', 'section', 'page',
        'matrix', 'nps', 'star', 'ranking', 'file',
    ];

    private const MAX_LABEL = 190;
    private const MAX_HELP = 255;

    /** Cache en memoria de las plantillas ya leídas (evita releer en un request). */
    private static array $cache = [];

    public static function dir(): string
    {
        return (string) Config::get('paths.templates', dirname(__DIR__, 2) . '/database/templates');
    }

    /**
     * Todas las plantillas disponibles para el selector del dashboard.
     * Cada entrada: key, name, description, fields (nº de campos), sections.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function list(): array
    {
        $out = [];
        foreach (self::files() as $file) {
            $data = self::readFile($file);
            if ($data === null) {
                continue;
            }
            $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
            $sections = 0;
            $questions = 0;
            foreach ($fields as $f) {
                if (!is_array($f)) {
                    continue;
                }
                if (($f['type'] ?? '') === 'section') {
                    $sections++;
                } else {
                    $questions++;
                }
            }
            $out[] = [
                'key'         => (string) ($data['key'] ?? basename($file, '.json')),
                'name'        => (string) ($data['name'] ?? basename($file, '.json')),
                'description' => (string) ($data['description'] ?? ''),
                'questions'   => $questions,
                'sections'    => $sections,
            ];
        }
        usort($out, static fn($a, $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Carga una plantilla por key (nombre de archivo sin .json).
     * Devuelve null si no existe o el JSON es inválido.
     *
     * @return array<string, mixed>|null
     */
    public static function load(string $key): ?array
    {
        $key = trim($key);
        if ($key === '' || !preg_match('/^[a-z0-9][a-z0-9\-]{0,60}$/', $key)) {
            return null;
        }
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $file = self::dir() . '/' . $key . '.json';
        $data = self::readFile($file);
        if ($data === null) {
            return null;
        }
        $data['key'] = $key;
        self::$cache[$key] = $data;
        return $data;
    }

    /**
     * Traduce el formato declarativo (refs) al contrato wire del builder.
     *
     * @param array<int, mixed> $fields campos del JSON de la plantilla
     * @return array{items: array<int, array<string, mixed>>, error: string|null}
     */
    public static function normalizeStructure(array $fields): array
    {
        $items = [];
        $byRef = [];
        $types = [];
        $parents = [];

        // PASO 1 — Refs únicos y enteros, tipo en whitelist.
        foreach ($fields as $idx => $field) {
            if (!is_array($field)) {
                return ['items' => [], 'error' => 'Plantilla inválida: un campo no es un objeto.'];
            }
            $ref = $field['ref'] ?? ($idx + 1);
            if (!is_int($ref) || $ref < 1) {
                return ['items' => [], 'error' => 'Plantilla inválida: los "ref" deben ser enteros positivos.'];
            }
            if (isset($byRef[$ref])) {
                return ['items' => [], 'error' => 'Plantilla inválida: "ref" duplicado (' . $ref . ').'];
            }
            $type = (string) ($field['type'] ?? '');
            if (!in_array($type, self::ALLOWED_TYPES, true)) {
                return ['items' => [], 'error' => 'Plantilla inválida: tipo no permitido (' . $type . ').'];
            }
            $byRef[$ref] = true;
            $types[$ref] = $type;
            $parents[$ref] = array_key_exists('parent', $field) && $field['parent'] !== null && $field['parent'] !== ''
                ? $field['parent']
                : null;
        }

        // PASO 2 — Coherencia de referencias: el padre debe ser una section
        // declarada ANTES; los estructurales (section/page) van siempre en raíz.
        foreach ($types as $ref => $type) {
            $parent = $parents[$ref];
            $isStructural = $type === 'section' || $type === 'page';
            if ($isStructural) {
                if ($parent !== null) {
                    return ['items' => [], 'error' => 'Plantilla inválida: ' . $type . ' anidado dentro de una sección (ref ' . $ref . ').'];
                }
                continue;
            }
            if ($parent === null) {
                continue;
            }
            if (!is_int($parent) || !isset($types[$parent])) {
                return ['items' => [], 'error' => 'Plantilla inválida: el campo ref ' . $ref . ' referencia una sección inexistente.'];
            }
            if ($types[$parent] !== 'section') {
                return ['items' => [], 'error' => 'Plantilla inválida: el campo ref ' . $ref . ' solo puede anidarse en una sección.'];
            }
            if ($parent >= $ref) {
                return ['items' => [], 'error' => 'Plantilla inválida: la sección del campo ref ' . $ref . ' debe declararse antes.'];
            }
        }

        // PASO 3 — Construir los items con _uid y lógica resuelta a _uid.
        $answerable = array_values(array_diff(self::ALLOWED_TYPES, ['section', 'page']));
        $order = [];
        foreach ($fields as $field) {
            $ref = (int) $field['ref'];
            $type = $types[$ref];
            $uid = self::uid($ref);
            $order[$ref] = count($order);

            $isStructural = $type === 'section' || $type === 'page';
            $label = (string) ($field['label'] ?? '');
            $placeholder = (string) ($field['placeholder'] ?? '');
            $helpText = (string) ($field['help_text'] ?? '');
            if (mb_strlen($label) > self::MAX_LABEL
                || mb_strlen($placeholder) > self::MAX_LABEL
                || mb_strlen($helpText) > self::MAX_HELP
            ) {
                return ['items' => [], 'error' => 'Plantilla inválida: etiqueta o texto de ayuda demasiado largo (ref ' . $ref . ').'];
            }

            $item = [
                '_uid'        => $uid,
                'type'        => $type,
                'label'       => $label,
                'placeholder' => $placeholder,
                'help_text'   => $helpText,
                'is_required' => $isStructural ? false : (bool) ($field['is_required'] ?? false),
                'options'     => self::normalizeOptions($field['options'] ?? []),
                'validations' => $isStructural ? [] : self::normalizeValidations($field['validations'] ?? []),
                'parent_id'   => $parents[$ref] === null ? null : self::uid((int) $parents[$ref]),
            ];

            $logic = self::normalizeLogic($ref, $field['logic'] ?? null, $types, $answerable);
            if ($logic['error'] !== null) {
                return ['items' => [], 'error' => $logic['error']];
            }
            if ($logic['logic'] !== null) {
                $item['logic'] = $logic['logic'];
            }

            $items[] = $item;
        }

        return ['items' => $items, 'error' => null];
    }

    /**
     * Persiste la estructura normalizada en un formulario recién creado.
     * Envuelve todo en transacción: si la validación del grafo (ciclos) falla,
     * no queda nada a medias.
     *
     * @param array<int, array<string, mixed>> $items contrato wire (normalizeStructure)
     * @return string|null mensaje de error, o null si se aplicó bien
     */
    public static function apply(int $formId, array $items): ?string
    {
        $db = Database::getInstance(Config::db());
        $payloadByUid = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['_uid'])) {
                return 'Plantilla inválida: falta el identificador de un campo.';
            }
            $payloadByUid[(string) $item['_uid']] = $item;
        }

        $db->beginTransaction();
        try {
            $uidToId = [];
            $idToUid = [];
            $parentUid = [];
            $refOf = [];
            $positionCounters = ['__root__' => 0];

            foreach ($items as $item) {
                $uid = (string) $item['_uid'];
                $type = (string) $item['type'];
                $parent = $item['parent_id'] ?? null;
                $parentUid[$uid] = ($parent === null || $parent === '') ? null : (string) $parent;

                $parentIdResolved = null;
                if ($parentUid[$uid] !== null) {
                    if (!isset($uidToId[$parentUid[$uid]])) {
                        $db->rollBack();
                        return 'Plantilla inválida: la sección contenedora debe ir antes que sus preguntas.';
                    }
                    $parentIdResolved = $uidToId[$parentUid[$uid]];
                }

                if (isset($item['logic']) && is_array($item['logic'])) {
                    $refOf[$uid] = $item['logic'];
                }

                $counterKey = $parentUid[$uid] ?? '__root__';
                if (!isset($positionCounters[$counterKey])) {
                    $positionCounters[$counterKey] = 0;
                }
                $position = ++$positionCounters[$counterKey];

                $id = Field::create(
                    $formId,
                    $type,
                    (string) ($item['label'] ?? ''),
                    (string) ($item['placeholder'] ?? ''),
                    (string) ($item['help_text'] ?? ''),
                    (bool) ($item['is_required'] ?? false),
                    (array) ($item['options'] ?? []),
                    (array) ($item['validations'] ?? []),
                    $position,
                    $parentIdResolved,
                    null
                );
                $uidToId[$uid] = $id;
                $idToUid[$id] = $uid;
            }

            // PASO 2 — Resolver la lógica (_uid -> id) y validar el grafo.
            $resolvedLogicByUid = [];
            foreach ($refOf as $uid => $logicRaw) {
                $resolved = self::resolveLogic($uid, $logicRaw, $uidToId, $idToUid, $payloadByUid);
                if ($resolved === null) {
                    $db->rollBack();
                    return 'Plantilla inválida: una condición apunta a un campo inexistente, a sí misma o a una sección.';
                }
                $resolvedLogicByUid[$uid] = $resolved;
            }

            $graph = [];
            foreach ($items as $item) {
                $uid = (string) $item['_uid'];
                $graph[] = [
                    'id'        => $uidToId[$uid],
                    'type'      => (string) $item['type'],
                    'parent_id' => $parentUid[$uid] === null ? null : ($uidToId[$parentUid[$uid]] ?? null),
                    'logic'     => $resolvedLogicByUid[$uid] ?? null,
                ];
            }
            $answerable = array_values(array_diff(self::ALLOWED_TYPES, ['section', 'page']));
            $error = LogicEvaluator::validateStructure($graph, self::ALLOWED_TYPES, $answerable);
            if ($error !== null) {
                $db->rollBack();
                return $error;
            }

            // PASO 3 — Persistir la lógica resuelta.
            foreach ($resolvedLogicByUid as $uid => $logic) {
                Field::setLogic($uidToId[$uid], $logic);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('[TemplateService] Error aplicando plantilla: ' . $e->getMessage());
            return 'No se pudo aplicar la plantilla: ' . $e->getMessage();
        }

        return null;
    }

    /**
     * Construye el payload de estructura (formato declarativo con refs) de un
     * formulario existente: es la inversa de load() y lo que consume
     * /forms/{id}/export-structure.json.
     *
     * @param array<string, mixed> $form   fila del formulario con 'fields'
     * @param array<string, mixed> $settings settings normalizados
     * @return array<string, mixed>
     */
    public static function exportPayload(array $form, array $settings): array
    {
        $fields = (array) ($form['fields'] ?? []);
        $idToRef = [];
        $ref = 0;
        // refs = orden de lectura (1..N), para que el archivo sea legible y
        // las referencias tengan sentido al abrirlo.
        foreach ($fields as $f) {
            $ref++;
            $idToRef[(int) ($f['id'] ?? 0)] = $ref;
        }

        $out = [];
        foreach ($fields as $f) {
            $id = (int) ($f['id'] ?? 0);
            $item = [
                'ref'         => $idToRef[$id] ?? 0,
                'type'        => (string) ($f['type'] ?? ''),
                'label'       => (string) ($f['label'] ?? ''),
            ];
            if (($f['placeholder'] ?? '') !== '') {
                $item['placeholder'] = (string) $f['placeholder'];
            }
            if (($f['help_text'] ?? '') !== '') {
                $item['help_text'] = (string) $f['help_text'];
            }
            if (!empty($f['is_required'])) {
                $item['is_required'] = true;
            }
            $options = (array) ($f['options'] ?? []);
            if ($options !== []) {
                $item['options'] = $options;
            }
            $validations = (array) ($f['validations'] ?? []);
            if ($validations !== []) {
                $item['validations'] = $validations;
            }
            $parentId = $f['parent_id'] ?? null;
            if ($parentId !== null && $parentId !== '' && isset($idToRef[(int) $parentId])) {
                $item['parent'] = $idToRef[(int) $parentId];
            }
            $logic = $f['logic'] ?? null;
            if (is_array($logic) && !empty($logic['rules']) && is_array($logic['rules'])) {
                $rules = [];
                foreach ($logic['rules'] as $rule) {
                    if (!is_array($rule) || !isset($rule['op'])) {
                        continue;
                    }
                    $targetRef = $idToRef[(int) ($rule['field_id'] ?? 0)] ?? null;
                    if ($targetRef === null) {
                        continue;
                    }
                    $rules[] = [
                        'field_id' => $targetRef,
                        'op'       => (string) $rule['op'],
                        'value'    => (string) ($rule['value'] ?? ''),
                    ];
                }
                if ($rules !== []) {
                    $item['logic'] = [
                        'operator' => in_array($logic['operator'] ?? 'all', ['all', 'any'], true) ? (string) $logic['operator'] : 'all',
                        'rules'    => $rules,
                    ];
                }
            }
            $out[] = $item;
        }

        return [
            'app'         => 'vanilla-forms',
            'version'     => 1,
            'key'         => (string) ($form['slug'] ?? ''),
            'name'        => (string) ($form['title'] ?? ''),
            'description' => (string) ($form['description'] ?? ''),
            'settings'    => $settings,
            'fields'      => $out,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readFile(string $file): ?array
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            error_log('[TemplateService] JSON inválido en ' . basename($file) . ': ' . json_last_error_msg());
            return null;
        }
        if (!is_array($data['fields'] ?? null)) {
            error_log('[TemplateService] La plantilla ' . basename($file) . ' no tiene "fields".');
            return null;
        }
        return $data;
    }

    /** @return array<int, string> */
    private static function files(): array
    {
        $files = glob(self::dir() . '/*.json') ?: [];
        sort($files);
        return $files;
    }

    private static function uid(int $ref): string
    {
        return 'tpl-' . $ref;
    }

    /**
     * Opciones: acepta lista de strings ("value") o de objetos {value, group}
     * (para las filas de una matriz).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeOptions($options): array
    {
        if (!is_array($options)) {
            return [];
        }
        $out = [];
        foreach ($options as $option) {
            if (is_array($option)) {
                $value = (string) ($option['value'] ?? '');
                if ($value === '') {
                    continue;
                }
                $item = ['value' => $value];
                if (($option['group'] ?? '') !== '') {
                    $item['group'] = (string) $option['group'];
                }
                $out[] = $item;
            } else {
                $value = trim((string) $option);
                if ($value === '') {
                    continue;
                }
                $out[] = ['value' => $value];
            }
        }
        return $out;
    }

    private static function normalizeValidations($validations): array
    {
        return is_array($validations) ? $validations : [];
    }

    /**
     * Traduce la lógica declarativa (field_id = ref) a _uid de origen, validando
     * operadores y que la fuente sea contestable y distinta del propio campo.
     *
     * @param array<int, string> $types      ref => type
     * @param array<int, string> $answerable
     * @return array{logic: array<string, mixed>|null, error: string|null}
     */
    private static function normalizeLogic(int $ref, $logic, array $types, array $answerable): array
    {
        if (!is_array($logic) || empty($logic['rules']) || !is_array($logic['rules'])) {
            return ['logic' => null, 'error' => null];
        }
        $operator = (string) ($logic['operator'] ?? 'all');
        if ($operator !== 'all' && $operator !== 'any') {
            return ['logic' => null, 'error' => 'Plantilla inválida: el operador de la condición debe ser "all" u "any" (ref ' . $ref . ').'];
        }

        $rules = [];
        foreach ($logic['rules'] as $rule) {
            if (!is_array($rule) || !isset($rule['op'])) {
                return ['logic' => null, 'error' => 'Plantilla inválida: regla de condición incompleta (ref ' . $ref . ').'];
            }
            $op = (string) $rule['op'];
            if (!in_array($op, LogicEvaluator::OPERATORS, true)) {
                return ['logic' => null, 'error' => 'Plantilla inválida: operador de condición no permitido (' . $op . ').'];
            }
            $targetRef = $rule['field_id'] ?? null;
            if (!is_int($targetRef) || !isset($types[$targetRef])) {
                return ['logic' => null, 'error' => 'Plantilla inválida: la condición del ref ' . $ref . ' apunta a un campo inexistente.'];
            }
            if ($targetRef === $ref) {
                return ['logic' => null, 'error' => 'Plantilla inválida: el ref ' . $ref . ' se condiciona a sí mismo.'];
            }
            if (!in_array($types[$targetRef], $answerable, true)) {
                return ['logic' => null, 'error' => 'Plantilla inválida: el ref ' . $targetRef . ' no puede ser fuente de condición (sección o página).'];
            }
            $rules[] = [
                'field_id' => self::uid($targetRef),
                'op'       => $op,
                'value'    => in_array($op, ['filled', 'empty'], true) ? '' : (string) ($rule['value'] ?? ''),
            ];
        }
        if ($rules === []) {
            return ['logic' => null, 'error' => null];
        }
        return ['logic' => ['operator' => $operator, 'rules' => $rules], 'error' => null];
    }

    /**
     * Espejo de BuilderController::resolveLogic: _uid -> id, con las mismas
     * guarantías (fuente existente, contestable y ≠ el propio campo).
     */
    private static function resolveLogic(string $uid, array $logicRaw, array $uidToId, array $idToUid, array $payloadByUid): ?array
    {
        $operator = (string) ($logicRaw['operator'] ?? 'all');
        if ($operator !== 'all' && $operator !== 'any') {
            return null;
        }
        $rules = [];
        foreach ((array) ($logicRaw['rules'] ?? []) as $rule) {
            if (!is_array($rule) || !isset($rule['op'])) {
                return null;
            }
            $op = (string) $rule['op'];
            if (!in_array($op, LogicEvaluator::OPERATORS, true)) {
                return null;
            }
            $ref = $rule['field_id'] ?? null;
            $targetId = null;
            if (is_string($ref) && str_starts_with($ref, 'tpl-')) {
                $targetId = $uidToId[$ref] ?? null;
            } elseif (is_numeric($ref)) {
                $candidate = (int) $ref;
                if (isset($idToUid[$candidate]) && ($uidToId[$idToUid[$candidate]] ?? null) === $candidate) {
                    $targetId = $candidate;
                }
            }
            if ($targetId === null || $targetId === ($uidToId[$uid] ?? -1)) {
                return null;
            }
            $targetUid = $idToUid[$targetId] ?? null;
            if ($targetUid === null) {
                return null;
            }
            $targetType = (string) ($payloadByUid[$targetUid]['type'] ?? '');
            if ($targetType === 'section' || $targetType === 'page') {
                return null;
            }
            $rules[] = [
                'field_id' => $targetId,
                'op'       => $op,
                'value'    => in_array($op, ['filled', 'empty'], true) ? '' : (string) ($rule['value'] ?? ''),
            ];
        }
        return $rules === [] ? null : ['operator' => $operator, 'rules' => $rules];
    }
}
