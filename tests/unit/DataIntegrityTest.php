<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Models\Field;
use App\Models\Form;

/**
 * F1 — Integridad de datos: la fila manda sobre el archivo.
 *
 * Los tres bugs que cubre esta clase tienen la misma raíz: el filesystem no
 * participa en las transacciones de la BD, y el código estaba escrito como si
 * sí. En los tres, el orden de las operaciones convertía un fallo reversible en
 * pérdida irrecuperable.
 *
 *   F1.1 — Se purgaban los adjuntos ANTES de confirmar. Un rollback posterior
 *          devolvía la fila `answers` con su ruta `stored` intacta pero sin el
 *          binario: `GET /attachments/...` daba 404 para siempre. Al revés, un
 *          archivo huérfano en disco se puede limpiar después.
 *   F1.2 — `Form::duplicate()` hacía 1+N escrituras sin transacción: un fallo en
 *          el campo N dejaba un formulario en `draft` con N-1 campos y el slug
 *          consumido.
 *   F1.3 — `answers.value` es TEXT y el tamaño solo lo acotaba
 *          `validations.max_length`, que es opcional: un textarea enorme daba
 *          `ERROR 1406` y el catch borraba los adjuntos ya subidos.
 *   F1.4 — `Request::userAgent()` cortaba por bytes contra un VARCHAR(255)
 *          utf8mb4: un multibyte partido daba `ERROR 1366` y un 500 por envío.
 *
 * F1.1 se verifica por orden en el código fuente porque los tres controllers
 * emiten `Response::redirect()`/`Response::json()`, que cortan el proceso fuera
 * de CLI: no hay forma de invocarlos y observar el orden real. El invariante
 * «la purga va después del commit» sí es comprobable de forma directa, y es lo
 * único que un refactor podría romper sin que nadie se entere.
 */
class DataIntegrityTest
{
    private static string $email = 'integridad@test.local';
    private static int $userId = 0;
    private static int $formId = 0;
    private static bool $envLoaded = false;

