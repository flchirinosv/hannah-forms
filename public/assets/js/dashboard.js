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
        const full = window.location.origin + url;
        const done = () => toast('Enlace copiado');
        const fail = () => toast('No se pudo copiar el enlace', 'danger');

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(full).then(done, fail);
            return;
        }
        const ta = document.createElement('textarea');
        ta.value = full;
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
    const select = document.getElementById('template');
    if (!picker || !select) {
        return;
    }

    const radios = Array.prototype.slice.call(document.querySelectorAll('input[name="mode"]'));
    const title = document.getElementById('title');

    function apply() {
        const useTemplate = radios.some((r) => r.checked && r.value === 'template');
        picker.hidden = !useTemplate;
        // En modo «en blanco» no debe viajar una plantilla antigua.
        select.disabled = !useTemplate;
        // Con plantilla el título puede quedar vacío (el servidor usa el de la
        // plantilla); en blanco sigue siendo obligatorio.
        if (title) {
            title.required = !useTemplate;
        }
    }

    radios.forEach((r) => r.addEventListener('change', apply));
    apply();
})();
