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
                '#@media\s*\(max-width:\s*991\.98px\)\s*\{\s*\.app-content \.btn:has\(i\[class\*="fa-"\]\):not\(\.w-100\)\s*\{[^}]*font-size:\s*0#s',
                self::css()
            ),
            'en móvil, los botones del contenido con icono colapsan su texto'
        );

        assertSame(
            1,
            preg_match(
                '#\.app-content \.btn:has\(i\[class\*="fa-"\]\):not\(\.w-100\) i\[class\*="fa-"\]\s*\{[^}]*font-size:\s*1rem#s',
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
}
