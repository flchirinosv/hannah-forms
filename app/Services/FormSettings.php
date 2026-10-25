<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\I18n;
use App\Core\Contrast;

final class FormSettings
{
    public const ACCESS_PUBLIC = 'public';
    public const ACCESS_EMAIL = 'email';
    public const ACCESS_ALLOWLIST = 'allowlist';
    public const ACCESS_ROSTER = 'roster';

    public const ACCESS_MODES = [self::ACCESS_PUBLIC, self::ACCESS_EMAIL, self::ACCESS_ALLOWLIST, self::ACCESS_ROSTER];

    public const ACCESS_IDENTIFIERS = ['cedula', 'correo', 'codigo'];

    public const RESTRICTED_MODES = [self::ACCESS_EMAIL, self::ACCESS_ALLOWLIST, self::ACCESS_ROSTER];

    public const DASHBOARD_BLOCKS = ['access','schedule','quota','notify','anti_bot','locale','review'];

    public const BUILDER_BLOCKS = ['theme','redirects'];

    public static function defaults(): array
    {
        return [
            'access' => [
                'mode' => self::ACCESS_PUBLIC,
                'identifier' => 'cedula',
                'emails' => [],
                'allow_public_link' => true,
                'require_email' => false,
            ],
            'schedule' => [
                'open_at' => '',
                'close_at' => '',
            ],
            'quota' => [
                'enabled' => false,
                'max' => 0,
            ],
            'notify' => [
                'enabled' => false,
                'emails' => [],
                'confirmation' => [
                    'enabled' => false,
                    'to_field' => '',
                ],
            ],
            'anti_bot' => [
                'enabled' => false,
                'turnstile' => [
                    'enabled' => false,
                ],
            ],
            'locale' => [
                'code' => '',
            ],
            'review' => [
                'required' => false,
            ],
            'theme' => [
                'primary' => null,
                'gradient_start' => null,
                'gradient_end' => null,
                'background' => null,
                'text' => null,
                'font' => 'inter',
            ],
            'redirects' => [
                'after_submit' => '',
            ],
        ];
    }

