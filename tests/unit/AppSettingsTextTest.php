<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppSettings;
use App\Core\Config;
use App\Core\Database;

/**
 * `AppSettings::set()` / `delete()` — el ajuste de TEXTO.
 *
 * El bloque de autogestión necesita guardar textos (nombre de la instancia, color,
 * host SMTP…) además de los interruptores que ya había. La diferencia con
 * `setBool()` es que aquí `null` y `''` NO son lo mismo: `null` borra la fila
 * (el ajuste vuelve al default del `.env`) y `''` guarda un vacío explícito. Es
 * lo que hace falta para un ajuste opcional: «no lo he tocado» no es «déjalo en
 * blanco».
 *
 * `app_settings` es una tabla de la INSTALACIÓN, no un fixture: usa una clave
 * propia y la borra al terminar para no dejar rastro.
 */
class AppSettingsTextTest
{
    private const KEY = 'test_autogestion_texto';

    public function setUp(): void
    {
        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        self::borrar();
    }

    public function tearDown(): void
    {
        self::borrar();
        AppSettings::forgetForTesting();
    }

    private static function borrar(): void
    {
        Database::getInstance(Config::db())->execute(
            'DELETE FROM app_settings WHERE key_name = :k',
            ['k' => self::KEY]
        );
    }

    public function testEscribeYLeeUnTexto(): void
    {
        assertTrue(AppSettings::set(self::KEY, 'VanillaForms de Acme'));
        assertSame('VanillaForms de Acme', AppSettings::get(self::KEY));
    }

    public function testVacioExplicitoEsUnaFilaYNoUnAusente(): void
    {
        AppSettings::set(self::KEY, '');

        // La fila existe con `''`, que es distinto de `null` (fila ausente).
        assertSame('', AppSettings::get(self::KEY), 'el vacío guardado se lee como vacío, no como ausente');
    }

    /** `null` borra: es la forma de devolver el ajuste a su default. */
    public function testSetConNullBorraLaFila(): void
    {
        AppSettings::set(self::KEY, 'algo');
        assertNotNull(AppSettings::get(self::KEY));

        AppSettings::set(self::KEY, null);
        assertNull(AppSettings::get(self::KEY), 'sin la fila, el ajuste vuelve al default del .env');
    }

    public function testDeleteSobreLoAusenteNoEsUnFallo(): void
    {
        // Borrar algo que no está logra el objetivo (que la fila no exista).
        assertTrue(AppSettings::delete(self::KEY));
        AppSettings::set(self::KEY, 'x');
        assertTrue(AppSettings::delete(self::KEY));
        assertNull(AppSettings::get(self::KEY));
    }

    /** Reescribir actualiza en el sitio, sin duplicar ni lanzar por clave única. */
    public function testReescribirActualizaSinDuplicar(): void
    {
        AppSettings::set(self::KEY, 'uno');
        AppSettings::set(self::KEY, 'dos');

        $db = Database::getInstance(Config::db());
        $c = (int) $db->fetch(
            'SELECT COUNT(*) AS c FROM app_settings WHERE key_name = :k',
            ['k' => self::KEY]
        )['c'];
        assertSame(1, $c, 'una sola fila por clave');
        assertSame('dos', AppSettings::get(self::KEY));
    }

    /**
     * El override de test manda sobre la tabla, como en `bool()`. Se comprueba
     * aquí para dejar claro que el seam cubre el texto igual que el interruptor.
     */
    public function testElOverrideDeTestMandaSobreLaTabla(): void
    {
        AppSettings::set(self::KEY, 'de-la-tabla');
        AppSettings::setForTesting([self::KEY => 'del-override']);
        assertSame('del-override', AppSettings::get(self::KEY));

        AppSettings::forgetForTesting();
        assertSame('de-la-tabla', AppSettings::get(self::KEY));
    }
}
