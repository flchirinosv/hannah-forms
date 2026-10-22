<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Contrast;

/**
 * Contraste del texto sobre el degradado (AG12).
 *
 * El cálculo decide `--vf-on-grad` cuando nadie elige un color de texto: blanco
 * sobre fondos oscuros, tinta oscura sobre claros. Lo importante que se prueba
 * aquí no es la fórmula WCAG exacta sino la PROPIEDAD: un fondo claro nunca pide
 * tinta clara (que era el fallo real — un amarillo o un celeste con texto blanco
 * ilegible), y un valor que no se puede parsear cae a blanco en vez de dejar el
 * texto invisible.
 */
class ContrastTest
{
    public function testFondosOscurosPidenTintaClara(): void
    {
        assertSame(Contrast::LIGHT, Contrast::inkFor('#000000'), 'negro pide blanco');
        assertSame(Contrast::LIGHT, Contrast::inkFor('#1e293b'), 'azul muy oscuro pide blanco');
        assertSame(Contrast::LIGHT, Contrast::inkFor('#5b5bd6'), 'un índigo pide blanco');
        assertSame(Contrast::LIGHT, Contrast::inkFor('#8b5cf6'), 'el violeta de marca pide blanco');
    }

    public function testFondosClarosPidenTintaOscura(): void
    {
        assertSame(Contrast::DARK, Contrast::inkFor('#ffffff'), 'blanco pide oscuro');
        assertSame(Contrast::DARK, Contrast::inkFor('#fff59d'), 'un amarillo claro pide oscuro');
        assertSame(Contrast::DARK, Contrast::inkFor('#b3e5fc'), 'un celeste claro pide oscuro');
        assertSame(Contrast::DARK, Contrast::inkFor('#a5d6a7'), 'un verde claro pide oscuro');
    }

    public function testAceptaLaFormaCortaYLaAlmohadillaOpcional(): void
    {
        // `#fff` (atajo de 3) es el mismo blanco que `#ffffff`, con o sin `#`.
        assertSame(Contrast::DARK, Contrast::inkFor('#fff'), '#fff es blanco');
        assertSame(Contrast::DARK, Contrast::inkFor('fff'), 'sin almohadilla también');
        assertSame(Contrast::LIGHT, Contrast::inkFor('000'), '#000 es negro');
    }

    public function testUnValorIlegibleCaeATintaClara(): void
    {
        // El default histórico del texto sobre el degradado es el blanco: un
        // valor que no se puede parsear no puede dejar el texto invisible.
        assertSame(Contrast::LIGHT, Contrast::inkFor('rojo'), 'un nombre CSS no se parsea');
        assertSame(Contrast::LIGHT, Contrast::inkFor(''), 'vacío cae a blanco');
        assertSame(Contrast::LIGHT, Contrast::inkFor('#12345'), 'un hex a medias cae a blanco');
        assertSame(Contrast::LIGHT, Contrast::inkFor('red; } body { display:none'), 'una inyección cae a blanco');
    }

    public function testElAtajoBooleanoCoincideConElHex(): void
    {
        assertTrue(Contrast::prefersLightInk('#000000'), 'negro pide tinta clara');
        assertTrue(!Contrast::prefersLightInk('#ffffff'), 'blanco no pide tinta clara');
    }
}
