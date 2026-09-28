<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\FieldAggregator;

/**
 * Fase 3: la agregación en dos consultas fijas tiene que devolver EXACTAMENTE
 * lo mismo que los métodos por campo (`countByFieldInForm`, `numberStatsInForm`,
 * `npsStatsInForm`, `matrixStatsInForm`, `rankingStatsInForm`,
 * `countsCheckboxInForm`, `nonEmptyInForm`).
 *
 * Este test es la red de seguridad del refactor. La agregación nueva calcula en
 * PHP sobre filas agrupadas por `(field_id, value)`, así que el fallo que
 * busca no es una excepción: es un número plausible pero equivocado — el
 * clásico "olvidé multiplicar por c" que devuelve el promedio de una sola
 * respuesta repetida, o un NPS que sale 100 porque contó una fila y no diez.
 * Por eso compara contra los métodos por campo en vez de contra valores
 * escritos a mano.
 *
 * Crea datos temporales y se limpia en `tearDown()`.
 */
class AnalyticsAggregationTest
{
    private static ?int $userId = null;
    private static ?int $formId = null;
    private static array $fieldIds = [];
    private static array $submissionIds = [];

    private static bool $available = true;
    private static bool $envLoaded = false;

    public function setUp(): void
    {
        self::loadEnv();
        try {
            $db = \App\Core\Database::getInstance(\App\Core\Config::db());
            $db->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            self::$available = false;
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::cleanupResiduals();

        $suffix = bin2hex(random_bytes(4));
        self::$userId = User::create('Test Agregación', 'agrega-' . $suffix . '@test.local', 'password');
        self::$formId = Form::create((int) self::$userId, 'Form Agregación ' . $suffix);

        // Un campo de cada tipo con agregación propia en la analítica.
        self::$fieldIds['select'] = Field::create((int) self::$formId, 'select', 'País', '', '', false, [
            ['value' => 'MX', 'label' => 'México'],
            ['value' => 'AR', 'label' => 'Argentina'],
            ['value' => 'CL', 'label' => 'Chile'],
        ]);
        self::$fieldIds['radio'] = Field::create((int) self::$formId, 'radio', 'Plan', '', '', false, [
            ['value' => 'gratis', 'label' => 'Gratis'],
            ['value' => 'pago', 'label' => 'Pago'],
        ]);
        self::$fieldIds['checkbox'] = Field::create((int) self::$formId, 'checkbox', 'Intereses', '', '', false, [
            ['value' => 'php', 'label' => 'PHP'],
            ['value' => 'js', 'label' => 'JS'],
            ['value' => 'sql', 'label' => 'SQL'],
        ]);
        self::$fieldIds['number'] = Field::create((int) self::$formId, 'number', 'Edad', '', '', true);
        self::$fieldIds['text'] = Field::create((int) self::$formId, 'text', 'Comentario');
        self::$fieldIds['nps'] = Field::create((int) self::$formId, 'nps', 'Recomendaría', '', '', false);
        self::$fieldIds['matrix'] = Field::create((int) self::$formId, 'matrix', 'Satisfacción', '', '', false, [
            ['value' => 'velocidad', 'label' => 'Velocidad', 'group' => 'rows'],
            ['value' => 'precio', 'label' => 'Precio', 'group' => 'rows'],
            ['value' => 'bien', 'label' => 'Bien', 'group' => 'cols'],
            ['value' => 'mal', 'label' => 'Mal', 'group' => 'cols'],
        ]);
        self::$fieldIds['ranking'] = Field::create((int) self::$formId, 'ranking', 'Ordena', '', '', false, [
            ['value' => 'uno', 'label' => 'Uno'],
            ['value' => 'dos', 'label' => 'Dos'],
            ['value' => 'tres', 'label' => 'Tres'],
        ]);
    }

    public function tearDown(): void
    {
        if (!self::$available) {
            return;
        }
        try {
            self::cleanupResiduals();
        } catch (\Throwable $e) {
            // Nunca tumbar el runner: el informe de la corrida va primero.
        }
    }

    /**
     * Siembra respuestas designed para que CUALQUIER error de agregación se
     * note: valores repetidos (para cazar un `* $c` olvidado), duplicados
     * exactos (mismo `value` en varias filas, que es lo que la consulta
     * agrupada colapsa en una), respuestas vacías y no numéricas en un campo
     * numérico, y una matriz/ranking con etiquetas que ya no existen en las
     * opciones (quedan huérfanas al editar el campo después de recibir datos).
     */
    private function seed(): void
    {
        $f = self::$fieldIds;
        $crear = function (array $valores) use ($f): void {
            $sid = Submission::create((int) self::$formId);
            self::$submissionIds[] = $sid;
            foreach ($valores as $fieldId => $value) {
                if ($value === null) {
                    continue;
                }
                \App\Models\Answer::create($sid, (int) $f[$fieldId], (string) $value);
            }
        };

        // 'MX' sale 4 veces (las 3 primeras más la duplicada), 'CL' 2 y 'AR' 1.
        // Los valores repetidos a propósito son los que delatan un `* $c`
        // olvidado: sin multiplicar, "MX" valdría 1 en vez de 4.
        $crear(['select' => 'MX', 'radio' => 'gratis', 'checkbox' => '["php","js"]', 'number' => '10', 'text' => 'ok', 'nps' => '10']);
        $crear(['select' => 'MX', 'radio' => 'gratis', 'checkbox' => '["php"]', 'number' => '20', 'text' => '', 'nps' => '9']);
        $crear(['select' => 'MX', 'radio' => 'pago', 'checkbox' => '["js","sql"]', 'number' => '30', 'nps' => '7']);
        // Duplicado EXACTO de la fila 1: la consulta agrupada lo colapsa y el
        // conteo tiene que resuscitationarse con c=2, no quedarse en 1.
        $crear(['select' => 'MX', 'radio' => 'gratis', 'checkbox' => '["php","js"]', 'number' => '10', 'text' => 'ok', 'nps' => '10']);
        // Un campo numérico con basura: el avg NO debe contar esto.
        $crear(['select' => 'AR', 'radio' => 'pago', 'checkbox' => '[]', 'number' => 'N/A', 'text' => '   ', 'nps' => '3']);
        $crear(['select' => 'CL', 'radio' => 'pago', 'checkbox' => '["php"]', 'number' => '40', 'nps' => '8']);
        $crear(['select' => 'CL', 'radio' => 'gratis', 'checkbox' => '[]', 'number' => '50', 'nps' => '0']);
        // Respuestas de UNA cifra junto a las de dos: 'a.value' es TEXT, así
        // que un MIN/MAX que comparara como cadena daría min=10/max=9. Estas
        // dos filas son las que delatan esa comparación.
        $crear(['select' => 'CL', 'radio' => 'pago', 'checkbox' => '["php"]', 'number' => '9', 'nps' => '5']);
        $crear(['select' => 'AR', 'radio' => 'pago', 'checkbox' => '[]', 'number' => '7', 'nps' => '6']);

        // Matrices y rankings, con una etiqueta que ya no está en las opciones.
        $crear([
            'matrix'  => '{"velocidad":"bien","precio":"mal"}',
            'ranking' => '{"uno":1,"dos":2,"tres":3}',
        ]);
        $crear([
            'matrix'  => '{"velocidad":"bien","precio":"bien"}',
            'ranking' => '{"uno":2,"dos":1,"tres":3}',
        ]);
        // 'borrada' ya no es una opción de la matriz ni del ranking: su
        // celda no debe aparecer en los conteos.
        $crear([
            'matrix'  => '{"velocidad":"borrada","precio":"bien"}',
            'ranking' => '{"uno":1,"dos":3,"tres":2,"borrada":4}',
        ]);
        $crear([
            'matrix'  => '{"velocidad":"mal","precio":"bien"}',
            'ranking' => '{"uno":3,"dos":1,"tres":2}',
        ]);
        // Duplicado EXACTO de la fila anterior. La consulta agrupada colapsa
        // estas dos en una (`c = 2`), así que TODO lo que se sume a partir de
        // aquí tiene que multiplicar por c: sin eso el `total` de la matriz,
        // los promedios del ranking y las celdas salen a la mitad.
        $crear([
            'matrix'  => '{"velocidad":"mal","precio":"bien"}',
            'ranking' => '{"uno":3,"dos":1,"tres":2}',
        ]);
    }

    /**
     * Ejecuta la agregación nueva sobre el formulario sembrado.
     */
    private function agregar(): array
    {
        $form = Form::find((int) self::$formId);
        $campos = array_values(array_filter(
            $form['fields'],
            static fn($f) => ($f['type'] ?? '') !== 'section'
        ));
        $ids = array_map(static fn($f) => (int) $f['id'], $campos);
        $total = count(Submission::byFormFiltered((int) self::$formId));

        return FieldAggregator::forForm((int) self::$formId, $campos, $ids, $total);
    }

    public function testCoincideConLosMetodosPorCampo(): void
    {
        $this->seed();
        $nuevo = $this->agregar();
        $formId = (int) self::$formId;
        $f = self::$fieldIds;

        // select
        assertEquals(
            \App\Models\Answer::countByFieldInForm($formId, (int) $f['select']),
            $nuevo[(int) $f['select']]['values'],
            'select: el conteo por valor debe coincidir con countByFieldInForm'
        );

        // radio
        assertEquals(
            \App\Models\Answer::countByFieldInForm($formId, (int) $f['radio']),
            $nuevo[(int) $f['radio']]['values'],
            'radio: el conteo por valor debe coincidir con countByFieldInForm'
        );

        // checkbox (descompone JSON)
        assertEquals(
            \App\Models\Answer::countsCheckboxInForm($formId, (int) $f['checkbox']),
            $nuevo[(int) $f['checkbox']]['values'],
            'checkbox: la descomposición JSON debe coincidir con countsCheckboxInForm'
        );

        // number
        assertEquals(
            \App\Models\Answer::numberStatsInForm($formId, (int) $f['number']),
            $nuevo[(int) $f['number']]['values'],
            'number: count/avg/min/max deben coincidir con numberStatsInForm'
        );

        // nps: valores y score complementario
        assertEquals(
            \App\Models\Answer::npsStatsInForm($formId, (int) $f['nps']),
            $nuevo[(int) $f['nps']]['nps'],
            'nps: promotores/pasivos/detractores/score deben coincidir con npsStatsInForm'
        );

        // matrix
        assertEquals(
            \App\Models\Answer::matrixStatsInForm(
                $formId,
                (int) $f['matrix'],
                ['velocidad', 'precio'],
                ['bien', 'mal']
            ),
            $nuevo[(int) $f['matrix']]['values'],
            'matrix: celdas por fila/columna deben coincidir con matrixStatsInForm'
        );

        // ranking
        assertEquals(
            \App\Models\Answer::rankingStatsInForm($formId, (int) $f['ranking'], ['uno', 'dos', 'tres']),
            $nuevo[(int) $f['ranking']]['values'],
            'ranking: el promedio de posición debe coincidir con rankingStatsInForm'
        );

        // non_empty de TODOS los campos
        foreach ($f as $key => $fieldId) {
            assertEquals(
                \App\Models\Answer::nonEmptyInForm($formId, (int) $fieldId),
                $nuevo[(int) $fieldId]['non_empty'],
                "non_empty de '$key' debe coincidir con nonEmptyInForm"
            );
        }

        // 'count' es el total de submissions del formulario, no del campo.
        foreach ($nuevo as $fieldId => $stat) {
            assertEquals(count(Submission::byFormFiltered($formId)), $stat['count'], 'count debe ser el total del formulario');
        }
    }

    /**
     * Un formulario con respuestas duplicadas exactas es el caso donde "olvidé
     * multiplicar por c" NO se ve en la forma final: los conteos por valor
     * pueden cuadrar mientras la media, el NPS o las celdas de la matriz
     * salen mal. Estos asserts miran los números concretos.
     */
    public function testLosDuplicadosNoPierdenRespuestas(): void
    {
        $this->seed();
        $nuevo = $this->agregar();
        $f = self::$fieldIds;

        // 'MX' aparece 4 veces, 'CL' 3 y 'AR' 2.
        $porValor = [];
        foreach ($nuevo[(int) $f['select']]['values'] as $row) {
            $porValor[$row['value']] = $row['c'];
        }
        assertEquals(4, $porValor['MX'] ?? 0, 'MX debe contar 4 respuestas');
        assertEquals(3, $porValor['CL'] ?? 0, 'CL debe contar 3 respuestas');
        assertEquals(2, $porValor['AR'] ?? 0, 'AR debe contar 2 respuestas');

        // Números: 10,20,30,10,N/A,40,50,9,7 -> 8 numéricos, suma 176,
        // promedio 22.00, mínimo 7 y máximo 50. El 'N/A' NO entra (el regex
        // es el del SQL) y los dos '10' cuentan.
        $num = $nuevo[(int) $f['number']]['values'];
        assertEquals(8, (int) $num['answer_count'], 'solo los 8 valores numéricos cuentan');
        assertEquals(22.0, (float) $num['avg_value'], 'el promedio debe ignorar "N/A" y contar los dos 10');
        assertEquals(7.0, (float) $num['min_value'], 'el mínimo es 7: comparar "7" con "10" como texto daría 10');
        assertEquals(50.0, (float) $num['max_value'], 'el máximo es 50: como texto "9" ganaría a "50"');

        // NPS: 10,9,7,10,3,8,0,5,6 -> 3 promotores (los dos 10 y el 9), 2
        // pasivos (7, 8) y 4 detractores (3, 0, 5, 6).
        // nps = (3-4)/9*100 = -11.1 -> -11.
        $nps = $nuevo[(int) $f['nps']]['nps'];
        assertEquals(3, $nps['promoters'], 'tres promotores: los dos 10 y el 9');
        assertEquals(2, $nps['passives'], 'dos pasivos (7 y 8)');
        assertEquals(4, $nps['detractors'], 'cuatro detractores (3, 0, 5 y 6)');
        assertEquals(9, $nps['answer_count'], 'el total NPS son las 9 respuestas');
        assertEquals(-11, $nps['nps'], 'el score NPS sale de (promotores - detractores) / total');

        // Checkbox: php en 5 (filas 1,2,4,6,8), js en 3 (1,3,4), sql en 1 (3).
        $cb = [];
        foreach ($nuevo[(int) $f['checkbox']]['values'] as $row) {
            $cb[$row['value']] = $row['c'];
        }
        assertEquals(5, $cb['php'] ?? 0, 'php fue elegido 5 veces');
        assertEquals(3, $cb['js'] ?? 0, 'js fue elegido 3 veces');
        assertEquals(1, $cb['sql'] ?? 0, 'sql fue elegido 1 vez');

        // Matriz: 5 respuestas (las dos últimas son duplicado exacto, así que
        // la consulta agrupada las ve como `c = 2`).
        //   velocidad = bien x2, mal x2, borrada x1 (huérfana: no cuenta)
        //   precio    = bien x4, mal x1
        $mx = $nuevo[(int) $f['matrix']]['values'];
        assertEquals(5, $mx['total'], 'las 5 filas contestaron la matriz (la duplicada cuenta dos)');
        assertEquals(2, $mx['matrix']['velocidad']['bien'], 'velocidad=bien en 2');
        assertEquals(2, $mx['matrix']['velocidad']['mal'], 'velocidad=mal en 2 (una viene del duplicado)');
        assertEquals(4, $mx['matrix']['velocidad']['_total'], 'velocidad acumula 4; la celda borrada no suma');
        assertEquals(4, $mx['matrix']['precio']['bien'], 'precio=bien en 4');
        assertEquals(1, $mx['matrix']['precio']['mal'], 'precio=mal en 1');
        assertEquals(5, $mx['matrix']['precio']['_total'], 'precio acumula 5');
        // colCounts: bien = velocidad(2) + precio(4) = 6; mal = 2 + 1 = 3.
        // La etiqueta 'borrada' no está entre las opciones, así que no inventa
        // columna ni suma en 'bien'.
        assertEquals(6, $mx['colCounts']['bien'], 'colCounts[bien] suma las celdas conocidas de las dos filas');
        assertEquals(3, $mx['colCounts']['mal'], 'colCounts[mal] suma velocidad=mal y precio=mal');
        assertFalse(
            array_key_exists('borrada', $mx['colCounts']),
            'una etiqueta que ya no es opción no debe aparecer como columna'
        );

        // Ranking: 'uno' = 1+2+1+3+3 = 10 en 5 respuestas -> 2.00.
        // 'dos' = 2+1+3+1+1 = 8 en 5 -> 1.60. 'tres' = 3+3+2+2+2 = 12 en 5 -> 2.40.
        $rk = [];
        foreach ($nuevo[(int) $f['ranking']]['values'] as $row) {
            $rk[$row['value']] = $row;
        }
        assertEquals(5, $rk['uno']['c'], "'uno' lo eligieron las 5 respuestas");
        assertEquals(2.0, (float) $rk['uno']['avg'], "el promedio de 'uno' es 2.00");
        assertEquals(5, $rk['dos']['c'], "'dos' lo eligieron las 5 respuestas");
        assertEquals(1.6, (float) $rk['dos']['avg'], "el promedio de 'dos' es 1.60");
        assertEquals(5, $rk['tres']['c'], "'tres' lo eligieron las 5 respuestas");
        assertEquals(2.4, (float) $rk['tres']['avg'], "el promedio de 'tres' es 2.40");
        assertFalse(
            array_key_exists('borrada', $rk),
            "una opción que ya no existe ('borrada') no debe aparecer en el ranking"
        );
    }

    /**
     * El filtro de fechas tiene que recortar la agregación, no solo la tabla.
     * Y el recorte es por DÍA LOCAL (Caracas), no por día UTC: un filtro de un
     * solo día tiene que devolver lo mismo que suma ese día local.
     */
    public function testElFiltroDeFechasRecortaLaAgregacion(): void
    {
        $this->seed();

        // El día UTC/local de la última submission sembrada.
        $ultima = (int) array_slice(Submission::byFormFiltered((int) self::$formId), 0, 1)[0]['id'];
        $fila = \App\Core\Database::getInstance(\App\Core\Config::db())
            ->fetch('SELECT finished_at FROM submissions WHERE id = ?', [$ultima]);
        $diaLocal = \App\Core\Clock::toDisplay((string) $fila['finished_at'])->format('Y-m-d');

        $stats = [];
        $form = Form::find((int) self::$formId);
        $campos = array_values(array_filter($form['fields'], static fn($f) => ($f['type'] ?? '') !== 'section'));
        $ids = array_map(static fn($f) => (int) $f['id'], $campos);
        $total = count(Submission::byFormFiltered((int) self::$formId, $diaLocal, $diaLocal));
        $stats = FieldAggregator::forForm((int) self::$formId, $campos, $ids, $total, $diaLocal, $diaLocal);

        // Ese día solo hay 1 submission (la última sembrada), así que sus
        // respuestas son las suyas y las demás quedan fuera.
        $formId = (int) self::$formId;
        assertEquals(
            \App\Models\Answer::countByFieldInForm($formId, (int) self::$fieldIds['select'], $diaLocal, $diaLocal),
            $stats[(int) self::$fieldIds['select']]['values'],
            'con filtro de un día, select debe coincidir con el método por campo'
        );
        assertEquals(
            \App\Models\Answer::numberStatsInForm($formId, (int) self::$fieldIds['number'], $diaLocal, $diaLocal),
            $stats[(int) self::$fieldIds['number']]['values'],
            'con filtro de un día, number debe coincidir con el método por campo'
        );

        // Un rango vacío (día pasado) no debe devolver nada.
        $vacio = FieldAggregator::forForm($formId, $campos, $ids, 0, '2000-01-01', '2000-01-01');
        foreach ($vacio as $fieldId => $stat) {
            assertEquals(0, $stat['non_empty'], "un rango sin submissions da non_empty 0 en el campo $fieldId");
        }
    }

    /**
     * El filtro `from`/`to` recorta por DÍA LOCAL, no por día UTC.
     *
     * `a.value`/`finished_at` viven en UTC y el filtro es el día que eligió
     * quien mira. En Caracas (UTC-4) una respuesta de las 02:00 UTC del día 10
     * son las 22:00 del día 9: pertenece al día 9. Con el patrón ingenuo
     * (`>= '2026-03-09 00:00:00' AND <= '2026-03-09 23:59:59'`, en UTC) esa
     * fila se cae del día 9 y aparece en el 10.
     *
     * Este test siembra justo una fila en esa franja: es el único caso en el
     * que la diferencia se nota, y sin él el fix de `Clock::dayStartUtc()`
     * podría revertirse sin que nadie se entere.
     */
    public function testElFiltroRecortaPorDiaLocalNoPorDiaUtc(): void
    {
        $this->seed();

        // Las submissions de `seed()` se sembraron "ahora", así que se añade
        // una explícitamente en la frontera: 02:00 UTC del día 10 = 22:00
        // del día 9 en Caracas.
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $sid = Submission::create((int) self::$formId);
        $db->execute('UPDATE submissions SET finished_at = ? WHERE id = ?', ['2026-03-10 02:00:00', $sid]);
        $campo = (int) self::$fieldIds['select'];
        \App\Models\Answer::create($sid, $campo, 'AR');

        $form = Form::find((int) self::$formId);
        $campos = array_values(array_filter($form['fields'], static fn($f) => ($f['type'] ?? '') !== 'section'));
        $ids = array_map(static fn($f) => (int) $f['id'], $campos);
        $formId = (int) self::$formId;

        $nueve  = FieldAggregator::forForm($formId, $campos, $ids, 0, '2026-03-09', '2026-03-09');
        $diez   = FieldAggregator::forForm($formId, $campos, $ids, 0, '2026-03-10', '2026-03-10');

        $porValor = static function (array $stats, int $campoId): array {
            $out = [];
            foreach ($stats[$campoId]['values'] as $row) {
                $out[$row['value']] = $row['c'];
            }
            return $out;
        };

        assertEquals(
            1,
            $porValor($nueve, $campo)['AR'] ?? 0,
            "la respuesta de las 02:00 UTC del día 10 es del día 9 en Caracas, así que cuenta en el 9"
        );
        assertEquals(
            0,
            $porValor($diez, $campo)['AR'] ?? 0,
            'y NO debe contar en el día 10'
        );

        // El método por campo (usado por otras pantallas) recorta igual.
        assertEquals(
            \App\Models\Answer::countByFieldInForm($formId, $campo, '2026-03-09', '2026-03-09'),
            $nueve[$campo]['values'],
            'el método por campo usa los mismos límites de día local'
        );
    }

    /**
     * Sin campos (formulario recién creado, o uno al que solo le quedan
     * secciones) no debe consultar nada ni reventar por un `IN ()` vacío.
     */
    public function testSinCamposNoConsultaNiFalla(): void
    {
        $vacio = FieldAggregator::forForm((int) self::$formId, [], [], 0);
        assertEquals([], $vacio, 'sin campos no hay estadísticas');
    }

    /**
     * `validDay()` tiene que rechazar fechas imposibles y aceptar las reales.
     * `createFromFormat('Y-m-d', '2026-02-31')` devuelve un objeto (31 de
     * marzo) en vez de fallar, así que un filtro con esa fecha se convertiría
     * en un periodo que el usuario nunca pidió.
     */
    public function testValidDayRechazaFechasImposibles(): void
    {
        assertEquals('2026-09-28', FieldAggregator::validDay(' 2026-09-28 '), 'una fecha real pasa (y se recorta)');
        assertEquals('', FieldAggregator::validDay('2026-02-31'), '31 de febrero no es una fecha');
        assertEquals('', FieldAggregator::validDay('2026-13-01'), 'mes 13 no existe');
        assertEquals('', FieldAggregator::validDay(''), 'vacío no es un día');
        assertEquals('', FieldAggregator::validDay('ayer'), 'una palabra no es un día');
        assertEquals('', FieldAggregator::validDay('28/09/2026'), 'otro formato no es un día');
    }

    /**
     * Borra formularios de test residuales (título "Form Agregación") de
     * corridas previas. Se invoca al inicio de cada setUp.
     */
    private static function cleanupResiduals(): void
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $db->execute(
            "DELETE f FROM forms f JOIN users u ON u.id = f.user_id WHERE f.title LIKE 'Form Agregación%'"
        );
        $db->execute("DELETE FROM users WHERE email LIKE 'agrega-%@test.local'");
    }

    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }
        self::$envLoaded = true;
        $root = dirname(__DIR__, 2);
        if (is_file($root . '/.env')) {
            \App\Core\Env::load($root . '/.env');
        }
    }
}
