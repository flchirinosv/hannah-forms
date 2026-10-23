<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\AppSettings;
use App\Core\Database;
use App\Core\Secret;
use App\Core\TurnstileSettings;
use App\Services\Turnstile;

/**
 * Claves de Cloudflare Turnstile editables desde el panel (AG5).
 *
 * Tres propiedades que fallan en silencio si se rompen:
 *
 * 1. **Precedencia**: la fila gana, sin fila manda el `.env`. Si se mezclara mal,
 *    una instalación que mueve sus claves al panel seguiría verificando con las
 *    viejas del `.env` (o al revés) sin que nada avise.
 * 2. **La secret se guarda cifrada y nunca en claro.** Sin `APP_KEY`, `save()` lo
 *    dice explícitamente en vez de escribir la credencial legible en la tabla.
 * 3. **Coherencia**: `Turnstile::isConfigured()` exige LAS DOS claves. `save()`
 *    no puede dejar «medio configurado», que en Turnstile es peor que no
 *    configurado: todos los formularios anti-bot dejarían de servirse.
 *
 * `TurnstileSettings` escribe en `app_settings`, que es de la INSTALACIÓN: se
 * guardan las filas previas y se reponen al terminar, como `MailSettingsTest`.
 */
class TurnstileSettingsTest
{
    /** @var array<string, ?string> */
    private array $previas = [];

    public function setUp(): void
    {
        AppSettings::forgetForTesting();
        Config::forgetForTesting();
        Turnstile::setKeysForTesting(null, null);

        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        foreach (TurnstileSettings::KEYS as $key) {
            $this->previas[$key] = AppSettings::get($key);
        }
        self::borrarFilas();
    }