    public function setUp(): void
    {
        self::loadEnv();

        try {
            $this->db()->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        if (self::$userId === 0) {
            $db = $this->db();
            $db->execute(
                'INSERT INTO users (name, email, password_hash, role_id, is_active)
                 VALUES (:n, :e, :p, 2, 1)',
                ['n' => 'Integridad', 'e' => self::$email, 'p' => 'x']
            );
            self::$userId = (int) $db->lastInsertId();
        }
    }

    public function tearDown(): void
    {
        $db = $this->db();
        foreach ($this->testForms() as $fid) {
            $db->execute('DELETE FROM forms WHERE id = :id', ['id' => $fid]);
        }
        $db->execute('DELETE FROM users WHERE email = :e', ['e' => self::$email]);
        self::$userId = 0;
    }

    /* ---------- F1.1 · purga en dos fases: recoger ANTES, desenlazar DESPUÉS ----------
     *
     * Las dos mitas del invariante, porque las dos se rompen solas:
     *   · pendingFor*() antes del DELETE, o la cascada se lleva los `answers` y
     *     no queda nada que leer → el binario queda huérfano para siempre.
     *   · purgePaths() después del commit, o un rollback deja la fila `answers`
     *     viva apuntando a un binario inexistente → 404 permanente en
     *     /attachments/{id}, que no tiene arreglo.
     */

    public function testElBuilderRecogeLasRutasAntesDeBorrarElCampo(): void
    {
        $this->assertPurgeAfter(
            'app/Controllers/BuilderController.php',
            'Uploader::pendingForField(',
            'Field::deleteField(',
            'saveStructure() borra el campo sin leer sus rutas antes: la cascada se lleva los answers '
                . 'y el archivo se queda huérfano en disco para siempre'
        );
    }

    public function testElBuilderDesenlazaDespuesDelCommit(): void
    {
        $this->assertPurgeAfter(
            'app/Controllers/BuilderController.php',
            '$db->commit();',
            'Uploader::purgePaths(',
            'saveStructure() purga dentro de la transacción: si luego hay un rollback, la fila answers '
                . 'vuelve y el archivo ya no existe'
        );
    }

    public function testElBorradoDeFormularioRecogeLasRutasAntesDeBorrarLasFilas(): void
    {
        $this->assertPurgeAfter(
            'app/Controllers/FormController.php',
            'Uploader::pendingForForm(',
            'Form::delete($id)',
            'FormController::delete() borra sin leer las rutas antes: la cascada borra los answers y '
                . 'los adjuntos quedan huérfanos'
        );
    }

    public function testElBorradoDeFormularioDesenlazaDespuesDeBorrarLasFilas(): void
    {
        $this->assertPurgeAfter(
            'app/Controllers/FormController.php',
            'Form::delete($id)',
            'Uploader::purgePaths(',
            'FormController::delete() purga antes de borrar: si el DELETE falla, el formulario sigue '
                . 'existiendo con respuestas apuntando a adjuntos inexistentes'
        );
    }

    public function testElBorradoDeRespuestaRecogeLasRutasAntesDeBorrarLasFilas(): void
    {
        $this->assertPurgeAfter(
            'app/Controllers/ResponsesController.php',
            'Uploader::pendingForSubmission(',
            'Submission::delete($sid)',
            'ResponsesController::delete() borra sin leer las rutas antes: mismo huérfano que en el formulario'
        );
    }

    public function testElBorradoDeRespuestaDesenlazaDespuesDeBorrarLasFilas(): void
    {
        $this->assertPurgeAfter(
            'app/Controllers/ResponsesController.php',
            'Submission::delete($sid)',
            'Uploader::purgePaths(',
            'ResponsesController::delete() desenlaza antes de borrar las filas: mismo fallo que en el formulario'
        );
    }

    /* ---------- F1.2 · duplicate es atómico ---------- */

    public function testDuplicateRevierteElFormularioEnteroSiUnCampoFalla(): void
    {
        $db = $this->db();
        $baseId = $this->baseForm('Atomicidad');
        Field::create($baseId, 'text', 'Uno', '', '', false, [], [], 1);

        // Inyección de fallo que revienta en el INSERT del CAMPO, no en el del
        // formulario: es lo único que hace que la transacción tenga algo que
        // revertir. Si el primer INSERT es el que falla, hasta una
        // implementación sin transacción pasa el test.
        //
        // Vías probadas y descartadas por autocurarse, por si alguien retoma esto:
        //  · `user_id` inexistente → falla el INSERT de `forms`, el primero.
        //  · desbordar `label` (VARCHAR 190) con el modo estricto apagado → el
        //    servidor trunca en silencio y el duplicado sale bien.
        //  · un JSON corrupto en `logic`/`options`/`validations` → json_decode
        //    lo da como null y el modelo lo normaliza a null.
        //
        // Se usa un CHECK temporal sobre `fields`: «form_id <= N», con N = el
        // form_id más alto que hay ahora. Las filas existentes la cumplen (por
        // eso el ALTER no se rechaza al validarlas) y los campos de la COPIA la
        // incumplen, porque pertenecen al formulario recién insertado.
        // Dos caminos que no sirvieron: `position < 0` hace fallar el propio
        // ALTER (MariaDB valida las filas existentes) y `id <= N` se rechaza
        // porque `id` es AUTO_INCREMENT, no puede ir en un CHECK.
        // Es DDL, así que el try/finally lo baja siempre: si el test muere a
        // mitad, la tabla no queda con la restricción puesta.
        // `SET` y DDL no pasan por binds; de ahí el exec() sobre la conexión,
        // que es el uso legítimo de getPdo().
        $pdo = $this->db()->getPdo();
        $slugsBefore = $this->slugs();
        $maxFormId = (int) $db->fetch('SELECT COALESCE(MAX(form_id), 0) AS m FROM fields')['m'];
        $pdo->exec('ALTER TABLE fields ADD CONSTRAINT chk_test_integridad_falla CHECK (form_id <= ' . $maxFormId . ')');
        $failed = false;
        try {
            Form::duplicate($baseId);
        } catch (\Throwable $e) {
            $failed = true;
        } finally {
            $pdo->exec('ALTER TABLE fields DROP CONSTRAINT chk_test_integridad_falla');
        }
        assertTrue($failed, 'duplicate() debió fallar: el CHECK temporal bloquea el insert de campos nuevos');

        // El formulario se insertó ANTES de fallar al copiar el primer campo.
        // Sin transacción ese INSERT queda vivo.
        $new = array_values(array_diff($this->slugs(), $slugsBefore));
        assertTrue(
            $new === [],
            'duplicate() dejó un formulario a medias tras el fallo: ' . implode(', ', $new)
                . '. Sin transacción el INSERT de forms no se revierte y el slug queda consumido.'
        );
        assertEquals(
            1,
            (int) $db->fetch('SELECT COUNT(*) AS c FROM fields WHERE form_id = :f', ['f' => $baseId])['c'],
            'el formulario original debe quedarse con su único campo: nada se copió'
        );
    }

    public function testDuplicateCopiaLaJerarquiaDeSecciones(): void
    {
        $baseId = $this->baseForm('Jerarquía');
        $section = Field::create($baseId, 'section', 'Sección', '', '', false, [], [], 1);
        Field::create($baseId, 'text', 'Hija', '', '', false, [], ['max_length' => 30], 1, $section);
        Field::create($baseId, 'text', 'Suelta', '', '', false, [], [], 2);

        $newId = Form::duplicate($baseId);
        $fields = Field::byForm($newId);

        $byLabel = [];
        foreach ($fields as $f) {
            $byLabel[(string) $f['label']] = $f;
        }
        assertTrue(isset($byLabel['Hija'], $byLabel['Suelta'], $byLabel['Sección']), 'faltan campos en la copia');

        $newSectionId = (int) $byLabel['Sección']['id'];
        $children = 0;
        foreach ($fields as $f) {
            if ($f['parent_id'] !== null) {
                assertEquals(
                    $newSectionId,
                    (int) $f['parent_id'],
                    'la hija debe apuntar a la sección COPIADA, no a la original'
                );
                $children++;
            }
        }
        assertEquals(1, $children, 'la copia debe conservar exactamente una hija colgada');
        assertEquals(
            30,
            (int) $byLabel['Hija']['validations']['max_length'],
            'las validaciones deben copiarse intactas'
        );
        $this->extraForms[] = $newId;
    }

    /* ---------- F1.3 · el valor cabe, o es un error de validación ---------- */

    public function testUnTextoDemasiadoLargoEsUnErrorDeValidacionNoUnErrorDeServidor(): void
    {
        $result = $this->validateField(
            ['type' => 'textarea', 'label' => 'Comentario', 'validations' => []],
            str_repeat('a', 16001)
        );
        assertTrue(is_string($result), 'un texto de 16 001 caracteres debe rechazarse con un mensaje, no reventar');
        assertTrue(
            str_contains((string) $result, '16000'),
            'el mensaje debe decir el tope: ' . $result
        );
    }

    public function testElTopeMideCaracteresNoBytes(): void
    {
        // 4 bytes por carácter (emoji): 16 000 = 64 000 bytes, por debajo de los
        // 65 535 de TEXT. Con el antiguo corte por bytes esto reventaba.
        $fits = $this->validateField(
            ['type' => 'text', 'label' => 'Emoji', 'validations' => []],
            str_repeat('😀', 16000)
        );
        assertSame(true, $fits, '16 000 emoji (64 000 bytes) deben caber en TEXT');
        assertTrue(
            strlen(str_repeat('😀', 16000)) < 65535,
            'precondición del test: 16 000 emoji deben caber en la columna'
        );
    }

    public function testUnaMatrizConClavesGigantesSeRechazaComoValidacion(): void
    {
        // Las CLAVES de una matriz las manda el cliente y no se validan contra
        // las filas del autor, así que un mapa enorme es alcanzable sin tocar
        // ningún campo de texto. El valor acaba en la misma columna TEXT.
        $hostile = [];
        for ($i = 0; $i < 4000; $i++) {
            $hostile[str_repeat('r', 40) . $i] = 'col';
        }
        $result = $this->validateField(
            ['type' => 'matrix', 'label' => 'Matriz', 'validations' => [], 'options' => []],
            $hostile
        );
        assertTrue(is_string($result), 'una matriz de 4 000 claves debe rechazarse con un mensaje');
    }

    public function testUnaRespuestaNormalPasaElTope(): void
    {
        assertSame(
            true,
            $this->validateField(['type' => 'text', 'label' => 'Nombre', 'validations' => ['max_length' => 100]], 'Ana'),
            'una respuesta corta no debe verse afectada por el tope'
        );
    }

    /* ---------- F1.4 · el user agent no se parte ---------- */

    public function testElUserAgentNoSeParteEnElByteLimite(): void
    {
        // 254 ASCII + un € (3 bytes) + relleno: al byte 255 cae dentro del €,
        // que es exactamente donde substr() partía la secuencia.
        $ua = str_repeat('a', 254) . '€' . str_repeat('b', 40);
        $_SERVER['HTTP_USER_AGENT'] = $ua;

        $cut = Request::userAgent();

        assertTrue(
            $cut !== '',
            'un User-Agent válido no debe descartarse'
        );
        assertTrue(
            function_exists('mb_check_encoding') ? mb_check_encoding($cut, 'UTF-8') : true,
            'el User-Agent recortado debe seguir siendo UTF-8 válido: mb_strlen() lo cortó a ' . strlen($cut) . ' bytes'
        );
        assertTrue(mb_strlen($cut, 'UTF-8') <= 255, 'no debe exceder 255 caracteres');
        assertTrue(str_starts_with($cut, str_repeat('a', 254)), 'debe conservar el prefijo intacto');
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    public function testUnUserAgentConBytesInvalidosSeDescartaEnLieuDeReventar(): void
    {
        // Bytes sueltos a propósito: antes llegaban al INSERT y producían
        // ERROR 1366 (un 500 en cada envío); ahora se descartan.
        $_SERVER['HTTP_USER_AGENT'] = "Mozilla/5.0 \xC3\x28 roto";
        $cut = Request::userAgent();
        assertTrue(
            $cut === '' || mb_check_encoding($cut, 'UTF-8'),
            'un User-Agent corrupto debe devolverse vacío, nunca una cadena UTF-8 inválida'
        );
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    public function testSinUserAgentDevuelveCadenaVacia(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        assertSame('', Request::userAgent());
    }

    /* ---------- ayudas ---------- */

    /** @var int[] */
    private array $extraForms = [];

    private function assertPurgeAfter(string $rel, string $write, string $purge, string $why): void
    {
        // Se comparan solo las LÍNEAS DE CÓDIGO: un comentario que menciona el
        // método (p. ej. explicando por qué va en ese orden) no cuenta, o el
        // test pasa/falla por prosa en vez de por el orden real de las llamadas.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
        $code = '';
        foreach (explode("\n", $src) as $n => $line) {
            $trimmed = ltrim($line);
            $isComment = str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*');
            $code .= ($isComment ? '' : $line) . "\n";
        }
        $iWrite = strpos($code, $write);
        $iPurge = strpos($code, $purge);
        assertTrue($iWrite !== false, 'no se encontró «' . trim($write) . '» en ' . $rel);
        assertTrue($iPurge !== false, 'no se encontró «' . $purge . '» en ' . $rel);
        assertTrue(
            (int) $iPurge > (int) $iWrite,
            $why . ' (en ' . $rel . ' la purga aparece en la línea '
                . (substr_count($code, "\n", 0, (int) $iPurge) + 1)
                . ' y la escritura en la ' . (substr_count($code, "\n", 0, (int) $iWrite) + 1) . ')'
        );
    }

    private function validateField(array $field, $value)
    {
        $ref = new \ReflectionMethod(\App\Controllers\FillController::class, 'validateField');
        $ref->setAccessible(true);
        return $ref->invoke(null, $field, $value, 'es');
    }

    private function baseForm(string $suffix): int
    {
        $db = $this->db();
        $db->execute(
            'INSERT INTO forms (user_id, title, description, slug, status, settings)
             VALUES (:u, :t, :d, :s, :status, :st)',
            [
                'u'     => self::$userId,
                't'     => 'Informe ' . $suffix,
                'd'     => '',
                's'     => 'informe-' . strtolower($suffix) . '-' . $this->stamp(),
                'status' => 'draft',
                'st'    => '{}',
            ]
        );
        $id = (int) $db->lastInsertId();
        $this->extraForms[] = $id;
        return $id;
    }

    private function stamp(): string
    {
        return substr(md5(uniqid('', true)), 0, 8);
    }

    /** @return string[] */
    private function slugs(): array
    {
        $rows = $this->db()->fetchAll('SELECT slug FROM forms');
        return array_map(static fn($r) => (string) $r['slug'], $rows);
    }

    /** @return int[] */
    private function testForms(): array
    {
        $rows = $this->db()->fetchAll('SELECT id FROM forms WHERE user_id = :u', ['u' => self::$userId]);
        return array_merge($this->extraForms, array_map(static fn($r) => (int) $r['id'], $rows));
    }

    private function db(): Database
    {
        return Database::getInstance(Config::db());
    }

    /**
     * El runner solo carga `config/autoload.php`, no `config/bootstrap.php`, así
     * que el `.env` no está cargado y las credenciales saldrían vacías. Mismo
     * patrón que FormTest y P7ShareTest.
     */
    private static function loadEnv(): void
    {
        if (!self::$envLoaded) {
            \App\Core\Env::load(dirname(__DIR__, 2) . '/.env');
            self::$envLoaded = true;
        }
    }
}
