<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * La navegación del panel es una sidebar, no una barra superior.
 *
 * ## Por qué un test de estructura + CSS
 *
 * El cambio de barra a sidebar vive en dos sitios que nada obliga a mantener
 * juntos: `views/layouts/main.php` (el markup) y `public/assets/css/app.css`
 * (el plegado). Y tiene una trampa concreta: en móvil el cajón lo posiciona
 * Bootstrap (`.offcanvas-lg` → `position: fixed` DENTRO de un `@media`), así que
 * una regla `.app-sidebar { position: sticky }` sin media query lo pisaría por
 * orden de fuente (app.css va después de bootstrap) y el cajón dejaría de
 * abrirse sin ningún error. Por eso el `position` de escritorio va dentro de
 * `@media (min-width: 992px)`.
 *
 * Lo que se fija:
 *
 *  1. El layout tiene `#appSidebar` con `offcanvas-lg offcanvas-start` y una
 *     hamburguesa (`data-bs-toggle="offcanvas"`) que lo abre.
 *  2. La antigua navbar colapsable (`#mainNav`) ya no está.
 *  3. En móvil la sidebar CONSERVA sus etiquetas. El texto que se pidió ocultar
 *     era el de los botones del contenido, no el del menú; quitar el texto del
 *     menú deja un cajón de solo iconos que parece roto.
 *  4. El `position: sticky` de escritorio vive en su media query.
 *  5. La sidebar es oscura (degradado de la marca) con textos claros: volver a
 *     gris sobre blanco deja los enlaces ilegibles y este test en rojo.
 *  6. El diálogo de confirmación sigue en el layout.
 *
 * Sin BD: solo lee la vista y el CSS.
 */
class SidebarLayoutTest
{
    private static function css(): string
    {
        $bruto = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
        // Sin comentarios: aquí se miden declaraciones y hay selectores citados
        // en los comentarios que no deben confundirse con la regla.
        return (string) preg_replace('#/\*.*?\*/#s', '', $bruto);
    }

    public function testElLayoutMontaLaSidebarComoCajonResponsive(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/views/layouts/main.php');

        // Guard anti-vacío: si el fichero no fuera el esperado, todo lo de abajo
        // pasaría por no encontrar nada que contradecir.
        assertTrue(str_contains($src, 'appSidebar'), 'el layout tiene el contenedor de la sidebar');

        assertTrue(
            preg_match('/id="appSidebar"[^>]*class="[^"]*offcanvas-lg[^"]*offcanvas-start/', $src) === 1
            || preg_match('/class="[^"]*offcanvas-lg[^"]*offcanvas-start[^"]*"[^>]*id="appSidebar"/', $src) === 1,
            'la sidebar es `offcanvas-lg offcanvas-start`: bloque fijo en lg+ y cajón por debajo'
        );
        assertTrue(
            preg_match('/data-bs-toggle="offcanvas"[^>]*data-bs-target="#appSidebar"/', $src) === 1,
            'una hamburguesa abre el cajón con `data-bs-toggle="offcanvas"`'
        );
        assertTrue(
            str_contains($src, 'aria-controls="appSidebar"'),
            'el toggler declara qué controla (`aria-controls`)'
        );
        assertTrue(
            !str_contains($src, 'id="mainNav"'),
            'la antigua navbar colapsable ya no existe: era el otro camino, y los dos a la vez se pelean'
        );
    }

    public function testEnMovilLaSidebarConservaSusEtiquetas(): void
    {
        // El texto de la sidebar NO se oculta en móvil: abrir el cajón y no ver
        // las etiquetas del menú no es «solo iconos», es una barra ilegible. Lo
        // que se pidió ocultar era el texto de los botones del CONTENIDO, no el
        // del menú de navegación.
        assertSame(
            0,
            preg_match('/\.app-sidebar-label[^{]*\{[^}]*display:\s*none/', self::css()),
            'ninguna regla oculta las etiquetas de la sidebar'
        );
        assertSame(
            0,
            preg_match('/\.app-sidebar-user-name[^{]*\{[^}]*display:\s*none/', self::css()),
            'la cabecera de usuario conserva su nombre'
        );
    }

    public function testElPlegadoDeEscritorioViveEnSuMediaQuery(): void
    {
        // Directo con un regex que ancla la media query AL selector: no vale
        // buscar el primer `@media (min-width: 992px)` del archivo, porque en
        // app.css hay más de uno y el orden de fuente no es el contrato.
        assertSame(
            1,
            preg_match(
                '#@media\s*\(min-width:\s*992px\)\s*\{\s*\.app-sidebar\s*\{[^}]*position:\s*sticky#',
                self::css()
            ),
            'el `position: sticky` de escritorio va dentro de `@media (min-width: 992px)`'
        );

        // La regla base de `.app-sidebar` no puede llevar `position`: sin media
        // query pisaría el `fixed` de Bootstrap en móvil y el cajón no abriría.
        assertSame(
            1,
            preg_match('/\.app-sidebar\s*\{([^}]*)\}/', self::css(), $m),
            'existe la regla base de `.app-sidebar`'
        );
        assertTrue(
            !str_contains($m[1], 'position:'),
            'la regla base de `.app-sidebar` no declara `position`'
        );
    }

    public function testLaSidebarEsOscuraConTextoClaro(): void
    {
        // El fondo de la sidebar es oscuro (degradado de la marca) y los textos
        // claros. Sobre blanco los grises del menú no se leían: es la corrección
        // que pidió el dueño, y una regla que vuelva a `--vf-text-muted` sobre
        // `--vf-surface` deja esto en rojo.
        assertSame(
            1,
            preg_match('/\.app-sidebar-inner\s*\{([^}]*)\}/', self::css(), $inner),
            'existe la regla de `.app-sidebar-inner`'
        );
        assertTrue(
            str_contains($inner[1], 'linear-gradient') && preg_match('/\bcolor:\s*#fff/i', $inner[1]) === 1,
            'la sidebar es oscura (degradado) con texto blanco'
        );

        assertSame(
            1,
            preg_match('/\.app-sidebar-nav \.nav-link\s*\{([^}]*)\}/', self::css(), $link),
            'existe la regla base del enlace del menú'
        );
        assertSame(
            1,
            preg_match('/rgba\(255,\s*255,\s*255,\s*\.\d+\)/', $link[1]),
            'el enlace inactivo es blanco translúcido sobre el degradado, no gris sobre blanco'
        );
        assertTrue(
            !str_contains($link[1], '--vf-text-muted'),
            'el gris de superficie no vuelve como color del menú'
        );

        assertSame(
            1,
            preg_match('/\.app-sidebar-nav \.nav-link\.active\s*\{([^}]*)\}/', self::css(), $act),
            'existe la regla del enlace activo'
        );
        assertTrue(
            str_contains($act[1], 'background: #fff'),
            'el activo es una pastilla blanca (máximo contraste)'
        );
    }

    public function testElDialogoDeConfirmacionSigueEnElLayout(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/views/layouts/main.php');
        assertTrue(str_contains($src, 'id="vfConfirm"'), 'el diálogo de confirmación del panel no se ha ido con la navbar');
    }
}
