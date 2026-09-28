/* Vista imprimible (P9). El botón de imprimir NO lleva onclick inline a
 * propósito: con `script-src 'self'` (CSP) un handler en línea no se ejecuta.
 * Aquí se delega en un atributo de datos. */
(function () {
    'use strict';

    document.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('[data-print]') : null;
        if (btn) {
            ev.preventDefault();
            window.print();
        }
    });
})();
