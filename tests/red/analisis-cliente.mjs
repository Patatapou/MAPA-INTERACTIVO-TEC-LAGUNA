// ==========================================
// PRUEBA DEL ANÁLISIS TOPOLÓGICO EN EL CLIENTE
// ==========================================
// Carga `js/geo-utils.js`, `js/pathfinding.js` y `js/network-analysis.js`
// sobre la red real y comprueba:
//   1. La huella del grafo, calculada del propio archivo (no fija).
//   2. La coincidencia entre el análisis, el grafo y el archivo.
//   3. Un caso sintético por cada error E01–E05 y E08.
//   4. Un caso sintético por cada advertencia W01, W04, W05 y W06.
//
// Uso: node tests/red/analisis-cliente.mjs
// No necesita dependencias externas.

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const aquí = path.dirname(fileURLToPath(import.meta.url));
const raíz = path.resolve(aquí, '..', '..');

const contexto = { console };
contexto.globalThis = contexto;
vm.createContext(contexto);

for (const archivo of ['frontend/js/shared/geo-utils.js', 'frontend/js/shared/pathfinding.js', 'frontend/js/admin/network-analysis.js', 'frontend/data/GeoTec.js']) {
    const ruta = path.join(raíz, archivo);
    vm.runInContext(fs.readFileSync(ruta, 'utf8'), contexto, { filename: archivo });
}

const red = JSON.parse(fs.readFileSync(path.join(raíz, 'frontend/data/caminitos.geojson'), 'utf8'));

let fallos = 0;
let pruebas = 0;

function comprobar(descripcion, condicion, detalle) {
    pruebas++;
    if (condicion) {
        console.log('  ok  ' + descripcion);
        return;
    }
    fallos++;
    console.log('  FALLA  ' + descripcion + (detalle ? ' → ' + detalle : ''));
}

function codigos(lista) {
    return (lista || []).map((item) => item.codigo).sort();
}

function geojson(features) {
    return { type: 'FeatureCollection', features };
}

function feature(id, coords) {
    return {
        type: 'Feature',
        properties: { id },
        geometry: { type: 'LineString', coordinates: coords },
    };
}

// ---------- 1. Huella real (calculada del archivo) ----------
const analisis = contexto.redAnalizarRed(red, { redVigente: red, pares: [] });
const grafo = contexto.redGrafo(red);
const nodosGrafo = Object.keys(grafo || {});
const aristasGrafo = new Set();
let grado1Grafo = 0;

for (const nodo of nodosGrafo) {
    const vecinos = Object.keys(grafo[nodo] || {});
    if (vecinos.length === 1) grado1Grafo += 1;
    for (const vecino of vecinos) {
        aristasGrafo.add(nodo < vecino ? `${nodo}|${vecino}` : `${vecino}|${nodo}`);
    }
}

console.log('\n[1] Huella de la red real');
comprobar('el análisis no produce errores', analisis.errores.length === 0,
    JSON.stringify(analisis.errores));
comprobar('los nodos coinciden con el grafo', analisis.metricas.nodos === nodosGrafo.length,
    `${analisis.metricas.nodos} frente a ${nodosGrafo.length}`);
comprobar('las aristas coinciden con el grafo', analisis.metricas.aristas === aristasGrafo.size,
    `${analisis.metricas.aristas} frente a ${aristasGrafo.size}`);
comprobar('los extremos de grado 1 coinciden con el grafo',
    analisis.metricas.grado1 === grado1Grafo,
    `${analisis.metricas.grado1} frente a ${grado1Grafo}`);
comprobar('1 componente', analisis.metricas.componentes === 1, String(analisis.metricas.componentes));
comprobar('el texto de la leyenda usa el mismo grafo', contexto.redGrafo(red) !== null);

// ---------- 2. Cifras de referencia del archivo ----------
console.log('\n[2] Cifras del contrato');
const featuresRed = red.features.length;
const verticesRed = red.features.reduce((n, f) => n + f.geometry.coordinates.length, 0);
comprobar('la red tiene segmentos', featuresRed > 0, String(featuresRed));
comprobar('cada segmento tiene al menos dos vértices', verticesRed >= featuresRed * 2,
    `features=${featuresRed}, vertices=${verticesRed}`);
