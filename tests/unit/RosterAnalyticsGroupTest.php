<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Controllers\AnalyticsController;
use App\Models\Answer;
use App\Models\Field;
use App\Models\Form;
use App\Models\FormParticipant;
use App\Models\Submission;
use App\Models\User;
use App\Services\FieldAggregator;
use App\Services\Pii;

/**
 * Fase 8 — Comparar la analítica por un atributo del listado.
 *
 * La fase 7 dejó el filtro (una fila = un valor). Esta es la pregunta contraria:
 * **¿se parece la gente de cada grupo?** Y esa pregunta solo vale si el número que
 * se pinta es el de ese grupo, lo que da tres propiedades que se atan aquí y que
 * se rompen en silencio (no con un error: con un número plausible y equivocado):
 *
 *  1. **El techo.** El atributo lo define el dueño y llega de un `.xlsx`, así que
 *     «Sede» puede tener 4 valores o 800. Sin tope, la comparación es una tabla de
 *     800 columnas y las dos consultas del agregador se comen la memoria. Se
 *     comprueba con 20 valores distintos: 8 columnas con nombre + «Resto».
 *
 *  2. **El denominador.** El porcentaje de la opción más repetida se calcula sobre
 *     las respuestas del grupo, no sobre las que han sobrevivido al recorte. Con un
 *     recorte, la misma celda daría «40 %» donde la verdad es «17 %», y dos
 *     grupos con la misma proporción se verían distintos.
 *
 *  3. **Las columnas se eligen antes de consultar.** `FieldAggregator` hace DOS
 *     consultas fijas; si el techo de filas se aplicara después, serían dos
 *     consultas de 200 ids. Y las preguntas que no se van a pintar no se
 *     preguntan.
 *
 * Y el reparto de tipos es una decisión, no un olvido: `checkbox`, `matrix` y
 * `ranking` guardan un JSON con la respuesta entera, así que «el valor más
 * repetido» de una casilla de opción múltiple es la combinación, no lo que la
 * gente eligió. También está el caso PII: una comparación que no respeta
 * `responses.pii` sería el canal limpio que el resto de la analítica tapa.
 */
class RosterAnalyticsGroupTest
{
    private static bool $cleanupHookRegistered = false;
    private static ?int $ownerId = null;
    private static ?int $formId = null;
    private static ?string $sedeKey = null;
    /** @var int[] */
    private static array $campoIds = [];

    public function setUp(): void
    {
        self::ensureCleanupHook();
        self::cleanupResiduals();

        $rand = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Roster Group ' . $rand, 'rostergrp-' . $rand . '@test.local', 'clave-larga-123', 2);
        self::$formId  = Form::create((int) self::$ownerId, 'Roster Group ' . $rand);
        self::$sedeKey = self::crearSede((int) self::$formId, 'Sede');
        self::$campoIds = [];

        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
    }

    public function tearDown(): void
    {
        Auth::forgetCache();
        unset($_GET, $_POST, $_FILES);
        Session::remove('user_id');
        Response::setRecordHalt(false);
        self::cleanupResiduals();
    }

    // =====================================================================
    // 1. El techo de columnas
    // =====================================================================

