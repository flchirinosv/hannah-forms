<?php

declare(strict_types=1);

namespace App\Core;

class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'main'): void
    {
        $templatePath = self::resolve($template);

        if (!is_readable($templatePath)) {
            http_response_code(500);
            echo 'Vista no encontrada: ' . htmlspecialchars($template);
            exit;
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $templatePath;
        $content = ob_get_clean();

        if ($layout === null) {
            echo $content;
            return;
        }

        $layoutPath = self::layoutPath($layout);
        if (is_readable($layoutPath)) {
            include $layoutPath;
        } else {
            echo $content;
        }
    }

    /**
     * Renderiza una vista SIN layout y DEVUELVE el string (para montar HTML en
     * respuestas JSON, p. ej. la fila de una tabla paginada por fetch).
     */
    public static function partial(string $template, array $data = []): string
    {
        $templatePath = self::resolve($template);
        if (!is_readable($templatePath)) {
            return '';
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $templatePath;
        return (string) ob_get_clean();
    }

    private static function resolve(string $template): string
    {
        // B3: impedir traversal de path ('..') en el nombre del template.
        // Los templates son nombres fijos pasados por los controllers, pero
        // se valida por si acaso alguna ruta llegara a admitir entrada externa.
        if (str_contains($template, '..')) {
            http_response_code(500);
            echo 'Template inválido.';
            exit;
        }
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        return $config['paths']['views'] . '/' . str_replace('.', '/', $template) . '.php';
    }

    private static function layoutPath(string $layout): string
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        return $config['paths']['views'] . '/layouts/' . $layout . '.php';
    }

    public static function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Botón `?` de ayuda con tooltip. Vive aquí y no en cada vista para que el
     * contrato sea uno solo: el texto se escapa con `ENT_QUOTES` para el
     * atributo y se marca `data-bs-html="true"` para que `<code>` y `<strong>`
     * se pinten como tales dentro del tooltip.
     *
     * El texto lleva HTML a propósito, y por eso NUNCA se le pasa contenido
     * de usuario: solo literales de la propia vista. `app.js` inicializa estos
     * tooltips una vez para todas las páginas de `main.php`.
     */
    public static function help(string $html, ?string $label = null): string
    {
        $aria = $label !== null && $label !== '' ? $label : 'Qué hace este ajuste';

        return '<button type="button" class="app-help" data-bs-toggle="tooltip" data-bs-html="true"'
            . ' data-bs-title="' . self::escape($html) . '"'
            . ' aria-label="' . self::escape($aria) . '">'
            . '<i class="fa-solid fa-circle-question" aria-hidden="true"></i></button>';
    }

    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');
        // Cache-busting automático: si el archivo cambia, la URL cambia y el
        // navegador (móvil/PC) siempre recibe la versión fresca.
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $file = $config['paths']['public'] . '/assets/' . $path;
        $mtime = is_file($file) ? filemtime($file) : '';
        return '/assets/' . $path . ($mtime !== false && $mtime !== '' ? '?v=' . $mtime : '');
    }

    public static function url(string $path = '/'): string
    {
        return '/' . ltrim($path, '/');
    }

    /* ------------------------------------------------------------------
     * Fechas (fase 3, zona horaria canónica)
     *
     * Lo que sale de la BD es un string UTC (la sesión fija
     * `SET time_zone = '+00:00'` y PHP corre en UTC), así que pintarlo con
     * `date('d/m/Y', strtotime($v))` lo muestra en UTC: la hora se ve, pero es
     * la de Greenwich. Estos helpers son la única forma de pintar una fecha de
     * la BD, porque son los que la pasan a `app.timezone` (America/Caracas por
     * defecto). Con PHP en America/Mexico_City el mismo `date()` daba 2 h de
     * más sin delatar nada; ahora el desfase está en un solo sitio.
     * ------------------------------------------------------------------ */

    /** Fecha sin hora: 27/09/2026 */
    public static function date(?string $utc): string
    {
        return Clock::format($utc, 'd/m/Y');
    }

    /** Fecha y hora: 27/09/2026 21:33 */
    public static function datetime(?string $utc): string
    {
        return Clock::format($utc, 'd/m/Y H:i');
    }

    /**
     * Valor para un `<input type="datetime-local">`: `2026-10-03T14:30`.
     *
     * NO es una conversión de zona horaria. `schedule.open_at/close_at` son
     * muros de reloj de un `datetime-local`, no instantes: se interpretan en
     * `app.timezone` (ver `FormSettings::validate()`). Por eso aquí solo se
     * cambia el separador y se recortan los segundos — el `T` es lo que pide
     * el input y un `strtotime()` por el camino devolvería `false` en vez de un
     * valor, dejando el campo en blanco sin decir por qué.
     */
    public static function datetimeLocal(?string $stored): string
    {
        $v = trim((string) $stored);
        if ($v === '') {
            return '';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', $v, $m) === 1) {
            return $m[1] . 'T' . $m[2];
        }
        return '';
    }

    /**
     * "hace 5 minutos" / "hace 3 días", en la zona de presentación.
     *
     * El cálculo se hace sobre instantes, no sobre strings: con la zona mal
     * puesta la resta daba horas negativas o desproporcionadas sin que nada
     * fallara.
     */
    public static function relative(?string $utc): string
    {
        $dt = Clock::toDisplay($utc);
        if ($dt === null) {
            return '';
        }

        $segundos = time() - $dt->getTimestamp();

        // Un reloj que va por detrás no es un motivo para inventar un "dentro
        // de 2 minutos": se degrada a la fecha, que siempre es cierta.
        if ($segundos < 0) {
            return self::date($utc);
        }

        if ($segundos < 60) {
            return 'hace un momento';
        }
        if ($segundos < 3600) {
            $m = intdiv($segundos, 60);
            return 'hace ' . $m . ($m === 1 ? ' minuto' : ' minutos');
        }
        if ($segundos < 86400) {
            $h = intdiv($segundos, 3600);
            return 'hace ' . $h . ($h === 1 ? ' hora' : ' horas');
        }
        $d = intdiv($segundos, 86400);
        if ($d < 30) {
            return 'hace ' . $d . ($d === 1 ? ' día' : ' días');
        }
        if ($d < 365) {
            $meses = intdiv($d, 30);
            return 'hace ' . $meses . ($meses === 1 ? ' mes' : ' meses');
        }

        $anios = intdiv($d, 365);
        return 'hace ' . $anios . ($anios === 1 ? ' año' : ' años');
    }

    /**
     * El AHORA en la zona de presentación, para lo que no viene de la BD (la
     * fecha de impresión de una hoja, p. ej.).
     *
     * `date()` a pelo ya no sirve: PHP corre en UTC, así que imprimiría la hora
     * de Greenwich en un papel que luego lee alguien en Caracas. Se convierte
     * desde el instante, no desde la zona del proceso.
     *
     * @param string $format formato de `DateTimeImmutable::format()`
     */
    public static function now(string $format = 'd/m/Y H:i'): string
    {
        $ahora = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return Clock::format($ahora->format('Y-m-d H:i:s'), $format);
    }

    /**
     * Instante como epoch (segundos) para un `data-*` que consume JavaScript.
     *
     * El epoch no depende de la zona, así que aquí no hay conversión que hacer:
     * se resuelve con `strtotime` en UTC explícito para que quede claro que el
     * número es un instante y no una fecha reinterpretada.
     */
    public static function timestamp(?string $utc): string
    {
        if ($utc === null || trim($utc) === '') {
            return '0';
        }

        $ts = strtotime($utc . ' UTC');

        return $ts === false ? '0' : (string) $ts;
    }

    /**
     * Valor de una respuesta compuesta como HTML de chips, o `null` si el valor
     * no es un compuesto reconocible (el llamante pinta entonces el texto).
     *
     * Es la MISMA representación en la tabla de respuestas de la analítica
     * (`views/analytics/_rows.php`) y en el detalle de una respuesta
     * (`views/responses/show.php`): el dueño ve el mismo chip en los dos sitios,
     * y hay una sola copia de la regla.
     *
     * Devuelve **HTML ya escapado** (los chips llevan `View::escape()`), así que
     * el llamante lo imprime tal cual, sin volver a escapar.
     *
     * La regla del PII vive fuera: se llama con el valor **después** de
     * enmascarar, y `[oculto]` no es JSON válido, así que cae al texto y no se
     * reinterpreta como lista. Un valor que solo EMPIEZA por `[`/`{` sin ser
     * JSON también queda intacto.
     *
     * - Lista (checkbox): un chip por valor elegido (antes salía «a; b; c»).
     * - Mapa (matriz): `clave → valor` por fila.
     * - Mapa (ranking): ordenado por la posición (1..N) que es el valor.
     */
    public static function answerValue(string $value, string $fieldType = ''): ?string
    {
        if ($value !== '' && $value[0] === '[') {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && array_is_list($decoded)) {
                $chips = '';
                foreach ($decoded as $item) {
                    $chips .= '<span class="vf-chip">' . self::escape((string) $item) . '</span>';
                }
                return $chips === '' ? '' : '<span class="vf-answer-chips">' . $chips . '</span>';
            }
            return null;
        }

        if ($value !== '' && $value[0] === '{') {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                return null;
            }
            $filas = '';
            if ($fieldType === 'ranking') {
                uasort($decoded, static fn($a, $b) => (int) $a <=> (int) $b);
                foreach ($decoded as $item => $pos) {
                    $filas .= '<span class="vf-chip-ranking"><span class="vf-chip-pos">' . self::escape((string) $pos)
                        . '</span><span class="vf-chip-key">' . self::escape((string) $item) . '</span></span>';
                }
                $clase = 'vf-answer-ranking';
            } else {
                foreach ($decoded as $k => $v) {
                    $filas .= '<span class="vf-chip-matrix"><span class="vf-chip-key">' . self::escape((string) $k)
                        . '</span><span class="vf-chip-val">' . self::escape((string) $v) . '</span></span>';
                }
                $clase = 'vf-answer-matrix';
            }
            return $filas === '' ? '' : '<span class="' . $clase . '">' . $filas . '</span>';
        }

        return null;
    }

    /**
     * El logo de la instancia dentro de un `span` con la clase dada.
     *
     * Si hay un logo subido (`Brand::logoUrl()`) pinta un `<img>`; si no, cae al
     * icono FontAwesome de siempre (`fa-list-check`, el mismo de antes de AG2).
     * El `alt` es el nombre de la instancia, así que quien use lector de pantalla
     * oye la marca y no «imagen».
     *
     * Se centraliza aquí porque el logo aparece en TRES sitios (sidebar, topbar,
     * pantalla de auth) y tener tres copias es la forma más barata de que una se
     * quede con el icono viejo cuando se sube un logo.
     */
    public static function brandLogo(string $wrapClass = 'app-logo'): string
    {
        $url = Brand::logoUrl();
        if ($url !== null) {
            return '<span class="' . self::escape($wrapClass) . '">'
                . '<img src="' . self::escape($url) . '" alt="' . self::escape(Brand::name()) . '" class="app-logo-img">'
                . '</span>';
        }

        return '<span class="' . self::escape($wrapClass) . '"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span>';
    }

    /**
     * El `<link rel="icon">` de la instancia.
     *
     * Con favicon subido apunta a `/branding/favicon` con su `type` real; sin él,
     * al `favicon.svg` vendorizado de siempre. Devuelve la ETIQUETA completa
     * porque el favicon se repite en nueve vistas y el `type` cambia según la
     * imagen subida (png/jpg/webp): escribirlo a mano en cada una es garantizar
     * que una se quede con el `image/svg+xml` viejo.
     */
    public static function favicon(): string
    {
        $url = Brand::faviconUrl();
        if ($url !== null) {
            return '<link rel="icon" href="' . self::escape($url) . '">';
        }

        return '<link rel="icon" type="image/svg+xml" href="' . self::escape(self::asset('favicon.svg')) . '">';
    }

    /**
     * El `<style>` con los tokens de marca de la instancia (color primario).
     *
     * Va en los layouts ANTES del CSS por formulario: en la página pública de un
     * formulario con tema propio, ese tema puede sobrescribir el color de la
     * instancia; en el panel, el color de la instancia es el que manda.
     * `Brand::themeCss()` no emite nada si el color es el default, así que esto
     * no añade un `<style>` a una instalación que no toca la marca.
     */
    public static function brandStyle(): string
    {
        $css = Brand::themeCss();
        return $css === '' ? '' : '<style>' . $css . '</style>';
    }
}