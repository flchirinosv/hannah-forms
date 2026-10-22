<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * Lo que se ve en el constructor tiene que parecerse al formulario (bloque 6).
 *
 * Este archivo existe por un hallazgo concreto: `builder.js` emite trece clases
 * de la miniatura de la tarjeta (`.nps-dot`, `.scale-dot`, `.star-icon`,
 * `.ranking-pos`, `.vf-matrix-preview`…) y **`app.css` no definía ninguna**. La
 * miniatura de un NPS salía como el texto «0 1 2 3 … 10» y las estrellas del
 * tamaño por defecto: no era una miniatura, era un error de maquetación dentro
 * de la tarjeta. Y en el preview en vivo pasaba lo contrario — traía su propio
 * HTML, con estilos inline y clases inexistentes — así que se veía distinto del
 * formulario que iba a publicarse.
 *
 * Los tests de aquí no miran píxeles: miran el contrato entre el JS que pinta y
 * el CSS que debería estar. Es la parte que se puede automatizar de esto; lo que
 * solo se ve en pantalla es el bloque Q de `docs/pruebas-funcionales.md`.
 *
 * Salieron de los tres fallos que la revisión manual del bloque 6 destapó, los
 * tres del mismo tipo: una regla que faltaba o sobraba en el CSS del FILL
 * (`display` en el botón del NPS, `border-left` en los hijos de la sección) y un
 * dato que el builder inventaba (la etiqueta «Página N» de un salto de paso). El
 * primero de los dos CSS estaba en el lado del builder y el del fill se quedó
 * sin corregir, que es justo el fallo que hace que «se ve bien en el preview» no
 * signifique nada.
 *
 * Sin BD, salvo la migración del salto de paso, que necesita filas propias.
 */
