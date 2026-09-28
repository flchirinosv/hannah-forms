<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Evaluador de lógica condicional (motor DAG + orden topológico).
 *
 * Cada campo puede declarar una condición de visibilidad:
 *   { "operator": "all|any", "rules": [ { "field_id": <id>, "op": "...", "value": "..." } ] }
 *
 * Semántica de reglas (determinista, replicada en fill.js):
 *  - "afirmaciones" (eq, contains, gt/gte/lt/lte, filled): con fuente vacía
 *    o desconocida devuelven FALSE.
 *  - "negaciones" (neq, not_contains): con fuente vacía devuelven TRUE
 *    (muestran por defecto mientras no haya respuesta).
 *  - Para checkbox (valor = array) eq/contains equivalen a "la opción está
 *    marcada"; not_contains/neq a "no está marcada".
 *
 * La evaluación se hace sobre un MAPA de respuestas por field_id. La
 * acyclicidad se garantiza en guardado (BuilderController) y la resolución
 * runtime es en orden topológico para que una regla pueda referirse a
 * CUALQUIER campo (anterior o posterior), sin recursión.
 */
final class LogicEvaluator
{
    /** @var string[] Operadores permitidos (whitelist). */
    public const OPERATORS = ['eq', 'neq', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte', 'filled', 'empty', 'matches'];

    /**
     * Evalúa una condición completa (all/any) contra un mapa field_id => valor.
     */
    public static function evaluate(?array $logic, array $answers): bool
    {
        if ($logic === null || empty($logic['rules']) || !is_array($logic['rules'])) {
            return true;
        }

        $operator = $logic['operator'] ?? 'all';
        if ($operator !== 'any' && $operator !== 'all') {
            $operator = 'all';
        }

        $results = [];
        foreach ($logic['rules'] as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $results[] = self::evaluateRule($rule, $answers);
        }

        if ($results === []) {
            return true;
        }
        return $operator === 'any'
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);
    }

    /**
     * Evalúa una regla individual.
     */
    public static function evaluateRule(array $rule, array $answers): bool
    {
        $fieldId = (int) ($rule['field_id'] ?? 0);
        $op = (string) ($rule['op'] ?? '');
        $expected = $rule['value'] ?? '';
        if ($expected !== null && !is_string($expected)) {
            $expected = (string) $expected;
        }

        $value = array_key_exists($fieldId, $answers) ? $answers[$fieldId] : null;
        $empty = self::isEmpty($value);

        if ($op === 'filled') {
            return !$empty;
        }
        if ($op === 'empty') {
            return $empty;
        }
        if (!in_array($op, self::OPERATORS, true)) {
            return false;
        }

        // Afirmaciones con fuente vacía/desconocida => false.
        if ($empty) {
            return $op === 'neq' || $op === 'not_contains';
        }

        switch ($op) {
            case 'eq':
                return self::matches($value, $expected);
            case 'neq':
                return !self::matches($value, $expected);
            case 'contains':
                return self::contains($value, $expected);
            case 'not_contains':
                return !self::contains($value, $expected);
            case 'matches':
                return self::matchesRegex($value, $expected);
            case 'gt':
                return self::compareNumeric($value, $expected) > 0;
            case 'gte':
                return self::compareNumeric($value, $expected) >= 0;
            case 'lt':
                return self::compareNumeric($value, $expected) < 0;
            case 'lte':
                return self::compareNumeric($value, $expected) <= 0;
        }

        return false;
    }

