/**
 * Repintado de los gráficos de analítica (`public/assets/js/analytics.js`).
 *
 * G1 — El bug que dejaba las tarjetas en blanco al cambiar de vista: `pintar()`
 * creaba un `Chart` sobre un canvas que TODAVÍA tenía una instancia viva,
 * Chart.js lanzaba «Canvas is already in use» y el `catch` se lo tragaba. El
 * resultado era un lienzo vacío sin ningún error visible.
 *
 * No es un test de lectura de código: extrae del JS REAL las funciones que
 * gestionan el ciclo de vida del Chart (`soltarCanvas`, `destruir`, `crear`,
 * `pintar`) y las corre contra un `Chart` falso que **imita el guard real**
 * («already in use» si queda una instancia viva sobre el canvas). Si alguien
 * vuelve a tragarse el error o deja de soltar el canvas, esto se pone rojo.
 *
 * Lo ejecuta `tests/unit/AnalyticsChartsTest.php`, que hace SKIP si no hay
 * `node` (igual que los otros arneses).
 *
 * Uso: node tests/js/analytics-charts.js
 */

'use strict';

const fs = require('fs');
const path = require('path');

const ANALYTICS_JS = path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'analytics.js');
const source = fs.readFileSync(ANALYTICS_JS, 'utf8');

/* ---------- extracción (mismo truco que fill-ranking.js) ---------- */

function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    if (start < 0) {
        throw new Error('analytics.js ya no tiene ' + name + '(): el arnés está obsoleto');
    }
    let depth = 0;
    for (let i = source.indexOf('{', start); i < source.length; i++) {
        if (source[i] === '{') {
            depth++;
        } else if (source[i] === '}') {
            depth--;
            if (depth === 0) {
                return source.slice(start, i + 1);
            }
        }
    }
    throw new Error('llaves sin cerrar en ' + name);
}

// `chartsPorCanvas` es la pieza de estado que comparten las funciones.
const code = [
    'var chartsPorCanvas = new Map();',
    extract('soltarCanvas'),
    extract('destruir'),
    extract('crear'),
    extract('pintar'),
].join('\n\n');

/* ---------- Chart falso que imita el guard real ---------- */

/** Registro global canvas -> instancia viva, como `Chart.instances`. */
let registrados = new Map();
let construidos = 0;

function FakeChart(canvas) {
    if (registrados.has(canvas)) {
        throw new Error(
            "Canvas is already in use. Chart with ID '" + registrados.get(canvas).id +
            "' must be destroyed before the canvas with ID '' can be reused."
        );
    }
    construidos++;
    const self = { id: 'chart-' + construidos, canvas: canvas, destroyed: false };
    registrados.set(canvas, self);
    self.destroy = function () {
        self.destroyed = true;
        if (registrados.get(canvas) === self) {
            registrados.delete(canvas);
        }
    };
    return self;
}
FakeChart.getChart = (canvas) => registrados.get(canvas) || undefined;

const Chart = FakeChart;

/* ---------- aserciones ---------- */

let failures = 0;
let total = 0;

function is(label, got, want) {
    total++;
    if (got !== want) {
        failures++;
        console.log('  FALLO  ' + label + ': dio ' + JSON.stringify(got) + ', se esperaba ' + JSON.stringify(want));
        return;
    }
    console.log('  ok    ' + label + ' = ' + JSON.stringify(got));
}

function canvasFalso() {
    return { className: 'number-chart', id: '' };
}

/* ---------- escenarios ---------- */

const lib = new Function(
    'Chart',
    'console',
    code + '\nreturn { soltarCanvas: soltarCanvas, destruir: destruir, crear: crear, pintar: pintar, mapa: chartsPorCanvas };'
)(Chart, console);

function reset() {
    registrados = new Map();
    construidos = 0;
    lib.mapa.clear();
}

console.log('G1: repintar sobre el mismo canvas no deja el lienzo en blanco');
{
    reset();
    const c = canvasFalso();
    lib.pintar(c, { type: 'bar' });
    is('el primer gráfico se crea', registrados.has(c), true);
    let error = null;
    try {
        lib.pintar(c, { type: 'doughnut' });
    } catch (e) {
        error = e;
    }
    is('el segundo repintado NO lanza', error, null);
    is('y queda una única instancia viva', registrados.has(c), true);
    is('la anterior se destruyó', lib.mapa.get(c).id, 'chart-2');
}

console.log('G1: una instancia huérfana fuera del Map también se suelta');
{
    reset();
    const c = canvasFalso();
    // Simula un Chart creado por otra vía (no registrado en el Map).
    const huerfano = new Chart(c, { type: 'bar' });
    is('hay una instancia viva huérfana', registrados.has(c), true);
    let error = null;
    try {
        lib.pintar(c, { type: 'bar' });
    } catch (e) {
        error = e;
    }
    is('pintar sobre el huérfano tampoco lanza', error, null);
    is('el huérfano se destruyó', huerfano.destroyed, true);
    is('y el nuevo manda', registrados.get(c).id !== huerfano.id, true);
}

console.log('G1: `destruir` deja el canvas libre aunque el Map esté vacío');
{
    reset();
    const c = canvasFalso();
    const vivo = new Chart(c, { type: 'line' });
    lib.mapa.clear(); // el Map "olvida" la instancia, como en el bug real
    lib.destruir(c);
    is('la instancia viva se destruye igual', vivo.destroyed, true);
    is('y el canvas queda libre', registrados.has(c), false);
}

console.log('G1: `soltarCanvas` es idempotente si no hay nada que soltar');
{
    reset();
    const c = canvasFalso();
    let error = null;
    try {
        lib.soltarCanvas(c);
        lib.destruir(c);
    } catch (e) {
        error = e;
    }
    is('soltar un canvas limpio no lanza', error, null);
}

console.log('');
console.log('Arranques: ' + total + ' | Fallidos: ' + failures);
process.exit(failures === 0 ? 0 : 1);
