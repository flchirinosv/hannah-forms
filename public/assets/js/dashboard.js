/* VanillaForms — dashboard.js
 * Búsqueda, ordenación y copia de enlace.
 * Sin dependencias (usa Bootstrap solo para el dropdown del menú).
 *
 * M2 — La búsqueda y el orden ya NO filtran el listado entero en el
 * navegador: van en la URL (`?q=…&orden=…`) y el servidor pinta una página.
 * Aquí solo queda lo que hace que se sienta igual de rápido: el buscador
 * envía el formulario del GET con un retardo (como se escribe, no en cada
 * tecla) y el desplegable de orden envía al cambiar. Sin JS los dos son un
 * formulario GET normal y funcionan igual, y por eso no hay que replicar aquí
 * ningún filtro: lo que no se puede filtrar es lo que no se pinta.
 */
(function () {
    'use strict';

    /* Copiar enlace: delegado a nivel documento para que funcione también en el
     * detalle del formulario (views/dashboard/forms/show.php), que no tiene
     * #dashGrid. Común a index y forms/show. */
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-role="copy"], .btn-copy-link, .js-copy-url');
        if (btn) {
            e.preventDefault();
            copyLink(btn.dataset.url || '');
        }
    });

    const searchForm = document.querySelector('[data-vf-dash-search]');
    const search = document.getElementById('dashSearch');
    const sort = document.getElementById('dashSort');

    /* ---------- envío del GET ----------
     * `requestSubmit()` y no `submit()`: relanza el evento submit, así que un
     * enganche al formulario (o el diálogo de confirmación, si algún día lo
     * hubiera) lo ve igual. En su `else` se cae al envío nativo, que también
     * navega: un `Enter` del buscador nunca se queda sin hacer nada. */
    function send(form) {
        if (form.requestSubmit) {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    /* ---------- toast ----------
     * Delegado en `window.vfToast` (app.js). El aviso flotante y sus clases son
     * globales, y la página de respuestas —que borra filas por fetch— también
     * los usa sin cargar este script: mantener dos copias del markup era tener
     * dos avisos que no se parecían. Aquí queda solo el nombre corto que usan
     * las tres llamadas de más abajo. */
    function toast(msg, type) {
        window.vfToast(msg, type);
    }

    function copyLink(url) {
        if (!url) {
            return;
        }
        // Si la URL ya es absoluta (http/https), usarla tal cual; si es relativa,
        // anteponer el origen para copiarla completa.
        if (/^https?:\/\//i.test(url)) {
            copyText(url, 'Enlace copiado');
            return;
        }
        copyText(window.location.origin + url, 'Enlace copiado');
    }

    /**
     * Copia texto tal cual. `copyLink` le antepone el origen, pero el enlace de
     * acceso (F4) llega ABSOLUTO desde el servidor —que es quien sabe cuál es la
     * URL pública real, con `PublicUrl`— y anteponerle el origen lo convertiría
     * en `http://localhost/https://formularios.example/f/...`.
     */
    function copyText(text, okMsg) {
        const done = () => toast(okMsg || 'Copiado');
        const fail = () => toast('No se pudo copiar', 'danger');

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, fail);
            return;
        }
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            fail();
        }
        document.body.removeChild(ta);
    }

    /* ---------- init ---------- */
    function attach() {
        if (search && searchForm) {
            let timer = null;
            let last = search.value;
            search.addEventListener('input', () => {
                if (timer) {
                    clearTimeout(timer);
                }
                timer = setTimeout(() => {
                    // Escribir lo mismo que ya estaba no recarga nada: con el
                    // buscador de antes solo reordenaba las tarjetas, y aquí
                    // sería tirar la página entera por un espacio de más.
                    if (search.value === last) {
                        return;
                    }
                    last = search.value;
                    send(searchForm);
                }, 400);
            });
        }

        if (sort && searchForm) {
            sort.addEventListener('change', () => send(searchForm));
        }

        document.addEventListener('keydown', (e) => {
            const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable;
            if (e.key === '/' && !typing && search) {
                e.preventDefault();
                search.focus();
            }
        });
    }

    attach();
})();
/* P9/V6 — Modal de nuevo formulario: elegir arranque en blanco o plantilla.
 *
 * La elección es un ÚNICO grupo de radios `template` (value="" = en blanco),
 * como la galería de la portada de Google Forms: pulsar una tarjeta ya decide
 * el modo, así que no hay radios `mode` que sincronizar. El servidor tampoco
 * los lee: `FormController::store()` solo mira `template` (vacío = en blanco).
 *
 * Va fuera del bloque del grid porque el modal existe también en el estado
 * vacío (donde no hay #dashGrid).
 */
