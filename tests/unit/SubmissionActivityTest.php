<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;

/**
 * V5 — Sparkline del dashboard: `Submission::activityByForms()`.
 *
 * La serie se agrupa por DÍA LOCAL (CONVERT_TZ al `app.timezone`), se cierra
 * en PHP con huecos a 0 y comparte el contrato de `dailyStats`. Las filas se
 * siembran con `finished_at` calculado contra los límites del día local, no
 * con desplazamientos fijos de reloj: así el test es estable aunque el host
 * corra a una hora UTC que en la zona de presentación caiga en otro día.
 */
class SubmissionActivityTest
{
    private static array $users = [];
    private static array $forms = [];
    private static bool $available = true;
    private static bool $envLoaded = false;

    public function setUp(): void
    {
        self::loadEnv();
        try {
            self::$available = true;
            $db = Database::getInstance(Config::db());
            $db->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            self::$available = false;
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::cleanupResiduals();

        $suffix = bin2hex(random_bytes(4));
        $u = User::create('Test Activity', 'activity-' . $suffix . '@test.local', 'password');
        self::$users[] = $u;
        self::$forms['a'] = Form::create((int) $u, 'Actividad A');
        self::$forms['b'] = Form::create((int) $u, 'Actividad B');
    }

    public function testSerieAgrupaPorDiaLocalConHuecosACero(): void
    {
        self::seedSubmission(self::$forms['a'], self::localNoonUtc(-1)); // ayer
        self::seedSubmission(self::$forms['a'], self::localNoonUtc(0));  // hoy x3
        self::seedSubmission(self::$forms['a'], self::localNoonUtc(0));
        self::seedSubmission(self::$forms['a'], self::localNoonUtc(0));

        $activity = Submission::activityByForms([(int) self::$forms['a'], (int) self::$forms['b']], 14);

        assertSame(14, count($activity['labels']));
        assertSame(3, (int) $activity['max']);
        assertSame([(int) self::$forms['a'], (int) self::$forms['b']], array_keys($activity['forms']));

        $a = $activity['forms'][(int) self::$forms['a']];
        $b = $activity['forms'][(int) self::$forms['b']];
        assertSame(14, count($a));
        assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 3], $a);
        assertSame(array_fill(0, 14, 0), $b);

        // Las etiquetas alinean con el día local real.
        $zone = Clock::displayZone();
        assertSame((new DateTimeImmutable('now', $zone))->format('Y-m-d'), $activity['labels'][13]);
        assertSame((new DateTimeImmutable('now', $zone))->modify('-1 day')->format('Y-m-d'), $activity['labels'][12]);
    }

    public function testFormSinRespuestasSaleConCeros(): void
    {
        self::seedSubmission(self::$forms['b'], self::localNoonUtc(0));
        $activity = Submission::activityByForms([(int) self::$forms['a']], 7);

        // Solo se consultó el form a: ni sus counts ni el max cuentan a b.
        assertSame(array_fill(0, 7, 0), $activity['forms'][(int) self::$forms['a']]);
        assertSame(0, (int) $activity['max']);
        assertFalse(isset($activity['forms'][(int) self::$forms['b']]));
    }

    public function testSinIdsDevuelveEstructuraVacia(): void
    {
        $activity = Submission::activityByForms([]);
        assertSame([], $activity['labels']);
        assertSame([], $activity['forms']);
        assertSame(0, (int) $activity['max']);

        $activity = Submission::activityByForms([(int) self::$forms['a']], 0);
        assertSame([], $activity['forms']);
    }

    public function testRespuestasDeOtrosFormsNoSeMezclan(): void
    {
        self::seedSubmission(self::$forms['a'], self::localNoonUtc(0));
        self::seedSubmission(self::$forms['a'], self::localNoonUtc(0));
        self::seedSubmission(self::$forms['b'], self::localNoonUtc(0));

        $activity = Submission::activityByForms([(int) self::$forms['a']], 3);

        // Índice 0 = día más viejo, índice n-1 = hoy (misma convención que labels).
        assertSame([0, 0, 2], $activity['forms'][(int) self::$forms['a']]);
        assertSame(2, (int) $activity['max']);
    }

    /**
     * Instante UTC del mediodía LOCAL del día `+$offset` respecto a hoy, listo
     * para insertar en una columna TIMESTAMP (la sesión BD está en UTC).
     * El mediodía local nunca cruza el borde del día local, ni con DST.
     */
    private static function localNoonUtc(int $offset): string
    {
        static $cache = [];
        $zone = Clock::displayZone();
        $label = (new DateTimeImmutable('now', $zone))->modify(($offset < 0 ? '' : '+') . $offset . ' days')->format('Y-m-d');
        if (!isset($cache[$label])) {
            $cache[$label] = date('Y-m-d H:i:s', strtotime(Clock::dayStartUtc($label)) + 43200);
        }
        return $cache[$label];
    }

    private static function seedSubmission(int $formId, string $finishedAtUtc): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute(
            'INSERT INTO submissions (form_id, ip_address, user_agent, public_token, finished_at)
             VALUES (:f, NULL, NULL, :tok, :ts)',
            ['f' => $formId, 'tok' => bin2hex(random_bytes(16)), 'ts' => $finishedAtUtc]
        );
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        $users = $db->fetchAll("SELECT id FROM users WHERE email LIKE '%@test.local'");
        foreach ($users as $u) {
            $db->execute('DELETE FROM users WHERE id = :id', ['id' => (int) $u['id']]);
        }
        $db->execute("DELETE FROM rate_limits WHERE scope LIKE 'test:%'");
        self::$users = [];
        self::$forms = [];
    }

    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            \App\Core\Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
            register_shutdown_function(static function () {
                try {
                    self::cleanupResiduals();
                } catch (\Throwable $e) {
                    // Sin conexión no hay residuales que borrar.
                }
            });
        }
    }
}