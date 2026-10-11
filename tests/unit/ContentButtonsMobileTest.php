<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * En móvil, los botones del CONTENIDO que llevan icono se quedan solo con el
 * icono.
 *
 * ## Por qué un test de CSS
 *
 * La regla vive en `public/assets/css/app.css` y hay dos frentes que se pueden
 * romper en silencio: que la media query desaparezca (y el texto vuelva a
 * mostrarse) o que se caiga el `:not(.w-100)`, que es lo que deja legibles los
 * botones de ancho completo («Eliminar», «Guardar ajustes»): un botón a ancho
 * completo con un único icono en el centro no se distingue de uno roto.
 *
 * La sidebar NO entra en la regla: no vive dentro de `.app-content`, que es
 * justamente lo que se corrigió (se estaba ocultando el texto del menú en vez
 * del de los botones del contenido).
 *
 * Sin BD: solo lee el CSS.
 */
class ContentButtonsMobileTest
{
    private static function css(): string
    {
        $bruto = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');

        // Sin comentarios: aquí se miden declaraciones y hay selectores citados
        // en los comentarios que no deben confundirse con la regla.
        return (string) preg_replace('#/\*.*?\*/#s', '', $bruto);
    }

    public function testEnMovilElTextoDeLosBotonesDelContenidoConIconoSeColapsa(): void
    {
        assertSame(
            1,
            preg_match(
                '#@media\s*\(max-width:\s*991\.98px\)\s*\{\s*@supports selector\(:has\(\*\)\)\s*\{\s*\.app-content \.btn:has\(i\[class\*="fa-"\]\):not\(\.w-100\)[^{]*\{[^}]*font-size:\s*0#s',
                self::css()
            ),
            'en móvil, los botones del contenido con icono colapsan su texto'
        );

        assertSame(
            1,
            preg_match(
                '#\.app-content \.btn:has\(i\[class\*="fa-"\]\):not\(\.w-100\) i\[class\*="fa-"\][^{]*\{[^}]*font-size:\s*1rem#s',
                self::css()
            ),
            'el icono recupera su tamaño (si no, `font-size: 0` lo encogía también)'
        );
    }

    public function testLosBotonesDeAnchoCompletoConservanSuTexto(): void
    {
        // El `:not(.w-100)` es la mitad de la regla: sin él, «Eliminar» o
        // «Guardar ajustes» quedan como botones enormes con un icono suelto.
        assertTrue(
            str_contains(
                self::css(),
                ':has(i[class*="fa-"]):not(.w-100)'
            ),
            'los botones a ancho completo quedan fuera de la regla'
        );
    }

    public function testLaReglaNoTocaLaSidebar(): void
    {
        assertSame(
            0,
            preg_match('/\.app-sidebar[^{]*\{[^}]*font-size:\s*0/', self::css()),
            'ninguna regla colapsa el texto de la sidebar'
        );
    }

    public function testLaSubNavegacionDeAdminTambienSeQuedaSoloConElIcono(): void
    {
        // La sub-navegación de `/admin` son `nav-link` (no `.btn`), así que la
        // regla de los botones del contenido no la alcanza: sin una regla propia
        // se queda con el texto y parte la fila en varias líneas en móvil.
        assertSame(
            1,
            preg_match(
                '#\.app-admin-nav \.nav-link:has\(i\[class\*="fa-"\]\)\s*\{[^}]*font-size:\s*0#s',
                self::css()
            ),
            'la sub-navegación de /admin colapsa su texto en móvil'
        );
        assertSame(
            1,
            preg_match(
                '#\.app-admin-nav \.nav-link:has\(i\[class\*="fa-"\]\) i\[class\*="fa-"\]\s*\{[^}]*font-size:\s*1rem#s',
                self::css()
            ),
            'y el icono recupera su tamaño'
        );

        // Sin icono en las píldoras, la regla no engancharía a ninguna y el test
        // de CSS pasaría midiendo una regla muerta.
        $nav = (string) file_get_contents(dirname(__DIR__, 2) . '/views/admin/_nav.php');
        assertTrue(
            str_contains($nav, 'app-admin-nav') && str_contains($nav, 'fa-solid'),
            'las píldoras de /admin traen icono dentro de app-admin-nav'
        );
    }

