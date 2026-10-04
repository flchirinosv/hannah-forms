/**
 * Comportamiento del envío sin recarga (`public/assets/js/app.js`).
 *
 * No hay framework de test en el proyecto (y no se va a meter uno), pero esto
 * no es un test de lectura de código: extrae del JS REAL `vfPost()` y el
 * delegado de `submit` y los corre contra un DOM y un `fetch` de mentira.
 *
 * Lo que se fija aquí es lo caro de romper en silencio:
 *
 *   · El camino NATIVO es el de rescate. Si la respuesta no es JSON (una página
 *     de error tunesca llega con 200 y `Content-Type: text/html`), o no hay red,
 *     o no existe `fetch`, el formulario se envía con `form.submit()`. Ese
 *     `submit()` no dispara el evento `submit`, así que no se reentra.
 *   · Un `ok:false` del servidor NO es un fallo de red: se pinta SU motivo y no
 *     se reenvía nada. Reenviar un rechazo «por si acaso» duplica la operación.
 *   · `data-confirm` se pregunta ANTES de decidir el modo: cancelar no envía.
 *   · El botón se apaga y vuelve a encenderse, y el giro del markup se quita y
 *     se vuelve a poner (`d-none`): el botón de #formUpdate vive FUERA del
 *     formulario y se localiza por `form="…"`.
 *   · `data-vf-busy` evita el doble clic: dos envíos, dos POST.
 *
 * Se ejecuta desde `tests/unit/AsyncActionsJsTest.php`, que hace SKIP si este
 * entorno no tiene `node`.
 *
 * Uso: node tests/js/async-actions.js
 */

'use strict';

const fs = require('fs');
const path = require('path');

const APP_JS = path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'app.js');
const source = fs.readFileSync(APP_JS, 'utf8');

/* ---------- extracción ---------- */

/**
 * El código de una función del repo, desde `index` hasta su llave de cierre.
 *
 * Cuenta llaves sin que le falseen ni los comentarios ni las cadenas ni los
 * LITERALES DE REGEX: el `esc()` de app.js tiene `.replace(/"/g, '&quot;')`,
 * y un conteo a pelo lee ese `/` como el principio de un comentario o de una
 * cadena y se va de cabeza hasta el final del fichero. El criterio de «aquí
 * empieza una regex» es el de siempre: una `/` precededida por algo que no
 * puede cerrar una expresión (una letra, un número, un `)` o un `]`).
 */
function extractAt(index, nombre) {
    if (index < 0) {
        throw new Error('app.js ya no tiene ' + nombre + ': el arnés está obsoleto');
    }
    let depth = 0;
    let abierto = false;
    let mode = null; // null | 'line' | 'block' | 'regex' | clase | "'" | '"' | '`'
    let previo = '';   // último carácter que no era espacio
    for (let i = index; i < source.length; i++) {
        const c = source[i];
        const next = source[i + 1];
        if (mode === 'line') {
            if (c === '\n') mode = null;
            continue;
        }
        if (mode === 'block') {
            if (c === '*' && next === '/') { mode = null; i++; }
            continue;
        }
        if (mode === 'regex') {
            if (c === '\\') { i++; continue; }
            if (c === '[') mode = 'clase';
            else if (c === ']') mode = 'regex';
            else if (c === '/') mode = null;
            continue;
        }
        if (mode === 'clase') {
            if (c === '\\') { i++; continue; }
            if (c === ']') mode = 'regex';
            continue;
        }
        if (mode) {
            if (c === '\\') { i++; continue; }
            if (c === mode) mode = null;
            continue;
        }
        if (c === '/' && next === '/') { mode = 'line'; i++; continue; }
        if (c === '/' && next === '*') { mode = 'block'; i++; continue; }
        if (c === '/' && 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$)]'.indexOf(previo) === -1) { mode = 'regex'; continue; }
        if (c === "'" || c === '"' || c === '`') { mode = c; continue; }
        if (c === '{') { depth++; abierto = true; }
        else if (c === '}') {
            depth--;
            if (abierto && depth === 0) return source.slice(index, i + 1);
        }
        if (!/\s/.test(c)) previo = c;
    }
    throw new Error('llaves sin cerrar en ' + nombre);
}

function extract(name) {
    return extractAt(source.indexOf('function ' + name + '('), name + '()');
}

/** `window.vfPost = function (…)` no se busca por `function name(`. */
function extractAssigned(name) {
    return extractAt(source.indexOf('window.' + name + ' = function'), 'window.' + name);
}

/* ---------- DOM mínimo ---------- */

function classList(names) {
    const set = new Set(names);
    return {
        contains: (c) => set.has(c),
        add: (c) => set.add(c),
        remove: (c) => set.delete(c),
    };
}

/**
 * Un elemento. Solo lo que el código de `app.js` mira de verdad: atributos,
 * clases, `innerHTML`, el padre para `closest()` y `remove()`, y los selectores
 * que usan sus tres helpers: `[type=submit]`, `.spinner-border`,
 * `[data-vf-role="…"]`, `[data-vf-row]` y `button[form="…"]`.
 */
