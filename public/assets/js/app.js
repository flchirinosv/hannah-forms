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

    // Confirmación genérica de envío (CSP: sin onsubmit inline).
    // Todo <form data-confirm="mensaje"> pide confirmar antes de enviar.
    document.addEventListener('submit', function (e) {
        var el = e.target.closest('form[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
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

    // Auto-enviar el select de rol del admin al cambiar (CSP: sin onchange inline).
    document.querySelectorAll('.js-auto-submit').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var form = sel.closest('form');
            if (form) form.submit();
        });
    });

    /* ---------------------------------------------------------------
     * Matriz de permisos: marcar una sección entera (admin.roles)
     *
     * Dar permisos a un rol nuevo son ~20 clics de casillas por sección,
     * y la sección es la unidad que la persona piensa ("lo de
     * formularios"), no la casilla. Un [data-vf-bulk-all="<prefijo>"]
     * mueve todas las .js-perm de su sección.
     *
     * - Se saltan las casillas [disabled] (admin.access del rol admin no se
     *   puede desmarcar: el servidor lo vuelve a poner igual).
     * - Al tocar una casilla suelta, su "marcar toda" se recalcula, y queda
     *   indeterminate a medias: es la única forma de ver que la sección está
     *   a medio marcar sin contarlas a mano.
     * --------------------------------------------------------------- */
    function initPermissionBulkToggles() {
        var permSel = '.js-perm[data-vf-section]';

        document.querySelectorAll('[data-vf-bulk-all]').forEach(function (bulk) {
            var section = bulk.getAttribute('data-vf-bulk-all');
            var cells = function () {
                return Array.prototype.filter.call(
                    document.querySelectorAll(permSel),
                    function (c) { return c.getAttribute('data-vf-section') === section; }
                );
            };

            var sync = function () {
                var all = cells().filter(function (c) { return !c.disabled; });
                var on = all.filter(function (c) { return c.checked; }).length;
                bulk.checked = all.length > 0 && on === all.length;
                bulk.indeterminate = on > 0 && on < all.length;
            };

            bulk.addEventListener('change', function () {
                cells().forEach(function (c) {
                    if (!c.disabled) c.checked = bulk.checked;
                });
                sync();
            });

            document.querySelectorAll(permSel).forEach(function (cell) {
                if (cell.getAttribute('data-vf-section') !== section) return;
                cell.addEventListener('change', sync);
            });

            sync();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPermissionBulkToggles);
    } else {
        initPermissionBulkToggles();
    }
})();

/* ---------------------------------------------------------------
 * Paginación client-side (M2 / auditoría 2026-09-25)
 * Un contenedor con [data-paginate] pagina sus hijos directos que
 * tengan la clase .paginate-item. Reglas:
 *  - La paginación oculta los ítems fuera de la página con la clase
 *    .app-pg-hidden (CSS display:none). NUNCA toca .d-none, que es
 *    exclusivo de los filtros/búsqueda de dashboard.js: de esa forma
 *    el universo "visible" de aquí (ítems sin .d-none) es el conjunto
 *    filtrado y el conteo de páginas es estable al navegar.
 *  - Escucha el evento 'vf:refilter' (dashboard.js lo emite al filtrar)
 *    para re-renderizar la página sobre el nuevo conjunto filtrado.
 *  - Crea un footer con controles «prev / Pág. X de N / next».
 *  - Filas por página: data-rows (default 20).
 * --------------------------------------------------------------- */
(function () {
    'use strict';

    var PREV_LABEL = '«';
    var NEXT_LABEL = '»';

    function initClientPagination() {
        var wraps = document.querySelectorAll('[data-paginate]');

        wraps.forEach(function (wrap) {
            var perPage = parseInt(wrap.getAttribute('data-rows') || '20', 10) || 20;
            var items = Array.prototype.filter.call(wrap.children, function (child) {
                return child.classList.contains('paginate-item');
            });
            if (items.length === 0) {
                return;
            }

            var nav = document.createElement('nav');
            nav.className = 'app-pagination-wrap d-flex justify-content-center align-items-center gap-2 mt-3 flex-wrap';
            nav.setAttribute('aria-label', 'Paginación');
            if (wrap.parentNode) {
                wrap.parentNode.insertBefore(nav, wrap.nextSibling);
            }

            var page = 1;

            function visible() {
                return items.filter(function (i) {
                    return !i.classList.contains('d-none');
                });
            }

            function render() {
                var vis = visible();
                var pages = Math.max(1, Math.ceil(vis.length / perPage));
                if (page > pages) page = pages;
                if (page < 1) page = 1;

                var start = (page - 1) * perPage;
                var slice = new Set(vis.slice(start, start + perPage));

                items.forEach(function (i) {
                    i.classList.toggle('app-pg-hidden', !slice.has(i));
                });

                if (pages <= 1) {
                    nav.innerHTML = '';
                    return;
                }

                var mkBtn = function (label, title, onClick, disabled, icon) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'btn btn-sm btn-outline-secondary';
                    if (icon) b.classList.add(icon);
                    if (disabled) b.setAttribute('disabled', '');
                    b.setAttribute('title', title);
                    b.innerHTML = label;
                    b.addEventListener('click', onClick);
                    return b;
                };

                nav.innerHTML = '';

                var prev = mkBtn(PREV_LABEL, 'Anterior', function () {
                    if (page > 1) { page--; render(); }
                }, page === 1);
                nav.appendChild(prev);

                var indicator = document.createElement('span');
                indicator.className = 'small text-muted';
                indicator.textContent = 'Pág. ' + page + ' de ' + pages;
                nav.appendChild(indicator);

                var next = mkBtn(NEXT_LABEL, 'Siguiente', function () {
                    if (page < pages) { page++; render(); }
                }, page === pages);
                nav.appendChild(next);
            }

            window.addEventListener('vf:refilter', render);

            window.addEventListener('vf:refresh', function () {
                page = 1;
                render();
            });
            render();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initClientPagination);
    } else {
        initClientPagination();
    }
})();