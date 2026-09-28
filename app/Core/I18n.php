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
 *   1. settings.locale.code (por formulario, whitelist es|en)
 *   2. config `app.locale` (APP_LOCALE del .env)
 *   3. 'es' (fallback).
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
     */
    public static function resolve(array $settings = []): string
    {
        $code = strtolower(trim((string) ($settings['locale']['code'] ?? '')));
        if (in_array($code, self::supportedLocales(), true)) {
            return $code;
        }
        $appLocale = strtolower(trim((string) Config::get('app.locale', 'es')));
        return in_array($appLocale, self::supportedLocales(), true) ? $appLocale : 'es';
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