(function () {
    'use strict';

    const modal = document.getElementById('newFormModal');
    if (!modal) {
        return;
    }
    const cards = Array.prototype.slice.call(modal.querySelectorAll('.app-tpl-card'));
    const inputs = Array.prototype.slice.call(modal.querySelectorAll('input[name="template"]'));
    if (inputs.length === 0) {
        return;
    }

    // El título es opcional (vacío = nombre de la plantilla o «Formulario sin
    // título»), así que aquí solo queda realzar la tarjeta marcada: es el
    // respaldo del `:has(input:checked)` del CSS para navegadores viejos.
    function apply() {
        cards.forEach((card) => {
            const r = card.querySelector('input[name="template"]');
            card.classList.toggle('app-tpl-checked', !!(r && r.checked));
        });
    }

    inputs.forEach((r) => r.addEventListener('change', apply));
    apply();
})();

/* F4 — «Copiar enlace» de la allowlist (views/dashboard/forms/show.php).
 *
 * Va en su propio bloque, y no dentro del de arriba, por una razón concreta: el
 * bloque de la búsqueda hace `if (!grid) return;` porque en la ficha del
 * formulario no hay rejilla. Un handler de la lista de acceso añadido ahí dentro
 * sería código muerto en el dashboard y código vivo solo en la ficha, según la
 * ruta por la que se llegara. Aquí, al final y sin `return`, funciona en las dos.
 *
 * El enlace se pide al servidor y no se «compone» en el cliente porque el token
 * se emite en ese momento: es la única vez que existe en claro fuera del correo
 * (igual que en `FormAccess::issue()`). Volver a pedirlo revoca el anterior, y el
 * botón dice «copiar» y no «ver» por eso: cada clic da un enlace nuevo y válido.
 */
(function () {
    'use strict';

    /* Recopiar lo ya emitido. Va en el mismo bloque y no en el de `copyLink` de
     * arriba porque este NO prepende el origen: la URL del servidor ya es
     * absoluta y `copyLink('https://…')` produciría `http://host/https://…`. */
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-role="access-recopy"]');
        if (!btn) {
            return;
        }
        e.preventDefault();
        const field = btn.parentElement.querySelector('input');
        if (field && field.value) {
            copyText(field.value, 'Enlace copiado');
        }
    });

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-role="access-link"]');
        if (!btn) {
            return;
        }
        e.preventDefault();

        const card = btn.closest('#access');
        if (!card || btn.disabled) {
            return;
        }
        const endpoint = card.dataset.linkEndpoint;
        const email = btn.dataset.email || '';
        if (!endpoint) {
            return;
        }

        btn.disabled = true;
        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-CSRF-Token': card.dataset.csrf || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: 'email=' + encodeURIComponent(email),
        })
            .then(function (r) {
                return r.json().then(function (data) {
                    return { ok: r.ok && data.ok, data: data };
                });
            })
            .then(function (res) {
                if (res.ok && res.data.url) {
                    // El enlace va al portapapeles Y se muestra en un campo: si el
                    // navegador no deja copiar (permisos, http sin contexto
                    // seguro) el enlace sigue a la vista para seleccionarlo a
                    // mano. Y como no se puede volver a pedir el MISMO token
                    // (emitir uno nuevo revoca el anterior), perderlo no sería un
                    // contratiempo sin importancia.
                    const out = document.getElementById('accessLinkOut');
                    if (out) {
                        out.classList.remove('d-none');
                        const field = out.querySelector('input');
                        if (field) {
                            field.value = res.data.url;
                            field.select();
                        }
                        const who = out.querySelector('[data-role="access-link-for"]');
                        if (who) {
                            who.textContent = email;
                        }
                    }
                    copyText(res.data.url, 'Enlace de acceso copiado');
                } else {
                    toast((res.data && res.data.error) || 'No se pudo generar el enlace', 'danger');
                }
            })
            .catch(function () {
                toast('No se pudo generar el enlace', 'danger');
            })
            .then(function () {
                btn.disabled = false;
            });
    });
})();

