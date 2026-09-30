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
        return;
    }

    var daily = (D.daily && D.daily.labels && D.daily.labels.length)
        ? D.daily
        : { labels: [], counts: [] };

    var colors = D.colors || ['#6366f1', '#22c55e', '#f59e0b', '#0ea5e9', '#ef4444'];

    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
    Chart.defaults.color = '#64748b';

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

        D.fields.forEach(function (field) {
            // Choice chart (select/radio/checkbox)
            var choiceCanvas = document.querySelector('.choice-chart[data-field="' + field.id + '"]');
            if (choiceCanvas) {
                try {
                    var rows = Array.isArray(field.values) ? field.values : [];
                    var labels = rows.map(function (r) { return String(r.value); });
                    var data = rows.map(function (r) { return Number(r.c); });
                    var palette = labels.map(function (_, i) { return colors[i % colors.length]; });

                    new Chart(choiceCanvas, {
                        type: field.type === 'checkbox' ? 'bar' : 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Respuestas',
                                data: data,
                                backgroundColor: field.type === 'checkbox' ? palette.map(function (c) { return c + 'cc'; }) : palette,
                                borderWidth: 1,
                                borderColor: '#ffffff',
                            }],
                        },
                        options: {
                            maintainAspectRatio: false,
                            responsive: true,
                            plugins: {
                                legend: {
                                    display: field.type === 'checkbox',
                                    position: 'bottom',
                                    labels: { boxWidth: 12, font: { size: 11 } },
                                },
                            },
                            scales: field.type === 'checkbox' ? {
                                y: { beginAtZero: true, ticks: { precision: 0 } },
                            } : undefined,
                        },
                    });
                } catch (e) {
                    console.error('[VanillaForms] Error chart choice field=' + field.id, e);
                }
            }

            // Number chart
            var numCanvas = document.querySelector('.number-chart[data-field="' + field.id + '"]');
            if (numCanvas && field.values && field.values.avg_value != null) {
                try {
                    new Chart(numCanvas, {
                        type: 'bar',
                        data: {
                            labels: ['Promedio', 'Mínimo', 'Máximo'],
                            datasets: [{
                                label: 'Valor',
                                data: [
                                    Number(field.values.avg_value || 0),
                                    Number(field.values.min_value || 0),
                                    Number(field.values.max_value || 0),
                                ],
                                backgroundColor: ['#6366f1', '#22c55e', '#f59e0b'],
                                borderRadius: 8,
                            }],
                        },
                        options: {
                            maintainAspectRatio: false,
                            responsive: true,
                            plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true }, x: { grid: { display: false } } },
                        },
                    });
                } catch (e) {
                    console.error('[VanillaForms] Error chart number field=' + field.id, e);
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(initCharts, 100);
        });
    } else {
        setTimeout(initCharts, 100);
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

        function fetchPage(page) {
            var url = '/forms/' + encodeURIComponent(formId) + '/analytics/rows'
                + '?page=' + encodeURIComponent(page)
                + (from ? '&from=' + encodeURIComponent(from) : '')
                + (to ? '&to=' + encodeURIComponent(to) : '');

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
})();
