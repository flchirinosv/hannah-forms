<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Claves de Cloudflare Turnstile editables desde el panel (`/admin/settings`).
 *
 * El anti-bot es **opt-in por formulario** (`settings.anti_bot.enabled`, lo pone
 * el dueño en el builder), pero las claves son de la INSTANCIA: sin ellas, un
 * formulario que pide Turnstile **no se sirve** (`FillController` corta con
 * `fill/closed` y el POST con 403). Hasta ahora eran dos variables del `.env`
 * (`TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY`) que exigían SSH; esto las sube
 * al panel con el mismo patrón que marca (AG2), correo (AG3) e identidad (AG4):
 * **la fila guardada gana; si no hay fila, manda el `.env`**.
 *
 * La SITE key es pública (viaja en el HTML del widget). La SECRET key es una
 * credencial: se guarda **cifrada** con `App\Core\Secret` (AES-256-GCM, clave en
 * `APP_KEY`) y es de **solo escritura** —nunca se devuelve a la vista—. Sin
 * `APP_KEY`, `save()` de una secret falla explícitamente en vez de escribirla en
 * claro, y `secretStored()` dice `false` (la fila no se puede descifrar y el
 * fill caerá al `.env`).
 *
 * **Coherencia:** `Turnstile::isConfigured()` exige LAS DOS claves; guardar solo
 * una dejaría «medio configurado», que en Turnstile es peor que no configurado
 * (todos los formularios anti-bot dejarían de servirse). Por eso `save()`
 * comprueba el estado EFECTIVO (fila + `.env`) tras aplicar los cambios y, si
 * queda una sola de las dos, rechaza y no escribe nada.
 */
final class TurnstileSettings
{
    /** Site key pública (va en el HTML del widget). */
    public const SITE_KEY = 'instance_turnstile_site_key';

    /** Secret key de siteverify. CIFRADA en la fila. */
    public const SECRET_KEY = 'instance_turnstile_secret_key';

    /** Claves que gestiona esta clase. */
    public const KEYS = [self::SITE_KEY, self::SECRET_KEY];

    /**
     * Vista completa para el panel: lo GUARDADO (lo que se prellena; `''` = sin
     * fila), el default del `.env` y si hay una secret guardada y utilizable.
     *
     * La secret guardada NUNCA se devuelve (solo su booleano), y la del `.env`
     * tampoco: la vista solo necesita saber si hay algo con qué trabajar.
     *
     * @return array{stored_site_key:string, env_site_key:string, has_env_secret:bool, secret_stored:bool, configured:bool}
     */
    public static function all(): array
    {
        $site = self::siteKey();
        $secret = self::secretKey();

        return [
            'stored_site_key' => (string) (AppSettings::get(self::SITE_KEY) ?? ''),
            'env_site_key'    => trim((string) Config::get('app.turnstile.site_key', '')),
            'has_env_secret'  => trim((string) Config::get('app.turnstile.secret_key', '')) !== '',
            'secret_stored'   => self::secretStored(),
            'configured'      => $site !== '' && $secret !== '',
        ];
    }

    /**
     * Site key resuelta: la fila si está; si no, el `.env`. Nunca la secret.
     */
    public static function siteKey(): string
    {
        $stored = AppSettings::get(self::SITE_KEY);
        if ($stored !== null && trim($stored) !== '') {
            return trim($stored);
        }

        return trim((string) Config::get('app.turnstile.site_key', ''));
    }

    /**
     * Secret key resuelta: la fila descifrada si se puede leer; si no, el `.env`.
     * Un `APP_KEY` cambiado NO deja la instancia sin anti-bot mientras el `.env`
     * siga teniendo la secret vieja.
     */
    public static function secretKey(): string
    {
        $stored = AppSettings::get(self::SECRET_KEY);
        if ($stored !== null && $stored !== '') {
            $plain = Secret::decrypt($stored);
            if ($plain !== null) {
                return trim($plain);
            }
        }

        return trim((string) Config::get('app.turnstile.secret_key', ''));
    }

    /**
     * ¿Hay una secret guardada y utilizable? `true` = hay fila y se descifra (hay
     * `APP_KEY` correcta). Decide la pista «(guardada)»; NUNCA se devuelve el
     * valor.
     */
    public static function secretStored(): bool
    {
        $stored = AppSettings::get(self::SECRET_KEY);
        if ($stored === null || $stored === '') {
            return false;
        }

        return Secret::canDecrypt($stored);
    }

    /**
     * Guarda las claves. Devuelve `['ok' => bool, 'error' => ?string]`.
     *
     * Semántica:
     * - Site key: texto vacío BORRA la fila (vuelve al `.env`).
     * - Secret: campo de solo escritura. `null` = no la he tocado (se conserva);
     *   `''` con `clear_secret` = borra la fila; cualquier otro valor = se cifra
     *   y se guarda. Vacío sin `clear` = no la he tocado.
     *
     * Tras calcular el estado efectivo (fila nueva + `.env`), si queda **una sola**
     * de las dos claves se rechaza sin escribir nada: medio configurado dejaría
     * todos los formularios anti-bot sin servir.
     *
     * @param array<string, mixed> $data
     * @return array{ok:bool, error:?string}
     */
    public static function save(array $data): array
    {
        $siteKey = trim((string) ($data['site_key'] ?? ''));
        $clear = !empty($data['clear_secret']);
        $newSecret = $data['secret'] ?? null;

        // La secret resultante (para la comprobación de coherencia): lo que
        // quedará tras aplicar este guardado.
        $secretAfter = self::secretKey();
        $siteAfter = $siteKey !== '' ? $siteKey : trim((string) Config::get('app.turnstile.site_key', ''));

        if ($clear) {
            $secretAfter = trim((string) Config::get('app.turnstile.secret_key', ''));
        } elseif (is_string($newSecret) && $newSecret !== '') {
            if (!Secret::isAvailable()) {
                return [
                    'ok' => false,
                    'error' => 'No hay APP_KEY: genera una con `php scripts/generate-key.php --write` para guardar la secret key cifrada.',
                ];
            }
            $secretAfter = trim($newSecret);
        }

        if (($siteAfter === '') !== ($secretAfter === '')) {
            return [
                'ok' => false,
                'error' => 'Turnstile necesita la site key Y la secret key a la vez: con una sola, los formularios con anti-bot dejarían de servirse.',
            ];
        }

        $writes = [
            self::SITE_KEY => $siteKey === '' ? null : $siteKey,
        ];

        if ($clear) {
            $writes[self::SECRET_KEY] = null; // borrar
        } elseif (is_string($newSecret) && $newSecret !== '') {
            $cifrado = Secret::encrypt($newSecret);
            if ($cifrado === null) {
                return ['ok' => false, 'error' => 'No se pudo cifrar la secret key.'];
            }
            $writes[self::SECRET_KEY] = $cifrado;
        }
        // Si no viene secret y no se pide borrar: no se toca la fila.

        foreach ($writes as $key => $value) {
            if (!AppSettings::set($key, $value)) {
                return ['ok' => false, 'error' => 'No se pudo guardar la configuración (¿migraciones?).'];
            }
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Fija las filas para un test (sin tocar la BD). Seam de `AppSettings`.
     */
    public static function setForTesting(?array $values): void
    {
        AppSettings::setForTesting($values ?? []);
    }

    public static function forgetForTesting(): void
    {
        AppSettings::forgetForTesting();
    }
}
