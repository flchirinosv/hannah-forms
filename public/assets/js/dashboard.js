/* VanillaForms — dashboard.js
 * Búsqueda, filtros por estado, ordenación y copia de enlace.
 * Sin dependencias (usa Bootstrap solo para el dropdown del menú).
 */
(function () {
    'use strict';

    const grid = document.getElementById('dashGrid');

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

    if (!grid) {
        return;
    }

    const search = document.getElementById('dashSearch');
    const sort = document.getElementById('dashSort');
    const noResults = document.getElementById('dashNoResults');
    const cols = Array.prototype.slice.call(grid.querySelectorAll('.app-dash-card-col'));

    let filter = 'all';

    function visibleCards() {
        const term = (search && search.value ? search.value.trim().toLowerCase() : '');
        return cols.filter((col) => {
            if (filter !== 'all' && col.dataset.status !== filter) {
                return false;
            }
            if (term !== '') {
                const hay = (col.dataset.title || '').toLowerCase();
                if (hay.indexOf(term) === -1) {
                    return false;
                }
            }
            return true;
        });
    }

    function render() {
        const cards = visibleCards();

        cols.forEach((col) => {
            col.classList.toggle('d-none', cards.indexOf(col) === -1);
        });

        if (noResults) {
            noResults.classList.toggle('d-none', cards.length > 0);
        }

        // M2: avisar a la paginación client-side para que re-renderice la página
        // sobre el nuevo conjunto de tarjetas visibles tras el filtrado.
        window.dispatchEvent(new CustomEvent('vf:refilter'));
    }

    function sortCards() {
        const key = sort ? sort.value : 'updated';
        const get = {
            updated: (c) => parseInt(c.dataset.updated || 0, 10),
            activity: (c) => parseInt(c.dataset.activity || 0, 10),
            subs: (c) => parseInt(c.dataset.subs || 0, 10),
            alpha: (c) => (c.dataset.title || '').trim(),
        }[key];

        const ordered = cols.slice();
        ordered.sort((a, b) => {
            if (key === 'alpha') {
                return get(a).localeCompare(get(b));
            }
            return get(b) - get(a);
        });

        ordered.forEach((col) => grid.appendChild(col));
        render();
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
        copyText(window.location.origin + url, 'Enlace copiado');
    }

    /**
     * Copia texto tal cual. `copyLink` le antepone el origen, pero el enlace de
     * acceso (F4) llega ABSOLUTO desde el servidor —que es quien sabe cuál es la
     * URL pública real, con `PublicUrl`— y anteponerle el origen lo convertiría en
     * `http://localhost/https://formularios.example/f/...`.
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
        if (search) {
            search.addEventListener('input', render);
        }
        if (sort) {
            sort.addEventListener('change', sortCards);
        }

        const filterGroup = document.getElementById('dashFilters');
        if (filterGroup) {
            filterGroup.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-filter]');
                if (!btn) {
                    return;
                }
                filter = btn.dataset.filter;
                filterGroup.querySelectorAll('[data-filter]').forEach((b) => b.classList.toggle('active', b === btn));
                render();
            });
        }

        document.addEventListener('keydown', (e) => {
            const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable;
            if (e.key === '/' && !typing && search) {
                e.preventDefault();
                search.focus();
            }
        });

        sortCards();
    }

    attach();
})();
/* P9 — Modal de nuevo formulario: elegir arranque en blanco o plantilla.
 * Va fuera del bloque del grid porque el modal existe también en el estado
 * vacío (donde no hay #dashGrid).
 */
(function () {
    'use strict';

    const picker = document.getElementById('templatePicker');
    // V6 — Las plantillas son tarjetas de radio, ya no un <select>.
    const templateInputs = picker
        ? Array.prototype.slice.call(picker.querySelectorAll('input[name="template"]'))
        : [];
    if (!picker || templateInputs.length === 0) {
        return;
    }

    const radios = Array.prototype.slice.call(document.querySelectorAll('input[name="mode"]'));
    const modeTemplate = radios.find((r) => r.value === 'template');
    const title = document.getElementById('title');

    function apply() {
        const useTemplate = radios.some((r) => r.checked && r.value === 'template');
        picker.hidden = !useTemplate;
        // En modo «en blanco» no debe viajar una plantilla antigua: las
        // tarjetas deshabilitadas no se serializan en el submit.
        templateInputs.forEach((r) => {
            r.disabled = !useTemplate;
        });
        picker.classList.toggle('app-tpl-off', !useTemplate);
        // Con plantilla el título puede quedar vacío (el servidor usa el de la
        // plantilla); en blanco sigue siendo obligatorio.
        if (title) {
            title.required = !useTemplate;
        }
    }

    // Elegir una tarjeta activa el modo «Desde una plantilla».
    templateInputs.forEach((r) => r.addEventListener('change', () => {
        if (r.checked && modeTemplate && !modeTemplate.disabled) {
            modeTemplate.checked = true;
            apply();
        }
    }));

    radios.forEach((r) => r.addEventListener('change', apply));
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
 * Son ESTADO, no explicación: el texto depende del interruptor y de si el
 * formulario está en revisión, y quien acaba de cambiarlo lo necesita ver sin
 * tener que volver a pasar el ratón por encima. Por eso NO son tooltips y por
 * eso el constructor (`builder.js`) ya no los escribe. */
(function () {
    'use strict';

    const accessSel = document.getElementById('access_mode');
    const reviewBox = document.getElementById('review_enabled');
    const reviewMail = document.getElementById('review_notify_email');

    if (!accessSel && !reviewBox) {
        return;
    }

    /* F4 — Los tres modos de acceso no se distinguen solos: 'email' y
     * 'allowlist' los dos piden un correo y mandan un enlace, y lo único que
     * los separa es si el correo tiene que estar en una lista. Sin esta frase
     * alguien elige 'email' pensando que es la lista y se queda con un
     * formulario abierto a cualquiera que escriba un correo. */
    function updateAccessHint() {
        const hint = document.getElementById('stAccessHint');
        if (!hint || !accessSel) {
            return;
        }
        if (accessSel.value === 'public') {
            hint.textContent = 'Sin identificación: cualquiera con el enlace responde.';
        } else if (accessSel.value === 'email') {
            hint.textContent = 'Pide un correo y envía un enlace. No hay lista: cualquier correo válido entra.';
        } else {
            hint.textContent = 'Solo los correos de la lista reciben un enlace. La lista vacía cierra el formulario a todo el mundo.';
        }
    }

    /* F3 — Explica el efecto sin pedir que lean las notas. El aviso por correo
     * va aparte porque es el que más sorprende: activar la revisión y no
     * enterarse de que no hay correo es como se queda un formulario esperando a
     * un revisor que nunca se entera. */
    function updateReviewHint() {
        const hint = document.getElementById('stReviewHint');
        if (!hint || !reviewBox) {
            return;
        }
        if (!reviewBox.checked) {
            hint.textContent = 'Desactivado: publicas directamente, como hasta ahora.';
            return;
        }
        const mail = reviewMail && reviewMail.checked
            ? 'Se avisará por correo a quien tenga el permiso de revisión.'
            : 'Solo aparecerá en la bandeja de revisión del panel, sin correo.';
        hint.textContent = 'El botón Publicar desaparece y el formulario espera en la bandeja. '
            + 'Editarlo de nuevo lo devuelve a la cola. ' + mail;
    }

    if (accessSel) {
        accessSel.addEventListener('change', updateAccessHint);
        updateAccessHint();
    }
    if (reviewBox) {
        reviewBox.addEventListener('change', updateReviewHint);
        updateReviewHint();
    }
    if (reviewMail) {
        reviewMail.addEventListener('change', updateReviewHint);
    }
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

/* ---- Grupos colapsables (views/dashboard/forms/show.php y views/admin/users.php)
 * En la ficha del formulario son siete grupos porque, con los siete abiertos,
 * «Ajustes del formulario» ocupaba más de tres pantallas y el ajuste que se busca
 * casi nunca está en el primero; en «Usuarios» el alta viene cerrada de entrada,
 * porque con el registro público cerrado es la única forma de crear una cuenta y
 * lo que se abre en esa pantalla ocho de cada diez veces es la tabla.
 *
 * El plegado es de Bootstrap (`data-bs-toggle="collapse"`), así que esto solo
 * recuerda en qué estado quedó cada uno: el estado va en `localStorage` POR
 * PANTALLA (`vf:grp:<clave>`) y no en el del navegador, porque el mismo usuario
 * pasa por varias fichas y cerrarlos todos en cada una era más ruido que el que
 * arreglaba. Cada contenedor trae su clave: la ficha la sigue nombrando
 * `data-vf-form-id` (su clave es el id del formulario y no cambia), y el alta de
 * usuarios usa `data-vf-group-key`. `data-vf-group-default="closed"` es lo que
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
     * y quien guarda tiene que abrir los siete a ciegas. Con error, se ignoran los
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
 * Pestañas de un panel: `data-vf-tabs`
 *
 * Fase 5 (listado de participantes). La tarjeta de Acceso trae dos pestañas
 * («Lista» e «Importar») y aquí está el interruptor.
 *
 * ## Por qué NO es el `tab` de Bootstrap
 *
 * Porque el de Bootstrap esconde sus paneles con CSS (`.tab-pane` sin `.active` es
 * `display: none`), así que sin JavaScript el segundo panel **desaparece sin decir
 * nada**. Aquí todo lo que hay debajo es de dueño y con `Access::canEdit()` detrás:
 * un panel que depende de JS para existir es un panel que se pierde en el
 * navegador que menos lo puede permitir.
 *
 * Con `hidden` (el atributo nativo) el degradado sale solo: la vista pinta los dos
 * paneles SIN `hidden` y este script lo pone. Sin JS, no hay nadie que lo ponga y
 * se ven los dos, uno debajo de otro.
 *
 * ## El estado va en `localStorage` por PANTALLA, como los grupos
 *
 * `vf:tab:<clave>`, con la clave del contenedor (`data-vf-tabs="roster-<id>"`),
 * porque quien importa un archivo vuelve a la pestaña «Importar» con el informe
 * delante y no a «Lista» con el informe escondido en la otra.
 *
 * Y con la misma trampa que los grupos: el `hidden` que pone este arranque NO se
 * guarda. Si se guardara, la primera visita decidiría la pestaña para siempre y un
 * cambio futuro del valor por defecto no llegaría a nadie (`decididos`).
 * ------------------------------------------------------------------------- */
(function () {
    'use strict';

    const contenedores = Array.prototype.slice.call(document.querySelectorAll('[data-vf-tabs]'));
    if (contenedores.length === 0) { return; }

    const leido = (clave) => {
        try {
            return window.localStorage.getItem('vf:tab:' + clave) || '';
        } catch (e) {
            return '';
        }
    };
    const guardado = (clave, valor) => {
        try {
            window.localStorage.setItem('vf:tab:' + clave, valor);
        } catch (e) { /* sin localStorage las pestañas funcionan igual, sin memoria */ }
    };

    contenedores.forEach((caja) => {
        const clave = caja.getAttribute('data-vf-tabs') || '';
        const botones = Array.prototype.slice.call(caja.querySelectorAll('[data-vf-tab]'));
        if (botones.length === 0) { return; }

        const panelDe = (boton) => document.getElementById(boton.getAttribute('data-vf-tab'));
        const porId = (id) => botones.filter((b) => b.getAttribute('data-vf-tab') === id)[0];

        /* Pintar una pestaña = `aria-selected` en el botón y `hidden` en el resto.
         * Los paneles que no son el elegido se ocultan TODOS, no solo los que el
         * botón conoce: un panel sin su botón (porque el servidor lo pintó y el
         * JS no lo vio) se queda visible, que es lo que se ve sin JS. */
        const pintar = (id, guardar) => {
            const elegido = panelDe(porId(id) || botones[0]);
            if (!elegido) { return; }
            botones.forEach((b) => {
                const suyo = b.getAttribute('data-vf-tab') === elegido.id;
                b.classList.toggle('active', suyo);
                b.setAttribute('aria-selected', suyo ? 'true' : 'false');
                if (suyo) { b.removeAttribute('tabindex'); } else { b.setAttribute('tabindex', '-1'); }
                const panel = panelDe(b);
                if (panel) {
                    if (suyo) { panel.removeAttribute('hidden'); } else { panel.setAttribute('hidden', ''); }
                }
            });
            if (guardar && clave !== '') { guardado(clave, elegido.id); }
        };

        /* El arranque NO guarda. La primera visita enseña la pestaña activa del
         * servidor y no la fija para siempre (ver la nota de `decididos` arriba). */
        const guardada = clave !== '' ? leido(clave) : '';
        const inicial = (guardada && porId(guardada)) ? guardada : botones[0].getAttribute('data-vf-tab');
        pintar(inicial, false);

        botones.forEach((b) => {
            b.addEventListener('click', function (e) {
                e.preventDefault();
                pintar(this.getAttribute('data-vf-tab'), true);
            });
        });

        /* Volver al panel después de buscar.
         *
         * El buscador es un GET a esta misma ficha, así que la página se recarga y
         * el navegador deja arriba del todo, con el resultado fuera de pantalla:
         * se ha buscado y parece que no ha pasado nada. El ancla está en el
         * `action` de la vista, pero un envío GET puede llevársela, y por eso se
         * la pone aquí justo antes de enviar.
         *
         * Y es una mejora, no la puerta: si el navegador se sigue llevando el
         * fragmento por delante, la búsqueda devuelve la página entera con el
         * resultado, solo que arriba del todo, y sin JavaScript es exactamente eso
         * lo que hay. Lo único que hace este bloque es añadir un `#`; nunca toca
         * el `query`, así que no puede cambiar lo que se busca. */
        const buscador = caja.querySelector('form[role="search"]');
        if (buscador) {
            buscador.addEventListener('submit', function () {
                const accion = buscador.getAttribute('action') || '';
                if (accion.indexOf('#') === -1) {
                    buscador.setAttribute('action', accion + '#' + caja.id);
                }
            });
        }
    });
})();