class BuilderPreviewCssTest
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function js(): string
    {
        return (string) file_get_contents(self::root() . '/public/assets/js/builder.js');
    }

    private static function css(): string
    {
        return (string) file_get_contents(self::root() . '/public/assets/css/app.css');
    }

    /**
     * Clases que el JS emite SOLO como gancho (un `querySelector`, un
     * `classList.add`, o el contenedor cuyo layout ya dan las utilidades de
     * Bootstrap del markup) y que por tanto no necesitan regla propia.
     *
     * Lista explícita y corta a propósito: lo que no está aquí y no tiene CSS es
     * un error, que es justo lo que pasó con las trece de la miniatura. Si
     * añades un gancho nuevo, se añade aquí y se justifica.
     */
    private const GANCHOS_SIN_CSS = [
        'app-label-edit'   => 'el `<span>` de la etiqueta que el contenteditable convierte en input',
        'nps-preview'      => 'el contenedor; su hueco es la utilidad `gap-1` del markup',
        'scale-preview'    => 'el contenedor; su hueco es la utilidad `gap-2` del markup',
        'star-preview'     => 'el contenedor; su hueco es la utilidad `gap-1` del markup',
        'vf-ranking-list'  => 'el contenedor del ranking; la maqueta es `d-flex flex-column`',
        // El `<span>` que envuelve el icono de papel y la palabra del botón. Lo
        // emite el markup del fill (`views/fill/show.php`) y no tiene regla propia:
        // mientras no se envía es un inline sin maquetar, y durante el envío lo
        // estira `.vf-submit.vf-submitting span`. Está aquí porque el preview en
        // vivo copia ese markup y el archivo audita lo que el JS emite.
        'vf-submit-label'  => 'el `<span>` del botón de enviar; durante el envío lo estira `.vf-submit.vf-submitting span`',
    ];

    /**
     * Prefijos que son de la app. Las clases de Bootstrap y de FontAwesome
     * (`btn`, `gap-1`, `fa-star`…) quedan fuera por definición, así que la
     * auditoría no necesita una lista deBootstrap que se queda vieja a la
     * primera actualización de la librería.
     */
    private const PREFIJOS_APP = ['app-', 'vf-', 'nps-', 'scale-', 'star-', 'ranking-'];

    /** @return array<int,string> */
    private static function clasesEmitidas(): array
    {
        $clases = [];
        if (preg_match_all('/class="([^"{}]*)"/', self::js(), $m) > 0) {
            foreach ($m[1] as $valor) {
                // OJO: el HTML va dentro de cadenas de JS, así que una clase
                // interpolada (`' vf-preview-dim' : ''`) deja el resto del
                // marcador pegado al token (`vf-preview-dim'`). El filtro por
                // forma de identificador es lo que evita que eso se cuente como
                // una clase que el CSS no define.
                foreach (preg_split('/\s+/', trim($valor)) ?: [] as $c) {
                    if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $c) === 1) {
                        $clases[$c] = true;
                    }
                }
            }
        }
        return array_keys($clases);
    }

    /**
     * Quita los comentarios de LÍNEA COMPLETA, que es como están escritos en este
     * repositorio. Hace falta porque el por qué de cada cambio cita las clases
     * viejas: si no se limpian, el test que busca «esta clase ya no está» se
     * encuentra con su propia explicación.
     */
    private static function sinComentarios(string $codigo): string
    {
        $lineas = [];
        foreach (preg_split('/\R/', $codigo) ?: [] as $linea) {
            $lineas[] = preg_match('/^\s*\/\//', $linea) === 1 ? '' : $linea;
        }
        return implode("\n", $lineas);
    }

    /** @return array<int,string> */
    private static function clasesDefinidas(): array
    {
        preg_match_all('/\.([A-Za-z][A-Za-z0-9_-]*)/', self::css(), $m);
        return array_unique($m[1]);
    }

    /**
     * El cuerpo de la primera regla que casa con el selector dado, o `null` si no
     * existe. Se usa para poder preguntar por UNA propiedad de UNA regla («¿esta
     * declara `border-left`?») en vez de buscar el texto suelto por el archivo,
     * que encontraría también el comentario que explica por qué no está.
     */
    private static function bloqueCss(string $selector): ?string
    {
        $patron = '/' . str_replace('/', '\\/', preg_quote($selector, '/')) . '\s*\{([^}]*)\}/';
        if (preg_match($patron, self::css(), $m) !== 1) {
            return null;
        }
        // Se quitan los comentarios de bloque: el por qué de cada cambio va escrito
        // dentro de la regla (aquí, «antes 2.35rem…»), y un test que busca «¿esta
        // declara esto?» tiene que leer las DECLARACIONES, no la explicación.
        return (string) preg_replace('#/\*.*?\*/#s', '', $m[1]);
    }

    /**
     * La auditoría que encontró las trece: toda clase de la app que el builder
     * emite tiene que existir como selector en `app.css`, salvo los ganchos
     * declarados arriba. En cuanto alguien pinta algo con una clase nueva y
     * olvida el CSS, esto se pone rojo.
     */
    public function testCadaClaseDeLaAppQueEmiteElBuilderTieneCss(): void
    {
        $definidas = self::clasesDefinidas();
        $faltan = [];
        foreach (self::clasesEmitidas() as $clase) {
            if (!self::esDeLaApp($clase) || in_array($clase, $definidas, true)) {
                continue;
            }
            $faltan[] = $clase;
        }
        sort($faltan);

        assertSame(
            [],
            array_values(array_diff($faltan, array_keys(self::GANCHOS_SIN_CSS))),
            'clases de la app que builder.js emite y app.css no define (' . implode(', ', $faltan) . '). Si la clase es un gancho de JS, declárala en GANCHOS_SIN_CSS con su motivo; si es estilo, escribe la regla'
        );
        // Y al revés: un gancho declarado que ya tiene CSS es una excepción que
        // sobró, y deja de ser una excepción.
        $obsoletas = array_values(array_intersect(array_keys(self::GANCHOS_SIN_CSS), $definidas));
        assertSame([], $obsoletas, 'estos ganchos ya tienen regla en app.css: quítalos de GANCHOS_SIN_CSS');
    }

    /**
     * La miniatura de los tipos de encuesta es la que salió cruda, así que se
     * fija por tipo y no solo por el recuento: si mañana alguien vuelve a
     * emitir `vf-nps-num` (la clase que no existía), esto lo dice con nombre.
     */
    public function testLaMiniaturaDeEncuestaTieneFormaEnLaTarjeta(): void
    {
        $css = self::css();
        foreach ([
            '.nps-dot'      => 'los botones de la escala 0-10 de la miniatura',
            '.scale-dot'    => 'los botones de una escala 1..N',
            '.star-icon'    => 'las estrellas',
            '.ranking-pos'  => 'la insignia de posición de una opción del ranking',
            '.vf-matrix-preview' => 'la matriz en miniatura',
        ] as $selector => $que) {
            assertTrue(
                str_contains($css, $selector),
                'app.css define ' . $selector . ' (' . $que . '); sin ella sale como texto suelto'
            );
        }
    }

    /**
     * El preview en vivo reutiliza el markup del fill. La razón es de fidelidad
     * y de mantenimiento: si el preview trae su propio HTML, cada cambio en el
     * formulario obliga a cambiarlo otra vez, y el primero que se olvide es el
     * que nadie nota porque «se ve bien en el preview».
     */
    public function testElPreviewEnVivoReutilizaElMarkupDelFill(): void
    {
        $js = self::js();

        // El preview vive en `previewBody()`; se recorta esa función para no
        // estar atado a las clases de la miniatura de la tarjeta, que sí tienen
        // su propio markup a propósito.
        // El recorte va de `previewBody()` a la siguiente función: un tamaño
        // fijo se quedaría corto en cuanto un `case` ocupe más líneas, y un
        // `strpos` sin límite metería en el examen el resto del archivo.
        $ini = strpos($js, 'function previewBody(');
        assertTrue($ini !== false, 'sigue existiendo previewBody() en builder.js');
        $fin = strpos($js, 'function openLivePreview(', (int) $ini);
        assertTrue($fin !== false, 'previewBody() sigue antes de openLivePreview()');
        $cuerpo = self::sinComentarios((string) substr($js, (int) $ini, (int) $fin - (int) $ini));

        foreach (['nps-option', 'scale-btn', 'star-option', 'star-btn', 'vf-matrix-cell', 'vf-ranking-pos', 'vf-ranking-input'] as $clase) {
            assertTrue(
                str_contains($cuerpo, $clase),
                'el preview en vivo usa ' . $clase . ' (la misma clase que el fill)'
            );
        }
        foreach (['vf-nps-num', 'vf-matrix-preview', 'style="min-width'] as $resto) {
            assertTrue(
                !str_contains($cuerpo, $resto),
                'el preview en vivo ya no trae ' . $resto . ': es markup suyo que se veía distinto del fill'
            );
        }
    }

    /**
     * `margin: auto .5rem` empuja el icono a la derecha de la fila (`.vf-section-inner`
     * es flex) y es como se ve el encabezado del fill. Con `0 auto` el icono se
     * centraba y el título bailaba: el mismo selector llega al fill y al preview.
     */
    public function testElIconoDeSeccionVaALaDerecha(): void
    {
        $css = self::css();

        // El icono no lleva margen propio: la alineación es de la fila, no del
        // icono. Cuando la llevaba (`margin: auto .5rem`) la posición dependía de
        // él, y como el `fill` y el preview traían los hijos en orden distinto
        // (icono primero el fill, título primero el preview), el margen era lo
        // que los hacía coincidir: se tocaba el orden y el icono cambiaba de
        // lado en un sitio sin que se viera el motivo.
        assertTrue(
            preg_match('/\.vf-section-icon\s*\{[^}]*margin:/', $css) !== 1,
            '.vf-section-icon no declara margin: el hueco lo reparte la fila'
        );
        assertTrue(
            preg_match('/\.vf-section-inner\s*\{[^}]*justify-content:\s*space-between/', $css) === 1,
            '.vf-section-inner reparte el sobrante (space-between): título a la izquierda, icono a la derecha'
        );

        // Y el orden de los hijos, en las DOS superficies: con `space-between` lo
        // que decide el lado es cuál va el último, así que el preview tiene que
        // acabar en icono igual que el fill.
        $fill = self::sinComentarios((string) file_get_contents(self::root() . '/views/fill/show.php'));
        $ini = strpos($fill, 'class="vf-section-inner"');
        assertTrue($ini !== false, 'el fill sigue montando la fila de la sección');
        $fila = (string) substr($fill, (int) $ini, 400);
        assertTrue(
            strpos($fila, 'vf-section-title') < strpos($fila, 'vf-section-icon'),
            'en el fill el título va antes que el icono'
        );

        $js = self::sinComentarios(self::js());
        $ini = strpos($js, "if (f.type === 'section') {", (int) strpos($js, 'function buildPreviewHtml('));
        assertTrue($ini !== false, 'sigue el preview de sección en buildPreviewHtml()');
        $fila = (string) substr($js, (int) $ini, 1200);
        assertTrue(
            strpos($fila, 'vf-section-title') < strpos($fila, 'vf-section-icon'),
            'y en el preview también: por eso los dos salen con el icono a la derecha'
        );
    }

    /**
     * El preview de sección usa la MISMA estructura que el fill (`.vf-section-inner`
     * con título a la izquierda e icono a la derecha), no un `text-center` con el
     * icono encima.
     */
    public function testElPreviewDeSeccionUsaVfSectionInner(): void
    {
        $js = self::js();
        $ini = strpos($js, "if (f.type === 'section') {", (int) strpos($js, 'function buildPreviewHtml('));
        assertTrue($ini !== false, 'sigue el preview de sección en buildPreviewHtml()');
        $bloque = self::sinComentarios((string) substr($js, (int) $ini, 1200));

        assertTrue(str_contains($bloque, 'vf-section-inner'), 'el preview de sección reutiliza `.vf-section-inner`');
        assertTrue(!str_contains($bloque, 'text-center'), 'y no lo centra: el icono va a la derecha, como en el fill');
    }

    /**
     * Un salto de página sin etiqueta no inventa «Página N». El `aria-label` del
     * bloque y el badge del pie ya dicen «Paso 2 de 3»: el encabezado de relleno
     * repetía lo mismo dos veces y no decía nunca el contenido del paso.
     */
    public function testElSaltoDePaginaSinEtiquetaNoInventaTitulo(): void
    {
        $fill = self::sinComentarios((string) file_get_contents(self::root() . '/views/fill/show.php'));
        assertTrue(
            !str_contains($fill, "ui.page_title"),
            'views/fill/show.php ya no inventa el título de un salto sin etiqueta'
        );
        assertTrue(
            str_contains($fill, 'ui.paso') && str_contains($fill, 'ui.de'),
            'el nombre accesible del paso («Paso 2 de 3») sigue siendo el del `aria-label`'
        );

        foreach (['es', 'en'] as $idioma) {
            $lang = (string) file_get_contents(self::root() . '/app/lang/' . $idioma . '.php');
            assertTrue(
                !str_contains($lang, 'ui.page_title'),
                'la clave ' . $idioma . '.php `ui.page_title` se borra con su uso: sin leer, es ruido'
            );
        }
    }

    /**
     * Los hijos de una sección (los dos, el del builder y el del fill) van sobre
     * el fondo de la sección y SIN la línea vertical de la izquierda. Con el
     * `border-left` de 3px y su `padding-left` de 1rem, el borde se confundía con
     * el de la propia tarjeta y hacía un doble escalón. Se fija en los dos sitios
     * porque son clases distintas y se corrigió solo una la primera vez.
     */
    public function testLosHijosDeUnaSeccionNoRepitenElBordeDeLaTarjeta(): void
    {
        foreach ([
            '.app-section-children' => 'el lienzo del builder',
            '.vf-section-children'  => 'la página pública',
        ] as $selector => $donde) {
            $bloque = self::bloqueCss($selector);
            assertTrue($bloque !== null, 'app.css declara ' . $selector . ' (' . $donde . ')');
            foreach (['border-left', 'padding-left'] as $propiedad) {
                assertTrue(
                    !preg_match('/(?:^|;|\s|\{)' . $propiedad . '\s*:/', (string) $bloque),
                    $selector . ' (' . $donde . ') no declara ' . $propiedad . ': el borde de 3px con su hueco de 1rem se confundía con el de la tarjeta de la sección'
                );
            }
        }

        // El fondo queda SOLO en el lienzo del builder. Ahí el bloque es la zona
        // de trabajo y el tinte dice dónde acaba la sección; en el formulario
        // publicado la sección ya trae su encabezado y su línea, y encima el tinte
        // solo hacía que el formulario pareciera dividido en dos.
        assertTrue(
            str_contains((string) self::bloqueCss('.app-section-children'), 'background'),
            'el lienzo del builder conserva el fondo, que es lo que separa el bloque de la tarjeta'
        );
        assertTrue(
            !preg_match('/(?:^|;|\s)background\s*:/', (string) self::bloqueCss('.vf-section-children')),
            'los hijos en la página pública NO llevan fondo: la sección ya tiene su encabezado y su línea'
        );
    }

    /**
     * La banda que dibuja el «+» de inserción entre tarjetas estaba desplazada
     * `2.35rem` a la derecha, que es el hueco reservado en la cabecera para las
     * herramientas (duplicar, configurar, eliminar): la línea y el botón se
     * colgaban del borde derecho de las tarjetas sin nada que lo justificara, y
     * se notaba sobre todo en el lienzo del builder, que sí tiene fondo.
     *
     * Se ata el `left: 0` del `.app-canvas-add` y que el «Agregar dentro» ya no
     * reste ni mida el ancho del hueco, porque los dos medían la misma
     * constante y por eso hay que moverlos a la vez.
     */
    public function testLaBandaDeInsercionNoSeDesplazaDelBordeDeLaTarjeta(): void
    {
        $banda = self::bloqueCss('.app-canvas-add');
        assertTrue($banda !== null, 'app.css declara .app-canvas-add');
        assertTrue(
            preg_match('/(?:^|;|\s)left:\s*0\s*;/', (string) $banda) === 1,
            'la banda arranca en el borde de la tarjeta (`left: 0`), no en el hueco de las herramientas'
        );
        assertTrue(
            !str_contains((string) $banda, '2.35rem'),
            'y no lleva el desplazamiento de 2.35rem en ninguna propiedad'
        );

        $control = self::bloqueCss('.app-add-control');
        assertTrue($control !== null, 'app.css declara .app-add-control');
        assertTrue(
            str_contains((string) $control, 'max-width: 100%;'),
            '«Agregar dentro» ocupa el ancho de la tarjeta (`max-width: 100%`)'
        );
        assertTrue(
            !str_contains((string) $control, '2.35rem'),
            'y no descuenta el hueco de las herramientas'
        );
    }

    /**
     * Los siete grupos de «Ajustes del formulario» se pliegan con un botón que
     * lleva `aria-expanded`, así que el estado se ve en el icono del chevron: sin
     * esa regla el caret se queda apuntando hacia abajo con el grupo abierto y
     * no hay forma de distinguir un estado del otro en la pantalla.
     *
     * Es el mismo contrato que el resto del archivo, aplicado al lado del panel:
     * lo que el marcado emite tiene que existir como selector en `app.css`.
     */
    public function testLosGruposColapsablesTienenBotonYCaretConEstado(): void
    {
        $boton = self::bloqueCss('.app-settings-group .app-group-toggle');
        assertTrue($boton !== null, 'app.css declara .app-settings-group .app-group-toggle');
        foreach (['display: flex', 'width: 100%', 'cursor: pointer'] as $declaracion) {
            assertTrue(
                str_contains((string) $boton, $declaracion),
                'el botón del grupo declara ' . $declaracion . ' (es el único control del plegado)'
            );
        }
        assertTrue(
            preg_match('/\.app-group-toggle:focus-visible\s*\{[^}]*box-shadow/s', self::css()) === 1,
            'el botón de un grupo se puede usar con teclado: :focus-visible con anillo, no outline:none a secas'
        );

        $caret = self::bloqueCss('.app-group-caret');
        assertTrue($caret !== null, 'app.css declara .app-group-caret');
        assertTrue(
            str_contains((string) $caret, 'transition: transform'),
            'el chevron anima su giro en vez de saltar de golpe'
        );
        assertTrue(
            preg_match('/aria-expanded="true"[^}]*\.app-group-caret\s*\{[^}]*transform/s', self::css()) === 1,
            'y hay una regla que gira el chevron cuando `aria-expanded="true"`: el icono dice en qué estado está el grupo'
        );
    }

    /**
     * El botón del NPS es un `<span class="scale-btn">`, y a un elemento inline
     * `width`/`height` no le aplican: sin `display`, el `width: 40px` de la regla
     * del NPS no se aplicaba y las opciones salían como el número suelto pegado
     * al borde. La regla de `.scale-selector` sí lo declaraba, y como el NPS
     * reutiliza la clase, el estilo venía de la del scale o de ningún sitio.
     *
     * Se ata el `display` y el centrado porque son la diferencia entre «un botón»
     * y «un dígito con un borde alrededor».
     */
    public function testElBotonDelNpsEsUnaCajaYNoUnNumeroSuelto(): void
    {
        $bloque = self::bloqueCss('.nps-selector .scale-btn');
        assertTrue($bloque !== null, 'app.css declara .nps-selector .scale-btn');
        assertTrue(
            preg_match('/(?:^|;|\s)display:\s*inline-flex/', (string) $bloque) === 1,
            'el botón del NPS declara `display: inline-flex`; sin display el width/height de un <span> inline no se aplican y sale el número suelto'
        );
        foreach (['align-items', 'justify-content'] as $propiedad) {
            assertTrue(
                str_contains((string) $bloque, $propiedad . ':'),
                'y ' . $propiedad . ' para que el número quede centrado en la caja'
            );
        }
    }

    /**
     * Un salto de paso nace con «Página N», como la sección con «Nueva sección».
     *
     * Se quitó y se ha vuelto a poner, así que vale la pena dejar por qué está:
     * `fields.label` es contenido y lo que se guarda es lo que se publica, así
     * que el número es un punto de partida para sobrescribir, no un rótulo que
     * imponga el builder. Lo que NO se hace es lo contrario: el fill no vuelve a
     * fabricar «Página N» cuando el editor borra el título
     * (`testElSaltoDePaginaSinEtiquetaNoInventaTitulo`), porque entonces el
     * relleno sería imposible de quitar.
     *
     * Y quien edita lo ve antes de publicar: en el constructor el paso se
     * distingue de los demás campos por su número, no por un «Sin título» o un
     * «Página» a secas.
     */
    public function testElSaltoDePasoNaceConSuNumeroDePagina(): void
    {
        $js = self::sinComentarios(self::js());
        assertTrue(
            str_contains($js, "'Página ' + (fields.filter((x) => x.type === 'page').length + 1)"),
            'un salto de página nuevo nace con «Página N», como la sección con «Nueva sección»'
        );

        // El N es el de páginas que YA hay, más uno. La alternativa —el número de
        // posición en el formulario— cuenta cosas que no son pasos: las secciones
        // se intercalan y el primer bloque es implícito, así que el orden del
        // lienzo no es el número del paso.
        assertTrue(
            !str_contains($js, "'Página ' + (fields.length + 1)"),
            'y no se confunde con la posición en el lienzo, que cuenta secciones y el bloque implícito'
        );

        // La tarjeta sigue cayendo en el nombre del tipo cuando el editor borra
        // el título a mano: es el mismo dato, no un rótulo aparte.
        $ini = strpos($js, 'function cardHeadHtml(');
        assertTrue($ini !== false, 'sigue existiendo cardHeadHtml() en builder.js');
        $bloque = (string) substr($js, (int) $ini, 700);
        assertTrue(
            str_contains($bloque, "f.type === 'page'"),
            'la tarjeta del salto de paso se identifica por el nombre del tipo («Página») cuando no hay etiqueta'
        );
    }

    /**
     * La migración 0012 se queda, aunque el builder vuelva a escribir «Página N».
     * Antes limpiaba un relleno que el builder había retirado y ya no iba a
     * aparecer nunca más; ahora limpia el que hay en las instalaciones que
     * llevan tiempo usando la app, que es justo donde se accumulated. El patrón
     * sigue siendo estrecho y es lo importante: una etiqueta escrita a mano
     * («Primera página») es contenido y se respeta.
     *
     * Su alcance son las filas de siempre: los saltos que se creen después
     * nacen con su número y no se vuelven a tocar. Por eso se documenta aquí y no
     * se borra, para que nadie la lea como un sitio donde el builder ya no
     * inventa nada.
     */
    public function testLaMigracionDelSaltoDePasoSoloBorraElRellenoDelBuilder(): void
    {
        $db = \App\Core\Database::getInstance(\App\Core\Config::db());
        $archivo = self::root() . '/database/migrations/0012_page_label_placeholder.php';
        assertTrue(is_file($archivo), 'existe la migración que limpia el relleno de los saltos de paso');
        $migra = require $archivo;
        assertTrue(is_callable($migra), 'la migración devuelve una closure');

        $suffix = bin2hex(random_bytes(4));
        $userId = \App\Models\User::create('Test Página', 'pagina-' . $suffix . '@test.local', 'password');
        $formId = \App\Models\Form::create((int) $userId, 'Salto @test.local');
        $relleno = \App\Models\Field::create($formId, 'page', 'Página 3', '', '', false, [], [], 1);
        $propia  = \App\Models\Field::create($formId, 'page', 'Primera página', '', '', false, [], [], 2);
        $paso    = \App\Models\Field::create($formId, 'page', '', '', 'Una descripción', false, [], [], 3);

        try {
            $migra($db);
            // Re-ejecutarla no cambia nada: es idempotente.
            $migra($db);

            assertSame('', (string) (\App\Models\Field::find($relleno)['label'] ?? '?'), '«Página 3» era relleno del builder y se borra');
            assertSame('Primera página', (string) (\App\Models\Field::find($propia)['label'] ?? '?'), 'una etiqueta escrita a mano se respeta');
            assertSame('', (string) (\App\Models\Field::find($paso)['label'] ?? '?'), 'un salto sin etiqueta se queda como estaba');
            assertSame('Una descripción', (string) (\App\Models\Field::find($paso)['help_text'] ?? '?'), 'y la descripción nunca se toca: esa sí es contenido');
        } finally {
            \App\Models\Form::delete($formId);
            \App\Models\User::delete((int) $userId);
        }
    }

    /**
     * El elemento página es la caja con icono, título y descripción (`.vf-page-head`)
     * y tenía una caja crema con borde punteado encima: un aviso («esto es un
     * separador») dentro del formulario publicado. Se separa ahora como una
     * sección, con su línea fina, y se conserva el ámbar del icono porque un paso
     * y una sección no son lo mismo.
     */
    public function testLaCabeceraDePaginaNoTieneFondoNiCaja(): void
    {
        $bloque = self::bloqueCss('.vf-page-head');
        assertTrue($bloque !== null, 'existe la regla de .vf-page-head');
        $cuerpo = (string) $bloque;
        assertTrue(!str_contains($cuerpo, 'background'), '.vf-page-head no pinta fondo');
        assertTrue(!str_contains($cuerpo, 'border-radius'), 'sin radio: la caja ya no existe');
        assertTrue(!preg_match('/border\s*:/', $cuerpo), 'sin caja: la separación la hace la línea de arriba');
        assertTrue(
            str_contains($cuerpo, 'border-top: 1px solid var(--vf-border)'),
            'la cabecera de página se separa con la misma línea fina que la de sección'
        );

        $icono = self::bloqueCss('.vf-page-icon');
        assertTrue($icono !== null && str_contains((string) $icono, '#fef3c7'), 'el icono conserva su ámbar: un paso no es una sección');
    }

    /**
     * El preview del builder pintaba la página como una insignia centrada
     * (`.vf-page-divider`), un tercer diseño que no se parecía en nada ni a la
     * caja del formulario ni a la cabecera de sección. Ahora reutiliza el markup del
     * fill, y con el ajuste apagado no pinta caja: el preview no puede prometer una
     * cabecera que luego no aparece.
     */
    public function testElPreviewDelSaltoDePaginaReutilizaLaCabeceraDelFill(): void
    {
        $js = self::sinComentarios(self::js());
        assertTrue(
            !str_contains($js, 'vf-page-divider'),
            'builder.js ya no emite .vf-page-divider: el preview usa el markup del formulario final'
        );
        assertTrue(!str_contains(self::sinComentarios(self::css()), 'vf-page-divider'), 'y su CSS sobra');

        $ini = strpos($js, 'function buildPreviewHtml(');
        assertTrue($ini !== false, 'el preview sigue dibujando el salto de página');
        // Ventana generosa a propósito: el salto de página quedó más abajo en la
        // función cuando se le añadió el criterio de contenido, y una ventana
        // justa pasaba a mirar otro trozo sin avisar.
        $bloque = (string) substr($js, (int) $ini, 4000);
        assertTrue(str_contains($bloque, "class=\"vf-page-head\""), 'el preview emite la misma caja que el fill');
        assertTrue(str_contains($bloque, 'f.show_header === false'), 'y respeta el ajuste de cabecera');
        assertTrue(str_contains($bloque, 'vf-page-break'), 'el separador de paso es una clase con CSS, no texto suelto');
    }

    /**
     * El preview y el formulario publicado tienen que **decidir lo mismo** sobre
     * la cabecera de un salto de página, no solo emitir las mismas clases.
     *
     * El segundo criterio es el que se olvidó: el fill solo pinta la caja si el
     * paso tiene título o descripción, y el preview la pintaba siempre que el
     * interruptor estuviera encendido. El síntoma era el peor posible para
     * diagnosticar —el icono se veía en el constructor y en el formulario
     * publicado no había nada—, porque las dos pantallas usan markup y clases
     * idénticos y solo se diferencian en una condición que nadie compara.
     *
     * Se ata por predicado, no por texto: cada lado tiene que mencionar las tres
     * cosas que deciden (el interruptor, el título y la descripción) en la misma
     * rama del salto de página. El `trim()` va en la lista porque el fill recorta
     * y una etiqueta de solo espacios es sin título; si un lado dejara de
     * recortar, el otro volvería a prometer una caja vacía.
     */
    public function testElPreviewYElFillDecidenIgualCuandoPintaLaCabeceraDelPaso(): void
    {
        $js = self::sinComentarios(self::js());
        $ini = strpos($js, 'function buildPreviewHtml(');
        $bloque = (string) substr($js, (int) $ini, 4000);

        assertTrue(str_contains($bloque, 'show_header === false'), 'el preview mira el interruptor');
        assertTrue(str_contains($bloque, "String(f.label || '').trim()"), 'el preview recorta el título');
        assertTrue(str_contains($bloque, "String(f.help_text || '').trim()"), 'y la descripción');
        assertTrue(
            str_contains($bloque, "titulo === '' && desc === ''"),
            'la caja solo se pinta si hay título o descripción, igual que en el fill'
        );

        // Y el lado del fill, para que el par no se pueda mover de uno en uno.
        $fill = self::sinComentarios((string) file_get_contents(self::root() . '/views/fill/show.php'));
        assertTrue(
            str_contains($fill, "trim((string) (\$pageField['label'] ?? ''))"),
            'el fill recorta el título del salto de página'
        );
        assertTrue(
            str_contains($fill, "\$title !== '' || \$desc !== ''"),
            'y solo pinta la cabecera si hay algo que decir'
        );

        // Y el icono va al final en las DOS superficies, que es lo que decide su
        // lado con `space-between`: título primero, icono después, en el fill y
        // en el preview. Con el icono primero, el relleno sobrante lo empujaba a
        // la izquierda y el constructor se veía distinto del publicado.
        $fill = (string) file_get_contents(self::root() . '/views/fill/show.php');
        $ini = strpos($fill, 'class="vf-page-head"');
        assertTrue($ini !== false, 'el fill sigue montando la cabecera del paso');
        // 1200 y no 500 porque entre el `header` y el icono hay un comentario que
        // explica el orden; con una ventana justa la aserción miraba al vacío y
        // `strpos` devolvía `false`, que es menor que todo y pasaba de largo.
        $fila = (string) substr($fill, (int) $ini, 1200);
        assertTrue(
            strpos($fila, 'vf-page-title') < strpos($fila, 'vf-page-icon'),
            'en el fill el título va antes que el icono'
        );
        assertTrue(
            strpos($fila, 'vf-page-icon') < strpos($fila, 'vf-page-desc') + 400,
            'y el icono cierra la cabecera'
        );
        $fila = (string) substr($js, (int) strpos($js, "class=\"vf-page-head\""), 500);
        assertTrue(
            strpos($fila, 'vf-page-title') < strpos($fila, 'vf-page-icon'),
            'y en el preview también, que es de donde viene la mitad de las diferencias'
        );
        assertTrue(
            preg_match('/\.vf-page-head\s*\{[^}]*justify-content:\s*space-between/', self::css()) === 1,
            'la fila del paso reparte el sobrante, igual que la de la sección'
        );

        // El aviso del panel: el interruptor no inventa contenido, así que sin
        // título ni descripción tiene que explicar por qué no se ve nada, en vez
        // de dejar que parezca roto.
        $panel = self::sinComentarios((string) file_get_contents(self::root() . '/views/builder/index.php'));
        assertTrue(str_contains($panel, 'id="pageHeaderHint"'), 'el interruptor avisa de que no inventa el título');
        assertTrue(
            str_contains($js, 'function updatePageHeaderHint()'),
            'y el aviso se recalcula en el cliente en vez de quedar pegado al primer pintado'
        );
        // El aviso depende del título y de la descripción: si no los escucha,
        // seguiría diciendo «no hay nada que mostrar» con el título ya escrito, y
        // al revés se callaría con la etiqueta recién borrada.
        assertTrue(
            str_contains($js, "['fieldLabel', 'fieldHelp'].forEach"),
            'el aviso se refresca al escribir el contenido del que depende'
        );
        assertTrue(
            str_contains($js, "el.fieldPageHeader.addEventListener('change', updatePageHeaderHint)"),
            'y al mover el interruptor'
        );
    }

    /**
     * El botón de enviar lleva su propio color de texto porque NO lleva la clase
     * `btn` de Bootstrap: sin ella el color es el del navegador, que es negro
     * sobre un degradado que acaba en `#7c3aed`. Se leyó como un detalle, pero es
     * el texto de la acción principal del formulario y el degradado es el fondo
     * más oscuro de toda la página.
     *
     * Se fija en claro y no «el que toque»: el degradado siempre acaba en
     * `#7c3aed`, así que claro es el único color que se lee en toda la barra. El
     * icono de papel hereda `currentColor` y no necesita regla propia.
     *
     * El cuerpo de la regla se lee con `bloqueCss()` y no con `sinComentarios()`,
     * y no es una manía de estilo. Este test estaba escrito con el segundo, que
     * borra las líneas que empiezan por `//`, que es justo lo que había en medio
     * de esta regla: pasaba porque el helper tiraba lo que tenía que comprobar. Y
     * un comentario de línea dentro de un bloque de declaraciones no es CSS —el
     * parser lo descarta y se come la declaración de al lado—, así que la última
     * aserción ata que no vuelva a colarse.
     */
    public function testElBotonDeEnviarTieneElTextoEnClaro(): void
    {
        $bloque = self::bloqueCss('.vf-submit');
        assertTrue($bloque !== null, 'existe la regla de .vf-submit');
        $cuerpo = (string) $bloque;

        assertTrue(
            preg_match('/(?:^|;|\s)color:\s*(#fff|#ffffff|white|rgba?\([^)]*255|var\(--vf-on-grad)/i', $cuerpo) === 1,
            '.vf-submit declara un texto en claro (o el token que lo controla): sin clase `btn` el color sería el del navegador (negro) sobre el degradado'
        );
        assertTrue(
            str_contains($cuerpo, 'linear-gradient') && str_contains($cuerpo, 'var(--vf-grad'),
            'y el degradado sale de los tokens de marca, que sostienen ese texto'
        );
        assertTrue(
            preg_match('/(?:^|;|\s|\})\/\//m', $cuerpo) !== 1,
            'dentro de .vf-submit no hay comentarios de línea: no son CSS y se comen la declaración siguiente (van con /* … */)'
        );
    }

    /**
     * El botón que se ve en el constructor tiene que ser el que se publica.
     *
     * El preview en vivo pintaba el suyo: `<button class="btn btn-lg
     * app-btn-primary">`. `app-btn-primary` aporta alto, radio y elevación pero NO
     * color —está escrito así a propósito, para que el color lo ponga el destino—,
     * así que salía un botón claro con el texto del navegador encima de un
     * degradado morado: negro sobre morado. Y como tampoco era `.vf-submit`, la
     * regla que lo arregla no le llegaba nunca. Dos pantallas con el mismo
     * formulario y dos botones distintos, que es como este fallo pasa desapercibido
     * en el constructor y salta en el formulario final.
     */
    public function testElPreviewEnVivoUsaElBotonDeEnviarDelFill(): void
    {
        $js = self::sinComentarios(self::js());
        $ini = strpos($js, 'function previewBody(');
        assertTrue($ini !== false, 'sigue existiendo previewBody() en builder.js');
        $fin = strpos($js, 'function openLivePreview(', (int) $ini);
        assertTrue($fin !== false, 'previewBody() sigue antes de openLivePreview()');
        $cuerpo = (string) substr($js, (int) $ini, (int) $fin - (int) $ini);

        foreach (['vf-submit-row', 'class="vf-submit"', 'vf-submit-label', 'fa-paper-plane'] as $pieza) {
            assertTrue(
                str_contains($cuerpo, $pieza),
                'el preview en vivo pinta el botón del fill (`' . $pieza . '`), no el suyo'
            );
        }
        foreach (['btn btn-lg', 'app-btn-primary'] as $suyo) {
            assertTrue(
                !str_contains($cuerpo, $suyo),
                'el preview en vivo ya no pinta su propio botón (`' . $suyo . '`): `app-btn-primary` pone fondo y NO color, así que el texto salía negro'
            );
        }

        // Y que el otro lado siga siendo el del fill, para que el par no se pueda
        // mover de uno en uno: el mismo contrato que las cabeceras del salto.
        $fill = self::sinComentarios((string) file_get_contents(self::root() . '/views/fill/show.php'));
        foreach (['class="vf-submit"', 'vf-submit-label', 'fa-paper-plane'] as $pieza) {
            assertTrue(
                str_contains($fill, $pieza),
                'views/fill/show.php sigue emitiendo `' . $pieza . '` en el botón de enviar'
            );
        }
    }

    /**
     * El modal de «Agregar elemento» tiene que caber en un móvil.
     *
     * En un teléfono se veía el fondo oscurecido sin que apareciera el diálogo, y
     * no había forma de cerrarlo salvo recargar. La causa eran tres cosas a la vez,
     * y por eso el test ata las tres y no solo una:
     *
     *   - la rejilla medía `minmax(9.5rem, 1fr)` = 152px, y el cuerpo del modal en
     *     un móvil de 360px mide ~296px: cabía UNA columna, y 21 tipos en columna
     *     son ~1700px de alto;
     *   - `.modal-dialog-scrollable` mide en `100%` de un elemento `position:
     *     fixed`, que es el viewport GRANDE (con la barra de direcciones
     *     desplegada), no el que se está viendo;
     *   - `.modal-dialog-centered` centra con `align-items: center`, así que el
     *     desborde cae por ARRIBA, y arriba no scrollea nadie: el `overflow-y`
     *     está en `.modal-body`, dentro del diálogo.
     *
     * De ahí los tres síntomas que se reportaron: no se ve, no se cierra (se toca
     * el diálogo y no el fondo del modal) y «a veces funciona» según esté la barra
     * desplegada. Los otros dos modales del constructor no se rompían porque usan
     * las mismas clases de diálogo pero no tienen una rejilla de 21 baldosas.
     *
     * El tope se ata a `dvh` y no solo a `vh` porque `dvh` es el viewport real, y
     * era justo el `100%` de Bootstrap lo que no servía para nada.
     */
    public function testElDialogoDeTiposNoSaleDeLaPantallaEnMovil(): void
    {
        $movil = self::bloquesMedia('(max-width: 575.98px)');
        assertTrue($movil !== '', 'app.css declara un bloque @media (max-width: 575.98px)');

        // El mínimo de la rejilla, tal y como lo ve cada pantalla. La regla de
        // escritorio se queda en 9.5rem a propósito: en un `modal-lg` de 800px
        // salen tres columnas cómodas y en un móvil de 360px NO sale ninguna
        // completa, que es justo lo que hay que tapar con una regla del móvil.
        preg_match('/\.app-type-grid\s*\{[^}]*minmax\(([0-9.]+)rem/', self::css(), $m);
        assertTrue(isset($m[1]), 'app.css declara la rejilla de tipos con su ancho mínimo');
        $escritorio = (float) $m[1];

        // El móvil tiene que declarar la SUYA. Es lo que ata el arreglo: si el
        // mínimo sube a 8.5rem o más, en un móvil de 360px vuelve a caber una
        // sola columna (el cuerpo del modal mide ~296px) y los 21 tipos se salen.
        preg_match('/\.app-type-grid\s*\{[^}]*minmax\(([0-9.]+)rem/', $movil, $mm);
        assertTrue(
            isset($mm[1]),
            'el móvil declara su propia rejilla de tipos: sin ella manda la de escritorio y solo cabía una columna'
        );
        assertTrue(
            (float) $mm[1] <= 8.0,
            'y su mínimo no pasa de 8rem (pide ' . $mm[1] . 'rem): por encima, en un móvil de 360px cabe una sola columna y los 21 tipos no entran'
        );
        assertTrue(
            (float) $mm[1] < $escritorio,
            'y es más estrecha que la de escritorio (' . $escritorio . 'rem), que en un modal de 800px sí sale en tres columnas'
        );
        assertTrue(
            preg_match('/#addFieldModal[^{]*\.modal-dialog\s*\{[^}]*align-items:\s*flex-start/', $movil) === 1,
            'el diálogo se ancla arriba en móvil: centrado, el desborde cae por arriba, donde no scrollea nadie'
        );
        assertTrue(
            preg_match('/#addFieldModal[^{]*\.modal-content\s*\{[^}]*max-height:[^;]*dvh/', $movil) === 1,
            'y su alto se topa con `dvh`, que es el viewport que se ve: el `100%` de Bootstrap es el grande, con la barra desplegada'
        );
    }

