<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\View;

/**
 * Fase 2, items 2.4 y 2.5 — el escapado del cliente y la CSP que lo respalda.
 *
 * 2.5 — `esc()` en el front escapaba con el truco `textContent` → `innerHTML`,
 * que escapa `& < >` pero NO las comillas. Se interpolaba en atributos entre
 * comillas dobles (`placeholder="' + esc(f.placeholder) + '"`, `value=`,
 * `accept=`, `<option value=`), así que un texto con `"` cerraba el atributo y
 * lo que venía detrás se convertía en atributos nuevos (`onfocus=…`). Y el
 * builder lo abre cualquiera con rol editor o admin (`canEdit()`), de modo que
 * un editor que revisa el formulario de otro ejecutaba el JS de quien lo creó:
 * XSS con la sesión de un privileged, no auto-XSS.
 *
 * El invariante que sostiene el arreglo, y que se mide aquí:
 *
 *     la salida de `esc()` NO contiene comillas crudas (ni " ni ')
 *
 * con eso vale para texto y para cualquier atributo entrecomillado. Escapar las
 * comillas tampoco cambia el resultado visual en un nodo de texto (el navegador
 * decodifica la entidad), así que una sola función cubre todos los call sites.
 *
 * 2.4 — la CSP estaba vacía por defecto en `config/config.php` "porque las
 * vistas usaban <script> inline". Ya no hay ninguno: todo está externalizado y
 * el único handler en línea que quedaba (el botón de la vista imprimible) se
 * movió a `public/assets/js/print.js`. Con el default vacío, una instalación
 * limpia se quedaba sin CSP, que es lo que convertía cualquier inyección de
 * HTML en XSS sin ninguna barrera. Estos tests fijan que la política siga
 * siendo segura por defecto Y que la app no vuelva a introducir un <script>
 * inline que la dejara rota en silencio.
 */
class BuilderEscapingTest
{
    private const JS_BUILDER = 'public/assets/js/builder.js';
    private const JS_DASHBOARD = 'public/assets/js/dashboard.js';
    private const JS_APP = 'public/assets/js/app.js';
    private const JS_PRINT = 'public/assets/js/print.js';
    private const PRINT_VIEW = 'views/dashboard/forms/print.php';

    /* ---------- 2.5: el escapado ---------- */

    public function testEscEscapaLasComillasEnBuilderYApp(): void
    {
        $fallos = [];

        // `dashboard.js` ya NO está en la lista: su único texto interpolado era
        // el del aviso flotante, y desde que delega en `window.vfToast()`
        // (app.js) ese escapado ocurre allí. Dejar `esc()` en dashboard.js
        // para satisfacer el test sería código muerto —y un `esc()` que nadie
        // llama es un `esc()` que nadie revisa—. Lo que sustituye a la lista es
        // el test de abajo: si dashboard.js vuelve a construir HTML, tiene que
        // hacerlo por el camino escapado.
        foreach ([self::JS_BUILDER, self::JS_APP] as $rel) {
            $esc = $this->escBody($rel);
            if ($esc === '') {
                $fallos[] = $rel . ': no se encuentra esc()';
                continue;
            }
            // El truco `textContent`/`innerHTML` no escapa comillas: es
            // exactamente el bug, así que su regreso es un fallo.
            if (str_contains($esc, 'textContent')) {
                $fallos[] = $rel . ': esc() no puede volver al truco textContent (no escapa comillas)';
            }
            foreach ([
                '"/g'   => 'comilla doble',
                "'/g"   => 'comilla simple',
                '&/g'   => 'ampersand',
                '</g'   => 'menor que',
                '>/g'   => 'mayor que',
            ] as $needle => $what) {
                if (!str_contains($esc, $needle)) {
                    $fallos[] = $rel . ': esc() debe escapar ' . $what . ' (' . $needle . ')';
                }
            }
        }

        assertEquals([], $fallos, implode(' | ', $fallos));
    }

