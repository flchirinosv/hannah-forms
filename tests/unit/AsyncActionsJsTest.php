<?php

declare(strict_types=1);

namespace Tests\Unit;

/**
 * El envío sin recarga del panel: `tests/js/async-actions.js` corre el JS real.
 *
 * Hay dos capas, y la razón es la misma que en el progreso del fill:
 *
 *   · El arnés (`tests/js/async-actions.js`) extrae `vfPost()` y el delegado de
 *     `submit` del `app.js` de verdad y los ejecuta contra un DOM y un `fetch`
 *     de mentira. Es el único que puede comprobar lo que de verdad importa y
 *     que no se ve: que una respuesta que NO es JSON (la página de error de un
 *     PHP, con 200 y `text/html`) devuelva el envío al POST nativo, que un
 *     `ok:false` del servidor se pinte sin repetir la operación, y que el
 *     botón externo de «Publicar» (que cuelga del layout y usa
 *     `form="formUpdate"`) se apague mientras dura el POST.
 *   · Los métodos de aquí son el CONTRATO que no necesita node: que el
 *    (`app.js`) siga siendo el que aporta `esc()` y que la respuesta del
 *     servidor sea JSON de verdad. Sin `node` el arnés no se ejecuta y esto lo
 *     dice, en vez de fingir que lo ha comprobado.
 *
 * Ojo al escribir casos del arnés: el DOM mínimo tiene que imitar al navegador
 * en las cosas que el código consulta de verdad (`element.id` reflects el
 * atributo, quitar un nodo le pone `parentNode` a null y NO se lo quita a sus
 * hijos). Un `closest()` que encuentra algo que en el navegador no está hace
 * que el caso pase sin haber comprobado nada.
 */
class AsyncActionsJsTest
{
    private const JS = 'public/assets/js/app.js';
    private const HARNESS = 'tests/js/async-actions.js';

    /* ---------- comportamiento (necesita node) ---------- */

    public function testElEnvioSinRecargaRespetaElCaminoNativo(): void
    {
        if (!$this->nodeAvailable()) {
            // Sin node no se puede ejecutar el arnés. Se avisa y se pasa: el
            // runner trata un SKIP lanzado desde setUp() como ERROR, y ponerlo
            // en rojo en una máquina sin node sería un fallo inventado.
            printf("        (sin node: el comportamiento del envío no se ejecuta; %s)\n", self::HARNESS);
            return;
        }

        $output = [];
        $code = 0;
        $command = escapeshellcmd('node')
            . ' ' . escapeshellarg($this->path(self::HARNESS)) . ' 2>&1';
        @exec($command, $output, $code);

        $text = implode("\n", $output);
        assertEquals(
            0,
            $code,
            'el arnés del envío sin recarga falló (código ' . $code . "):\n" . $text
        );
        assertTrue(
            str_contains($text, 'Fallidos: 0'),
            'el arnés no llegó al final o tuvo fallos:\n' . $text
        );
        // Guarda contra un arnés que se queda a medio camino: un fichero que
        // revienta antes de imprimir el resumen sale con código distinto, pero
        // uno que se traga el error puede imprimir «Fallidos: 0» de una versión
        // anterior. El número de casos ata el arnés a esta versión del JS.
        //
        // El número EXACTO y no un prefijo: `str_contains($text, 'Arranques: 5')`
        // casa con «51» y con «50», así que el contador dejó de mirar cuando el
        // arnés pasó de 5 a 97 casos y nadie se enteró de que se podía añadir un
        // caso sin subir la cifra. La línea entera, con `^`/`$` en multilínea: el
        // «| Fallidos: 0» va en la misma línea y hace falta para atar las dos
        // mitades del resumen.
        assertTrue(
            preg_match('/^Arranques: 109 \| Fallidos: 0$/m', $text) === 1,
            'el arnés no llegó a sus 109 casos, o alguno falló:\n' . $text
        );
    }

    /* ---------- contrato (siempre) ---------- */

