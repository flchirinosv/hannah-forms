<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Controllers\AnalyticsController;
use App\Controllers\ResponsesController;
use App\Models\Answer;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;

/**
 * Exportaciones por TANDAS (streaming): las rutas JSON/CSV ya no cargan el
 * histórico entero en memoria; `exportBatches()` las recorre con keyset de
 * `(finished_at, id)` y `countByFiltered()` sirve el `total` antes de barrer.
 *
 * Contratos que se pinzan aquí:
 * - `exportBatches` cruza la frontera de tanda (25 filas / tanda de 7) en el
 *   MISMO orden global que la paginación (`finished_at DESC, id DESC`), sin
 *   huecos ni repeticiones. Quitar el keyset o cambiar el orden del tie-break
 *   deja este test rojo.
 * - `countByFiltered` coincide con `byFormFiltered` con y sin ventana.
 * - El JSON exportado sigue siendo parseable y con el mismo esqueleto que
 *   `exportPayload`, con la PII enmascarada para quien no tenga `responses.pii`
 *   (IP y campos marcados).
 * - El CSV conserva su fila `#` correlativa y el enmascarado de campos PII
 *   dentro del bucle.
 */
class ExportStreamingTest
{
    private static bool $cleanupHookRegistered = false;
    private static ?int $userId = null;
    private static ?int $formId = null;
    private static int $fPlain = 0;
    private static int $fPii = 0;

    public function setUp(): void
    {
        self::ensureCleanupHook();
        self::cleanupResiduals();
        // Tanda corta para cruzar la frontera con pocas filas (seam oficial;
        // con 5000 la invariante del keyset no se ejercitaría).
        Submission::setIdBatchForTesting(7);
    }

    public function tearDown(): void
    {
        Submission::setIdBatchForTesting(null);
        self::cleanupResiduals();
        Session::remove('user_id');
        Auth::forgetCache();
        unset($_GET['from'], $_GET['to']);
        Response::setRecordHalt(false);
    }

    public function testExportBatchesRecorreElHistoricoEnOrdenGlobal(): void
    {
        self::setUpData();

        $ids = [];
        $counts = [];
        foreach (Submission::exportBatches((int) self::$formId) as [$rows, $bySubmission]) {
            $counts[] = count($rows);
            foreach ($rows as $row) {
                $sid = (int) $row['id'];
                $ids[] = $sid;
                assertArrayHasKey($sid, $bySubmission, 'cada tanda trae sus submissions con answers');
            }
        }

        assertSame([7, 7, 7, 4], $counts, '25 filas en tandas de 7 cierran en 4 tandas (7,7,7,4)');
        assertSame(25, count($ids));
        assertSame(
            array_values(array_unique($ids)),
            $ids,
            'sin huecos ni repeticiones a lo largo de las tandas'
        );

        // El orden del keyset es el mismo contrato que la paginación: si un
        // cambio lo rompe, la exportación y la pantalla dejan de coincidir.
        $pag = Submission::byFormFilteredPaginated((int) self::$formId, '', '', 1, 100);
        $pagIds = array_map('intval', array_column($pag['items'], 'id'));
        assertSame($pagIds, $ids, 'el keyset mantiene el orden global finished_at DESC, id DESC');
    }

