<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\FillController;
use App\Core\Config;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Models\Field;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\Uploader;

/**
 * P10 — Uploads de archivos.
 *
 * Cubre el servicio de storage (whitelist de formatos, MIME real, tamaño,
 * cantidad, nombres aleatorios, anti path-traversal, purge) y su integración
 * en el envío público (metadata persistida, rollback cuando otra pregunta
 * falla, descarga autenticada y no servible como archivo estático).
 */
class P10UploadsTest
{
    private static ?int $userId = null;
    private static int $formId = 0;
    private static string $slug = '';

    /** Archivos en storage/uploads al empezar cada test (para comparar deltas). */
    private static int $baseStored = 0;

    /** Submissions del formulario de pruebas al empezar cada test. */
    private static int $baseSubs = 0;

    /** Rutas (relativas a baseDir) de los adjuntos que ya existían al empezar. */
    private static array $baseFiles = [];

    /**
     * Deja el árbol de storage como estaba: borra todo lo que este test escribió
     * (los tests que necesitan conservar el archivo lo comprueban ANTES).
     */
    public function tearDown(): void
    {
        if (self::$formId > 0) {
            Uploader::purgeForm(self::$formId);
        }
        // Solo se borra lo que NO está en el snapshot inicial y tampoco está
        // referenciado por ninguna respuesta: así el suite nunca toca un
        // adjunto real de otro formulario del entorno de desarrollo.
        $keep = array_merge(self::$baseFiles, self::referencedFiles());
        foreach (self::listStored() as $rel) {
            if (!in_array($rel, $keep, true)) {
                @unlink(Uploader::baseDir() . '/' . $rel);
            }
        }
    }

    /**
     * Rutas de adjuntos referenciadas por CUALQUIER respuesta de la BD
     * (protege los archivos reales si el entorno tiene datos de desarrollo).
     *
     * @return string[]
     */
    private static function referencedFiles(): array
    {
        $rows = Database::getInstance(Config::db())
            ->fetchAll("SELECT value FROM answers WHERE value LIKE '%\"stored\"%'");
        $out = [];
        foreach ($rows as $row) {
            foreach (Uploader::decode((string) $row['value']) ?? [] as $meta) {
                if (!empty($meta['stored'])) {
                    $out[] = (string) $meta['stored'];
                }
            }
        }
        return $out;
    }

    public function setUp(): void
    {
        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::$baseFiles  = self::listStored();
        self::$baseStored = count(self::$baseFiles);
        self::$baseSubs   = self::$formId > 0 ? Form::submissionCount(self::$formId) : 0;

        // El scope de rate limit del submit es por formulario (submit:{id}); la
        // suite reutiliza el MISMO form en todos los tests de la clase, así que
        // sin reset, a partir del décimo envío el guard de submit responde 429 y
        // (ahora que el `return;` es honesto) el envío se corta de verdad. Este
        // test no cubre el rate limiter (lo cubre RateLimiterTest): se aísla.
        if (self::$formId > 0) {
            RateLimiter::reset('submit:' . self::$formId, \App\Core\Request::ip());
        }

        if (self::$userId === null) {
            self::cleanupResiduals();
            $suffix = bin2hex(random_bytes(4));
            self::$userId = User::create('Test P10', 'p10-' . $suffix . '@test.local', 'password');
            self::$formId = Form::create((int) self::$userId, 'Form P10 ' . $suffix);
            // `Form::create` deja el form en borrador y el submit público solo
            // acepta forms publicados; estos tests cubren UPLOADS, no la máquina
            // de estados, así que se publica explícitamente.
            Form::setStatus(self::$formId, 'published');
            $form = Form::find(self::$formId);
            self::$slug = (string) ($form['slug'] ?? '');
        }
    }

    /* ---------- Uploader: validación y límites ---------- */

