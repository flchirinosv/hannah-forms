<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppSettings;
use App\Core\Config;
use App\Core\Database;
use App\Core\WebhookSettings;
use App\Services\Webhook;

/**
 * Webhook red local editable desde el panel (AG10).
 */
class WebhookSettingsTest
{
    /** @var array<string, ?string> */
    private array $previas = [];

    public function setUp(): void
    {
        AppSettings::forgetForTesting();
        Config::forgetForTesting();

        try {
            Database::getInstance(Config::db())->fetch('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            throw new \RuntimeException('SKIP: base de datos no disponible');
        }

        foreach (WebhookSettings::KEYS as $key) {
            $this->previas[$key] = AppSettings::get($key);
        }
        self::borrarFilas();
    }

    public function tearDown(): void
    {
        AppSettings::forgetForTesting();
        Config::forgetForTesting();
        Webhook::setAllowPrivate(null);

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
            'DELETE FROM app_settings WHERE key_name = :a',
            ['a' => WebhookSettings::ALLOW_PRIVATE_HOSTS]
        );
    }

    private static function fila(string $key): ?array
    {
        return Database::getInstance(Config::db())->fetch(
            'SELECT value FROM app_settings WHERE key_name = :k',
            ['k' => $key]
        );
    }

    public function testSinFilasSalenDelEnv(): void
    {
        Config::setForTesting('webhook', ['allow_private_hosts' => true]);

        assertTrue(WebhookSettings::allowPrivateHosts());
    }

    public function testLaFilaGanaAlEnv(): void
    {
        Config::setForTesting('webhook', ['allow_private_hosts' => true]);
        WebhookSettings::setForTesting([WebhookSettings::ALLOW_PRIVATE_HOSTS => '0']);

        assertFalse(WebhookSettings::allowPrivateHosts());
    }

    public function testWebhookLeeDesdeWebhookSettings(): void
    {
        Webhook::setAllowPrivate(null);
        WebhookSettings::setForTesting([WebhookSettings::ALLOW_PRIVATE_HOSTS => '1']);

        assertTrue(Webhook::serverAllowsPrivate());
    }

    public function testSaveGuardaYDevuelveBooleano(): void
    {
        $r = WebhookSettings::save(['allow_private_hosts' => true]);
        assertTrue($r['ok']);
        assertSame('1', (string) self::fila(WebhookSettings::ALLOW_PRIVATE_HOSTS)['value']);

        WebhookSettings::save(['allow_private_hosts' => false]);
        assertSame('0', (string) self::fila(WebhookSettings::ALLOW_PRIVATE_HOSTS)['value']);
    }
}
