<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Session;

abstract class Middleware
{
    public static function handle(string $name, array $params = []): void
    {
        $guards = static::guards();

        if (!isset($guards[$name])) {
            Response::abort(500, 'Middleware no registrado: ' . $name);
        }

        $guards[$name]();
    }

    /**
     * Lista de guards disponibles.
     *
     * M6: se extrae a método público para permitir extensibilidad sin modificar
     * esta clase. Se puede sobrescribir en subclasses o filtrar el array.
     */
    public static function guards(): array
    {
        return [
            'auth'   => static function (): void {
                if (!Auth::check()) {
                    Session::flash('error', 'Debes iniciar sesión para continuar.');
                    Response::redirect('/login');
                }
            },
            'admin'  => static function (): void {
                if (!Auth::check() || !Auth::isAdmin()) {
                    Response::forbidden();
                }
            },
            'guest'  => static function (): void {
                if (Auth::check()) {
                    Response::redirect('/dashboard');
                }
            },
            'editor' => static function (): void {
                if (!Auth::check() || !in_array(Auth::role(), ['admin', 'editor'], true)) {
                    Response::forbidden();
                }
            },
            // P10: debe correr ANTES de csrf — si el cuerpo excede
            // post_max_size PHP vacía $_POST/$_FILES y el token nunca llega.
            'post-max' => static function (): void {
                if (\App\Core\Request::bodyTooLarge()) {
                    Response::abort(413, \App\Core\I18n::t(
                        'err.upload_too_big_global',
                        \App\Core\I18n::resolve(),
                        ['max' => \App\Services\Uploader::maxSizeMb()]
                    ));
                }
            },
            'csrf'   => static function (): void {
                if (!\App\Core\Csrf::verifyRequest()) {
                    Response::abort(419, 'Token CSRF inválido. La sesión ha expirado.');
                }
            },
            'csrf-header' => static function (): void {
                if (!\App\Core\Csrf::verifyHeader()) {
                    Response::json(['ok' => false, 'error' => 'Token de seguridad inválido. Recarga la página e intenta de nuevo.'], 419);
                }
            },
        ];
    }
}