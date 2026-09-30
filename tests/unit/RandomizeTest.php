<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FillController;

/**
 * P8 — Randomización de render (solo visual, nunca afecta al submit).
 *
 * randomizeFields() baraja unidades de preguntas dentro de cada segmento de
 * página y, por campo (validations.randomize), las opciones de
 * select/radio/checkbox. Las páginas anclan su segmento y las secciones se
 * mueven junto a sus hijos. Los tests son del tipo "invariantes": mismos ids,
 * orden de pages intacto, subtree contiguo — y "ha cambiado en alguna de N
 * pasadas" (aleatorio pero con probabilidad de flake despreciable).
 */
class RandomizeTest
{
    public function testBarajarUnidadesConservaIdsOrdenDePaginasYSubtree(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'page', 'parent_id' => null, 'logic' => null, 'options' => [], 'validations' => []],
            ['id' => 2, 'type' => 'text', 'parent_id' => null, 'logic' => null, 'options' => [], 'validations' => []],
            ['id' => 3, 'type' => 'section', 'parent_id' => null, 'logic' => null, 'options' => [], 'validations' => []],
            ['id' => 4, 'type' => 'text', 'parent_id' => 3, 'logic' => null, 'options' => [], 'validations' => []],
            ['id' => 5, 'type' => 'page', 'parent_id' => null, 'logic' => null, 'options' => [], 'validations' => []],
            ['id' => 6, 'type' => 'radio', 'parent_id' => null, 'logic' => null, 'options' => [], 'validations' => []],
        ];
        $settings = ['randomize' => ['questions' => true]];

        $seen = [];
        for ($i = 0; $i < 30; $i++) {
            $shuffled = FillController::randomizeFields($fields, $settings);
            assertSame(6, count($shuffled));

            $ids = array_map(static fn ($f) => (int) $f['id'], $shuffled);
            $seen[implode(',', $ids)] = true;

            // Mismos ids (sin pérdidas/duplicados): al ordenar deben ser exactamente 1..6.
            sort($ids);
            assertSame([1, 2, 3, 4, 5, 6], $ids, 'ids ordenados paso ' . $i);

            // El orden relativo de las páginas nunca cambia.
            $idxPage1 = array_search(1, array_map('intval', array_column($shuffled, 'id')), true);
            $idxPage2 = array_search(5, array_map('intval', array_column($shuffled, 'id')), true);
            assertTrue($idxPage1 < $idxPage2, 'page1 siempre antes que page2');

            // El hijo de la sección queda contiguo a su padre.
            $idxSec = array_search(3, array_map('intval', array_column($shuffled, 'id')), true);
            $idxChild = array_search(4, array_map('intval', array_column($shuffled, 'id')), true);
            assertSame($idxSec + 1, $idxChild, 'la sección se mueve con su hijo');

            // page1 ancla su segmento: sus preguntas quedan entre las páginas.
            foreach ($shuffled as $pos => $f) {
                if ((int) $f['id'] === 2 || (int) $f['id'] === 3) {
                    assertTrue($pos > $idxPage1 && $pos < $idxPage2, 'las preguntas del paso 1 van entre los dos pages');
                }
            }
        }

        assertTrue(count($seen) >= 2, 'debe haber al menos 2 ordenes distintos en 30 pasadas');
    }

    public function testSinRandomizeNoCambiaNada(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'parent_id' => null, 'logic' => null, 'options' => [], 'validations' => []],
            ['id' => 2, 'type' => 'section', 'parent_id' => null, 'logic' => null, 'options' => [], 'validations' => []],
            ['id' => 3, 'type' => 'radio', 'parent_id' => 2, 'logic' => null, 'options' => [['value' => 'a'], ['value' => 'b']], 'validations' => []],
        ];
        $out = FillController::randomizeFields($fields, []);
        assertSame(['1', '2', '3'], array_map(static fn ($f) => (string) $f['id'], $out));
        assertSame(['a', 'b'], array_column($out[2]['options'], 'value'));
    }

    public function testBarajarOpcionesConservaParesYValores(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'radio', 'parent_id' => null, 'logic' => null, 'options' => [
                ['value' => 'a', 'label' => 'Op A'],
                ['value' => 'b', 'label' => 'Op B'],
                ['value' => 'c', 'label' => 'Op C'],
                ['value' => 'd', 'label' => 'Op D'],
            ], 'validations' => ['randomize' => true]],
        ];
        $settings = [];

        $origOrder = ['a', 'b', 'c', 'd'];
        $diffSeen = false;
        for ($i = 0; $i < 40; $i++) {
            $out = FillController::randomizeFields($fields, $settings);
            $opts = $out[0]['options'];
            assertSame(4, count($opts));

            $values = array_column($opts, 'value');
            sort($values);
            assertSame(['a', 'b', 'c', 'd'], $values, 'mismo conjunto de opciones');

            // El par value/label se conserva en cada opción.
            foreach ($opts as $o) {
                assertSame('Op ' . strtoupper((string) $o['value']), $o['label']);
            }

            if (array_column($out[0]['options'], 'value') !== $origOrder) {
                $diffSeen = true;
            }
        }

        assertTrue($diffSeen, 'con 4 opciones alguna pasada deberia diferir del orden original');
    }

    public function testBarajarOpcionesNoTocaOpccionUnica(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'select', 'parent_id' => null, 'logic' => null, 'options' => [['value' => 'a']], 'validations' => ['randomize' => true]],
        ];
        $out = FillController::randomizeFields($fields, []);
        assertSame('a', $out[0]['options'][0]['value']);
    }

    public function testBarajarOpcionesSoloEnTiposDeOpcion(): void
    {
        $fields = [
            ['id' => 1, 'type' => 'text', 'parent_id' => null, 'logic' => null, 'options' => [], 'validations' => ['randomize' => true]],
            ['id' => 2, 'type' => 'matrix', 'parent_id' => null, 'logic' => null, 'options' => [['value' => 'x'], ['value' => 'y']], 'validations' => ['randomize' => true]],
        ];
        // Sin randomize.questions, los text/matrix no barajan.
        $out = FillController::randomizeFields($fields, []);
        assertSame('text', $out[0]['type']);
        assertSame('x', $out[1]['options'][0]['value']);
    }
}