    public function tearDown(): void
    {
        AppSettings::forgetForTesting();
        Config::forgetForTesting();
        Turnstile::setKeysForTesting(null, null);
        Secret::setKeyForTesting(null);

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
            'DELETE FROM app_settings WHERE key_name IN (:a, :b)',
            ['a' => TurnstileSettings::SITE_KEY, 'b' => TurnstileSettings::SECRET_KEY]
        );
    }

    private static function fila(string $key): ?array
    {
        return Database::getInstance(Config::db())->fetch(
            'SELECT value FROM app_settings WHERE key_name = :k',
            ['k' => $key]
        );
    }

    private static function env(string $site = '', string $secret = ''): void
    {
        Config::setForTesting('app', [
            'turnstile' => ['site_key' => $site, 'secret_key' => $secret],
        ]);
    }

    /* ------------------------------------------------------------------
     * Precedencia fila ↔ .env
     * ------------------------------------------------------------------ */

    public function testSinFilasLasClavesSalenDelEnv(): void
    {
        self::env('env-site', 'env-secret');

        assertSame('env-site', TurnstileSettings::siteKey(), 'sin fila, la site key sale del .env');
        assertSame('env-secret', TurnstileSettings::secretKey(), 'y la secret también');
    }

    public function testLaFilaGanaAlEnv(): void
    {
        self::env('env-site', 'env-secret');
        Secret::setKeyForTesting(Secret::generateKey());
        TurnstileSettings::save(['site_key' => 'fila-site', 'secret' => 'fila-secret']);

        assertSame('fila-site', TurnstileSettings::siteKey(), 'la fila gana para la site key');
        assertSame('fila-secret', TurnstileSettings::secretKey(), 'y para la secret');
    }

    public function testUnaSecretGuardadaSinAppKeyCaeAlEnv(): void
    {
        self::env('', 'env-secret');
        Secret::setKeyForTesting(Secret::generateKey());
        TurnstileSettings::save(['site_key' => 'fila-site', 'secret' => 'fila-secret']);

        // Se pierde la clave de cifrado (APP_KEY cambiada / ausente): la fila no
        // se puede descifrar y la resolución cae al `.env` completo.
        Secret::setKeyForTesting(null);

        assertSame('fila-site', TurnstileSettings::siteKey(), 'la site key no está cifrada: sigue leyéndose');
        assertSame('env-secret', TurnstileSettings::secretKey(), 'la secret ilegible cae al .env');
        assertFalse(TurnstileSettings::secretStored(), 'y para la UI es «no guardada»');
    }

    /* ------------------------------------------------------------------
     * Turnstile lee de aquí
     * ------------------------------------------------------------------ */

    public function testTurnstileUsaLasClavesDeLaFila(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());
        self::env('', '');
        TurnstileSettings::save(['site_key' => 'site-x', 'secret' => 'secret-x']);

        assertTrue(Turnstile::isConfigured(), 'con las dos claves de la fila, Turnstile está configurado');
        assertSame('site-x', Turnstile::publicSiteKey(), 'y la site key que se pinta es la de la fila');
    }

    /* ------------------------------------------------------------------
     * save() y coherencia
     * ------------------------------------------------------------------ */

    public function testSaveCifraLaSecretYNoLaDevuelve(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());
        $r = TurnstileSettings::save(['site_key' => 'site-1', 'secret' => 'mi-secret']);

        assertTrue($r['ok'], 'guarda sin error');

        $fila = self::fila(TurnstileSettings::SECRET_KEY);
        assertNotNull($fila, 'la secret se guarda');
        assertTrue(strpos((string) $fila['value'], 'mi-secret') === false, 'y NUNCA en claro');
        assertSame('mi-secret', Secret::decrypt((string) $fila['value']), 'se descifra al leer');
        assertTrue(TurnstileSettings::secretStored(), 'y la UI la ve como guardada');
    }

    public function testSinAppKeyLaSecretNoSeGuardaEnClaro(): void
    {
        // `null` = vuelve a leer APP_KEY; `''` = fuerza su ausencia.
        Secret::setKeyForTesting('');
        $r = TurnstileSettings::save(['site_key' => 'site-1', 'secret' => 'mi-secret']);

        assertFalse($r['ok'], 'sin APP_KEY no se guarda');
        assertNull(self::fila(TurnstileSettings::SECRET_KEY), 'y no queda la secret en claro');
    }

    public function testSaveRechazaMedioConfiguradoSinEscribirNada(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());
        self::env('', '');
        // Sin secret key: solo site key dejaría «medio configurado».
        $r = TurnstileSettings::save(['site_key' => 'solo-site', 'secret' => null]);

        assertFalse($r['ok'], 'una sola de las dos claves se rechaza');
        assertNull(self::fila(TurnstileSettings::SITE_KEY), 'y no escribe nada a medias');
    }

    public function testSaveConLasDosClavesEsCoherente(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());
        self::env('', '');
        $r = TurnstileSettings::save(['site_key' => 's', 'secret' => 'k']);

        assertTrue($r['ok']);
        assertTrue(Turnstile::isConfigured());
    }

    public function testVaciarLaSiteKeyBorraSuFila(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());
        self::env('', '');
        TurnstileSettings::save(['site_key' => 's', 'secret' => 'k']);
        assertNotNull(self::fila(TurnstileSettings::SITE_KEY));

        // Vaciar la site key Y borrar la secret: si no, quedaría una sola.
        TurnstileSettings::save(['site_key' => '', 'clear_secret' => '1']);

        assertNull(self::fila(TurnstileSettings::SITE_KEY), 'vaciar la site key borra su fila');
        assertNull(self::fila(TurnstileSettings::SECRET_KEY), 'y borrar la secret borra la suya');
    }

    public function testClearSecretBorraLaFila(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());
        self::env('', '');
        TurnstileSettings::save(['site_key' => 's', 'secret' => 'k']);
        assertTrue(TurnstileSettings::secretStored());

        TurnstileSettings::save(['site_key' => '', 'clear_secret' => '1']);

        assertFalse(TurnstileSettings::secretStored(), 'tras borrar, ya no hay secret guardada');
    }

    public function testAllDistingueLoGuardadoDeLoResuelto(): void
    {
        self::env('env-site', 'env-secret');
        Secret::setKeyForTesting(Secret::generateKey());
        TurnstileSettings::save(['site_key' => 'site-1', 'secret' => 'k']);

        $all = TurnstileSettings::all();

        assertSame('site-1', $all['stored_site_key'], 'lo guardado es lo de la fila');
        assertSame('env-site', $all['env_site_key'], 'y el .env se expone para el placeholder');
        assertTrue($all['secret_stored'], 'con secret utilizable, la UI la ve guardada');
        assertTrue($all['configured'], 'y las dos claves juntas configuran Turnstile');
    }
}