    /**
     * El `Content-Type` es lo que separa «el servidor respondió y dijo que no»
     * de «la respuesta no es la que esperábamos». Sin la comprobación, el
     * `catch` del `vfPost` se come también las páginas de error tunescas (que
     * llegan con 200) y el formulario se queda sin hacer nada con un error de
     * parseo que no explica nada.
     */
    public function testElPostSoloAceptaRespuestasJson(): void
    {
        $cuerpo = $this->functionBody('window.vfPost = function');

        assertTrue(
            str_contains($cuerpo, "res.headers.get('content-type')"),
            'vfPost() tiene que mirar el Content-Type de la respuesta: es lo que '
                . 'distingue el JSON del controlador de una página de error tunesca, que llega '
                . 'con 200 y no con un error'
        );
        assertTrue(
            str_contains($cuerpo, "ct.indexOf('application/json') === -1"),
            'si el Content-Type no es JSON, vfPost() tiene que RECHAZAR (y que el '
                . 'rechazo sea lo que hace volver al envío nativo)'
        );
        assertTrue(
            str_contains($cuerpo, "json.ok !== false"),
            'un ok:false dentro de un JSON bien formado también es un «no»: el '
                . 'servidor lo manda con 200 y no es un error de transporte'
        );
    }

    /**
     * El nombre de la función del arnés. Si `vfHandleSubmit` pasa a ser una
     * `function (e)` anónima en el `addEventListener`, el arnés no puede
     * extraerla y falla al arrancar —que es lo que tiene que pasar, y no un
     * verde que parece que todo se probó.
     */
    public function testElDelegadoDeSubmitEsUnaFuncionConNombre(): void
    {
        $src = $this->read(self::JS);

        assertTrue(
            str_contains($src, 'function vfHandleSubmit('),
            'el delegado de submit tiene que ser una función con nombre: es lo que '
                . 'extrae tests/js/async-actions.js para correr el JS real contra un DOM mínimo'
        );
        assertTrue(
            str_contains($src, "document.addEventListener('submit', vfHandleSubmit)"),
            'el addEventListener tiene que apuntar a la función con nombre, no a una anónima'
        );
    }

    /**
     * `data-vf-async` es lo que hace que un formulario vaya por fetch. Sin él,
     * el camino nativo no se toca: ni se previene el evento ni sale el fetch.
     * Este test ata que el atributo está en las vistas donde debe estar, para
     * que un `data-vf-async` borrado por error no degrade la página en
     * silencio a «nada pasa».
     */
    public function testLasAccionesDeLaFichaSeMandanSinRecarga(): void
    {
        // Los formularios de estado NO están todos en `show.php`: viven en los
        // partials (`_status_actions.php`), que es justo lo que permite
        // reponerlos sin recargar. Por eso el test mira TODAS las vistas de la
        // ficha y no solo la principal: buscarlos en una sola daría «no existe»
        // por un fichero que sí lo tiene.
        $fichero = '';
        $vistas = glob($this->path('views/dashboard/forms/*.php')) ?: [];
        foreach ($vistas as $v) {
            if (is_file($v)) {
                $fichero .= "\n" . (string) file_get_contents($v);
            }
        }
        $responses = $this->read('views/responses/index.php');

        $fallos = [];
        foreach ([
            'id="formUpdate"' => 'guardar título y descripción',
            'id="formSettings"' => 'guardar ajustes',
            'id="formSendReview"' => 'mandar a revisión',
            'id="formApprove"' => 'aprobar',
            'id="formReturnReview"' => 'devolver a borrador',
            'id="formPublishActions"' => 'publicar (acciones)',
            'id="formPublishSidebar"' => 'publicar (tarjeta de compartir)',
            'id="formClose"' => 'cerrar',
            'id="formReopenActions"' => 'reabrir',
            'id="formDelete"' => 'eliminar el formulario',
        ] as $ancla => $que) {
            $i = strpos($fichero, $ancla);
            if ($i === false) {
                $fallos[] = 'la ficha no tiene el formulario de ' . $que;
                continue;
            }
            $trozo = substr($fichero, $i, 400);
            if (!str_contains($trozo, 'data-vf-async')) {
                $fallos[] = 'el formulario de ' . $que . ' no lleva data-vf-async (se '
                    . 'quedaría haciendo un POST nativo con recarga)';
            }
        }
        // Los ids tienen que ser ÚNICOS entre los partials y la ficha: el
        // repintado busca el hueco por id y el botón externo se localiza por
        // `form="…"`, así que un id repetido (que fue lo que pasaba con
        // `formPublishActions`, que estaba en la ficha y en las acciones) hace
        // que se repinte el hueco equivocado y que el POST salga por otro
        // formulario.
        preg_match_all('/id="(form[A-Za-z0-9_]*)"/', $fichero, $m);
        foreach (array_keys(array_filter(array_count_values($m[1]), static fn ($n) => $n > 1)) as $dup) {
            $fallos[] = 'id repetido en la ficha: ' . $dup;
        }

        if (!str_contains($responses, 'data-vf-async="remove"')) {
            $fallos[] = 'el borrado de una respuesta no lleva data-vf-async="remove"';
        }
        if (!str_contains($responses, 'data-vf-row')) {
            $fallos[] = 'las respuestas necesitan data-vf-row para quitarse la fila';
        }
        if (!str_contains($responses, 'data-vf-role="response-count"')) {
            $fallos[] = 'el contador de respuestas necesita data-vf-role="response-count", '
                . 'que es lo que ajusta el borrado sin recarga';
        }

        assertEquals([], $fallos, implode(' | ', $fallos));
    }