    public function testGuardaPdfValidoYDevuelveMetadata(): void
    {
        $tmp = self::tmpFile('%PDF-1.4 fake pdf body');
        $res = Uploader::storeMany(self::filesFor($tmp, 'cv.pdf'));

        assertTrue($res['ok'], 'un PDF válido debe aceptarse');
        assertNull($res['error']);
        assertTrue(is_array($res['value']), 'el valor debe ser la metadata');

        $meta = $res['value'];
        assertSame('cv.pdf', $meta['name']);
        assertSame('application/pdf', $meta['mime']);
        assertSame(1, preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.pdf$#', $meta['stored']), 'ruta YYYY/MM/nombre-aleatorio.ext');
        assertTrue(Uploader::absolutePath($meta['stored']) !== null, 'absolutePath debe resolver la metadata');

        @unlink($tmp);
    }

    public function testRechazaMimeNoPermitidoYNoGuardaNada(): void
    {
        // Script con extensión inofensiva: lo rechaza el MIME real, no el nombre.
        $tmp = self::tmpFile("#!/bin/sh\necho pwned\n", 'nota.txt');
        $res = Uploader::storeMany(self::filesFor($tmp, 'nota.txt'));

        assertFalse($res['ok'], 'un script no puede aceptarse');
        assertSame('file_type', $res['error']);
        assertSame(0, self::storedDelta(), 'no debe quedar ningún archivo huérfano');
        @unlink($tmp);
    }

    public function testRechazaEjecutablePorSuExtension(): void
    {
        $tmp = self::tmpFile('contenido cualquiera', 'virus.php');
        $res = Uploader::storeMany(self::filesFor($tmp, 'virus.php'));

        assertFalse($res['ok'], 'una extensión ejecutable se rechaza siempre');
        assertSame('blocked_extension', $res['error']);
        assertSame(0, self::storedDelta());
        @unlink($tmp);
    }

    public function testRechazaExtensionBloqueadaPorElCampo(): void
    {
        // Contenido PDF real (finfo lo ve como application/pdf) pero extensión
        // prohibida por el campo: se rechaza ANTES de escribir nada.
        $tmp = self::tmpFile('%PDF-1.4 real header', 'malicioso.php');
        $res = Uploader::storeMany(self::filesFor($tmp, 'malicioso.php'), ['accept' => 'pdf']);

        assertFalse($res['ok']);
        assertSame('blocked_extension', $res['error']);
        assertSame(0, self::storedDelta());
        @unlink($tmp);
    }

    public function testRespetaExtensionPermitidaDelCampo(): void
    {
        $tmp = self::tmpFile('id,tipo,total' . "\n1,USD,10\n", 'datos.csv');
        $ok = Uploader::storeMany(self::filesFor($tmp, 'datos.csv'), ['accept' => 'pdf, csv']);
        assertTrue($ok['ok'], 'csv está en la accept del campo');
        @unlink($tmp);

        $tmp2 = self::tmpFile('notas del cliente', 'notas.txt');
        $ko = Uploader::storeMany(self::filesFor($tmp2, 'notas.txt'), ['accept' => 'pdf']);
        assertFalse($ko['ok'], 'el campo que solo admite pdf rechaza un texto');
        assertSame('file_type', $ko['error']);
        @unlink($tmp2);

        // El contenido manda sobre el nombre: un PDF renombrado a .csv se
        // guarda con la extensión real (.pdf) y el campo que admite pdf lo acepta.
        $tmp3 = self::tmpFile('%PDF-1.4 renombrado', 'datos.csv');
        $ok2 = Uploader::storeMany(self::filesFor($tmp3, 'datos.csv'), ['accept' => 'pdf']);
        assertTrue($ok2['ok'], 'el MIME real manda sobre la extensión del cliente');
        assertTrue(str_ends_with((string) $ok2['value']['stored'], '.pdf'), 'se almacena con la extensión del MIME detectado');
        Uploader::deleteStored((string) $ok2['value']['stored']);
        @unlink($tmp3);
    }

    public function testRechazaArchivoDemasiadoGrande(): void
    {
        $tmp = self::tmpFile(str_repeat('A', 3 * 1024 * 1024), 'grande.pdf');
        $res = Uploader::storeMany(self::filesFor($tmp, 'grande.pdf'), ['max_size_mb' => 1]);

        assertFalse($res['ok']);
        assertSame('file_too_big', $res['error']);
        assertSame(1, $res['params']['max']);
        assertSame(0, self::storedDelta());
        @unlink($tmp);
    }

    public function testRechazaDemasiadosArchivos(): void
    {
        $a = self::tmpFile('%PDF-1.4 a', 'a.pdf');
        $b = self::tmpFile('%PDF-1.4 b', 'b.pdf');
        $c = self::tmpFile('%PDF-1.4 c', 'c.pdf');
        $res = Uploader::storeMany(
            self::multiFilesFor([[$a, 'a.pdf'], [$b, 'b.pdf'], [$c, 'c.pdf']]),
            ['max_files' => 2, 'multiple' => true]
        );

        assertFalse($res['ok']);
        assertSame('file_count', $res['error']);
        @unlink($a);
        @unlink($b);
        @unlink($c);
    }

    public function testCampoSimpleGuardaSoloElPrimero(): void
    {
        $a = self::tmpFile('%PDF-1.4 a', 'a.pdf');
        $b = self::tmpFile('%PDF-1.4 b', 'b.pdf');
        $res = Uploader::storeMany(self::multiFilesFor([[$a, 'a.pdf'], [$b, 'b.pdf']]));

        assertTrue($res['ok']);
        assertTrue(is_array($res['value']) && isset($res['value']['stored']));
        assertSame(1, self::storedDelta(), 'el segundo archivo se descarta');
        @unlink($b);
    }

    public function testErrorDeSubidaDePhpSeReporta(): void
    {
        $res = Uploader::storeMany([
            ['name' => 'x.pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0],
        ]);

        assertFalse($res['ok']);
        assertSame('upload_too_big', $res['error']);
    }

    /* ---------- Uploader: recorrido seguro de la metadata ---------- */

    public function testAbsolutePathRechazaTraversalYAbsorbe(): void
    {
        assertNull(Uploader::absolutePath('../../../etc/passwd'), 'traversal fuera de uploads');
        assertNull(Uploader::absolutePath('2026/09/x.pdf'), 'no cumple el patrón YYYY/MM/nombre');
        assertNull(Uploader::absolutePath(''), 'ruta vacía');
        assertNull(Uploader::absolutePath("2026/09/aa\0.pdf"), 'byte nulo');
    }

    public function testDecodeYDisplayDeMetadata(): void
    {
        $tmp = self::tmpFile('%PDF-1.4 metadata', 'mi curriculum.pdf');
        $res = Uploader::storeMany(self::filesFor($tmp, 'mi curriculum.pdf'));
        $json = (string) Uploader::encode($res['value']);

        assertTrue(Uploader::isAttachmentJson($json), 'la metadata es JSON de adjunto');
        assertFalse(Uploader::isAttachmentJson('texto normal'), 'un texto no es metadata');
        assertFalse(Uploader::isAttachmentJson('{"otra":"cosa"}'), 'otra cosa no es metadata');

        $metas = Uploader::decode($json);
        assertNotNull($metas);
        assertSame(1, count($metas));
        assertSame('mi curriculum.pdf', $metas[0]['name']);

        $display = Uploader::displayValue($json);
        assertTrue(str_contains($display, 'mi curriculum.pdf'), 'el display muestra el nombre');
        assertFalse(str_contains($display, '/'), 'el display NO expone la ruta interna');
        @unlink($tmp);
    }

    public function testListaMultipleDecodificaYPurga(): void
    {
        $a = self::tmpFile('%PDF-1.4 a', 'a.pdf');
        $b = self::tmpFile('id,valor' . "\n1,2\n", 'b.csv');
        $res = Uploader::storeMany(
            [
                ['name' => 'a.pdf', 'tmp_name' => $a, 'error' => UPLOAD_ERR_OK, 'size' => 11],
                ['name' => 'b.csv', 'tmp_name' => $b, 'error' => UPLOAD_ERR_OK, 'size' => 12],
            ],
            ['multiple' => true]
        );
        assertTrue($res['ok']);

        $json = (string) Uploader::encode($res['value']);
        $metas = Uploader::decode($json);
        assertSame(2, count($metas));
        Uploader::rollback($metas);
        assertSame(0, self::storedDelta(), 'rollback borra ambos archivos');
        @unlink($a);
        @unlink($b);
    }

    public function testSanitizeNameQuitaRutasYCaracteres(): void
    {
        assertSame('cv.pdf', Uploader::sanitizeName('../../cv.pdf'));
        assertSame('reporte final.pdf', Uploader::sanitizeName("reporte\x0Cfinal.pdf"));
        assertSame('cv.pdf', Uploader::sanitizeName('cv.pdf'), 'un nombre limpio no cambia');
        assertSame('archivo.pdf', Uploader::sanitizeName('   ', 'pdf'));
    }

    public function testLimitesGlobalesAcotanElCampo(): void
    {
        $global = Uploader::maxSizeMb();
        assertTrue($global >= 1);
        assertTrue(Uploader::maxSizeMb(['max_size_mb' => 999]) <= $global, 'el campo nunca amplía el tope global');
        assertTrue(Uploader::maxSizeMb(['max_size_mb' => 3]) <= $global, 'un tope menor se respeta tal cual');
        assertTrue(in_array('pdf', Uploader::globalExts(), true));
        assertFalse(in_array('php', Uploader::globalExts(), true), 'php nunca está en la whitelist');
    }

    /* ---------- Integración: envío público ---------- */

    public function testSubmitConArchivoPersisteMetadataYNoExponeRuta(): void
    {
        $fieldId = self::fileField('Adjunta tu CV', true, []);
        $tmp = self::tmpFile('%PDF-1.4 curriculum', 'cv.pdf');

        $this->setPostEnVio('p10-ok', []);
        $_FILES = ['field_' . $fieldId => self::filesFor($tmp, 'cv.pdf')];

        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        $html = (string) ob_get_clean();
        $_FILES = [];

        assertSame(1, self::subsDelta(), 'el envío con archivo se guarda');

        $sub = self::lastSubmission();
        $value = (string) ($sub['answers'][$fieldId] ?? '');
        assertTrue(Uploader::isAttachmentJson($value), 'la respuesta guarda la metadata');
        assertFalse(str_contains($html, 'storage/uploads'), 'el HTML nunca expone la ruta de storage');
        assertTrue(Uploader::exists((string) Uploader::decode($value)[0]['stored']));
        @unlink($tmp);
    }

    public function testSubmitArchivoInvalidoNoGuardaYReportaError(): void
    {
        $fieldId = self::fileField('Sube un documento', true, ['accept' => 'pdf']);
        $tmp = self::tmpFile("malicioso\n", 'roto.pdf');

        $this->setPostEnVio('p10-bad', []);
        $_FILES = ['field_' . $fieldId => self::filesFor($tmp, 'roto.pdf')];

        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        $html = (string) ob_get_clean();
        $_FILES = [];

        assertSame(0, self::subsDelta(), 'un archivo rechazado no crea submission');
        assertTrue(str_contains($html, 'no está permitido') || str_contains($html, 'not allowed'), 'se informa el tipo no permitido');
        @unlink($tmp);
    }

    public function testRollbackCuandoOtraFallaNoDejaArchivos(): void
    {
        $fileId = self::fileField('CV', false, []);
        $mailId = Field::create(self::$formId, 'email', 'Tu correo', '', '', true, [], [], 99);
        $tmp = self::tmpFile('%PDF-1.4 ok', 'ok.pdf');

        $this->setPostEnVio('p10-rollback', ['field_' . $mailId => 'no-es-un-email']);
        $_FILES = ['field_' . $fileId => self::filesFor($tmp, 'ok.pdf')];

        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();
        $_FILES = [];

        assertSame(0, self::storedDelta(), 'el archivo se borra si otra respuesta es inválida');
        @unlink($tmp);
    }

    public function testArchivoOpcionalVacioNoEsError(): void
    {
        $fieldId = self::fileField('Opcional', false, []);

        $this->setPostEnVio('p10-empty', []);
        $_FILES = ['field_' . $fieldId => [
            'name' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE], 'size' => [0],
        ]];

        $controller = new FillController();
        ob_start();
        $controller->submit(self::$slug);
        ob_end_clean();
        $_FILES = [];

        assertSame(1, self::subsDelta(), 'sin archivo y no obligatorio: envío válido');
    }

    public function testRenderMuestraInputDeArchivoConEnctype(): void
    {
        $fieldId = self::fileField('Sube tu archivo', true, ['accept' => 'pdf,png', 'max_size_mb' => 4]);

        ob_start();
        \App\Core\View::render('fill/show', [
            'form'     => Form::find(self::$formId),
            'fields'   => Field::byForm(self::$formId),
            'csrf'     => 'token-x',
            'settings' => Form::settings(self::$formId),
        ], null);
        $html = (string) ob_get_clean();

        assertTrue(str_contains($html, 'enctype="multipart/form-data"'), 'el form de fill es multipart');
        assertTrue(str_contains($html, 'name="field_' . $fieldId . '[]"'), 'el input de archivo usa el nombre del campo');
        assertTrue(str_contains($html, 'accept=".pdf,.png"'), 'respeta las extensiones configuradas');
        $maxBytes = Uploader::maxSizeMb(['max_size_mb' => 4]) * 1024 * 1024;
        assertTrue(str_contains($html, 'data-vf-file-max-size="' . $maxBytes . '"'), 'expone el límite de tamaño real (acotado por php.ini) al cliente');
    }

    /* ---------- Descarga autenticada ---------- */

    public function testDescargaResuelveRutaYAutoriza(): void
    {
        $fieldId = self::fileField('CV', false, []);
        $tmp = self::tmpFile('%PDF-1.4 download', 'descarga.pdf');
        $res = Uploader::storeMany(self::filesFor($tmp, 'descarga.pdf'));
        $meta = $res['value'];

        $sid = self::crearSubmissionCon($fieldId, (string) Uploader::encode($meta));
        $answerId = (int) Database::getInstance(Config::db())
            ->fetch('SELECT id FROM answers WHERE submission_id = ' . $sid)['id'];

        $row = \App\Models\Answer::findWithContext($answerId);
        assertNotNull($row, 'la descarga resuelve el contexto de la respuesta');
        assertSame(self::$formId, (int) $row['form_id']);
        assertNotNull(Uploader::absolutePath((string) $meta['stored']), 'el archivo existe en disco');

        $files = \App\Models\Answer::attachmentsBySubmission($sid);
        assertSame(1, count($files));
        assertSame('descarga.pdf', $files[0]['name']);
        assertSame(0, $files[0]['index']);
        assertFalse($files[0]['has_thumb'], 'un PDF no genera vista previa');
        @unlink($tmp);
    }

    public function testAdjuntosDeSubmissionAplanadosPorCampo(): void
    {
        $fieldId = self::fileField('Documentos', false, ['multiple' => true]);
        $a = self::tmpFile('%PDF-1.4 a', 'a.pdf');
        $b = self::tmpFile('k,v' . "\n1,2\n", 'b.csv');
        $res = Uploader::storeMany(self::multiFilesFor([[$a, 'a.pdf'], [$b, 'b.csv']]), ['multiple' => true]);

        $sid = self::crearSubmissionCon($fieldId, (string) Uploader::encode($res['value']));
        $files = \App\Models\Answer::attachmentsBySubmission($sid);
        assertSame(2, count($files), 'los dos archivos quedan aplanados con su índice');
        assertSame(1, $files[1]['index']);

        // Purge por submission: ambos archivos desaparecen del disco.
        assertSame(2, Uploader::purgeSubmission($sid));
        assertSame(0, self::storedDelta());
        @unlink($a);
        @unlink($b);
    }

    public function testImagenGeneraVistaPreviaWebp(): void
    {
        $fieldId = self::fileField('Foto', false, []);
        // PNG válido 1x1 (decodificable por GD para generar el webp).
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        $tmp = self::tmpFile($png, 'foto.png');
        $res = Uploader::storeMany(self::filesFor($tmp, 'foto.png'));

        assertTrue($res['ok'], 'una imagen válida debe aceptarse');
        $meta = $res['value'];
        assertSame('image/png', $meta['mime']);
        assertSame(1, preg_match('#^\d{4}/\d{2}/[a-f0-9]{16,}-thumb\.webp$#', (string) $meta['thumb']), 'la subida genera un webp de vista previa');
        $thumbAbs = Uploader::absolutePath((string) $meta['thumb']);
        assertNotNull($thumbAbs, 'el thumb existe en disco');
        assertSame('image/webp', (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $thumbAbs));

        $sid = self::crearSubmissionCon($fieldId, (string) Uploader::encode($meta));
        $files = \App\Models\Answer::attachmentsBySubmission($sid);
        assertSame(1, count($files));
        assertTrue($files[0]['has_thumb'], 'la superficie de la vista previa expone que hay thumb');
        assertSame('image/png', $files[0]['mime']);

        // La purga se lleva el original Y el thumb (archivos + metadatos).
        assertSame(2, Uploader::purgeSubmission($sid));
        assertSame(0, self::storedDelta());
        @unlink($tmp);
    }

    public function testPurgePorFormularioBorraTodo(): void
    {
        $fieldId = self::fileField('Comprobante', false, []);
        $tmp = self::tmpFile('%PDF-1.4 comprobante', 'comprobante.pdf');
        $res = Uploader::storeMany(self::filesFor($tmp, 'comprobante.pdf'));
        $meta = $res['value'];

        self::crearSubmissionCon($fieldId, (string) Uploader::encode($meta));
        assertSame(1, self::storedDelta(), 'el adjunto está en disco');

        assertTrue(Uploader::purgeForm(self::$formId) >= 1);
        assertSame(0, self::storedDelta(), 'borrar el form limpia los adjuntos');
        @unlink($tmp);
    }

    public function testArchivoNoEsServiblePorLaWeb(): void
    {
        $tmp = self::tmpFile('%PDF-1.4 privado', 'privado.pdf');
        $res = Uploader::storeMany(self::filesFor($tmp, 'privado.pdf'));
        assertTrue($res['ok'], 'setup: el archivo se guardó');
        $meta = $res['value'];

        $abs = Uploader::absolutePath($meta['stored']);
        assertNotNull($abs);
        $publicRoot = dirname(__DIR__, 2) . '/public';
        assertFalse(str_starts_with($abs, $publicRoot), 'los adjuntos NUNCA viven bajo public/');
        @unlink($tmp);
    }

    /* ---------- Helpers ---------- */

    private function fileField(string $label, bool $required, array $validations): int
    {
        static $pos = 200;
        // Cada test arranca con un formulario limpio (sin otros campos que
        // exigen valor ni adjuntos de tests anteriores).
        Database::getInstance(Config::db())->execute('DELETE FROM fields WHERE form_id = ' . self::$formId);
        return Field::create(self::$formId, 'file', $label, '', 'PDF o imagen', $required, [], $validations, ++$pos);
    }

    private static function crearSubmissionCon(int $fieldId, string $value): int
    {
        $db = Database::getInstance(Config::db());
        $sid = Submission::create(self::$formId, '198.51.100.9', 'P10UploadsTest');
        \App\Models\Answer::create($sid, $fieldId, $value);
        return $sid;
    }

    private static function lastSubmission(): array
    {
        $db = Database::getInstance(Config::db());
        $id = (int) $db->fetch('SELECT id FROM submissions WHERE form_id = ' . self::$formId . ' ORDER BY id DESC LIMIT 1')['id'];
        $data = Submission::findWithAnswers($id);
        return $data ?? ['answers' => []];
    }

    private static function tmpFile(string $content, string $name = 'archivo.txt'): string
    {
        $dir = sys_get_temp_dir() . '/p10uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . bin2hex(random_bytes(6)) . '-' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Reproduce la forma real de `$_FILES` de un input
     * `<input type="file" name="field_X[]">`: arrays paralelos.
     */
    private static function filesFor(string $tmp, string $name): array
    {
        return [[
            'name'     => [$name],
            'tmp_name' => [$tmp],
            'error'    => [UPLOAD_ERR_OK],
            'size'     => [(int) filesize($tmp)],
        ]];
    }

    /** Varios archivos en el mismo campo (mismo shape de $_FILES). */
    private static function multiFilesFor(array $files): array
    {
        $out = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        foreach ($files as [$tmp, $name]) {
            $out['name'][]     = $name;
            $out['tmp_name'][] = $tmp;
            $out['error'][]    = UPLOAD_ERR_OK;
            $out['size'][]     = (int) filesize($tmp);
        }
        return [$out];
    }

    private static function countStored(): int
    {
        return count(self::listStored());
    }

    /**
     * Rutas relativas a Uploader::baseDir() de los adjuntos reales en disco
     * (ignora .gitkeep y los .htaccess defensivos).
     *
     * @return string[]
     */
    private static function listStored(): array
    {
        $dir = Uploader::baseDir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || in_array($f->getFilename(), ['.gitkeep', '.htaccess'], true)) {
                continue;
            }
            $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        }
        sort($out);
        return $out;
    }