comprobar('todos los IDs tienen formato definitivo',
    red.features.every((f) => /^seg-(?:[a-f0-9]{8}(?:-\d{2})?|\d{6})$/.test(f.properties.id)));

// ---------- 3. Errores E01–E05 ----------
console.log('\n[3] Errores estructurales');
const base = [
    [-103.4359, 25.5301],
    [-103.4358, 25.5302],
];

comprobar('E01 tipo raíz incorrecto',
    codigos(contexto.redErroresEstructura({ type: 'Feature', features: [] })).includes('E01'));
comprobar('E02 geometría que no es LineString',
    codigos(contexto.redErroresEstructura(geojson([
        { type: 'Feature', properties: { id: 'tmp-1' }, geometry: { type: 'Point', coordinates: base[0] } },
    ]), { recuadro: contexto.redRecuadro(red) })).includes('E02'));
comprobar('E03 con un solo vértice',
    codigos(contexto.redErroresEstructura(geojson([
        feature('tmp-1', [[-103.4359, 25.5301]]),
    ]), { recuadro: contexto.redRecuadro(red) })).includes('E03'));
comprobar('E04 fuera del recuadro',
    codigos(contexto.redErroresEstructura(geojson([
        feature('tmp-1', [[-103.0, 25.0], [-103.0, 25.001]]),
    ]), { recuadro: contexto.redRecuadro(red) })).includes('E04'));
comprobar('E05 ID inválido',
    codigos(contexto.redErroresEstructura(geojson([
        feature('segmento-1', base),
    ]), { recuadro: contexto.redRecuadro(red) })).includes('E05'));
comprobar('E05 ID duplicado',
    codigos(contexto.redErroresEstructura(geojson([
        feature('tmp-1', base),
        feature('tmp-1', base),
    ]), { recuadro: contexto.redRecuadro(red) })).includes('E05'));
comprobar('E07 por exceso de segmentos',
    codigos(contexto.redErroresEstructura(geojson([
        feature('tmp-1', base),
    ]), { recuadro: contexto.redRecuadro(red), maxSegmentos: 0 })).includes('E07'));

// ---------- 4. E08 sintético ----------
console.log('\n[4] Regresión de alcanzabilidad (E08)');
// Cadena A—B—C—D—E: el par A→E usa toda la cadena. Si se elimina B—C,
// la cadena se parte en dos y E deja de ser alcanzable (B y C siguen
// existiendo como extremos, que es lo que hace visible la regresión).
const a = [-103.4360, 25.5300];
const b = [-103.4355, 25.5300];
const c = [-103.4350, 25.5300];
const d = [-103.4345, 25.5300];
const e = [-103.4340, 25.5300];
const vigente = geojson([
    feature('seg-000001', [a, b]),
    feature('seg-000002', [b, c]),
    feature('seg-000003', [c, d]),
    feature('seg-000004', [d, e]),
]);
const pares = [{ id: 'P1→1', origen: { lat: a[1], lng: a[0] }, destino: { lat: e[1], lng: e[0] } }];

comprobar('con la red completa no hay E08',
    codigos(contexto.redAnalizarRed(vigente, { redVigente: vigente, pares }).errores).indexOf('E08') === -1);

const rota = geojson([
    feature('seg-000001', [a, b]),
    feature('seg-000003', [c, d]),
    feature('seg-000004', [d, e]),
]);
const conE08 = contexto.redAnalizarRed(rota, { redVigente: vigente, pares });
comprobar('al eliminar el segmento puente aparece E08', codigos(conE08.errores).includes('E08'));
comprobar('E08 informa del par perdido',
    (conE08.errores.find((er) => er.codigo === 'E08') || {}).pares?.[0] === 'P1→1');
comprobar('E08 bloquea el guardado', conE08.ok === false);

// ---------- 5. Advertencias sintéticas ----------
console.log('\n[5] Advertencias');
const aislado = geojson([feature('tmp-1', [a, b])]);
const conW01 = contexto.redAnalizarRed(aislado, { recuadro: contexto.redRecuadro(vigente) });
comprobar('W01 no salta con una sola componente', !codigos(conW01.advertencias).includes('W01'));

