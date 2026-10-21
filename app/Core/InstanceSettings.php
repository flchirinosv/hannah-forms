<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Identidad operativa de la INSTANCIA editable desde el panel: URL pública,
 * zona horaria de presentación e idioma por defecto.
 *
 * Los tres eran, hasta ahora, variables del `.env` que exigían SSH para
 * cambiarlas (`APP_PUBLIC_URL`, `APP_TIMEZONE`, `APP_LOCALE`). Son justo las que
 * un cliente quiere ajustar al arrancar su instancia —«mi dominio», «mi hora»,
 * «mi idioma»—, así que siguen el mismo patrón que la marca (AG2) y el correo
 * (AG3): **la fila guardada gana; si no hay fila, manda el `.env`**. Nada se
 * congela, y una instalación que nunca toca esto se comporta exactamente como
 * antes.
 *
 * Precedencia de cada valor (de más específico a más general):
 *
 *  - **URL pública**: fila → `APP_PUBLIC_URL` → petición actual → `APP_URL`
 *    (la resolución vive en `PublicUrl`, que es quien pinta el enlace, el QR y
 *    el embed; aquí solo se LEE la fila).
 *  - **Zona horaria**: fila → `APP_TIMEZONE` → `America/Caracas` (la lee
 *    `Clock::displayZone()`).
 *  - **Idioma**: fila → `APP_LOCALE` → `es` (lo lee `I18n::resolve()`).
 *
 * El idioma es el DEFAULT de la instalación, no un candado: un formulario puede
 * seguir fijando el suyo (`settings.locale.code`) y el visitante sobrescribirlo
 * con `?lang=`; lo que cambia esta fila es el punto de partida.
 */
final class InstanceSettings
{
    /** URL pública fijada a mano (el «pin» de `PublicUrl`). */
    public const PUBLIC_URL = 'instance_public_url';

    /** Zona horaria de PRESENTACIÓN (identificador IANA). */
    public const TIMEZONE = 'instance_timezone';

    /** Idioma por defecto de la instancia (`es`/`en`). */
    public const LOCALE = 'instance_locale';

    /** Claves que gestiona esta clase. */
    public const KEYS = [self::PUBLIC_URL, self::TIMEZONE, self::LOCALE];

    /**
     * Vista completa para el panel: el valor GUARDADO (lo que se prellena en el
     * formulario; `''` = sin fila), el default del `.env` (lo que se enseña como
     * «Por defecto (…)») y el valor RESUELTO (para tests y para quien quiera el
     * efectivo sin volver a aplicar la precedencia).
     *
     * @return array{stored_public_url:string, stored_timezone:string, stored_locale:string, env_public_url:string, env_timezone:string, env_locale:string, public_url:string, timezone:string, locale:string}
     */
    public static function all(): array
    {
        return [
            'stored_public_url' => (string) (AppSettings::get(self::PUBLIC_URL) ?? ''),
            'stored_timezone'   => (string) (AppSettings::get(self::TIMEZONE) ?? ''),
            'stored_locale'     => (string) (AppSettings::get(self::LOCALE) ?? ''),
            'env_public_url'    => (string) Config::get('app.public_url', ''),
            'env_timezone'      => (string) Config::get('app.timezone', 'America/Caracas'),
            'env_locale'        => (string) Config::get('app.locale', 'es'),
            'public_url'        => self::publicUrl(),
            'timezone'          => self::timezone(),
            'locale'            => self::locale(),
        ];
    }

    /**
     * URL pública resuelta: la fila si está y es válida; si no, el `.env`
     * (`app.public_url`), que puede estar vacío (y entonces `PublicUrl` sigue
     * deduciendo de la petición). Un valor inválido en la fila se ignora: es
     * mejor deducir que servir un enlace roto.
     */
    public static function publicUrl(): string
    {
        $stored = AppSettings::get(self::PUBLIC_URL);
        if ($stored !== null && trim($stored) !== '' && self::isValidUrl($stored)) {
            return trim($stored);
        }

        return (string) Config::get('app.public_url', '');
    }

    /** Zona horaria resuelta, validada contra los identificadores IANA. */
    public static function timezone(): string
    {
        $stored = AppSettings::get(self::TIMEZONE);
        if ($stored !== null && Clock::isValidZone(trim($stored))) {
            return trim($stored);
        }

        return (string) Config::get('app.timezone', 'America/Caracas');
    }

    /** Idioma resuelto, validado contra los idiomas soportados. */
    public static function locale(): string
    {
        $stored = AppSettings::get(self::LOCALE);
        if ($stored !== null) {
            $code = strtolower(trim($stored));
            if (in_array($code, I18n::supportedLocales(), true)) {
                return $code;
            }
        }

        return (string) Config::get('app.locale', 'es');
    }

    /**
     * Guarda los tres ajustes. Devuelve `['ok' => bool, 'error' => ?string]`.
     *
     * Semántica, igual que en marca/correo:
     * - Texto vacío BORRA la fila (vuelve al `.env` / al default).
     * - Un valor inválido NO se guarda y devuelve el error (no borra la fila
     *   anterior: un guardado a medias sería peor que no guardar).
     *
     * @param array<string, mixed> $data
     * @return array{ok:bool, error:?string}
     */
    public static function save(array $data): array
    {
        $url      = trim((string) ($data['public_url'] ?? ''));
        $timezone = trim((string) ($data['timezone'] ?? ''));
        $locale   = strtolower(trim((string) ($data['locale'] ?? '')));

        if ($url !== '' && !self::isValidUrl($url)) {
            return ['ok' => false, 'error' => 'La URL pública no es válida: usa http:// o https:// y un host real.'];
        }
        if ($timezone !== '' && !Clock::isValidZone($timezone)) {
            return ['ok' => false, 'error' => 'La zona horaria no es un identificador válido (p. ej. America/Caracas).'];
        }
        if ($locale !== '' && !in_array($locale, I18n::supportedLocales(), true)) {
            return ['ok' => false, 'error' => 'El idioma tiene que ser uno de los soportados (es, en).'];
        }

        $writes = [
            self::PUBLIC_URL => $url === '' ? null : $url,
            self::TIMEZONE   => $timezone === '' ? null : $timezone,
            self::LOCALE     => $locale === '' ? null : $locale,
        ];

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

    /* ------------------------------------------------------------------ */

    /**
     * Valida una URL de base con la misma exigencia que `PublicUrl::cleanBase()`:
     * esquema http/https (o se le añade) y un host con forma de host. No se
     * guarda con barra final: `PublicUrl` la normaliza al salir, pero guardarla
     * limpia evita que la vista muestre una forma y el enlace use otra.
     */
    private static function isValidUrl(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '') {
            return true; // vacío = borrar (lo trata el llamador), no inválido
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $raw) !== 1) {
            $raw = 'http://' . ltrim($raw, '/');
        }

        $parts = parse_url($raw);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        // `host` no puede quedar vacío ni tener espacios; `parse_url` ya descarta
        // muchos casos raros, pero un `http://` a secas da host vacío.
        $host = (string) $parts['host'];
        if ($host === '' || preg_match('/\s/', $host) === 1) {
            return false;
        }

        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            return false;
        }

        return true;
    }
}
