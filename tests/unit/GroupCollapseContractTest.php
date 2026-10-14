<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * Los grupos plegables, en las dos pantallas que los tienen.
 *
 * `dashboard.js` solo sabía de un contenedor: la ficha del formulario
 * (`#formSettings`, con `data-vf-form-id`). Hoy también son plegables las
 * tarjetas de `/admin/groups` (con `data-vf-group-key="admin-grupos"`), y por
 * eso el estado es «POR PANTALLA»: cada contenedor trae su clave, y el JS lee de
 * los dos sitios. El alta de usuarios dejó de ser un grupo plegable cuando pasó
 * a un modal, así que el contrato lo fija el listado de grupos.
 *
 * Hay tres cosas aquí que se pueden romper sin que se note, y por eso están
 * fijadas en vez de quedar en el comentario:
 *
 *   1. **El panel llega abierto del servidor.** La tarjeta de grupo se usa para
 *      marcar roles y miembros, y plegar es una comodidad con JS: si el panel
 *      saliera cerrado del servidor, quien no tenga JS vería la cabecera y nada
 *      dentro, sin ningún error. Además el botón seguiría ahí y el POST seguiría
 *      funcionando, así que la pantalla parecería normal.
 *   2. **Ninguna pantalla pide cierre por defecto.** El atributo que dice
 *      «cerrado de entrada» lo añade el JS y su ausencia SIGNIFICA «abierto»: si
 *      se colara en la ficha, sus siete grupos empezarían cerrados; si se colara
 *      en los grupos, las tarjetas nacerían plegadas.
 *   3. **El JS no graba el valor por defecto como si fuera elección de quien
 *      guarda.** El `hide()` de arranque dispara `hidden.bs.collapse` igual que
 *      un clic, y sin el descarte esa escritura se guarda: la primera visita
 *      cerraría el panel para siempre y, si mañana cambiara el default, el
 *      usuario seguiría viendo el viejo.
 *
 * Las comprobaciones miran **etiquetas**, no el archivo entero: estas mismas
 * cadenas están escritas en los comentarios que explican el porqué, y un
 * `str_contains` sobre el archivo pasa verde aunque el atributo haya desaparecido
 * del marcado (le basta con el comentario). Del JS se quitan los comentarios antes
 * de buscar, por el mismo motivo.
 */
class GroupCollapseContractTest
{
    private const VISTA_GRUPOS = __DIR__ . '/../../views/admin/groups.php';
    private const RESUMEN_GRUPO = __DIR__ . '/../../views/admin/_group_summary.php';
    private const VISTA_FICHA = __DIR__ . '/../../views/dashboard/forms/show.php';
    private const JS = __DIR__ . '/../../public/assets/js/dashboard.js';

    private const PANEL = 'grp-grupo-';

    /** Solo el marcado: sin comentarios, sin texto, sin PHP. */
    private static function etiquetas(string $archivo): string
    {
        // Los bloques PHP se van antes de nada, y por dos razones: sus comentarios
        // son los que explican el porqué (y una búsqueda los contaría), y el eco de
        // una etiqueta que lleva PHP dentro corta el recorte en su primer «mayor que»
        // y dejaría fuera los atributos que vienen detrás.
        // (Ojo: el cierre de PHP no se puede escribir aquí, ni escrito con
        // comillas, porque dentro de un comentario de una línea lo termina y el
        // resto de la línea se lee como código.)
        $html = (string) preg_replace('/<\?(?:php|=).*?\?>/s', '', (string) file_get_contents($archivo));
        $coincidencias = [];
        preg_match_all('/<[a-zA-Z][^>]*>/', $html, $coincidencias);

        return (string) preg_replace('/\s+/', ' ', implode(' ', $coincidencias[0]));
    }

    /** El marcado del listado de grupos (contenedor, panel y formulario). */
    private static function grupos(): string
    {
        return self::etiquetas(self::VISTA_GRUPOS);
    }

    /** El marcado de la cabecera-resumen, que es donde vive el caret. */
    private static function resumen(): string
    {
        return self::etiquetas(self::RESUMEN_GRUPO);
    }

    private static function ficha(): string
    {
        return self::etiquetas(self::VISTA_FICHA);
    }

    /** El JS sin comentarios: buscar en el archivo entero lo encuentra escrito en el porqué. */
    private static function js(): string
    {
        $js = (string) file_get_contents(self::JS);
        $sinBloque = (string) preg_replace('#/\*.*?\*/#s', ' ', $js);

        return (string) preg_replace('#(^|\s)//[^\n]*#', '$1', $sinBloque);
    }

    public function testLasTarjetasDeGrupoSonPlegablesYLleganAbiertas(): void
    {
        $html = self::grupos();
        $resumenCrudo = (string) file_get_contents(self::RESUMEN_GRUPO);
        $gruposCrudo  = (string) file_get_contents(self::VISTA_GRUPOS);

        assertTrue(
            str_contains($html, 'data-vf-group-key="admin-grupos"'),
            'el listado de grupos es un contenedor plegable y trae su propia clave de estado'
        );

        // El caret vive en el partial de la cabecera-resumen (es parte de lo que
        // el POST repinta), así que se busca ahí.
        assertTrue(
            str_contains(self::resumen(), '<button type="button" class="app-group-toggle" data-bs-toggle="collapse"'),
            'la tarjeta se pliega con un <button type="button"> (sin él, el caret enviaría el formulario de al lado)'
        );

        // El id del panel es dinámico (`grp-grupo-<gid>`), así que la referencia
        // se ata sobre el código, no sobre el marcado ya sin PHP.
        assertTrue(
            str_contains($resumenCrudo, "\$panelId = 'grp-grupo-' . (int) \$groupId;")
                && str_contains($resumenCrudo, 'data-bs-target="#<?= View::escape($panelId) ?>"')
                && str_contains($resumenCrudo, 'aria-controls="<?= View::escape($panelId) ?>"'),
            'el botón apunta al panel #' . self::PANEL . '<gid> por `$panelId`'
        );

        assertSame(
            1,
            preg_match_all('/class="app-group-toggle"/', self::resumen()),
            'la cabecera-resumen trae exactamente un caret'
        );

        // Y el panel se sirve ABIERTO: es lo que permite usarlo sin JS.
        assertSame(
            1,
            substr_count($gruposCrudo, 'class="collapse show" id="<?= $panelId ?>"'),
            'el panel #' . self::PANEL . '<gid> llega abierto del servidor (`collapse show`)'
        );
    }

