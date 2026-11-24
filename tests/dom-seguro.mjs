// ==========================================
// PRUEBA DEL HELPER DE DOM SEGURO
// ==========================================
// Verifica `frontend/js/shared/dom-seguro.js`: escapado de HTML, escapado de
// metacaracteres para RegExp y normalización de rutas de imagen.
//
// Uso:
//   node tests/dom-seguro.mjs
//
// Código de salida: 0 si todo pasa; 1 si algo falla.

import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const DomSeguro = require('../frontend/js/shared/dom-seguro.js');

let pruebas = 0;
let fallos = 0;

function comprobar(descripcion, condicion, detalle = '') {
    pruebas += 1;
    if (condicion) {
        console.log('  ok    ' + descripcion);
        return;
    }
    fallos += 1;
    console.log('  FALLA ' + descripcion + (detalle ? ' → ' + detalle : ''));
}

console.log('\n[1] escaparHtml');
comprobar('escapa & < > " \'',
    DomSeguro.escaparHtml(`<img src="x" onerror='alert(1)'> &`)
        === '&lt;img src=&quot;x&quot; onerror=&#39;alert(1)&#39;&gt; &amp;');
comprobar('convierte null en cadena vacía', DomSeguro.escaparHtml(null) === '');
comprobar('convierte undefined en cadena vacía', DomSeguro.escaparHtml(undefined) === '');
comprobar('convierte números a texto', DomSeguro.escaparHtml(18) === '18');

const payloads = [
    '<img src=x onerror=alert(1)>',
    '</script><script>alert(1)</script>',
    '<svg onload=alert(1)>'
];
for (const payload of payloads) {
    const escapado = DomSeguro.escaparHtml(payload);
    comprobar(`neutraliza el payload ${payload}`,
        !/[<>"']/.test(escapado) && escapado.includes('&lt;'), escapado);
}

console.log('\n[2] escaparRegex');
const meta = 'a.b*c+d?e^f$g{h}i(j)k|l[m]n\\o';
const escapado = DomSeguro.escaparRegex(meta);
comprobar('escapa los metacaracteres', escapado !== meta && escapado.includes('\\.'));
let coincide = false;
try {
    coincide = new RegExp('^' + escapado + '$').test(meta);
} catch (error) {
    coincide = false;
}
comprobar('el patrón escapado coincide de forma literal', coincide, escapado);

console.log('\n[3] normalizarRutaImagen');
comprobar('acepta img/<id>/<archivo>', DomSeguro.normalizarRutaImagen('img/18/2.jpeg', '18') === 'img/18/2.jpeg');
comprobar('quita la barra inicial', DomSeguro.normalizarRutaImagen('/img/18/2.jpeg', '18') === 'img/18/2.jpeg');
comprobar('rechaza otra carpeta', DomSeguro.normalizarRutaImagen('img/19/2.jpeg', '18') === null);
comprobar('rechaza path traversal', DomSeguro.normalizarRutaImagen('img/18/../secreto.png', '18') === null);
comprobar('rechaza barra invertida', DomSeguro.normalizarRutaImagen('img\\18\\2.jpeg', '18') === null);
comprobar('rechaza URL absoluta', DomSeguro.normalizarRutaImagen('https://ejemplo.com/img/18/2.jpeg', '18') === null);
comprobar('rechaza archivo vacío', DomSeguro.normalizarRutaImagen('img/18/', '18') === null);
comprobar('rechaza query string', DomSeguro.normalizarRutaImagen('img/18/2.jpeg?v=1', '18') === null);
comprobar('rechaza valores no textuales', DomSeguro.normalizarRutaImagen(18, '18') === null);
comprobar('acepta id numérico', DomSeguro.normalizarRutaImagen('img/18/2.jpeg', 18) === 'img/18/2.jpeg');
comprobar('rechaza id distinto', DomSeguro.normalizarRutaImagen('img/18/2.jpeg', '19') === null);

console.log('\n' + (fallos === 0
    ? `OK: ${pruebas} comprobaciones correctas.`
    : `FALLOS: ${fallos} de ${pruebas} comprobaciones.`) + '\n');

process.exit(fallos === 0 ? 0 : 1);