    /**
     * `dashboard.js`delegó su aviso en `app.js` (una sola implementación, y la
     * página de respuestas no carga dashboard.js pero sí app.js). Se ata el
     * trato nuevo por los dos lados: delega, y no se ha quedado con markup
     * propio donde se escape. Un `innerHTML` que se colara aquí sin pasar por
     * `vfToast()` reintroduciría el XSS en el panel.
     */
    public function testElAvisoDelPanelSeEscapaEnAppYNoEnDashboard(): void
    {
        $app = $this->read(self::JS_APP);
        $dash = $this->read(self::JS_DASHBOARD);
        $fallos = [];

        if (!str_contains($app, 'window.vfToast')) {
            $fallos[] = 'app.js debe definir window.vfToast(), que es el aviso compartido';
        }
        // El `esc()` de app.js tiene que estar pegado al punto donde se
        // construye el HTML del aviso, no solo existir en el fichero.
        $toast = $this->escBodyFrom($app, 'function esc(');
        $cuerpo = strpos($app, 'window.vfToast = function');
        $cola = $cuerpo === false ? '' : substr($app, (int) $cuerpo, 1200);
        if (strpos($cola, 'esc(msg)') === false) {
            $fallos[] = 'vfToast() tiene que pasar el texto por esc(msg) antes de meterlo en el HTML';
        }
        if ($toast === '') {
            $fallos[] = 'app.js necesita esc()';
        }

        if (!str_contains($dash, 'window.vfToast')) {
            $fallos[] = 'dashboard.js debe delegar el aviso en window.vfToast()';
        }
        if (str_contains($dash, 'app-toast text-bg-')) {
            $fallos[] = 'dashboard.js ya no debe construir el markup del aviso (una sola implementación)';
        }

        assertEquals([], $fallos, implode(' | ', $fallos));
    }

    /**
     * El mismo escapado en PHP, para poder comprobar el INVARIANTE (salida sin
     * comillas crudas) sobre entradas de ataque. Si esto dejara de ser cierto,
     * cualquier interpolación en atributo sería inyectable.
     */
    public function testLaSalidaDeEscNoTieneComillasCrudas(): void
    {
        $ataques = [
            'x" onfocus="alert(1)',
            "y' onmouseover='alert(1)",
            '"><script>alert(1)</script>',
            '</textarea><img src=x onerror=alert(1)>',
            '&amp; ya escapado',
            'comilla " suelta',
        ];

        $fallos = [];
        foreach ($ataques as $i => $a) {
            $out = $this->phpEsc($a);
            if (str_contains($out, '"')) {
                $fallos[] = sprintf('caso %d: la salida no debe tener "', $i);
            }
            if (str_contains($out, "'")) {
                $fallos[] = sprintf('caso %d: la salida no debe tener comilla simple', $i);
            }
            if (str_contains($out, '<')) {
                $fallos[] = sprintf('caso %d: la salida no debe tener <', $i);
            }
        }

        assertEquals([], $fallos, implode(' | ', $fallos));
    }

    /**
     * El punto concreto del hallazgo: un placeholder con comilla ya no rompe el
     * atributo. Antes `placeholder="x" onfocus="…"` salía literal del
     * `innerHTML` y el navegador leía dos atributos.
     *
     * El invariante correcto NO es "la palabra onfocus no aparece" (aparece,
     * pero como texto inerte dentro del valor): es que el payload no abre ni
     * cierra ningún delimitador de atributo, o sea que el número de comillas
     * crudas del HTML es el de los dos atributos legítimos y no uno más.
     */
    public function testPlaceholderConComillaNoRompeElAtributo(): void
    {
        $fallos = [];

        foreach (['x" onfocus="alert(1)', 'a"b"c" onerror=alert(1)', '"><script>x</script>'] as $i => $payload) {
            $escaped = $this->phpEsc($payload);
            $html = '<input type="text" placeholder="' . $escaped . '">';

            // type + placeholder = 4 comillas crudas. Cualquier comilla que se
            // colara sería un delimitador nuevo, y por tanto un atributo nuevo.
            if (substr_count($html, '"') !== 4) {
                $fallos[] = sprintf('caso %d: el payload mete comillas crudas (%d): %s', $i, substr_count($html, '"'), $html);
            }
            // Ninguna etiqueta nueva en el valor (el `<input ...>` de la
            // izquierda es legítimo; lo que no puede aparecer es otro `<`).
            if (str_contains($escaped, '<')) {
                $fallos[] = sprintf('caso %d: el payload mete una etiqueta: %s', $i, $escaped);
            }
            if (!str_contains($escaped, '&quot;')) {
                $fallos[] = sprintf('caso %d: la comilla debe quedar como entidad: %s', $i, $escaped);
            }
        }

        assertEquals([], $fallos, implode(' | ', $fallos));
    }

