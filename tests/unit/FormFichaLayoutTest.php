<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * La cabecera y la tarjeta de ajustes de la ficha: dos regresiones de maquetación.
 *
 * Las dos son de las que no dan ningún error: un botón de 32px al lado de uno de
 * 44 no falla, y un `col-md-3` dentro de una columna estrecha no falla —solo se
 * ve mal en una pantalla de escritorio—. Como no hay PHP ni JS que se caiga, la
 * única señal es visual, y es exactamente la clase de regla que este repo ata con
 * un test (lo mismo que `FillLayoutTest` con el ancho del fill).
 *
 * ## Lo que se fija
 *
 *  1. **Los cuatro controles del top de la ficha miden lo mismo.** La regla
 *     `.app-head-actions` sube los secundarios y el botón de menú al alto y al
 *     radio del CTA, y se acota para no tocar la cabecera de respuestas, que usa
 *     `btn-sm` a propósito.
 *  2. **Notificaciones y Webhook se reparten por el ancho de la TARJETA, no de la
 *     ventana.** Viven en una `col-lg-4` de ~400px: un `col-md-*` mira la ventana
 *     (≥768px) y ahí dentro metía tres o cuatro columnas enanas y dejaba un tercio
 *     de la fila vacío. Por eso el par es `col-sm-6` y la URL del webhook va a
 *     `col-12`.
 *
 * Sin BD: solo lee `app.css` y la vista de la ficha.
 */
class FormFichaLayoutTest
{
    private static function vista(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/dashboard/forms/show.php');
    }

    private static function css(): string
    {
        $bruto = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
        // Sin comentarios: un selector citado en una nota no puede confundirse con
        // la regla de verdad.
        return (string) preg_replace('#/\*.*?\*/#s', '', $bruto);
    }

    /** Cuerpo entre llaves del bloque que empieza por $pre. */
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
        return $cierre === null ? null : substr($css, $abierto + 1, $cierre - $abierto - 1);
    }

    public function testLosBotonesDelTopDeLaFichaMidenLoMismo(): void
    {
        $btn = self::bloque('.app-head-actions .btn');
        assertNotNull($btn, 'app.css tiene la regla que unifica la fila de acciones del top');
        assertSame(
            1,
            preg_match('/min-height:\s*var\(--vf-btn-cta-h\)/', (string) $btn),
            'los secundarios suben al alto del CTA (44px): antes convivían 44/38/32 en la misma fila'
        );
        assertSame(
            1,
            preg_match('/border-radius:\s*12px/', (string) $btn),
            'y al mismo radio, o los lados no casan aunque el alto coincida'
        );

        $dd = self::bloque('.app-head-actions .app-dd-btn');
        assertNotNull($dd, 'el botón de menú ⋯ también se acota en la cabecera de la ficha');
        assertSame(
            1,
            preg_match('/width:\s*var\(--vf-btn-cta-h\)/', (string) $dd),
            'icono cuadrado al lado del CTA, no un cuadro de 32 junto a pastillas de 44'
        );
        assertSame(
            1,
            preg_match('/height:\s*var\(--vf-btn-cta-h\)/', (string) $dd),
            'y con la misma altura'
        );

        // La fila real de la ficha trae los cuatro controles dentro del contenedor
        // `app-head-actions`: sin la clase en la vista, la regla no engancha a
        // ninguno y el test de CSS pasaría midiendo una regla muerta.
        $vista = self::vista();
        $desde = strpos($vista, 'app-head-actions');
        assertTrue($desde !== false, 'la vista de la ficha marca su fila de acciones con app-head-actions');
        $acciones = substr($vista, (int) $desde);
        $hasta = strpos($acciones, '<div class="row g-4">');
        assertTrue($hasta !== false, 'la fila de acciones va antes del cuerpo de la ficha');
        $acciones = substr($acciones, 0, (int) $hasta);

        foreach (['app-btn-primary', 'btn-outline-primary', 'btn-outline-success', 'app-dd-btn'] as $clase) {
            assertTrue(
                str_contains($acciones, $clase),
                'la fila de acciones de la ficha tiene que traer ' . $clase
            );
        }
    }

    public function testLosCamposDeAvisosYWebhookNoDependenDelAnchoDeLaVentana(): void
    {
        $vista = self::vista();

        $avisos = substr($vista, (int) strpos($vista, 'id="grp-avisos"'));
        $avisos = substr($avisos, 0, (int) strpos($avisos, 'id="grp-webhook"'));

        $webhook = substr($vista, (int) strpos($vista, 'id="grp-webhook"'));
        $webhook = substr($webhook, 0, (int) strpos($webhook, 'id="grp-gracias"'));

        foreach (['Notificaciones' => $avisos, 'Webhook' => $webhook] as $nombre => $bloque) {
            assertTrue($bloque !== '', 'se pudo leer el bloque ' . $nombre);
            assertTrue(
                str_contains($bloque, 'col-sm-6'),
                $nombre . ': los campos se emparejan con `col-sm-6` (dos por fila en la tarjeta estrecha)'
            );
            assertFalse(
                str_contains($bloque, 'col-md-'),
                $nombre . ': `col-md-*` mira la VENTANA, no la tarjeta; en una `col-lg-4` de ~400px'
                    . ' metía tres o cuatro columnas enanas y dejaba la fila a medias'
            );
        }
    }
}