    public function testLasColumnasSonLosValoresMasFrecuentesYElResto(): void
    {
        $num = self::campo('number', 'Edad');
        // Nueve sedes distintas: ocho con columna y la novena al «resto». Con
        // menos, el resto sería 0 y no se pintaría (que es otro test).
        self::persona('V-1', 'Norte', [$num => [7]]);
        self::persona('V-2', 'Norte', [$num => [8]]);
        self::persona('V-3', 'Norte', [$num => [9]]);
        self::persona('V-4', 'Sur', [$num => [4]]);
        self::persona('V-5', 'Sur', [$num => [5]]);
        // Siete sedes de una persona: 3 + 2 + 7 = 9 distintas en total.
        foreach (['Este', 'Oeste', 'Centro', 'Oficina', 'Lima', 'Cali', 'Medellín'] as $i => $sede) {
            self::persona('V-' . (6 + $i), $sede, [$num => [(string) (6 - $i)]]);
        }

        $grupos = FieldAggregator::gruposDeAtributo((int) self::$formId, (string) self::$sedeKey);

        assertSame(FieldAggregator::GRUPOS_MAX + 1, count($grupos), 'ocho columnas con nombre y el resto');
        assertSame('Norte', $grupos[0]['clave'], 'la más frecuente va primera');
        assertSame(3, $grupos[0]['n'], 'con la gente que la tiene');
        assertSame('Sur', $grupos[1]['clave'], 'luego la segunda');
        assertSame(2, $grupos[1]['n']);
        assertSame(FieldAggregator::CLAVE_RESTO, end($grupos)['clave'], 'y el resto sin nombre propio');
        assertSame(1, end($grupos)['n'], 'el resto se lleva a la gente que no tiene columna');

        // Ninguna de las ocho con nombre puede tener más gente que la primera, y
        // la que queda fuera es la última por orden: en este ejemplo todas valen
        // 1, así que **cuál** se queda al resto depende del orden de las
        // etiquetas (`utf8mb4_unicode_ci`, con acentos) y no se comprueba.
        $conNombre = array_slice($grupos, 0, FieldAggregator::GRUPOS_MAX);
        assertSame(1, end($conNombre)['n'], 'la última con nombre tiene una persona');

        $gente = 0;
        foreach ($grupos as $grupo) {
            $gente += (int) $grupo['n'];
        }
        assertSame(12, $gente, 'y entre columnas y resto está toda la lista, sin perder a nadie');
    }

    public function testUnAtributoDeMuchosValoresNoTraeMasDeNueveColumnas(): void
    {
        self::campo('number', 'Edad');
        $num = self::campo('number', 'Edad');
        // 20 sedes distintas, una persona cada una: el caso «.xlsx de una hoja».
        for ($i = 1; $i <= 20; $i++) {
            self::persona('V-' . $i, 'Sede ' . $i, [$num => [$i]]);
        }

        $grupos = FieldAggregator::gruposDeAtributo((int) self::$formId, (string) self::$sedeKey);

        assertSame(
            FieldAggregator::GRUPOS_MAX + 1,
            count($grupos),
            'N columnas con nombre más el resto, y no una columna por valor'
        );
        // El resto tiene que traer a TODOS los que no tienen columna: si saliera
        // con la cola del tope de SQL en vez de con el resto del atributo, el
        // número sería otro y no se vería ningún error.
        $enColumnas = 0;
        foreach (array_slice($grupos, 0, FieldAggregator::GRUPOS_MAX) as $grupo) {
            $enColumnas += (int) $grupo['n'];
        }
        assertSame(20, $enColumnas + (int) end($grupos)['n'], 'nadie se pierde entre las columnas y el resto');
    }

    public function testElRestoNoSePintaCuandoNoQuedaGente(): void
    {
        $num = self::campo('number', 'Edad');
        for ($i = 1; $i <= FieldAggregator::GRUPOS_MAX; $i++) {
            self::persona('V-' . $i, 'Sede ' . $i, [$num => [$i]]);
        }

        $grupos = FieldAggregator::gruposDeAtributo((int) self::$formId, (string) self::$sedeKey);

        assertSame(FieldAggregator::GRUPOS_MAX, count($grupos), 'sin resto: una columna en cero diría que hay gente sin sede');
        foreach ($grupos as $grupo) {
            assertTrue((int) $grupo['n'] > 0, 'toda columna con nombre tiene gente');
        }
    }

    // =====================================================================
    // 2. Cada celda es la de su grupo
    // =====================================================================

    public function testElPromedioDeCadaGrupoSaleDeSuPropiaSuma(): void
    {
        $num = self::campo('number', 'Edad');
        // Norte: 10 y 20 (promedio 15 con 2 respuestas). Sur: 4 (promedio 4 con 1).
        // Si el promedio fuera la media de las medias daría 9,5, que no es el
        // promedio de nadie.
        self::persona('V-1', 'Norte', [$num => [10, 20]]);
        self::persona('V-2', 'Sur', [$num => [4]]);

        $filas = self::comparacion();

        assertSame(1, count($filas['filas']), 'una sola pregunta');
        $celdas = $filas['filas'][0]['celdas'];
        assertSame(2, count($celdas), 'una celda por columna');
        assertSame(15.0, (float) $celdas[0]['avg'], 'Norte promedia 10 y 20');
        assertSame(2, $celdas[0]['n'], 'con las dos respuestas que lo llenan');
        assertSame(4.0, (float) $celdas[1]['avg'], 'Sur promedia 4');
        assertSame(1, $celdas[1]['n']);
    }

