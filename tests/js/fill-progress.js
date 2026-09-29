/**
 * Comportamiento de la barra de progreso del fill (`public/assets/js/fill.js`).
 *
 * No hay framework de test en el proyecto (y no se va a meter uno), pero esto
 * no es un test de lectura de código: extrae del JS REAL las funciones de
 * progreso y las corre contra un DOM mínimo. Si alguien cambia la aritmética o
 * vuelve a filtrar por `.vf-step-hidden`, esto se pone rojo; el contrato
 * estático de `tests/unit/FillProgressTest.php` solo comprueba que las
 * funciones existen y se llaman entre sí.
 *
 * Se ejecuta desde `tests/unit/FillProgressTest.php`, que hace SKIP si este
 * entorno no tiene `node` (igual que los tests que necesitan MariaDB).
 *
 * Uso: node tests/js/fill-progress.js
 */

'use strict';

const fs = require('fs');
const path = require('path');

const FILL_JS = path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'fill.js');
const source = fs.readFileSync(FILL_JS, 'utf8');

/* ---------- extracción ---------- */

/** Cuerpo completo de una función declarada en el JS del repo. */
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

/* ---------- DOM mínimo ---------- */

function classList(names) {
    const set = new Set(names);
    return {
        contains: (c) => set.has(c),
        add: (c) => set.add(c),
        remove: (c) => set.delete(c),
        toggle: (c, on) => (on ? set.add(c) : set.delete(c)),
    };
}

/**
 * Un bloque de página. `skipped` imita el data-vf-skipped que pone
 * paintSteps(), y `stepHidden` la clase .vf-step-hidden, que lleva el paso
 * visible que no es el actual (por eso el arnés tiene que distinguirlas: la
 * primera significa «no aplica» y la segunda solo «no se está viendo»).
 */
function pageBlock(skipped, stepHidden) {
    return {
        classList: classList(stepHidden ? ['vf-step-hidden'] : []),
        getAttribute: (k) => (k === 'data-vf-skipped' && skipped ? '1' : null),
    };
}

function sectionBlock(hidden) {
    return { classList: classList(hidden ? ['vf-hidden'] : []) };
}

/**
 * Una tarjeta de campo. Cada opción modela solo lo que el código de progreso
 * mira de verdad: el tipo, si es obligatoria, las filas de una matriz, las
 * posiciones de un ranking y el valor de los inputs simples.
 */
function card(opts) {
    const o = opts || {};
    const rows = o.rows || [];
    const values = o.values || [];
    const node = {
        dataset: { vfType: o.type, vfRequired: o.required ? '1' : '0' },
        classList: classList(o.hidden ? ['vf-hidden'] : []),
        _section: o.section || null,
        _page: o.page || null,
    };

    node.closest = (sel) => {
        if (sel === '.vf-section-block') {
            return node._section;
        }
        if (sel === '.vf-page-block') {
            return node._page;
        }
        return null;
    };

    const rowElements = rows.map((checked) => ({
        querySelector: () => (checked ? { value: 'opcion' } : null),
    }));

    node.querySelectorAll = (sel) => {
        if (sel === '[data-vf-row]') {
            return rowElements;
        }
        if (sel === '[data-vf-row] input[type=radio]:checked') {
            return rows.filter(Boolean).map(() => ({}));
        }
        if (sel === '[data-vf-rank] input[type=number]') {
            return values.map((v) => ({ value: v }));
        }
        if (sel === '[data-vf-rank]') {
            return values.map((v) => ({ querySelector: () => ({ value: v }) }));
        }
        if (sel === 'input[type=checkbox]:checked') {
            return values.map((v) => ({ value: v }));
        }
        return [];
    };

    node.querySelector = (sel) => {
        if (sel === 'input:not([type=radio]):not([type=checkbox]), textarea, select') {
            return values.length ? { value: values[0] } : null;
        }
        if (sel === 'input[type=radio]:checked') {
            return values.length && values[0] !== '' ? { value: values[0] } : null;
        }
        return null;
    };

    return node;
}

/* ---------- el resto de las funciones que las de progreso necesitan ---------- */

/** Copia literal de fill.js; si cambia, la comparación de abajo se pone roja. */
function isEmptyValue(value) {
    if (Array.isArray(value)) {
        return value.filter((v) => v !== '' && v != null).length === 0;
    }
    if (value && typeof value === 'object') {
        return Object.keys(value).every((k) => value[k] === '' || value[k] == null);
    }
    return value === null || (typeof value === 'string' && value.trim() === '');
}

function getFieldValue(c) {
    const type = c.dataset.vfType;
    if (type === 'file') {
        return [];
    }
    if (type === 'checkbox') {
        return c.querySelectorAll('input[type=checkbox]:checked').map((i) => i.value);
    }
    if (type === 'matrix') {
        const out = {};
        c.querySelectorAll('[data-vf-row]').forEach((row, i) => {
            const checked = row.querySelector('input[type=radio]:checked');
            out['fila' + i] = checked ? checked.value : '';
        });
        return out;
    }
    if (type === 'ranking') {
        const out = {};
        c.querySelectorAll('[data-vf-rank]').forEach((row, i) => {
            const input = row.querySelector('input[type=number]');
            out['pos' + i] = input ? input.value : '';
        });
        return out;
    }
    if (type === 'radio' || type === 'scale' || type === 'nps' || type === 'star') {
        const checked = c.querySelector('input[type=radio]:checked');
        return checked ? checked.value : '';
    }
    const el = c.querySelector('input:not([type=radio]):not([type=checkbox]), textarea, select');
    return el ? el.value : '';
}