    public static function merge(array $current, array $patch, ?array $allowlist = null): array
    {
        $base = is_array($current) ? $current : [];
        $out = array_replace_recursive(self::defaults(), $base);
        if ($patch === []) {
            return $out;
        }

        if ($allowlist === null) {
            $allowlist = array_keys(self::defaults());
        }

        $allowed = array_flip($allowlist);
        foreach ($patch as $block => $value) {
            if (!array_key_exists($block, $allowed)) {
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            if (!isset($out[$block]) || !is_array($out[$block])) {
                $out[$block] = $value;
                continue;
            }
            $out[$block] = array_replace_recursive($out[$block], $value);
        }

        return $out;
    }

    public static function fromDashboardPost(array $post): array
    {
        $parche = [];

        if (array_key_exists('access_mode', $post) && is_string($post['access_mode'])) {
            $mode = trim($post['access_mode']);
            if (in_array($mode, self::ACCESS_MODES, true)) {
                $parche['access'] = $parche['access'] ?? [];
                $parche['access']['mode'] = $mode;
            }
        }
        if (array_key_exists('access_identifier', $post) && is_string($post['access_identifier'])) {
            $id = trim($post['access_identifier']);
            if (in_array($id, self::ACCESS_IDENTIFIERS, true)) {
                $parche['access'] = $parche['access'] ?? [];
                $parche['access']['identifier'] = $id;
            }
        }

        if (array_key_exists('open_at', $post) && is_string($post['open_at'])) {
            $v = trim($post['open_at']);
            if ($v !== '') {
                $v = str_replace('T', ' ', $v);
                $parche['schedule'] = $parche['schedule'] ?? [];
                $parche['schedule']['open_at'] = $v;
            }
        }
        if (array_key_exists('close_at', $post) && is_string($post['close_at'])) {
            $v = trim($post['close_at']);
            if ($v !== '') {
                $v = str_replace('T', ' ', $v);
                $parche['schedule'] = $parche['schedule'] ?? [];
                $parche['schedule']['close_at'] = $v;
            }
        }

        if (array_key_exists('notify_enabled', $post)) {
            $val = $post['notify_enabled'];
            $parche['notify'] = $parche['notify'] ?? [];
            $parche['notify']['enabled'] = ($val === '1' || $val === 1 || $val === true);
        }
        if (array_key_exists('notify_emails', $post) && is_string($post['notify_emails'])) {
            $raw = $post['notify_emails'];
            $emails = [];
            foreach (preg_split('/[,;\n\r]+/', $raw) ?: [] as $e) {
                $e = trim(strtolower($e));
                if ($e === '') {
                    continue;
                }
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $e;
                }
            }
            $emails = array_values(array_unique($emails));
            $parche['notify'] = $parche['notify'] ?? [];
            $parche['notify']['emails'] = $emails;
        }
        if (array_key_exists('confirm_enabled', $post)) {
            $val = $post['confirm_enabled'];
            $parche['notify'] = $parche['notify'] ?? [];
            $parche['notify']['confirmation'] = $parche['notify']['confirmation'] ?? [];
            $parche['notify']['confirmation']['enabled'] = ($val === '1' || $val === 1 || $val === true);
        }
        if (array_key_exists('confirm_to_field', $post) && is_string($post['confirm_to_field'])) {
            $parche['notify'] = $parche['notify'] ?? [];
            $parche['notify']['confirmation'] = $parche['notify']['confirmation'] ?? [];
            $parche['notify']['confirmation']['to_field'] = trim($post['confirm_to_field']);
        }

        if (array_key_exists('quota_enabled', $post)) {
            $val = $post['quota_enabled'];
            $parche['quota'] = $parche['quota'] ?? [];
            $parche['quota']['enabled'] = ($val === '1' || $val === 1 || $val === true);
        }
        if (array_key_exists('quota_max', $post) && is_string($post['quota_max'])) {
            $v = trim($post['quota_max']);
            if ($v !== '' && is_numeric($v)) {
                $parche['quota'] = $parche['quota'] ?? [];
                $parche['quota']['max'] = (int) $v;
            }
        }

        if (array_key_exists('locale', $post) && is_string($post['locale'])) {
            $v = trim($post['locale']);
            if ($v !== '') {
                $norm = \App\Core\I18n::normalizeLocale($v);
                if ($norm !== '') {
                    $parche['locale'] = $parche['locale'] ?? [];
                    $parche['locale']['code'] = $norm;
                }
            }
        }

        if (array_key_exists('antibot_enabled', $post)) {
            $val = $post['antibot_enabled'];
            $parche['anti_bot'] = $parche['anti_bot'] ?? [];
            $parche['anti_bot']['enabled'] = ($val === '1' || $val === 1 || $val === true);
        }
        if (array_key_exists('turnstile_enabled', $post)) {
            $val = $post['turnstile_enabled'];
            $parche['anti_bot'] = $parche['anti_bot'] ?? [];
            $parche['anti_bot']['turnstile'] = $parche['anti_bot']['turnstile'] ?? [];
            $parche['anti_bot']['turnstile']['enabled'] = ($val === '1' || $val === 1 || $val === true);
        }

        if (array_key_exists('review_required', $post)) {
            $val = $post['review_required'];
            $parche['review'] = $parche['review'] ?? [];
            $parche['review']['required'] = ($val === '1' || $val === 1 || $val === true);
        }

        return $parche;
    }

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
        if ($primary !== null && $primary !== '') {
            $add('--vf-primary', $primary);
            $add('--vf-primary-contrast', Contrast::inkFor($primary));
        }

        $start = Contrast::normalizeHex((string) ($settings['theme']['gradient_start'] ?? ''));
        $end = Contrast::normalizeHex((string) ($settings['theme']['gradient_end'] ?? ''));
        $hasGradient = ($start !== null && $start !== '' && $end !== null && $end !== '');
        if ($hasGradient) {
            $add('--vf-grad-start', $start);
            $add('--vf-grad-end', $end);
            $ink = Contrast::normalizeHex((string) ($settings['theme']['text'] ?? ''));
            if ($ink === null || $ink === '') {
                $ink = Contrast::inkFor($start);
            }
            $add('--vf-grad-text', $ink);
        }

        $bg = Contrast::normalizeHex((string) ($settings['theme']['background'] ?? ''));
        if ($bg !== null && $bg !== '') {
            $add('--vf-background', $bg);
        }

        $root .= '}';
        $css[] = $root;

        if ($hasGradient) {
            $css[] = 'body.fill-body{background:radial-gradient(1200px 600px at 50% 0, var(--vf-grad-start, #0f7bff), var(--vf-grad-end, #1a1f36)) fixed;background-size:cover;color:var(--vf-grad-text, #fff);}';
        } else {
            $css[] = 'body.fill-body{background:#f7f8fa;color:#1f2937;}';
        }
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
