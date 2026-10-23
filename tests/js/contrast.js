/**
 * Paridad del CONTRASTE automático entre PHP (`App\Core\Contrast`) y JS
 * (`textoAuto()` de `public/assets/js/builder.js`).
 *
 * El editor elige un color de degradado y, si deja el texto en automático, el
 * constructor (`builder.js`) y el formulario publicado (`Contrast`) tienen que
 * decidir lo MISMO. Si uno calcula blanco y el otro tinta oscura, el mismo
 * formulario se ve de dos maneras según la pantalla y no falla nada.
 *
 * Este arnés no compara contra PHP (no hay forma de invocarlo desde node sin
 * montar la app): corre la tabla de colores conocidos por la función REAL
 * extraída del JS. La MISMA tabla está en
 * `tests/unit/ContrastParityTest.php`, que la pasa por `Contrast::inkFor()`.
 * Cambiar el cálculo en un solo lado pone uno de los dos rojo.
 *
 * Se ejecuta desde `tests/unit/ContrastParityTest.php`, que hace SKIP si este
 * entorno no tiene `node`.
 *
 * Uso: node tests/js/contrast.js
 */

'use strict';

const fs = require('fs');
const path = require('path');

const BUILDER_JS = path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'builder.js');
const source = fs.readFileSync(BUILDER_JS, 'utf8');

/** Cuerpo completo de una función declarada en el JS del repo. */
function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    if (start < 0) {
        throw new Error('builder.js ya no tiene ' + name + '(): el arnés está obsoleto');
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

const textoAuto = new Function(extract('textoAuto') + '\nreturn textoAuto;')();

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

/*
 * Tabla compartida con tests/unit/ContrastParityTest.php. Si cambia aquí, cambia
 * allí (y al revés). El corte es la luminancia WCAG < 0.5 → blanco, si no tinta
 * oscura. Un valor ilegible cae a blanco en los dos lados.
 */
const TABLA = [
    ['#000000', '#ffffff'],
    ['#ffffff', '#1e293b'],
    ['#ff0000', '#ffffff'],
    ['#00ff00', '#1e293b'],
    ['#ffff00', '#1e293b'],
    ['#fff59d', '#1e293b'],
    ['#123456', '#ffffff'],
    ['#777777', '#ffffff'],
    ['#eeeeee', '#1e293b'],
    ['#EEE', '#1e293b'],
    ['#FFFFFF', '#1e293b'],
    ['', '#ffffff'],
    ['rojo', '#ffffff'],
    ['#12345', '#ffffff'],
    ['#11223344', '#ffffff'],
];

console.log('Contraste automático (misma tabla que ContrastParityTest)');
for (const [color, esperado] of TABLA) {
    is(JSON.stringify(color), textoAuto(color), esperado);
}

console.log('El arnés extrae la función real del builder');
is('textoAuto() usa la luminancia WCAG', /0\.2126/.test(extract('textoAuto')), true);

console.log('');
console.log('Casos: ' + total + ' | Fallidos: ' + failures);
process.exit(failures === 0 ? 0 : 1);
