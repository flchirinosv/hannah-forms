<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * F0.3-seis — un `</div>` perdido, y 732 tests que no lo vieron.
 *
 * El modal «Agregar elemento» del constructor salía con el fondo oscurecido y
 * sin diálogo, y en móvil no había forma de cerrarlo. La causa no era el CSS ni
 * el móvil: al `modal-footer` de `#formSettingsModal` le faltaba el cierre del
 * propio modal, y a partir de ahí `#addFieldModal` y `#builderApp` quedaban
 * ANIDADOS dentro de `#formSettingsModal`, que Bootstrap lleva `display: none`.
 *
 * Los tres síntomas son el mismo hecho, y por eso la avería era tan confusa:
 *
 *   - el backdrop lo añade Bootstrap a `<body>`, que está FUERA, así que el
 *     tinte se ve igual (y en el escritorio el diálogo aparece en su sitio);
 *   - el diálogo nunca se pinta: su padre está oculto, y él pone
 *     `display: block` inline, que solo hace efecto si el padre se ve;
 *   - no se cierra tocando el fondo, porque el clic de dismissal lo escucha el
 *     propio `.modal` y un elemento con `display: none` no puede ser el
 *     objetivo de un clic. En escritorio queda `Esc`; en móvil no hay `Esc` y
 *     `body.modal-open` bloquea el scroll, así que solo quedaba recargar.
 *
 * `#builderApp` es un `<div>` vacío que solo lleva `data-*`, y por eso el
 * constructor se veía entero y la avería parecía del modal y no de la vista.
 * `#formSettingsModal`, `#fieldSettingsModal` y `#livePreviewModal` abrían
 * bien, que es lo que hacía pensar que el problema era del modal de tipos.
 *
 * Lo que se fija aquí es la clase de fallo, no este modal: el HTML que una vista
 * emite tiene que estar anidado como se lee, y un modal no puede vivir dentro de
 * otra cosa (todo lo que lo contiene es, casi por definición, algo con
 * `display: none`). Ningún test recorría la estructura de las 62 vistas, así que
 * el error podía entrar con la suite en verde.
 */
class ViewStructureTest
{
    private const VIEWS = __DIR__ . '/../../views';

    /** Etiquetas sin cierre: `<div>` no la admite, así que el balance es exacto. */
    private const SIN_CIERRE = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img',
        'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /** Las clases de Bootstrap del diálogo: el que se busca es el `<div class="modal">`. */
    private const PARTES_MODAL = ['modal-dialog', 'modal-content', 'modal-header',
        'modal-body', 'modal-footer', 'modal-backdrop'];

    /**
     * Lo único que puede contener un modal es el documento entero.
     *
     * La regla nació de que Bootstrap pone `display: none` al `.modal` y lo
     * hace visible solo él: cualquier ancestro con `display` distinto lo deja
     * invisible para siempre. `<html>` y `<body>` no están nunca en ese caso,
     * así que un modal al final del `<body>` es justo donde lo deja Bootstrap.
     * Sin esta lista, el diálogo de confirmación del panel (F0.4-tres) no
     * podría existir sin romper el test, y la salida sería ponerlo en un
     * sitio donde no puede vivir.
     *
     * Las etiquetas son las de `etiquetaDe()`, que para una etiqueta sin `id` ni
     * `class` devuelve `<html>` CON los ángulos dentro: `<html>`, no `html`.
     */
    private const CONTENEDORES_OK = ['<html>', '<body>'];

