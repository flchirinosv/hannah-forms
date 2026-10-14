/* VanillaForms app.js */
(function () {
    'use strict';

    // ---- Ayuda con tooltip -----------------------------------------------------
    // Un `button.app-help[data-bs-toggle="tooltip"]` pinta su `data-bs-title` al
    // pasar por encima. Vive AQUÍ y no en builder.js/dashboard.js porque app.js
    // se carga en todas las páginas de `main.php` (justo después de bootstrap,
    // sin defer, con el DOM ya completo): un solo sitio que inicializa, sin
    // tener que acordarse de llamarlo en cada script de página.
    //
    // El trigger incluye `click` porque en un móvil no hay hover: sin él la
    // ayuda sería inalcanzable justo donde más se necesita. Y el clic se
    // neutraliza cuando el botón va DENTRO de un `<label for=...>` (que es lo
    // normal: la ayuda cuelga de la etiqueta del control), porque el clic en un
    // label reenvía al control y abrir la ayuda acabaría encendiendo o apagando
    // el interruptor de al lado.
    document.querySelectorAll('.app-help[data-bs-toggle="tooltip"]').forEach(function (btn) {
        if (!window.bootstrap || !bootstrap.Tooltip) return;
        var opts = { trigger: 'hover focus click', container: 'body' };
        try {
            bootstrap.Tooltip.getOrCreateInstance(btn, opts);
        } catch (err) {
            /* sin tooltip no se rompe nada: el texto sigue en data-bs-title */
        }
        btn.addEventListener('click', function (e) {
            if (btn.closest('label')) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });

    // ---- Red de la casa: vfToast() y vfPost() -------------------------------
    // Escape de & < > y comillas: el resultado vale tanto en texto como dentro
    // de un atributo entre comillas (mismo criterio que builder.js/dashboard.js).
    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /* Aviso flotante. `.app-toast-wrap` / `.app-toast` ya están en app.css, que
     * es global: por eso esto vive en app.js y no en dashboard.js. La página de
     * respuestas (que usa este helper para los borros) no carga dashboard.js, y
     * duplicar el markup en los dos scripts era tener dos avisos que no se
     * parecían. `dashboard.js` sigue teniendo su `toast()` local, pero ahora
     * delega aquí: una sola implementación. */
    window.vfToast = function (msg, type) {
        if (!msg) return;
        var wrap = document.getElementById('toastWrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'toastWrap';
            wrap.className = 'app-toast-wrap';
            // `role="status"` + `aria-live`: el aviso es la única confirmación
            // que hay —la página no se recarga— y sin esto un lector de
            // pantalla no se entera de que lo que tocó se guardó.
            wrap.setAttribute('role', 'status');
            wrap.setAttribute('aria-live', 'polite');
            document.body.appendChild(wrap);
        }
        var t = document.createElement('div');
        t.className = 'app-toast text-bg-' + (type || 'success') + ' shadow';
        t.innerHTML = '<i class="fa-solid ' + (type === 'danger' ? 'fa-circle-xmark' : 'fa-circle-check') + ' me-2"></i>'
            + esc(msg);
        wrap.appendChild(t);
        setTimeout(function () { t.classList.add('show'); }, 20);
        setTimeout(function () {
            t.classList.remove('show');
            setTimeout(function () { t.remove(); }, 300);
        }, 2400);
    };

    /* El token CSRF global del panel (`<body data-csrf>` de main.php). Un
     * `FormData` construido desde un formulario NO lo necesita: ese formulario
     * ya trae su `_token` en `Csrf::field()`, y las rutas de estado usan el
     * middleware `csrf` (que mira el POST), no `csrf-header`. Esto está para los
     * POST hechos desde JS sin formulario. */
    function vfCsrfToken() {
        return document.body ? (document.body.getAttribute('data-csrf') || '') : '';
    }

    /* ---------------------------------------------------------------
     * `window.vfPost(url, body)` — POST por fetch con el contrato de la casa
     *
     * El servidor bimodal (`AccessController::addEmail()` primero, y detrás de
     * él las acciones de la ficha) responde JSON cuando ve `X-Requested-With`
     * y con el 302 de siempre cuando no lo ve. Así que:
     *
     *  - Se resuelve SOLO si la respuesta es JSON de verdad. No «a ver si
     *    `r.json()` no revienta»: una respuesta HTML tunesca llega con 200 (una
     *    página de error, un aviso del servidor web, el índice de un
     *    despliegue mal hecho) y parsearla a la fuerza da un error que se
     *    confunde con «el servidor dijo que no». Mirar el `Content-Type` separa
     *    las dos cosas, y quien llama usa esa separación: un `ok:false` se
     *    PINTA, un rechazo cae al POST nativo.
     *  - `X-CSRF-Token` sale del `<body>` (ver `vfCsrfToken`).
     *  - `body` puede ser un `FormData` (se manda tal cual, que es lo que
     *    necesita un formulario con casillas y sus `hidden`), un objeto (se
     *    serializa a JSON) o `null`.
     * --------------------------------------------------------------- */
    window.vfPost = function (url, body, opts) {
        opts = opts || {};
        if (typeof window.fetch !== 'function') {
            return Promise.reject(new Error('fetch no disponible'));
        }

        var headers = { 'X-Requested-With': 'XMLHttpRequest' };
        var token = vfCsrfToken();
        if (token) headers['X-CSRF-Token'] = token;

        var payload = null;
        if (typeof FormData !== 'undefined' && body instanceof FormData) {
            payload = body; // el navegador pone el boundary del multipart
        } else if (body !== null && body !== undefined) {
            headers['Content-Type'] = 'application/json; charset=utf-8';
            payload = JSON.stringify(body);
        }

        var extra = opts.headers || {};
        Object.keys(extra).forEach(function (k) { headers[k] = extra[k]; });

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: payload
        }).then(function (res) {
            var ct = (res.headers && res.headers.get) ? (res.headers.get('content-type') || '') : '';
            if (ct.indexOf('application/json') === -1) {
                throw new Error('la respuesta no es JSON: ' + ct);
            }
            return res.json().then(function (json) {
                return {
                    ok: res.ok && json.ok !== false,
                    status: res.status,
                    json: json || {}
                };
            });
        });
    };

    // Auto-ocultar alertas después de unos segundos
    document.querySelectorAll('.alert-dismissible.fade').forEach(function (alert) {
        setTimeout(function () {
            var instance = bootstrap && bootstrap.Alert ? bootstrap.Alert.getOrCreateInstance(alert) : null;
            if (instance) instance.close();
        }, 4000);
    });

    // Auto-focus en el primer campo del auth
    var firstInput = document.querySelector('.auth-wrapper input.autofocus, .auth-card input[required]');
    if (firstInput) firstInput.focus();

    /* ---------------------------------------------------------------
     * Envío de formularios: confirmación y POST sin recarga
     *
     * UN solo delegado para las dos cosas, y en este orden: si el
     * formulario lleva `data-confirm`, primero se pregunta (igual que
     * siempre) y solo después se decide si el envío va por fetch. Con dos
     * `addEventListener('submit')` el orden dependía del que se anotara
     * primero, y una línea cambiada de sitio podía dejar saliendo un POST
     * sin preguntar o una pregunta sobre un envío que ya había salido.
     *
     * - `data-vf-async` es lo que hace el envío por fetch: sin el atributo,
     *   el formulario se va por su POST nativo de siempre.
     * - Su valor es el modo: `slots` (el de por defecto) aplica el `html`
     *   que devuelve el servidor y avisa; `remove` además quita la fila.
     * - `form.submit()` NO dispara este evento, así que la vuelta al camino
     *   nativo no vuelve a entrar aquí.
     * --------------------------------------------------------------- */
    function vfApplySlots(html) {
        if (!html || typeof html !== 'object') return;
        Object.keys(html).forEach(function (id) {
            var slot = document.getElementById(id);
            if (slot) slot.innerHTML = html[id];
        });

        /* Un hueco repuesto puede traer dentro un contenedor de pestañas
         * (`data-vf-tabs`): la tarjeta de Acceso entera es el hueco `vfSlotAccess`
         * y guardar los ajustes la repinta. El HTML nuevo no lleva el `hidden`
         * que puso el arranque de `dashboard.js`, así que sin reinicializarlas se
         * verían las dos zonas a la vez. La función la expone `dashboard.js`; en
         * el builder no existe y la guarda lo deja pasar. */
        if (typeof window.vfInitTabs === 'function') {
            window.vfInitTabs();
        }
    }

    // El botón de #formUpdate vive FUERA del formulario (usa `form="formUpdate"`),
    // así que `querySelector` dentro del form no lo encuentra y sin esto esa
    // acción no se deshabilita mientras está en vuelo: dos clics, dos POST.
    function vfSubmitButton(form) {
        var btn = form.querySelector('[type="submit"]');
        if (!btn && /^[A-Za-z][\w-]*$/.test(form.id || '')) {
            btn = document.querySelector('button[form="' + form.id + '"]');
        }
        return btn;
    }

    /* El giro dentro del botón: se busca un `.spinner-border` ya puesto en el
     * markup (con `d-none`) y se quita el `d-none` mientras dura el POST. Es un
     * contrato con la vista y no una invención aquí: el JS no crea nodos de
     * marcado propio porque no es el dueño de ese HTML. Un botón sin giro
     * simplemente se apaga. */
    function vfSpinner(btn) {
        return btn ? btn.querySelector('.spinner-border') : null;
    }

    /* `data-vf-<algo>="<id>"` → ese elemento, o `null` si el formulario no lo
     * declara. Es el puntero de una vista a su propio mensaje de error y a su
     * campo, sin que el JS tenga que saber cómo se llaman. */
    function vfByAttr(form, attr) {
        var id = form.getAttribute(attr);
        return id ? document.getElementById(id) : null;
    }

    /* ---------------------------------------------------------------
     * Confirmar en un DIÁLOGO, no en `confirm()`
     *
     * El `confirm()` del navegador es feo, no se puede estilar y no lo puedes
     * ni señalar ni traducir; el diálogo de Bootstrap sí, y además es un
     * `role="dialog"` con trampa de foco, `Esc` y vuelta del foco al botón, que
     * es accesibilidad y no decoración.
     *
     * El diálogo es UNO en todo el panel (`views/layouts/main.php`), porque
     * copiarlo en cada vista es copiar el prompt feo por décimo lugar. Si no está
     * (una página que no usa ese layout) se cae a `confirm()`: la pregunta se
     * sigue haciendo, que es lo que no puede desaparecer nunca.
     * --------------------------------------------------------------- */
    var vfPending = null;      // {form, submitter} a la espera de «sí»
    var vfConfirmed = null;    // el form que ya pasó por el diálogo (una pasada)
    var vfWired = false;

    function vfConfirmBox() {
        var box = document.getElementById('vfConfirm');
        if (!box || typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
        return box;
    }

    /* El botón de «Sí» y el `Esc` los ata Bootstrap (`data-bs-dismiss`), así que
     * aquí solo hace falta lo que él no hace: decidir si se envía, y a cuál de
     * los dos botones se le pone el foco. Se ata al cargar y también desde
     * `vfAsk()`, que es idempotente por el `vfWired`, para que el diálogo
     * funcione aunque se llegue a él sin pasar por el final del fichero. */
    function vfConfirmWire() {
        if (vfWired || !vfConfirmBox()) return;
        vfWired = true;

        var ok = document.getElementById('vfConfirmOk');
        if (ok) ok.addEventListener('click', vfConfirmGo);

        var box = vfConfirmBox();
        box.addEventListener('hidden.bs.modal', function () { vfPending = null; });
        box.addEventListener('shown.bs.modal', function () {
            /* El foco va a «Cancelar», no al botón que borra: abrir un diálogo
             * con «Eliminar formulario» ya enfocado es un `Enter` de distancia
             * de algo que no tiene vuelta atrás. */
            var cancelar = document.getElementById('vfConfirmCancel');
            if (cancelar && typeof cancelar.focus === 'function') cancelar.focus();
        });
    }

    /* Devuelve `true` si ya se puede seguir (o sea: no había diálogo y el
     * `confirm()` nativo dijo que sí), y `false` si la decisión está pendiente
     * y la ha de tomar el diálogo. */
    function vfAsk(msg, form, submitter) {
        var box = vfConfirmBox();
        if (!box) {
            return window.confirm(msg);
        }

        var texto = document.getElementById('vfConfirmText');
        var ok = document.getElementById('vfConfirmOk');
        if (texto) texto.textContent = msg;      // NUNCA innerHTML: lo pinta la vista
        if (ok) {
            ok.textContent = form.getAttribute('data-confirm-ok') || 'Sí, continuar';
            // Rojo por defecto porque casi todas son destructivas; `primary` es
            // para lo que se puede repetir sin miedo (publicar, activar).
            ok.className = 'btn ' + (form.getAttribute('data-confirm-tone') === 'primary' ? 'btn-primary' : 'btn-danger');
        }

        vfPending = { form: form, submitter: submitter };
        vfConfirmWire();
        bootstrap.Modal.getOrCreateInstance(box).show();
        return false;
    }

    function vfConfirmGo() {
        var p = vfPending;
        vfPending = null;
        if (vfConfirmBox()) bootstrap.Modal.getOrCreateInstance(vfConfirmBox()).hide();
        if (!p || !p.form) return;

        if (typeof p.form.requestSubmit === 'function') {
            /* `requestSubmit()` y NO `submit()`: solo el primero dispara el
             * evento `submit`, que es el que lleva el envío por fetch. Con
             * `submit()` se iría al POST nativo y el diálogo sin recarga
             * dejaría de funcionar justo en las acciones que lo piden.
             *
             * Y `vfConfirmed` se pone ANTES, porque la segunda pasada del evento
             * es la que no tiene que volver a preguntar. */
            vfConfirmed = p.form;
            p.form.requestSubmit(p.submitter && p.submitter.form === p.form ? p.submitter : undefined);
            return;
        }
        p.form.submit();
    }

    /* El delegado de envío. Con nombre y no como `function (e)` anónimo en el
     * `addEventListener` porque es lo que extrae `tests/js/async-actions.js`
     * para correrlo contra un DOM mínimo: una función anónima no se puede
     * sacar del fichero, y el comportamiento que importa (el camino nativo de
     * rescate) es justamente el que hay que poder ejecutar. */
    function vfHandleSubmit(e) {
        var form = e.target;
        if (!form || form.nodeName !== 'FORM') return;

        /* Confirmación genérica de envío (CSP: sin onsubmit inline).
         *
         * `data-confirm` va en el `<form>`, y la segunda pasada (la que hace
         * `vfConfirmGo()` con `requestSubmit`) NO vuelve a preguntar: sin el
         * `vfConfirmed` el diálogo se abriría una vez por cada vez que el
         * formulario se reintentara, y con un formulario reenviado por el
         * navegador eso es un bucle de diálogos. */
        var msg = form.getAttribute('data-confirm');
        if (msg && form !== vfConfirmed) {
            /* Se previene DESPUÉS de la respuesta, no antes: si el diálogo (o el
             * `confirm()` nativo) dice que sí, la `preventDefault()` de la línea
             * siguiente no hacía falta y el conteo mentía. */
            if (!vfAsk(msg, form, e.submitter || vfSubmitButton(form))) {
                e.preventDefault();
                return;
            }
        } else if (msg) {
            vfConfirmed = null;
        }

        var modo = form.getAttribute('data-vf-async');
        if (!modo) return;                                  // envío nativo
        if (typeof window.fetch !== 'function') return;     // sin fetch, nativo
        if (form.getAttribute('data-vf-busy')) return;      // ya en vuelo

        e.preventDefault();

        // El FormData se construye ANTES de deshabilitar el botón: un control
        // deshabilitado no viaja.
        var data = new FormData(form);
        var btn = vfSubmitButton(form);
        var spin = vfSpinner(btn);
        form.setAttribute('data-vf-busy', '1');
        if (btn) {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
        }
        if (spin) spin.classList.remove('d-none');

        var soltar = function () {
            form.removeAttribute('data-vf-busy');
            if (btn) {
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
            }
            if (spin) spin.classList.add('d-none');
        };

        /* Dos handlers, no `.then(...).catch(...)`: el rescate es para cuando
         * la PETICIÓN falla, no para cuando falla el pintado. Con `.catch` al
         * final, una excepción dentro del `.then` —un aviso que no se puede
         * pintar, un `innerHTML` que revienta— caía en el mismo brazo que la
         * red caída y `form.submit()` reenviaba el formulario: un fallo al
         * ENSEÑAR el resultado se convertía en una segunda operación. */
        window.vfPost(form.getAttribute('action') || window.location.href, data)
            .then(function (r) {
                /* El UI se suelta PRIMERO, antes de pintar nada. Al revés, una
                 * excepción al reponer los huecos o al enseñar el aviso
                 * dejaba el botón apagado para siempre (con `data-vf-busy`
                 * puesto encima) y el formulario inservible sin ningún error a
                 * la vista: solo en la consola. */
                soltar();

                /* Los dos punteros que el formulario puede declarar, resueltos
                 * una vez: `data-vf-error` (dónde va el «no») y `data-vf-clear`
                 * (el campo que queda listo para el siguiente intento). */
                var destino = vfByAttr(form, 'data-vf-error');
                var limpiar = vfByAttr(form, 'data-vf-clear');

                if (!r.ok) {
                    /* Los huecos se reponen ANTES de mirar si la operación tuvo
                     * éxito. Un `ok:false` con `html` es el caso del informe de una
                     * importación con filas malas: el aviso dice «5 filas quedaron
                     * fuera» y el informe dice cuáles, y ese «cuáles» es justo lo
                     * que se pierde si el repintado fuera solo del camino bueno
                     * (RosterPanelTest lo ata). Reponer no es «hacer la operación»:
                     * aquí el servidor ya la ha hecho. */
                    vfApplySlots(r.json.html);

                    /* Un `ok:false` es el servidor diciendo que NO, y su motivo se
                     * pinta donde toca: si el formulario ha declarado un
                     * destino (`data-vf-error`), el aviso va junto a su campo —
                     * que es donde se busca y donde lo lee el lector de
                     * pantalla — y NO sale además flotante, que es repetir lo
                     * mismo dos veces. */
                    var motivo = r.json.error || r.json.message || 'No se pudo completar la acción.';
                    if (destino) {
                        destino.textContent = motivo;
                        destino.classList.remove('d-none');
                    } else {
                        window.vfToast(motivo, 'danger');
                    }
                    return;
                }

                vfApplySlots(r.json.html);

                // `redirect` es para las acciones cuya pantalla ya no existe
                // (eliminar el formulario, borrar la última respuesta): no hay
                // nada que repintar, solo a dónde ir.
                if (r.json.redirect) {
                    window.location.assign(r.json.redirect);
                    return;
                }

                if (modo === 'remove') {
                    var fila = form.closest('[data-vf-row]');
                    if (fila) fila.remove();
                    // El contador del encabezado («Respuestas (12)») es del
                    // servidor y el borrado no lo toca: sin esto, la lista
                    // acertaba y el número no.
                    var contador = document.querySelector('[data-vf-role="response-count"]');
                    if (contador && typeof r.json.count === 'number') {
                        contador.textContent = r.json.count > 0 ? '(' + r.json.count + ')' : '';
                    }
                    // Sin filas, la tabla vacía con su «aún no tiene
                    // respuestas» mintiendo: recargar es lo honesto, y borrar la
                    // última respuesta es el caso raro.
                    if (r.json.count === 0) {
                        window.location.reload();
                        return;
                    }
                }

                if (r.json.message) window.vfToast(r.json.message);

                /* Éxito: el aviso del intento anterior se borra (si lo hubo, lo
                 * dejó el mismo `destino` de arriba con un «no»), y `data-vf-clear`
                 * vacía el campo y devuelve el foco a él para encadenar la misma
                 * acción sin tabular hasta el principio. El foco se pone a mano
                 * porque un `role="alert"` que desaparece es justo lo que roba el
                 * foco en algunos lectores de pantalla. */
                if (destino) {
                    destino.textContent = '';
                    destino.classList.add('d-none');
                }
                if (limpiar) {
                    limpiar.value = '';
                    if (typeof limpiar.focus === 'function') limpiar.focus();
                }
            }, function () {
                // Solo si la PETICIÓN falló: sin red, respuesta que no es JSON
                // o un `window.fetch` que no existe. Es el camino nativo, que
                // es el que ya funcionaba.
                soltar();
                form.submit();
            });
    }

    document.addEventListener('submit', vfHandleSubmit);
    vfConfirmWire();

    /* ---------------------------------------------------------------
     * Ctrl/Cmd+S sobre un formulario con `data-vf-shortcut`
     *
     * Guardar los ajustes con el atajo que todo el mundo ya tiene en la
     * cabeza, en vez de buscar el botón en una tarjeta con siete grupos
     * plegados. Va con el FOCO DENTRO del formulario a propósito (`closest`):
     * un atajo global quitaría el «guardar página» del navegador de debajo de
     * quien está escribiendo el título o la descripción, y `data-vf-shortcut`
     * solo está en el formulario de ajustes.
     *
     * `requestSubmit()` y no `submit()` porque el primero dispara el evento
     * `submit` —que es lo que lleva la confirmación y el envío por fetch— y el
     * segundo se lo salta entero.
     * --------------------------------------------------------------- */
    document.addEventListener('keydown', function (e) {
        if (!e.ctrlKey && !e.metaKey) return;
        if (String(e.key).toLowerCase() !== 's') return;

        var target = e.target;
        var form = target && target.closest ? target.closest('form[data-vf-shortcut]') : null;
        if (!form || form.getAttribute('data-vf-busy')) return;

        e.preventDefault();
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }
        // Sin `requestSubmit` (navegadores viejos): el evento es lo que dispara
        // el delegado, y si este Camino no existiera el Ctrl+S se comería el
        // diálogo del navegador sin guardar nada.
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });

    // Copiar enlace para compartir (CSP: sin onclick inline).
    document.querySelectorAll('.js-copy-url').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById('shareUrl');
            if (!input || !navigator.clipboard) return;
            navigator.clipboard.writeText(input.value).then(function () {
                btn.textContent = '¡Copiado!';
                setTimeout(function () { btn.textContent = 'Copiar'; }, 1500);
            });
        });
    });

    /* Auto-enviar el select de rol del admin al cambiar (CSP: sin onchange
     * inline).
     *
     * `requestSubmit()` y NO `submit()`: `submit()` no dispara el evento
     * `submit`, así que el delegado de más arriba —el que pregunta, el que
     * apaga el botón y el que decide entre fetch y POST nativo— no se enteraba
     * de nada y la fila se recargaba entera por cada cambio de rol. Lo segundo
     * es lo que dolía: recargar la página tira abajo el desplegable de grupos
     * que se estuviera editando al lado y cualquier otra cosa que estuviera a
     * medias en la pantalla.
     *
     * El `else` es para navegadores sin `requestSubmit()`: ahí sí `submit()`,
     * que es exactamente lo que se hacía antes. */
    function vfAutoSubmit(sel) {
        sel.addEventListener('change', function () {
            var form = sel.closest('form');
            if (!form) return;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    }
    document.querySelectorAll('.js-auto-submit').forEach(vfAutoSubmit);

    /* ---------------------------------------------------------------
     * Buscador de una lista de casillas (los miembros de un grupo)
     *
     * `data-vf-filter` marca el campo de búsqueda; la lista a filtrar es su
     * ancestro `[data-vf-list]`. Esconde las filas que no casan pero NO toca los
     * checkboxes: un input oculto sigue viajando en el POST, y la pertenencia a
     * un grupo es la lista COMPLETA —esconder a alguien no es sacarlo—. Por eso
     * el filtro es de cliente y no una consulta: filtrar en servidor dejaría
     * fuera del formulario a quien no casa, y guardar lo echaría del grupo sin
     * que nadie lo pidiera.
     * --------------------------------------------------------------- */
    function vfFilterList(input) {
        var cont = input.closest('[data-vf-list]');
        if (!cont) return;
        var items = Array.prototype.slice.call(cont.querySelectorAll('.app-rbac-item'));
        var vacio = cont.querySelector('.app-filter-empty');

        input.addEventListener('input', function () {
            var q = String(input.value || '').trim().toLowerCase();
            var visibles = 0;
            items.forEach(function (item) {
                var casa = q === '' || String(item.textContent || '').toLowerCase().indexOf(q) !== -1;
                item.hidden = !casa;
                if (casa) visibles++;
            });
            if (vacio) vacio.hidden = visibles > 0;
        });
    }
    document.querySelectorAll('[data-vf-filter]').forEach(vfFilterList);

    /* ---------------------------------------------------------------
     * Matriz de permisos (admin.roles)
     *
     * Tres cosas, todas de la misma pantalla:
     *
     * 1. Marcar una sección entera. Dar permisos a un rol nuevo son ~20 clics
     *    de casillas por sección, y la sección es la unidad que la persona
     *    piensa ("lo de formularios"), no la casilla. Un
     *    [data-vf-bulk-all="<prefijo>"] mueve todas las .js-perm de su sección.
     *
     * 2. El contador «n/total» de cada rol, en vivo. El de la cabecera lo
     *    pinta el servidor al cargar, pero marcar casillas no recarga, así que
     *    sin esto el número se queda congelado en el de la última carga y deja
     *    de cuadrar con lo que se ve marcado.
     *
     * 3. La altura de la cabecera pegajosa: los rótulos de sección se pegan
     *    justo debajo del encabezado, y esa altura depende del ancho (en móvil
     *    el nombre del rol ocupa dos líneas), así que se mide y se deja en una
     *    variable CSS en vez de fijarla a mano.
     * --------------------------------------------------------------- */

    /** Repinta los contadores de rol a partir de las casillas marcadas. */
    function syncRoleCounts(matrix) {
        var cells = Array.prototype.slice.call(matrix.querySelectorAll('.js-perm[data-vf-role-id]'));
        var counts = Array.prototype.slice.call(matrix.querySelectorAll('[data-vf-count]'));

        counts.forEach(function (el) {
            var rid = el.getAttribute('data-vf-count');
            var total = 0;
            var on = 0;
            cells.forEach(function (c) {
                if (c.getAttribute('data-vf-role-id') !== rid) return;
                total++;
                if (c.checked) on++;
            });
            el.textContent = on + '/' + total;
        });
    }

    function initRoleMatrix() {
        var matrix = document.querySelector('.app-rbac-matrix');
        if (!matrix) return;

        // La cabecera pegajosa se mide sobre el contenedor con scroll propio.
        var scroll = matrix.closest('.app-rbac-matrix-scroll');
        var thead = matrix.querySelector('thead');
        var ajustar = function () {
            if (scroll && thead) {
                scroll.style.setProperty('--vf-rbac-head', thead.offsetHeight + 'px');
            }
        };
        ajustar();
        window.addEventListener('resize', ajustar);

        var cells = Array.prototype.slice.call(matrix.querySelectorAll('.js-perm'));

        matrix.querySelectorAll('[data-vf-bulk-all]').forEach(function (bulk) {
            var section = bulk.getAttribute('data-vf-bulk-all');
            var seccion = cells.filter(function (c) {
                return c.getAttribute('data-vf-section') === section;
            });

            var sync = function () {
                var habiles = seccion.filter(function (c) { return !c.disabled; });
                var on = habiles.filter(function (c) { return c.checked; }).length;
                bulk.checked = habiles.length > 0 && on === habiles.length;
                bulk.indeterminate = on > 0 && on < habiles.length;
            };

            bulk.addEventListener('change', function () {
                seccion.forEach(function (c) {
                    if (!c.disabled) c.checked = bulk.checked;
                });
                sync();
                syncRoleCounts(matrix);
            });

            seccion.forEach(function (cell) {
                cell.addEventListener('change', function () {
                    sync();
                    syncRoleCounts(matrix);
                });
            });

            sync();
        });

        syncRoleCounts(matrix);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRoleMatrix);
    } else {
        initRoleMatrix();
    }
})();
