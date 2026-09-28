<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\Answer;
use App\Services\Uploader;

/**
 * P10 — Descarga autenticada de adjuntos.
 *
 * Los archivos viven en storage/uploads (FUERA de public/), por lo que nunca
 * son servibles por el web server: se Bajan por aquí, siempre con
 * autorización previa del dueño del formulario, un editor o un admin.
 *
 * El identificador de la URL es el id de la fila `answers` (nunca el nombre
 * del archivo): la ruta real se resuelve en el servidor y se valida contra
 * la raíz de uploads (anti path-traversal).
 */
class AttachmentController
{
    /**
     * GET /attachments/{id}[?i=0]  — {id} es el id de la fila `answers`.
     */
    public function download(int $id): void
    {
        $index = max(0, (int) Request::query('i', '0'));

        $row = Answer::findWithContext($id);
        if ($row === null) {
            Response::notFound();
            return;
        }

        if (!$this->canAccess((int) $row['user_id'])) {
            Response::forbidden();
            return;
        }

        $metas = Uploader::decode((string) ($row['value'] ?? ''));
        if ($metas === null || !isset($metas[$index])) {
            Response::notFound();
            return;
        }

        $meta  = $metas[$index];
        $path  = Uploader::absolutePath((string) ($meta['stored'] ?? ''));
        $thumb = (bool) Request::query('thumb', '') && !empty($meta['thumb']);

        if ($thumb) {
            $path = Uploader::absolutePath((string) $meta['thumb']);
        }
        if ($path === null || !is_file($path)) {
            Response::notFound();
            return;
        }

        $name = Uploader::sanitizeName((string) ($meta['name'] ?? 'archivo'));
        if ($thumb) {
            $name = pathinfo($name, PATHINFO_FILENAME) . '-thumb.webp';
        }
        $size = (int) filesize($path);

        if ($thumb) {
            // Vista previa (webp generada en la subida): se sirve INLINE, como
            // imagen, no como descarga. El resto de headers de seguridad se
            // conservan; `nosniff` + `image/webp` hacen que el navegador la
            // pinte dentro de un <img> en vez de bajarla.
            if (PHP_SAPI !== 'cli') {
                header('Content-Type: image/webp');
                header('Content-Length: ' . $size);
                header('Content-Disposition: inline; filename="' . self::asciiName($name) . '"');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: private, no-store');
                header('Content-Security-Policy: default-src \'none\'; sandbox');

                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                readfile($path);
                exit;
            }
            return;
        }

        if (PHP_SAPI !== 'cli') {
            // octet-stream + attachment + nosniff: aunque el archivo sea un
            // HTML/SVG (bloqueados en la subida) nunca se ejecuta en el origen.
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . $size);
            header('Content-Disposition: attachment; filename="' . self::asciiName($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
            header('X-Content-Type-Options: nosniff');
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: private, no-store');
            header('Content-Security-Policy: default-src \'none\'; sandbox');

            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            readfile($path);
            exit;
        }
    }

    private function canAccess(int $ownerId): bool
    {
        return (int) Auth::id() === $ownerId || Auth::isAdmin() || Auth::role() === 'editor';
    }

    /** Fallback ASCII para clientes HTTP viejos que no soportan filename*. */
    private static function asciiName(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'archivo';
        $ascii = str_replace(['"', '\\'], '_', $ascii);
        return $ascii === '' ? 'archivo' : mb_substr($ascii, 0, 100);
    }
}
