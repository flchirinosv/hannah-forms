/* VanillaForms Analytics */
(function () {
    'use strict';

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
})();
