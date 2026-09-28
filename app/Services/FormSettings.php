<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Configuración por formulario (`forms.settings`, JSON).
 *
 * Fuente única para: programación (schedule), cupo (quota), notificaciones
 * email (notify), webhook, mensaje de gracias (thanks), quiz, randomización,
 * embed, honeypot, tema (theme) e idioma (locale). Todos los consumidores
 * (FillController, Notifier, webhook, embed…) pasan por aquí para leer el
 * mismo esquema con defaults y sin claves desconocidas.
 *
 * normalize(): limpia/coeerciona contra un whitelist y aplica defaults.
 * validate():   devuelve el primer error de entrada como string (o null).
 */
final class FormSettings
{
    public const LOCALES = ['es', 'en'];

    public static function defaults(): array
    {
        return [
            'theme'     => ['primary' => null, 'background' => null, 'font' => null],
            'schedule'  => ['open_at' => null, 'close_at' => null],
            'quota'     => ['enabled' => false, 'max' => 0],
            'notify'    => [
                'enabled'      => false,
                'emails'       => [],
                'confirmation' => [
                    'enabled'  => false,
                    'subject'  => '',
                    'message'  => '',
                    'to_field' => null,
                ],
            ],
            'webhook'    => ['url' => '', 'allow_private_hosts' => false, 'kind' => 'generic', 'secret' => ''],
            'thanks'     => ['message' => '', 'redirect_url' => ''],
            'quiz'       => ['enabled' => false],
            'randomize'  => ['questions' => false],
            'embed'      => ['enabled' => false],
            'honeypot'   => ['enabled' => true],
            'anti_bot'   => ['enabled' => false],
            'locale'     => ['code' => 'es'],
        ];
    }

    /**
     * Normaliza un arreglo crudo (JSON de BD o del builder) contra el esquema.
     */
    public static function normalize(array $raw): array
    {
        $out = self::defaults();

        if (isset($raw['theme']) && is_array($raw['theme'])) {
            foreach (['primary', 'background', 'font'] as $k) {
                if (array_key_exists($k, $raw['theme'])) {
                    $v = $raw['theme'][$k];
                    $out['theme'][$k] = is_string($v) ? self::short(trim($v), 40) : null;
                }
            }
            // Una familia tipográfica no es texto libre: va directa a un
            // `font-family` de un <style> que el visitante carga en el fill
            // público. Sin patrón, `;`/`}`/`url(` permiten inyectar
            // declaraciones CSS nuevas (y con ellas, beacons y fugas por CSS).
            // Se descarta aquí, al normalizar: es la puerta por la que pasa
            // todo lo que llega del builder o de un POST de settings.
            if ($out['theme']['font'] !== null && !self::isValidFontFamily((string) $out['theme']['font'])) {
                $out['theme']['font'] = null;
            }
        }

        if (isset($raw['schedule']) && is_array($raw['schedule'])) {
            foreach (['open_at', 'close_at'] as $k) {
                if (array_key_exists($k, $raw['schedule'])) {
                    $v = $raw['schedule'][$k];
                    $out['schedule'][$k] = is_string($v) ? self::short(trim($v), 40) : null;
                }
            }
        }

        if (isset($raw['quota']) && is_array($raw['quota'])) {
            $out['quota']['enabled'] = (bool) ($raw['quota']['enabled'] ?? false);
            $out['quota']['max'] = max(0, min(1000000, (int) ($raw['quota']['max'] ?? 0)));
        }

        if (isset($raw['notify']) && is_array($raw['notify'])) {
            $n = $raw['notify'];
            $out['notify']['enabled'] = (bool) ($n['enabled'] ?? false);
            if (isset($n['emails']) && is_array($n['emails'])) {
                $emails = [];
                foreach ($n['emails'] as $e) {
                    if (!is_string($e)) {
                        continue;
                    }
                    $e = trim($e);
                    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && count($emails) < 10) {
                        $emails[] = $e;
                    }
                }
                $out['notify']['emails'] = array_values(array_unique($emails));
            }
            if (isset($n['confirmation']) && is_array($n['confirmation'])) {
                $c = $n['confirmation'];
                $out['notify']['confirmation']['enabled'] = (bool) ($c['enabled'] ?? false);
                $out['notify']['confirmation']['subject'] = is_string($c['subject'] ?? null)
                    ? self::short(trim($c['subject']), 190) : '';
                $out['notify']['confirmation']['message'] = is_string($c['message'] ?? null)
                    ? self::short(trim($c['message']), 2000) : '';
                $out['notify']['confirmation']['to_field'] = is_string($c['to_field'] ?? null)
                    ? self::short(trim($c['to_field']), 190) : null;
            }
        }