/* Ficha del formulario (`views/dashboard/forms/show.php`): los ajustes
 * operativos se editan aquí, no en el modal del builder, así que sus textos
 * de estado los pinta este archivo.
 *
 * Son ESTADO, no explicación: el texto depende del interruptor y quien acaba
 * de cambiarlo lo necesita ver sin tener que volver a pasar el ratón por
 * encima. Por eso NO son tooltips y por eso el constructor (`builder.js`) ya
 * no los escribe. */
(function () {
    'use strict';

    const accessSel = document.getElementById('access_mode');

    if (!accessSel) {
        return;
    }

    /* F4 — Los modos de acceso no se distinguen solos: 'email' y
     * 'allowlist' los dos piden un correo y mandan un enlace, y lo único que
     * los separa es si el correo tiene que estar en una lista. Sin esta frase
     * alguien elige 'email' pensando que es la lista y se queda con un
     * formulario abierto a cualquiera que escriba un correo.
     *
     * Fase 6 — `roster` se añade como SU frase, no como otra más del `else`: el
     * identificador (`cédula` / `indicador` / `correo`) solo significa algo aquí,
     * así que el cuarto modo necesita un cuarto modo de explicar. Y el `else` se
     * queda siendo el de la allowlist a propósito: es el que no tiene nombre
     * propio y el que más fácil es cambiar sin querer. */
    function updateAccessHint() {
        const hint = document.getElementById('stAccessHint');
        if (!hint || !accessSel) {
            return;
        }
        if (accessSel.value === 'public') {
            hint.textContent = 'Sin identificación: cualquiera con el enlace responde.';
        } else if (accessSel.value === 'email') {
            hint.textContent = 'Pide un correo y envía un enlace. No hay lista: cualquier correo válido entra.';
        } else if (accessSel.value === 'roster') {
            hint.textContent = 'Solo entran las personas del listado, con su código o con el identificador elegido. La lista se gestiona en la tarjeta de Acceso.';
        } else {
            hint.textContent = 'Solo los correos de la lista reciben un enlace. La lista vacía cierra el formulario a todo el mundo.';
        }
    }

    /* Fase 6 — El identificador solo tiene sentido con lista, así que se enseña y
     * se esconde con el modo.
     *
     * Lo pone el SERVIDOR el estado inicial (el `<select>` viene sin `hidden` en
     * `roster` y con él en los otros modos), y esto solo lo mantiene al día al
     * cambiar de modo antes de guardar. Sin JavaScript el camino también existe,
     * con **un guardado de más**: se elige `roster`, se guarda, y al reabrir la
     * ficha el selector ya está a la vista. Lo que depende del JS es que se
     * enseñe al vuelo, no que exista.
     *
     * **`hidden` y `disabled` van juntos, y no es un descuido**: un `<select>`
     * escondido sigue mandando su valor, así que sin `disabled` un formulario en
     * «Público» guardaría «cédula» —la primera opción— en cada guardado de
     * cualquier ajuste, y al volver al listado el identificador elegido en su día
     * habría desaparecido sin que nadie lo tocase. */
    function updateIdentifierBox() {
        const box = document.getElementById('accessIdentifierBox');
        const sel = document.getElementById('access_identifier');
        if (!box || !sel || !accessSel) {
            return;
        }
        const roster = accessSel.value === 'roster';
        if (roster) {
            box.removeAttribute('hidden');
            sel.removeAttribute('disabled');
        } else {
            box.setAttribute('hidden', '');
            sel.setAttribute('disabled', '');
        }
    }

    // Las dos van en el mismo `change` y no en dos `addEventListener`: el modo
    // manda sobre la frase y sobre si el identificador está a la vista, y
    // separarlas es la forma de que una se quede atrás.
    accessSel.addEventListener('change', function () {
        updateAccessHint();
        updateIdentifierBox();
    });
    updateAccessHint();
    updateIdentifierBox();
})();