    public function testElNpsSeCalculaPorGrupoYNoSobreElFormularioEntero(): void
    {
        $nps = self::campo('nps', 'Recomienda');
        // Norte: 10, 10, 10 → NPS 100. Sur: 0, 0 → NPS -100. El conjunto NPS 0.
        self::persona('V-1', 'Norte', [$nps => [10, 10, 10]]);
        self::persona('V-2', 'Sur', [$nps => [0, 0]]);

        $filas = self::comparacion();
        $celdas = $filas['filas'][0]['celdas'];

        assertSame(100, (int) $celdas[0]['nps'], 'Norte, tres promotores y ningún detractor');
        assertSame(-100, (int) $celdas[1]['nps'], 'Sur, dos detractores y ningún promotor');
        // El NPS del formulario entero sería 0 (tres promoters y dos detractores):
        // si la celda saliera de ahí, las dos columnas dirían lo mismo.
        assertTrue($celdas[0]['nps'] !== $celdas[1]['nps'], 'las columnas no salen del promedio del formulario');
    }

    public function testElPorcentajeDeLaOpcionMasRepetidaUsaElTotalDeRespuestas(): void
    {
        $sel = self::campo('select', 'Turno', options: [['value' => 'Mañana'], ['value' => 'Tarde'], ['value' => 'Noche']]);
        // Norte responde 6 veces: 2 Mañana, 2 Tarde, 1 Noche y un texto suelto que
        // no es una opción (una respuesta de texto en un campo de opción es
        // posible si se editó el campo después de recibir respuestas).
        self::persona('V-1', 'Norte', [$sel => ['Mañana', 'Mañana', 'Tarde', 'Tarde', 'Noche', ' algo']]);

        $celda = self::comparacion()['filas'][0]['celdas'][0];

        assertSame(6, $celda['total'], 'el denominador son TODAS las respuestas del grupo');
        assertSame('Mañana', $celda['items'][0]['label'], 'la más repetida, con el desempate por orden');
        assertSame(2, $celda['items'][0]['c']);
        assertSame(33, (int) $celda['items'][0]['pct'], '2 de 6 es 33 %, y no 2 de 5 (40 %)');
    }

    public function testUnGrupoSinRespuestasNoInventaUnCero(): void
    {
        $num = self::campo('number', 'Edad');
        self::persona('V-1', 'Norte', [$num => [7]]);
        // Sur entra en la lista pero no responde: su columna no es un 0,00.
        self::alta('V-2', 'Sur');

        $celda = self::comparacion()['filas'][0]['celdas'][1];

        assertNull($celda['avg'], 'sin respuestas no hay promedio: la vista pinta «—»');
        assertSame(0, $celda['n']);
    }

    // =====================================================================
    // 3. Qué preguntas entran, y por qué
    // =====================================================================

    public function testNoSeComparaLoQueNoTieneUnaMetricaPorGrupo(): void
    {
        $num   = self::campo('number', 'Edad');
        $sel   = self::campo('select', 'Turno', options: [['value' => 'Mañana'], ['value' => 'Tarde']]);
        $caja  = self::campo('checkbox', 'Extras', options: [['value' => 'A'], ['value' => 'B']]);
        $texto = self::campo('text', 'Comentario');
        self::campo('matrix', 'Trato', options: [
            ['value' => 'Bueno', 'group' => 'rows'],
            ['value' => 'Malo', 'group' => 'cols'],
        ]);
        self::campo('ranking', 'Orden', options: [['value' => 'Uno'], ['value' => 'Dos']]);
        self::persona('V-1', 'Norte', [
            $num     => [7],
            $sel     => ['Mañana'],
            $caja    => [['A', 'B']],
            $texto   => ['Comentario'],
        ]);

        $filas = self::comparacion()['filas'];
        $ids = array_map(static fn(array $f): int => (int) $f['field']['id'], $filas);

        assertTrue(in_array($num, $ids, true), 'el numérico se compara');
        assertTrue(in_array($sel, $ids, true), 'la opción única se compara');
        assertFalse(in_array($caja, $ids, true), 'la casilla múltiple no: su valor es la combinación en JSON');
        assertFalse(in_array($texto, $ids, true), 'el texto libre no tiene promedio');
        assertSame(2, count($ids), 'solo las dos comparables');
    }

