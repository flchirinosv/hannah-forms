<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * F4 — Paridad i18n del cliente fill con el catálogo del servidor.
 *
 * `fill.js` lleva un FALLBACK_I18N en español para cuando `data-vf-i18n` falta
 * o es inválido. Ese fallback es código muerto el día en que sus claves se
 * desincronizan del catálogo (app/lang/es.php), que es la fuente que también
 * alimenta `data-vf-i18n`:
 *   - cada clave del fallback debe existir en `es.php`, y el fallback debe
 *     cubrir exactamente las claves que `t('...')` usa (sin claves muertas);
 *   - toda clave usada por `t('...')` debe existir en `es.php`, o el
 *     navegador imprimiría el nombre de la clave crudo.
 * Además el fallback no puede auto-referenciarse con `t(...)` internamente
 * (lo hacía dependiente de la misma fuente que debía respaldar: si falta la
 * clave en `data-vf-i18n`, el fallback resolvía a la propia clave).
 */
class FillJSI18nTest
{
    private const JS_PATH = __DIR__ . '/../../public/assets/js/fill.js';
    private const ES_PATH = __DIR__ . '/../../app/lang/es.php';

    public function testElFallbackNoSeAutoReferenciaConT(): void
    {
        $missing = array_values(array_filter(
            preg_split('/\R/', self::fallbackBlock()),
            static fn(string $line) => preg_match("/t\('/", $line) === 1
        ));
        assertSame([], $missing, 'el fallback no puede resolverse con t() (caería a sí mismo)');
    }

    public function testTodasLasClavesDelFallbackExistenEnElCatalogoYAlReves(): void
    {
        $used = self::usedKeys();
        $fallback = self::fallbackKeys();

        $ausentes = array_diff($fallback, self::esKeys());
        assertSame([], $ausentes, 'claves del fallback sin traducción en es.php');

        $muertas = array_diff($fallback, $used);
        assertSame([], $muertas, 'claves del fallback que ninguna t() usa: ' . implode(', ', $muertas));
    }

    public function testTodasLasClavesUsadasPorTExistenEnElCatalogo(): void
    {
        $missing = array_diff(self::usedKeys(), self::esKeys());
        assertSame([], $missing, 'claves usadas por t() sin traducción en es.php');
    }

    private static function fallbackBlock(): string
    {
        $js = (string) file_get_contents(self::JS_PATH);
        if (preg_match('/const FALLBACK_I18N = \{(.*?)\n    \};/s', $js, $m) !== 1) {
            fail('no se encontró el bloque const FALLBACK_I18N en fill.js');
        }
        return $m[1];
    }

    /** @return string[] */
    private static function fallbackKeys(): array
    {
        preg_match_all("/'([a-z]+\.[a-z_]+)'\s*:/", self::fallbackBlock(), $m);
        return $m[1];
    }

    /** @return string[] */
    private static function usedKeys(): array
    {
        $js = (string) file_get_contents(self::JS_PATH);
        preg_match_all("/t\(\s*'([a-z]+\.[a-z_]+)'\s*[,)]/", $js, $m);
        return array_values(array_unique($m[1]));
    }

    /** @return string[] */
    private static function esKeys(): array
    {
        return array_keys((array) require self::ES_PATH);
    }
}