    /**
     * El hueco de un repintado NO puede ser un `<form>`.
     *
     * El JS repone con `slot.innerHTML = html`, y si el `id` del hueco estuviera
     * en el `<form>` lo que se metería dentro es OTRO formulario. El parser del
     * navegador descarta el `<form>` anidado, así que «funcionaría» por casualidad
     * y perdiendo contenido según el navegador: por eso el hueco del toggle de
     * `/admin` es un `<span>` que envuelve al `<form>`.
     *
     * Se comprueba sobre las tres vistas que usan `data-vf-async`, que es donde
     * el JS va a sustituir contenido.
     */
    public function testElHuecoQueReponeElJsNoEsUnFormulario(): void
    {
        $fallos = [];

        foreach ($this->viewFiles() as $file) {
            $src = $this->sinComentarios((string) file_get_contents($file));

            // `<form … id="vf…">`: el id del hueco puesto en el formulario.
            if (preg_match('/<form\b[^>]*\bid="(vf[A-Za-z0-9_]*)"/i', $src, $m) === 1) {
                $fallos[] = basename($file) . ': el hueco «' . $m[1] . '» está en un <form>, '
                    . 'así el repintado mete un formulario dentro de otro (envuélvelo en un <span>)';
            }
        }

        assertEquals([], $fallos, implode(' | ', $fallos));
    }

    /**
     * La confirmación va en el `<form>`, no en el botón. En el botón no la miraba
     * nadie: el delegado lee `form.getAttribute('data-confirm')`, así que un
     * `data-confirm` colgado del botón se quedaba sin preguntar y el borrado
     * salía sin preguntar. Lo fijó el arnés («un data-confirm en el BOTÓN no
     * pregunta por nadie»).
     */
    public function testLaConfirmacionVaEnElFormYNoEnElBoton(): void
    {
        $fallos = [];

        foreach ($this->viewFiles() as $file) {
            $src = $this->sinComentarios((string) file_get_contents($file));

            // `data-confirm` dentro de un `<button …>`: inerte.
            if (preg_match('/<button\b[^>]*\bdata-confirm\b[^>]*>/i', $src, $m) === 1) {
                $fallos[] = basename($file) . ': data-confirm en el botón ('
                    . trim($m[0]) . ') — en el <form> o nada';
            }
        }

        // Y al menos un sitio donde sí está, para que el test no sea un «no
        // aparece en ninguna parte» que pasa con las vistas vacías.
        $show = $this->read('views/dashboard/forms/show.php');
        $acceso = $this->read('views/dashboard/forms/_access_list.php');
        if (!str_contains($acceso . $show, 'data-confirm=')) {
            $fallos[] = 'ninguna vista de la ficha confirma nada: el borrado tiene que preguntar';
        }

        assertEquals([], $fallos, implode(' | ', $fallos));
    }