/**
     * El cuerpo de todos los bloques `@media` de una condición, concatenados.
     *
     * Hace falta contar llaves y no recortar hasta el siguiente `@media`, porque
     * un bloque puede anidar otro (`prefers-reduced-motion` dentro de un
     * `max-width`) y el corte por texto se llevaría por delante reglas que no son
     * suyas. Los comentarios se quitan antes de contar porque una llave escrita
     * dentro de una explicación descuadra el conteo, y el bloque se corta en el
     * sitio que no toca: el test passaría por la mitad de las reglas.
     */
    private static function bloquesMedia(string $condicion): string
    {
        // Sin comentarios: una llave escrita dentro de una explicación descoloca
        // el conteo y el bloque se corta en el sitio que no toca.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', self::css());
        $salida = '';
        $offset = 0;
        $prefijo = '@media ' . $condicion;
        while (($ini = strpos($css, $prefijo, $offset)) !== false) {
            $apertura = strpos($css, '{', $ini);
            if ($apertura === false) {
                break;
            }
            $nivel = 0;
            $cierre = null;
            for ($i = $apertura, $n = strlen($css); $i < $n; $i++) {
                if ($css[$i] === '{') {
                    $nivel++;
                } elseif ($css[$i] === '}') {
                    $nivel--;
                    if ($nivel === 0) {
                        $cierre = $i;
                        break;
                    }
                }
            }
            if ($cierre === null) {
                break;
            }
            $salida .= "\n" . substr($css, $apertura + 1, $cierre - $apertura - 1);
            $offset = $cierre + 1;
        }
        return $salida;
    }

    private static function esDeLaApp(string $clase): bool
    {
        foreach (self::PREFIJOS_APP as $prefijo) {
            if (str_starts_with($clase, $prefijo)) {
                return true;
            }
        }
        return false;
    }
}