        if (isset($raw['webhook']) && is_array($raw['webhook'])) {
            $w = $raw['webhook']['url'] ?? '';
            $out['webhook']['url'] = is_string($w) ? self::short(trim($w), 500) : '';
            // SSRF: el destino se valida y se bloquean las IP privadas/reservadas
            // (loopback, RFC1918, 169.254.169.254 de la metadata…). Esta bandera
            // es el opt-in explícito para apuntar a un servicio de la red local
            // propia. OJO: la clave tiene que estar aquí o normalize() la
            // borraría y el opt-in nunca llegaría a Webhook.
            $out['webhook']['allow_private_hosts'] = !empty($raw['webhook']['allow_private_hosts']);
            // B1 — tipo de destino: 'generic' (payload JSON crudo) o 'slack'
            // (mensaje de la API de incoming-webhooks de Slack).
            $kind = $raw['webhook']['kind'] ?? 'generic';
            $out['webhook']['kind'] = in_array($kind, ['generic', 'slack'], true) ? $kind : 'generic';
            // B1 — secreto opcional: si se fija, el POST viaja firmado (HMAC-SHA256
            // del body) en `X-VanillaForms-Signature` para que el receptor pueda
            // verificar que la petición es de esta instancia.
            $secret = $raw['webhook']['secret'] ?? '';
            $out['webhook']['secret'] = is_string($secret) ? self::short(trim($secret), 190) : '';
        }

        if (isset($raw['thanks']) && is_array($raw['thanks'])) {
            $t = $raw['thanks'];
            $out['thanks']['message'] = is_string($t['message'] ?? null)
                ? self::short(trim($t['message']), 2000) : '';
            $out['thanks']['redirect_url'] = is_string($t['redirect_url'] ?? null)
                ? self::short(trim($t['redirect_url']), 500) : '';
        }

        foreach (['quiz', 'randomize', 'embed', 'honeypot', 'anti_bot'] as $k) {
            if (isset($raw[$k]) && is_array($raw[$k])) {
                $out[$k]['enabled'] = (bool) ($raw[$k]['enabled'] ?? false);
            }
        }
        if (isset($raw['randomize']) && is_array($raw['randomize'])) {
            $out['randomize']['questions'] = (bool) ($raw['randomize']['questions'] ?? false);
        }

        if (isset($raw['locale']) && is_array($raw['locale']) && isset($raw['locale']['code'])) {
            $code = (string) $raw['locale']['code'];
            $out['locale']['code'] = in_array($code, self::LOCALES, true) ? $code : 'es';
        }

