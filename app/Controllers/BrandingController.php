<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Brand;
use App\Core\Response;

/**
 * Sirve el logo y el favicon de la instancia.
 *
 * Las imágenes viven en `storage/branding/`, FUERA de `public/`, así que no son
 * servibles por el web server: se sirven por aquí. A diferencia de los adjuntos,
 * estas rutas son PÚBLICAS (sin `auth`): el logo aparece en la pantalla de login
 * y en el formulario publicado, a los que se llega sin sesión.
 *
 * No hay nada sensible que proteger: la imagen la subió un administrador para
 * que la viera todo el mundo. Lo que sí se cuida es el `Content-Type` (sale del
 * MIME real, no del nombre) y `nosniff`, para que el archivo se pinte como
 * imagen y nunca se interprete.
 */
class BrandingController
{
    /** GET /branding/{kind} — {kind} es `logo` o `favicon`. */
    public function show(string $kind): void
    {
        if (!in_array($kind, ['logo', 'favicon'], true)) {
            Response::notFound();
            return;
        }

        $path = Brand::absolutePath($kind);
        $mime = Brand::mime($kind);
        if ($path === null || $mime === null || !isset(Brand::MIME_EXT[$mime])) {
            Response::notFound();
            return;
        }

        if (PHP_SAPI === 'cli') {
            return;
        }

        $size = (int) filesize($path);

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('X-Content-Type-Options: nosniff');
        // Cacheable pero revalidable: `no-cache` fuerza revalidación y el
        // cache-busting de la URL (?v=size) garantiza la imagen fresca al
        // reemplazarla, sin `no-store` (que impediría cualquier caché).
        header('Cache-Control: public, max-age=86400');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }
}