    public function testElTechoDeFilasSeAplicaAntesDeConsultar(): void
    {
        $primero = self::campo('number', 'Numérica 1');
        for ($i = 2; $i <= FieldAggregator::FILAS_MAX + 1; $i++) {
            self::campo('number', 'Numérica ' . $i);
        }
        self::persona('V-1', 'Norte', [$primero => array_fill(0, FieldAggregator::FILAS_MAX + 1, 7)]);

        $filas = self::comparacion()['filas'];

        assertSame(FieldAggregator::FILAS_MAX, count($filas), 'el techo de filas se aplica');
        assertSame($primero, (int) $filas[0]['field']['id'], 'y se queda con las primeras, en el orden del formulario');
    }

    // =====================================================================
    // 4. Las puertas: PII, filtro por valor y fechas
    // =====================================================================

    public function testUnCampoMarcadoComoPiiNoSeComparaSinElPermiso(): void
    {
        $normal = self::campo('number', 'Edad');
        $secreto = self::campo('number', 'Ingresos');
        self::marcarPii((int) $secreto);
        self::persona('V-1', 'Norte', [$normal => [7], $secreto => [1500]]);

        $idsSinPermiso = self::idsDe(self::comparacion(piiTypes: self::piiDe(self::campos())));
        assertTrue(in_array($normal, $idsSinPermiso, true), 'la pregunta normal se sigue viendo');
        assertFalse(in_array($secreto, $idsSinPermiso, true), 'la marcada como personal no sale: no hay quien la pueda leer');

        // Y el otro lado de la misma puerta: con el permiso, la comparación sí la
        // trae. Si esto no se comprueba, «excluir PII» y «no agrupar nunca» pasan
        // el mismo test.
        Auth::assumeLoggedIn((int) self::adminId());
        Auth::forgetCache();
        assertTrue(Pii::canView(), 'el admin del test tiene el permiso');
        $idsConPermiso = self::idsDe(self::comparacion(piiTypes: []));
        assertTrue(in_array($secreto, $idsConPermiso, true), 'con el permiso, la pregunta también se compara');
    }

    public function testLaComparacionDesapareceCuandoHayUnValorFiltrado(): void
    {
        $num = self::campo('number', 'Edad');
        self::persona('V-1', 'Norte', [$num => [7]]);
        self::persona('V-2', 'Sur', [$num => [4]]);

        $_GET = ['atr_key' => (string) self::$sedeKey, 'atr_value' => 'Norte'];
        $html = self::render();

        assertFalse(str_contains($html, 'Comparar por'), 'comparar los grupos de un atributo mientras se mira uno de ellos no dice nada');
        assertTrue(str_contains($html, 'Descargar CSV'), 'la tabla de detalle sigue ahí');
    }

    public function testLaComparacionRespetaLasFechas(): void
    {
        $num = self::campo('number', 'Edad');
        self::persona('V-1', 'Norte', [$num => [7]]);

        $hoy = self::hoy();
        $ayer = date('Y-m-d', strtotime($hoy . ' -2 days'));
        // Dos respuestas de Norte: una de hoy y otra de hace dos días. Con el
        // filtro de «hoy» el promedio es el de una sola.
        $alta = self::alta('V-2', 'Norte');
        $vieja = Submission::create((int) self::$formId, '127.0.0.1', 'test', null, (int) $alta['id']);
        Answer::create($vieja, (int) $num, '99');
        self::envejecer($vieja, $ayer);

        $todo = self::comparacion();
        assertSame(53.0, (float) $todo['filas'][0]['celdas'][0]['avg'], 'sin fechas, las dos');

        $soloHoy = self::comparacion($hoy, $hoy);
        assertSame(1, $soloHoy['filas'][0]['celdas'][0]['n'], 'con el rango de hoy solo cuenta la de hoy');
        assertSame(7.0, (float) $soloHoy['filas'][0]['celdas'][0]['avg'], 'y el promedio es el suyo');
    }

