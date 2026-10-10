<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * El ancho del formulario publicado: `col-12 col-lg-6`.
 *
 * ## Por qué un test de CSS
 *
 * El ancho del fill vive en UNA regla de `app.css` y no lo rompe nada si alguien
 * la vuelve a poner a 700px: no hay PHP que falle, no hay JS que se caiga y la
 * única señal es que en una pantalla de 1920 las matrices vuelven a estrecharse.
 * Es exactamente la clase de regla que este repo ata con un test (lo mismo que
 * hace `ThemeFontCssInjectionTest` con las variables del tema).
 *
 * ## Lo que se fija
 *
 *  1. `.fill-container` es `width: 100%` y **ya no** tiene techo fijo
 *     (`max-width: 700px`), con `margin: 0 auto` para centrarlo.
 *  2. A partir de `lg` (992px) ocupa la mitad: `@media (min-width: 992px)`.
 *     La media query mira la VENTANA, no el elemento, por eso el preview del
 *     constructor —dentro de un `modal-xl`— se parte igual que el fill.
 *  3. Las **ocho superficies** que se pintan con `.fill-container` (las siete
 *     vistas del fill y el preview) lo llevan sin clases de ancho encima: la
 *     regla es una sola y el día que aparezca una novena hay que decidir su
 *     ancho, no heredárselo por accidente.
 *  4. Nada reabre el ancho del preview por su id. El constructor tiene una
 *     superficie de preview (el modal en pantalla completa),
 *     pintada con la misma clase y sin regla de ancho por
 *     id: un `#livePreviewContent { width: … }` lo separaría en silencio (el id
 *     gana por especificidad a la media query).
 *
 * Sin BD: solo lee `app.css` y las vistas.
 */
class FillLayoutTest
{
    /** Las siete vistas del fill + el preview en vivo del constructor. */
    private const SUPERFICIES = [
        'views/fill/show.php',
        'views/fill/thanks.php',
        'views/fill/access.php',
        'views/fill/draft.php',
        'views/fill/full.php',
        'views/fill/closed.php',
        'views/fill/in_review.php',
        'views/builder/index.php',
    ];

    private static function css(): string
    {
        $bruto = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
        // Sin comentarios: el por qué va escrito dentro de las reglas y un test
        // que mide declaraciones tiene que leer declaraciones. Quitarlos ANTES
        // de buscar hace que un selector citado en un comentario (lo está) no
        // pueda confundirse con la regla.
        return (string) preg_replace('#/\*.*?\*/#s', '', $bruto);
    }

    /**
     * Cuerpo entre llaves del bloque que empieza por $pre, con recuento de
     * llaves para que los `@media` anidados no corten la lectura.
     */
    private static function bloque(string $pre): ?string
    {
        $css = self::css();
        $pos = strpos($css, $pre);
        if ($pos === false) {
            return null;
        }
        $abierto = strpos($css, '{', $pos);
        if ($abierto === false) {
            return null;
        }
        $nivel = 0;
        $cierre = null;
        for ($i = $abierto, $n = strlen($css); $i < $n; $i++) {
            if ($css[$i] === '{') {
                $nivel++;
            } elseif ($css[$i] === '}') {
                $nivel--;
                if ($nivel === 0) {
                    $cierre = $i;
                    break;
                }
            }
        }
        if ($cierre === null) {
            return null;
        }
        return substr($css, $abierto + 1, $cierre - $abierto - 1);
    }

