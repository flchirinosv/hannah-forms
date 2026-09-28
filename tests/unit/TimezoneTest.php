<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\View;
use App\Models\Submission;

/**
 * Zona horaria canónica (fase 3). Fija el contrato en sus tres puntos:
 *
 *   1. la BD habla UTC (sesión con `SET time_zone = '+00:00'`),
 *   2. PHP habla UTC (lo que sale de la BD se interpreta como el instante que es),
 *   3. la conversión a la zona del lector ocurre en Clock/View, no en las vistas.
 *
 * Los tests de base de datos usan un formulario y respuestas con slug único y
 * se limpian en tearDown. Los de pantalla (Clock/View) no tocan la BD.
 */
class TimezoneTest
{
    private static bool $envLoaded = false;
    private static ?Database $db = null;
    private static array $formIds = [];
    private static array $submissionIds = [];
    private static array $answerIds = [];

    public function setUp(): void
    {
        self::loadEnv();
        self::db();
    }

    public function tearDown(): void
    {
        foreach (self::$answerIds as $id) {
            self::db()->execute('DELETE FROM answers WHERE id = :id', ['id' => $id]);
        }
        foreach (self::$submissionIds as $id) {
            self::db()->execute('DELETE FROM submissions WHERE id = :id', ['id' => $id]);
        }
        foreach (self::$formIds as $id) {
            self::db()->execute('DELETE FROM fields WHERE form_id = :id', ['id' => $id]);
            self::db()->execute('DELETE FROM forms WHERE id = :id', ['id' => $id]);
        }
        self::$formIds = [];
        self::$submissionIds = [];
        self::$answerIds = [];
    }

    /* ------------------------------------------------------------------
     * 1. La BD y PHP en UTC
     * ------------------------------------------------------------------ */

    public function testLaSesionDeLaBdEstaFijadaEnUtc(): void
    {
        $fila = self::db()->fetch('SELECT @@session.time_zone AS tz');
        $tz = $fila === null ? null : (string) $fila['tz'];
        assertTrue(
            in_array($tz, ['+00:00', 'UTC'], true),
            'La sesión de MariaDB debe quedar fijada explícitamente en +00:00, no heredada de la zona del host (vale "'
            . var_export($tz, true) . '"; si es SYSTEM, la lectura de TIMESTAMP depende de dónde esté la máquina)'
        );
    }

    public function testElDesfaseDeLaSesionEsCero(): void
    {
        $filas = self::db()->fetchAll("SELECT NOW() AS a, UTC_TIMESTAMP() AS b");
        $a = strtotime((string) $filas[0]['a'] . ' UTC');
        $b = strtotime((string) $filas[0]['b'] . ' UTC');
        assertTrue(
            $a === $b,
            'NOW() y UTC_TIMESTAMP() deben coincidir en la sesión, si no la escritura de TIMESTAMP está desplazada'
        );
    }

    public function testPhpCorreEnUtc(): void
    {
        assertEquals(
            'UTC',
            date_default_timezone_get(),
            'PHP debe correr en UTC: si corre en la zona de presentación, todo strtotime() de un string de la BD queda desplazado'
        );
    }

    public function testLaZonaDePresentacionEsCaracasPorDefecto(): void
    {
        assertEquals('America/Caracas', (string) Config::get('app.timezone', ''));
    }

    /* ------------------------------------------------------------------
     * 2. Lo que sale de la BD se convierte en el punto de pintado
     * ------------------------------------------------------------------ */

    public function testUnInstanteUtcSeMuestraEnLaZonaDelLector(): void
    {
        // 01:33 UTC es las 21:33 del DÍA ANTERIOR en Caracas (UTC-4). Si esto
        // devolviera 28/09, la app estaría pintando UTC y el lector vería cuatro
        // horas de más sin que nada pareciera roto.
        assertEquals('27/09/2026 21:33', View::datetime('2026-09-28 01:33:35'));
        assertEquals('27/09/2026', View::date('2026-09-28 01:33:35'));
    }

