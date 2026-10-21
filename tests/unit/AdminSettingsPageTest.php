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

    /**
     * AG3 — La tarjeta de correo tiene que apuntar a SUS dos endpoints, y guardar
     * y probar son formularios distintos: uno no lleva archivo y el otro es un
     * POST aparte. Que los dos existan es lo que hace usable el ajuste (guardar
     * sin poder probar deja al operador adivinando).
     */
    public function testLaTarjetaDeCorreoApuntaASusDosEndpoints(): void
    {
        $settings = self::leer('views/admin/settings.php');

        assertTrue(str_contains($settings, 'action="/admin/settings/mail"'), 'el formulario de guardar');
        assertTrue(str_contains($settings, 'action="/admin/settings/mail/test"'), 'y el de la prueba');
        assertTrue(str_contains($settings, 'name="mail_host"'), 'con los campos del SMTP');
        assertTrue(str_contains($settings, 'name="mail_password"'), 'y el de la contraseña');
    }

    /**
     * La contraseña es un campo de SOLO ESCRITURA: la vista NUNCA recibe el valor
     * guardado, así que su `value` tiene que estar vacío y su `type`, en password.
     * Un `value="<?= $mailPassword ?>"` devolvería la contraseña al HTML y a
     * cualquier «ver código fuente».
     */
    public function testLaContrasenaNoSeDevuelveAlHtml(): void
    {
        $settings = self::leer('views/admin/settings.php');

        assertTrue(
            str_contains($settings, 'id="mailPassword"') && str_contains($settings, 'type="password"'),
            'la contraseña es un input de tipo password'
        );
        assertFalse(
            (bool) preg_match('/name="mail_password"[^>]*value="<\?=/', $settings),
            'el input de la contraseña no puede rellenar `value` con una variable de la vista'
        );
    }

    /**
     * AG4 — La tarjeta de identidad operativa apunta a SU endpoint y manda los
     * tres campos con los nombres que lee `InstanceSettings::save()`. Si uno
     * cambiara, el POST guardaría vacío (borraría la fila) sin ningún error.
     */
    public function testLaTarjetaDeIdentidadApuntaASuEndpoint(): void
    {
        $settings = self::leer('views/admin/settings.php');

        assertTrue(str_contains($settings, 'action="/admin/settings/instance"'), 'el formulario de la identidad');
        assertTrue(str_contains($settings, 'name="instance_public_url"'), 'con la URL pública');
        assertTrue(str_contains($settings, 'name="instance_timezone"'), 'la zona horaria');
        assertTrue(str_contains($settings, 'name="instance_locale"'), 'y el idioma');
    }

    /**
     * El idioma es una lista CERRADA (dos opciones) y la zona sale de la lista
     * IANA que pasa el controlador (`$timezoneOptions`); la opción «Por defecto»
     * se pinta vacía para poder BORRAR la fila y volver al `.env`. Una lista de
     * texto libre en el idioma dejaría guardar «fr», que `save()` rechaza.
     */
    public function testElIdiomaEsUnaListaCerradaYLaZonaEsUnDesplegable(): void
    {
        $settings = self::leer('views/admin/settings.php');

        assertTrue(
            (bool) preg_match('/<select[^>]*name="instance_timezone"/', $settings),
            'la zona horaria es un desplegable, no un campo de texto'
        );
        assertTrue(
            str_contains($settings, 'foreach (($timezoneOptions ?? []) as $tz)'),
            'y se rellena con la lista IANA que pasa el controlador'
        );

        assertTrue(
            (bool) preg_match('/<select[^>]*name="instance_locale"/', $settings),
            'el idioma también es un desplegable'
        );
        assertTrue(str_contains($settings, 'value="es"'), 'con español');
        assertTrue(str_contains($settings, 'value="en"'), 'e inglés');
        assertTrue(
            str_contains($settings, '<option value=""'),
            'y una opción vacía «Por defecto» para borrar la fila'
        );
    }

    /**
     * AG5 — La tarjeta de Turnstile apunta a SU endpoint y manda las dos claves
     * con los nombres que lee `TurnstileSettings::save()`.
     */
    public function testLaTarjetaDeTurnstileApuntaASuEndpoint(): void
    {
        $settings = self::leer('views/admin/settings.php');

        assertTrue(str_contains($settings, 'action="/admin/settings/turnstile"'), 'el formulario de Turnstile');
        assertTrue(str_contains($settings, 'name="turnstile_site_key"'), 'con la site key');
        assertTrue(str_contains($settings, 'name="turnstile_secret"'), 'y la secret key');
    }

    /**
     * La secret key es de SOLO ESCRITURA, como la del SMTP: la vista nunca recibe
     * el valor guardado, así que su `value` está vacío y su `type`, en password.
     * Un `value="<?= ... ?>"` devolvería la credencial al HTML.
     */
    public function testLaSecretKeyDeTurnstileNoSeDevuelveAlHtml(): void
    {
        $settings = self::leer('views/admin/settings.php');

        assertTrue(
            str_contains($settings, 'id="turnstileSecret"') && str_contains($settings, 'type="password"'),
            'la secret key es un input de tipo password'
        );
        assertFalse(
            (bool) preg_match('/name="turnstile_secret"[^>]*value="<\?=/', $settings),
            'el input de la secret key no puede rellenar `value` con una variable de la vista'
        );
    }
}
