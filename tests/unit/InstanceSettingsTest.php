<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppSettings;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\I18n;
use App\Core\InstanceSettings;
use App\Core\PublicUrl;

/**
 * Identidad operativa de la instancia editable desde el panel (AG4): URL pública,
 * zona horaria e idioma.
 *
 * Dos propiedades que fallan en silencio si se rompen:
 *
 * 1. **Precedencia**: la fila gana, sin fila manda el `.env`. Si se mezclara mal,
 *    una instalación que mueve su dominio/zona/idioma al panel seguiría
 *    compartiendo enlaces con el host viejo —o, peor, presentando fechas en otra
 *    zona— sin que nada avise.
 * 2. **Un valor inválido no se guarda NI se sirve**: una URL a medio escribir o
 *    una zona inventada en la fila no puede romper el enlace ni la aritmética de
 *    fechas, así que se ignora y se cae al `.env`.
 *
 * `InstanceSettings` escribe en `app_settings`, que es de la INSTALACIÓN: se
 * guardan las filas previas y se reponen al terminar, como `MailSettingsTest`.
 */
class InstanceSettingsTest
{
    /** @var array<string, ?string> */
    private array $previas = [];

    public function setUp(): void
    {
        AppSettings::forgetForTesting();
        Config::forgetForTesting();
        Clock::forgetDisplayZoneForTesting();
        PublicUrl::setBaseForTesting(null);

        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        foreach (InstanceSettings::KEYS as $key) {
            $this->previas[$key] = AppSettings::get($key);
        }
        self::borrarFilas();
    }

    public function tearDown(): void
    {
        AppSettings::forgetForTesting();
        Config::forgetForTesting();
        Clock::forgetDisplayZoneForTesting();
        PublicUrl::setBaseForTesting(null);

        self::borrarFilas();
        foreach ($this->previas as $key => $value) {
            if ($value !== null) {
                AppSettings::set($key, $value);
            }
        }
    }

    private static function borrarFilas(): void
    {
        Database::getInstance(Config::db())->execute(
            'DELETE FROM app_settings WHERE key_name IN (:a, :b, :c)',
            [
                'a' => InstanceSettings::PUBLIC_URL,
                'b' => InstanceSettings::TIMEZONE,
                'c' => InstanceSettings::LOCALE,
            ]
        );
    }

    private static function fila(string $key): ?array
    {
        return Database::getInstance(Config::db())->fetch(
            'SELECT value FROM app_settings WHERE key_name = :k',
            ['k' => $key]
        );
    }

    /* ------------------------------------------------------------------
     * Precedencia fila ↔ .env
     * ------------------------------------------------------------------ */

    public function testSinFilasLaIdentidadSaleDelEnv(): void
    {
        Config::setForTesting('app', [
            'public_url' => 'https://env.test',
            'timezone'   => 'Europe/Madrid',
            'locale'     => 'en',
        ]);

        assertSame('https://env.test', InstanceSettings::publicUrl(), 'sin fila, la URL sale del .env');
        assertSame('Europe/Madrid', InstanceSettings::timezone());
        assertSame('en', InstanceSettings::locale());
    }

    public function testLaFilaGanaAlEnv(): void
    {
        Config::setForTesting('app', [
            'public_url' => 'https://env.test',
            'timezone'   => 'Europe/Madrid',
            'locale'     => 'en',
        ]);
        InstanceSettings::setForTesting([
            InstanceSettings::PUBLIC_URL => 'https://fila.test',
            InstanceSettings::TIMEZONE   => 'America/Caracas',
            InstanceSettings::LOCALE     => 'es',
        ]);

        assertSame('https://fila.test', InstanceSettings::publicUrl(), 'la fila gana para la URL');
        assertSame('America/Caracas', InstanceSettings::timezone(), 'y para la zona');
        assertSame('es', InstanceSettings::locale(), 'y para el idioma');
    }

    public function testUnValorInvalidoEnLaFilaCaeAlEnv(): void
    {
        Config::setForTesting('app', [
            'public_url' => 'https://env.test',
            'timezone'   => 'Europe/Madrid',
            'locale'     => 'en',
        ]);
        InstanceSettings::setForTesting([
            InstanceSettings::PUBLIC_URL => 'esto no es una url con espacios y demas',
            InstanceSettings::TIMEZONE   => 'Marte/Olympus',
            InstanceSettings::LOCALE     => 'fr',
        ]);

        assertSame('https://env.test', InstanceSettings::publicUrl(), 'una URL inválida se ignora');
        assertSame('Europe/Madrid', InstanceSettings::timezone(), 'una zona inventada se ignora');
        assertSame('en', InstanceSettings::locale(), 'un idioma no soportado se ignora');
    }

    /* ------------------------------------------------------------------
     * Los consumidores leen de aquí
     * ------------------------------------------------------------------ */

