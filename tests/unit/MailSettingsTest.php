<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppSettings;
use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;
use App\Core\MailSettings;
use App\Core\Secret;

/**
 * Configuración SMTP de la instancia editable desde el panel (AG3).
 *
 * Dos propiedades que fallan en silencio si se rompen:
 * 1. **Precedencia**: la fila gana, sin fila manda el `.env`. Si se mezclara mal,
 *    una instalación que mueve el SMTP al panel dejaría de mandar correo sin que
 *    nada avise, o —peor— seguiría usando credenciales viejas del `.env`.
 * 2. **La contraseña se guarda cifrada y nunca en claro.** Sin `APP_KEY`, `save()`
 *    lo dice explícitamente en vez de escribir la contraseña legible en la tabla.
 *
 * `MailSettings` escribe en `app_settings`, que es de la INSTALACIÓN: se guardan
 * las filas previas y se reponen al terminar, como `BrandControllerTest`.
 */
class MailSettingsTest
{
    /** @var array<string, ?string> */
    private array $previas = [];

    public function setUp(): void
    {
        AppSettings::forgetForTesting();
        Config::forgetForTesting();
        Mailer::setConfigOverride(null);

        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        foreach (MailSettings::KEYS as $key) {
            $this->previas[$key] = AppSettings::get($key);
        }
        self::borrarFilas();
    }

    public function tearDown(): void
    {
        Mailer::setConfigOverride(null);
        Secret::setKeyForTesting(null);
        AppSettings::forgetForTesting();
        Config::forgetForTesting();

        self::borrarFilas();
        foreach ($this->previas as $key => $value) {
            if ($value !== null) {
                AppSettings::set($key, $value);
            }
        }
        unset($_POST, $_SERVER['REQUEST_METHOD']);
    }

