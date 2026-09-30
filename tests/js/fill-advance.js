/**
 * Comportamiento del avance automático entre páginas (`public/assets/js/fill.js`, F5).
 *
 * Igual que `fill-progress.js`, no es un test de lectura de código: extrae del
 * JS REAL la medida de completitud y la puerta de auto-avance y las corre contra
 * un DOM mínimo. Lo que importa aquí es que quien decide si el paso está
 * terminado sea `isCardAnswered()` — la misma medida que el envío y la barra — y
 * no `checkValidity()`, que es justo la trampa que F5 tenía que evitar.
 *
 * A diferencia de `fill-progress.js`, aquí NO se copia ninguna función del repo:
 * se extraen `isEmptyValue`, `getFieldValue`, `isCardAnswered`, `stepCards`,
 * `conditionVisible`, `requiredVisibleCards`, `stepComplete` y
 * `autoAdvanceAllowed`. Si el arnés copiara `isCardAnswered`, los números de
 * este archivo no dirían nada sobre la app.
 *
 * Se ejecuta desde `tests/unit/FillAdvanceTest.php`, que hace SKIP si este
 * entorno no tiene `node` (igual que los tests que necesitan MariaDB).
 *
 * Uso: node tests/js/fill-advance.js
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
 * Un bloque de página. `skipped` imita el `data-vf-skipped` que pone
 * paintSteps() cuando la condición del campo `page` hace que el paso se omita.
 */
function pageBlock(skipped) {
    return {
        classList: classList([]),
        getAttribute: (k) => (k === 'data-vf-skipped' && skipped ? '1' : null),
    };
}

function sectionBlock(hidden) {
    return { classList: classList(hidden ? ['vf-hidden'] : []) };
}

/** Un control suelto dentro de una tarjeta: el `event.target` del `change`. */
function control(type, value) {
    const el = {
        type: type,
        tagName: type === 'SELECT' ? 'SELECT' : 'INPUT',
        value: value === undefined ? 'opcion' : value,
        _page: null,
    };
    el.closest = (sel) => (sel === '.vf-page-block' ? el._page : null);
    return el;
}

/**
 * Una tarjeta de campo. Modela solo lo que la decisión de auto-avance mira:
 * el tipo, si es obligatoria, la página en la que vive, la sección que la
 * contiene (para el caso de una tarjeta en una sección oculta) y el valor de
 * sus inputs, incluidas las filas de una matriz y las posiciones de un ranking.
 */
