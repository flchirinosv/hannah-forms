<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * Los ajustes de la INSTANCIA (registro público y política de revisión) tienen
 * pantalla propia en `/admin/settings`, y esta los desliga de `/admin`, que es
 * una lista de personas. Antes eran dos tarjetas apiladas encima de la tabla,
 * donde competían en altura con el listado sin ser de él.
 *
 * Se ata en dos direcciones: que la ruta y la pestaña existen con el permiso
 * correcto, y que las tarjetas NO se quedaron también en la ficha de usuarios.
 * Una pantalla nueva a la que le sobrevive la copia vieja deja dos sitios que se
 * van separando sin que nada falle.
 */
class AdminSettingsPageTest
{
    private static function raiz(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function leer(string $rel): string
    {
        return (string) file_get_contents(self::raiz() . '/' . $rel);
    }

    public function testLaRutaEsSuPropiaPantallaDetrasDelPanel(): void
    {
        $rutas = self::leer('config/routes.php');

        assertSame(
            1,
            preg_match(
                '#->get\(\s*\'/admin/settings\'\s*,\s*\[AdminController::class,\s*\'settings\'\]\s*,\s*\[\'auth\',\s*\'admin\'\]\s*\)#',
                $rutas
            ),
            'GET /admin/settings tiene que existir, apuntar a AdminController::settings y pasar por el middleware del panel'
        );
    }

    public function testLaPestanaDeLaInstanciaSoloVaConAdminAccess(): void
    {
        $nav = self::leer('views/admin/_nav.php');

        assertSame(
            1,
            preg_match(
                "#['\"]settings['\"]\s*=>\s*\[\s*'/admin/settings'\s*,\s*'[^']+'\s*,\s*'fa-sliders'\s*,\s*'admin\.access'\s*\]#",
                $nav
            ),
            'la pestaña «Ajustes de la instancia» va con `admin.access`, no con `admin.users`'
        );
    }

    public function testLasDosTarjetasVivenEnLaPantallaPropiaYNoEnLaDeUsuarios(): void
    {
        $settings = self::leer('views/admin/settings.php');
        $users = self::leer('views/admin/users.php');

        // El registro se guarda por fetch (el endpoint va en el `data-`), y la
        // revisión tiene un `<form>` nativo: lo que importa de los dos es que su
        // URL sea la de esta pantalla y no otra.
        assertTrue(
            str_contains($settings, '/admin/settings/registration'),
            'la tarjeta del registro tiene que apuntar a su endpoint'
        );
        assertTrue(
            str_contains($settings, 'action="/admin/settings/review"'),
            'y la de la revisión a la suya'
        );

        foreach (['/admin/settings/registration', '/admin/settings/review'] as $endpoint) {
            assertFalse(
                str_contains($users, $endpoint),
                'la ficha de usuarios ya no aloja el ajuste de la instalación ' . $endpoint
            );
        }

        // La sub-navegación activa es la de esta pantalla: `_page_head` la recibe
        // por su clave, y un `active` equivocado resalta «Usuarios» estando aquí.
        assertTrue(
            str_contains($settings, "'active'   => 'settings'") || str_contains($settings, "'active' => 'settings'"),
            'la pantalla marca «settings» como la pestaña activa'
        );
    }
}