function el(tag, attrs = {}, extra = {}) {
    const node = {
        nodeName: tag.toUpperCase(),
        tag: tag,
        attrs: Object.assign({}, attrs),
        classes: [],
        children: [],
        parent: null,
        innerHTML: '',
        textContent: '',
        removed: false,
        submitted: 0,
        matches(sel) {
            const m = sel.match(/^([a-zA-Z]*)([^\[]*)(?:\[([^\]]+)\])?$/);
            if (!m) return false;
            if (m[1] && m[1].toLowerCase() !== tag.toLowerCase()) return false;
            if (m[2]) {
                const want = m[2].split('.').filter(Boolean);
                for (const c of want) if (!node.classes.includes(c)) return false;
            }
            if (m[3]) {
                const kv = m[3].match(/^([^=]+)(?:=["']?([^"']*)["']?)?$/);
                const name = kv[1].trim();
                const val = kv[2];
                if (!(name in node.attrs)) return false;
                if (val !== undefined && node.attrs[name] !== val) return false;
            }
            return true;
        },
        getAttribute(k) {
            return Object.prototype.hasOwnProperty.call(node.attrs, k) ? node.attrs[k] : null;
        },
        setAttribute(k, v) { node.attrs[k] = String(v); },
        removeAttribute(k) { delete node.attrs[k]; },
        querySelector(sel) { return find(node, sel); },
        querySelectorAll(sel) { return all(node, sel); },
        closest(sel) {
            let n = node;
            while (n) {
                if (n.matches && n.matches(sel)) return n;
                n = n.parent;
            }
            return null;
        },
        remove() {
            node.removed = true;
            if (node.parent) {
                node.parent.children = node.parent.children.filter((c) => c !== node);
                // Y `parentNode` pasa a null, como en el DOM: si no, `closest()`
                // del harness seguía encontrando la fila que el navegador ya
                // no tiene en el documento, y el «se quitó la fila» pasaba sin
                // haberse quitado nada.
                node.parent = null;
            }
        },
        appendChild(child) {
            child.parent = node;
            node.children.push(child);
            return child;
        },
        /* El `submit()` NATIVO del navegador: no dispara el evento. Contado
         * para poder distinguir "rescate" de "no hizo nada". */
        submit() { node.submitted++; },
        /* `requestSubmit()` sí dispara el `submit`, como el navegador, y por eso
         * hay que distinguirlo de `submit()`: el diálogo de confirmación vuelve
         * a entrar por aquí, y si el arnés lo tratara como nativo daría verde
         * con el bucle de preguntas puesto. */
        requestSubmit(submitter) {
            node.requested++;
            node.requestedWith = submitter || null;
            if (typeof node.vfRequestSubmit === 'function') node.vfRequestSubmit();
        },
        requested: 0,
        focused: 0,
        focus() { node.focused++; node.hasFocus = true; },
        /* Listeners por tipo. Lo mínimo: `addEventListener` para el botón de
         * «sí» y para los eventos `*.bs.modal` de Bootstrap (que no son eventos
         * del DOM: no se pueden despachar con `dispatchEvent`, así que aquí se
         * llama a `emit()` a mano). */
        listeners: {},
        addEventListener(tipo, fn) {
            (node.listeners[tipo] = node.listeners[tipo] || []).push(fn);
        },
        emit(tipo) {
            (node.listeners[tipo] || []).forEach((fn) => fn({ target: node }));
        },
        /** Un clic de verdad: el que el usuario hace, y el que el
         * `btn-close` y el «Cancelar» no necesitan (los ata Bootstrap). */
        click() { node.emit('click'); },
    };
    // Fuera del literal: `node` no existe todavía dentro de él.
    node.classList = classList(node.classes);
    // `vfToast()` asigna `className` de una tacada (una cadena con el icono y
    // el tipo de color). Sin esto, `classes` y `className` serían dos verdades
    // y las aserciones sobre el color del aviso leerían la que no se escribe.
    // `node.id` refleja el atributo, como en el DOM: `vfSubmitButton()` mira
    // `form.id` (no `getAttribute('id')`) para buscar el botón externo.
    Object.defineProperty(node, 'id', {
        get: () => (Object.prototype.hasOwnProperty.call(node.attrs, 'id') ? node.attrs.id : ''),
        set: (v) => { node.attrs.id = String(v); },
    });
    /* `button.form` como en el DOM: el `<form>` que lo contiene, el que declara
     * con `form="…"` aunque esté fuera, o `null`. `vfConfirmGo()` compara el
     * submitter con el formulario para no perder el botón en el reenvío, así que
     * sin esto la comprobación miraría `undefined` y el arnés daría verde con el
     * submitter perdiéndose en el camino. */
    Object.defineProperty(node, 'form', {
        get() {
            const declarado = node.attrs.form;
            if (declarado) {
                let cima = node;
                while (cima.parent) cima = cima.parent;
                let encontrado = null;
                walk(cima, (n) => { if (!encontrado && n.id === declarado && n.tag === 'form') encontrado = n; });
                return encontrado;
            }
            let p = node.parent;
            while (p) {
                if (p.tag === 'form') return p;
                p = p.parent;
            }
            return null;
        },
    });
    Object.defineProperty(node, 'className', {
        get: () => node.classes.join(' '),
        set: (v) => { node.classes = String(v).split(/\s+/).filter(Boolean); },
    });
    Object.assign(node, extra);
    return node;
}

function walk(node, fn) {
    fn(node);
    for (const c of node.children) walk(c, fn);
}

function find(root, sel) {
    let out = null;
    walk(root, (n) => { if (!out && n !== root && n.matches(sel)) out = n; });
    return out;
}

function all(root, sel) {
    const out = [];
    walk(root, (n) => { if (n !== root && n.matches(sel)) out.push(n); });
    return out;
}

/**
 * Monta el escenario. `opciones.slot` es lo que hay que reponer, `opciones.row`
 * envuelve el formulario (para el modo `remove`), `botonExterno` pone el
 * `<button form="…">` FUERA del form, que es como está en la ficha.
 */