    public function testUnaFechaNulaOInvalidaNoSeInventa(): void
    {
        assertEquals('', View::datetime(null));
        assertEquals('', View::datetime(''));
        assertEquals('', View::date('no-es-fecha'));
        assertEquals('', View::relative(null));
        assertEquals('0', View::timestamp(null));
        assertEquals('0', View::timestamp('no-es-fecha'));
    }

    public function testElTextoRelativoUsaInstantesYNoCadenas(): void
    {
        $hace2h = gmdate('Y-m-d H:i:s', time() - 7200);
        assertEquals('hace 2 horas', View::relative($hace2h));
        $hace1min = gmdate('Y-m-d H:i:s', time() - 60);
        assertEquals('hace 1 minuto', View::relative($hace1min));
    }

    public function testUnRelativoEnElFuturoDegradaALaFecha(): void
    {
        // Un reloj que va por detrás no puede inventionar "dentro de 5 minutos":
        // se cae a la fecha, que siempre es cierta.
        $futuro = gmdate('Y-m-d H:i:s', time() + 3600);
        assertEquals(View::date($futuro), View::relative($futuro));
    }

    public function testUnaZonaInvalidaNoDejaLaAppSinFechas(): void
    {
        assertTrue(Clock::isValidZone('America/Caracas'));
        assertTrue(!Clock::isValidZone('America/Caracas/QueNoExiste'));
        assertTrue(!Clock::isValidZone(''));
    }

    /* ------------------------------------------------------------------
     * 3. Los límites de día son de la zona del lector, no días UTC
     * ------------------------------------------------------------------ */

    public function testElDiaLocalEmpiezaCuatroHorasDespuesEnUtc(): void
    {
        assertEquals('2026-09-28 04:00:00', Clock::dayStartUtc('2026-09-28'));
    }

    public function testElFinalDelDiaEsElComienzoDelSiguiente(): void
    {
        // El rango se cierra con `<` del día siguiente a las 00:00, no con
        // 23:59:59: así no hay ni un segundo sin cubrir ni un instante que
        // dependa del redondeo.
        assertEquals(Clock::dayStartUtc('2026-09-29'), Clock::dayEndUtc('2026-09-28'));
        assertEquals('2026-09-29 04:00:00', Clock::dayEndUtc('2026-09-28'));
    }

    public function testElDesfaseQueSeMandaASqlEsUnNumeroYNoElNombreDeLaZona(): void
    {
        // CONVERT_TZ con 'America/Caracas' exige las tablas de zonas horarias
        // en el servidor; si no están, devuelve NULL y el agrupamiento queda
        // vacío sin error. Por eso va el desplazamiento numérico.
        assertEquals('-04:00', Clock::localSqlOffsetFor('2026-09-28 12:00:00'));
    }

    public function testSeValidanLasFechasDelFiltro(): void
    {
        assertTrue(Submission::isValidDay('2026-09-27'));
        // createFromFormat "corrige" 31 de febrero a marzo sin avisar; si solo
        // se mirara que no falla, un día imposible pasaría por bueno.
        assertTrue(!Submission::isValidDay('2026-02-31'));
        assertTrue(!Submission::isValidDay('2026-13-01'));
        assertTrue(!Submission::isValidDay('2026-9-7'));
        assertTrue(!Submission::isValidDay('ayer'));
        assertTrue(!Submission::isValidDay(''));
        assertTrue(!Submission::isValidDay('   '));
    }

    /* ------------------------------------------------------------------
     * Con la BD: los datos no se mueven, y los filtros cuentan el día correcto
     * ------------------------------------------------------------------ */