    private function vistas(): array
    {
        $salidas = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::VIEWS, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $salidas[] = $f->getPathname();
            }
        }
        sort($salidas);

        return $salidas;
    }

    /**
     * El HTML que el navegador recibe. Los bloques `<?php … ?>` no emiten
     * etiquetas (guardan la lógica y, a veces, HTML en cadenas), mientras que
     * `<?= … ?>` sí emite su valor: va dentro de atributos o de texto y no
     * balanced nada, así que se deja donde está.
     */
    private function htmlDe(string $ruta): string
    {
        $src = file_get_contents($ruta);
        $limpio = preg_replace('#<\?php.*?\?>#s', '', $src);

        return $limpio === null ? '' : $limpio;
    }

    private function etiquetaDe(string $tag, string $attrs): string
    {
        if (preg_match('/\bid="([^"]*)"/', $attrs, $m) === 1 && $m[1] !== '') {
            return '#' . $m[1];
        }
        if (preg_match('/\bclass="([^"]*)"/', $attrs, $m) === 1) {
            $clases = preg_split('/\s+/', trim($m[1]));
            if ($clases !== false && $clases !== []) {
                return '.' . implode('.', $clases);
            }
        }

        return '<' . $tag . '>';
    }

    private function esModal(string $attrs): bool
    {
        if (preg_match('/\bclass="([^"]*)"/', $attrs, $m) !== 1) {
            return false;
        }
        $clases = preg_split('/\s+/', trim($m[1])) ?: [];
        if (!in_array('modal', $clases, true)) {
            return false;
        }
        foreach (self::PARTES_MODAL as $parte) {
            if (in_array($parte, $clases, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recorre el HTML como el parser del navegador: apila las aperturas y las
     * cierra con la última coincidencia. Devuelve los modales con su profundidad
     * y lo que queda abierto al final del archivo.
     *
     * @return array{modales: array<int, array{linea:int, etiqueta:string, dentro:string[]}>, sinCerrar: string[], abiertas: int}
     */
    private function analiza(string $html): array
    {
        $pila = [];
        $modales = [];
        $sinCerrar = [];
        $abiertas = 0;
        $linea = 1;
        $patron = '#<(/?)([a-zA-Z][\w-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)(/?)>|(\n)#';

        preg_match_all($patron, $html, $m, PREG_SET_ORDER);
        foreach ($m as $t) {
            if (($t[5] ?? '') === "\n") {
                $linea++;
                continue;
            }
            $cierra = $t[1] === '/';
            $tag = strtolower($t[2]);
            $attrs = $t[3];
            $vacia = $t[4] === '/';

            if ($vacia || in_array($tag, self::SIN_CIERRE, true)) {
                continue;
            }
            if ($cierra) {
                $cima = count($pila) - 1;
                if ($cima >= 0 && $pila[$cima]['tag'] === $tag) {
                    array_pop($pila);
                } else {
                    // El navegador cierra hasta la coincidencia más externa.
                    for ($i = $cima; $i >= 0; $i--) {
                        if ($pila[$i]['tag'] === $tag) {
                            array_splice($pila, $i);
                            break;
                        }
                    }
                }
                continue;
            }
            if ($this->esModal($attrs)) {
                $dentro = array_values(array_diff(
                    array_map(static fn(array $e): string => $e['etiqueta'], $pila),
                    self::CONTENEDORES_OK
                ));
                $modales[] = [
                    'linea' => $linea,
                    'etiqueta' => $this->etiquetaDe($tag, $attrs),
                    'dentro' => $dentro,
                ];
            }
            $pila[] = ['tag' => $tag, 'linea' => $linea, 'etiqueta' => $this->etiquetaDe($tag, $attrs)];
            $abiertas++;
        }

        foreach ($pila as $e) {
            $sinCerrar[] = $e['etiqueta'] . ' (línea ' . $e['linea'] . ')';
        }

        return ['modales' => $modales, 'sinCerrar' => $sinCerrar, 'abiertas' => $abiertas];
    }

    public function testNingunaVistaDejaEtiquetasSinCerrar(): void
    {
        $vistas = $this->vistas();
        assertTrue(count($vistas) >= 50, 'Se esperaban las 68 vistas y se encontraron ' . count($vistas));

        $rotas = [];
        $analizadas = 0;
        foreach ($vistas as $ruta) {
            $r = $this->analiza($this->htmlDe($ruta));
            $analizadas += $r['abiertas'];
            if ($r['sinCerrar'] !== []) {
                $rotas[] = str_replace(self::VIEWS . '/', '', $ruta) . ' → ' . implode(', ', $r['sinCerrar']);
            }
        }

        // Guard anti-vacío: un recorrido que no recorre pasa las dos
        // comprobaciones. Las 68 vistas suman unas 2000 aperturas,
        // y así un `preg_match_all` que no casa o un `foreach` sobre el retorno (un int)
        // salen como fallo y no como un test verde.
        assertTrue(
            $analizadas > 1800,
            'El recorrido solo vio ' . $analizadas . ' aperturas: no está analizando el HTML'
        );
        // Un `</div>` de menos no da error en ningún sitio: ni en PHP, ni en el
        // navegador, ni en un test que no mire el HTML emitido. Lo único que lo
        // delata es el síntoma, y el síntoma es ambiguo (parecía del móvil).
        assertTrue(
            $rotas === [],
            "Etiquetas sin cerrar; el resto de la vista queda anidado dentro:\n  " . implode("\n  ", $rotas)
        );
    }

    public function testLosModalesNoVivenAnidadosDentroDeOtroElemento(): void
    {
        $anidados = [];
        $vistos = 0;
        foreach ($this->vistas() as $ruta) {
            $r = $this->analiza($this->htmlDe($ruta));
            foreach ($r['modales'] as $m) {
                $vistos++;
                if ($m['dentro'] !== []) {
                    $anidados[] = str_replace(self::VIEWS . '/', '', $ruta) . ' línea ' . $m['linea']
                        . ' → ' . $m['etiqueta'] . ' dentro de ' . implode(' > ', $m['dentro']);
                }
            }
        }

        assertTrue($vistos >= 6, 'Solo se encontraron ' . $vistos . ' modales: el rastreo no está buscando');
        // Todo lo que contiene a un modal está oculto (`display: none`), así que
        // abrirlo no puede pintarlo aunque él se ponga `display: block`.
        assertTrue(
            $anidados === [],
            "Modales anidados, invisibles para siempre:\n  " . implode("\n  ", $anidados)
        );
    }
}