/* El ALTA de un correo a la allowlist (`#accessAddForm`) ya no tiene un
 * handler propio: la hace el delegado de `app.js` como cualquier otra acción con
 * `data-vf-async="slots"`, y la lista vuelve por `html.accessList`.
 *
 * Aquí hubo uno, y era la razón de que «añadir correo» recargara la página. Leía
 * `card.dataset.addEndpoint` —un atributo que la vista no tiene: la tarjeta
 * declara `data-link-endpoint`, el del botón de copiar el enlace—, así que
 * `endpoint` salía `undefined`, la línea de guarda `if (!endpoint) return;` lo
 * apagaba sin quitar el evento, y el navegador sacaba su POST nativo. La lista
 * se repintaba porque la página entera venía de vuelta: por eso quitar un
 * correo (que sí pasó por fetch) no recargaba y añadirlo sí, y por eso el
 * síntoma parecía del servidor cuando el atributo lo había perdido la vista.
 *
 * El aviso de error y el campo también los pone ahora el delegado
 * (`data-vf-error` y `data-vf-clear`): el `role="alert"` es el mismo. */
/* Interruptor del registro público (`/admin`).
 *
 * Es de la instalación, no de la cuenta de nadie, así que va con su propio
 * permiso y su propio endpoint. El interruptor NO se reposiciona al terminar:
 * si el guardado fallara, moverlo sería mentir sobre el estado real (que es
 * justo lo que haría creerse al que cerrara el registro y lo dejara abierto).
 * El servidor devuelve el `enabled` que ha quedado guardado y se pinta ese. */
(function () {
    'use strict';

    const card = document.getElementById('instance');
    if (!card) {
        return;
    }

    const toggle = document.getElementById('registrationToggle');
    const state = card.querySelector('[data-role="registration-state"]');
    const hint = document.getElementById('registrationHint');
    if (!toggle || typeof window.fetch !== 'function') {
        return;
    }

    toggle.addEventListener('change', function () {
        const body = new URLSearchParams();
        body.set('_token', card.dataset.csrf || '');
        body.set('enabled', toggle.checked ? '1' : '0');

        toggle.disabled = true;
        if (hint) {
            hint.textContent = 'Guardando…';
            hint.className = 'form-text mb-0 text-muted';
        }

        fetch(card.dataset.registrationEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString(),
        })
            .then(function (r) {
                return r.json().then(function (data) {
                    return { ok: r.ok && data.ok, data: data };
                });
            })
            .then(function (res) {
                const enabled = !!(res.data && res.data.enabled);
                // Siempre: también en error, porque `enabled` es lo que hay en la
                // BD ahora mismo y la casilla tiene que decir la verdad.
                toggle.checked = enabled;
                if (state) {
                    state.textContent = enabled ? 'Registro público abierto' : 'Registro público cerrado';
                }
                if (hint) {
                    const ok = res.ok;
                    hint.textContent = ok
                        ? (res.data.message || '')
                        : ((res.data && res.data.error) || 'No se pudo guardar el ajuste.');
                    hint.className = 'form-text mb-0 ' + (ok ? 'text-success' : 'text-danger fw-semibold');
                }
            })
            .catch(function () {
                if (hint) {
                    hint.textContent = 'No se pudo contactar con el servidor: el ajuste sigue como estaba.';
                    hint.className = 'form-text mb-0 text-danger fw-semibold';
                }
            })
            .then(function () {
                toggle.disabled = false;
            });
    });
})();

/* Sincronía del selector de color con su campo de texto (`/admin/settings`, AG2).
 *
 * Van juntos porque uno es la forma cómoda (picker) y el otro la precisa (hex):
 * sin esto, elegir un color en el picker no se guardaría (el POST manda el
 * texto) y escribir un hex no movería el picker. El gancho es `data-vf-color-for`
 * y no un `oninput` en línea, que la CSP prohíbe. */
(function () {
    'use strict';

    document.querySelectorAll('[data-vf-color-for]').forEach(function (picker) {
        const target = document.getElementById(picker.dataset.vfColorFor);
        if (!target) {
            return;
        }
        picker.addEventListener('input', function () {
            target.value = picker.value;
        });
        target.addEventListener('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(target.value)) {
                picker.value = target.value;
            }
        });
    });
})();