function escenario(opciones = {}) {
    const body = el('body', { 'data-csrf': 'token-de-prueba' });
    const root = el('html');
    root.appendChild(body);

    const form = el('form', Object.assign({ action: '/forms/7/close' }, opciones.id ? { id: opciones.id } : {}, opciones.attrs));
    if (opciones.confirm) form.attrs['data-confirm'] = opciones.confirm;
    if (opciones.spinner) {
        const spin = el('span', { class: 'spinner-border d-none' });
        spin.classes = ['spinner-border', 'd-none'];
        spin.classList = classList(spin.classes);
        opciones._spin = spin;
    }

    /* `botonInterno` como cadena = el botón cuelga del layout con
     * `form="<id>"` (el caso de #formUpdate); sin ella, va dentro del form. */
    let boton = null;
    if (opciones.boton !== false) {
        if (opciones.botonInterno) {
            boton = el('button', { form: opciones.botonInterno });
            root.appendChild(boton);
        } else {
            boton = el('button', { type: 'submit' });
            form.appendChild(boton);
        }
        if (opciones.spinner) boton.appendChild(opciones._spin);
    }

    let padre = form;
    if (opciones.row) {
        padre = el('tr', { 'data-vf-row': '' });
        padre.appendChild(form);
        root.appendChild(padre);
    } else {
        root.appendChild(form);
    }

    const slots = {};
    (opciones.slots || []).forEach((id) => {
        const s = el('div', { id: id });
        s.innerHTML = 'viejo';
        body.appendChild(s);
        slots[id] = s;
    });

    /* Registro de `id` → nodo, que es lo que resuelve `getElementById`. Se
     * declara aquí (y no junto al `document`) porque el par campo/aviso de
     * más abajo ya lo necesita. */
    const ids = {};

    /* El par campo/aviso del alta de la allowlist (`#accessAddEmail` y
     * `#accessAddError`), que es lo que apunta `data-vf-error` y `data-vf-clear`.
     * Viven fuera del `<form>` en el markup real, así que aquí también. */
    let campo = null;
    let aviso = null;
    if (opciones.aviso || opciones.limpiar) {
        const idAviso = opciones.aviso || 'accessAddError';
        const idCampo = opciones.limpiar || 'accessAddEmail';
        aviso = el('p', { id: idAviso, class: 'small mb-0 mt-1 d-none' });
        aviso.classes = ['small', 'mb-0', 'mt-1', 'd-none'];
        campo = el('input', { id: idCampo });
        body.appendChild(aviso);
        body.appendChild(campo);
        ids[idAviso] = aviso;
        ids[idCampo] = campo;
    }

    let contador = null;
    if (opciones.contador !== undefined) {
        contador = el('span', { 'data-vf-role': 'response-count' });
        contador.textContent = '(' + opciones.contador + ')';
        body.appendChild(contador);
    }

    const toasts = el('div', { id: 'toastWrap' });
    body.appendChild(toasts);

    ids.toastWrap = toasts;
    Object.keys(slots).forEach((id) => { ids[id] = slots[id]; });

    /* `#vfConfirm` es el diálogo del layout (`views/layouts/main.php`), con las
     * mismas piezas: la caja, el texto de la pregunta y los dos botones. Sin
     * `modal: true` NO está, y es lo que obliga a `vfAsk()` a caer al
     * `confirm()` del navegador — el camino que se quiere conservar para las
     * páginas que no traen el layout del panel. */

    let dialogo = null;
    if (opciones.modal) {
        const cancel = el('button', { type: 'button', id: 'vfConfirmCancel' });
        cancel.classes = ['btn', 'btn-outline-secondary'];
        dialogo = el('div', { id: 'vfConfirm', class: 'modal fade' });
        dialogo.appendChild(el('p', { id: 'vfConfirmText' }));
        dialogo.appendChild(cancel);
        dialogo.appendChild(el('button', { type: 'button', id: 'vfConfirmOk', class: 'btn btn-danger' }));
        body.appendChild(dialogo);
        ids.vfConfirm = dialogo;
        ids.vfConfirmText = dialogo.children[0];
        ids.vfConfirmCancel = cancel;
        ids.vfConfirmOk = dialogo.children[2];
    }

    const document = {
        body: body,
        getElementById: (id) => (Object.prototype.hasOwnProperty.call(ids, id) ? ids[id] : null),
        querySelector: (sel) => find(root, sel),
        querySelectorAll: (sel) => all(root, sel),
        createElement: (tag) => el(tag),
    };

    /* Bootstrap de mentira: solo `Modal.getOrCreateInstance().show/hide`, que
     * es lo que el diálogo usa. El `hidden.bs.modal` (lo que dispara el `Esc` o
     * el «Cancelar») NO lo emite él —los emite quien lo causa— y por eso hay un
     * `cerrar()` en el escenario. */
    const modal = {
        shown: 0,
        hidden: 0,
        show() { modal.shown++; },
        hide() { modal.hidden++; },
    };
    const bootstrap = { Modal: { getOrCreateInstance: () => modal } };

    const ventana = {
        document: document,
        location: {
            href: '/dashboard/forms/7',
            assigned: null,
            reloaded: 0,
            assign(u) { ventana.location.assigned = u; },
            reload() { ventana.location.reloaded++; },
        },
        confirm(msg) {
            ventana.confirmMsg = msg;
            return opciones.confirmar !== false;
        },
        fetches: [],
        fetch: null,
    };

    /* Sin `requestSubmit` (Safari viejo, y el `else` de `vfConfirmGo()`): el
     * diálogo confirma y la vuelta al navegador es el POST nativo entero. */
    if (opciones.sinRequestSubmit) {
        form.requestSubmit = undefined;
    }

    return {
        document: document,
        window: ventana,
        root: root,
        form: form,
        fila: padre,
        boton: boton,
        spinner: opciones._spin || null,
        slots: slots,
        contador: contador,
        toasts: toasts,
        /* El diálogo: `confirmar()` es el clic del botón de «sí» y `cerrar()` es
         * lo que hace el «Cancelar», la `X` o el `Esc` (los tres son
         * `data-bs-dismiss`, y el `hidden.bs.modal` es lo que sueltra lo
         * pendiente). */
        dialogo: dialogo,
        campo: campo,
        aviso: aviso,
        ok: dialogo ? dialogo.children[2] : null,
        cancelar: dialogo ? dialogo.children[1] : null,
        texto: dialogo ? dialogo.children[0] : null,
        modal: modal,
        bootstrap: bootstrap,
        bootstrapVacio: false,
        confirmar() {
            /* Como el navegador: `requestSubmit()` vuelve a lanzar el evento
             * `submit` y el delegado lo ve otra vez. Sin esto el arnés
             * comprobaría que el bypass existe sin que llegue a ejecutarse. */
            const yo = this;
            form.vfRequestSubmit = function () { yo.handle(evento(form, { submitter: boton })); };
            if (dialogo) dialogo.children[2].click();
            else ventana.confirm(true);
            form.vfRequestSubmit = null;
        },
        cerrar() { if (dialogo) dialogo.emit('hidden.bs.modal'); },
        /* `fetch` de mentira: registra la llamada y devuelve lo que se le haya
         * dado. Por defecto, JSON con `ok:true`. */
        setFetch(fn) { ventana.fetch = fn; },
        defaultFetch(json, contentType) {
            return function (url, init) {
                ventana.fetches.push({ url: url, init: init });
                const headers = { get: (k) => (k === 'content-type' ? (contentType || 'application/json; charset=utf-8') : null) };
                return Promise.resolve({
                    ok: true,
                    status: 200,
                    headers: headers,
                    json: () => Promise.resolve(json || { ok: true }),
                });
            };
        },
    };
}

