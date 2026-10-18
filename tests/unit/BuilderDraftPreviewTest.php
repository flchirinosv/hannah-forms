<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FillController;
use App\Controllers\ReviewController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Models\Field;
use App\Models\Form;
use App\Models\Role;
use App\Models\User;
use App\Services\Uploader;

/**
 * Preview real (modelo Elementor) — F0–F7 de la preview en otra pestaña.
 *
 * Lo que se fija aquí es lo que la preview NO debe hacer, que es lo caro de
 * romper:
 *
 *  - No persiste NADA: ni `Submission`, ni `answers`, ni archivos en disco.
 *    Una preview que ensucia la BD convierte «probar el formulario» en un
 *    problema de datos reales, y el dueño deja de usarla.
 *  - El borrador (`?draft=1`) es del DUEÑO: un revisor no tiene nada que ver
 *    ahí. `?draft=1` sin `canEdit` es 403, igual que el gate de preview para
 *    un ajeno.
 *  - `sendReview()` limpia el borrador: si no, el dueño vuelve al constructor
 *    y sigue viendo cambios que ya no representan lo que se sometió a revisión
 *    (el revisor solo ve la estructura VIVA).
 *  - El rollback de archivos es SIEMPRE, incluso con errores de validación:
 *    un `.pdf` que se quedó huérfano en `storage/uploads` no lo ve nadie y
 *    crece sin control.
 */
class BuilderDraftPreviewTest
{
    private static ?int $ownerId = null;
    private static ?int $reviewerId = null;
    private static ?int $strangerId = null;
    private static ?int $reviewRoleId = null;
    private static ?int $formId = null;
    private static ?string $slug = null;