    /** Archivos creados por este test (delta contra el inicio). */
    private static function storedDelta(): int
    {
        return self::countStored() - self::$baseStored;
    }

    /** Submissions creadas por este test (delta contra el inicio). */
    private static function subsDelta(): int
    {
        return Form::submissionCount(self::$formId) - self::$baseSubs;
    }

    private function setPostEnVio(string $token, array $post): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_USER_AGENT'] = 'P10UploadsTest';
        $_SERVER['CONTENT_LENGTH'] = '0';
        $_SESSION['_csrf_token'] = $token;
        $post['_token'] = $token;
        $_POST = $post;
    }


    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        $users = $db->fetchAll("SELECT id FROM users WHERE email LIKE '%@test.local'");
        foreach ($users as $u) {
            $forms = $db->fetchAll('SELECT id FROM forms WHERE user_id = ' . (int) $u['id']);
            foreach ($forms as $f) {
                $fields = $db->fetchAll('SELECT id FROM fields WHERE form_id = ' . (int) $f['id']);
                foreach ($fields as $fl) {
                    $db->execute('DELETE FROM answers WHERE field_id = ' . (int) $fl['id']);
                }
                $db->execute('DELETE FROM fields WHERE form_id = ' . (int) $f['id']);
                $subs = $db->fetchAll('SELECT id FROM submissions WHERE form_id = ' . (int) $f['id']);
                foreach ($subs as $s) {
                    $db->execute('DELETE FROM answers WHERE submission_id = ' . (int) $s['id']);
                }
                $db->execute('DELETE FROM submissions WHERE form_id = ' . (int) $f['id']);
            }
            $db->execute('DELETE FROM forms WHERE user_id = ' . (int) $u['id']);
        }
        $db->execute("DELETE FROM users WHERE email LIKE '%@test.local'");
    }
}