    public function testLoQueEscribeLaBdSeReleeIgualYNoCambiaDeSignificado(): void
    {
        $formId = self::crearFormulario();
        $id = self::crearSubmission($formId, '2026-09-28 01:33:35');

        $vuelta = self::db()->fetch('SELECT finished_at FROM submissions WHERE id = :id', ['id' => $id]);
        assertEquals('2026-09-28 01:33:35', (string) $vuelta['finished_at']);

        // El instante es el que es, en UTC, y no ha sido desplazado por la
        // zona: es exactamente lo que había antes de la fase 3.
        $instante = strtotime((string) $vuelta['finished_at'] . ' UTC');
        assertEquals(strtotime('2026-09-28T01:33:35+00:00'), $instante);
        assertEquals('2026-09-27 21:33:35', gmdate('Y-m-d H:i:s', $instante - 14400));
    }

    public function testElFiltroPorDiaUsaElDiaDelLectorYNoElDiaUtc(): void
    {
        $formId = self::crearFormulario();
        $id = self::crearSubmission($formId, '2026-09-28 01:33:35'); // 27/09 21:33 en Caracas

        $enElDia = array_column(Submission::byFormFiltered($formId, '2026-09-27', '2026-09-27'), 'id');
        assertTrue(in_array($id, array_map('intval', $enElDia), true), 'una respuesta de la noche del 27 debe caer en el filtro del día 27');

        $enElSiguiente = array_column(Submission::byFormFiltered($formId, '2026-09-28', '2026-09-28'), 'id');
        assertTrue(!in_array($id, array_map('intval', $enElSiguiente), true), 'esa misma respuesta no debe caer en el filtro del día 28: en UTC sí caería');
    }

    public function testElAgrupamientoDiarioAgrupaPorDiaLocal(): void
    {
        $formId = self::crearFormulario();
        $tarde = self::crearSubmission($formId, '2026-09-28 20:00:00'); // 28/09 16:00 en Caracas
        $noche = self::crearSubmission($formId, '2026-09-28 03:00:00'); // 27/09 23:00 en Caracas

        $dias = [];
        foreach (Submission::dailyStats($formId) as $fila) {
            $dias[(string) $fila['day']] = (int) $fila['c'];
        }

        assertTrue(isset($dias['2026-09-28']) && $dias['2026-09-28'] === 1, 'la respuesta de la tarde va al 28');
        assertTrue(isset($dias['2026-09-27']) && $dias['2026-09-27'] === 1, 'la de la madrugada va al 27, no al 28');
        assertTrue(isset($dias[$dias === [] ? 'x' : '2026-09-26']) === false, 'no debe inventar un bucket vacío');

        // Y el filtro tiene que contar lo mismo que el agrupamiento: si el
        // filtro dice 1 y el gráfico dice 2, hay un borde mal puesto.
        assertEquals(2, count(Submission::byFormFiltered($formId, '2026-09-27', '2026-09-28')));
        assertTrue(in_array($noche, array_map('intval', array_column(Submission::byFormFiltered($formId, '2026-09-27', '2026-09-27'), 'id')), true));
        assertTrue(in_array($tarde, array_map('intval', array_column(Submission::byFormFiltered($formId, '2026-09-28', '2026-09-28'), 'id')), true));
    }

    /* ------------------------------------------------------------------
     * El calendario del formulario (open_at/close_at)
     * ------------------------------------------------------------------ */

    public function testElFormularioAbreALaHoraQueEscribioElDueno(): void
    {
        // `open_at` viene de un datetime-local: una pared de reloj en la zona de
        // quien administra. Leída en UTC (o en America/Mexico_City) el
        // formulario se abría 4 h (o 2 h) antes de la hora escrita.
        $m = new \ReflectionMethod(\App\Controllers\FillController::class, 'parseScheduleDate');
        $m->setAccessible(true);

        $instante = $m->invoke(null, '2026-09-28T09:00');
        assertEquals(
            strtotime('2026-09-28T13:00:00+00:00'),
            $instante,
            'las 09:00 locales de Caracas son las 13:00 UTC'
        );

        // Con zona explícita manda la que viene, no la que suponemos.
        assertEquals(strtotime('2026-09-28T13:00:00+00:00'), $m->invoke(null, '2026-09-28T13:00:00Z'));
        assertEquals(strtotime('2026-09-28T13:00:00+00:00'), $m->invoke(null, '2026-09-28T09:00:00-04:00'));

        assertEquals(null, $m->invoke(null, ''));
        assertEquals(null, $m->invoke(null, 'no-es-fecha'));
    }