    public function setUp(): void
    {
        try {
            $db = Database::getInstance(Config::db());
            $db->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::cleanupResiduals();

        $suffix = bin2hex(random_bytes(4));
        self::$ownerId = User::create('Preview Owner', 'preview-owner-' . $suffix . '@test.local', 'clave-larga-123', 2);
        self::$formId = Form::create((int) self::$ownerId, 'Formulario preview ' . $suffix);
        self::$slug = (string) Form::find((int) self::$formId, false)['slug'];

        // Revisor puro: `forms.review` y nada más de alcance (mismo patrón que
        // FormReviewTest). Si tuviera `forms.manage_all`, el gate pasaría por
        // el permiso equivocado y no se probaría la rama del revisor real.
        $db = Database::getInstance(Config::db());
        $db->execute(
            'INSERT INTO roles (name, description) VALUES (:n, :d)',
            ['n' => 'preview-revisor-' . $suffix, 'd' => 'rol de test preview']
        );
        self::$reviewRoleId = (int) $db->lastInsertId();
        Role::setPermissions((int) self::$reviewRoleId, ['dashboard.view', 'forms.review']);
        self::$reviewerId = User::create('Preview Reviewer', 'preview-reviewer-' . $suffix . '@test.local', 'clave-larga-123', (int) self::$reviewRoleId);

        // Un ajeno sin ningún permiso sobre el formulario (rol user default).
        self::$strangerId = User::create('Preview Stranger', 'preview-stranger-' . $suffix . '@test.local', 'clave-larga-123', 2);
    }

    public function tearDown(): void
    {
        Response::setRecordHalt(false);
        Auth::forgetCache();
        Session::destroy();
        unset($_POST, $_FILES, $_SERVER['HTTP_X_CSRF_TOKEN']);
        $_POST = [];
        $_FILES = [];
        self::cleanupResiduals();
    }

    /* ---------- GET: gates ---------- */

    public function testElDueñoVeLaPreviewDeUnFormularioPublicado(): void
    {
        Form::setStatus((int) self::$formId, 'published');
        Field::create((int) self::$formId, 'text', 'Pregunta preview', '', '', false, [], [], 1);
        self::authAs((int) self::$ownerId);

        $r = $this->getPreview((int) self::$formId);

        assertSame(200, (int) ($r['halt']['code'] ?? 200), 'el dueño ve su preview sin cortes');
        assertTrue(str_contains($r['html'], 'Vista previa'), 'el banner de preview debe estar');
        assertTrue(str_contains($r['html'], 'Pregunta preview'), 'el campo live se pinta');
    }

    public function testUnAjenoRecibe403EnLaPreview(): void
    {
        Form::setStatus((int) self::$formId, 'published');
        self::authAs((int) self::$strangerId);

        $r = $this->getPreview((int) self::$formId);

        assertSame(403, (int) ($r['halt']['code'] ?? 0), 'un ajeno no ve la preview');
    }

    public function testElRevisorVeLaPreviewSoloConFormularioEnRevision(): void
    {
        Field::create((int) self::$formId, 'text', 'Pregunta en revisión', '', '', false, [], [], 1);
        Form::setStatus((int) self::$formId, 'in_review');
        self::authAs((int) self::$reviewerId);

        $r = $this->getPreview((int) self::$formId);
        assertSame(200, (int) ($r['halt']['code'] ?? 200), 'el revisor ve la preview de un in_review');
        assertTrue(str_contains($r['html'], 'Pregunta en revisión'));

        // Un borrador NO es asunto del revisor.
        Form::setStatus((int) self::$formId, 'draft');
        Auth::forgetCache();
        $r2 = $this->getPreview((int) self::$formId);
        assertSame(403, (int) ($r2['halt']['code'] ?? 0), 'el revisor no ve la preview de un borrador');
    }

    public function testElRevisorNoPuedeVerElBorradorDelDueño(): void
    {
        Form::setStatus((int) self::$formId, 'in_review');
        Form::saveDraft((int) self::$formId, ['fields' => [], 'title' => 'Borrador privado']);
        self::authAs((int) self::$reviewerId);

        $r = $this->getPreview((int) self::$formId, ['draft' => '1']);

        assertSame(403, (int) ($r['halt']['code'] ?? 0), '?draft=1 sin canEdit es 403');
    }

    /* ---------- GET: el borrador del dueño ---------- */

    public function testElDueñoVeElBorradorConDraft1YSinDraft1VeElLive(): void
    {
        // Estructura VIVA: una pregunta.
        Field::create((int) self::$formId, 'text', 'Versión viva', '', '', false, [], [], 1);
        // Borrador del dueño: otra pregunta distinta (aún no commiteada).
        Form::saveDraft((int) self::$formId, [
            'title'    => 'Preview draft',
            'fields'   => [
                ['_uid' => 'a1', 'type' => 'text', 'label' => 'Versión borrador', 'is_required' => false, 'parent_id' => null],
            ],
            'settings' => [],
        ]);
        self::authAs((int) self::$ownerId);

        // Sin ?draft=1 → estructura viva.
        $live = $this->getPreview((int) self::$formId);
        assertTrue(str_contains($live['html'], 'Versión viva'), 'sin draft=1 se ve la estructura publicada');
        assertFalse(str_contains($live['html'], 'Versión borrador'), 'el borrador NO se cuela en la preview normal');

        // Con ?draft=1 → borrador del dueño.
        $draft = $this->getPreview((int) self::$formId, ['draft' => '1']);
        assertTrue(str_contains($draft['html'], 'Versión borrador'), 'con draft=1 se ve el borrador');
        assertFalse(str_contains($draft['html'], 'Versión viva'), 'el borrador pisa la estructura viva');
    }

    /* ---------- POST: no persiste ---------- */

    public function testElPostDeLaPreviewNoCreaSubmission(): void
    {
        $q = Field::create((int) self::$formId, 'text', 'Tu nombre', '', '', true, [], [], 1);
        Form::setStatus((int) self::$formId, 'published');
        self::authAs((int) self::$ownerId);

        $this->postPreviewSubmit((int) self::$formId, ['field_' . $q => 'Valor de prueba']);

        assertSame(0, Form::submissionCount((int) self::$formId), 'la preview nunca persiste submissions');
    }

    public function testElPostDeLaPreviewConErroresReRenderizaSinPersistir(): void
    {
        $q = Field::create((int) self::$formId, 'text', 'Obligatoria', '', '', true, [], [], 1);
        Form::setStatus((int) self::$formId, 'published');
        self::authAs((int) self::$ownerId);

        $r = $this->postPreviewSubmit((int) self::$formId, []);

        assertSame(0, Form::submissionCount((int) self::$formId), 'un error de validación no crea submission');
        assertTrue(str_contains($r['html'], 'Vista previa'), 'el re-render de errores sigue siendo preview');
        assertTrue(str_contains($r['html'], 'Obligatoria'), 'el campo obligatorio se pinta de nuevo');
    }

    /* ---------- POST: rollback de archivos ---------- */

    public function testElPostDeLaPreviewRevientaLosArchivosSubidos(): void
    {
        $fileId = Field::create((int) self::$formId, 'file', 'Adjunta un PDF', '', '', true, [], ['accept' => 'pdf'], 1);
        // Otro campo obligatorio inválido: la validación falla DESPUÉS de que
        // el archivo ya esté en disco, que es el caso en el que el rollback
        // tiene que correr.
        $mailId = Field::create((int) self::$formId, 'email', 'Tu correo', '', '', true, [], [], 2);
        Form::setStatus((int) self::$formId, 'published');
        self::authAs((int) self::$ownerId);

        $antes = self::countStoredFiles();
        $tmp = self::tmpFile('%PDF-1.4 contenido de prueba', 'preview.pdf');

        $this->postPreviewSubmit((int) self::$formId, ['field_' . $mailId => 'no-es-un-email'], [
            'field_' . $fileId => [[
                'name'     => ['preview.pdf'],
                'tmp_name' => [$tmp],
                'error'    => [UPLOAD_ERR_OK],
                'size'     => [(int) filesize($tmp)],
            ]],
        ]);

        @unlink($tmp);

        assertSame(0, Form::submissionCount((int) self::$formId), 'el submit de preview no persiste submissions');
        assertSame($antes, self::countStoredFiles(), 'el archivo subido se revierte aunque haya errores de validación');
    }

    /* ---------- sendReview limpia el borrador ---------- */

    public function testMandarARevisionLimpiaElBorrador(): void
    {
        Field::create((int) self::$formId, 'text', 'Pregunta', '', '', false, [], [], 1);
        Form::setStatus((int) self::$formId, 'draft');
        Form::saveDraft((int) self::$formId, [
            'title'    => 'Borrador por limpiar',
            'fields'   => [],
            'settings' => [],
        ]);
        self::authAs((int) self::$ownerId);

        // sendReview exige CSRF ( Csrf::verifyRequest() ), como el POST real.
        $token = 'token-preview-test';
        Session::set('_csrf_token', $token);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_POST = ['_token' => $token];

        Response::setRecordHalt(true);
        ob_start();
        try {
            (new ReviewController())->sendReview((int) self::$formId);
        } finally {
            ob_end_clean();
        }
        Response::setRecordHalt(false);

        $form = Form::find((int) self::$formId, false);
        assertSame('in_review', (string) $form['status'], 'el formulario queda en revisión');
        assertNull(Form::draft((int) self::$formId), 'el borrador se limpia al mandarlo a revisión');
    }

    /* ---------- Helpers ---------- */

    private function getPreview(int $formId, array $query = []): array
    {
        $_GET = $query;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_POST = [];

        Response::setRecordHalt(true);
        ob_start();
        try {
            (new FillController())->preview($formId);
        } finally {
            $html = (string) ob_get_clean();
        }
        $halt = Response::lastHalt();
        Response::setRecordHalt(false);
        $_GET = [];

        return ['halt' => is_array($halt) ? $halt : [], 'html' => $html];
    }

    private function postPreviewSubmit(int $formId, array $post, array $files = []): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_POST = $post;
        $_FILES = $files;

        Response::setRecordHalt(true);
        ob_start();
        try {
            (new FillController())->previewSubmit($formId);
        } finally {
            $html = (string) ob_get_clean();
        }
        $halt = Response::lastHalt();
        Response::setRecordHalt(false);
        $_FILES = [];

        return ['halt' => is_array($halt) ? $halt : [], 'html' => $html];
    }

    private static function countStoredFiles(): int
    {
        $dir = Uploader::baseDir();
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !in_array($f->getFilename(), ['.gitkeep', '.htaccess'], true)) {
                $n++;
            }
        }
        return $n;
    }

    private static function tmpFile(string $content, string $name): string
    {
        $dir = sys_get_temp_dir() . '/vfpreview';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . bin2hex(random_bytes(6)) . '-' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    private static function authAs(int $userId): void
    {
        Auth::assumeLoggedIn($userId);
        Auth::forgetCache();
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        $db->execute("DELETE FROM users WHERE email LIKE 'preview-owner-%'");
        $db->execute("DELETE FROM users WHERE email LIKE 'preview-reviewer-%'");
        $db->execute("DELETE FROM users WHERE email LIKE 'preview-stranger-%'");
        $db->execute("DELETE FROM roles WHERE name LIKE 'preview-revisor-%'");

        self::$ownerId = null;
        self::$reviewerId = null;
        self::$strangerId = null;
        self::$reviewRoleId = null;
        self::$formId = null;
        self::$slug = null;
    }
}