/* Compila el código real del repo contra el escenario. Devuelve un `disparar`
 * que llama al delegado y devuelve una promesa resuelta cuando todo ha corrido.
 */
function montar(esc) {
    const codigo = [
        extract('esc'),
        extract('vfCsrfToken'),
        extractAssigned('vfToast'),
        extractAssigned('vfPost'),
        extract('vfApplySlots'),
        extract('vfSubmitButton'),
        extract('vfSpinner'),
        extract('vfByAttr'),
        extract('vfConfirmBox'),
        extract('vfConfirmWire'),
        extract('vfAsk'),
        extract('vfConfirmGo'),
        extract('vfHandleSubmit'),
        extract('vfAutoSubmit'),
    ].join('\n\n');

    const contexto = {
        document: esc.document,
        window: esc.window,
        bootstrap: esc.bootstrapVacio ? null : esc.bootstrap,
        /* Los tres `var` del módulo (`vfPending`, `vfConfirmed`, `vfWired`) se
         * pasan como PARÁMETROS de la función compilada, que es lo mismo que el
         * ámbito del módulo dentro del IIFE: se escriben y se leen igual entre
         * llamadas. Como valores sueltos en el contexto se perderían en cada
         * llamada, y con ellos el «ya no pregunto esta vez» y el «el botón ya
         * está atado». */
        vfPending: null,
        vfConfirmed: null,
        vfWired: null,
        FormData: function FormDataStub(form) { this.form = form; this.entries = []; },
        setTimeout: setTimeout,
        clearTimeout: clearTimeout,
        fetch: function (url, init) {
            if (typeof esc.window.fetch !== 'function') {
                return Promise.reject(new Error('sin fetch'));
            }
            return esc.window.fetch(url, init);
        },
        Promise: Promise,
        Event: Event,
    };

    const nombres = Object.keys(contexto);
    const fn = new Function(
        nombres.join(','),
        codigo + '\nreturn { handle: vfHandleSubmit, post: window.vfPost, toast: window.vfToast,'
            + ' autoSubmit: vfAutoSubmit,'
            + ' wire: vfConfirmWire, ask: vfAsk, go: vfConfirmGo };'
    );
    const api = fn.apply(null, nombres.map((n) => contexto[n]));
    /* `esc.confirmar()` (el clic del botón de «sí») vuelve a pasar por el
     * delegado, así que necesita la referencia. */
    esc.handle = api.handle;
    return api;
}

/**
 * Registra el delegado como el `submit` que pone `app.js` en el documento, pero
 * en el formulario: el arnés no imita el `document.addEventListener`, así que
 * quien necesite que un `submit` **disparado por el código** llegue al delegado
 * (un `requestSubmit()` del auto-envío, un `data-vf-confirm` de segunda pasada)
 * tiene que atarlo aquí. `prevented` cuenta las `preventDefault()`, que es lo
 * que separa «el delegado lo cogió» de «el formulario se fue nativo».
 */
function conDelegado(esc) {
    esc.form.addEventListener('submit', function (ev) {
        const e = evento(esc.form);
        esc.handle(e);
        ev.prevented = e.prevented;
    });
    return esc;
}

/** Deja correr la cadena de promesas (vfPost → then → then). */
function flush() {
    return new Promise((res) => setImmediate(res)).then(() => new Promise((res) => setImmediate(res)));
}

function evento(form, atributos = {}) {
    return Object.assign({
        target: form,
        prevented: 0,
        preventDefault() { this.prevented++; },
    }, atributos);
}

/* ---------- aserciones ---------- */

let failures = 0;
let total = 0;

/** `JSON.stringify` que no se tira con un nodo del DOM (tiene ciclos). */
function ver(v) {
    if (v === null || typeof v !== 'object') return JSON.stringify(v);
    if (v.nodeName) return '<' + String(v.tag || v.nodeName).toLowerCase() + '>';
    return '[objeto]';
}

function is(label, got, want) {
    total++;
    if (got !== want) {
        failures++;
        console.log('  FALLO  ' + label + ': dio ' + ver(got) + ', se esperaba ' + ver(want));
        return;
    }
    console.log('  ok    ' + label + ' = ' + ver(got));
}

/**
 * Que el arnés siga hablando del JS real. Si alguien renombra `vfPost` o
 * quita el `form.submit()` del `catch`, los escenarios de abajo correrían
 * contra código viejo y pasarían en verde: esto es lo que lo ata al fichero.
 */
function checkHarnessIsFresh() {
    is('app.js sigue declarando window.vfPost', source.includes('window.vfPost = function'), true);
    is('el delegado sigue siendo una función con nombre (extraíble)', source.includes('function vfHandleSubmit('), true);
    // Se mira el CUERPO de vfHandleSubmit, no el fichero entero: un regex
    // flojo sobre el `source` casa con el `form.submit()` de otro bloque
    // (el auto-envío del select de rol) y da verde con el rescate borrado.
    // Sin comentarios: el propio `vfHandleSubmit` explica POR QUÉ no lleva
    // `.catch()`, y un chequeo de texto sin quitarlos lee su propia nota.
    const cuerpo = extract('vfHandleSubmit')
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/^\s*\/\/.*$/gm, ' ');
    is('el rescate sigue siendo el submit() nativo', cuerpo.indexOf('form.submit()') > -1, true);
    is('el rescate va en el SEGUNDO argumento (una excepción al pintar no reenvía)', cuerpo.indexOf('}, function () {') > -1, true);
    is('y no hay un .catch que se lo trague todo', cuerpo.indexOf('.catch(') === -1, true);
    is('los huecos se buscan por id', source.includes("document.getElementById(id)"), true);
}