    // =====================================================================
    // 5. La regresión de fase 7 que salió aquí: el segundo CSV
    // =====================================================================

    public function testLosDosEnlacesDeCsvLlevanElFiltroDeAtributo(): void
    {
        self::campo('text', 'Comentario');
        self::persona('V-1', 'Norte', [], [], [], ['Hola']);

        $_GET = [
            'from'      => '2026-01-01',
            'atr_key'   => (string) self::$sedeKey,
            'atr_value' => 'Norte',
        ];
        $html = self::render();

        assertSame(
            2,
            substr_count($html, 'export.csv?from=2026-01-01&atr_key=sede&atr_value=Norte'),
            'los DOS botones de CSV llevan el filtro completo: el de arriba y el de la tarjeta de detalle'
        );
        assertFalse(str_contains($html, 'export.csv?from=2026-01-01"'), 'el del detalle ya no se arma a mano con solo from/to');
    }

    // =====================================================================
    // Ayudas
    // =====================================================================

    /** Crea un campo y recuerda su id en el orden del formulario. */
    private static function campo(string $type, string $label, array $options = []): int
    {
        $id = (int) Field::create(
            (int) self::$formId,
            $type,
            $label,
            options: $options,
            position: count(self::$campoIds)
        );
        self::$campoIds[] = $id;
        return $id;
    }

    private static function marcarPii(int $fieldId): void
    {
        Database::getInstance(Config::db())
            ->execute('UPDATE fields SET is_pii = 1 WHERE id = :id', ['id' => $fieldId]);
    }

    /**
     * Alta de una persona con el atributo de sede, sin respuestas.
     *
     * @return array{id: int}
     */
    private static function alta(string $cedula, string $sede): array
    {
        $alta = FormParticipant::create(
            (int) self::$formId,
            'cedula',
            ['cedula' => $cedula, 'nombre' => 'N ' . $cedula],
            [(string) self::$sedeKey => $sede]
        );
        assertTrue(($alta['ok'] ?? false) === true, 'alta de ' . $cedula . ': ' . (string) ($alta['motivo'] ?? ''));
        return ['id' => (int) $alta['id']];
    }

    /**
     * Una persona con una o varias respuestas, **indicando qué campo** recibe cada
     * valor: `[$num => [10, 20], $sel => ['Mañana']]`.
     *
     * Va indexado por id de campo y no como cuatro listas en paralelo porque un
     * `persona('V-1', 'Norte', ['Mañana'])` sobre un campo `select` se come los
     * valores en el hueco equivocado y no da ningún error: la persona queda con
     * cero respuestas y el agregado sale a cero, que parece un dato.
     *
     * Una submission por posición, con una respuesta de cada campo que tenga
     * valor en esa posición: así una persona con `[7]` y `['Mañana']` deja las
     * dos respuestas en la misma submission, que es como se respondieron.
     *
     * @param array<int, array<int, mixed>> $valores campo => valores de cada respuesta
     */
    private static function persona(string $cedula, string $sede, array $valores = []): void
    {
        $alta = self::alta($cedula, $sede);
        $posiciones = 0;
        foreach ($valores as $lista) {
            $posiciones = max($posiciones, count($lista));
        }

        for ($i = 0; $i < $posiciones; $i++) {
            $submissionId = Submission::create((int) self::$formId, '127.0.0.1', 'test', null, (int) $alta['id']);
            foreach ($valores as $campoId => $lista) {
                if (!isset($lista[$i])) {
                    continue;
                }
                $valor = $lista[$i];
                Answer::create(
                    $submissionId,
                    (int) $campoId,
                    is_array($valor) ? (string) json_encode($valor, JSON_UNESCAPED_UNICODE) : (string) $valor
                );
            }
        }
    }