    /**
     * El diálogo de confirmación del panel, en el layout, y con las piezas que el
     * JS y el lector de pantalla necesitan.
     *
     * No se comprueba que «se vea bonito» (eso es el bloque T de las pruebas
     * manuales), sino lo que se rompe en silencio: un `#vfConfirm` que no está o
     * está con otro `id` deja las acciones con `confirm()` del navegador —que es
     * funcional, no un fallo—; un `aria-labelledby` que apunte a nada deja el
     * diálogo sin nombre; y un `type="button"` que falte en «Sí» convierte el
     * botón de confirmar en un `submit` del formulario que hay detrás.
     */
    public function testElDialogoDeConfirmacionEstaEnElLayoutConSusPiezas(): void
    {
        $layout = $this->read('views/layouts/main.php');

        assertTrue(str_contains($layout, 'id="vfConfirm"'), 'falta el diálogo #vfConfirm en el layout');
        assertTrue(str_contains($layout, 'aria-labelledby="vfConfirmTitle"'), 'el diálogo necesita nombre accesible (aria-labelledby)');
        assertTrue(str_contains($layout, 'aria-describedby="vfConfirmText"'), 'el diálogo necesita describirlo la pregunta (aria-describedby)');
        assertTrue(str_contains($layout, 'id="vfConfirmText"'), 'falta el elemento donde va la pregunta, que es el aria-describedby');
        assertTrue(str_contains($layout, 'id="vfConfirmOk"'), 'falta el botón de «sí» (#vfConfirmOk), el único que ata app.js');
        assertTrue(str_contains($layout, 'id="vfConfirmCancel"'), 'falta el de «Cancelar» (#vfConfirmCancel): es el que recibe el foco al abrir');
        assertTrue(str_contains($layout, 'data-bs-dismiss="modal"'), 'cerrar tiene que ser Bootstrap (Esc, X y Cancelar), no un onclick');

        // `type="button"`: dentro de un `<form>` (y el layout tiene varios), un
        // `button` sin tipo es `submit`.
        foreach (['vfConfirmOk', 'vfConfirmCancel'] as $id) {
            assertTrue(
                preg_match('/<button\b[^>]*id="' . $id . '"[^>]*>/i', $layout, $m) === 1,
                'no se encontró el <button> de #' . $id
            );
            assertTrue(
                stripos($m[0], 'type="button"') !== false,
                'el botón #' . $id . ' necesita type="button": sin él es un submit del formulario que hay detrás'
            );
        }

        assertTrue(
            str_contains($this->read('public/assets/css/app.css'), '.app-confirm'),
            'el diálogo necesita su estilo en app.css (#vfConfirm sin reglas sale como un modal pelado)'
        );
    }

    /**
     * Toda acción que pregunta dice QUÉ va a hacer en el botón de «sí».
     *
     * Sin `data-confirm-ok` el botón pone «Sí, continuar», y eso es volver al
     * prompt feo con otro texto: un «Eliminar formulario» y un «Desactivar
     * cuenta» se confirman igual, y quien va a perder algo no llega a leer la
     * pregunta. Además el `data-confirm` de una vista que no usa el layout del
     * panel se quedaría con `confirm()` del navegador sin que nadie lo note.
     */
    public function testCadaAccionQueConfirmaDiceQueVaASerElSuBoton(): void
    {
        $fallos = [];
        $vistas = 0;

        foreach ($this->viewFiles() as $file) {
            $src = $this->sinComentarios((string) file_get_contents($file));
            /* Los `<?= ?>` se cambian por una palabra antes de buscar la etiqueta:
             * un `action="/forms/<?= (int) $form['id'] ?>/close"` tiene `>` dentro
             * del PHP, y un `/<form\b[^>]*>/` se cortaba ahí — que es como se
             * encontraron 3 formularios de 10 sin que faltara ninguno. */
            $src = preg_replace('/<\?=.*?\?>/s', 'PHP', $src) ?? $src;

            preg_match_all('/<form\b[^>]*>/i', $src, $tags);
            foreach ($tags[0] as $tag) {
                if (!str_contains($tag, 'data-confirm=')) {
                    continue;
                }
                $vistas++;
                if (!str_contains($tag, 'data-confirm-ok=')) {
                    $fallos[] = basename($file) . ': un <form data-confirm> sin data-confirm-ok '
                        . '(el botón de «sí» quedaría en «Sí, continuar»)';
                }
                // Y tiene que PREGUNTAR (con su «¿»), no limitarse a avisar: el
                // diálogo que sustituye al prompt pide una respuesta.
                if (!str_contains($tag, '¿')) {
                    $fallos[] = basename($file) . ': la pregunta no está escrita como pregunta';
                }
            }
        }

        assertTrue($vistas >= 8, 'Solo se encontraron ' . $vistas . ' <form data-confirm>: el rastreo no está buscando');
        assertEquals([], $fallos, implode(' | ', $fallos));
    }