    /**
     * Las vistas del servidor usan View::escape(), que ya escapa ENT_QUOTES. Se
     * fija para que nadie "simplifique" esa parte pensando que esc() del front
     * la cubre.
     */
    public function testViewEscapeEscapaComillas(): void
    {
        $out = View::escape('x" onfocus=\'alert(1)\'');
        $fallos = [];
        if (!str_contains($out, '&quot;')) {
            $fallos[] = 'View::escape debe escapar la comilla doble';
        }
        if (!str_contains($out, '&#039;')) {
            $fallos[] = 'View::escape debe escapar la comilla simple';
        }
        assertEquals([], $fallos, implode(' | ', $fallos));
    }

    /* ---------- 2.4: la CSP por defecto ---------- */

    public function testLaCspTieneDefaultSeguroEnCodigo(): void
    {
        $csp = (string) Config::get('app.csp_policy');
        $fallos = [];

        if ($csp === '') {
            $fallos[] = 'la CSP no puede estar vacía por defecto';
        }
        foreach ([
            "default-src 'self'",
            "script-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ] as $directiva) {
            if (!str_contains($csp, $directiva)) {
                $fallos[] = 'la política debe incluir: ' . $directiva . ' (actual: ' . $csp . ')';
            }
        }
        // Sin 'unsafe-inline' en script-src: es lo que convierte una inyección de
        // HTML en XSS, así que es justo lo que no puede faltar.
        if (preg_match("/script-src[^;]*'unsafe-inline'/", $csp) === 1) {
            $fallos[] = 'script-src no puede llevar unsafe-inline';
        }
        if (str_contains($csp, 'unsafe-eval')) {
            $fallos[] = 'no debe haber unsafe-eval';
        }
        // El default viene en el código, no solo de .env.example: si alguien
        // borra la línea CSP_POLICY de su .env, la política sigue estando.
        if (preg_match(
            "/'csp_policy'\s*=>\s*\(string\)\s*App\\\\Core\\\\Env::get\(\s*'CSP_POLICY'\s*,\s*[\"']default-src/s",
            $this->read('config/config.php')
        ) !== 1) {
            $fallos[] = "config/config.php debe pasar un default NO vacío a Env::get('CSP_POLICY', ...)";
        }

        assertEquals([], $fallos, implode(' | ', $fallos));
    }

    /**
     * El embed relaja `frame-ancestors 'none'` a `'self'` con un regex sobre la
     * política (FillController::allowEmbedHeaders). Si el default no casara con
     * ese patrón, el embed se quedaría con 'none' y no se podría embeber nunca
     * aunque el formulario lo permita. Se fija el formato exacto.
     *
     * Se calcula todo primero y se asserta después: mezclar llamadas con
     * lecturas del valor intermedio hace el test frágil ante cualquier
     * reordenación de dentro de la función.
     */
    public function testElDefaultDeCspCasaConElRegexDelEmbed(): void
    {
        $csp = (string) Config::get('app.csp_policy');
        $nulo = null;
        $relajado = preg_replace("/frame-ancestors\s+'none'/", "frame-ancestors 'self'", $csp);
        $esString = is_string($relajado);
        $relajado = $esString ? $relajado : '';
        $comprobaciones = [
            'el regex del embed debe aplicar (preg_replace devuelve string)' => $esString,
            "el default debe llevar frame-ancestors 'none'" => $relajado !== $csp,
            "el embed queda en frame-ancestors 'self'" => str_contains($relajado, "frame-ancestors 'self'"),
            'el default debe llevar frame-ancestors \'none\' literal' => str_contains($csp, "frame-ancestors 'none'"),
        ];
        unset($nulo);

        $fallos = [];
        foreach ($comprobaciones as $msg => $ok) {
            if ($ok !== true) {
                $fallos[] = $msg;
            }
        }

        assertEquals([], $fallos, implode(' | ', $fallos));
    }

    /**
     * La app tiene que seguir funcionando con `script-src 'self'`: eso exige
     * cero scripts inline y cero handlers en línea. Este es el test que evita
     * que alguien rompa la app en silencio al añadir un `<script>` inline.
     */
    public function testNingunaVistaTieneScriptInlineNiHandlerEnLinea(): void
    {
        $offenders = [];

        foreach ($this->viewFiles() as $file) {
            $src = $this->sinComentarios($this->readAbs($file));

            // <script> sin src. (Los comentarios ya van fuera, así que un
            // "<script>" mencionado en una nota no cuenta como etiqueta.)
            if (preg_match('/<script(?![^>]*\ssrc=)[^>]*>/i', $src) === 1) {
                $offenders[] = $file . ': <script> sin src';
            }

            // Handlers en línea: on*= en una etiqueta.
            if (preg_match('/\son(click|submit|change|input|load|error|mouseover|focus|blur|keydown)\s*=/i', $src) === 1) {
                $offenders[] = $file . ': handler on* en línea';
            }
        }

        assertEquals([], $offenders, "Con script-src 'self' esto rompe la página: " . implode(' | ', $offenders));
    }

    /**
     * El botón de imprimir pasó de onclick a un atributo de datos + print.js.
     * Si alguien devuelve el onclick, la CSP lo deja muerto sin avisar.
     */
    public function testElBotonDeImprimirUsaElScriptExterno(): void
    {
        $view = $this->read(self::PRINT_VIEW);
        $js = $this->read(self::JS_PRINT);
        $fallos = [];

        if (!str_contains($view, 'data-print')) {
            $fallos[] = 'el botón debe llevar data-print';
        }
        if (str_contains($view, 'onclick')) {
            $fallos[] = 'el botón no debe llevar onclick inline (la CSP no lo ejecuta)';
        }
        if (!str_contains($view, 'js/print.js')) {
            $fallos[] = 'la vista debe cargar print.js';
        }
        if (!str_contains($js, 'window.print()')) {
            $fallos[] = 'print.js debe llamar a window.print()';
        }
        if (!str_contains($js, 'data-print')) {
            $fallos[] = 'print.js debe enganchar [data-print]';
        }

        assertEquals([], $fallos, implode(' | ', $fallos));
    }

    /**
     * El estilo inline sí está permitido a propósito (Bootstrap y la vista
     * imprimible usan style="" y un <style>), pero conviene dejarlo dicho para
     * que nadie lo quite pensando que sobra.
     */
    public function testElEstiloInlineSigueSiendoNecesario(): void
    {
        $csp = (string) Config::get('app.csp_policy');
        $fallos = [];

        if (!str_contains($csp, "style-src 'self' 'unsafe-inline'")) {
            $fallos[] = "style-src necesita unsafe-inline: Bootstrap y print.php usan style=\"\" y un <style>";
        }
        if (!str_contains($this->read(self::PRINT_VIEW), '<style>')) {
            $fallos[] = 'la vista imprimible tiene su propio <style>';
        }

        assertEquals([], $fallos, implode(' | ', $fallos));
    }

    /* ---------- Utilidades ---------- */

    /**
     * Espejo en PHP del escapado del front, para poder comprobar el invariante
     * sin un motor JS en el entorno. Si cambia el JS, esto cambia con él.
     */
    private function phpEsc(string $str): string
    {
        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&#39;'],
            $str
        );
    }

    /** Cuerpo de la función esc() en un JS dado. */
    private function escBody(string $rel): string
    {
        return $this->escBodyFrom($this->read($rel), 'function esc(');
    }

    private function escBodyFrom(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            return '';
        }
        $end = strpos($src, "\n    }", (int) $start);
        return $end === false ? '' : substr($src, (int) $start, (int) $end - (int) $start);
    }

    /**
     * Quita comentarios PHP para no contar como código lo que solo se menciona
     * en una nota (p. ej. "sin <script> inline" dentro de un //).
     */
    private function sinComentarios(string $src): string
    {
        $src = preg_replace('#/\*.*?\*/#s', ' ', $src) ?? $src;
        $src = preg_replace('#^[ \t]*(//|\#).*$#m', ' ', $src) ?? $src;
        return $src;
    }

    /** @return string[] */
    private function viewFiles(): array
    {
        $base = dirname(__DIR__, 2) . '/views';
        if (!is_dir($base)) {
            return [];
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        $out = [];
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    private function readAbs(string $abs): string
    {
        return is_file($abs) ? (string) file_get_contents($abs) : '';
    }

    private function read(string $rel): string
    {
        return $this->readAbs(dirname(__DIR__, 2) . '/' . $rel);
    }
}
