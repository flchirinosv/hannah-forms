<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * B2 — Cloudflare Turnstile (verificación bot-server, opt-in por formulario).
 *
 * El dueño del formulario activa `settings.anti_bot.enabled` en el builder; el
 * visitante resuelve el widget y el token llega en `cf-turnstile-response`
 * (el widget lo inyecta solo). El servidor lo verifica contra la API de
 * Cloudflare con `TURNSTILE_SECRET_KEY`.
 *
 * Contrato de robustez:
 * - Si el formulario lo pide pero la INSTANCIA no tiene claves configuradas
 *   (`TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY`), el formulario se bloquea en
 *   el fill con un aviso: nunca se entrega un formulario "protegido" sin
 *   proteger (pasar sin verificar sería desactivar la protección en silencio).
 * - `verify()` es un POST a la URL FIJA de Cloudflare (constante del código,
 *   no entrada del usuario, así que no hay SSRF aquí) y nunca lanza hacia el
 *   controller: un fallo de red equivale a "no verificado".
 * - El token es de un solo uso: un fallo de verificación se resuelve a un
 *   re-render del formulario, no a un grabar y listo.
 *
 * Testeable: Turnstile::setTransportForTesting() inyecta la llamada a siteverify
 * y Turnstile::setConfiguredForTesting() simula que la instancia tiene (o no)
 * las claves — Config no tiene setter.
 */
final class Turnstile
{
    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @var callable|null (string $body): array JSON de la respuesta */
    private static $transport = null;

    /** @var string|null Claves fijadas por tests; null = lee la config */
    private static $siteKey = null;
    private static $secretKey = null;

    public static function setTransportForTesting(?callable $fn): void
    {
        self::$transport = $fn;
    }

    /**
     * Fija las claves como siVINieran del .env (tests). null = leer de la
     * config (TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY), que es lo normal.
     */
    public static function setKeysForTesting(?string $siteKey, ?string $secret): void
    {
        self::$siteKey = $siteKey === null ? null : trim($siteKey);
        self::$secretKey = $secret === null ? null : trim($secret);
    }

    private static function siteKey(): string
    {
        return self::$siteKey ?? trim((string) Config::get('app.turnstile.site_key', ''));
    }

    private static function secretKey(): string
    {
        return self::$secretKey ?? trim((string) Config::get('app.turnstile.secret_key', ''));
    }

    /** ¿La instancia tiene claves para servir el widget? */
    public static function isConfigured(): bool
    {
        return self::siteKey() !== '' && self::secretKey() !== '';
    }

    /**
     * Site key PÚBLICA (va en el HTML del widget). Se lee por el mismo
     * accessor que isConfigured(): si el widget se pinta, la clave sale de
     * aquí, no de Config::get() directo, que en los tests con claves inyectadas
     * daría vacío.
     */
    public static function publicSiteKey(): string
    {
        return self::siteKey();
    }

    /**
     * Verifica el token de un visitante. Devuelve true SOLO si Cloudflare
     * responde con `success: true` (tupla idempotente para el CAPTCHA ya
     * resuelto). Un token vacío, una red caída o un body malformado = false.
     */
    public static function verify(string $token, string $remoteIp = ''): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        $secret = self::secretKey();
        if ($secret === '') {
            return false;
        }

        $params = [
            'secret'   => $secret,
            'response' => $token,
        ];
        if ($remoteIp !== '' && filter_var($remoteIp, FILTER_VALIDATE_IP) !== false) {
            $params['remoteip'] = $remoteIp;
        }
        $body = http_build_query($params);

        try {
            $data = self::siteverify($body);
        } catch (\Throwable $e) {
            return false;
        }

        return is_array($data) && !empty($data['success']);
    }

    /**
     * POST a siteverify. En pruebas, inyectable; en producción usa el contexto
     * de streams con TLS verificado. La URL es constante del código.
     */
    private static function siteverify(string $body): array
    {
        if (self::$transport !== null) {
            $data = (self::$transport)($body);
            return is_array($data) ? $data : [];
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body),
                'content'       => $body,
                'timeout'       => 5,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'SNI_enabled'       => true,
            ],
        ]);

        $raw = @file_get_contents(self::SITEVERIFY_URL, false, $ctx);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}