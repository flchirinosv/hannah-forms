<?php

declare(strict_types=1);

namespace App\Services;

/**
 * P8 — Autoevaluación (quiz). Corrección en el servidor usando respuestas ya
 * persistidas: stateless (sin columna de puntuación), reproducible en el
 * thanks vía el token de capacidad `?t=` y en futuras vistas de respuestas.
 *
 * Semántica por tipo:
 *  - select/radio:        igualdad exacta (trim).
 *  - checkbox:            igualdad de CONJUNTO (independiente del orden).
 *  - text/textarea/email/
 *    url/tel:             igualdad case-insensitive con trim.
 *  - number/nps/star/
 *    scale/range:         igualdad numérica.
 *  - matrix/ranking:      no tienen respuesta correcta: se EXCLUYEN del recuento.
 *  - section/page:        no se puntúan.
 *
 * La respuesta correcta vive en validations.correct (string, o array o string
 * JSON para checkbox). En el builder se edita con un input por pregunta.
 */
class QuizScore
{
    /**
     * Calcula la puntuación de una submission contra los campos del form.
     *
     * @param array $fields        campos del formulario (con 'id', 'type', 'validations')
     * @param array $answersById   respuestas: field_id => valor (string o array deserializado)
     * @return array{points: int, max: int, per: array<int, array{correct: bool, answer: mixed, expected: mixed}>}|null
     */
    public static function score(array $fields, array $answersById): ?array
    {
        $points = 0;
        $max = 0;
        $per = [];

        foreach ($fields as $f) {
            $id = (int) ($f['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $type = (string) ($f['type'] ?? '');
            if (self::scorable($type) === false) {
                continue;
            }

            $validations = $f['validations'] ?? [];
            if (!is_array($validations)) {
                continue;
            }
            $correct = $validations['correct'] ?? null;
            if ($correct === null || $correct === '') {
                continue;
            }

            $answer = $answersById[$id] ?? null;
            $ok = self::isCorrect($type, $answer, $correct);

            $max++;
            if ($ok) {
                $points++;
            }
            $per[$id] = [
                'correct'  => $ok,
                'answer'   => $answer,
                'expected' => $correct,
            ];
        }

        if ($max === 0) {
            return null;
        }

        return [
            'points' => $points,
            'max'    => $max,
            'per'    => $per,
        ];
    }

    /**
     * Tipos que admiten respuesta correcta.
     */
    private static function scorable(string $type): bool
    {
        // P10: un archivo no se puede comparar con una respuesta esperada.
        if (in_array($type, ['section', 'page', 'matrix', 'ranking', 'file'], true)) {
            return false;
        }
        return true;
    }

    /**
     * Compara una respuesta contra la esperada según el tipo de campo.
     */
    private static function isCorrect(string $type, $answer, $correct): bool
    {
        if (is_array($answer)) {
            // Checkbox: igualdad de conjunto con la esperada (coma o JSON).
            return self::setsEqual($answer, $correct);
        }

        if ($answer === null || $answer === '') {
            return false;
        }

        $expected = is_array($correct) ? ($correct[0] ?? '') : (string) $correct;

        if (self::isNumericType($type)) {
            return self::numbersEqual((string) $answer, $expected);
        }

        if (in_array($type, ['text', 'textarea', 'email', 'url', 'tel'], true)) {
            return mb_strtolower(trim((string) $answer)) === mb_strtolower(trim($expected));
        }

        // select/radio (y por defecto): igualdad exacta.
        return trim((string) $answer) === trim($expected);
    }

    private static function isNumericType(string $type): bool
    {
        return in_array($type, ['number', 'nps', 'star', 'scale', 'range'], true);
    }

    private static function numbersEqual(string $answer, string $expected): bool
    {
        if (!is_numeric($answer) || !is_numeric($expected)) {
            return false;
        }
        return (float) $answer === (float) $expected;
    }

    /**
     * Normaliza la respuesta correcta a lista: array, JSON array o coma.
     */
    private static function expectedSet($correct): array
    {
        if (is_array($correct)) {
            return array_values(array_map('strval', $correct));
        }
        $s = trim((string) $correct);
        if ($s === '') {
            return [];
        }
        if ($s[0] === '[') {
            $decoded = json_decode($s, true);
            if (is_array($decoded)) {
                return array_values(array_map('strval', $decoded));
            }
        }
        return array_values(array_filter(array_map('trim', explode(',', $s)), static fn ($v) => $v !== ''));
    }

    /**
     * Igualdad como conjuntos (sin importar orden). La respuesta (array) y lo
     * esperado se normalizan: trims y comparación exacta por opción.
     */
    private static function setsEqual(array $answer, $correct): bool
    {
        $got = array_values(array_map('trim', array_map('strval', $answer)));
        $want = self::expectedSet($correct);
        sort($got);
        sort($want);
        return $got === $want;
    }
}