    /**
     * La fila de usuarios de `/admin` envía el rol y los grupos por fetch.
     *
     * Los dos formularios de la fila (el `select` de rol y el desplegable de
     * grupos) son de los pocos que se rellenan sin botón —el rol se envía solo al
     * cambiarlo— así que el `data-vf-async` es lo único que dice «esto no
     * recarga». Sin él, cambiar el rol de alguien se llevaba la página entera y
     * con ella el desplegable de grupos que estuviera abierto al lado.
     */
    public function testLaFilaDeUsuariosEnviaElRolYLosGruposSinRecargar(): void
    {
        $vista = $this->sinComentarios($this->read('views/admin/users.php'));
        /* Los `<?= ?>` a una palabra antes, por el mismo motivo que en
         * `testCadaAccionQueConfirmaDiceQueVaASerElSuBoton`: un `>` dentro del
         * PHP cortaba la etiqueta y el formulario no aparecía en el recuento. */
        $src = preg_replace('/<\?=.*?\?>/s', 'PHP', $vista) ?? $vista;

        preg_match_all('/<form\b[^>]*action="\/admin\/users\/(role|groups)"[^>]*>/i', $src, $m, PREG_SET_ORDER);
        $vistos = array_map(static fn(array $x): string => $x[1], $m);

        foreach (['role', 'groups'] as $accion) {
            assertTrue(
                in_array($accion, $vistos, true),
                'no se encontró el formulario de `/admin/users/' . $accion . '`: '
                    . 'el rastreo no está buscando (o el formulario no lleva el action esperado)'
            );
        }
        foreach ($m as $x) {
            assertTrue(
                str_contains($x[0], 'data-vf-async'),
                'el formulario de /admin/users/' . $x[1] . ' necesita data-vf-async: sin él '
                    . 'la fila se recarga entera por cada cambio'
            );
        }

        // Y el hueco de las insignias tiene que existir con el MISMO nombre que el
        // que compone el servidor (`vfUserGroups<id>`): si uno de los dos se
        // aparta, el guardado responde y no se ve nada.
/* Antes del replace de los `<?= ?>`: aquí lo que se busca es el PHP del
         * id, y `sinComentarios()` basta (un `vfUserGroups` en una nota no
         * cuenta). Y en BLOQUE, no en `//`: un `?>` dentro de un comentario de
         * línea cierra el modo PHP y todo lo que viene detrás se va como HTML. */
        assertTrue(
            str_contains($vista, 'id="vfUserGroups<?= $uid ?>"'),
            'el botón de grupos necesita su hueco con el id por fila: es el mismo '
                . 'nombre que compone el servidor en su respuesta'
        );
    }

    /**
     * El `select` que se envía solo tiene que disparar el evento `submit`.
     *
     * `form.submit()` —que es lo que había— **no** dispara ese evento, así que
     * el delegado no se enteraba de nada: ni preguntaba, ni apagaba el botón, ni
     * elegía el camino sin recarga, y la página se recargaba. Es un fallo que no
     * se ve leyendo el código («se envía solo», dice) y solo se ve usando el
     * selector; el arnés lo ejecuta de verdad.
     */
    public function testElAutoEnvioDisparaElEventoSubmitYNoLlamaAMethodSubmit(): void
    {
        $js = $this->read(self::JS);
        $cuerpo = $this->functionBody('function vfAutoSubmit(', "\n    }");

        assertTrue($cuerpo !== '', 'no se encontró vfAutoSubmit() en ' . self::JS);
        assertTrue(
            str_contains($cuerpo, 'requestSubmit'),
            'el auto-envío tiene que usar requestSubmit(): submit() no dispara el evento submit '
                . 'y el delegado nunca se entera'
        );
        assertTrue(
            str_contains($cuerpo, 'form.submit();'),
            'y el `else` de navegador viejo con submit(): sin él, un navegador sin '
                . 'requestSubmit no hace nada al cambiar el rol'
        );
        // Y que ese `submit()` sea EL del `else`: un `submit()` suelto por
        // delante (por ejemplo, «si viene de un clic, nativo») volvería a dejar
        // el cambio de rol recargando la página, y esta comprobación de arriba
        // seguiríaöntento..
        assertSame(
            1,
            substr_count($cuerpo, 'form.submit();'),
            'el cuerpo hace un solo submit() nativo'
        );
        assertTrue(
            strpos($cuerpo, 'else') !== false
                && strpos($cuerpo, 'form.submit();') > strpos($cuerpo, 'else'),
            'y está en el `else` del navegador viejo: un submit() en la rama de '
                . 'requestSubmit() deja el cambio de rol recargando la página igual'
        );
        assertTrue(
            str_contains($js, "document.querySelectorAll('.js-auto-submit').forEach(vfAutoSubmit)"),
            'la función con nombre es la que se ata a los selectores: es lo único que '
                . 'permite que el arnés la ejecute contra un DOM mínimo'
        );
    }

