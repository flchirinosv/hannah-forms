/* VanillaForms Analytics */
(function () {
    'use strict';

    /* ---------- Q5 · Presets de rango (7/30/90 días, este mes) ----------
     * Ponen los inputs from/to y reenvían el GET de filtrado. Se registra a
     * nivel documento para que funcione aunque el bloque de gráficas se salte. */
    var pickIso = function (date) {
        var m = String(date.getMonth() + 1);
        var d = String(date.getDate());
        return date.getFullYear() + '-' + (m.length === 1 ? '0' + m : m) + '-' + (d.length === 1 ? '0' + d : d);
    };
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-preset]') : null;
        if (!btn) {
            return;
        }
        var form = btn.closest('form');
        var fromInput = form && form.querySelector('input[name="from"]');
        var toInput = form && form.querySelector('input[name="to"]');
        if (!fromInput || !toInput || btn.dataset.preset === '') {
            return;
        }
        var to = new Date();
        to.setHours(0, 0, 0, 0);
        var from = new Date(to);
        if (btn.dataset.preset === 'month') {
            from.setDate(1);
        } else {
            from.setDate(from.getDate() - parseInt(btn.dataset.preset, 10));
        }
        fromInput.value = pickIso(from);
        toInput.value = pickIso(to);
        form.submit();
    });

    var D = null;
    var chartEl = document.getElementById('analyticsApp');
    if (chartEl && chartEl.getAttribute('data-chart')) {
        try {
            D = JSON.parse(chartEl.getAttribute('data-chart'));
        } catch (e) {
            D = null;
        }
    }
    if (!D || typeof D !== 'object') {
        console.warn('[VanillaForms] CHART_DATA no encontrado');
        // W4 — Sin datos no hay gráficos que pintar, pero el bloque NO puede
        // terminar aquí: el índice de preguntas de abajo (initIndex) tiene que
        // seguir registrándose. Un `return` en el borde de una IIFE se resolvería
        // con ASI dentro de una línea que empieza por `(` en el consumidor.
        D = null;
    }
    if (D !== null) {

    var daily = (D.daily && D.daily.labels && D.daily.labels.length)
        ? D.daily
        : { labels: [], counts: [] };

    var colors = D.colors || ['#6366f1', '#22c55e', '#f59e0b', '#0ea5e9', '#ef4444'];

    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
    Chart.defaults.color = '#64748b';

    // -------------------------------------------------------------
    // W3 — Gráficos por campo con vista efímera.
    //
    // El servidor manda los datos ya agregados en `data-chart` (CSP: sin
    // `<script>` inline) y marca con `data-vf-view` la vista que llega puesta.
    // Aquí solo se pinta y se vuelve a pintar: cambiar de vista NO recarga ni
    // escribe estado, porque es una lente sobre los mismos números y una
    // preferencia guardada mentiría al cambiar el filtro de fechas.
    //
    // Las vistas permitidas por familia son las de `FieldAggregator::VISTAS`;
    // si llega otra, se cae a la primera de la lista (no hay selector libre).
    // -------------------------------------------------------------
    var chartsPorCanvas = new Map();
    var camposPorId = {};
    (Array.isArray(D.fields) ? D.fields : []).forEach(function (f) {
        camposPorId[String(f.id)] = f;
    });

    // G1 — Suelta CUALQUIER Chart que Chart.js tenga registrado sobre este
    // canvas, aunque no esté en el `Map`. Es la red de seguridad para el caso
    // que dejaba la tarjeta en blanco: `new Chart` sobre un canvas que todavía
    // tiene una instancia viva lanza «Canvas is already in use» y el `catch` se
    // lo tragaba, así que el lienzo se quedaba vacío sin error visible.
    function soltarCanvas(canvas) {
        if (typeof Chart.getChart === 'function') {
            var vivo = Chart.getChart(canvas);
            if (vivo) {
                try { vivo.destroy(); } catch (e) { /* ya destruido */ }
            }
        }
    }

    function destruir(canvas) {
        var previo = chartsPorCanvas.get(canvas);
        if (previo) {
            try { previo.destroy(); } catch (e) { /* ya destruido */ }
            chartsPorCanvas.delete(canvas);
        }
        // Aunque el `Map` no lo tuviera (una instancia creada por otra vía o un
        // `destroy()` que falló), el registro interno de Chart.js manda.
        soltarCanvas(canvas);
    }

    function crear(canvas, config) {
        chartsPorCanvas.set(canvas, new Chart(canvas, config));
    }

    function pintar(canvas, config) {
        destruir(canvas);
        try {
            crear(canvas, config);
            return;
        } catch (e) {
            // Si aún choca con una instancia viva, se fuerza un segundo
            // `destroy` y se reintenta UNA vez. Solo si vuelve a fallar se
            // registra: así el fallo deja rastro y no un canvas muerto.
            soltarCanvas(canvas);
        }
        try {
            crear(canvas, config);
        } catch (e) {
            console.error('[VanillaForms] Error chart ' + (canvas.className || ''), e);
        }
    }

    function totalDe(rows) {
        return rows.reduce(function (acc, r) { return acc + Number(r.c || 0); }, 0);
    }

    function pct(c, total) {
        return total > 0 ? Math.round((Number(c) / total) * 100) : 0;
    }

    function tooltipPct(total) {
        return {
            callbacks: {
                label: function (ctx) {
                    var n = Number(ctx.raw || 0);
                    return ctx.label + ': ' + n + ' (' + pct(n, total) + '%)';
                },
            },
        };
    }

    function renderChoice(field, canvas, view) {
        var rows = Array.isArray(field.values) ? field.values : [];
        var total = totalDe(rows);
        var esCheckbox = field.type === 'checkbox';
        var labels = rows.map(function (r) { return String(r.value); });
        var data = rows.map(function (r) { return Number(r.c); });
        var palette = labels.map(function (_, i) { return colors[i % colors.length]; });
        var esAnillo = view === 'doughnut' && !esCheckbox;

        // W3 — Con barras la etiqueta lleva el porcentaje, que es la lectura
        // que la tabla daba y el gráfico no: «MX · 4 (44%)». Con el anillo el
        // porcentaje va al tooltip para no llenar la leyenda.
        if (!esAnillo) {
            labels = rows.map(function (r) {
                return String(r.value) + ' · ' + Number(r.c) + ' (' + pct(r.c, total) + '%)';
            });
        }

        pintar(canvas, {
            type: esAnillo ? 'doughnut' : 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Respuestas',
                    data: data,
                    backgroundColor: esAnillo ? palette : palette.map(function (c) { return c + 'cc'; }),
                    borderWidth: 1,
                    borderColor: '#ffffff',
                    borderRadius: esAnillo ? 0 : 6,
                }],
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                // Barras horizontales: las opciones largas se leen sin girar la
                // cabeza y el eje de conteo queda en el suelo. El checkbox se
                // queda vertical (su lectura natural es «cuántos marcaron»).
                indexAxis: !esAnillo && !esCheckbox ? 'y' : 'x',
                plugins: {
                    legend: {
                        display: esAnillo,
                        position: 'bottom',
                        labels: { boxWidth: 12, font: { size: 11 } },
                    },
                    tooltip: tooltipPct(total),
                },
                scales: esAnillo ? {} : {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: { display: esCheckbox } },
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { display: !esCheckbox } },
                },
            },
        });
    }

    function renderHistogram(field, canvas, esNps) {
        var dist = Array.isArray(field.dist) ? field.dist : [];
        var labels = dist.map(function (d) { return String(d.value); });
        var data = dist.map(function (d) { return Number(d.c); });
        var fondo = esNps
            ? dist.map(function (d) {
                var n = Number(d.value);
                return n <= 6 ? '#ef4444' : (n <= 8 ? '#f59e0b' : '#22c55e');
            })
            : '#6366f1';

        pintar(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Respuestas',
                    data: data,
                    backgroundColor: fondo,
                    borderRadius: 6,
                    maxBarThickness: 42,
                }],
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: { grid: { display: false } },
                },
            },
        });
    }

    function renderRanking(field, canvas) {
        var rows = (Array.isArray(field.values) ? field.values : [])
            .filter(function (r) { return r.avg !== null && r.avg !== undefined; })
            .sort(function (a, b) { return Number(a.avg) - Number(b.avg); });

        pintar(canvas, {
            type: 'bar',
            data: {
                labels: rows.map(function (r) { return String(r.value); }),
                datasets: [{
                    label: 'Promedio de orden',
                    data: rows.map(function (r) { return Number(r.avg); }),
                    backgroundColor: '#6366f1cc',
                    borderRadius: 6,
                }],
            },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var r = rows[ctx.dataIndex];
                                return 'Orden medio ' + ctx.raw + ' · ' + r.c + ' respuestas';
                            },
                        },
                    },
                },
                scales: {
                    x: { beginAtZero: true, title: { display: true, text: '1 = primero' } },
                    y: { grid: { display: false } },
                },
            },
        });
    }

    function pintarTablaChoice(contenedor, field) {
        var rows = Array.isArray(field.values) ? field.values : [];
        var total = totalDe(rows);

        while (contenedor.firstChild) {
            contenedor.removeChild(contenedor.firstChild);
        }

        var tabla = document.createElement('table');
        tabla.className = 'table table-sm align-middle mb-0';
        var thead = document.createElement('thead');
        var trh = document.createElement('tr');
        ['Opción', 'Respuestas', '%'].forEach(function (texto, i) {
            var th = document.createElement('th');
            th.className = 'small text-muted' + (i === 0 ? '' : ' text-end');
            th.textContent = texto;
            trh.appendChild(th);
        });
        thead.appendChild(trh);
        tabla.appendChild(thead);

        var tbody = document.createElement('tbody');
        rows.forEach(function (r) {
            var tr = document.createElement('tr');
            var td1 = document.createElement('td');
            td1.className = 'small';
            td1.textContent = String(r.value);
            var td2 = document.createElement('td');
            td2.className = 'text-end';
            td2.textContent = String(Number(r.c));
            var td3 = document.createElement('td');
            td3.className = 'text-end';
            td3.textContent = pct(r.c, total) + ' %';
            tr.appendChild(td1);
            tr.appendChild(td2);
            tr.appendChild(td3);
            tbody.appendChild(tr);
        });
        tabla.appendChild(tbody);
        contenedor.appendChild(tabla);
    }

    function aplicarVista(card, view) {
        var familia = card.getAttribute('data-vf-family');
        var caja = card.querySelector('[data-vf-chart-box]');
        var canvas = caja ? caja.querySelector('canvas[data-field]') : null;
        var field = canvas ? camposPorId[canvas.getAttribute('data-field')] : null;

        card.querySelectorAll('[data-vf-view]').forEach(function (btn) {
            var activa = btn.getAttribute('data-vf-view') === view;
            btn.classList.toggle('active', activa);
            btn.setAttribute('aria-pressed', activa ? 'true' : 'false');
        });

        if (familia === 'choice') {
            var tabla = card.querySelector('.vf-choice-table');
            var esTabla = view === 'table';
            if (tabla) tabla.classList.toggle('d-none', !esTabla);
            if (caja) caja.classList.toggle('d-none', esTabla);
            if (esTabla) {
                if (tabla && field) pintarTablaChoice(tabla, field);
            } else if (field && canvas) {
                renderChoice(field, canvas, view === 'doughnut' ? 'doughnut' : 'bars');
            }
            return;
        }

        if (familia === 'ranking') {
            var barras = view === 'bars';
            var tablaRanking = card.querySelector('[data-vf-ranking-table]');
            if (caja) caja.classList.toggle('d-none', !barras);
            if (tablaRanking) tablaRanking.classList.toggle('d-none', barras);
            if (barras && field && canvas) renderRanking(field, canvas);
            return;
        }

        // numeric y nps: «Resumen» no pinta un lienzo nuevo, deja a la vista
        // las mini-stats y el score que ya escribió el servidor.
        var esResumen = view === 'summary';
        if (caja) caja.classList.toggle('d-none', esResumen);
        if (!esResumen && field && canvas) {
            renderHistogram(field, canvas, field.type === 'nps');
        }
    }

    function pintarTodasLasTarjetas() {
        document.querySelectorAll('.vf-chart-card').forEach(function (card) {
            var activa = card.querySelector('[data-vf-view].active');
            var view = activa ? activa.getAttribute('data-vf-view') : '';
            if (view !== '') aplicarVista(card, view);
        });
    }

    // Delegado (como los presets): el conmutador funciona aunque una tarjeta se
    // repinte, y no hay un listener por botón que se quede colgado.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-vf-view]') : null;
        if (!btn) return;
        var card = btn.closest('.vf-chart-card');
        if (!card) return;
        aplicarVista(card, btn.getAttribute('data-vf-view'));
    });

    // Se expone para depurar desde la consola: `vfRenderFieldChart(12, 'bars')`.
    window.vfRenderFieldChart = function (fieldId, view) {
        var canvas = document.querySelector('.vf-chart-card canvas[data-field="' + fieldId + '"]');
        var card = canvas ? canvas.closest('.vf-chart-card') : null;
        if (card) aplicarVista(card, view);
    };

    function initCharts() {
        // Gráfico diario
        var ctx = document.getElementById('dailyChart');
        if (ctx && daily.labels.length) {
            try {
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: daily.labels,
                        datasets: [{
                            label: 'Respuestas',
                            data: daily.counts,
                            borderColor: '#6366f1',
                            backgroundColor: 'rgba(99,102,241,.15)',
                            fill: true,
                            tension: .4,
                            pointBackgroundColor: '#6366f1',
                            pointRadius: 4,
                        }],
                    },
                    options: {
                        maintainAspectRatio: false,
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { precision: 0 } },
                            x: { grid: { display: false } },
                        },
                    },
                });
            } catch (e) {
                console.error('[VanillaForms] Error chart daily:', e);
            }
        }

        // Gráficos por campo
        if (!Array.isArray(D.fields)) return;
        pintarTodasLasTarjetas();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(initCharts, 100);
        });
    } else {
        setTimeout(initCharts, 100);
    }

    }

    // -------------------------------------------------------------
    // P1 (2026-09-28) — Tabla de detalle con paginación server-side.
    // La página 1 llega renderizada del servidor; los botones del pager piden
    // el fragmento a `GET /forms/{id}/analytics/rows` y lo inyectan. El HTML
    // que se inyecta lo ESCAPA el servidor (View::escape), nunca se construye
    // desde datos del cliente.
    // -------------------------------------------------------------
    function initDetailPager() {
        var tbody = document.getElementById('analyticsRows');
        if (!tbody) return;

        var formId = tbody.getAttribute('data-form') || '';
        var from = tbody.getAttribute('data-from') || '';
        var to = tbody.getAttribute('data-to') || '';
        // Fase 7 — El filtro del listado viaja con la página. Sin esto, el contador
        // y los gráficos dicen «38 respuestas del grupo Norte» y las filas que se
        // pintan al cambiar de página son las de otra cosa: la siguiente tabla es
        // exactamente la misma pantalla que la primera.
        var atrKey = tbody.getAttribute('data-atr-key') || '';
        var atrValue = tbody.getAttribute('data-atr-value') || '';

        function fetchPage(page) {
            var url = '/forms/' + encodeURIComponent(formId) + '/analytics/rows'
                + '?page=' + encodeURIComponent(page)
                + (from ? '&from=' + encodeURIComponent(from) : '')
                + (to ? '&to=' + encodeURIComponent(to) : '')
                + (atrKey ? '&atr_key=' + encodeURIComponent(atrKey) : '')
                + (atrValue ? '&atr_value=' + encodeURIComponent(atrValue) : '');

            tbody.classList.add('vf-loading');

            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(function (data) {
                    if (!data.ok || typeof data.html !== 'string') throw new Error('respuesta inválida');
                    tbody.innerHTML = data.html;
                    var pager = document.querySelector('.analytics-pager');
                    if (pager && typeof data.pagination === 'string' && data.pagination !== '') {
                        pager.outerHTML = data.pagination;
                    }
                    initDetailPager(); // re-engancha los botones nuevos
                    // La clase se quita TAMBIÉN en el camino de éxito. Antes solo
                    // la quitaba el catch, así que tras el primer cambio de
                    // página la tbody se quedaba con opacity .55 y
                    // pointer-events: none para siempre, con el spinner
                    // girando: las respuestas ya cargadas no se podían abrir.
                    tbody.classList.remove('vf-loading');
                })
                .catch(function () {
                    tbody.classList.remove('vf-loading');
                });
        }

        document.querySelectorAll('.analytics-pager [data-paginate-page]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                if (btn.disabled || btn.classList.contains('page-disabled')) return;
                fetchPage(btn.getAttribute('data-paginate-page'));
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDetailPager);
    } else {
        initDetailPager();
    }

    /* ---------- W4 · Índice de preguntas en móvil ----------
     * En escritorio el índice es una lista de anclas `<a href="#campo-N">` (sin
     * JS). En móvil esa columna no cabe, así que el desplegable lleva a la
     * tarjeta con `scrollIntoView`. Sin JS queda el `<select>` sin efecto, pero
     * ninguno de los dos rompe la navegación normal. */
    function initQuestionIndex() {
        var select = document.getElementById('analyticsIndexSelect');
        if (!select) return;
        select.addEventListener('change', function () {
            var target = select.value ? document.getElementById(select.value) : null;
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            select.value = '';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initQuestionIndex);
    } else {
        initQuestionIndex();
    }
})();