        return $out;
    }

    /**
     * Valida la entrada (antigua al normalize) y devuelve un mensaje de error
     * o null si todo es válido. Se usa en el builder para responder 422.
     */
    public static function validate(array $raw): ?string
    {
        $n = self::normalize($raw);

        foreach (['open_at', 'close_at'] as $k) {
            $v = $n['schedule'][$k];
            if ($v !== null && $v !== '' && preg_match(
                '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/',
                $v
            ) !== 1) {
                return 'La fecha programada "' . $k . '" no tiene un formato válido (YYYY-MM-DD o con hora).';
            }
        }
        if ($n['schedule']['open_at'] !== null && $n['schedule']['close_at'] !== null
            && $n['schedule']['open_at'] !== '' && $n['schedule']['close_at'] !== ''
            && strtotime((string) $n['schedule']['open_at']) > strtotime((string) $n['schedule']['close_at'])) {
            return 'La fecha de apertura no puede ser posterior a la de cierre.';
        }

        if ($n['quota']['enabled'] && $n['quota']['max'] < 1) {
            return 'El cupo máximo debe ser un número mayor que cero.';
        }

        if ($n['notify']['enabled'] && $n['notify']['emails'] === []) {
            return 'Activa al menos un correo para notificar.';
        }

        foreach (['url'] as $k) {
            $u = $n['webhook'][$k];
            if ($u !== '' && self::invalidUrl($u)) {
                return 'La URL del webhook no es válida (debe ser http/https).';
            }
        }
        if (!in_array($n['webhook']['kind'], ['generic', 'slack'], true)) {
            return 'El tipo de webhook debe ser "generic" o "slack".';
        }

        $r = $n['thanks']['redirect_url'];
        if ($r !== '' && self::invalidUrl($r)) {
            return 'La URL de redirección no es válida (debe ser http/https).';
        }

        foreach (['primary', 'background'] as $k) {
            $c = $n['theme'][$k];
            if ($c !== null && $c !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) !== 1) {
                return 'El color de tema "' . $k . '" no es un hex válido (#RRGGBB o #RRGGBBAA).';
            }
        }

        // Se mira el valor CRUDO y no el normalizado: `normalize()` ya lo dejó en
        // null cuando no cumple el patrón, así que comprobar `$n` nunca
        // detectaría nada y quien escribe la tipografía se quedaría sin aviso de
        // que su valor se descartó en silencio. Se aplica el mismo recorte de 40
        // para que lo que se valida sea exactamente lo que se guarda.
        $rawFont = $raw['theme']['font'] ?? null;
        if (is_string($rawFont)) {
            $font = self::short(trim($rawFont), 40);
            if ($font !== '' && !self::isValidFontFamily($font)) {
                return 'La tipografía solo admite letras, números, espacios y los signos - _ . , (una o varias familias separadas por comas).';
            }
        }

        return null;
    }

    /**
     * ¿Es una lista de familias tipográficas legítima?
     *
     * Lista blanca, no solo de blacklist: lo que se acepta es lo que se puede
     * escribir como `font-family` sin cambiar de contexto. Se permiten coma
     * (familias alternativas), espacio, punto, guion y guion bajo, que es lo que
     * cubre desde `Inter` hasta `Source Sans 3, sans-serif`. Quedan fuera
     * comillas, paréntesis, punto y coma, llaves, barra, barra invertida,
     * dos puntos, coma... y por tanto `url(`, `@import` y el cierre de la
     * declaración con `;`.
     *
     * OJO: el mismo filtro se aplica en `themeCss()` al emitir, porque puede
     * haber filas ya guardadas en `forms.settings` de antes de este arreglo:
     * filtrar solo al guardar no las limpia.
     */
    public static function isValidFontFamily(string $font): bool
    {
        // 120 = 40 del recorte de normalize() × 3 familias.
        return preg_match("/^[A-Za-z0-9][A-Za-z0-9 _.,-]{0,119}$/", $font) === 1;
    }

    /**
     * Aplica un tema CSS por formulario (variables) si está definido.
     *
     * El filtro de `font` se repite aquí a propósito, y no por redundancia:
     * esta función lee de `forms.settings`, así que puede recibir una fila
     * guardada antes de que existiera el patrón, y un `<style>` que escapa
     * solo a posteriori sigue siendo una inyección de CSS. Lo que sale de aquí
     * está en el fill público de cualquiera, sin sesión.
     *
     * @param array<string, mixed> $settings settings normalizados
     * @return string CSS a incluir en <style> de la página pública
     */
    public static function themeCss(array $settings): string
    {
        $theme = $settings['theme'] ?? [];
        $vars = [];
        if (isset($theme['primary']) && $theme['primary'] !== '') {
            $vars[] = '--vf-primary:' . $theme['primary'];
        }
        if (isset($theme['background']) && $theme['background'] !== '') {
            $vars[] = '--vf-background:' . $theme['background'];
        }
        if (isset($theme['font']) && $theme['font'] !== '' && self::isValidFontFamily((string) $theme['font'])) {
            // Sin comillas: el patrón no las admite, así que no hacen falta y
            // además su htmlspecialchars previo las convertía en &quot; (que
            // dentro de un <style> no se decodifica, era un nombre de fuente
            // roto). Una familia con espacios es CSS válido sin comillas.
            $vars[] = '--vf-font:' . $theme['font'];
        }
        if ($vars === []) {
            return '';
        }
        return ':root{' . implode(';', array_map('htmlspecialchars', $vars)) . ';}';
    }

    private static function short(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }

    private static function invalidUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return true;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return !in_array($scheme, ['http', 'https'], true);
    }
}