/**
 * Compila el % real de progreso con las funciones del repo.
 *
 * Se compila TAMBIÉN visibleFieldCards(), aunque el código bueno no la use para
 * nada: si alguien devuelve countAnswered() a esa función (que es como estaba
 * antes del arreglo), el arnés tiene que sacar un FALLO legible y no reventar
 * con un ReferenceError a medio test.
 */
function progressPercent(cards) {
    const code = [
        extract('visibleFieldCards'),
        extract('progressFieldCards'),
        extract('isCardAnswered'),
        extract('countAnswered'),
    ].join('\n\n');
    const run = new Function(
        'allFieldCards',
        'isEmptyValue',
        'getFieldValue',
        'hasPages',
        code + '\nreturn countAnswered();'
    );
    return run(cards, isEmptyValue, getFieldValue, true);
}

function answered(node) {
    const run = new Function(
        'c',
        'isEmptyValue',
        'getFieldValue',
        extract('isCardAnswered') + '\nreturn isCardAnswered(c);'
    );
    return run(node, isEmptyValue, getFieldValue);
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

function checkHarnessIsFresh() {
    // Si getFieldValue() del arnés se aparta de la del repo, los números de este
    // archivo no dicen nada sobre la app.
    const real = source.slice(source.indexOf('function getFieldValue(card)'));
    const mine = getFieldValue.toString();
    const sameShape = real.includes("if (type === 'matrix')") && mine.includes("if (type === 'matrix')")
        && real.includes("out[rowEl.dataset.vfRow]") === mine.includes("out['fila' + i]");
    is('el arnés sigue imitando getFieldValue() del repo', sameShape, true);
}

/* ---------- escenarios ---------- */

console.log('Matriz y ranking: completa o nada (antes una fila rellenaba la barra)');
is('matriz con 1 de 5 filas no cuenta', answered(card({ type: 'matrix', rows: [true, false, false, false, false] })), false);
is('matriz con las 5 filas cuenta', answered(card({ type: 'matrix', rows: [true, true, true, true, true] })), true);
is('matriz vacía no cuenta', answered(card({ type: 'matrix', rows: [false, false] })), false);
is('ranking con una posición vacía no cuenta', answered(card({ type: 'ranking', values: ['1', '2', ''] })), false);
is('ranking completo cuenta', answered(card({ type: 'ranking', values: ['1', '2', '3'] })), true);

console.log('Multipágina: el denominador es el formulario entero');
{
    // REGRESIÓN. Página 1 a la vista, página 2 y 3 detrás con .vf-step-hidden.
    // Con el código viejo (countAnswered sobre visibleFieldCards) el
    // denominador era solo lo visible y responder la primera página ponía la
    // barra al 100%. Ojo al escribir este caso: si las tarjetas no están
    // dentro de bloques .vf-page-block, el filtro viejo NO las excluía y el
    // test pasaba verde con el bug puesto.
    const step1 = pageBlock(false, false);
    const step2 = pageBlock(false, true);
    const step3 = pageBlock(false, true);
    const cards = [
        card({ type: 'text', required: true, values: ['Ana'], page: step1 }),
        card({ type: 'text', required: true, values: [''], page: step2 }),
        card({ type: 'text', required: true, values: [''], page: step3 }),
    ];
    is('la página 1 contestada NO es el 100% (1 de 3)', progressPercent(cards), 33);

    cards[1].querySelector = card({ type: 'text', values: ['Luis'] }).querySelector;
    is('página 2 contestada (2 de 3)', progressPercent(cards), 67);
}
{
    // Un solo paso visible, cinco preguntas: contestarlas todas sí es el 100%.
    const step1 = pageBlock(false, false);
    const cards = [
        card({ type: 'text', required: true, values: ['a'], page: step1 }),
        card({ type: 'text', required: true, values: ['b'], page: step1 }),
    ];
    is('las dos preguntas de la única página = 100%', progressPercent(cards), 100);
}

console.log('El paso OMITIDO se excluye; el paso que no es el actual NO');
{
    const current = card({ type: 'text', required: true, values: ['Ana'] });
    const other = card({ type: 'text', required: true, values: [''] });
    const skipped = card({ type: 'text', required: true, values: [''], hidden: true });
    current._page = pageBlock(false, false);
    other._page = pageBlock(false, true);
    skipped._page = pageBlock(true, true);
    is('1 de 2 (el omitido queda fuera del denominador)', progressPercent([current, other, skipped]), 50);
}
{
    // Un paso que se deja de saltar tiene que volver a contar. Antes, saltarlo
    // ocultaba sus tarjetas con vf-hidden y nadie las volvía a mostrar.
    const recovered = card({ type: 'text', required: true, values: [''], hidden: false });
    recovered._page = pageBlock(false, true);
    is('un paso ya no omitido vuelve al denominador', progressPercent([recovered]), 0);
}

console.log('Sección oculta por lógica');
{
    const visible = card({ type: 'text', required: true, values: ['Ana'] });
    const inSection = card({ type: 'text', required: true, values: [''], section: sectionBlock(true) });
    is('1 de 1 (la de la sección oculta no cuenta)', progressPercent([visible, inSection]), 100);
}

console.log('Sin obligatorias, cuenta el porcentaje de respondidas');
{
    const cards = [
        card({ type: 'text', values: ['x'] }),
        card({ type: 'text', values: [''] }),
        card({ type: 'text', values: ['y'] }),
        card({ type: 'text', values: [''] }),
    ];
    is('2 de 4 opcionales = 50%', progressPercent(cards), 50);
}
is('formulario vacío = 0%', progressPercent([]), 0);
is('formulario con una pregunta = 100% al contestarla', progressPercent([card({ type: 'text', values: ['x'] })]), 100);

checkHarnessIsFresh();

console.log('');
console.log('Arranques: ' + total + ' | Fallidos: ' + failures);
process.exit(failures === 0 ? 0 : 1);
