<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Config;
use App\Core\UploadSettings;

/**
 * Identidad visual de la INSTANCIA: nombre, logo, favicon y color primario.
 *
 * Antes esto era fijo en el código: el nombre salía de `APP_NAME` del `.env`,
 * el icono era un `fa-list-check` escrito en cada vista y el color un token
 * `--vf-primary` hardcodeado en `app.css`. Una instalación self-hosted que se
 * vende a un cliente quiere ponerle SU marca sin tocar ficheros, y eso es lo que
 * resuelve este servicio.
 *
 * Patrón, igual que `AppSettings`: **la fila guardada gana; si no hay fila,
 * manda el `.env` o el default del código**. Nada de esto se congela: se lee en
 * cada petición, así que cambiar la marca se ve en la siguiente carga.
 *
 * El logo y el favicon se guardan como IMAGEN SUBIDA (no una URL externa) para
 * que la instancia no dependa de un tercero: se almacenan en `storage/branding/`
 * y se sirven por `BrandingController` (`/branding/logo`, `/branding/favicon`),
 * nunca directamente por el web server.
 */
final class Brand
{
    /** Nombre visible de la instalación. Fila ausente = `app.name` del `.env`. */
    public const NAME = 'instance_name';

    /** Color primario (hex `#rrggbb`). Fila ausente = el default del código. */
    public const COLOR = 'instance_color';

    /** Extremo inicial del degradado de marca. Fila ausente = el primario. */
    public const COLOR_GRAD_START = 'instance_color_grad_start';

    /** Extremo final del degradado de marca. Fila ausente = el violeta default. */
    public const COLOR_GRAD_END = 'instance_color_grad_end';

    /** Color de fondo de las pantallas de la instancia (login, etc.). */
    public const COLOR_BG = 'instance_color_bg';

    /** Color del TEXTO sobre el degradado. Vacío = automático por luminancia. */
    public const COLOR_TEXT = 'instance_color_text';

    /** Metadatos JSON del logo subido (`{stored, mime, size}`). */
    public const LOGO = 'instance_logo';

    /** Metadatos JSON del favicon subido (`{stored, mime, size}`). */
    public const FAVICON = 'instance_favicon';

    /** Color primario por defecto: el mismo `--vf-primary` de siempre. */
    public const DEFAULT_COLOR = '#5b5bd6';

    /** Extremo final del degradado por defecto: el violeta de `app.css`. */
    public const DEFAULT_GRAD_END = '#8b5cf6';

    /** Directorio de marca dentro de `storage/`. Fuera de `public/`. */
    public const DIR = 'branding';

    /** Tipos de imagen aceptados para logo/favicon. */
    public const MIME_EXT = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * Nombre visible de la instalación. Un valor en blanco (fila con `''`) cae
     * al default: un nombre vacío dejaría la barra sin nada que leer.
     */
    public static function name(): string
    {
        $stored = AppSettings::get(self::NAME);
        if ($stored !== null && trim($stored) !== '') {
            return trim($stored);
        }

        return (string) Config::get('app.name', 'VanillaForms');
    }

    /**
     * Color primario, siempre en formato `#rrggbb`. Un valor inválido cae al
     * default en vez de inyectarse en el CSS: `themeCss()` no escapa, y un
     * color mal formado (o un intento de inyección) no puede llegar al `<style>`.
     */
    public static function color(): string
    {
        $stored = AppSettings::get(self::COLOR);
        if ($stored !== null && self::isValidColor($stored)) {
            return strtolower(trim($stored));
        }

        return self::DEFAULT_COLOR;
    }

    /**
     * Extremo inicial del degradado. Fila ausente (o inválida) = el primario,
     * que es como se comportaba la marca antes de que el degradado se pudiera
     * configurar: tocar solo el color principal sigue tiñendo la navbar entera.
     */
    public static function gradientStart(): string
    {
        $stored = AppSettings::get(self::COLOR_GRAD_START);
        if ($stored !== null && self::isValidColor($stored)) {
            return strtolower(trim($stored));
        }

        return self::color();
    }

    /** Extremo final del degradado. Fila ausente (o inválida) = violeta default. */
    public static function gradientEnd(): string
    {
        $stored = AppSettings::get(self::COLOR_GRAD_END);
        if ($stored !== null && self::isValidColor($stored)) {
            return strtolower(trim($stored));
        }

        return self::DEFAULT_GRAD_END;
    }

    /**
     * Color de fondo de las pantallas de la instancia (login, registro…). Fila
     * ausente = `null`, y el CSS de siempre (el degradado de `.auth-body`) se
     * queda sin tocar.
     */
    public static function background(): ?string
    {
        $stored = AppSettings::get(self::COLOR_BG);
        if ($stored !== null && self::isValidColor($stored)) {
            return strtolower(trim($stored));
        }

        return null;
    }