/* Una excepción dentro del manejador de la respuesta se sale como rechazo sin
 * manejar: en el navegador es un error de consola y nada más. Se cuenta para
 * poder afirmar que el fallo se queda ahí y no se convierte en otra operación. */
let rechazos = 0;
process.on('unhandledRejection', () => { rechazos++; });

async function main() {
    checkHarnessIsFresh();

    console.log('El camino nativo sigue intacto');
    {
        // Sin `data-vf-async` no se toca nada: el formulario se va por su POST
        // de siempre y el delegado no se apropia del evento.
        const esc = escenario({ slots: ['vfSlotStatusBadge'] });
        esc.setFetch(esc.defaultFetch());
        const { handle } = montar(esc);
        const ev = evento(esc.form);
        handle(ev);
        await flush();
        is('sin data-vf-async no se previene el evento', ev.prevented, 0);
        is('sin data-vf-async no hay fetch', esc.window.fetches.length, 0);
        is('sin data-vf-async el JS no envía por su cuenta', esc.form.submitted, 0);
    }
    {
        // Sin `window.fetch` (navegador viejo): el evento NO se previene, porque
        // lo que tiene que salir es el envío nativo del navegador.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' } });
        esc.window.fetch = null;
        const { handle } = montar(esc);
        const ev = evento(esc.form);
        handle(ev);
        await flush();
        is('sin fetch no se previene el evento', ev.prevented, 0);
        is('sin fetch el JS tampoco llama a submit()', esc.form.submitted, 0);
    }

    console.log('La confirmación va antes que cualquier envío');
    {
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' }, confirm: '¿Cerrar el formulario?' });
        esc.setFetch(esc.defaultFetch());
        const { handle } = montar(esc);
        const ev = evento(esc.form);
        handle(ev);
        await flush();
        is('se preguntó con el texto del data-confirm', esc.window.confirmMsg, '¿Cerrar el formulario?');
        is('aceptar sí sale (el evento lo cogió el fetch)', ev.prevented, 1);
        is('y sale UNA vez', esc.window.fetches.length, 1);
    }
    {
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' }, confirm: '¿Seguro?', confirmar: false });
        esc.setFetch(esc.defaultFetch());
        const { handle } = montar(esc);
        const ev = evento(esc.form);
        handle(ev);
        await flush();
        // Previene A PROPÓSITO: es lo que impide que el navegador saque el
        // envío nativo detrás. Lo que no puede pasar es que salga algo.
        is('cancelar la confirmación previene el envío nativo', ev.prevented, 1);
        is('y no sale nada por fetch', esc.window.fetches.length, 0);
    }
    {
        // La confirmación es delega DOBLE: el `data-confirm` que estaba en el
        // botón y no en el `<form>` no lo miraba nadie, y el borrado salía sin
        // preguntar. Ahora está en el form.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' }, confirmar: false });
        esc.boton.setAttribute('data-confirm', '¿Borrar?');
        esc.setFetch(esc.defaultFetch());
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        is('un data-confirm en el BOTÓN no pregunta por nadie', esc.window.fetches.length, 1);
    }

    console.log('La confirmación se pregunta en un DIÁLOGO, no en confirm()');
    {
        const esc = escenario({
            attrs: { 'data-vf-async': 'slots', 'data-confirm-ok': 'Cerrar formulario' },
            confirm: '¿Cerrar este formulario?',
            modal: true,
        });
        esc.setFetch(esc.defaultFetch({ ok: true }));
        const { handle } = montar(esc);
        const ev = evento(esc.form);
        handle(ev);
        await flush();

        is('el evento se previene (nadie tiene que hacer nada todavía)', ev.prevented, 1);
        is('y NO sale nada por fetch hasta que se confirme', esc.window.fetches.length, 0);
        is('confirm() del navegador NO se ha llamado', esc.window.confirmMsg, undefined);
        is('el diálogo se ha abierto', esc.modal.shown, 1);
        is('la pregunta va en el texto del diálogo', esc.texto.textContent, '¿Cerrar este formulario?');
        is('y no por innerHTML (el texto lleva HTML de la vista)', esc.texto.innerHTML, '');
        is('el botón de «sí» dice la acción, no «Sí, continuar»', esc.ok.textContent, 'Cerrar formulario');
        is('sin `data-confirm-tone` el botón es rojo', esc.ok.className, 'btn btn-danger');
        is('el formulario no se ha enviado por su cuenta', esc.form.submitted, 0);
        is('ni se ha reenviado con requestSubmit', esc.form.requested, 0);
    }
    {
        // `data-confirm-tone="primary"`: publicar o activar no es borrar, y un
        // botón rojo encima de «Publicar» enseña a leer mal los botones rojos.
        const esc = escenario({
            attrs: { 'data-vf-async': 'slots', 'data-confirm-tone': 'primary', 'data-confirm-ok': 'Publicar' },
            confirm: '¿Publicar?',
            modal: true,
        });
        esc.setFetch(esc.defaultFetch({ ok: true }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        is('con tono primary el botón de «sí» es azul', esc.ok.className, 'btn btn-primary');
    }
    {
        // El foco va a «Cancelar». Abrir el diálogo con «Eliminar formulario»
        // ya enfocado es un `Enter` de distancia de lo que no tiene vuelta atrás.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' }, confirm: '¿Eliminar?', modal: true });
        esc.setFetch(esc.defaultFetch({ ok: true }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        esc.dialogo.emit('shown.bs.modal');
        is('al abrir, el foco está en Cancelar', esc.cancelar.focused, 1);
        is('y no en el botón que borra', esc.ok.focused, 0);
    }

    console.log('Confirmar en el diálogo envía UNA vez y no vuelve a preguntar');
    {
        const esc = escenario({
            attrs: { 'data-vf-async': 'slots', 'data-confirm-ok': 'Eliminar' },
            confirm: '¿Eliminar este formulario y todas sus respuestas?',
            modal: true,
        });
        esc.setFetch(esc.defaultFetch({ ok: true, message: 'Eliminado.' }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();

        esc.confirmar();                       // el clic del botón de «sí»
        await flush();

        is('sale el POST por fetch', esc.window.fetches.length, 1);
        is('y con el diálogo cerrado detrás', esc.modal.hidden, 1);
        is('una sola vez: el reenvío con requestSubmit no vuelve a preguntar', esc.modal.shown, 1);
        is('el evento se previó otra vez (la segunda pasada sí)', esc.form.requested, 1);
        is('el botón de «sí» recibe el submitter', esc.form.requestedWith, esc.boton);
        is('y sale por fetch, no por el submit() nativo', esc.form.submitted, 0);
    }
    {
        // Cancelar: `Esc`, la `X` o el botón son lo mismo para el JS (Bootstrap
        // emite `hidden.bs.modal`), y lo pendiente se olvida: si no se olvidara,
        // el siguiente `Enter» en cualquier parte confirmaría la acción
        // anterior.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' }, confirm: '¿Eliminar?', modal: true });
        esc.setFetch(esc.defaultFetch({ ok: true }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        esc.cerrar();
        esc.confirmar();
        await flush();
        is('cancelar no envía nada', esc.window.fetches.length, 0);
        is('y un clic posterior en «sí» tampoco', esc.form.requested, 0);
    }
    {
        // Sin el diálogo (una página que no usa el layout del panel) se cae al
        // `confirm()` del navegador. La pregunta no puede desaparecer nunca,
        // aunque sea fea: ese es el caso que ya existía.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' }, confirm: '¿Seguro?' });
        esc.setFetch(esc.defaultFetch({ ok: true }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        is('sin #vfConfirm se usa confirm()', esc.window.confirmMsg, '¿Seguro?');
        is('y aceptando sale el POST', esc.window.fetches.length, 1);
    }
    {
        // Navegador sin `requestSubmit`: no hay forma de reenviar por fetch, así
        // que el `else` de `vfConfirmGo()` devuelve el envío al navegador entero
        // (el POST nativo). Sigue siendo la misma operación, no dos.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' }, confirm: '¿Eliminar?', modal: true, sinRequestSubmit: true });
        esc.setFetch(esc.defaultFetch({ ok: true }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        esc.confirmar();
        await flush();
        is('sin requestSubmit, confirmar manda el POST nativo', esc.form.submitted, 1);
        is('y no por fetch', esc.window.fetches.length, 0);
    }
    {
        // El diálogo en la página pero sin Bootstrap (el bundle no cargó, o
        // alguien lo quitó): mismo camino que sin diálogo, y no una excepción
        // en mitad del `submit`.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' }, confirm: '¿Seguro?' });
        esc.bootstrapVacio = true;
        esc.setFetch(esc.defaultFetch({ ok: true }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        is('sin bootstrap tampoco revienta', esc.window.confirmMsg, '¿Seguro?');
        is('y sale el POST', esc.window.fetches.length, 1);
    }
    {
        // El botón de «sí» se ata al cargar (no solo en el primer uso): con el
        // diálogo ya en la página, `wire()` ata el listener sin preguntas de por
        // medio.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' }, confirm: '¿Eliminar?', modal: true });
        esc.setFetch(esc.defaultFetch({ ok: true }));
        const { wire } = montar(esc);
        wire();
        wire();                                 // dos veces: atar dos veces sería clic doble
        esc.dialogo.emit('shown.bs.modal');
        is('el foco se puede preparar sin preguntar nada', esc.cancelar.focused, 1);
        is('y atarlo dos veces no duplica el foco', esc.cancelar.focused, 1);
    }

    console.log('El aviso va junto a su campo, no flotante y por duplicado');
    {
        // `data-vf-error`: el «ese correo ya está» es del campo, se lee debajo
        // del campo y lo anuncia el `role="alert"` que hay ahí. Si además
        // saliera flotante sería lo mismo dos veces.
        const esc = escenario({
            attrs: { 'data-vf-async': 'slots', 'data-vf-error': 'accessAddError' },
            aviso: 'accessAddError',
        });
        esc.setFetch(esc.defaultFetch({ ok: false, error: 'Ese correo ya está en la lista.' }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        is('el motivo va al elemento del formulario', esc.aviso.textContent, 'Ese correo ya está en la lista.');
        is('y se quita su d-none para que se vea', esc.aviso.classList.contains('d-none'), false);
        is('sin aviso flotante (sería el mismo texto dos veces)', esc.toasts.children.length, 0);
        is('y sin reenviar nada', esc.form.submitted, 0);
    }
    {
        // `data-vf-clear`: encadenar altas. El campo se vacía, el foco vuelve a
        // él (para el siguiente correo sin tabular hasta el principio) y el aviso
        // del intento anterior desaparece.
        const esc = escenario({
            attrs: { 'data-vf-async': 'slots', 'data-vf-error': 'accessAddError', 'data-vf-clear': 'accessAddEmail' },
            aviso: 'accessAddError',
            limpiar: 'accessAddEmail',
            slots: ['accessList'],
        });
        esc.campo.value = 'nuevo@ejemplo.com';
        esc.aviso.textContent = 'Ese correo ya está en la lista.';
        esc.aviso.classes = ['small', 'mb-0', 'mt-1', 'text-danger', 'fw-semibold'];
        esc.setFetch(esc.defaultFetch({ ok: true, message: 'Añadido.', html: { accessList: '<ul>nuevo</ul>' } }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        is('la lista se repone por su id', esc.slots.accessList.innerHTML, '<ul>nuevo</ul>');
        is('el campo queda vacío', esc.campo.value, '');
        is('y con el foco en él', esc.campo.focused, 1);
        is('el aviso del intento anterior se borra', esc.aviso.textContent, '');
    }

    {
        // FASE 5 — Un `ok:false` CON `html` sí repone huecos, y este es el caso
        // que lo fija: el informe de una importación con filas malas. El mensaje
        // dice «5 filas quedaron fuera» y el informe dice cuáles; si el
        // repintado fuera solo del camino bueno, quien subiera un archivo con 5
        // cédulas mal tecleadas se quedaría con el número y sin las filas, y sin
        // ningún error que lo delatara. El orden importa: los huecos ANTES de
        // pintar el aviso, para que un aviso que revienta no se lleve el informe
        // por delante.
        const esc = escenario({
            attrs: { 'data-vf-async': 'slots', 'data-vf-error': 'rosterImportError' },
            aviso: 'rosterImportError',
            slots: ['rosterImportReport', 'rosterList'],
        });
        esc.setFetch(esc.defaultFetch({
            ok: false,
            message: 'El archivo no se pudo leer.',
            html: {
                rosterImportReport: '<div>Fila 3: sin cédula</div>',
                rosterList: '<ul>las 2 que sí entraron</ul>',
            },
        }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        is('el informe se repone con un ok:false', esc.slots.rosterImportReport.innerHTML, '<div>Fila 3: sin cédula</div>');
        is('y la lista también', esc.slots.rosterList.innerHTML, '<ul>las 2 que sí entraron</ul>');
        is('el motivo va a su campo', esc.aviso.textContent, 'El archivo no se pudo leer.');
        is('y no se reenvía nada', esc.form.submitted, 0);
    }

    console.log('El rescate: lo que no es JSON vuelve al POST nativo');
    {
        // CASO QUE IMPORTABA: la respuesta tunesca (200 con text/html) llega
        // con la página de error de un PHP caído o la de «no encontrada». El
        // navegador la habría enseñado; con fetch hay que devolver el envío al
        // navegador entero.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' } });
        esc.setFetch(esc.defaultFetch({ ok: true }, 'text/html; charset=UTF-8'));
        const { handle } = montar(esc);
        const ev = evento(esc.form);
        handle(ev);
        await flush();
        is('el evento se previó (el fetch ya lo había cogido)', ev.prevented, 1);
        is('se vuelve a enviar por el camino nativo', esc.form.submitted, 1);
        is('y no se pinta ningún error inventado', esc.toasts.children.length, 0);
    }
    {
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' } });
        esc.setFetch(() => Promise.reject(new Error('red')));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        is('sin red, envío nativo', esc.form.submitted, 1);
    }

    console.log('Un rechazo del servidor se PINTA, no se reenvía');
    {
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' } });
        esc.setFetch(esc.defaultFetch({ ok: false, error: 'No tienes permiso.' }, 'application/json'));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        is('no se reenvía el formulario', esc.form.submitted, 0);
        is('se pinta el motivo del servidor', esc.toasts.children.length, 1);
        is('con su texto', esc.toasts.children[0].innerHTML.indexOf('No tienes permiso.') > -1, true);
        is('y en rojo', esc.toasts.children[0].className.indexOf('text-bg-danger') > -1, true);
    }

    console.log('Un fallo al MOSTRAR el resultado no repite la operación');
    {
        // Lo que encontró este arnés: con `.then(...).catch(...)` el mismo
        // brazo recogía «se cayó la red» y «reventó el pintado», así que un
        // aviso que no se podía pintar acababa en `form.submit()` y la
        // operación se ejecutaba DOS veces.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' } });
        esc.setFetch(esc.defaultFetch({ ok: true, message: 'Guardado.' }));
        const { handle } = montar(esc);
        esc.window.vfToast = function () { throw new Error('no se puede pintar'); };
        handle(evento(esc.form));
        await flush();
        is('el formulario NO se reenvía', esc.form.submitted, 0);
        // Y el UI queda libre: `soltar()` va antes de pintar, o el botón se
        // quedaba apagado para siempre sin que se viera el motivo.
        is('el botón no se queda apagado', esc.boton.disabled, false);
        is('ni el formulario marcado como ocupado', esc.form.getAttribute('data-vf-busy'), null);
        is('el fallo quedó sin manejar (solo consola, sin tocar la BD)', rechazos, 1);
    }

    console.log('Los huecos se reponen');
    {
        const esc = escenario({
            attrs: { 'data-vf-async': 'slots' },
            slots: ['vfSlotStatusBadge', 'vfSlotActions'],
        });
        esc.setFetch(esc.defaultFetch({
            ok: true,
            message: 'Formulario cerrado.',
            html: { vfSlotStatusBadge: 'cerrado', vfSlotActions: 'reabrir' },
        }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        is('la insignia se repone', esc.slots.vfSlotStatusBadge.innerHTML, 'cerrado');
        is('la fila de acciones se repone', esc.slots.vfSlotActions.innerHTML, 'reabrir');
        is('se avisa del motivo del servidor', esc.toasts.children.length, 1);
        is('sin recargar', esc.window.location.reloaded, 0);
    }
    {
        // `redirect` es para lo que ya no existe en pantalla.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' } });
        esc.setFetch(esc.defaultFetch({ ok: true, message: 'Eliminado.', redirect: '/dashboard/forms' }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        is('va a la lista de formularios', esc.window.location.assigned, '/dashboard/forms');
    }

    console.log('Borrar una fila quita la fila y ajusta el contador');
    {
        const esc = escenario({
            attrs: { 'data-vf-async': 'remove' },
            row: true,
            contador: 12,
        });
        esc.setFetch(esc.defaultFetch({ ok: true, message: 'Respuesta eliminada.', count: 11 }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        // Se comprueba que la fila salió del árbol, NO que el form se quedara
        // sin padre: quitar la fila no borra el `parentNode` de sus hijos ni
        // en el DOM real, así que el `closest()` del código seguiría
        // encontrándola. Lo que tiene que ser verdad es que ya no cuelga.
        is('la fila sale del árbol', esc.fila.parent, null);
        is('y el código quitó la fila', esc.fila.removed, true);
        is('el contador baja a 11', esc.contador.textContent, '(11)');
    }
    {
        const esc = escenario({
            attrs: { 'data-vf-async': 'remove' },
            row: true,
            contador: 1,
        });
        esc.setFetch(esc.defaultFetch({ ok: true, count: 0 }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        is('la última respuesta que se borra recarga (la tabla vacía mintiendo)', esc.window.location.reloaded, 1);
    }

    console.log('El botón se apaga mientras dura el POST y vuelve después');
    {
        // El caso de #formUpdate: el botón vive FUERA del formulario (usa
        // `form="formUpdate"`), así que sin buscarlo por atributo esa acción se
        // quedaba habilitada y un segundo clic sacaba otro POST.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' }, botonInterno: 'formUpdate', id: 'formUpdate' });
        esc.setFetch(esc.defaultFetch({ ok: true }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        is('el botón se encontró fuera del form', esc.boton.disabled, false);
        is('y ya no queda en vuelo', esc.form.getAttribute('data-vf-busy'), null);
    }
    {
        // En vuelo de verdad: se mira DURANTE la promesa, antes de resolverse.
        let soltar;
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' } });
        esc.setFetch((url, init) => {
            esc.window.fetches.push({ url: url, init: init });
            return new Promise((res) => { soltar = res; });
        });
        const { handle } = montar(esc);
        handle(evento(esc.form));
        is('el botón se apaga mientras está en vuelo', esc.boton.disabled, true);
        is('y se marca para el lector de pantalla', esc.boton.getAttribute('aria-busy'), 'true');
        is('el formulario queda marcado como ocupado', esc.form.getAttribute('data-vf-busy'), '1');

        // Segundo clic mientras el primero sigue en vuelo: ni un fetch más.
        handle(evento(esc.form));
        is('un segundo clic NO saca otro POST', esc.window.fetches.length, 1);

        soltar({ ok: true, status: 200, headers: { get: () => 'application/json' }, json: () => Promise.resolve({ ok: true }) });
        await flush();
        is('al terminar, el botón vuelve', esc.boton.disabled, false);
        is('y el aviso de ocupado se quita', esc.form.getAttribute('data-vf-busy'), null);
    }
    {
        // El giro del markup: se quita el `d-none` y se vuelve a poner. Sin él
        // el guardado tardaba lo que tardara el servidor sin decir nada.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' }, spinner: true });
        let soltar;
        esc.setFetch(() => new Promise((res) => { soltar = res; }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        is('el giro se ve mientras guarda', esc.spinner.classList.contains('d-none'), false);
        soltar({ ok: true, status: 200, headers: { get: () => 'application/json' }, json: () => Promise.resolve({ ok: true, message: 'Guardado.' }) });
        await flush();
        is('el giro se esconde al terminar', esc.spinner.classList.contains('d-none'), true);
    }

    console.log('El <select> que se envía solo pasa por el delegado');

    {
        // F0.4-cuatro — El `select` de rol de `/admin` se envía solo al cambiarlo
        // (`.js-auto-submit`). Lo que lo hacía recargar la página entera era el
        // `form.submit()` de entonces: `submit()` NO dispara el evento `submit`,
        // así que el delegado no se enteraba, no preguntaba, no apagaba el
        // botón y no elegía el camino sin recarga.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots', action: '/admin/users/role' } });
        const select = el('select', { name: 'role_id' });
        select.parent = esc.form;
        esc.form.vfRequestSubmit = function () { esc.form.emit('submit'); };
        esc.setFetch(esc.defaultFetch({ ok: true, message: 'Rol actualizado.' }));

        montar(esc).autoSubmit(select);
        conDelegado(esc);
        select.emit('change');
        await flush();

        is('el cambio de rol no se va por el POST nativo', esc.form.submitted, 0);
        is('sino por fetch', esc.window.fetches.length, 1);
        is('y con el destino del formulario', esc.window.fetches[0].url, '/admin/users/role');
    }
    {
        // Sin `requestSubmit()` (navegador viejo) el `else` vuelve al `submit()`
        // de siempre: el POST nativo, y en ese caso recargar es lo único que
        // hay. Sin un caso que lo recorra, ese `else` deja de vigilarse.
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' } });
        esc.form.requestSubmit = undefined;
        const select = el('select', { name: 'role_id' });
        select.parent = esc.form;

        montar(esc).autoSubmit(select);
        conDelegado(esc);
        select.emit('change');
        await flush();

        is('sin requestSubmit el cambio se va por el POST nativo', esc.form.submitted, 1);
        is('y no por fetch', esc.window.fetches.length, 0);
    }

    console.log('El token CSRF sale del <body>');
    {
        const esc = escenario({ attrs: { 'data-vf-async': 'slots' } });
        esc.setFetch(esc.defaultFetch({ ok: true }));
        const { handle } = montar(esc);
        handle(evento(esc.form));
        await flush();
        const cabeceras = esc.window.fetches[0].init.headers;
        is('se manda X-Requested-With (es lo que hace bimodal al servidor)', cabeceras['X-Requested-With'], 'XMLHttpRequest');
        is('y el token del body', cabeceras['X-CSRF-Token'], 'token-de-prueba');
        is('el cuerpo va tal cual, sin Content-Type a mano', cabeceras['Content-Type'], undefined);
    }

    console.log('');
    console.log('Arranques: ' + total + ' | Fallidos: ' + failures);
    process.exit(failures === 0 ? 0 : 1);
}

/* El `unhandledRejection` de arriba se come también el rechazo de `main()`
 * (que es un fallo REAL del arnés), así que este se mira aparte: sin esto, un
 * `TypeError` a mitad de un escenario deja el fichero a medias y sale 0. */
main().catch((err) => {
    console.log('  ERROR ' + (err && err.stack ? err.stack : err));
    console.log('');
    console.log('Arranques: ' + total + ' | Fallidos: ' + (failures + 1));
    process.exit(1);
});
