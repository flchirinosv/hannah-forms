<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Decide si un color de fondo pide tinta CLARA u OSCURA.
 *
 * Lo usan la marca de la instancia (`Brand`) y el tema del formulario
 * (`FormSettings`): cuando el editor NO elige un color de texto sobre el
 * degradado, la variable `--vf-on-grad` se calcula a partir del color de
 * arranque del degradado. Blancos sobre un amarillo o un celeste claro quedan
 * ilegibles, y pedirle a quien configura la marca que sepa de contraste es
 * pedirle que acierte a ciegas.
 *
 * El cálculo es la luminancia relativa de la WCAG (sRGB linealizada y ponderada
 * 0.2126/0.7152/0.0722). No se busca el ratio exacto ni el umbral 4.5: basta
 * con separar los fondos claros de los oscuros, así que el corte va en 0.5.
 * Un color que no se pueda parsear cae a blanco, que es el default histórico
 * del texto sobre el degradado.
 */
final class Contrast
{
    /** Tinta oscura por defecto cuando el fondo es claro. */
    public const DARK = '#1e293b';

    /** Tinta clara por defecto cuando el fondo es oscuro. */
    public const LIGHT = '#ffffff';

    /**
     * Devuelve el color de tinta (`#rrggbb`) legible sobre `$background`.
     *
     * Acepta hex de 3 o 6 dígitos (con o sin `#`). Cualquier otra cosa devuelve
     * `LIGHT`: el default del degradado de marca siempre ha sido el blanco, y
     * un valor ilegible no debe dejar el texto invisible por un fallo de parseo.
     */
    public static function inkFor(string $background): string
    {
        $rgb = self::rgb($background);
        if ($rgb === null) {
            return self::LIGHT;
        }

        return self::luminance($rgb[0], $rgb[1], $rgb[2]) < 0.5 ? self::LIGHT : self::DARK;
    }

    /**
     * ¿El color es lo bastante oscuro como para querer tinta clara encima?
     *
     * Atajo booleano para quien no quiere el hex, solo la decisión.
     */
    public static function prefersLightInk(string $background): bool
    {
        return self::inkFor($background) === self::LIGHT;
    }

    /**
     * Hex (#rgb o #rrggbb, con o sin almohadilla) → [r, g, b] en 0..255, o
     * `null` si no es un color válido.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function rgb(string $value): ?array
    {
        $hex = ltrim(trim($value), '#');
        if (preg_match('/^[0-9a-fA-F]{3}$/', $hex) === 1) {
            // Atajo de 3 dígitos: cada uno se duplica (f -> ff).
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** Luminancia relativa WCAG (0 = negro, 1 = blanco). */
    private static function luminance(int $r, int $g, int $b): float
    {
        return 0.2126 * self::channel($r)
            + 0.7152 * self::channel($g)
            + 0.0722 * self::channel($b);
    }

    /** Linealización sRGB de un canal 0..255 a 0..1. */
    private static function channel(int $c): float
    {
        $v = $c / 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }
}
