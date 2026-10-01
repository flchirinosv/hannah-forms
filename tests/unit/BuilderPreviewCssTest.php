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
        return $m[1];
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
        assertTrue(
            preg_match('/\.vf-section-icon\s*\{[^}]*margin:\s*auto\s+\.5rem;/', self::css()) === 1,
            '.vf-section-icon lleva `margin: auto .5rem` (icono a la derecha, con margen simétrico)'
        );
        assertTrue(
            preg_match('/\.vf-section-icon\s*\{[^}]*margin:\s*0\s+auto/', self::css()) !== 1,
            'y NO `margin: 0 auto`, que lo centra'
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
            assertTrue(
                str_contains((string) $bloque, 'background'),
                $selector . ' conserva el fondo, que es lo que separa el bloque de la tarjeta'
            );
        }
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
     * Un salto de paso no lleva etiqueta: `fields.label` es contenido, y lo que
     * el builder guarda es lo que se publica. Con «Página N» como etiqueta, el
     * formulario final pintaba un encabezado que no decía el contenido del paso.
     * El rótulo del editor lo pone el nombre del tipo al pintar la tarjeta.
     */
    public function testElBuilderNoInventaLaEtiquetaDeUnSaltoDePaso(): void
    {
        $js = self::sinComentarios(self::js());
        assertTrue(
            !str_contains($js, "'Página ' +"),
            'builder.js no fabrica la etiqueta «Página N» de un salto de paso: se guardaba y salía como título en el formulario final'
        );

        $ini = strpos($js, 'function cardHeadHtml(');
        assertTrue($ini !== false, 'sigue existiendo cardHeadHtml() en builder.js');
        $bloque = (string) substr($js, (int) $ini, 700);
        assertTrue(
            str_contains($bloque, "f.type === 'page'"),
            'la tarjeta del salto de paso se identifica por el nombre del tipo («Página»), no por «Sin título»'
        );
    }

    /**
     * Corregir el builder solo afecta a los saltos NUEVOS: los formularios que ya
     * tenían «Página N» guardado seguirían pintándolo. Esto ata la migración que
     * limpia ese relleno, y sobre todo que el patrón sea estrecho: una etiqueta
     * escrita a mano («Primera página») es contenido y se respeta.
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