    /**
     * Calcula un orden de evaluación TIPO TOPOLÓGICO para los campos.
     *
     * Las aristas del grafo son dependencias: un campo depende de los field_id
     * de sus reglas y del de su sección contenedora (parent_id). Kahn (sin
     * recursión). Devuelve los ids en orden, o NULL si el grafo tiene un ciclo
     * (formulario inválido que no debería poder guardarse).
     *
     * P8: `$extraDeps` permite añadir dependencias implícitas por campo
     * (mapa field_id => lista de field_id) — el FillController la usa para
     * que cada pregunta dependa del campo «page» que abre su bloque, de modo
     * que la condición de omisión de página se resuelva antes de sus preguntas.
     *
     * @param array<int, array> $fields Lista de campos (con id, type, parent_id, logic).
     * @param array<int, int[]> $extraDeps Dependencias adicionales field_id => [deps]
     * @return int[]|null
     */
    public static function dependencyOrder(array $fields, array $extraDeps = []): ?array
    {
        $byId = [];
        foreach ($fields as $f) {
            $id = (int) ($f['id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = $f;
            }
        }
        if ($byId === []) {
            return [];
        }

        $dependents = [];
        $indeg = array_fill_keys(array_keys($byId), 0);

        foreach ($fields as $f) {
            $fid = (int) ($f['id'] ?? 0);
            $deps = [];

            if (!empty($f['parent_id']) && isset($byId[(int) $f['parent_id']])) {
                $deps[] = (int) $f['parent_id'];
            }

            $logic = $f['logic'] ?? null;
            if (is_array($logic) && !empty($logic['rules']) && is_array($logic['rules'])) {
                foreach ($logic['rules'] as $rule) {
                    $tid = (int) ($rule['field_id'] ?? 0);
                    if ($tid > 0 && $tid !== $fid && isset($byId[$tid]) && !in_array($tid, $deps, true)) {
                        $deps[] = $tid;
                    }
                }
            }

            if (!empty($extraDeps[$fid])) {
                foreach ($extraDeps[$fid] as $depId) {
                    $depId = (int) $depId;
                    if ($depId > 0 && $depId !== $fid && isset($byId[$depId]) && !in_array($depId, $deps, true)) {
                        $deps[] = $depId;
                    }
                }
            }

            foreach ($deps as $dep) {
                if (!isset($dependents[$dep])) {
                    $dependents[$dep] = [];
                }
                $dependents[$dep][] = $fid;
                $indeg[$fid]++;
            }
        }

        $queue = [];
        foreach ($indeg as $id => $deg) {
            if ($deg === 0) {
                $queue[] = $id;
            }
        }

        $order = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            $order[] = $id;

            if (empty($dependents[$id])) {
                continue;
            }
            foreach ($dependents[$id] as $depId) {
                if (--$indeg[$depId] === 0) {
                    $queue[] = $depId;
                }
            }
        }

        return count($order) === count($byId) ? $order : null;
    }

    /**
     * Valida que la estructura tenga referencias consistentes y sin ciclos.
     */
    public static function validateStructure(array $fields, array $allowedTypes, array $answerableTypes): ?string
    {
        foreach ($fields as $f) {
            $type = (string) ($f['type'] ?? '');
            if (!in_array($type, $allowedTypes, true)) {
                return 'Tipo de campo no permitido: ' . $type;
            }

            $logic = $f['logic'] ?? null;
            if ($logic === null || $logic === []) {
                continue;
            }
            if (!is_array($logic) || !isset($logic['rules']) || !is_array($logic['rules'])) {
                return 'Condición inválida.';
            }
            if (isset($logic['operator'])) {
                $operator = $logic['operator'];
                if ($operator !== 'all' && $operator !== 'any') {
                    return 'Combinador de condición inválido.';
                }
            }
            if (count($logic['rules']) > 20) {
                return 'Demasiadas condiciones en un campo.';
            }
        }

        $order = self::dependencyOrder($fields);
        if ($order === null) {
            return 'La condición crea un ciclo de dependencias. Revisa las condiciones.';
        }

        return null;
    }

    private static function isEmpty($value): bool
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                if ($v !== '' && $v !== null && $v !== []) {
                    return false;
                }
            }
            return true;
        }
        return $value === null || trim((string) $value) === '';
    }

    private static function matches($value, string $expected): bool
    {
        if (is_array($value)) {
            return in_array($expected, array_values($value), true);
        }
        return (string) $value === $expected;
    }

    private static function contains($value, string $needle): bool
    {
        if (is_array($value)) {
            return in_array($needle, array_values($value), true);
        }
        return $needle === '' || str_contains((string) $value, $needle);
    }

    /**
     * Regla `matches` (P8): el valor del campo cumple el patrón regex.
     * Para checkbox (array) basta con que UNA opción coincida. Un patrón
     * inválido devuelve false y se loguea (fail-safe: nunca puede ocultar una
     * pregunta obligatoria por una expresión mal escrita).
     */
    private static function matchesRegex($value, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }
        // Delimitador @ + flags iu; se escapan arrobas internas antes de compilar.
        $regex = '@' . str_replace('@', '\@', $pattern) . '@iu';

        set_error_handler(static function (): bool {
            return true;
        });
        try {
            // Compilación: un patrón inválido falla incluso sobre cadena vacía.
            if (preg_match($regex, '') === false) {
                error_log('[LogicEvaluator] Patrón regex inválido en regla "matches": ' . $pattern);
                return false;
            }
            if (is_array($value)) {
                foreach (array_values($value) as $v) {
                    if (preg_match($regex, (string) $v) === 1) {
                        return true;
                    }
                }
                return false;
            }
            return preg_match($regex, (string) $value) === 1;
        } finally {
            restore_error_handler();
        }
    }

    private static function compareNumeric($value, string $expected): int
    {
        $a = trim((string) $value);
        $b = trim($expected);
        if (!is_numeric($a) || !is_numeric($b)) {
            return strcmp($a, $b) <=> 0;
        }
        return (float) $a <=> (float) $b;
    }
}