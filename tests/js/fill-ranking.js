/**
 * Interacción del fill para estrellas y ranking (`public/assets/js/fill.js`).
 *
 * No es un test de lectura de código: extrae del JS REAL las funciones que
 * pintan las estrellas (`paintStars`) y que reordenan/renumeran el ranking
 * (`rankInput`, `syncRankingOrder`, `renumberRanking`, `moveRanking`) y las
 * corre contra un DOM mínimo. Si alguien cambia la renumeración por el orden
 * visual —que es lo que hace válido el mapa opción=>orden que espera el
 * servidor— o el relleno de las estrellas, esto se pone rojo.
 *
 * Lo ejecuta `tests/unit/FillInteractionTest.php`, que hace SKIP si no hay
 * `node` (igual que los otros arneses del fill).
 *
 * Uso: node tests/js/fill-ranking.js
 */

'use strict';

const fs = require('fs');
const path = require('path');

const FILL_JS = path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'fill.js');
const source = fs.readFileSync(FILL_JS, 'utf8');

/* ---------- extracción ---------- */

function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    if (start < 0) {
        throw new Error('fill.js ya no tiene ' + name + '(): el arnés está obsoleto');
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

const code = [
    extract('paintStars'),
    extract('rankInput'),
    extract('syncRankingOrder'),
    extract('renumberRanking'),
    extract('moveRanking'),
].join('\n\n');

const lib = new Function(
    code + '\nreturn { paintStars, syncRankingOrder, renumberRanking, moveRanking };'
)();

/* ---------- DOM mínimo ---------- */

function classList(names) {
    const set = new Set(names || []);
    return {
        contains: (c) => set.has(c),
        add: (c) => set.add(c),
        remove: (c) => set.delete(c),
        toggle: (c, on) => (on ? set.add(c) : set.delete(c)),
        _set: set,
    };
}

/** Una fila del ranking: su input numérico, su badge y su clave de opción. */
function makeRow(label) {
    const input = { value: '' };
    const pos = { textContent: '' };
    const row = {
        dataset: { vfRank: label },
        querySelector: (sel) => {
            if (sel === 'input[type=number]') {
                return input;
            }
            if (sel === '.vf-ranking-pos') {
                return pos;
            }
            return null;
        },
        closest: (sel) => (sel === '[data-vf-rank]' ? row : null),
    };
    row.input = input;
    row.pos = pos;
    return row;
}

/** El contenedor [data-vf-ranking]: mantiene el orden real de sus filas. */
function makeWrap(labels) {
    const wrap = { children: labels.map(makeRow), classList: classList([]) };
    wrap.querySelectorAll = (sel) => (sel === '[data-vf-rank]' ? wrap.children.slice() : []);
    wrap.appendChild = (node) => {
        const i = wrap.children.indexOf(node);
        if (i >= 0) {
            wrap.children.splice(i, 1);
        }
        wrap.children.push(node);
        return node;
    };
    wrap.insertBefore = (node, ref) => {
        const i = wrap.children.indexOf(node);
        if (i >= 0) {
            wrap.children.splice(i, 1);
        }
        const j = wrap.children.indexOf(ref);
        if (j >= 0) {
            wrap.children.splice(j, 0, node);
        } else {
            wrap.children.push(node);
        }
        return node;
    };
    return wrap;
}

function makeBtn(row, dir) {
    return {
        dataset: { vfRankMove: dir },
        closest: (sel) => (sel === '[data-vf-rank]' ? row : null),
    };
}

function order(wrap) {
    return wrap.children.map((r) => r.dataset.vfRank).join(',');
}

function ranks(wrap) {
    return wrap.children.map((r) => r.input.value).join(',');
}

/** Un selector de estrellas: un radio por valor, con su icono. */
function makeStars(count, checkedValue) {
    const opt = (v) => {
        const input = { value: String(v) };
        const icon = { classList: classList([]) };
        return {
            querySelector: (sel) => {
                if (sel === 'input[type=radio]') {
                    return input;
                }
                if (sel === '.star-btn') {
                    return icon;
                }
                return null;
            },
            input,
            icon,
        };
    };
    const opts = Array.from({ length: count }, (_, i) => opt(i + 1));
    const selector = {
        querySelector: (sel) => {
            if (sel === 'input[type=radio]:checked') {
                if (checkedValue == null) {
                    return null;
                }
                return opts[checkedValue - 1].input;
            }
            return null;
        },
        querySelectorAll: (sel) => (sel === '.star-option' ? opts : []),
    };
    return { selector, opts };
}

function lit(stars) {
    return stars.opts.map((o) => (o.icon.classList.contains('is-lit') ? '1' : '0')).join('');
}

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

/* ---------- escenarios ---------- */

console.log('Ranking: mover reordena y renumera 1..N por orden visual');
{
    const wrap = makeWrap(['A', 'B', 'C']);
    // Valores vacíos: el primer movimiento rellena 1..N y aplica el cambio.
    lib.moveRanking(makeBtn(wrap.children[1], 'down'), wrap);
    is('mover B abajo deja A,C,B', order(wrap), 'A,C,B');
    is('y asigna 1,2,3 por el orden nuevo', ranks(wrap), '1,2,3');
}
{
    const wrap = makeWrap(['A', 'B', 'C']);
    lib.moveRanking(makeBtn(wrap.children[0], 'up'), wrap);
    is('mover A arriba (está primero) no hace nada', order(wrap), 'A,B,C');
    is('y no rellena valores: no hubo movimiento', ranks(wrap), ',,');
}
{
    const wrap = makeWrap(['A', 'B', 'C']);
    lib.moveRanking(makeBtn(wrap.children[2], 'down'), wrap);
    is('mover C abajo (está último) no hace nada', order(wrap), 'A,B,C');
    is('y no rellena valores: no hubo movimiento', ranks(wrap), ',,');
}
{
    const wrap = makeWrap(['A', 'B', 'C']);
    lib.moveRanking(makeBtn(wrap.children[2], 'up'), wrap);
    is('mover C arriba deja A,C,B', order(wrap), 'A,C,B');
    is('renumera 1,2,3', ranks(wrap), '1,2,3');
}

console.log('Ranking: el orden restaurado se sincroniza sin reescribir valores');
{
    const wrap = makeWrap(['A', 'B', 'C']);
    wrap.children[0].input.value = '3';
    wrap.children[1].input.value = '1';
    wrap.children[2].input.value = '2';
    lib.syncRankingOrder(wrap);
    is('las filas se colocan por su posición', order(wrap), 'B,C,A');
    is('los valores se conservan (los reescribe solo mover)', ranks(wrap), '1,2,3');
    is('el badge muestra el puesto', wrap.children.map((r) => r.pos.textContent).join(','), '1,2,3');
    is('marca el contenedor como realzado con JS (oculta el número)', wrap.classList.contains('vf-ranking--js') ? '1' : '0', '1');
}
{
    const wrap = makeWrap(['A', 'B', 'C']);
    wrap.children[1].input.value = '2';
    lib.syncRankingOrder(wrap);
    is('las que tienen posición van delante; las vacías mantienen su orden', order(wrap), 'B,A,C');
}

console.log('Estrellas: elegir la N rellena 1..N y el hover previsualiza');
{
    const stars = makeStars(5, 3);
    lib.paintStars(stars.selector);
    is('marcada la 3 se pintan 1,2,3', lit(stars), '11100');
}
{
    const stars = makeStars(5, null);
    lib.paintStars(stars.selector);
    is('sin marcar no se pinta ninguna', lit(stars), '00000');
}
{
    const stars = makeStars(5, 3);
    lib.paintStars(stars.selector, 5);
    is('el hover hasta la 5 previsualiza 1..5', lit(stars), '11111');
    lib.paintStars(stars.selector);
    is('al salir vuelve a la marcada (1..3)', lit(stars), '11100');
}

console.log('');
console.log('Arranques: ' + total + ' | Fallidos: ' + failures);
process.exit(failures === 0 ? 0 : 1);
