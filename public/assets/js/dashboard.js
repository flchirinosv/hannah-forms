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

    /* ---------- toast ---------- */
    function toast(msg, type) {
        let wrap = document.getElementById('toastWrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'toastWrap';
            wrap.className = 'app-toast-wrap';
            document.body.appendChild(wrap);
        }
        const t = document.createElement('div');
        t.className = 'app-toast text-bg-' + (type || 'success') + ' shadow';
        t.innerHTML = '<i class="fa-solid ' + (type === 'danger' ? 'fa-circle-xmark' : 'fa-circle-check') + ' me-2"></i>'
            + esc(msg);
        wrap.appendChild(t);
        setTimeout(() => t.classList.add('show'), 20);
        setTimeout(() => {
            t.classList.remove('show');
            setTimeout(() => t.remove(), 300);
        }, 2400);
    }

    // Escapa & < > y las comillas: el resultado es válido tanto en texto como
    // dentro de un atributo entre comillas (mismo criterio que builder.js).
    function esc(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
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
