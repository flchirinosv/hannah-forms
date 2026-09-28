<?php

declare(strict_types=1);

namespace App\Core;

/**
 * i18n reducido (P9): catálogos planos por idioma en app/lang/{es,en}.php.
 *
 * Ámbito (decisión del roadmap): lo que ve el público en el formulario
 * (fill, gracias, estados cerrado/lleno/borrador), los correos
 * (notificación/confirmación) y los mensajes de validación que se muestran
 * en el fill. El dashboard/builder/admin quedan en español (herramienta
 * interna) y NO pasan por aquí.
 *
 * Precedencia de idioma (I18n::resolve):
 *   1. `$requested` — override explícito del request: `?lang=es|en` al
 *      compartir el enlace (o el campo oculto `lang` del form en el POST).
 *   2. settings.locale.code (por formulario, whitelist es|en)
 *   3. Accept-Language del navegante (SOLO si el formulario no fija idioma
 *      explícito: la elección del dueño manda sobre el perfil del visitante).
 *   4. config `app.locale` (APP_LOCALE del .env)
 *   5. 'es' (fallback).
 *
 * Con la firma clásica `resolve($settings)` el comportamiento histórico se
 * conserva tal cual (settings > app > es), de modo que emails y middleware no
 * se ven afectados por la detección de navegante. `forRequest()` es el
 * acceso del fill público: suma el override de url/POST y el navegante.
 *
 * Al no haber Composer ni librerías de traducción, cada catálogo es un array
 * PHP plano key => mensaje, con placeholders {name} para interpolación.
 * Si falta una clave se devuelve la propia clave (fail-safe visibles).
 */
final class I18n
{
    /** @var array<string, array<string, string>> catálogos ya cargados */
    private static array $catalog = [];

    public static function supportedLocales(): array
    {
        return ['es', 'en'];
    }

    /**
     * Resuelve el idioma efectivo para un formulario (ver precedencia arriba).
     *
     * @param array<string, mixed> $settings settings normalizados (FormSettings)
     * @param string|null $requested override explícito del visitante (`?lang=`)
     * @param bool $browser si true, se consulta Accept-Language cuando el
     *                      formulario no fija idioma explícito
     */
    public static function resolve(array $settings = [], ?string $requested = null, bool $browser = false): string
    {
        if ($requested !== null && $requested !== '') {
            $code = strtolower(trim($requested));
            if (in_array($code, self::supportedLocales(), true)) {
                return $code;
            }
        }
        $code = strtolower(trim((string) ($settings['locale']['code'] ?? '')));
        if (in_array($code, self::supportedLocales(), true)) {
            return $code;
        }
        if ($browser) {
            $code = self::browserLocale();
            if ($code !== '') {
                return $code;
            }
        }
        $appLocale = strtolower(trim((string) Config::get('app.locale', 'es')));
        return in_array($appLocale, self::supportedLocales(), true) ? $appLocale : 'es';
    }

    /**
     * Idiomas para el fill público (Q6): resuelve con el override del request
     * (`?lang=` en GET, campo oculto `lang` en el POST) y la detección de
     * navegante. Única puerta del front; emails/middleware siguen en resolve().
     *
     * @param array<string, mixed> $settings
     */
    public static function forRequest(array $settings = []): string
    {
        $requested = (string) (Request::get('lang', '') ?: Request::post('lang', ''));
        return self::resolve($settings, $requested !== '' ? $requested : null, true);
    }

    /**
     * Primer idioma soportado del Accept-Language (p. ej. "en-US,en;q=0.9" → en).
     */
    private static function browserLocale(): string
    {
        $header = trim((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        if ($header === '') {
            return '';
        }
        $first = preg_split('/[,;]/', $header, 2);
        $code = substr(trim($first !== false ? $first[0] : $header), 0, 2);
        $code = strtolower($code);
        return in_array($code, self::supportedLocales(), true) ? $code : '';
    }

    /**
     * Traduce una clave con interpolación de placeholders ({name}).
     * Idiomas no soportados caen a 'es'.
     *
     * @param array<string, mixed> $params
     */
    public static function t(string $key, string $locale = 'es', array $params = []): string
    {
        $locale = in_array($locale, self::supportedLocales(), true) ? $locale : 'es';
        self::load($locale);

        $message = self::$catalog[$locale][$key] ?? $key;
        if ($params === []) {
            return $message;
        }
        foreach ($params as $name => $value) {
            $message = str_replace('{' . $name . '}', (string) $value, $message);
        }
        return $message;
    }

    /**
     * Devuelve todas las claves visibles del catálogo de un idioma (para
     * mandarlas al cliente: fill.js usa data-vf-i18n con el subconjunto
     * ui./val./js.).
     *
     * @return array<string, string>
     */
    public static function allFor(string $locale): array
    {
        $locale = in_array($locale, self::supportedLocales(), true) ? $locale : 'es';
        self::load($locale);
        return self::$catalog[$locale];
    }

    private static function load(string $locale): void
    {
        if (isset(self::$catalog[$locale])) {
            return;
        }
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $file = $config['paths']['app'] . '/lang/' . $locale . '.php';
        $cat = is_file($file) ? (include $file) : [];
        self::$catalog[$locale] = is_array($cat) ? $cat : [];
    }
}