    /* ------------------------------------------------------------------
     * Guarda contra regresión en las vistas
     * ------------------------------------------------------------------ */

    /**
     * Ninguna vista puede pintar una fecha de la BD con `date()`/`strtotime()`
     * a pelo: son los helpers de View los que saben qué zona es la del lector.
     *
     * Se permite `date()` sin argumentos (el "ahora" de una hoja imprimible,
     * que ya usa la zona de presentación) y `View::timestamp()`/`Clock::`.
     */
    public function testNingunaVistaPintaUnaFechaDeLaBdSinElHelper(): void
    {
        $raiz = dirname(__DIR__, 2) . '/views';
        $infractores = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));
        foreach ($rii as $archivo) {
            if (!$archivo->isFile() || $archivo->getExtension() !== 'php') {
                continue;
            }
            $lineas = file($archivo->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lineas as $n => $linea) {
                $codigo = self::sinComentarios($linea);
                if (!preg_match('/\bdate\s*\(|strtotime\s*\(/', $codigo)) {
                    continue;
                }
                // `date('r')` del Mailer y `date('Y/m')` de rutas no son vistas;
                // aquí solo hay date() sin argumentos y strtotime de la BD.
                if (preg_match('/\bdate\s*\(\s*\)/', $codigo)) {
                    continue;
                }
                if (strpos($codigo, 'View::') !== false || strpos($codigo, 'Clock::') !== false) {
                    continue;
                }
                $infractores[] = $archivo->getPathname() . ':' . ($n + 1);
            }
        }

        assertTrue(
            $infractores === [],
            'Estas vistas pintan una fecha sin pasar por View::date()/datetime()/relative() '
            . '(el string de la BD es UTC y date() lo mostraría en UTC): ' . implode(', ', $infractores)
        );
    }

    /* ------------------------------------------------------------------
     * Utilidades
     * ------------------------------------------------------------------ */

    private static function sinComentarios(string $linea): string
    {
        $linea = preg_replace('#//.*$#', '', $linea);
        $linea = preg_replace('#/\*.*?\*/#', '', (string) $linea);

        return trim((string) $linea);
    }

    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
        }
    }

    private static function db(): Database
    {
        if (self::$db === null) {
            self::$db = Database::getInstance(Config::get('database', []));
        }

        return self::$db;
    }

    private static function crearFormulario(): int
    {
        $slug = 'tz-test-' . bin2hex(random_bytes(6));
        self::db()->execute(
            'INSERT INTO forms (user_id, title, slug, status, settings) VALUES (1, :title, :slug, :status, :settings)',
            [
                'title'    => 'TZ test',
                'slug'     => $slug,
                'status'   => 'published',
                'settings' => '{}',
            ]
        );
        $id = (int) self::db()->lastInsertId();
        self::$formIds[] = $id;

        return $id;
    }

    /**
     * Inserta una respuesta con `finished_at` EXACTO. La columna es TIMESTAMP, y
     * como la sesión está en +00:00, el string se guarda como el instante UTC
     * que dice.
     */
    private static function crearSubmission(int $formId, string $finishedAtUtc): int
    {
        self::db()->execute(
            'INSERT INTO submissions (form_id, finished_at, ip_address) VALUES (:form_id, :finished_at, :ip_address)',
            ['form_id' => $formId, 'finished_at' => $finishedAtUtc, 'ip_address' => '127.0.0.1']
        );
        $id = (int) self::db()->lastInsertId();
        self::$submissionIds[] = $id;

        return $id;
    }
}