    /**
     * La matriz de permisos guarda sin recarga y su contador se recalcula en
     * cliente. El `syncRoleCounts()` con nombre es lo que el arnés extrae y
     * ejecuta (el caso «el contador de la matriz se recalcula con lo marcado»);
     * sin atarlo a `initRoleMatrix()` en el arranque, el cálculo existe y no se
     * llama nunca.
     */
    public function testElGuardadoDeLaMatrizYSuContadorEstanAtados(): void
    {
        $vista = $this->sinComentarios($this->read('views/admin/roles.php'));

        assertTrue(str_contains($vista, 'data-vf-async="slots"'), 'guardar la matriz tiene que ir sin recarga');
        assertTrue(
            str_contains($vista, 'app-rbac-matrix-scroll'),
            'la matriz necesita su contenedor con scroll propio: sin él `position: sticky` no pega la cabecera'
        );
        assertTrue(str_contains($vista, 'data-vf-count="'), 'cada rol necesita su contador con data-vf-count');
        assertTrue(str_contains($vista, 'data-vf-role-id="'), 'cada casilla necesita data-vf-role-id para agrupar su contador');

        $js = $this->read(self::JS);
        assertTrue(
            str_contains($js, 'function syncRoleCounts('),
            'syncRoleCounts() tiene que ser una función con nombre: el arnés la extrae y la corre'
        );
        assertTrue(
            str_contains($js, "document.addEventListener('DOMContentLoaded', initRoleMatrix)"),
            'initRoleMatrix() tiene que atarse al documento'
        );
        assertTrue(
            str_contains($js, 'syncRoleCounts(matrix)'),
            'y el cálculo de contadores tiene que llamarse desde el arranque'
        );
    }

    /**
     * El giro del guardado de ajustes es un contrato entre la vista y el JS:
     * `app.js` quita el `d-none` del `.spinner-border` que ya está en el
     * markup. Si el JS lo creara él, el botón del guardado sería distinto del
     * resto; si la vista no lo trajera, el guardado no daría ninguna señal
     * mientras dura.
     */
    public function testElBotonDeGuardarAjustesTraeElGiroQueEnciendeElJs(): void
    {
        $show = $this->read('views/dashboard/forms/show.php');

        assertTrue(
            str_contains($show, 'spinner-border'),
            'el botón de guardar ajustes necesita su .spinner-border: app.js quita el '
                . 'd-none de ese nodo mientras dura el POST'
        );
        assertTrue(
            str_contains($this->read(self::JS), "btn.querySelector('.spinner-border')"),
            'app.js tiene que buscar el giro DENTRO del botón (el del markup), no crearlo'
        );
    }

    /* ---------- ayudas ---------- */

    private function nodeAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $out = [];
        $code = 0;
        @exec('node --version 2>&1', $out, $code);
        return $code === 0;
    }

    /**
     * Cuerpo desde una firma hasta la llave que la cierra (por sangría).
     *
     * El terminador es un parámetro porque no todas las funciones de `app.js`
     * acaban igual: las que se EXPORTAN a `window` (`vfPost`, `vfToast`) cierran
     * con `};` y las demás con `}`. Buscar siempre `};` devolvía el cuerpo
     * vacío de `vfAutoSubmit()` —que es una declaración— y el test fallaba con
     * «no se encontró», que es el mensaje equivocado: sí que se encuentra.
     */
    private function functionBody(string $signature, string $fin = "\n    };"): string
    {
        $src = $this->read(self::JS);
        $start = strpos($src, $signature);
        assertTrue($start !== false, 'no se encontró ' . $signature . ' en ' . self::JS);
        $end = strpos($src, $fin, (int) $start);
        return $end === false ? '' : substr($src, (int) $start, (int) $end - (int) $start);
    }

    /** Quita comentarios PHP: un `data-confirm` mencionado en una nota no cuenta. */
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

    private function read(string $rel): string
    {
        $abs = $this->path($rel);
        return is_file($abs) ? (string) file_get_contents($abs) : '';
    }

    private function path(string $rel): string
    {
        return dirname(__DIR__, 2) . '/' . $rel;
    }
}