/* ---- Grupos colapsables (views/dashboard/forms/show.php y views/admin/groups.php)
 * En la ficha del formulario son seis grupos porque, con los seis abiertos,
 * «Ajustes del formulario» ocupaba más de tres pantallas y el ajuste que se busca
 * casi nunca está en el primero; en «Grupos» cada tarjeta es un grupo y abre en su
 * estado por defecto.
 *
 * El plegado es de Bootstrap (`data-bs-toggle="collapse"`), así que esto solo
 * recuerda en qué estado quedó cada uno: el estado va en `localStorage` POR
 * PANTALLA (`vf:grp:<clave>`) y no en el del navegador, porque el mismo usuario
 * pasa por varias fichas y cerrarlos todos en cada una era más ruido que el que
 * arreglaba. Cada contenedor trae su clave: la ficha la sigue nombrando
 * `data-vf-form-id` (su clave es el id del formulario y no cambia), y la lista de
 * grupos de `/admin/groups` usa `data-vf-group-key="admin-grupos"`.
 * `data-vf-group-default="closed"` es lo que
 * distingue «cerrado de entrada» de «abierto de entrada», sin tener que mantener
 * dos comportamientos distintos según la pantalla.
 *
 * `bootstrap.bundle` viene en el layout y este script va con `defer`, así que el
 * global existe cuando esto corre; aun así se resuelve en `window.bootstrap` como en
 * builder.js, para que el archivo siga siendo inocuo si se carga antes.
 */
(function () {
    'use strict';

    const contenedores = Array.prototype.slice.call(
        document.querySelectorAll('[data-vf-group-key], [data-vf-form-id]')
    );
    if (contenedores.length === 0) { return; }

    /* Un error de guardado sale en el aviso de arriba, no dentro del grupo que lo
     * provocó: si el grupo estaba plegado, el aviso no señala dónde está el problema
     * y quien guarda tiene que abrir los seis a ciegas. Con error, se ignoran los
     * estados guardados y se abren todos. En «Usuarios» el aviso de un alta
     * rechazada hace lo mismo que aquí: abrir el formulario para poder corregirlo. */
    const hayError = !!document.querySelector('.alert-danger.app-alert');

    contenedores.forEach(function (cont) {
        const clave = cont.dataset.vfGroupKey || cont.dataset.vfFormId;
        if (!clave) { return; }

        const KEY = 'vf:grp:' + clave;
        const toggles = Array.prototype.slice.call(cont.querySelectorAll('.app-group-toggle'));
        if (toggles.length === 0) { return; }

        const abiertoPorDefecto = cont.dataset.vfGroupDefault !== 'closed';

        let estado = {};
        if (!hayError) {
            try {
                const crudo = window.localStorage.getItem(KEY);
                const parsed = crudo ? JSON.parse(crudo) : null;
                if (parsed && typeof parsed === 'object') { estado = parsed; }
            } catch (e) {
                estado = {}; // Almacenamiento lleno o bloqueado: los grupos funcionan igual.
            }
        }

        function guardar() {
            try {
                window.localStorage.setItem(KEY, JSON.stringify(estado));
            } catch (e) { /* sin persistencia no se rompe nada más */ }
        }

        const guardado = function (slug) {
            return Object.prototype.hasOwnProperty.call(estado, slug);
        };

        /* Los `hide()` de arranque también disparan `hidden.bs.collapse`, y esa
         * escritura no es de la persona: guardaría el valor por defecto como si
         * fuera una elección suya y, si mañana cambiara el default, seguiría viendo
         * el viejo. Solo se escribe lo que alguien toca de verdad. */
        const decididos = {};

        if (window.bootstrap && bootstrap.Collapse) {
            toggles.forEach(function (btn) {
                const panel = document.getElementById(btn.getAttribute('aria-controls'));
                if (!panel) { return; }
                const slug = panel.id.replace(/^grp-/, '');
                const visible = panel.classList.contains('show');
                const abierto = guardado(slug) ? estado[slug] !== false : abiertoPorDefecto;
                const col = bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false });
                if (abierto && !visible) { decididos[slug] = true; col.show(); }
                if (!abierto && visible) { decididos[slug] = false; col.hide(); }
            });
        }

        toggles.forEach(function (btn) {
            const panel = document.getElementById(btn.getAttribute('aria-controls'));
            if (!panel) { return; }
            const slug = panel.id.replace(/^grp-/, '');
            const persistir = function (abierto) {
                if (Object.prototype.hasOwnProperty.call(decididos, slug)) {
                    if (decididos[slug] === abierto) { delete decididos[slug]; return; }
                }
                estado[slug] = abierto;
                guardar();
            };
            panel.addEventListener('shown.bs.collapse', function () { persistir(true); });
            panel.addEventListener('hidden.bs.collapse', function () { persistir(false); });
        });
    });
})();