    public function testLaSubNavegacionDeAdminVisteComoUnBotonDeCabecera(): void
    {
        // El dueño pidió que la fila de pestañas de /admin (Usuarios, Grupos de
        // roles, Roles y permisos, Log de seguridad, Formularios) no conviva con
        // el resto de cabeceras a otro tamaño: son `.nav-link`, no `.btn`, así
        // que ni `.app-head-actions` ni la regla de colapso las visten. Deben
        // tomar las MISMAS métricas del botón de cabecera; si esta regla cae,
        // vuelven a 40px/radio 6/peso 400 sin que nada más lo note.
        assertSame(
            1,
            preg_match(
                '#\.app-admin-nav \.nav-link\s*\{[^}]*min-height:\s*var\(--vf-btn-cta-h\)[^}]*border-radius:\s*12px[^}]*font-size:\s*1rem[^}]*font-weight:\s*600#s',
                self::css()
            ),
            'la sub-navegación de /admin toma el alto, el radio y el peso del botón de cabecera'
        );

        // La pestaña activa se rellena como el CTA y la inactiva lleva contorno
        // `secondary`: el mismo par que un `btn-outline-secondary`/`btn-primary`.
        assertSame(
            1,
            preg_match(
                '#\.app-admin-nav \.nav-link\.active\s*\{[^}]*background-color:\s*var\(--vf-primary\)#s',
                self::css()
            ),
            'la pestaña activa de /admin se rellena como el CTA'
        );
    }

    public function testElColapsoVaBajoSupportsYNoPierdeNiInsigniasNiLoQueDebe(): void
    {
        $css = self::css();

        // El bloque entero va bajo `@supports selector(:has(*))`: sin `:has()`
        // no hay forma de decir «botón que contiene un icono», y la
        // degradación es que el texto se quede (nunca un botón vacío).
        assertSame(
            1,
            preg_match('#@supports selector\(:has\(\*\)\)#', $css),
            'el colapso va guardado bajo @supports selector(:has(*))'
        );

        // Las insignias de grupos se reponen a su tamaño: no son texto del
        // botón, y `font-size: 0` las dejaría invisibles y con ellas la
        // pertenencia de la fila.
        assertSame(
            1,
            preg_match(
                '#\.app-content \.btn:has\(i\[class\*="fa-"\]\) \.badge\s*\{[^}]*font-size:\s*0\.7rem#s',
                $css
            ),
            'las insignias dentro de un botón colapsado siguen legibles'
        );

        // Los botones de crear de /admin son `w-100`, así que quedan fuera de
        // la regla general; se colapsan por estar marcados con
        // `app-btn-mobile-icon`.
        assertSame(
            1,
            preg_match(
                '#\.app-content \.btn\.app-btn-mobile-icon:has\(i\[class\*="fa-"\]\)\s*\{[^}]*font-size:\s*0#s',
                $css
            ),
            'los botones marcados con app-btn-mobile-icon también colapsan'
        );

        foreach (['groups' => true, 'roles' => true] as $vista => $_) {
            $contenido = (string) file_get_contents(dirname(__DIR__, 2) . "/views/admin/{$vista}.php");
            assertTrue(
                str_contains($contenido, 'app-btn-mobile-icon'),
                "el botón de crear de /admin/{$vista} lleva app-btn-mobile-icon"
            );
        }

        // El botón de grupos de la fila trae icono (si no, la regla no lo
        // alcanza) y conserva su gancho de fetch.
        $users = (string) file_get_contents(dirname(__DIR__, 2) . '/views/admin/users.php');
        assertTrue(
            str_contains($users, 'app-rbac-groups-btn') && str_contains($users, 'fa-users'),
            'el botón de grupos de la fila de /admin trae icono'
        );
    }
}
