<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * La cabecera y la tarjeta de ajustes de la ficha: dos regresiones de maquetación.
 *
 * Las dos son de las que no dan ningún error: un botón de 32px al lado de uno de
 * 44 no falla, y un `col-md-3` dentro de una columna estrecha no falla —solo se
 * ve mal en una pantalla de escritorio—. Como no hay PHP ni JS que se caiga, la
 * única señal es visual, y es exactamente la clase de regla que este repo ata con
 * un test (lo mismo que `FillLayoutTest` con el ancho del fill).
 *
 * ## Lo que se fija
 *
 *  1. **Los cuatro controles del top de la ficha miden lo mismo.** La regla
 *     `.app-head-actions` sube los secundarios y el botón de menú al alto y al
 *     radio del CTA.
 *  2. **Dashboard, ficha y builder usan la MISMA fila de acciones.** La regla
 *     `.app-head-actions` solo engancha a quien lleva la clase: si el dashboard o
 *     el builder no la traen, sus botones se quedan en la altura de Bootstrap
 *     (~38px) al lado de los 44px de la ficha.
 *  3. **Respuestas, analítica y administración también la usan, sin `btn-sm`.** Antes
 *     sus cabeceras se quedaban en los ~32px del `btn-sm` al lado de los 44px del
 *     resto. La barra de filtros de analítica entra en la misma fila, así que sus
 *     controles toman el mismo alto y su `flex-wrap` / `width:auto` evitan que los
 *     44px desborden la pantalla: en tablet y en la franja 992–1023, donde el
 *     `nowrap` que se probó antes se salía de la vista.
 *  4. **Notificaciones y Webhook se reparten por el ancho de la TARJETA, no de
 *     la ventana.** Viven en una `col-lg-4` de ~400px: un `col-md-*` mira la ventana
 *     (≥768px) y ahí dentro metía tres o cuatro columnas enanas y dejaba un tercio
 *     de la fila vacío. Por eso el par es `col-sm-6` y la URL del webhook va a
 *     `col-12`.
 *
 * Sin BD: solo lee `app.css` y las vistas.
 */
class FormFichaLayoutTest
{
    private static function vista(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/dashboard/forms/show.php');
    }

    private static function plantilla(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/views/' . $rel);
    }

    private static function css(): string
    {
        $bruto = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
        // Sin comentarios: un selector citado en una nota no puede confundirse con
        // la regla de verdad.
        return (string) preg_replace('#/\*.*?\*/#s', '', $bruto);
    }

