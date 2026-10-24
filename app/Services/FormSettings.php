<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\I18n;

final class FormSettings
{
    public const ACCESS_PUBLIC = 'public';
    public const ACCESS_EMAIL = 'email';
    public const ACCESS_ALLOWLIST = 'allowlist';
    public const ACCESS_ROSTER = 'roster';

    public const ACCESS_MODES = [self::ACCESS_PUBLIC, self::ACCESS_EMAIL, self::ACCESS_ALLOWLIST, self::ACCESS_ROSTER];

    public const ACCESS_IDENTIFIERS = ['cedula', 'correo', 'codigo'];

    public const RESTRICTED_MODES = [self::ACCESS_EMAIL, self::ACCESS_ALLOWLIST, self::ACCESS_ROSTER];

    public static function normalize(array $settings): array
    {
        if (!isset($settings['access']) || !is_array($settings['access'])) {
            $settings['access'] = [];
        }

        $mode = (string) ($settings['access']['mode'] ?? self::ACCESS_PUBLIC);
        if (!in_array($mode, self::ACCESS_MODES, true)) {
            $mode = self::ACCESS_PUBLIC;
        }
        $settings['access']['mode'] = $mode;

        $identifier = (string) ($settings['access']['identifier'] ?? 'cedula');
        if (!in_array($identifier, self::ACCESS_IDENTIFIERS, true)) {
            $identifier = 'cedula';
        }
        $settings['access']['identifier'] = $identifier;

        if (!isset($settings['schedule']) || !is_array($settings['schedule'])) {
            $settings['schedule'] = [];
        }
        if (!isset($settings['theme']) || !is_array($settings['theme'])) {
            $settings['theme'] = [];
        }
        if (!isset($settings['anti_bot']) || !is_array($settings['anti_bot'])) {
            $settings['anti_bot'] = [];
        }
        if (!isset($settings['locale']) || !is_array($settings['locale'])) {
            $settings['locale'] = [];
        }
        if (!isset($settings['advance']) || !is_array($settings['advance'])) {
            $settings['advance'] = [];
        }
        if (!isset($settings['thanks']) || !is_array($settings['thanks'])) {
            $settings['thanks'] = [];
        }

        if (isset($settings['limit_per_ip'])) {
            $settings['limit_per_ip'] = max(0, (int) $settings['limit_per_ip']);
        }
        if (isset($settings['randomize_questions'])) {
            $settings['randomize_questions'] = !empty($settings['randomize_questions']);
        }
        if (isset($settings['show_progress'])) {
            $settings['show_progress'] = !empty($settings['show_progress']);
        }
        if (isset($settings['save_draft'])) {
            $settings['save_draft'] = !empty($settings['save_draft']);
        }

        $settings['anti_bot']['enabled'] = !empty($settings['anti_bot']['enabled'] ?? false);

        $settings['advance']['auto'] = !empty($settings['advance']['auto'] ?? false);

        if (isset($settings['thanks']['redirect_url'])) {
            $settings['thanks']['redirect_url'] = trim((string) $settings['thanks']['redirect_url']);
        }

        if (!isset($settings['locale']['code'])) {
            $settings['locale']['code'] = (string) Config::get('app.locale', 'es');
        }
        $settings['locale']['code'] = I18n::normalizeLocale((string) $settings['locale']['code']);

        $fonts = array_keys(self::fonts());
        if (isset($settings['theme']['font'])) {
            $font = (string) $settings['theme']['font'];
            if (!in_array($font, $fonts, true)) {
                unset($settings['theme']['font']);
            }
        }
        if (isset($settings['theme']['primary'])) {
            $settings['theme']['primary'] = Contrast::normalizeHex((string) $settings['theme']['primary']);
        }
        if (isset($settings['theme']['gradient_start'])) {
            $settings['theme']['gradient_start'] = Contrast::normalizeHex((string) $settings['theme']['gradient_start']);
        }
        if (isset($settings['theme']['gradient_end'])) {
            $settings['theme']['gradient_end'] = Contrast::normalizeHex((string) $settings['theme']['gradient_end']);
        }
        if (isset($settings['theme']['background'])) {
            $settings['theme']['background'] = Contrast::normalizeHex((string) $settings['theme']['background']);
        }
        if (isset($settings['theme']['text'])) {
            $settings['theme']['text'] = Contrast::normalizeHex((string) $settings['theme']['text']);
        }

        return $settings;
    }