    public function testPublicUrlBaseUsaLaFila(): void
    {
        Config::setForTesting('app', ['public_url' => 'https://env.test']);
        InstanceSettings::setForTesting([InstanceSettings::PUBLIC_URL => 'https://panel.test']);

        assertSame('https://panel.test', PublicUrl::base(), 'PublicUrl::base() pasa por la fila');
        assertTrue(PublicUrl::isPinned(), 'y queda «fijada», no deducida de la petición');
    }

    public function testClockUsaLaZonaDeLaFila(): void
    {
        Config::setForTesting('app', ['timezone' => 'Europe/Madrid']);
        InstanceSettings::setForTesting([InstanceSettings::TIMEZONE => 'America/Caracas']);

        Clock::forgetDisplayZoneForTesting();
        assertSame('America/Caracas', Clock::displayZone()->getName(), 'Clock::displayZone() pasa por la fila');
    }

    public function testI18nResuelveElIdiomaDeLaFila(): void
    {
        Config::setForTesting('app', ['locale' => 'en']);
        InstanceSettings::setForTesting([InstanceSettings::LOCALE => 'es']);

        assertSame('es', I18n::resolve([]), 'I18n::resolve() pasa por la fila');
    }

    /* ------------------------------------------------------------------
     * save()
     * ------------------------------------------------------------------ */

    public function testSaveGuardaLosTresValores(): void
    {
        $r = InstanceSettings::save([
            'public_url' => 'https://guardado.test',
            'timezone'   => 'America/Bogota',
            'locale'     => 'en',
        ]);

        assertTrue($r['ok'], 'guarda sin error');
        assertSame('https://guardado.test', InstanceSettings::publicUrl());
        assertSame('America/Bogota', InstanceSettings::timezone());
        assertSame('en', InstanceSettings::locale());
    }

    public function testUnaUrlInvalidaNoGuardaNada(): void
    {
        $r = InstanceSettings::save([
            'public_url' => 'no-es-una-url válida',
            'timezone'   => 'America/Caracas',
            'locale'     => 'es',
        ]);

        assertFalse($r['ok']);
        assertNull(self::fila(InstanceSettings::PUBLIC_URL), 'una URL inválida no escribe ninguna fila');
        assertNull(self::fila(InstanceSettings::TIMEZONE), 'y no guarda nada a medias');
    }

    public function testUnaZonaInvalidaNoGuardaNada(): void
    {
        $r = InstanceSettings::save([
            'public_url' => '', 'timezone' => 'Marte/Olympus', 'locale' => 'es',
        ]);

        assertFalse($r['ok']);
        assertNull(self::fila(InstanceSettings::TIMEZONE));
    }

    public function testUnIdiomaNoSoportadoNoGuardaNada(): void
    {
        $r = InstanceSettings::save([
            'public_url' => '', 'timezone' => '', 'locale' => 'fr',
        ]);

        assertFalse($r['ok']);
        assertNull(self::fila(InstanceSettings::LOCALE));
    }

    public function testVaciarUnCampoBorraSuFila(): void
    {
        InstanceSettings::save([
            'public_url' => 'https://x.test', 'timezone' => 'America/Caracas', 'locale' => 'en',
        ]);
        assertNotNull(self::fila(InstanceSettings::PUBLIC_URL));

        InstanceSettings::save([
            'public_url' => '', 'timezone' => 'America/Caracas', 'locale' => 'en',
        ]);

        assertNull(self::fila(InstanceSettings::PUBLIC_URL), 'vaciar la URL borra la fila (vuelve al .env)');
        assertSame('America/Caracas', InstanceSettings::timezone(), 'y no toca las otras dos');
    }

    public function testAllDistingueLoGuardadoDeLoResuelto(): void
    {
        Config::setForTesting('app', [
            'public_url' => 'https://env.test',
            'timezone'   => 'Europe/Madrid',
            'locale'     => 'en',
        ]);
        InstanceSettings::setForTesting([InstanceSettings::LOCALE => 'es']);

        $all = InstanceSettings::all();

        assertSame('', $all['stored_public_url'], 'sin fila, lo guardado está vacío');
        assertSame('https://env.test', $all['env_public_url'], 'y el .env se expone para el placeholder');
        assertSame('https://env.test', $all['public_url'], 'lo resuelto cae al .env');
        assertSame('es', $all['stored_locale'], 'con fila, lo guardado es el valor');
        assertSame('es', $all['locale']);
    }

    public function testUnaUrlSinEsquemaSeAceptaYPrefijadaAlServir(): void
    {
        $r = InstanceSettings::save([
            'public_url' => 'formularios.test.local', 'timezone' => '', 'locale' => '',
        ]);

        assertTrue($r['ok'], 'sin esquema se acepta (se le añade http://)');
        assertSame('http://formularios.test.local', PublicUrl::base());
    }
}
