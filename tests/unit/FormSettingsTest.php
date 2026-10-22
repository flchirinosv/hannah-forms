<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Database;
use App\Models\Form;
use App\Models\User;
use App\Services\FormSettings;

/**
 * Tests de FormSettings (whitelist/coerción/validación) y del round-trip
 * en BD vía Form::settings()/Form::saveSettings(). Usa BD real y se auto-limpia.
 */
class FormSettingsTest
{
    /** @var int|null */
    private $formId = null;

    /** @var int|null */
    private $userId = null;

    public function setUp(): void
    {
        self::cleanupResiduals();

        $this->userId = User::create('Form Settings Test', 'settings-' . bin2hex(random_bytes(4)) . '@test.local', 'password', 2);
        $this->formId = Form::create((int) $this->userId, 'Mi formulario de prueba');
    }

    public function testDefaultsCoincidenConSchema(): void
    {
        $d = FormSettings::defaults();
        assertArrayHasKey('theme', $d);
        assertArrayHasKey('schedule', $d);
        assertArrayHasKey('quota', $d);
        assertArrayHasKey('notify', $d);
        assertArrayHasKey('webhook', $d);
        assertArrayHasKey('thanks', $d);
        assertArrayHasKey('quiz', $d);
        assertArrayHasKey('randomize', $d);
        assertArrayHasKey('embed', $d);
        assertArrayHasKey('honeypot', $d);
        // La revisión ya NO es un bloque del formulario: es la política de la
        // instancia (`app_settings`), así que no tiene clave en `defaults()`.
        assertFalse(array_key_exists('review', $d), 'la revisión dejó de ser un ajuste por formulario');
        assertArrayHasKey('locale', $d);
        assertSame(['es', 'en'], FormSettings::LOCALES);
    }

    public function testNormalizeAplanaYDescartaClavesExtras(): void
    {
        $raw = [
            'theme' => ['primary' => '#ff0000', 'background' => '  #fff  ', 'font' => 'Lora', 'extra' => 1],
            'quota' => ['enabled' => true, 'max' => '25'],
            'claveDesconocida' => 'x',
        ];
        $n = FormSettings::normalize($raw);
        assertSame('#ff0000', $n['theme']['primary']);
        assertSame('#fff', $n['theme']['background']);
        assertSame('Lora', $n['theme']['font']);
        assertTrue($n['quota']['enabled']);
        assertSame(25, $n['quota']['max']);
        self::assertArrayNotHasKeyCompat('claveDesconocida', $n);
        self::assertArrayNotHasKeyCompat('extra', $n['theme']);
    }

    public function testNormalizeCoeercionaTiposPeligrosos(): void
    {
        $n = FormSettings::normalize([
            'quota' => ['enabled' => 1, 'max' => -50],
            'randomize' => ['questions' => 'no'],
            'honeypot' => ['enabled' => 'yes'],
            'notify' => ['emails' => 'a@x.com,b@y.com'],
            'locale' => ['code' => 'fr'],
            'theme' => ['primary' => 42],
        ]);
        assertTrue($n['quota']['enabled']);
        assertSame(0, $n['quota']['max'], 'max negativo se acota a 0');
        assertTrue($n['randomize']['questions'], 'string "no" es truthy en bool()');
        assertTrue($n['honeypot']['enabled']);
        assertSame([], $n['notify']['emails'], 'emails en string no aplican (solo array)');
        assertSame('es', $n['locale']['code'], 'locale no soportado cae a es');
        assertNull($n['theme']['primary'], 'color no-string se descarta');
    }

    public function testNormalizeFiltraEmailsInvalidos(): void
    {
        $n = FormSettings::normalize([
            'notify' => [
                'emails' => ['  a@x.com  ', 'no-es-email', 'b@y.com', 'a@x.com'],
            ],
        ]);
        assertSame(['a@x.com', 'b@y.com'], $n['notify']['emails'], 'dedupe + trim + filtro inválidos');
    }

    public function testValidateRechazaConfiguracionesInvalidas(): void
    {
        $cases = [
            'fecha inválida' => ['schedule' => ['open_at' => 'ayer']],
            'cupo sin max' => ['quota' => ['enabled' => true, 'max' => 0]],
            'notificar sin emails' => ['notify' => ['enabled' => true, 'emails' => []]],
            'webhook inválido' => ['webhook' => ['url' => 'ftp://x.com']],
            'redirect inválido' => ['thanks' => ['redirect_url' => 'javascript:alert(1)']],
            'color inválido' => ['theme' => ['primary' => 'rojo']],
        ];
        foreach ($cases as $name => $raw) {
            $err = FormSettings::validate($raw);
            assertNotNull($err, 'caso "' . $name . '" debería fallar');
            assertTrue(is_string($err), 'error debe ser string legible');
        }
    }

    public function testValidateAceptaConfigValida(): void
    {
        $err = FormSettings::validate([
            'theme' => ['primary' => '#0d6efd', 'background' => '#f6f6f6'],
            'schedule' => ['open_at' => '2026-10-01 09:00', 'close_at' => '2026-10-31 18:00'],
            'quota' => ['enabled' => true, 'max' => 100],
            'notify' => ['enabled' => true, 'emails' => ['admin@example.com']],
            'webhook' => ['url' => 'https://hook.example.com/f'],
            'thanks' => ['redirect_url' => 'https://example.com/gracias'],
        ]);
        assertNull($err);
    }

    public function testValidateRechazaAperturaPosteriorAlCierre(): void
    {
        $err = FormSettings::validate([
            'schedule' => ['open_at' => '2026-11-01 09:00', 'close_at' => '2026-10-01 09:00'],
        ]);
        assertNotNull($err);
    }