function card(opts) {
    const o = opts || {};
    // values/rows/ranks viven EN el nodo, no en el closure: los escenarios
    // cambian la respuesta a mitad de prueba y tienen que notarlo.
    const node = {
        dataset: { vfType: o.type, vfRequired: o.required ? '1' : '0' },
        classList: classList(o.hidden ? ['vf-hidden'] : []),
        values: o.values || [],
        rows: o.rows || [],
        ranks: o.ranks || [],
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

    // Las filas y las posiciones se reconstruyen en cada consulta, porque el
    // arnés las cambia entre aserciones.
    const rowEls = () => node.rows.map((checked, i) => ({
        dataset: { vfRow: i },
        querySelector: (sel) => (sel === 'input[type=radio]:checked' && checked ? { value: 'opcion' } : null),
    }));
    const rankEls = () => node.ranks.map((v, i) => ({
        dataset: { vfRank: i },
        querySelector: (sel) => (sel === 'input[type=number]' ? { value: v } : null),
    }));

    node.querySelectorAll = (sel) => {
        if (sel === '[data-vf-row]') {
            return rowEls();
        }
        // isCardAnswered() no mira la fila sino sus descendientes marcados: un
        // selector descendant, no el de la fila. Imitar solo el que usa el repo.
        if (sel === '[data-vf-row] input[type=radio]:checked') {
            return node.rows.filter(Boolean).map(() => ({ value: 'opcion' }));
        }
        if (sel === '[data-vf-rank]') {
            return rankEls();
        }
        if (sel === '[data-vf-rank] input[type=number]') {
            return node.ranks.map((v) => ({ value: v }));
        }
        if (sel === 'input[type=checkbox]:checked') {
            return node.values.map((v) => ({ value: v }));
        }
        if (sel === 'input[type=radio]:checked') {
            return node.values[0] ? [{ value: node.values[0] }] : [];
        }
        return [];
    };

    node.querySelector = (sel) => {
        if (sel === 'input[type=radio]:checked' || sel === 'input[type=number]') {
            return node.values[0] ? { value: node.values[0] } : null;
        }
        if (sel === 'input:not([type=radio]):not([type=checkbox]), textarea, select') {
            return node.values[0] === undefined ? null : { value: node.values[0] };
        }
        return null;
    };

    return node;
}

/* ---------- la puerta, tal cual está en el repo ---------- */

/**
 * Corre `autoAdvanceAllowed()` con el estado de cierre que le pasan por
 * parámetro: el arnés declara con el MISMO nombre las variables de las que la
 * función depende (`autoAdvanceOn`, `autoAdvanceReady`, `currentStep`,
 * `pageBlocks`…), así que lo que se ejercita es el cuerpo real.
 */
function allowed(env) {
    const code = [
        extract('isEmptyValue'),
        extract('len'),
        extract('getFieldValue'),
        // validateField() entra en la ecuación porque «terminado» es contestado
        // Y VÁLIDO: sin él, un ranking con posiciones repetidas o un email mal
        // escrito darían el paso por bueno y el avance dejaría el error en la
        // página anterior. Se extrae la REAL (copiarla aquí daría al arnés el
        // poder de decir que la app valida algo que ya no valida).
        extract('validateField'),
        extract('isCardAnswered'),
        extract('conditionVisible'),
        // stepCards() filtra por stepOfCard(), que a su vez mira el índice del
        // bloque en pageBlocks: sin él, `requiredVisibleCards` no puede existir.
        extract('stepOfCard'),
        extract('stepCards'),
        extract('requiredVisibleCards'),
        extract('stepComplete'),
        extract('autoAdvanceAllowed'),
    ].join('\n\n');

    const params = [
        'allFieldCards',
        'stepBlocks',
        'autoAdvanceOn',
        'autoAdvanceReady',
        'hasPages',
        'currentStep',
        'pageBlocks',
        'target',
        't',
    ];
    const values = [
        env.allFieldCards,
        env.stepBlocks,
        !!env.on,
        env.ready !== false,
        env.pages !== false,
        env.step,
        env.pageBlocks,
        env.target,
        // validateField() solo usa t() para los TEXTOS del error: lo que
        // importa para la puerta es cuántas cadenas devuelve, no qué dicen.
        (k) => String(k),
    ];

    const run = new Function(...params, code + '\nreturn autoAdvanceAllowed(target);');
    return run(...values);
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

/**
 * Un formulario de DOS páginas, que es la forma mínima creíble de este feature:
 * con un solo bloque, `currentStep >= pageBlocks.length - 1` siempre es cierto y
 * el arnés estaría probando el guard de «último paso» sin querer.
 */
function twoPages(skippedFirst) {
    return [pageBlock(!!skippedFirst), pageBlock(false)];
}

console.log('Sin el opt-in no avanza nunca');
{
    const pages = twoPages(false);
    const c = card({ type: 'text', required: true, values: ['Ana'], page: pages[0] });
    const target = control('text', 'Ana');
    target._page = pages[0];
    const env = { step: 0, pageBlocks: pages, allFieldCards: [c], target: target };
    is('setting apagado, paso completo', allowed(Object.assign({ on: false }, env)), false);
    is('el mismo paso con el opt-in puesto sí avanza', allowed(Object.assign({ on: true }, env)), true);
}

console.log('El opt-in sale del atributo del formulario, no de un default');
is("autoAdvanceOn se lee de data-vfAutoAdvance === '1'", /form\.dataset\.vfAutoAdvance === '1'/.test(source), true);

console.log('Un texto con espacios NO está contestado');
{
    const pages = twoPages(false);
    const c = card({ type: 'text', required: true, values: ['   '], page: pages[0] });
    const target = control('text', '   ');
    target._page = pages[0];
    is('el navegador lo daría por bueno, isCardAnswered no',
        allowed({ on: true, step: 0, pageBlocks: pages, allFieldCards: [c], target: target }), false);
}

console.log('Las opcionales no bloquean, las obligatorias sí');
{
    const pages = twoPages(false);
    const opt = card({ type: 'text', required: false, values: [''], page: pages[0] });
    const req = card({ type: 'text', required: true, values: [''], page: pages[0] });
    const target = control('text', '');
    target._page = pages[0];
    const env = { on: true, step: 0, pageBlocks: pages, allFieldCards: [opt, req], target: target };

    is('con una obligatoria vacía, no avanza', allowed(env), false);
    req.values = ['Ana'];
    is('contestada la obligatoria, avanza aunque la opcional siga vacía', allowed(env), true);
}

console.log('Un paso sin obligatorias no se come el formulario');
{
    const pages = twoPages(false);
    const opt = card({ type: 'text', required: false, values: ['x'], page: pages[0] });
    const target = control('text', 'x');
    target._page = pages[0];
    is('no hay nada que esperar: no avanza',
        allowed({ on: true, step: 0, pageBlocks: pages, allFieldCards: [opt], target: target }), false);
}

console.log('Matriz y ranking: completas o nada');
{
    const pages = twoPages(false);
    const target = control('radio', 'opcion');
    target._page = pages[0];
    const m = card({ type: 'matrix', required: true, rows: [true, true, false], page: pages[0] });
    const env = { on: true, step: 0, pageBlocks: pages, allFieldCards: [m], target: target };
    is('matriz a medias no avanza', allowed(env), false);
    m.rows = [true, true, true];
    is('matriz completa avanza', allowed(env), true);

    const r = card({ type: 'ranking', required: true, ranks: ['1', '', '3'], page: pages[0] });
    const renv = { on: true, step: 0, pageBlocks: pages, allFieldCards: [r], target: target };
    is('ranking con una posición vacía no avanza', allowed(renv), false);
    r.ranks = ['1', '2', '3'];
    is('ranking completo avanza', allowed(renv), true);
    // Las posiciones repetidas SÍ las rechaza el campo (val.ranking_unique), en
    // el cliente y en el servidor. Y por eso el avance automático, que es lo
    // único que cambia de página sin que la persona pulse nada, tiene que mirar
    // la VALIDEZ y no solo la completitud: si solo mirara isCardAnswered(),
    // saltaría con el error pintado en la página anterior, que es la forma de
    // que ese error no se vuelva a ver nunca.
    r.ranks = ['1', '1', '3'];
    is('ranking con posiciones repetidas: no avanza aunque todas las posiciones tengan número',
        allowed(renv), false);
}

console.log('Una tarjeta en una sección oculta no bloquea el paso');
{
    const pages = twoPages(false);
    const visible = card({ type: 'text', required: true, values: ['Ana'], page: pages[0] });
    const hidden = card({ type: 'text', required: true, values: [''], page: pages[0], section: sectionBlock(true) });
    const target = control('text', 'Ana');
    target._page = pages[0];
    is('la obligatoria de la sección oculta no cuenta',
        allowed({ on: true, step: 0, pageBlocks: pages, allFieldCards: [visible, hidden], target: target }), true);
}

console.log('Una tarjeta oculta por su propia condición no bloquea el paso');
{
    const pages = twoPages(false);
    const visible = card({ type: 'text', required: true, values: ['Ana'], page: pages[0] });
    const byCond = card({ type: 'text', required: true, values: [''], page: pages[0], hidden: true });
    const target = control('text', 'Ana');
    target._page = pages[0];
    is('la obligatoria oculta por lógica no cuenta',
        allowed({ on: true, step: 0, pageBlocks: pages, allFieldCards: [visible, byCond], target: target }), true);
}

console.log('Escrito pero inválido tampoco es un paso terminado');
{
    const pages = twoPages(false);
    // El campo ya lo pinta en rojo con val.email: avanzar aquí escondería ese
    // error en la página anterior.
    const c = card({ type: 'email', required: true, values: ['no-es-un-email'], page: pages[0] });
    const target = control('text', 'no-es-un-email');
    target._page = pages[0];
    is('obligatorio relleno con un email inválido: no avanza',
        allowed({ on: true, step: 0, pageBlocks: pages, allFieldCards: [c], target: target }), false);
    c.values = ['ana@example.com'];
    is('el mismo campo ya válido sí avanza',
        allowed({ on: true, step: 0, pageBlocks: pages, allFieldCards: [c], target: target }), true);
}

console.log('Una OPCIONAL inválida no bloquea el paso');
{
    const pages = twoPages(false);
    // Bloquear el avance por un campo opcional que además está mal escrito
    // dejaría el formulario sin salida: la única forma de pasar sería arreglar
    // una pregunta que no hace falta responder.
    const opt = card({ type: 'email', required: false, values: ['tampoco'], page: pages[0] });
    const req = card({ type: 'text', required: true, values: ['Ana'], page: pages[0] });
    const target = control('text', 'Ana');
    target._page = pages[0];
    is('la opcional inválida no bloquea a la obligatoria que sí está bien',
        allowed({ on: true, step: 0, pageBlocks: pages, allFieldCards: [opt, req], target: target }), true);
}

console.log('El paso omitido no avanza');
{
    const pages = twoPages(true);
    const c = card({ type: 'text', required: true, values: ['Ana'], page: pages[0] });
    const target = control('text', 'Ana');
    target._page = pages[0];
    is('data-vf-skipped bloquea el avance',
        allowed({ on: true, step: 0, pageBlocks: pages, allFieldCards: [c], target: target }), false);
}

console.log('El último paso es Enviar, no Siguiente');
{
    const pages = twoPages(false);
    const c = card({ type: 'text', required: true, values: ['Ana'], page: pages[1] });
    const target = control('text', 'Ana');
    target._page = pages[1];
    is('en el último paso no se avanza',
        allowed({ on: true, step: 1, pageBlocks: pages, allFieldCards: [c], target: target }), false);
}

console.log('Un cambio que viene de otro paso no mueve este');
{
    const pages = twoPages(false);
    const c1 = card({ type: 'text', required: true, values: ['Ana'], page: pages[0] });
    const c2 = card({ type: 'text', required: true, values: ['Luis'], page: pages[1] });
    const target = control('text', 'Luis');
    target._page = pages[1];
    is('el control está en el paso 1 y se está en el 0: no avanza',
        allowed({ on: true, step: 0, pageBlocks: pages, allFieldCards: [c1, c2], target: target }), false);
}

console.log('Durante el arranque no avanza (borrador restaurado)');
{
    const pages = twoPages(false);
    const c = card({ type: 'text', required: true, values: ['Ana'], page: pages[0] });
    const target = control('text', 'Ana');
    target._page = pages[0];
    is('autoAdvanceReady=false: no avanza',
        allowed({ on: true, ready: false, step: 0, pageBlocks: pages, allFieldCards: [c], target: target }), false);
}

console.log('Formulario de una sola página: no hay a dónde ir');
{
    const c = card({ type: 'text', required: true, values: ['Ana'], page: null });
    is('sin páginas no avanza',
        allowed({ on: true, step: 0, pages: false, pageBlocks: [], allFieldCards: [c], target: control('text', 'Ana') }), false);
}

console.log('El target tiene que ser un control de una tarjeta');
{
    const pages = twoPages(false);
    const c = card({ type: 'text', required: true, values: ['Ana'], page: pages[0] });
    is('un target sin closest() no revienta',
        allowed({ on: true, step: 0, pageBlocks: pages, allFieldCards: [c], target: { type: 'checkbox' } }), false);
}

console.log('');
console.log('Arranques: ' + total + ' | Fallidos: ' + failures);
process.exit(failures === 0 ? 0 : 1);