/* ---------------------------------------------------------------------------
 * Modales del panel: MOVER, no duplicar.
 *
 * Las altas y las acciones de fila del panel viven en un diálogo, pero su
 * marcado se sirve EN LÍNEA en la vista (que es lo que se ve sin JS) y este
 * bloque lo traslada al modal. La alternativa —duplicar el formulario, una
 * copia en línea y otra dentro del modal— son dos sitios que mantener y un
 * `<noscript>` que solo tapa la copia duplicada, no la ausencia de una.
 *
 *  - `data-vf-modal-move="#modal"` en un contenedor + `data-vf-modal-form` en
 *    un hijo: el hijo se mueve al `[data-vf-modal-body]` y el contenedor se
 *    oculta (el botón de la cabecera abre el diálogo).
 *  - `[data-vf-user]` abre el `#userActionsModal` con el panel de ESA fila
 *    (`#rowactions-<uid>`) movido dentro y devuelto a su fila al cerrar, porque
 *    los formularios, los `id` de los huecos y los avisos son de esa fila.
 *    No hay un modal por usuario: sería clonar el panel una vez por fila.
 *  - `[data-vf-participant]` hace lo mismo en la pantalla de participantes
 *    (`/forms/{id}/access`): abre `#rosterEditModal` con el panel de esa fila
 *    (`#rowactions-<pid>`). Mismo patrón, otro listado.
 * ------------------------------------------------------------------------- */
