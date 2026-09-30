<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Response;
use App\Core\Session;
use App\Controllers\AnalyticsController;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;

/**
 * P1 — Paginación server-side de la tabla de respuestas de analítica.
 *
 * `Submission::byFormFilteredPaginated()` acota la TABLA (no las métricas) y
 * la acción JSON `GET /forms/{id}/analytics/rows` rellena el tbody + el pager
 * sin recargar. Aquí se pinza el contrato del envelope y la respuesta.
 */
class SubmissionPaginationTest
{
    private static bool $envLoaded = false;
    private static ?int $userId = null;
    private static ?int $otherId = null;
    private static ?int $formId = null;

    public function setUp(): void
    {
        self::loadEnv();
        self::cleanupResiduals();

        self::$userId = User::create('Pager User', 'pager-' . bin2hex(random_bytes(4)) . '@test.local', 'password', 2);
        self::$otherId = User::create('Pager Otro', 'pager-oth-' . bin2hex(random_bytes(4)) . '@test.local', 'password', 2);
        self::$formId = Form::create((int) self::$userId, 'Paginación-' . bin2hex(random_bytes(3)));
        Field::create((int) self::$formId, 'text', 'Nombre', position: 0);
        for ($i = 0; $i < 25; $i++) {
            Submission::create((int) self::$formId, '127.0.0.1');
        }
    }

    public function tearDown(): void
    {
        self::cleanupResiduals();
        Session::remove('user_id');
        Auth::forgetCache();
        unset($_GET['page']);
        Response::setRecordHalt(false);
    }

    public function testEnvelopeCalculaTotalYPaginas(): void
    {
        $page1 = Submission::byFormFilteredPaginated((int) self::$formId, '', '', 1, 15);
        assertSame(25, $page1['total'], 'total global del formulario');
        assertSame(2, $page1['pages'], '25 filas / 15 por página = 2 páginas');
        assertSame(15, count($page1['items']), 'la página 1 trae 15 filas');
        assertSame(1, $page1['page']);

        $page2 = Submission::byFormFilteredPaginated((int) self::$formId, '', '', 2, 15);
        assertSame(10, count($page2['items']), 'la página 2 trae las 10 restantes');
        assertSame(2, $page2['page']);
    }

    public function testPaginaFueraDeRangoClampaALaUltima(): void
    {
        $out = Submission::byFormFilteredPaginated((int) self::$formId, '', '', 42, 15);
        assertSame(2, $out['page'], 'la página 42 clampa a la última (2)');
        assertSame(10, count($out['items']), 'y trae la última página, no vacía');
    }

    public function testPerPagePersonalizado(): void
    {
        $ten = Submission::byFormFilteredPaginated((int) self::$formId, '', '', 1, 10);
        assertSame(3, $ten['pages'], '25 / 10 = 3 páginas');
        assertSame(10, count($ten['items']));
    }

    public function testRowsDevuelveHtmlYPaginadorJson(): void
    {
        self::authAs(self::$userId);
        Response::setRecordHalt(true);
        ob_start();
        $controller = new AnalyticsController();
        $controller->rows((int) self::$formId);
        $body = (string) ob_get_clean();
        $halt = Response::lastHalt();

        $json = json_decode($body, true);
        assertTrue(is_array($json), 'rows() responde JSON');
        assertSame(true, $json['ok'] ?? null);
        assertSame(1, $json['page'] ?? null);
        assertSame(2, $json['pages'] ?? null);
        assertSame(25, $json['total'] ?? null);
        assertTrue(str_contains($json['html'] ?? '', 'paginate-item'), 'el html trae filas de la página');
        assertTrue(str_contains($json['pagination'] ?? '', 'paginate'), 'el html trae el paginador');
        assertSame('json', $halt['type'] ?? null);
        assertSame(200, $halt['status'] ?? null);
    }

    public function testRowsSegundaPagina(): void
    {
        self::authAs(self::$userId);
        $_GET['page'] = '2';
        Response::setRecordHalt(true);
        ob_start();
        (new AnalyticsController())->rows((int) self::$formId);
        $body = (string) ob_get_clean();

        $json = json_decode($body, true);
        assertSame(true, $json['ok'] ?? null);
        assertSame(2, $json['page'] ?? null);
    }

    public function testRowsSinPermisoDevuelve403(): void
    {
        self::authAs(self::$otherId); // no es el dueño
        Response::setRecordHalt(true);
        ob_start();
        (new AnalyticsController())->rows((int) self::$formId);
        $body = (string) ob_get_clean();
        $halt = Response::lastHalt();

        $json = json_decode($body, true);
        assertSame(false, $json['ok'] ?? null);
        assertSame('json', $halt['type'] ?? null);
        assertSame(403, $halt['status'] ?? null);
    }

    /* ---------- Helpers ---------- */

    private static function authAs(int $userId): void
    {
        Auth::assumeLoggedIn($userId);
        Auth::forgetCache();
    }

    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
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
        $db->execute("DELETE FROM forms WHERE title LIKE 'Paginación-%'");
        $db->execute("DELETE FROM users WHERE email LIKE '%@test.local'");
        self::$userId = null;
        self::$otherId = null;
        self::$formId = null;
    }
}