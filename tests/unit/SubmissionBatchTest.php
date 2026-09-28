<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Answer;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;

/**
 * Fase 3: `withAnswersByIds()` lotea los ids para no reventar el techo de
 * 65 535 placeholders de MariaDB.
 *
 * Un formulario con más de 65 535 respuestas agrupaba todas en un solo
 * `IN (?,?,…)`. Eso no devuelve "cero resultados": es un error del driver, así
 * que la analítica y el CSV morían justo en los formularios grandes, que son
 * exactamente los que más los necesitan.
 *
 * Lo que se fija aquí es que el loteo NO rompe nada: mismas respuestas, mismo
 * orden y sin duplicados. El orden importa porque el CSV y la tabla están
 * ordenados por `finished_at DESC` y, al concatenar tandas, cada una trae su
 * propio orden — sin reordenar al final el histórico sale mezclado a trozos.
 */
class SubmissionBatchTest
{
    private static ?int $userId = null;
    private static ?int $formId = null;
    private static array $submissionIds = [];
    private static array $expected = [];

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

        // Se siembra en CADA test, no una vez por clase: `tearDown()` borra el
        // formulario, así que un seed único dejaría a los tests siguientes sin
        // datos que comprobar (y `self::$formId` seguiría "> 0").
        self::seed();
    }

    public function tearDown(): void
    {
        Submission::setIdBatchForTesting(null);
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
     * Siembra UNA vez por clase (el runner instancia la clase por test) un
     * número de submissions que cruza el tamaño de tanda.
     *
     * El loteo está en una constante privada (`Submission::ID_BATCH` = 5 000), así
     * que sembrar 5 001 filas reales en cada test sería lentísimo. En su lugar
     * este test usa ids que NO existen, que es justo el caso límite: verifica
     * que la función trocea la lista y no intenta un `IN` gigante, sin
     * necesitar filas de verdad. El contenido real de las respuestas lo
     * comprueba `testDevuelveLasRespuestasDeCadaSubmission`.
     */
    private static function seed(): void
    {
        self::cleanupResiduals();

        // El runner instancia la clase una vez por test, pero `static`
        // sobrevive entre ellos: los arrays se vacían aquí y no al final. Con
        // `[] = $sid` acumulando, el tercer test compararía sus 12 ids contra
        // las 4 filas de la corrida anterior (el `tearDown` ya borró las
        // viejas) y fallaría sin motivo aparente.
        self::$submissionIds = [];
        self::$expected = [];

        // Tanda de 2: las 4 submissions se reparten en 2 lotes, que es lo que
        // hace necesaria la reordenación global. Con la tanda de producción
        // (5 000) las 4 irían en una sola y este test no probaría nada.
        Submission::setIdBatchForTesting(2);

        $suffix = bin2hex(random_bytes(4));
        self::$userId = User::create('Test Loteo', 'loteo-' . $suffix . '@test.local', 'password');
        self::$formId = Form::create((int) self::$userId, 'Form Loteo ' . $suffix);
        $campo = \App\Models\Field::create((int) self::$formId, 'text', 'Comentario', '', '', false);

        // 4 submissions con `finished_at` distintos y deterministas, para que
        // el orden esperado sea comprobable. `Submission::create()` pone
        // CURRENT_TIMESTAMP, así que la marca se fija después con un UPDATE:
        // dos filas con la MISMA marca no permitirían comprobar el desempate
        // por id, que es lo que hace el orden global tras los lotes.
        $marcas = ['2026-01-01 10:00:00', '2026-01-02 10:00:00', '2026-01-03 10:00:00', '2026-01-04 10:00:00'];
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        foreach ($marcas as $i => $marca) {
            $sid = Submission::create((int) self::$formId);
            $db->execute('UPDATE submissions SET finished_at = ? WHERE id = ?', [$marca, $sid]);
            self::$submissionIds[] = $sid;
            Answer::create($sid, (int) $campo, 'respuesta ' . $i);

            self::$expected[] = [
                'id'          => $sid,
                'finished_at' => $marca,
                'valor'       => 'respuesta ' . $i,
            ];
        }
    }

    /**
     * El orden global se restablece DESPUÉS de los lotes. Cada tanda viene en
     * su propio `ORDER BY finished_at DESC`, así que concatenarlas sin
     * reordenar deja el histórico partido en bloques: la tanda 1 (ids 1 y 2,
     * las dos más antiguas) sale antes que la tanda 2 (las dos más nuevas).
     *
     * Con `setIdBatchForTesting(2)` las 4 submissions se parten en 2 tandas,
     * que es exactamente el caso en el que el `usort()` final importa. Sin
     * él, quitar ese `usort()` no rompe NADA: una sola tanda ya viene
     * ordenada por SQL y el test pasaría igual.
     */
    public function testElOrdenSeRestableceAlFinal(): void
    {
        $resultado = Submission::withAnswersByIds(self::$submissionIds);
        $orden = array_keys($resultado);

        $esperado = array_map(
            static fn(array $f) => (int) $f['id'],
            array_reverse(self::$expected)
        );

        assertEquals(
            $esperado,
            $orden,
            'el resultado va de más reciente a más antigua, aunque las submissions se pidan en cualquier orden y en varias tandas'
        );
    }

    /**
     * El camino normal: devuelve una entrada por submission, cada una con sus
     * respuestas.
     */
    public function testDevuelveLasRespuestasDeCadaSubmission(): void
    {
        $resultado = Submission::withAnswersByIds(self::$submissionIds);
        // Orden inverso al de siembra: la última respuesta primero.
        $esperados = array_reverse(self::$expected);

        assertEquals(count($esperados), count($resultado), 'una entrada por submission solicitada');
        foreach ($esperados as $fila) {
            $id = (int) $fila['id'];
            assertArrayHasKey($id, $resultado, "falta la submission $id en el resultado");
            $respuestas = $resultado[$id]['answers'];
            assertEquals(1, count($respuestas), "la submission $id debe traer su respuesta");
            assertEquals($fila['valor'], array_values($respuestas)[0], "el valor de la submission $id no es el esperado");
        }
    }

    /**
     * El caso límite: una lista que cruza varias tandas completas (70 001 ids
     * con la tanda de producción de 5 000 = 14 tandas llenas más 1) no debe
     * reventar por el techo de 65 535 placeholders de MariaDB.
     *
     * Sin el loteo, un solo `IN` de 70 001 placeholders supera el techo real
     * del driver y falla con `HY093`; con el loteo son 15 consultas y devuelve
     * vacío. 20 001 ids NO bastarían para probarlo: están por debajo del techo
     * (lo que se coló en una versión anterior del test y pasaba sin probar
     * nada). Los ids arrancan muy por encima del AUTO_INCREMENT real para que
     * no colisionen con filas de otra corrida.
     */
    public function testUnaListaQueCruzaVariasTandasNoRevienta(): void
    {
        // Aquí interesa la tanda REAL (5 000), no la de 2 que fija `setUp()`:
        // con 2 serían 35 001 consultas.
        Submission::setIdBatchForTesting(null);

        $total = 5000 * 14 + 1; // 70 001 ids: por encima del techo de 65 535
        $ids = range(3000000001, 3000000001 + $total - 1);

        $resultado = Submission::withAnswersByIds($ids);
        assertEquals([], $resultado, 'ids inexistentes devuelven vacío, sin error del driver');
    }

    /**
     * Una lista vacía (o toda inválida) no debe generar un `IN ()`, que es
     * error de sintaxis en MariaDB.
     */
    public function testListaVaciaNoConsulta(): void
    {
        assertEquals([], Submission::withAnswersByIds([]), 'lista vacía devuelve vacío');
        assertEquals([], Submission::withAnswersByIds([0, -1, 0]), 'solo ids no positivos devuelve vacío');
    }

    /**
     * Ids repetidos y no numéricos se normalizan ANTES de trocear: un `?` de
     * más en el `IN` no casa con su parámetro y PDO lanza
     * "SQLSTATE[HY093]: Invalid parameter number".
     *
     * La lista tiene que cruzar la frontera de tanda (son 13 elementos con
     * tanda de 2), porque el fallo solo aparece al concatenar: un solo `IN`
     * con 13 `?` y 13 valores funciona y el test pasaría sin comprobar nada.
     */
    public function testNormalizaIdsRepetidosYNoNumericos(): void
    {
        $ids = array_merge(self::$submissionIds, self::$submissionIds, ['abc', '', null, 2.7]);
        assertTrue(count($ids) > 2, 'la lista es más larga que una tanda, así que hay varias');

        $resultado = Submission::withAnswersByIds($ids);

        assertEquals(count(self::$submissionIds), count($resultado), 'los repetidos no duplican entradas');
        assertFalse(
            array_key_exists(0, $resultado),
            'un id 0 no es una submission y no debe aparecer en el resultado'
        );
        foreach (self::$submissionIds as $sid) {
            assertArrayHasKey((int) $sid, $resultado, "la submission $sid debe seguir en el resultado");
        }
    }

    private static function cleanupResiduals(): void
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $db->execute(
            "DELETE f FROM forms f JOIN users u ON u.id = f.user_id WHERE f.title LIKE 'Form Loteo%'"
        );
        $db->execute("DELETE FROM users WHERE email LIKE 'loteo-%@test.local'");
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