(function () {
    'use strict';

    function moverFormulariosInline() {
        const montajes = Array.prototype.slice.call(document.querySelectorAll('[data-vf-modal-move]'));
        montajes.forEach(function (montaje) {
            const destino = document.querySelector(montaje.getAttribute('data-vf-modal-move'));
            if (!destino) { return; }
            const cuerpo = destino.querySelector('[data-vf-modal-body]');
            let formulario = montaje.querySelector('[data-vf-modal-form]');
            if (!formulario && montaje.hasAttribute('data-vf-modal-form')) {
                formulario = montaje;
            }
            if (!cuerpo || !formulario) { return; }
            cuerpo.appendChild(formulario);
            montaje.hidden = true;
        });
    }

    function initAccionesDeFila(modalId, atributo, prefijoCaja) {
        const modalEl = document.getElementById(modalId);
        if (!modalEl || !window.bootstrap) { return; }
        /* Idempotente: `iniciar()` puede reejecutarse tras un repintado, y sin esta
         * guarda cada llamada acumularía un `click` más en el documento. */
        if (modalEl.getAttribute('data-vf-actions-ready') === '1') { return; }
        const cuerpo = modalEl.querySelector('[data-vf-modal-body]');
        if (!cuerpo) { return; }
        const titulo = modalEl.querySelector('[data-vf-modal-title]');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        let origen = null;

        /* Delegación sobre el documento y no un `click` por botón: la lista del
         * roster se repinta entera (borrar a alguien, buscar sin recargar), así
         * que los `⋯` nuevos no tendrían listener y no abrirían nada. Con
         * `closest`, cualquier botón presente o futuro del documento resuelve. */
        document.addEventListener('click', function (e) {
            const boton = e.target.closest('[' + atributo + ']');
            if (!boton) { return; }
            const id = boton.getAttribute(atributo);
            const caja = document.getElementById(prefijoCaja + id);
            const panel = caja ? caja.firstElementChild : null;
            if (!panel) { return; }
            /* El panel es hijo de la caja oculta (el `<span hidden>`): se mueve
             * él, no la caja, o viajaría el `hidden` con nosotros. Al cerrar se
             * devuelve a su caja, que es de donde salió. */
            origen = { panel: panel, caja: caja };
            if (titulo) { titulo.textContent = boton.getAttribute(atributo + '-name') || ''; }
            cuerpo.appendChild(panel);
            modal.show();
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            if (!origen) { return; }
            /* La lista puede haberse repintado con el modal abierto (guardar por
             * fetch repone `rosterList` y recrea el `<span>`): si la caja original
             * ya no está en el documento, el panel se queda en el modal en vez de
             * devolverse a un nodo muerto. */
            if (origen.panel.parentNode === cuerpo) { cuerpo.removeChild(origen.panel); }
            if (origen.caja && origen.caja.parentNode) { origen.caja.appendChild(origen.panel); }
            origen = null;
        });

        modalEl.setAttribute('data-vf-actions-ready', '1');
    }

    /* La búsqueda del roster sin recargar. El formulario es un GET a la MISMA
     * pantalla (`/forms/{id}/access?q=…`) y sin JavaScript se va por su camino
     * nativo, que ya funciona. Aquí se le añade el atajo: `fetch` con
     * `X-Requested-With` para que `FormController::access()` responda JSON con el
     * hueco `rosterList` repintado, y `pushState` para que la URL siga siendo la
     * de la búsqueda (compartible, «atrás» funciona, recargar no la pierde).
     *
     * El modo de fallo que importa: si algo sale mal se cae al `submit()` nativo,
     * nunca a una pantalla a medias. `vfPost`/`vfApplySlots` no sirven aquí porque
     * son de POST; este es un GET y no lleva CSRF ni cuerpo. */
    (function initBuscarRoster() {
        const form = document.querySelector('[data-vf-roster-search]');
        if (!form) { return; }

        /* El envío NATIVO (el que hay sin JS, y el rescate si el `fetch` falla)
         * tiene que aterrizar en el panel y no en lo alto de la pantalla: el
         * `action` lleva el ancla. El `fetch` usa la acción SIN el fragmento
         * (la URL de la petición no es la del navegador), pero el `submit()`
         * nativo sí lo ve. Se lee antes de tocarlo para quedarnos con el destino
         * limpio. Ojo: `form.action` devuelve el absoluto, se lee el atributo. */
        const destino = (form.getAttribute('action') || '').split('#')[0];
        if ((form.getAttribute('action') || '').indexOf('#') === -1) {
            form.setAttribute('action', destino + '#participantes');
        }

        form.addEventListener('submit', function (e) {
            if (typeof window.fetch !== 'function' || !window.history) { return; }

            e.preventDefault();
            const params = new URLSearchParams(new FormData(form));
            const url = destino + '?' + params.toString();
            const lista = document.getElementById('rosterList');

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (res) {
                    if (!res.ok) { throw new Error('HTTP ' + res.status); }
                    return res.json();
                })
                .then(function (data) {
                    if (!data || data.ok !== true || !data.html || !lista) {
                        form.submit();
                        return;
                    }
                    /* `html` es un mapa de id → markup, igual que en el POST sin
                     * recarga: hoy solo trae `rosterList`, pero se recorre entero
                     * para que añadir un hueco al buscador no obligue a tocar esto. */
                    Object.keys(data.html).forEach(function (id) {
                        const slot = document.getElementById(id);
                        if (slot) { slot.innerHTML = data.html[id]; }
                    });
                    window.history.pushState(null, '', url + '#participantes');
                })
                .catch(function () {
                    /* Red caída, JSON inválido o un 500: se termina por donde
                     * empezó todo, el GET nativo. `form.submit()` no dispara
                     * `submit`, así que no vuelve a entrar aquí. */
                    form.submit();
                });
        });

        /* La URL del `pushState` y el `<input value>` del buscador tienen que
         * decir lo mismo: al ir «atrás», el navegador restaura el `value` que
         * había en ese momento, pero el buscador no se reenvía solo. Se copia el
         * `q` de la URL al campo para que el formulario y la pantalla no mientan. */
        window.addEventListener('popstate', function () {
            const q = new URLSearchParams(window.location.search).get('q') || '';
            const campo = form.querySelector('[name="q"]');
            if (campo && campo.value !== q) { campo.value = q; }
        });
    })();

    function iniciar() {
        moverFormulariosInline();
        initAccionesDeFila('userActionsModal', 'data-vf-user', 'rowactions-');
        initAccionesDeFila('rosterEditModal', 'data-vf-participant', 'rowactions-');
    }

    /* Expuesta porque una repintada puede traer marcado nuevo que mover; hoy la
     * llama el arranque. Idempotente por el `data-vf-modal-*`: un contenedor ya
     * movido tiene `hidden` y no vuelve a aparecer en `moverFormulariosInline`. */
    window.vfInitAdminModals = iniciar;
    iniciar();
})();
