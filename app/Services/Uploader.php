<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\UploadSettings;
use App\Core\Config;
use App\Core\Request;
use App\Models\Answer;
use App\Models\Submission;

/**
 * P10 — Uploads de archivos.
 *
 * Servicio único de entrada/salida de adjuntos:
 *  - valida el archivo subido (error de PHP, tamaño, cantidad, tipo real);
 *  - lo guarda FUERA de public/ con nombre aleatorio (nunca el del cliente);
 *  - devuelve metadata serializable que se guarda en answers.value (JSON);
 *  - resuelve la ruta real solo en el servidor (nunca desde el cliente);
 *  - purga los archivos huérfanos cuando se borra un form/submission/campo.
 *
 * Security: el MIME se detecta con finfo sobre el archivo temporal (nunca se
 * confía en $_FILES['type']); la extensión sale de una whitelist mime→ext (sin
 * ejecutables ni SVG); la descarga siempre va como attachment + nosniff.
 */
final class Uploader
{
    /** Formatos permitidos: mime detectado => extensiones válidas. */
    private const MIME_EXT = [
        'application/pdf'                                                        => ['pdf'],
        'text/plain'                                                              => ['txt', 'md', 'log', 'csv'],
        'text/csv'                                                                => ['csv'],
        'text/markdown'                                                           => ['md'],
        'application/json'                                                        => ['json'],
        'image/jpeg'                                                              => ['jpg', 'jpeg'],
        'image/png'                                                               => ['png'],
        'image/gif'                                                               => ['gif'],
        'image/webp'                                                              => ['webp'],
        'application/zip'                                                         => ['zip'],
        'application/msword'                                                      => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel'                                                => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'      => ['xlsx'],
        'application/vnd.ms-powerpoint'                                           => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/rtf'                                                          => ['rtf'],
        'application/vnd.oasis.opendocument.text'                                 => ['odt'],
        'application/vnd.oasis.opendocument.spreadsheet'                          => ['ods'],
    ];