    /**
     * El día de HOY en la zona de presentación, que es lo que espera el filtro
     * `from`/`to` (`Clock::dayStartUtc()` traduce la zona, no el string).
     */
    private static function hoy(): string
    {
        return Clock::format((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'Y-m-d');
    }

    /** Envejece una submission para que caiga en un día pasado. */
    private static function envejecer(int $submissionId, string $dia): void
    {
        Database::getInstance(Config::db())->execute(
            'UPDATE submissions SET finished_at = :f WHERE id = :id',
            ['f' => $dia . ' 15:00:00', 'id' => $submissionId]
        );
    }

    /** @return array<int, array> los campos del formulario, en orden */
    private static function campos(): array
    {
        $out = [];
        foreach (self::$campoIds as $id) {
            $field = Field::find($id);
            if ($field !== null) {
                $out[] = $field;
            }
        }

        return $out;
    }

    /**
     * Los tipos de los campos marcados como personales: la lista que el
     * controlador pasa al agregador cuando quien mira NO tiene el permiso.
     */
    private static function piiDe(array $campos): array
    {
        return Pii::canView() ? [] : Pii::piiFieldTypes($campos);
    }

    /** @return int[] los ids de las preguntas de la comparación */
    private static function idsDe(array $comparacion): array
    {
        return array_map(static fn(array $f): int => (int) $f['field']['id'], $comparacion['filas']);
    }

    /** Un admin del test, para el lado de la puerta que sí tiene el permiso. */
    private static function adminId(): int
    {
        return (int) User::create(
            'Roster Group Admin',
            'rostergrpa-' . bin2hex(random_bytes(4)) . '@test.local',
            'clave-larga-123',
            1
        );
    }

    /**
     * El `$agrupacion` que pinta el controlador, tal cual.
     *
     * @param array|null $campos  campos a comparar (null = los del formulario)
     */
    private static function comparacion(string $from = '', string $to = '', ?array $piiTypes = null, ?array $atr = null, ?array $campos = null): array
    {
        $atrClave = FormParticipant::atributoDeCatalogo((int) self::$formId, (string) self::$sedeKey);
        assertTrue($atrClave !== null, 'el atributo está en el catálogo');

        return FieldAggregator::forFormByGroup(
            (int) self::$formId,
            $campos ?? self::campos(),
            FieldAggregator::gruposDeAtributo((int) self::$formId, (string) $atrClave['key_name']),
            (string) $atrClave['key_name'],
            $from,
            $to,
            $piiTypes ?? self::piiDe(self::campos()),
            $atr
        );
    }

    private static function crearSede(int $formId, string $cabecera): string
    {
        $key = FormParticipant::ensureAttribute($formId, $cabecera);
        assertTrue($key !== null, 'el catálogo se crea desde la cabecera del dueño');
        return (string) $key;
    }

    private static function render(): string
    {
        Auth::assumeLoggedIn((int) self::$ownerId);
        Auth::forgetCache();
        Response::setRecordHalt(true);
        ob_start();
        (new AnalyticsController())->show((int) self::$formId);
        return (string) ob_get_clean();
    }

    private static function ensureCleanupHook(): void
    {
        if (self::$cleanupHookRegistered) {
            return;
        }
        self::$cleanupHookRegistered = true;
        register_shutdown_function(static function (): void {
            self::cleanupResiduals();
        });
    }

    private static function cleanupResiduals(): void
    {
        // Sin el guion después de «rostergrp»: el admin del test de PII se crea
        // como `rostergrpa-…`, y `LIKE 'rostergrp-%'` no lo cogía (el comodín
        // empieza en la «a»). Se积累了 un admin por corrida y la limpieza
        // parecía funcionar porque los dueños sí se borraban.
        Database::getInstance(Config::db())->execute("DELETE FROM users WHERE email LIKE 'rostergrp%'");
        self::$ownerId = null;
        self::$formId = null;
        self::$sedeKey = null;
        self::$campoIds = [];
    }
}