    public function testElFormularioDelGrupoEstaDentroDelPanelYNoAlLado(): void
    {
        // El orden y el cierre del panel se leen del archivo entero: aquí se
        // comprueba una relación entre posiciones, no la presencia de una cadena.
        $html = (string) file_get_contents(self::VISTA_GRUPOS);

        $panel = strpos($html, 'id="<?= $panelId ?>"');
        $form  = strpos($html, 'action="/admin/groups/update"');
        assertTrue($panel !== false, 'el panel del grupo existe');
        assertTrue($form !== false, 'el formulario del grupo existe');
        assertTrue(
            $panel < $form,
            'el panel se abre antes del formulario: el POST de edición va DENTRO del grupo'
        );

        // Si alguien mueve el <form> fuera del grupo sin querer, seguiría habiendo
        // `<form>` y habría `<div class="collapse show">`: entre el panel y el
        // formulario no puede cerrarse NINGUNA de las dos etiquetas que lo
        // envuelven. Con el panel cerrado por defecto, un formulario fuera del panel
        // se vería sin ningún error: el botón seguiría desplegando un panel vacío.
        $entre = substr($html, (int) $panel, (int) $form - (int) $panel);
        foreach (['</div>', '</fieldset>'] as $cierre) {
            assertFalse(
                str_contains($entre, $cierre),
                'el formulario queda DENTRO del panel: no se cierra ' . $cierre . ' antes de él'
            );
        }

        foreach ([
            'name="name"', 'name="description"', 'name="roles[]"',
            'name="users[]"', 'name="group_id"',
        ] as $campo) {
            assertTrue(
                str_contains(self::grupos(), $campo),
                'el campo ' . $campo . ' sigue en el formulario del grupo'
            );
        }

        // El alta es un modal, pero su formulario se sirve en línea y el JS lo
        // mueve: si el marcado del modal se sirviera solo, sin JS no habría forma
        // de crear un grupo.
        assertTrue(
            str_contains(self::grupos(), 'data-vf-modal-move="#newGroupModal"')
                && str_contains(self::grupos(), 'data-vf-modal-form')
                && str_contains(self::grupos(), 'action="/admin/groups/create"'),
            'el alta se sirve en línea para que el JS la mueva al modal (sin duplicarla)'
        );
    }

    public function testElCierrePorDefectoLoAplicaElJsYNingunaPantallaLoPide(): void
    {
        assertFalse(
            str_contains(self::grupos(), 'data-vf-group-default'),
            'las tarjetas de grupo abren de entrada: no piden cierre por defecto'
        );

        // La ficha abre de entrada: el atributo es opcional y su ausencia SIGNIFICA
        // «abierto». Si se añadiera a la ficha, sus siete grupos empezarían cerrados
        // y ningún test lo diría.
        assertFalse(
            str_contains(self::ficha(), 'data-vf-group-default'),
            'la ficha del formulario no pide cierre por defecto (sus siete grupos abren)'
        );

        assertTrue(
            str_contains(self::js(), "vfGroupDefault !== 'closed'"),
            'el JS trata la ausencia del atributo como «abierto»'
        );
    }

    public function testElJsRecorreTodosLosContenedoresYConservaLaClaveDeLaFicha(): void
    {
        $js = self::js();

        assertTrue(
            str_contains($js, "[data-vf-group-key], [data-vf-form-id]"),
            'el JS busca cualquier contenedor de grupos, no solo la ficha'
        );
        assertFalse(
            str_contains($js, "getElementById('formSettings')"),
            'y no se queda atado a un id concreto'
        );
        assertTrue(
            str_contains($js, 'cont.dataset.vfGroupKey || cont.dataset.vfFormId'),
            'la clave sale del atributo nuevo, con el viejo como alternativa'
        );
        assertTrue(
            str_contains($js, "'vf:grp:' + clave"),
            'el estado se guarda por pantalla con el mismo prefijo de siempre'
        );

        // La ficha sigue nombrando su clave como `data-vf-form-id`: si alguien
        // renombra el atributo sin migrar la clave, el estado guardado de todos los
        // formularios se pierde en silencio y los grupos abren todos.
        assertTrue(
            str_contains(self::ficha(), 'data-vf-form-id='),
            'la ficha conserva `data-vf-form-id`, que es su clave guardada'
        );
    }

    public function testElJsNoGrabaElValorPorDefectoComoSiFueraUnaDecision(): void
    {
        $js = self::js();

        assertTrue(
            str_contains($js, 'decididos'),
            'los cambios de arranque se marcan aparte de los de quien navega'
        );
        assertTrue(
            str_contains($js, 'delete decididos[slug]'),
            'y un `hidden.bs.collapse` que solo reproduce el valor por defecto se descarta sin guardar'
        );
    }
}