    /** Extensiones que nunca se aceptan aunque el MIME cuadre (XSS / ejecución). */
    private const DENY_EXT = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'js', 'html', 'htm', 'xhtml', 'svg', 'exe', 'bat', 'cmd', 'sh', 'py', 'cgi', 'pl', 'jsp', 'asp', 'aspx', 'jar', 'vbs', 'jscript', 'swf', 'com', 'msi', 'dll'];

    /** Errores de subida de PHP que deben reportarse con su código legible. */
    private const ERRORS = [
        UPLOAD_ERR_INI_SIZE   => 'upload_too_big',
        UPLOAD_ERR_FORM_SIZE  => 'upload_too_big',
        UPLOAD_ERR_PARTIAL    => 'upload_partial',
        UPLOAD_ERR_NO_FILE    => 'no_file',
        UPLOAD_ERR_NO_TMP_DIR => 'server_error',
        UPLOAD_ERR_CANT_WRITE => 'server_error',
        UPLOAD_ERR_EXTENSION  => 'blocked_extension',
    ];

    private const DEFAULT_MAX_MB   = 10;
    private const DEFAULT_MAX_FILES = 5;

    // ---------------------------------------------------------------- storage

    /** Raíz de uploads (fuera de public/): config paths.uploads. */
    public static function baseDir(): string
    {
        $path = trim((string) Config::get('paths.uploads', ''));
        if ($path === '') {
            $storage = trim((string) Config::get('paths.storage', ''));
            // Nunca armar "/uploads" (raíz del FS) por un config vacío.
            $path = $storage !== '' ? $storage . '/uploads' : dirname(__DIR__, 2) . '/storage/uploads';
        }
        return rtrim($path, '/');
    }

    public static function maxSizeMb(array $validations = []): int
    {
        $global = UploadSettings::maxSizeMb();
        // El límite real lo impone PHP (upload_max_filesize): si es menor que
        // el configurado se usa el de PHP, para no prometer un tamaño imposible
        // y recibir un error de subida confuso.
        $ini = Request::iniBytes((string) ini_get('upload_max_filesize'));
        if ($ini > 0) {
            $global = min($global, (int) ceil($ini / (1024 * 1024)));
        }
        $perField = isset($validations['max_size_mb']) ? (int) $validations['max_size_mb'] : 0;
        $mb = $perField > 0 ? min($perField, $global) : $global;
        return max(1, $mb);
    }

    public static function maxFiles(array $validations = []): int
    {
        $global = UploadSettings::maxFiles();
        $perField = isset($validations['max_files']) ? (int) $validations['max_files'] : 0;
        $n = $perField > 0 ? min($perField, $global) : $global;
        return max(1, $n);
    }

    /** Lista de extensiones aceptadas por el campo (vacía = todas las permitidas). */
    public static function allowedExts(array $validations = []): array
    {
        $raw = $validations['accept'] ?? '';
        if (is_string($raw) && $raw !== '') {
            $parts = preg_split('/[\s,;]+/', strtolower($raw)) ?: [];
        } elseif (is_array($raw)) {
            $parts = array_map('strtolower', array_map('strval', $raw));
        } else {
            $parts = [];
        }

        $out = [];
        foreach ($parts as $p) {
            $p = ltrim(trim((string) $p), '.');
            if ($p !== '' && !in_array($p, self::DENY_EXT, true) && preg_match('/^[a-z0-9]{1,10}$/', $p)) {
                $out[$p] = true;
            }
        }

        // Intersección con la whitelist global: el campo nunca amplía la lista.
        if ($out !== []) {
            $out = array_intersect_key($out, array_flip(self::globalExts()));
        }

        return array_keys($out);
    }

    /** Extensiones permitidas globalmente (derivadas de MIME_EXT). */
    public static function globalExts(): array
    {
        $out = [];
        foreach (self::MIME_EXT as $exts) {
            foreach ($exts as $e) {
                $out[$e] = true;
            }
        }
        return array_keys($out);
    }

    /** Atributo accept para el input (extensions, como espera el builder). */
    public static function acceptAttr(array $validations = []): string
    {
        $exts = self::allowedExts($validations);
        return $exts === [] ? '' : '.' . implode(',.', $exts);
    }

    public static function isMultiple(array $validations = []): bool
    {
        return !empty($validations['multiple']);
    }

    // ------------------------------------------------------------------ store

    /**
     * Valida y guarda todos los archivos de un campo.
     *
     * @param array  $files       entrada de $_FILES[$name] (normalizada a lista)
     * @param array  $validations validations del campo
     * @param string $locale      es|en (para códigos de error traducibles)
     * @return array{ok: bool, error: string|null, params: array, value: mixed}
     *                    value = null | meta | lista de metas
     */
    public static function storeMany(array $files, array $validations = [], string $locale = 'es'): array
    {
        $files = self::normalizeFiles($files);

        // Sin archivos: el campo requerido se resuelve en el controller
        // (respuesta vacía), aquí solo se ignoran los slots vacíos.
        $files = array_values(array_filter($files, static fn(array $f): bool => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));

        $maxFiles = self::maxFiles($validations);
        if (count($files) > $maxFiles) {
            return self::fail('file_count', ['max' => $maxFiles], $locale);
        }

        $maxBytes = self::maxSizeMb($validations) * 1024 * 1024;
        $allowed   = self::allowedExts($validations);
        $multiple  = self::isMultiple($validations);
        $stored    = [];

        foreach ($files as $f) {
            $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                $res = self::fail(self::ERRORS[$err] ?? 'server_error', [], $locale);
                self::rollback($stored);
                return $res;
            }

            $tmp = (string) ($f['tmp_name'] ?? '');
            // is_uploaded_file() solo aplica bajo SAPI web; en CLI (runner de
            // tests) se valida únicamente que el temporal exista.
            $isRealUpload = PHP_SAPI === 'cli' ? is_file($tmp) : is_uploaded_file($tmp);
            if ($tmp === '' || !is_file($tmp) || !$isRealUpload) {
                $res = self::fail('server_error', [], $locale);
                self::rollback($stored);
                return $res;
            }

            $size = (int) filesize($tmp);
            if ($size <= 0 || $size > $maxBytes) {
                $res = self::fail('file_too_big', ['max' => self::maxSizeMb($validations)], $locale);
                self::rollback($stored);
                return $res;
            }

            $clientName = self::baseName((string) ($f['name'] ?? 'archivo'));
            $clientExt  = strtolower((string) pathinfo($clientName, PATHINFO_EXTENSION));
            if (in_array($clientExt, self::DENY_EXT, true)) {
                \App\Core\SecurityLog::record('upload.denied_ext', 'extensión prohibida: .' . $clientExt . ' (' . $clientName . ')');
                $res = self::fail('blocked_extension', [], $locale);
                self::rollback($stored);
                return $res;
            }

            $mime = self::detectMime($tmp);
            $exts = self::MIME_EXT[$mime] ?? [];
            if ($exts === []) {
                $res = self::fail('file_type', [], $locale);
                self::rollback($stored);
                return $res;
            }

            // La extensión efectiva sale del MIME detectado (nunca del cliente
            // por sí solo). Si el MIME admite varias extensiones de la misma
            // familia (text/plain: txt/md/csv) se prefiere la del cliente, que
            // solo gana si pertenece a esa familia detectada.
            $ext = $clientExt !== '' && in_array($clientExt, $exts, true) ? $clientExt : $exts[0];

            // Si el campo restringe formatos, debe coincidir la extensión
            // efectiva (o, en la misma familia, la del cliente).
            if ($allowed !== [] && !in_array($ext, $allowed, true)) {
                $res = self::fail('file_type', [], $locale);
                self::rollback($stored);
                return $res;
            }

            $meta = self::write($tmp, $ext, $mime, $size, $clientName);
            if ($meta === null) {
                self::rollback($stored);
                return self::fail('server_error', [], $locale);
            }
            $stored[] = $meta;
        }

        if ($stored === []) {
            return ['ok' => true, 'error' => null, 'params' => [], 'value' => null, 'locale' => $locale];
        }
        if (!$multiple) {
            // Un campo de un solo archivo nunca guarda más de uno.
            foreach (array_slice($stored, 1) as $extra) {
                self::deleteStored((string) $extra['stored']);
            }
            return ['ok' => true, 'error' => null, 'params' => [], 'value' => $stored[0], 'locale' => $locale];
        }

        return ['ok' => true, 'error' => null, 'params' => [], 'value' => $stored, 'locale' => $locale];
    }

    /**
     * Escribe el archivo temporal en disco con nombre aleatorio.
     *
     * @return array|null metadata o null si falló el write
     */
    private static function write(
        string $tmp,
        string $ext,
        string $mime,
        int $size,
        string $clientName
    ): ?array {
        $dir = self::dirFor(time());
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        // Defensa extra: si el servidor sirve accidentalmente storage/ y hay
        // Apache delante, .htaccess bloquea el acceso directo.
        $guard = $dir . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\nDeny from all\nOptions -Indexes\n");
        }

        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        $ok = (PHP_SAPI !== 'cli' && is_uploaded_file($tmp)) ? @move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
        if (!$ok) {
            $ok = @copy($tmp, $dest) && @unlink($tmp);
        }
        if (!$ok || !is_file($dest)) {
            return null;
        }
        @chmod($dest, 0640);

        // Relativo a la RAÍZ de uploads: «YYYY/MM/<nombre>» (no solo el nombre).
        $rel   = self::relative(self::baseDir(), $dest);
        $thumb = null;
        if (str_starts_with($mime, 'image/') && ($t = self::makeThumb($dest)) !== null) {
            // makeThumb devuelve el nombre del archivo dentro del mismo YYYY/MM.
            $thumb = self::yearMonth($rel) . '/' . $t;
        }

        return [
            'name'   => self::sanitizeName($clientName, $ext),
            'stored' => $rel,
            'size'   => $size,
            'mime'   => $mime,
            'thumb'  => $thumb,
            'sha'    => substr((string) hash_file('sha256', $dest), 0, 32),
        ];
    }

    /**
     * Thumbnail opcional para imágenes (sin GD no se genera: null).
     * Nunca se sirve directamente: requiere autorización (AttachmentController).
     */
    private static function makeThumb(string $src): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            return null;
        }
        $data = @file_get_contents($src);
        if ($data === false) {
            return null;
        }
        $im = @imagecreatefromstring($data);
        if ($im === false) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w > 1600 || $h > 1600) {
            $scale = min(1600 / $w, 1600 / $h);
            $nw    = max(1, (int) round($w * $scale));
            $nh    = max(1, (int) round($h * $scale));
            $dst   = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($im);
            $im = $dst;
        }
        $name = bin2hex(random_bytes(8)) . '-thumb.webp';
        $ok   = @imagewebp($im, dirname($src) . '/' . $name, 80);
        imagedestroy($im);
        return $ok ? $name : null;
    }

    // -------------------------------------------------------------- resolución

    /**
     * Normaliza $_FILES[$name] (ya sea de un input simple o multiple) a una
     * lista de archivos ['name','tmp_name','error','size'].
     */
    public static function normalizeFiles(mixed $entry): array
    {
        if (!is_array($entry) || $entry === []) {
            return [];
        }

        // Una entrada de $_FILES no es una lista (no tiene claves enteras).
        $isEntry = !isset($entry[0]) || !is_array($entry[0]);

        if ($isEntry) {
            if (!is_array($entry['name'] ?? null)) {
                return [$entry];   // input simple: name/tmp_name/error planos
            }
            $out = [];
            foreach (array_keys($entry['name']) as $i) {
                $out[] = [
                    'name'     => $entry['name'][$i]     ?? '',
                    'tmp_name' => $entry['tmp_name'][$i] ?? '',
                    'error'    => $entry['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $entry['size'][$i]     ?? 0,
                ];
            }
            return $out;
        }

        // Lista de entradas: se aplana (tolerante a las dos formas de entrada).
        $out = [];
        foreach ($entry as $item) {
            if (is_array($item)) {
                $out = array_merge($out, self::normalizeFiles($item));
            }
        }
        return $out;
    }

    /**
     * Traduce la respuesta a un valor persistible (JSON) o la deja como está.
     */
    public static function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && !self::isAttachmentJson($value)) {
            return $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Decodifica una respuesta de adjuntos a meta | lista de metas | null. */
    public static function decode(?string $value): ?array
    {
        if ($value === null || $value === '' || !self::isAttachmentJson($value)) {
            return null;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }
        if (array_is_list($decoded)) {
            $out = [];
            foreach ($decoded as $item) {
                if (is_array($item) && isset($item['stored'])) {
                    $out[] = $item;
                }
            }
            return $out === [] ? null : $out;
        }
        return isset($decoded['stored']) ? [$decoded] : null;
    }

    /** ¿El valor es JSON de adjuntos generado por storeMany()? */
    public static function isAttachmentJson(string $value): bool
    {
        $t = ltrim($value);
        if ($t === '' || $t[0] !== '{' && $t[0] !== '[') {
            return false;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || $decoded === []) {
            return false;
        }
        $sample = array_is_list($decoded) ? ($decoded[0] ?? null) : $decoded;
        return is_array($sample) && isset($sample['stored'], $sample['name']);
    }

    /**
     * Ruta ABSOLUTA de un archivo almacenado, validada contra la raíz.
     * Devuelve null si la ruta es inválida o sale de storage/uploads (traversal).
     */
    public static function absolutePath(string $stored): ?string
    {
        $stored = ltrim(trim($stored), '/');
        if ($stored === '' || str_contains($stored, "\0") || str_contains($stored, '..')) {
            return null;
        }
        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{8,64}(\.[a-z0-9]{1,10})?(-thumb\.webp)?$#', $stored)) {
            return null;
        }
        $base = self::baseDir();
        $full = $base . '/' . $stored;
        $real = realpath($full);
        $realBase = realpath($base);
        if ($real === false || $realBase === false || !str_starts_with($real, $realBase . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $real;
    }

    public static function exists(string $stored): bool
    {
        return self::absolutePath($stored) !== null;
    }

    public static function deleteStored(string $stored): bool
    {
        $full = self::absolutePath($stored);
        if ($full === null) {
            return false;
        }
        if (!is_file($full)) {
            // Ya no está: no hay nada que hacer y no es un error.
            return true;
        }
        return @unlink($full);
    }

    public static function rollback(array $metas): void
    {
        foreach ($metas as $meta) {
            if (is_array($meta)) {
                self::deleteStored((string) ($meta['stored'] ?? ''));
                if (!empty($meta['thumb'])) {
                    self::deleteStored((string) $meta['thumb']);
                }
            }
        }
    }

    // ---------------------------------------------------------------- purgado
    //
    // P10/F1.1 — el purgado va en DOS fases y el orden importa en las dos:
    //
    //   1. pendingFor*()  lee las filas `answers` y devuelve las rutas a borrar.
    //      TIENE que ejecutarse ANTES de borrar la fila dueña: los ON DELETE
    //      CASCADE se llevan por delante los `answers`, y después no queda nada
    //      que leer (el archivo se queda huérfano en disco para siempre).
    //   2. purgePaths()   borra esas rutas del disco. Se ejecuta DESPUÉS del
    //      commit: si el guardado falla y hay rollback, un huérfano se puede
    //      limpiar en un mantenimiento, pero un adjunto fantasma (la fila
    //      `answers` viva apuntando a un binario que ya no existe) deja un 404
    //      permanente en GET /attachments/{id} que no se arregla solo.
    //
    // Los purge*() de una sola fase se quedan para cuando el llamador todavia
    // tiene las filas; los tres controladores usan el patrón de dos fases.

    /** Rutas a borrar de todas las respuestas de un submission (aún con filas). */
    public static function pendingForSubmission(int $submissionId): array
    {
        if ($submissionId <= 0) {
            return [];
        }
        return self::pathsOf(Answer::bySubmission($submissionId));
    }

    /** Rutas a borrar de todos los submissions de un formulario (aún con filas). */
    public static function pendingForForm(int $formId): array
    {
        if ($formId <= 0) {
            return [];
        }
        return self::pathsOf(Submission::attachmentValuesByForm($formId));
    }

    /** Rutas a borrar de todas las respuestas de un campo (aún con filas). */
    public static function pendingForField(int $fieldId): array
    {
        if ($fieldId <= 0) {
            return [];
        }
        return self::pathsOf(Answer::attachmentValuesByField($fieldId));
    }

    /**
     * Borra del disco las rutas devueltas por los pendingFor*().
     * Un archivo ya ausente no es un error: cuenta como borrado.
     *
     * @param string[] $paths
     */
    public static function purgePaths(array $paths): int
    {
        $n = 0;
        foreach (array_unique($paths) as $path) {
            $path = (string) $path;
            if ($path === '' || self::deleteStored($path)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @param  array<int,array<string,mixed>> $rows filas con columna `value`
     * @return string[]
     */
    private static function pathsOf(array $rows): array
    {
        $paths = [];
        foreach ($rows as $row) {
            $metas = self::decode((string) ($row['value'] ?? ''));
            if ($metas === null) {
                continue;
            }
            foreach ($metas as $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                $stored = (string) ($meta['stored'] ?? '');
                if ($stored !== '') {
                    $paths[] = $stored;
                }
                if (!empty($meta['thumb'])) {
                    $paths[] = (string) $meta['thumb'];
                }
            }
        }
        return $paths;
    }

    /** Borra los adjuntos de todas las respuestas de un submission. */
    public static function purgeSubmission(int $submissionId): int
    {
        return self::purgePaths(self::pendingForSubmission($submissionId));
    }

    /** Borra los adjuntos de todos los submissions de un formulario. */
    public static function purgeForm(int $formId): int
    {
        return self::purgePaths(self::pendingForForm($formId));
    }

    /** Borra los adjuntos de todas las respuestas de un campo (por id o etiqueta). */
    public static function purgeField(int $fieldId): int
    {
        return self::purgePaths(self::pendingForField($fieldId));
    }

    /**
     * Barrer los directorios de mes que quedaron vacíos tras las purgas.
     *
     * El storage vive en `baseDir()/YYYY/MM/<hex>.<ext>` (Uploader::absolutePath),
     * así que año y mes son solo dígitos. Cuando una purga de dos fases vacía un
     * mes, la carpeta queda huérfana para siempre: este barrido la borra y, si el
     * año queda sin meses, también la del año. Nunca toca directorios que no
     * sigan el patrón estricto `YYYY/MM` (una carpeta extraña se deja intacta).
     *
     * @param string|null $root   raíz de trabajo (tests); por defecto baseDir().
     * @param bool        $dryRun cuenta sin borrar (modo «qué haría»).
     */
    public static function pruneEmptyMonths(?string $root = null, bool $dryRun = false): int
    {
        $root = $root ?? self::baseDir();
        if (!is_dir($root)) {
            return 0;
        }
        $removed = 0;
        $years = @scandir($root) ?: [];
        foreach ($years as $year) {
            if ($year === '.' || $year === '..' || !preg_match('/^\d{4}$/', $year)) {
                continue;
            }
            $yearDir = $root . '/' . $year;
            $months = @scandir($yearDir) ?: [];
            foreach ($months as $month) {
                if ($month === '.' || $month === '..' || !preg_match('/^\d{2}$/', $month)) {
                    continue;
                }
                $monthDir = $yearDir . '/' . $month;
                if (!is_dir($monthDir) || self::isEmptyDir($monthDir) === false) {
                    continue;
                }
                if ($dryRun || @rmdir($monthDir)) {
                    $removed++;
                }
            }
            if (@is_dir($yearDir) && self::isEmptyDir($yearDir) && ($dryRun || @rmdir($yearDir))) {
                $removed++;
            }
        }
        return $removed;
    }

    /** ¿El directorio solo tiene las entradas «.» y «..»? */
    private static function isEmptyDir(string $dir): bool
    {
        $entries = @scandir($dir);
        return $entries !== false && count($entries) === 2;
    }

    private static function purgeValue(string $value): int
    {
        $metas = self::decode($value);
        if ($metas === null) {
            return 0;
        }
        $n = 0;
        foreach ($metas as $meta) {
            self::deleteStored((string) ($meta['stored'] ?? ''));
            if (!empty($meta['thumb'])) {
                self::deleteStored((string) $meta['thumb']);
            }
            $n++;
        }
        return $n;
    }

    // ---------------------------------------------------------------- helpers

    /** Valor legible para respuestas/CSV: "cv.pdf (1,2 MB)" o lista. */
    public static function displayValue(string $value): string
    {
        $metas = self::decode($value);
        if ($metas === null) {
            return $value;
        }
        $parts = [];
        foreach ($metas as $meta) {
            $parts[] = (string) ($meta['name'] ?? 'archivo') . ' (' . self::humanSize((int) ($meta['size'] ?? 0)) . ')';
        }
        return implode('; ', $parts);
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 KB';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $kb = $bytes / 1024;
        if ($kb < 1024) {
            return number_format($kb, $kb < 10 ? 1 : 0, ',', '.') . ' KB';
        }
        $mb = $kb / 1024;
        return number_format($mb, $mb < 10 ? 1 : 0, ',', '.') . ' MB';
    }

    /** Nombre de descarga seguro: solo base, sin rutas ni caracteres de control. */
    public static function sanitizeName(string $name, string $ext = ''): string
    {
        // Operaciones byte a byte: un nombre de cliente puede no ser UTF-8
        // válido y preg_replace con /u devolvería null (nombre perdido).
        $name = self::baseName($name);
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/', ' ', $name) ?? '';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $name = ltrim($name, '.');
        if ($name === '') {
            return 'archivo' . ($ext !== '' ? '.' . $ext : '');
        }
        // Limitar longitud (bytes) conservando la extensión.
        if (strlen($name) > 120) {
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $e    = pathinfo($name, PATHINFO_EXTENSION);
            $stem = substr($stem, 0, 100);
            $name = $stem . ($e !== '' ? '.' . substr($e, 0, 10) : '');
        }
        return $name;
    }

    private static function baseName(string $path): string
    {
        $path = str_replace(["\0", '\\'], ['', '/'], $path);
        $base = trim(basename($path));
        return $base === '.' || $base === '/' ? '' : $base;
    }

    private static function detectMime(string $tmp): string
    {
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($tmp);
            if (is_string($mime) && $mime !== '') {
                return strtolower(trim(explode(';', $mime)[0]));
            }
        }
        // Fallback: sin fileinfo solo se acepta por extensión whitelist.
        $ext = strtolower((string) pathinfo($tmp, PATHINFO_EXTENSION));
        foreach (self::MIME_EXT as $mime => $exts) {
            if (in_array($ext, $exts, true)) {
                return $mime;
            }
        }
        return 'application/octet-stream';
    }

    private static function dirFor(int $ts): string
    {
        return self::baseDir() . '/' . date('Y/m', $ts);
    }

    private static function relative(string $dir, string $file): string
    {
        $dir  = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        $file = str_replace('\\', '/', $file);
        return str_starts_with($file, $dir) ? substr($file, strlen($dir)) : basename($file);
    }

    private static function yearMonth(string $rel): string
    {
        $parts = explode('/', $rel);
        return count($parts) >= 2 ? $parts[0] . '/' . $parts[1] : '';
    }

    private static function intConfig(string $key, int $default): int
    {
        $v = (int) Config::get($key, $default);
        return $v > 0 ? $v : $default;
    }

    private static function fail(string $code, array $params, string $locale): array
    {
        return ['ok' => false, 'error' => $code, 'params' => $params, 'value' => null, 'locale' => $locale];
    }
}