    /** Etiqueta `<div>` que lleva la clase `fill-container` en una vista. */
    private static function etiqueta(string $vista): ?string
    {
        $html = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $vista);
        if (preg_match('/<div\b[^>]*\bclass="[^"]*\bfill-container\b[^"]*"[^>]*>/', $html, $m) !== 1) {
            return null;
        }
        return (string) $m[0];
    }

    /** Etiqueta `<div>` que lleva el `id` dado en una vista. */
    private static function etiquetaPorId(string $vista, string $id): ?string
    {
        $html = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $vista);
        if (preg_match('/<div\b[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/', $html, $m) !== 1) {
            return null;
        }
        return (string) $m[0];
    }

    public function testElContenedorDelFillEsCol12PorDebajoDeLg(): void
    {
        $base = self::bloque('.fill-container');
        assertNotNull($base, 'app.css define .fill-container');
        assertSame(1, preg_match('/\bwidth:\s*100%/', $base), 'col-12: todo el ancho por debajo de lg');
        assertSame(1, preg_match('/\bmax-width:\s*100%/', $base), 'sin techo: los 700px fijos ya no aplican');
        assertSame(1, preg_match('/\bmargin:\s*0 auto/', $base), 'centrado');
        assertTrue(
            !str_contains($base, '700px'),
            'el techo de 700px se retiró del contenedor'
        );

        $print = self::bloque('@media print');
        assertNotNull($print, 'app.css tiene una regla de impresión para el contenedor');
        assertTrue(
            (bool) preg_match('/\.fill-container\s*\{[^}]*width:\s*100%/', $print),
            'al imprimir vuelve a todo el ancho: una tarjeta al 50% del papel es medio formulario'
        );
    }

    public function testALgElContenedorOcupaLaMitadDeLaPantalla(): void
    {
        $lg = self::bloque('@media (min-width: 992px)');
        assertNotNull($lg, 'app.css declara la media query de lg (992px)');
        assertTrue(
            (bool) preg_match('/\.fill-container\s*\{[^}]*width:\s*50%/', $lg),
            'col-lg-6: la mitad del ancho de la ventana desde 992px'
        );
        assertTrue(
            (bool) preg_match('/\.fill-container\s*\{[^}]*max-width:\s*50%/', $lg),
            'y el techo acompaña, o el `width` anterior seguiría ganando en el cálculo'
        );
    }

    public function testLasOchoSuperficiesLlevanLaMismaClaseSinAnchoEncima(): void
    {
        $conAnchoPropio = [];
        $presentes = [];
        foreach (self::SUPERFICIES as $vista) {
            $etiqueta = self::etiqueta($vista);
            if ($etiqueta === null) {
                $conAnchoPropio[] = $vista . ' (sin .fill-container)';
                continue;
            }
            $presentes[] = $vista;
            // Una clase de ancho de Bootstrap (`w-50`) o un `style` en la propia
            // etiqueta ganaría a la regla de app.css y dejaría esa superficie
            // fuera del col-12/col-lg-6 sin que ningún test lo delatara.
            if (stripos($etiqueta, 'style=') !== false
                || preg_match('/\bw-(?:auto|25|50|75|100)\b/', $etiqueta) === 1) {
                $conAnchoPropio[] = $vista . ': ' . $etiqueta;
            }
        }
        assertSame([], $conAnchoPropio, 'superficies con ancho propio encima de .fill-container');
        // La regla es de UNA clase: contar superficies hace que añadir una
        // pantalla nueva obligue a decidir su ancho en vez de heredarlo.
        assertSame(8, count($presentes), 'las siete vistas del fill y el preview del constructor');
    }

    public function testNadieReabreElAnchoDelPreviewDelConstructor(): void
    {
        // El constructor tiene una sola superficie de preview — el modal en
        // pantalla completa (`livePreviewContent`) — y se pinta con
        // `.fill-container`: hereda el ancho del formulario publicado en vez de
        // decidirlo. Si aparece una segunda, vuelve a mirarla también aquí.
        $etiqueta = self::etiquetaPorId('views/builder/index.php', 'livePreviewContent');
        assertNotNull($etiqueta, 'la superficie del preview livePreviewContent lleva .fill-container');
        assertTrue(
            preg_match('/\bclass="[^"]*\bfill-container\b[^"]*"/', (string) $etiqueta) === 1,
            'el preview livePreviewContent se pinta con la misma clase que el formulario publicado'
        );
        assertTrue(
            preg_match('/#livePreviewContent\s*\{[^}]*\bwidth\b/', self::css()) !== 1,
            'ninguna regla por id le pone ancho propio: el preview tiene el mismo'
            . ' comportamiento que el fill, y como la media query mira a la ventana,'
            . ' se parte igual aunque viva en un modal-xl'
        );
    }
}
