<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * Los grupos plegables, en las dos pantallas que los tienen.
 *
 * `dashboard.js` solo sabía de un contenedor: la ficha del formulario
 * (`#formSettings`, con `data-vf-form-id`). Ahora el alta de «Usuarios» también es
 * un grupo plegable, y por eso el estado pasó de «POR FORMULARIO» a «POR
 * PANTALLA»: cada contenedor trae su clave y su valor por defecto, y el JS los lee
 * de los dos sitios.
 *
 * Hay tres cosas aquí que se pueden romper sin que se note, y por eso están fijadas
 * en vez de quedar en el comentario:
 *
 *   1. **El panel llega abierto del servidor.** Con el registro público cerrado (el
 *      default) el alta es la ÚNICA forma de crear un `user` o un `editor`, así que
 *      sin JS tiene que poder usarse. Ponerlo cerrado en el servidor lo escondería
 *      entero de quien no tenga JS, y no daría ningún error: el botón seguiría ahí,
 *      el POST seguiría funcionando y la pantalla parecería normal.
 *   2. **La ficha NO se cierra.** El atributo que dice «cerrado de entrada» es
 *      opcional y por defecto significa «abierto»: si se añadiera a la ficha, sus
 *      siete grupos empezarían cerrados sin que ningún test lo dijera.
 *   3. **El JS no graba el valor por defecto como si fuera elección de quien
 *      guarda.** El `hide()` de arranque dispara `hidden.bs.collapse` igual que un
 *      clic, y sin el descarte esa escritura se guarda: la primera visita cerraría
 *      el panel para siempre y, si mañana cambiara el default, el usuario seguiría
 *      viendo el viejo.
 *
 * Las comprobaciones miran **etiquetas**, no el archivo entero: estas mismas
 * cadenas están escritas en los comentarios que explican el porqué, y un
 * `str_contains` sobre el archivo pasa verde aunque el atributo haya desaparecido
 * del marcado (le basta con el comentario). Del JS se quitan los comentarios antes
 * de buscar, por el mismo motivo.
 */
class GroupCollapseContractTest
{
    private const VISTA_USUARIOS = __DIR__ . '/../../views/admin/users.php';
    private const VISTA_FICHA = __DIR__ . '/../../views/dashboard/forms/show.php';
    private const JS = __DIR__ . '/../../public/assets/js/dashboard.js';

    private const PANEL = 'grp-nuevo-usuario';

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

    /** El marcado de las dos vistas, con los espacios plegados (atributos partidos en dos líneas). */
    private static function usuarios(): string
    {
        return self::etiquetas(self::VISTA_USUARIOS);
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

    public function testElAltaDeUsuariosEsUnGrupoPlegableQueLlegaAbierto()
    {
        $html = self::usuarios();

        assertTrue(
            str_contains($html, 'data-vf-group-key="admin-usuarios"'),
            'el alta de usuarios es un grupo plegable y trae su propia clave de estado'
        );

        // El botón va dentro del <legend>: `type="button"` porque sin él el título
        // del fieldset envía el formulario, que en esta pantalla sería «crear
        // cuenta» al plegar o desplegar el alta.
        assertTrue(
            str_contains($html, '<button type="button" class="app-group-toggle" data-bs-toggle="collapse"'
                . ' data-bs-target="#' . self::PANEL . '" aria-expanded="true" aria-controls="' . self::PANEL . '">'),
            'el grupo se pliega con un <button type="button"> que apunta a #' . self::PANEL
        );

        assertSame(
            1,
            preg_match_all('/class="app-group-toggle"/', $html),
            'en «Usuarios» hay exactamente un grupo plegable'
        );

        // Y el panel se sirve ABIERTO: es lo que permite usarlo sin JS.
        assertTrue(
            str_contains($html, '<div class="collapse show" id="' . self::PANEL . '">'),
            'el panel #' . self::PANEL . ' llega abierto del servidor (`collapse show`)'
        );
        assertSame(
            1,
            preg_match_all('/class="collapse show" id="grp-/', $html),
            'y hay exactamente un panel de grupo en la vista'
        );
    }

    public function testElFormularioDeAltaEstaDentroDelPanelYNoAlLado()
    {
        // El orden y el cierre del fieldset se leen del archivo entero: aquí se
        // comprueba una relación entre posiciones, no la presencia de una cadena.
        $html = (string) file_get_contents(self::VISTA_USUARIOS);

        $panel = strpos($html, 'id="' . self::PANEL . '"');
        $alta = strpos($html, 'action="/admin/users/create"');
        assertTrue($panel !== false, 'el panel del alta existe');
        assertTrue($alta !== false, 'el formulario de alta existe');
        assertTrue(
            $panel < $alta,
            'el panel se abre antes del formulario: el POST de alta va DENTRO del grupo'
        );

        // Si alguien mueve el <form> fuera del grupo sin querer, seguiría habiendo
        // `<form>` y habría `<div class="collapse show">`: entre el panel y el
        // formulario no puede cerrarse NINGUNA de las dos etiquetas que lo
        // envuelven. Con el panel cerrado por defecto, un alta fuera del panel se
        // vería sin ningún error: el botón seguiría desplegando un panel vacío.
        $entre = substr($html, (int) $panel, (int) $alta - (int) $panel);
        foreach (['</div>', '</fieldset>'] as $cierre) {
            assertFalse(
                str_contains($entre, $cierre),
                'el alta queda DENTRO del grupo: no se cierra ' . $cierre . ' antes del formulario'
            );
        }

        foreach (['name="name"', 'name="email"', 'name="password"', 'name="password_confirm"', 'name="role_id"'] as $campo) {
            assertTrue(
                str_contains(self::usuarios(), $campo),
                'el campo ' . $campo . ' sigue en el formulario de alta'
            );
        }
    }

    public function testElCierrePorDefectoLoAplicaElJsYSoloEnUsuarios()
    {
        assertTrue(
            str_contains(self::usuarios(), 'data-vf-group-default="closed"'),
            'el alta llega cerrada de entrada, y lo dice en el marcado'
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

    public function testElJsRecorreTodosLosContenedoresYConservaLaClaveDeLaFicha()
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

    public function testElJsNoGrabaElValorPorDefectoComoSiFueraUnaDecision()
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