    /**
     * Color del texto sobre el degradado, o `null` para «automático».
     *
     * Con `null`, `themeCss()` calcula la tinta por luminancia del extremo
     * inicial: es el comportamiento que la vista promete con el interruptor
     * «Automático», y por eso un valor vacío NO cae a blanco a pelo.
     */
    public static function text(): ?string
    {
        $stored = AppSettings::get(self::COLOR_TEXT);
        if ($stored !== null && self::isValidColor($stored)) {
            return strtolower(trim($stored));
        }

        return null;
    }

    /**
     * ¿Hay un logo subido? No comprueba que el archivo siga en disco: eso lo
     * resuelve `logoUrl()` contra el filesystem real.
     */
    public static function hasLogo(): bool
    {
        return self::meta(self::LOGO) !== null;
    }

    public static function hasFavicon(): bool
    {
        return self::meta(self::FAVICON) !== null;
    }

    /**
     * URL pública del logo (versión cache-busting con el `size` del archivo), o
     * `null` si no hay logo o su archivo desapareció del disco (restaurar un
     * backup sin `storage/` es un caso real). Quien pinta decide el fallback.
     */
    public static function logoUrl(): ?string
    {
        return self::assetUrl(self::LOGO, '/branding/logo');
    }

    public static function faviconUrl(): ?string
    {
        return self::assetUrl(self::FAVICON, '/branding/favicon');
    }

    /**
     * CSS de instancia: color primario, extremos del degradado, fondo y tinta
     * sobre el degradado. Se inyecta en un `<style>` de los layouts, ANTES del
     * CSS por formulario (que puede sobrescribirlo en la página pública de ese
     * formulario).
     *
     * Sin NINGÚN color guardado no se emite nada, así que una instalación que no
     * toca la marca no lleva un `<style>` de más. Cada variable se emite solo si
     * su fila es válida; lo que falte cae al default del `:root` de `app.css`.
     * `--vf-on-grad` en automático (sin fila o vacío) se calcula por luminancia
     * del extremo inicial, para que el texto sobre el degradado nunca salga
     * ilegible sin que nadie tenga que elegir.
     */
    public static function themeCss(): string
    {
        $vars = [];

        $color = AppSettings::get(self::COLOR);
        if ($color !== null && self::isValidColor($color)) {
            $vars[] = '--vf-primary:' . strtolower(trim($color));
        }

        $gradStart = AppSettings::get(self::COLOR_GRAD_START);
        if ($gradStart !== null && self::isValidColor($gradStart)) {
            $vars[] = '--vf-grad-start:' . strtolower(trim($gradStart));
        }

        $gradEnd = AppSettings::get(self::COLOR_GRAD_END);
        if ($gradEnd !== null && self::isValidColor($gradEnd)) {
            $vars[] = '--vf-grad-end:' . strtolower(trim($gradEnd));
        }

        $bg = AppSettings::get(self::COLOR_BG);
        if ($bg !== null && self::isValidColor($bg)) {
            $vars[] = '--vf-instance-bg:' . strtolower(trim($bg));
        }

        $text = AppSettings::get(self::COLOR_TEXT);
        if ($text !== null && self::isValidColor($text)) {
            $vars[] = '--vf-on-grad:' . strtolower(trim($text));
        } elseif ($vars !== []) {
            // Automático: tinta por luminancia del extremo inicial (o del
            // primario si no se tocó el degradado). Solo cuando ya hay algún
            // color guardado, para no emitir un bloque con una sola variable
            // calculada sobre el default de siempre.
            $vars[] = '--vf-on-grad:' . Contrast::inkFor(self::gradientStart());
        }

        if ($vars === []) {
            return '';
        }

        return ':root{' . implode(';', $vars) . ';}';
    }

    /**
     * MIME del logo/favicon, para que el controlador lo sirva con el header
     * correcto. `null` si no hay.
     */
    public static function mime(string $kind): ?string
    {
        $key = self::keyForKind($kind);
        if ($key === null) {
            return null;
        }
        $meta = self::meta($key);
        return $meta === null ? null : (string) ($meta['mime'] ?? '');
    }

    /** Ruta absoluta en disco del logo/favicon, o `null` si no existe. */
    public static function absolutePath(string $kind): ?string
    {
        $key = self::keyForKind($kind);
        if ($key === null) {
            return null;
        }
        $meta = self::meta($key);
        if ($meta === null) {
            return null;
        }

        $stored = (string) ($meta['stored'] ?? '');
        // El nombre lo generamos nosotros (hex + ext), pero se valida por si
        // acaso: nunca debe contener separadores de ruta.
        if ($stored === '' || str_contains($stored, '/') || str_contains($stored, '\\') || str_contains($stored, '..')) {
            return null;
        }

        $path = self::dirAbsoluto() . '/' . $stored;
        return is_file($path) ? $path : null;
    }