    private static function borrarFilas(): void
    {
        Database::getInstance(Config::db())->execute(
            'DELETE FROM app_settings WHERE key_name IN (:a, :b, :c, :d, :e, :f, :g, :h)',
            [
                'a' => MailSettings::HOST,
                'b' => MailSettings::PORT,
                'c' => MailSettings::ENCRYPTION,
                'd' => MailSettings::USER,
                'e' => MailSettings::PASSWORD,
                'f' => MailSettings::FROM_ADDRESS,
                'g' => MailSettings::FROM_NAME,
                'h' => MailSettings::TIMEOUT,
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

    public function testSinFilasLaConfigSaleDelEnv(): void
    {
        Config::setForTesting('mail', [
            'host' => 'smtp.env.test', 'port' => 465, 'encryption' => 'ssl',
            'user' => 'u@env.test', 'password' => 'clave-env',
            'from_address' => 'no-reply@env.test', 'from_name' => 'Env', 'timeout' => 20,
        ]);

        $all = MailSettings::all();

        assertSame('smtp.env.test', $all['host'], 'sin fila, el host sale del .env');
        assertSame(465, $all['port']);
        assertSame('ssl', $all['encryption']);
        assertSame('clave-env', $all['password'], 'y la contraseña del .env');
        assertTrue(MailSettings::isConfigured(), 'con host del .env hay SMTP');
    }

    public function testLaFilaGanaAlEnv(): void
    {
        Config::setForTesting('mail', ['host' => 'smtp.env.test', 'port' => 587, 'encryption' => 'tls', 'timeout' => 15]);
        Secret::setKeyForTesting(Secret::generateKey());
        MailSettings::setForTesting([
            MailSettings::HOST       => 'smtp.fila.test',
            MailSettings::PORT       => '2525',
            MailSettings::ENCRYPTION => 'ssl',
            MailSettings::PASSWORD   => (string) Secret::encrypt('clave-fila'),
        ]);

        $all = MailSettings::all();

        assertSame('smtp.fila.test', $all['host'], 'la fila gana');
        assertSame(2525, $all['port']);
        assertSame('ssl', $all['encryption']);
        assertSame('clave-fila', $all['password'], 'la contraseña se descifra');
        assertTrue(MailSettings::passwordStored());
    }

    public function testSinAppKeyLaContrasenaCaeAlEnv(): void
    {
        Config::setForTesting('mail', ['host' => 'h', 'password' => 'env-pass']);
        Secret::setKeyForTesting(null);
        // Fila con «contraseña» que no se puede descifrar (nunca cifrada, o con
        // otra clave): da igual el motivo, el efecto es el mismo.
        MailSettings::setForTesting([MailSettings::PASSWORD => 'no-es-cifrado']);

        $all = MailSettings::all();

        assertSame('env-pass', $all['password'], 'sin APP_KEY no se puede descifrar: manda el .env');
        assertFalse(MailSettings::passwordStored(), 'y para la UI no hay contraseña guardada');
    }

    public function testUnValorRaroDePuertoOCifradoNoRompe(): void
    {
        Config::setForTesting('mail', ['host' => 'h', 'port' => 587, 'encryption' => 'tls', 'timeout' => 15]);
        MailSettings::setForTesting([
            MailSettings::PORT       => 'no-es-numero',
            MailSettings::ENCRYPTION => 'pigeon',
        ]);

        $all = MailSettings::all();

        assertSame(587, $all['port'], 'un puerto no numérico cae al .env');
        assertSame('tls', $all['encryption'], 'y un cifrado desconocido también');
    }

    /* ------------------------------------------------------------------
     * Mailer lee de aquí
     * ------------------------------------------------------------------ */

    public function testMailerUsaLaConfigDelPanel(): void
    {
        Config::setForTesting('mail', ['host' => 'smtp.env.test', 'port' => 587, 'from_address' => 'a@e.test']);
        MailSettings::setForTesting([MailSettings::HOST => 'smtp.panel.test']);

        $cfg = Mailer::config();

        assertSame('smtp.panel.test', $cfg['host'], 'Mailer::config() pasa por MailSettings');
        assertTrue(Mailer::isConfigured(), 'y con host, está configurado');
    }

    /* ------------------------------------------------------------------
     * save()
     * ------------------------------------------------------------------ */

    public function testSaveGuardaLaConfigYLaContrasenaCifrada(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());

        $r = MailSettings::save([
            'host' => 'smtp.guardado.test', 'port' => '587', 'encryption' => 'tls',
            'user' => 'u@t.test', 'password' => 'secreto-123',
            'from_address' => 'no-reply@t.test', 'from_name' => 'Prueba', 'timeout' => '15',
        ]);

        assertTrue($r['ok'], 'guarda sin error');

        $row = self::fila(MailSettings::PASSWORD);
        assertNotNull($row, 'hay fila de contraseña');
        assertFalse(str_contains((string) $row['value'], 'secreto-123'), 'la contraseña NO se guarda en claro');
        assertSame('secreto-123', Secret::decrypt((string) $row['value']), 'pero se puede descifrar');
        assertSame('smtp.guardado.test', MailSettings::all()['host'], 'y el host queda guardado');
    }

    public function testSinAppKeyNoSeGuardaLaContrasenaYLoDice(): void
    {
        // `null` = vuelve a leer APP_KEY; `''` = fuerza su ausencia. El test
        // tiene que simular la instalación SIN clave, no la del .env actual.
        Secret::setKeyForTesting('');

        $r = MailSettings::save([
            'host' => 'h', 'port' => '587', 'encryption' => 'tls',
            'password' => 'no-debe-guardarse', 'from_address' => '', 'from_name' => '', 'timeout' => '15',
        ]);

        assertFalse($r['ok'], 'sin APP_KEY no puede guardar una contraseña');
        assertTrue(str_contains((string) $r['error'], 'APP_KEY'), 'y el mensaje dice qué falta');
        assertNull(self::fila(MailSettings::PASSWORD), 'la contraseña no se escribe en claro');
        assertNull(self::fila(MailSettings::HOST), 'y no se guardó nada a medias');
    }

    public function testUnPuertoFueraDeRangoNoGuardaNada(): void
    {
        $r = MailSettings::save([
            'host' => 'h', 'port' => '70000', 'encryption' => 'tls',
            'user' => '', 'from_address' => '', 'from_name' => '', 'timeout' => '15',
        ]);

        assertFalse($r['ok']);
        assertNull(self::fila(MailSettings::HOST), 'un error de validación no escribe ninguna fila');
    }

    public function testUnCifradoFueraDeLaListaNoGuardaNada(): void
    {
        $r = MailSettings::save([
            'host' => 'h', 'port' => '587', 'encryption' => 'pigeon',
            'user' => '', 'from_address' => '', 'from_name' => '', 'timeout' => '15',
        ]);

        assertFalse($r['ok']);
        assertNull(self::fila(MailSettings::HOST));
    }

    public function testUnRemitenteInvalidoNoGuardaNada(): void
    {
        $r = MailSettings::save([
            'host' => 'h', 'port' => '587', 'encryption' => 'tls',
            'user' => '', 'from_address' => 'no-es-un-correo', 'from_name' => '', 'timeout' => '15',
        ]);

        assertFalse($r['ok']);
        assertNull(self::fila(MailSettings::HOST));
    }

    public function testVaciarUnTextoBorraSuFila(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());
        // Primero con host; luego vacío → vuelve al .env.
        MailSettings::save([
            'host' => 'smtp.x.test', 'port' => '587', 'encryption' => 'tls',
            'user' => '', 'from_address' => '', 'from_name' => '', 'timeout' => '15',
        ]);
        assertNotNull(self::fila(MailSettings::HOST));

        MailSettings::save([
            'host' => '', 'port' => '587', 'encryption' => 'tls',
            'user' => '', 'from_address' => '', 'from_name' => '', 'timeout' => '15',
        ]);

        assertNull(self::fila(MailSettings::HOST), 'vaciar el host borra la fila (vuelve al .env)');
    }

    public function testSinContrasenaNoSeTocaLaGuardadaYClearLaBorra(): void
    {
        Secret::setKeyForTesting(Secret::generateKey());
        MailSettings::save([
            'host' => 'h', 'port' => '587', 'encryption' => 'tls', 'password' => 'primera',
            'user' => '', 'from_address' => '', 'from_name' => '', 'timeout' => '15',
        ]);
        $antes = (string) (self::fila(MailSettings::PASSWORD)['value'] ?? '');
        assertTrue($antes !== '', 'se guardó la contraseña inicial');

        // Sin password en el POST: se conserva.
        MailSettings::save([
            'host' => 'h', 'port' => '587', 'encryption' => 'tls',
            'user' => '', 'from_address' => '', 'from_name' => '', 'timeout' => '15',
        ]);
        assertSame($antes, (string) (self::fila(MailSettings::PASSWORD)['value'] ?? ''), 'sin contraseña no la toca');

        // Con clear: se borra.
        MailSettings::save([
            'host' => 'h', 'port' => '587', 'encryption' => 'tls', 'clear_password' => '1',
            'user' => '', 'from_address' => '', 'from_name' => '', 'timeout' => '15',
        ]);
        assertNull(self::fila(MailSettings::PASSWORD), 'clear_password borra la fila');
    }
}