    /** Cuerpo entre llaves del bloque que empieza por $pre. */
    private static function bloque(string $pre): ?string
    {
        $css = self::css();
        $pos = strpos($css, $pre);
        if ($pos === false) {
            return null;
        }
        $abierto = strpos($css, '{', $pos);
        if ($abierto === false) {
            return null;
        }
        $nivel = 0;
        $cierre = null;
        for ($i = $abierto, $n = strlen($css); $i < $n; $i++) {
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
        return $cierre === null ? null : substr($css, $abierto + 1, $cierre - $abierto - 1);
    }

    public function testLosBotonesDelTopDeLaFichaMidenLoMismo(): void
    {
        $btn = self::bloque('.app-head-actions .btn');
        assertNotNull($btn, 'app.css tiene la regla que unifica la fila de acciones del top');
        assertSame(
            1,
            preg_match('/min-height:\s*var\(--vf-btn-cta-h\)/', (string) $btn),
            'los secundarios suben al alto del CTA (44px): antes convivían 44/38/32 en la misma fila'
        );
        assertSame(
            1,
            preg_match('/border-radius:\s*12px/', (string) $btn),
            'y al mismo radio, o los lados no casan aunque el alto coincida'
        );

        $dd = self::bloque('.app-head-actions .app-dd-btn');
        assertNotNull($dd, 'el botón de menú ⋯ también se acota en la cabecera de la ficha');
        assertSame(
            1,
            preg_match('/width:\s*var\(--vf-btn-cta-h\)/', (string) $dd),
            'icono cuadrado al lado del CTA, no un cuadro de 32 junto a pastillas de 44'
        );
        assertSame(
            1,
            preg_match('/height:\s*var\(--vf-btn-cta-h\)/', (string) $dd),
            'y con la misma altura'
        );

        // La fila real de la ficha trae los cuatro controles dentro del contenedor
        // `app-head-actions`: sin la clase en la vista, la regla no engancha a
        // ninguno y el test de CSS pasaría midiendo una regla muerta.
        $vista = self::vista();
        $desde = strpos($vista, 'app-head-actions');
        assertTrue($desde !== false, 'la vista de la ficha marca su fila de acciones con app-head-actions');
        $acciones = substr($vista, (int) $desde);
        $hasta = strpos($acciones, '<div class="row g-4">');
        assertTrue($hasta !== false, 'la fila de acciones va antes del cuerpo de la ficha');
        $acciones = substr($acciones, 0, (int) $hasta);

        foreach (['app-btn-primary', 'btn-outline-primary', 'btn-outline-success', 'app-dd-btn'] as $clase) {
            assertTrue(
                str_contains($acciones, $clase),
                'la fila de acciones de la ficha tiene que traer ' . $clase
            );
        }
    }

    public function testElDashboardYElBuilderUsanLaMismaFilaDeAcciones(): void
    {
        // El dashboard: el botón «Nuevo formulario» dentro de `app-head-actions`.
        // Sin la clase, `app-btn-create` solo aporta radio y peso y el botón se
        // queda en la altura por defecto de Bootstrap.
        $dash = self::plantilla('dashboard/index.php');
        $desde = strpos($dash, 'app-head-actions');
        assertTrue($desde !== false, 'el dashboard marca su acción de cabecera con app-head-actions');
        $bloque = substr($dash, (int) $desde);
        $bloque = substr($bloque, 0, (int) strpos($bloque, '</div>', (int) strpos($bloque, '</button>')));
        assertTrue(
            str_contains($bloque, 'app-btn-create'),
            'el botón «Nuevo formulario» va DENTRO de la fila de acciones (si no, la regla no lo engancha)'
        );

        // El builder: la fila entera de la cabecera (CTA, deshacer/rehacer, vista
        // previa, configuración, abrir y guardar) es una sola fila de acciones.
        $builder = self::plantilla('builder/index.php');
        $desde = strpos($builder, 'app-head-actions');
        assertTrue($desde !== false, 'el builder marca su fila de cabecera con app-head-actions');
        $bloque = substr($builder, (int) $desde);
        $hasta = strpos($bloque, 'builder-layout');
        assertTrue($hasta !== false, 'la fila de acciones del builder va antes del lienzo');
        $bloque = substr($bloque, 0, (int) $hasta);
        foreach (['btnSaveStructure', 'btnUndo', 'btnRedo', 'btnLivePreview', 'btnFormSettings'] as $id) {
            assertTrue(
                str_contains($bloque, $id),
                'la fila de acciones del builder tiene que traer ' . $id
            );
        }
    }

    public function testLosCamposDeAvisosYWebhookNoDependenDelAnchoDeLaVentana(): void
    {
        $vista = self::vista();

        $avisos = substr($vista, (int) strpos($vista, 'id="grp-avisos"'));
        $avisos = substr($avisos, 0, (int) strpos($avisos, 'id="grp-webhook"'));

        $webhook = substr($vista, (int) strpos($vista, 'id="grp-webhook"'));
        $webhook = substr($webhook, 0, (int) strpos($webhook, 'id="grp-gracias"'));

        foreach (['Notificaciones' => $avisos, 'Webhook' => $webhook] as $nombre => $bloque) {
            assertTrue($bloque !== '', 'se pudo leer el bloque ' . $nombre);
            assertTrue(
                str_contains($bloque, 'col-sm-6'),
                $nombre . ': los campos se emparejan con `col-sm-6` (dos por fila en la tarjeta estrecha)'
            );
            assertFalse(
                str_contains($bloque, 'col-md-'),
                $nombre . ': `col-md-*` mira la VENTANA, no la tarjeta; en una `col-lg-4` de ~400px'
                    . ' metía tres o cuatro columnas enanas y dejaba la fila a medias'
            );
        }
    }

    public function testLasCabecerasDeRespuestasAnaliticaYAdminUsanLaMismaFila(): void
    {
        // Respuestas se quedaba en `btn-sm` (32px) al lado de los 44px del resto.
        $respuestas = self::plantilla('responses/index.php');
        $desde = strpos($respuestas, 'app-head-actions');
        assertTrue($desde !== false, 'respuestas: la cabecera marca su fila con app-head-actions');
        $fila = substr($respuestas, (int) $desde);
        // La fila de acciones solo contiene los dos enlaces, así que su primer
        // `</div>` la cierra (si no, el resto de la página trae `btn-sm` de sobra).
        $fin = strpos($fila, '</div>');
        assertTrue($fin !== false, 'respuestas: la fila de acciones cierra su contenedor');
        $fila = substr($fila, 0, (int) $fin);
        assertFalse(
            str_contains($fila, 'btn-sm'),
            'respuestas: los botones de cabecera no llevan btn-sm (32px junto a los 44 del resto)'
        );

        // Desde Fase 1, /admin comparte cabecera: cada pantalla incluye
        // `admin/_page_head`, que es quien emite `.app-head-actions`. Copiada en
        // cada vista, arreglar el reparto en una dejaba a las otras fuera.
        assertTrue(
            str_contains(self::plantilla('admin/_page_head.php'), 'app-head-actions'),
            'la cabecera compartida de /admin emite la fila de acciones'
        );
        foreach (['admin/users.php', 'admin/forms.php', 'admin/security_log.php', 'admin/groups.php', 'admin/roles.php', 'admin/settings.php'] as $rel) {
            assertTrue(
                str_contains(self::plantilla($rel), 'admin/_page_head'),
                $rel . ': su cabecera es la compartida de /admin'
            );
        }

        // La barra de filtros de analítica no fuerza `nowrap` en ninguna franja:
        // con los controles a 44px no cabe en una fila, y el `nowrap` empujaba la
        // página. `flex-sm-nowrap` desbordaba en tablet; `flex-lg-nowrap` desbordaba
        // en la franja 992–1023 (el sidebar deja ~700px de contenido y la barra mide
        // 722). Con `flex-wrap` se parte antes de desbordar y sigue en una sola
        // línea cuando hay sitio.
        $analitica = self::plantilla('analytics/show.php');
        assertFalse(
            str_contains($analitica, 'flex-sm-nowrap') || str_contains($analitica, 'flex-lg-nowrap'),
            'analitica: la barra de filtros no fuerza nowrap en ninguna franja'
        );
        assertTrue(
            str_contains($analitica, 'analytics-filters d-flex flex-wrap'),
            'analitica: la barra de filtros envuelve con flex-wrap'
        );

        // El botón de exportar no lleva `w-100`: en escritorio estiraba la fila
        // entera porque el `w-sm-auto` con el que se pretendía deshacerlo NO existe
        // (Bootstrap no trae utilidades de ancho por breakpoint), así que el `w-100`
        // aplicaba a todos los tamaños.
        assertFalse(
            str_contains($analitica, 'w-sm-auto') || str_contains($analitica, 'btn-success w-100'),
            'analitica: el botón de exportar no se fuerza a ancho completo'
        );

        $css = self::css();
        assertSame(
            1,
            preg_match('/\.app-head-actions\s*\{\s*flex-wrap:\s*wrap/', $css),
            'app.css deja que la fila de acciones se parta antes que desbordar'
        );
        assertSame(
            1,
            preg_match('/\.app-head-actions\s*\{[^}]*margin-left:\s*auto/', $css),
            'y la fila de acciones queda pegada a la derecha aunque envolva'
        );
        assertSame(
            1,
            preg_match('/\.app-head-actions\s*\{[^}]*justify-content:\s*flex-end/', $css),
            'también al partirse en dos líneas'
        );
        assertSame(
            1,
            preg_match('/\.analytics-filters\s+\.form-control,\s*\.analytics-filters\s+\.form-select\s*\{[^}]*width:\s*auto/', $css),
            'los campos de la barra de filtros se miden por su contenido para poder envolver'
        );
    }

    /**
     * Las cuatro vistas de un formulario comparten el ancho de contenido.
     *
     * Ficha, participantes y respuestas ya usaban `.container px-lg-4`; el
     * builder iba en `container-fluid` (ancho completo), así que su lienzo era
     * más ancho que el resto y la misma pantalla «se movía» al entrar y salir del
     * constructor. El dashboard y la analítica siguen a ancho completo a
     * propósito (no son de un formulario y la analítica quiere sitio para las
     * gráficas), así que el test mira exactamente estas cuatro.
     */
    public function testLasCuatroVistasDeFormularioCompartenElAnchoDeContenido(): void
    {
        foreach ([
            'dashboard/forms/show.php',
            'builder/index.php',
            'dashboard/forms/access.php',
            'responses/index.php',
        ] as $rel) {
            $html = self::plantilla($rel);
            assertTrue(
                str_contains($html, '<div class="container px-lg-4">'),
                $rel . ': usa el ancho de contenido común (.container px-lg-4)'
            );
            assertFalse(
                str_contains($html, 'container-fluid'),
                $rel . ': no vuelve a ancho completo (container-fluid)'
            );
        }
    }

    /**
     * Los campos del panel se distinguen de la tarjeta blanca y sus labels van
     * en negrita.
     *
     * El panel mete los controles en `.card` blancas, así que un `.form-control`
     * blanco con borde claro casi no se distinguía del fondo. La solución es el
     * mismo off-white que el fill ya usaba (`#fbfcfe`) y `font-weight: 600` en
     * los labels, acotado a `.app-content` para no tocar el formulario público.
     */
    public function testLosCamposDelPanelSeDistinguenYLosLabelsVanEnNegrita(): void
    {
        $campo = self::bloque('.app-content .form-control');
        assertNotNull($campo, 'app.css da a los campos del panel un fondo propio');
        assertSame(
            1,
            preg_match('/background-color:\s*#fbfcfe/i', (string) $campo),
            'los campos del panel dejan de ser blancos sobre tarjeta blanca'
        );

        $label = self::bloque('.app-content .form-label');
        assertNotNull($label, 'app.css sube los labels del panel a negrita');
        assertSame(
            1,
            preg_match('/font-weight:\s*600/', (string) $label),
            'los labels del panel van en negrita (600)'
        );
    }

    /**
     * El pie de un modal es otra fila de acciones.
     *
     * Sin una regla propia, el «Cancelar» (38px, un `.btn` a secas) convivía
     * con el CTA de `app-btn-primary` (44px) en el mismo `modal-footer`. Toma el
     * alto y el radio del CTA, como `.app-head-actions`, sin heredar su color ni
     * su sombra.
     *
     * La regla NO se acota a `.app-content`: el diálogo de confirmación
     * (`#vfConfirm`) vive fuera de él (es hijo directo de `<body>`), así que con
     * el selector acotado sus dos botones se quedaban en 38px. Por eso el
     * selector es `.modal-footer .btn` a secas.
     */
    public function testElPieDeLosModalesUnificaLosBotones(): void
    {
        $btn = self::bloque('.modal-footer .btn');
        assertNotNull($btn, 'app.css unifica el alto de los botones del pie de modal');
        assertFalse(
            str_contains(self::css(), '.app-content .modal-footer .btn'),
            'la regla del pie no se acota a .app-content: #vfConfirm vive fuera'
        );
        assertSame(
            1,
            preg_match('/min-height:\s*var\(--vf-btn-cta-h\)/', (string) $btn),
            'los botones del pie suben al alto del CTA (44px)'
        );
        assertSame(
            1,
            preg_match('/border-radius:\s*12px/', (string) $btn),
            'y al mismo radio'
        );
    }

    /**
     * El alto del CTA es el mismo dentro de una tarjeta que en la cabecera.
     *
     * `--vf-ctrl-h` es 32 y `--vf-btn-cta-h` es 44: los guardados de las tarjetas
     * iban con `btn-sm` (32) mientras el CTA de la cabecera de la misma pantalla
     * medía 44, y el formulario que el JS traslada al diálogo se veía más bajo en
     * la tarjeta que en el modal. `.app-form-cta` da el ALTO y el radio sin tocar
     * el color ni añadir sombra, que siguen siendo de cada variante — por eso
     * tampoco se le puede sumar `app-btn-primary`, que la pondría por encima.
     */
    public function testElAltoDelCtaDeFormularioEsElMismoDentroDeUnaTarjeta(): void
    {
        $cta = self::bloque('.app-form-cta');
        assertNotNull($cta, 'app.css tiene la utilidad de alto para los CTA de tarjeta');
        assertSame(
            1,
            preg_match('/min-height:\s*var\(--vf-btn-cta-h\)/', (string) $cta),
            'sube al alto del CTA (44px), no al de los controles (32px)'
        );
        assertSame(
            1,
            preg_match('/border-radius:\s*12px/', (string) $cta),
            'y al mismo radio que el resto de acciones'
        );
        assertSame(
            1,
            preg_match('/font-weight:\s*600/', (string) $cta),
            'y al mismo peso'
        );
        assertSame(
            0,
            preg_match('/\b(background|box-shadow|color)\s*:/', (string) $cta),
            'no pinta color ni sombra: la pone cada variante (btn-primary, -danger…)'
        );

        // Cada vista donde el JS traslada el formulario a un diálogo: sin uno de
        // los dos CTA, la tarjeta se queda en 32px y el modal en 44. El par
        // vista => aguja también atiende que el botón siga siendo el mismo.
        foreach ([
            'admin/groups.php'                    => 'Guardar grupo',
            'admin/roles.php'                     => 'Guardar matriz',
            'admin/users.php'                     => 'Guardar permisos',
            'admin/_user_toggle.php'              => 'Desactivar|Activar',
            'dashboard/forms/_status_actions.php' => 'formUpdate',
            'dashboard/forms/_share_card.php'     => 'Publicar ahora',
            'dashboard/forms/show.php'            => 'Guardar ajustes',
            'dashboard/forms/_roster_list.php'    => '</i>Quitar',
            'dashboard/forms/_roster_panel.php'   => 'Vaciar la lista',
            'dashboard/forms/_roster_add_form.php' => 'Añadir a la lista',
        ] as $vista => $aguja) {
            $html = self::plantilla($vista);
            assertTrue(
                // `app-btn-primary` ya mide 44 (y añade sombra y color de
                // variante primaria); `app-form-cta` es el alto sin eso, para los
                // botones que no son primarios (danger, outline…).
                str_contains($html, 'app-form-cta') || str_contains($html, 'app-btn-primary'),
                "el CTA de {$vista} sube al alto de 44px (app-form-cta o app-btn-primary)"
            );
            assertSame(
                1,
                preg_match('#' . $aguja . '#', $html),
                "la vista {$vista} sigue teniendo su botón (aguja: {$aguja})"
            );
        }
    }
}
