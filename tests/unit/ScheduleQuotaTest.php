<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FillController;
use App\Core\Clock;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;

/**
 * P6 — Cupos, apertura/cierre y gracias personalizado + PRG.
 *
 * Cubre la lógica pura (scheduleState / parseScheduleDate / isHttpUrl por
 * reflexión), la barrera de disponibilidad de FillController::show (calendar
 * y cupo, con BD real) y el render de thanks vía GET (PRG). La re-redirección
 * del POST no se invoca aquí porque Response::redirect/abort hacen exit.
 */
class ScheduleQuotaTest
{
    private static ?int $userId = null;
    private static int $formId = 0;
    private static string $slug = '';
    private static bool $cleanupHookRegistered = false;

    public function setUp(): void
    {
        self::ensureCleanupHook();

        try {
            $db = \App\Core\Database::getInstance(\App\Core\Config::db());
            $db->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::cleanupResiduals();

        self::$userId = User::create('Test P6', 'p6-' . bin2hex(random_bytes(4)) . '@test.local', 'password');
        self::$formId = Form::create((int) self::$userId, 'Form P6 ' . bin2hex(random_bytes(4)));
        $form = Form::find(self::$formId);
        self::$slug = (string) ($form['slug'] ?? '');
    }

    /**
     * Un instante escrito como lo escribiría el dueño en el `datetime-local`:
     * una pared de reloj en su zona, sin offset.
     *
     * Importa porque `schedule.open_at`/`close_at` NO son instantes sino muros de
     * reloj locales, y la app los interpreta en `app.timezone`. Con
     * `date('Y-m-d H:i:s', time() - 3600)` (que pinta la hora UTC) el valor
     * llegaba 4 h al futuro en Caracas, así que la ventana salía invertida:
     * el test de "cerrado" veía un formulario todavía abierto. Antes pasaba
     * porque PHP y el test compartían zona (America/Mexico_City) y el error se
     * anulaba solo; con PHP en UTC deja de verse.
     */
    private static function relojLocal(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))
            ->setTimezone(Clock::displayZone())
            ->format('Y-m-d H:i:s');
    }

    /* ---------- Lógica de calendario (pura) ---------- */

    public function testSinHorarioSiempreAbierto(): void
    {
        $settings = ['schedule' => ['open_at' => null, 'close_at' => null]];
        assertSame('open', self::invokeStatic('scheduleState', [$settings]));
    }

    public function testAperturaFuturaNoAbierto(): void
    {
        $settings = ['schedule' => ['open_at' => self::relojLocal(time() + 3600), 'close_at' => null]];
        assertSame('not_open', self::invokeStatic('scheduleState', [$settings]));
    }

    public function testCierrePasadoCerrado(): void
    {
        $settings = ['schedule' => ['open_at' => null, 'close_at' => self::relojLocal(time() - 3600)]];
        assertSame('closed', self::invokeStatic('scheduleState', [$settings]));
    }

    public function testVentanaAbierta(): void
    {
        $settings = [
            'schedule' => [
                'open_at'  => self::relojLocal(time() - 3600),
                'close_at' => self::relojLocal(time() + 3600),
            ],
        ];
        assertSame('open', self::invokeStatic('scheduleState', [$settings]));
    }

    public function testFechaInvalidaSeIgnora(): void
    {
        $settings = ['schedule' => ['open_at' => 'no-es-una-fecha', 'close_at' => null]];
        assertSame('open', self::invokeStatic('scheduleState', [$settings]));
        assertNull(self::invokeStatic('parseScheduleDate', ['basura']));
    }

    public function testIsHttpUrl(): void
    {
        assertTrue(self::invokeStatic('isHttpUrl', ['https://ejemplo.com/x']));
        assertTrue(self::invokeStatic('isHttpUrl', ['http://localhost:9000/hook']));
        assertFalse(self::invokeStatic('isHttpUrl', ['ftp://ejemplo.com/x']));
        assertFalse(self::invokeStatic('isHttpUrl', ['javascript:alert(1)']));
    }

    /* ---------- Cupo (BD real) ---------- */

    public function testCupoDisabledEsIlimitado(): void
    {
        Form::saveSettings(self::$formId, [
            'quota' => ['enabled' => false, 'max' => 5],
        ]);
        assertFalse(self::quotaExceededFor(self::$formId));
    }

    public function testCupoMaxCeroEsIlimitado(): void
    {
        Form::saveSettings(self::$formId, [
            'quota' => ['enabled' => true, 'max' => 0],
        ]);
        assertFalse(self::quotaExceededFor(self::$formId));
    }

    public function testCupoNoAlcanzadoPermite(): void
    {
        Form::saveSettings(self::$formId, [
            'quota' => ['enabled' => true, 'max' => 3],
        ]);
        Submission::create(self::$formId, '1.1.1.1', 'ScheduleQuotaTest');
        assertFalse(self::quotaExceededFor(self::$formId));
    }

    public function testCupoAlcanzadoBloquea(): void
    {
        Form::saveSettings(self::$formId, [
            'quota' => ['enabled' => true, 'max' => 1],
        ]);
        Submission::create(self::$formId, '1.1.1.1', 'ScheduleQuotaTest');
        assertTrue(self::quotaExceededFor(self::$formId));
    }

    /* ---------- Barrera de FillController@show (BD real) ---------- */

    public function testShowFormularioCerradoPorHorario(): void
    {
        Form::update(self::$formId, 'Form P6', '', 'published');
        Form::saveSettings(self::$formId, [
            'schedule' => ['open_at' => null, 'close_at' => self::relojLocal(time() - 3600)],
        ]);
        $html = self::renderShow();
        assertTrue(str_contains($html, 'Formulario cerrado'));
        assertTrue(str_contains($html, 'ya no acepta respuestas.'));
    }

    public function testShowNoAbiertoTodavia(): void
    {
        Form::update(self::$formId, 'Form P6', '', 'published');
        Form::saveSettings(self::$formId, [
            'schedule' => ['open_at' => self::relojLocal(time() + 3600), 'close_at' => null],
        ]);
        $html = self::renderShow();
        assertTrue(str_contains($html, 'Aún no está abierto'));
    }

    public function testShowCupoAgotado(): void
    {
        Form::update(self::$formId, 'Form P6', '', 'published');
        Form::saveSettings(self::$formId, [
            'quota' => ['enabled' => true, 'max' => 1],
        ]);
        Submission::create(self::$formId, '1.1.1.1', 'ScheduleQuotaTest');
        $html = self::renderShow();
        assertTrue(str_contains($html, 'Cupo agotado'));
    }

    public function testShowPermiteFormularioPublicado(): void
    {
        Form::update(self::$formId, 'Form P6', '', 'published');
        $html = self::renderShow();
        assertFalse(str_contains($html, 'Cupo agotado'));
        assertFalse(str_contains($html, 'Aún no está abierto'));
        assertTrue(str_contains($html, '<form'), 'debe renderizar el formulario');
    }

    /* ---------- Gracias por GET (PRG) ---------- */

    public function testThanksMuestraMensajePersonalizado(): void
    {
        Form::saveSettings(self::$formId, [
            'thanks' => ['message' => '¡Nos vemos en los resultados!', 'redirect_url' => ''],
        ]);
        $controller = new FillController();
        ob_start();
        $controller->thanks(self::$slug);
        $html = (string) ob_get_clean();
        assertTrue(str_contains($html, '¡Gracias por tu respuesta!'));
        assertTrue(str_contains($html, '¡Nos vemos en los resultados!'));
    }

    public function testThanksSinMensajeUsaDefault(): void
    {
        $controller = new FillController();
        ob_start();
        $controller->thanks(self::$slug);
        $html = (string) ob_get_clean();
        assertTrue(str_contains($html, '¡Respuesta enviada!'));
    }

    /* ---------- Helpers ---------- */

    private static function quotaExceededFor(int $formId): bool
    {
        $form = Form::find($formId);
        return (bool) self::invokeStatic('quotaExceeded', [$form]);
    }

    private static function renderShow(): string
    {
        $controller = new FillController();
        ob_start();
        $controller->show(self::$slug);
        return (string) ob_get_clean();
    }

    private static function invokeStatic(string $method, array $args)
    {
        $ref = new \ReflectionMethod(FillController::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    private static function ensureCleanupHook(): void
    {
        if (!self::$cleanupHookRegistered) {
            self::$cleanupHookRegistered = true;
            // Un cleanup de salida NO puede matar el proceso: este hook corre
            // al terminar, y un `getInstance()` fallido (mysqld parado, socket,
            // credenciales) lanzaría PDOException y se llevaría por delante el
            // informe entero del runner, que es justo cuando más falta hace.
            // Se traga el fallo a propósito: si no se pudo limpiar, se limpia
            // a mano. OJO: la config ya NO es una causa posible de ese fallo —
            // el runner carga el `.env` (ver `scripts/test.php`).
            register_shutdown_function(static function () {
                try {
                    self::cleanupResiduals();
                } catch (\Throwable $e) {
                    // Sin conexión no hay residuales que borrar.
                }
            });
        }
    }

    private static function cleanupResiduals(): void
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $users = $db->fetchAll("SELECT id FROM users WHERE email LIKE '%@test.local'");
        foreach ($users as $u) {
            $db->execute('DELETE FROM users WHERE id = :id', ['id' => (int) $u['id']]);
        }
        self::$userId = null;
        self::$formId = 0;
        self::$slug = '';
    }
}