const dosIslas = geojson([
    feature('tmp-1', [a, b]),
    feature('tmp-2', [[-103.4340, 25.5310], [-103.4339, 25.5311]]),
]);
const conW01b = contexto.redAnalizarRed(dosIslas, { recuadro: contexto.redRecuadro(vigente) });
comprobar('W01 detecta dos componentes', codigos(conW01b.advertencias).includes('W01'));

const corto = geojson([feature('tmp-1', [[-103.4360, 25.5300], [-103.4360001, 25.5300001]])]);
comprobar('W04 detecta un segmento de menos de 2,5 m',
    codigos(contexto.redAnalizarRed(corto, { recuadro: contexto.redRecuadro(vigente) }).advertencias).includes('W04'));

const duplicado = geojson([feature('tmp-1', [a, a, b])]);
comprobar('W06 detecta vértices consecutivos duplicados',
    codigos(contexto.redAnalizarRed(duplicado, { recuadro: contexto.redRecuadro(vigente) }).advertencias).includes('W06'));

const solapado = geojson([
    feature('tmp-1', [a, b]),
    feature('tmp-2', [a, b]),
]);
comprobar('W05 detecta segmentos idénticos',
    codigos(contexto.redAnalizarRed(solapado, { recuadro: contexto.redRecuadro(vigente) }).advertencias).includes('W05'));

// ---------- 6. Snapping ----------
console.log('\n[6] Ajuste a la red');
const cercaDeVertice = contexto.redAjustarPuntoARed(25.530001, -103.435999, vigente.features, 4);
comprobar('el snapping devuelve un vértice existente',
    cercaDeVertice && cercaDeVertice.tipo === 'vertice', JSON.stringify(cercaDeVertice));
const medio = contexto.redAjustarPuntoARed(25.53, -103.43575, vigente.features, 4);
comprobar('el snapping devuelve un punto sobre el segmento',
    medio && medio.tipo === 'segmento', JSON.stringify(medio));
const fueraDelRadio = contexto.redAjustarPuntoARed(25.53006, -103.4360, vigente.features);
comprobar('el snapping predeterminado no alcanza puntos a más de 4 m',
    fueraDelRadio === null, JSON.stringify(fueraDelRadio));

// ---------- 7. Rendimiento con todos los pares puerta → edificio ----------
console.log('\n[7] Análisis completo con pares');
const nodos = Object.keys(contexto.redGrafo(red));
const edificios = (contexto.datosTec.features || [])
    .filter((f) => f.properties && f.properties.id).length;
const paresEsperados = 6 * edificios;
const paresReales = [];
for (let i = 0; i < paresEsperados; i++) {
    const origen = contexto.redPuntoDeClave(nodos[i % 6]);
    const destino = contexto.redPuntoDeClave(nodos[(i * 7 + 6) % nodos.length]);
    paresReales.push({ id: 'P' + i + '→' + i, origen, destino });
}
const inicio = Date.now();
const completo = contexto.redAnalizarRed(red, { redVigente: red, pares: paresReales });
const ms = Date.now() - inicio;
comprobar(`${paresEsperados} pares sin errores (la red actual conserva la alcanzabilidad)`,
    completo.errores.length === 0, JSON.stringify(completo.errores));
comprobar('el análisis completo tarda menos de 2 s', ms < 2000, ms + ' ms');
comprobar('se informa la alcanzabilidad', completo.alcanzabilidad !== null
    && completo.alcanzabilidad.alcanzables.length === paresEsperados,
    JSON.stringify(completo.alcanzabilidad && completo.alcanzabilidad.alcanzables.length));
console.log('       tiempo: ' + ms + ' ms');

// ---------- Resultado ----------
console.log('\n' + (fallos === 0
    ? 'OK: ' + pruebas + ' comprobaciones correctas.'
    : 'FALLOS: ' + fallos + ' de ' + pruebas + ' comprobaciones.') + '\n');

process.exit(fallos === 0 ? 0 : 1);