    public function testThemeCssGeneraVariables(): void
    {
        $settings = FormSettings::normalize([
            'theme' => ['primary' => '#7a3ff2', 'background' => '', 'font' => 'Lora'],
        ]);
        $css = FormSettings::themeCss($settings);
        self::assertStringContains('#7a3ff2', $css);
        self::assertStringContains('--vf-primary', $css);
        assertFalse(str_contains($css, '--vf-background'), 'sin color de fondo no se emite variable');
        self::assertStringContains('--vf-font', $css);

        assertSame('', FormSettings::themeCss(FormSettings::defaults()), 'sin tema no se emite CSS');
    }

    public function testThemeCssEmiteDegradadoYTintaAutomatica(): void
    {
        $settings = FormSettings::normalize([
            'theme' => ['gradient_start' => '#ff0000', 'gradient_end' => '#00ff00'],
        ]);
        $css = FormSettings::themeCss($settings);
        self::assertStringContains('--vf-grad-start:#ff0000', $css);
        self::assertStringContains('--vf-grad-end:#00ff00', $css);
        // Con un rojo de arranque (oscuro), la tinta automática es blanca.
        self::assertStringContains('--vf-on-grad:#ffffff', $css);
    }

    public function testLaTintaAutomaticaSeCalculaPorLuminancia(): void
    {
        // Un degradado que arranca en amarillo claro NO puede pedir blanco: el
        // texto quedaría ilegible, que es el fallo que motivó el cálculo.
        $claro = FormSettings::themeCss(FormSettings::normalize([
            'theme' => ['gradient_start' => '#fff59d'],
        ]));
        self::assertStringContains('--vf-on-grad:#1e293b', $claro);

        $oscuro = FormSettings::themeCss(FormSettings::normalize([
            'theme' => ['gradient_start' => '#1e293b'],
        ]));
        self::assertStringContains('--vf-on-grad:#ffffff', $oscuro);
    }

    public function testUnColorDeTextoExplicitoGanaSobreElAutomatico(): void
    {
        $css = FormSettings::themeCss(FormSettings::normalize([
            'theme' => ['gradient_start' => '#000000', 'text' => '#ffcc00'],
        ]));
        self::assertStringContains('--vf-on-grad:#ffcc00', $css);
        assertFalse(str_contains($css, '--vf-on-grad:#ffffff'), 'el color elegido manda, no el calculado');
    }

    public function testElDegradadoNoSeEmiteSiSusExtremosEstanVacios(): void
    {
        $css = FormSettings::themeCss(FormSettings::normalize([
            'theme' => ['primary' => '#0d6efd', 'gradient_start' => '', 'gradient_end' => ''],
        ]));
        assertFalse(str_contains($css, '--vf-grad-start'), 'sin extremo no se emite su variable');
        assertFalse(str_contains($css, '--vf-grad-end'), 'ni la del fin');
    }

    public function testValidateRechazaUnColorDeDegradadoInvalido(): void
    {
        $err = FormSettings::validate(FormSettings::normalize([
            'theme' => ['gradient_start' => 'azul'],
        ]));
        assertNotNull($err, 'un degradado no-hex se rechaza');
    }

    public function testSaveSettingsRoundTrip(): void
    {
        Form::saveSettings((int) $this->formId, [
            'theme' => ['primary' => '#0d6efd'],
            'quota' => ['enabled' => true, 'max' => 50],
            'notify' => ['emails' => ['a@x.com']],
            'localler' => 'dato-extra',
        ]);
        $s = Form::settings((int) $this->formId);
        assertSame('#0d6efd', $s['theme']['primary']);
        assertTrue($s['quota']['enabled']);
        assertSame(50, $s['quota']['max']);
        assertSame(['a@x.com'], $s['notify']['emails']);
        self::assertArrayNotHasKeyCompat('localler', $s);

        $row = Database::getInstance(Config::db())->fetch(
            'SELECT settings FROM forms WHERE id = :id',
            ['id' => $this->formId]
        );
        assertTrue(is_array($row));
        $json = json_decode((string) $row['settings'], true);
        assertTrue(is_array($json), 'persistencia debe ser JSON');
        assertSame('#0d6efd', $json['theme']['primary']);
    }

    public function testSettingsDevuelveDefaultsCuandoNoHayJson(): void
    {
        $s = Form::settings((int) $this->formId);
        assertSame(FormSettings::defaults(), $s, 'sin settings persistidos debe dar defaults');
    }

    public function tearDown(): void
    {
        if ($this->userId !== null) {
            Database::getInstance(Config::db())->execute('DELETE FROM users WHERE id = :id', ['id' => $this->userId]);
        }
        $this->userId = null;
        $this->formId = null;
    }

    private static function assertArrayNotHasKeyCompat($key, array $array, string $message = ''): void
    {
        assertFalse(
            array_key_exists($key, $array),
            $message !== '' ? $message : 'la clave "' . (string) $key . '" no debería existir'
        );
    }

    private static function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        assertTrue(str_contains($haystack, $needle), $message !== '' ? $message : 'esperaba "' . $needle . '" dentro del string');
    }

    private static function cleanupResiduals(): void
    {
        $db = Database::getInstance(Config::db());
        foreach ($db->fetchAll("SELECT id FROM users WHERE email LIKE '%@test.local'") as $u) {
            $db->execute('DELETE FROM users WHERE id = :id', ['id' => (int) $u['id']]);
        }
        $db->execute("DELETE FROM forms WHERE title LIKE 'Mi formulario de prueba%'");
    }
}