    public static function fonts(): array
    {
        return [
            'inter' => ['name' => 'Inter', 'file' => 'Inter-Variable.woff2'],
            'jost' => ['name' => 'Jost', 'file' => 'Jost-Variable.woff2'],
            'lora' => ['name' => 'Lora', 'file' => 'Lora-Variable.woff2'],
            'roboto-slab' => ['name' => 'Roboto Slab', 'file' => 'RobotoSlab-Variable.woff2'],
        ];
    }

    public static function fontCss(): string
    {
        $css = [];
        foreach (self::fonts() as $key => $font) {
            $css[] = "@font-face{font-family:'" . $font['name'] . "';font-style:normal;font-weight:100 900;font-display:swap;src:url('/assets/fonts/" . $font['file'] . "') format('woff2');}";
        }
        return implode("\n", $css);
    }

    public static function themeCss(array $settings): string
    {
        $css = [];
        $root = ':root{';
        $add = function (string $name, ?string $value) use (&$root): void {
            if ($value === null || $value === '') {
                return;
            }
            $root .= $name . ':' . $value . ';';
        };

        $font = (string) ($settings['theme']['font'] ?? '');
        if (isset(self::fonts()[$font])) {
            $add('--vf-font', "'" . self::fonts()[$font]['name'] . "', system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif");
        }

        $primary = Contrast::normalizeHex((string) ($settings['theme']['primary'] ?? ''));
        if ($primary !== '') {
            $add('--vf-primary', $primary);
            $add('--vf-primary-contrast', Contrast::inkFor($primary));
        }

        $start = Contrast::normalizeHex((string) ($settings['theme']['gradient_start'] ?? ''));
        $end = Contrast::normalizeHex((string) ($settings['theme']['gradient_end'] ?? ''));
        if ($start !== '' && $end !== '') {
            $add('--vf-grad-start', $start);
            $add('--vf-grad-end', $end);
            $ink = Contrast::normalizeHex((string) ($settings['theme']['text'] ?? ''));
            if ($ink === '') {
                $ink = Contrast::inkFor($start);
            }
            $add('--vf-grad-text', $ink);
        }

        $bg = Contrast::normalizeHex((string) ($settings['theme']['background'] ?? ''));
        if ($bg !== '') {
            $add('--vf-background', $bg);
        }

        $root .= '}';
        $css[] = $root;

        $css[] = 'body.fill-body{background:radial-gradient(1200px 600px at 50% 0, var(--vf-grad-start, #0f7bff), var(--vf-grad-end, #1a1f36)) fixed;background-size:cover;color:var(--vf-grad-text, #fff);}';
        $css[] = '.fill-card{background:var(--fill-card-bg, #fff);color:var(--fill-card-text, #1f2937);}';

        return implode("\n", $css);
    }

    public static function isRestricted(array $settings): bool
    {
        $mode = (string) ($settings['access']['mode'] ?? self::ACCESS_PUBLIC);

        return in_array($mode, self::RESTRICTED_MODES, true);
    }

    public static function mode(array $settings): string
    {
        $mode = (string) ($settings['access']['mode'] ?? self::ACCESS_PUBLIC);

        return in_array($mode, self::ACCESS_MODES, true) ? $mode : self::ACCESS_PUBLIC;
    }

    public static function identifier(array $settings): string
    {
        $id = (string) ($settings['access']['identifier'] ?? 'cedula');

        return in_array($id, self::ACCESS_IDENTIFIERS, true) ? $id : 'cedula';
    }
}