    public function testExportBatchesFiltraPorFechasConElMismoContrato(): void
    {
        self::setUpData();

        $today = self::todayUtc();
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));

        $ids = [];
        foreach (Submission::exportBatches((int) self::$formId, $today, $tomorrow) as [$rows]) {
            foreach ($rows as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        assertSame(25, count($ids), 'hoy,hasta mañana alcanza todo');

        $vacio = [];
        foreach (Submission::exportBatches((int) self::$formId, $tomorrow, '') as [$rows]) {
            foreach ($rows as $row) {
                $vacio[] = $row;
            }
        }
        assertSame([], $vacio, 'desde mañana no queda nada');
    }

    public function testCountByFilteredCoincideConByFormFiltered(): void
    {
        self::setUpData();

        $today = self::todayUtc();
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));

        assertSame(25, Submission::countByFiltered((int) self::$formId));
        assertSame(
            count(Submission::byFormFiltered((int) self::$formId, $today, $tomorrow)),
            Submission::countByFiltered((int) self::$formId, $today, $tomorrow)
        );
        assertSame(0, Submission::countByFiltered((int) self::$formId, $tomorrow, ''));
    }

    public function testExportJsonStreamingParseableConPiiEnmascarada(): void
    {
        self::setUpData();
        self::authAs((int) self::$userId);

        Response::setRecordHalt(true);
        ob_start();
        (new ResponsesController())->exportJson((int) self::$formId);
        $body = (string) ob_get_clean();

        $json = json_decode($body, true);
        assertSame(JSON_ERROR_NONE, json_last_error(), 'el streaming produce JSON válido');
        assertNotNull($json, 'el cuerpo no está vacío');

        assertSame(25, $json['total'] ?? null);
        assertSame((int) self::$formId, $json['form']['id'] ?? null);
        assertSame(2, count($json['form']['fields'] ?? []), 'un campo normal + un email PII en la metadata');
        assertSame(25, count($json['submissions'] ?? []));

        $piiMeta = null;
        foreach ($json['form']['fields'] as $f) {
            if ((int) $f['id'] === self::$fPii) {
                $piiMeta = $f;
            }
        }
        assertSame(1, $piiMeta['is_pii'] ?? null, 'el email sale marcado como PII en la metadata');

        // Rol user NO tiene `responses.pii`: IP enmascarada y campo PII tapado.
        // El orden es finished_at DESC, id DESC: la primera fila es la ÚLTIMA
        // creada (Persona 24), no la primera.
        $first = $json['submissions'][0] ?? [];
        assertSame('127.0.0.x', $first['ip_address'] ?? null, 'IP enmascarada sin permiso');
        assertSame('Persona 24', $first['answers'][self::$fPlain] ?? null, 'campo normal intacto');
        assertSame('p***@t***.local', $first['answers'][self::$fPii] ?? null, 'email enmascarado');
    }

    public function testExportCsvStreamingCompletoConPiiEnmascarada(): void
    {
        self::setUpData();
        self::authAs((int) self::$userId);

        Response::setRecordHalt(true);
        ob_start();
        (new AnalyticsController())->exportCsv((int) self::$formId);
        $csv = (string) ob_get_clean();

        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($csv))), fn ($l) => $l !== ''));
        assertSame(26, count($lines), 'cabecera + 25 filas');

        $headers = str_getcsv($lines[0], ',', '"', '\\');
        assertSame(['#', 'Fecha', 'Nombre', 'Correo'], $headers);

        $first = str_getcsv($lines[1], ',', '"', '\\');
        assertSame('1', $first[0], 'la fila # corre de 1 en 1 a lo largo de las tandas');
        // Orden finished_at DESC, id DESC: la primera fila es la última creada.
        assertSame('Persona 24', $first[2]);

        $last = str_getcsv($lines[25], ',', '"', '\\');
        assertSame('25', $last[0], 'la última fila cierra la numeración');
        assertSame('Persona 0', $last[2]);

        // El CSV enmascara SIEMPRE los campos marcados (F1), aunque el JSON use
        // el permiso: ningún email crudo sale a mitad del archivo.
        assertSame('p***@t***.local', $first[3]);
        foreach ($lines as $line) {
            assertFalse(str_contains($line, '@test.local'), 'no se filtra ningún email crudo');
        }
    }

    /* ---------- Fixtures ---------- */

    private static function setUpData(): void
    {
        self::$userId = User::create('Export User', 'export-' . bin2hex(random_bytes(4)) . '@test.local', 'password', 2);
        self::$formId = Form::create((int) self::$userId, 'Exportación-' . bin2hex(random_bytes(3)));
        self::$fPlain = Field::create((int) self::$formId, 'text', 'Nombre', isPii: false);
        self::$fPii = Field::create((int) self::$formId, 'email', 'Correo', isPii: true);

        for ($i = 0; $i < 25; $i++) {
            $sid = Submission::create((int) self::$formId, '127.0.0.1');
            Answer::create($sid, self::$fPlain, "Persona $i");
            Answer::create($sid, self::$fPii, "persona$i@test.local");
        }
    }

    private static function authAs(int $userId): void
    {
        Auth::assumeLoggedIn($userId);
        Auth::forgetCache();
    }

    /** Hoy en UTC: los timestamps de la BD viven en UTC (contrato horario). */
    private static function todayUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
    }

    private static function ensureCleanupHook(): void
    {
        if (!self::$cleanupHookRegistered) {
            self::$cleanupHookRegistered = true;
            register_shutdown_function(static function () {
                try {
                    self::cleanupResiduals();
                } catch (\Throwable $e) {
                }
            });
        }
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute("DELETE FROM forms WHERE title LIKE 'Exportación-%'");
        $db->execute("DELETE FROM users WHERE email LIKE 'export-%@test.local'");
        self::$userId = null;
        self::$formId = null;
        self::$fPlain = 0;
        self::$fPii = 0;
    }
}