    /**
     * Guarda una imagen subida (`['tmp_name','name','error','size']`) como logo
     * o favicon. Devuelve `['ok' => bool, 'error' => ?string, 'mime' => ?string]`.
     *
     * Valida el MIME con `finfo` sobre el archivo temporal (nunca
     * `$_FILES['type']`), la extensión sale de la whitelist, y el tamaño
     * máximo es el de los uploads de la instancia. Borra el anterior si lo había.
     */
    public static function saveUpload(string $kind, array $file): array
    {
        $kind = self::normalizeKind($kind);
        if ($kind === null) {
            return ['ok' => false, 'error' => 'Tipo de marca inválido.', 'mime' => null];
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'No se recibió ningún archivo.', 'mime' => null];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'La subida falló (código ' . $error . ').', 'mime' => null];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'error' => 'Archivo temporal no disponible.', 'mime' => null];
        }

        $maxBytes = UploadSettings::maxSizeMb() * 1024 * 1024;
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            return ['ok' => false, 'error' => 'La imagen supera el tamaño máximo permitido.', 'mime' => null];
        }

        $mime = self::detectMime($tmp);
        if ($mime === null || !isset(self::MIME_EXT[$mime])) {
            return ['ok' => false, 'error' => 'Formato no soportado: usa PNG, JPG, GIF o WEBP.', 'mime' => null];
        }

        $ext = self::MIME_EXT[$mime];
        $dir = self::dirAbsoluto();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'No se pudo crear el directorio de marca.', 'mime' => null];
        }
        // Defensa extra si el server sirviera storage/ por accidente.
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
            return ['ok' => false, 'error' => 'No se pudo guardar la imagen.', 'mime' => null];
        }
        @chmod($dest, 0640);

        // Guardar los metadatos ANTES de borrar el anterior: si el `set` falla,
        // no dejamos la fila apuntando a un archivo que ya no está.
        $meta = ['stored' => $name, 'mime' => $mime, 'size' => (int) filesize($dest)];
        $key = $kind === 'logo' ? self::LOGO : self::FAVICON;
        $previo = self::absolutePath($kind);

        if (!AppSettings::set($key, json_encode($meta, JSON_UNESCAPED_SLASHES))) {
            @unlink($dest);
            return ['ok' => false, 'error' => 'No se pudo guardar el ajuste (¿migraciones?).', 'mime' => null];
        }

        if ($previo !== null && is_file($previo)) {
            @unlink($previo);
        }

        return ['ok' => true, 'error' => null, 'mime' => $mime];
    }

    /** Borra el logo o favicon (archivo + fila). Idempotente. */
    public static function remove(string $kind): bool
    {
        $kind = self::normalizeKind($kind);
        if ($kind === null) {
            return false;
        }

        $path = self::absolutePath($kind);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }

        return AppSettings::delete($kind === 'logo' ? self::LOGO : self::FAVICON);
    }

    /* ------------------------------------------------------------------ */

    private static function normalizeKind(string $kind): ?string
    {
        return in_array($kind, ['logo', 'favicon'], true) ? $kind : null;
    }

    /**
     * kind (`logo`/`favicon`) → clave de `app_settings` (`instance_logo`/…), o
     * `null` si el kind no es ninguno de los dos.
     *
     * Existe porque `meta()` lee por CLAVE y el resto de la API habla de KIND:
     * pasar el kind a `meta()` a pelo devolvía `null` siempre (no hay fila
     * `logo`), y el MIME y la ruta del logo subido salían vacíos sin error.
     */
    private static function keyForKind(string $kind): ?string
    {
        return match (self::normalizeKind($kind)) {
            'logo'    => self::LOGO,
            'favicon' => self::FAVICON,
            default   => null,
        };
    }

    /** @return array<string, mixed>|null */
    private static function meta(string $key): ?array
    {
        $raw = AppSettings::get($key);
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['stored'])) {
            return null;
        }

        return $decoded;
    }

    private static function assetUrl(string $key, string $base): ?string
    {
        $meta = self::meta($key);
        if ($meta === null) {
            return null;
        }

        $kind = $key === self::LOGO ? 'logo' : 'favicon';
        if (self::absolutePath($kind) === null) {
            return null;
        }

        // `size` cambia al reemplazar la imagen: sirve de cache-busting sin tocar
        // el mtime (que no viaja en un backup/restore).
        $v = (int) ($meta['size'] ?? 0);
        return $base . ($v > 0 ? '?v=' . $v : '');
    }

    private static function dirAbsoluto(): string
    {
        return (string) Config::get('paths.storage', dirname(__DIR__, 2) . '/storage') . '/' . self::DIR;
    }

    private static function isValidColor(string $value): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', trim($value)) === 1;
    }

    private static function detectMime(string $tmp): ?string
    {
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp);
            if (is_string($mime) && $mime !== '') {
                return strtolower(trim(explode(';', $mime)[0]));
            }
        }
        return null;
    }
}
