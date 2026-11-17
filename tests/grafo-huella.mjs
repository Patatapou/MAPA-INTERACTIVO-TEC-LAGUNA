// ==========================================
// HUELLA DEL GRAFO DE CAMINOS
// ==========================================
// Script de solo lectura. Verifica que la red de caminos conserve las
// métricas del grafo que usa Dijkstra. Sirve como prueba de regresión antes
// y después de migrar `caminitos.geojson`.
//
// Uso:
//   node tests/grafo-huella.mjs
//   node tests/grafo-huella.mjs --json
//
// Salida esperada (línea base del proyecto):
//   nodos=238
//   aristas=309
//   grado1=19
//   componentes=1
//   rutas=348/348
//
// Código de salida: 0 si coincide con la línea base; 1 si difiere.

import fs from 'node:fs';
import vm from 'node:vm';

const RAIZ = process.cwd();
const leer = (rel) => fs.readFileSync(`${RAIZ}/${rel}`, 'utf8');

// Cargar utilidades existentes en el contexto global (igual que el navegador).
vm.runInThisContext(leer('js/beacons-simulation.js'));
vm.runInThisContext(leer('js/pathfinding.js'));

// GeoTec.js declara `var datosTec = {...};`
vm.runInThisContext(leer('GeoTec.js'));

const caminitos = JSON.parse(leer('caminitos.geojson'));
const datos = JSON.parse(leer('datos.json'));

const LINEA_BASE = {
    nodos: 238,
    aristas: 309,
    grado1: 19,
    componentes: 1,
    rutas: '348/348',
};

// Huella de la red ya normalizada (se comprueba solo con --geometria).
// 314/646 → 313/644: se descarta el micro-tramo original de 0,107 m, que no
// puede ser arista del grafo (sus dos extremos caen en el mismo nodo).
const LINEA_BASE_GEOMETRIA = {
    features: 313,
    vertices: 644,
};

// ---------- Métricas del grafo ----------
const graph = buildGraph(caminitos);
const nodos = Object.keys(graph);
const aristas = new Set();
let grado1 = 0;

for (const nodo of nodos) {
    const vecinos = Object.keys(graph[nodo] || {});
    if (vecinos.length === 1) grado1 += 1;
    for (const vecino of vecinos) {
        aristas.add(nodo < vecino ? `${nodo}|${vecino}` : `${vecino}|${nodo}`);
    }
}

// ---------- Componentes conexas ----------
const visitados = new Set();
let componentes = 0;
let mayorComponente = 0;

for (const inicio of nodos) {
    if (visitados.has(inicio)) continue;
    componentes += 1;
    let tamano = 0;
    const pila = [inicio];
    visitados.add(inicio);
    while (pila.length > 0) {
        const actual = pila.pop();
        tamano += 1;
        for (const vecino of Object.keys(graph[actual] || {})) {
            if (!visitados.has(vecino)) {
                visitados.add(vecino);
                pila.push(vecino);
            }
        }
    }
    if (tamano > mayorComponente) mayorComponente = tamano;
}

// ---------- Centro de una geometría (bbox) ----------
function recorrerCoordenadas(coords, visitar) {
    if (!Array.isArray(coords) || coords.length === 0) return;
    if (typeof coords[0] === 'number') {
        visitar(coords);
        return;
    }
    for (const parte of coords) recorrerCoordenadas(parte, visitar);
}

function centroDeFeature(feature) {
    if (!feature || !feature.geometry || !feature.geometry.coordinates) return null;
    let minLat = Infinity;
    let maxLat = -Infinity;
    let minLng = Infinity;
    let maxLng = -Infinity;
    recorrerCoordenadas(feature.geometry.coordinates, ([lng, lat]) => {
        if (lat < minLat) minLat = lat;
        if (lat > maxLat) maxLat = lat;
        if (lng < minLng) minLng = lng;
        if (lng > maxLng) maxLng = lng;
    });
    if (!Number.isFinite(minLat)) return null;
    return { lat: (minLat + maxLat) / 2, lng: (minLng + maxLng) / 2 };
}

// ---------- Alcanzabilidad puerta → edificio ----------
const features = (datosTec.features || []).filter((f) => f.properties && f.properties.id);

const centrosEdificios = [];
for (const feature of features) {
    const centro = centroDeFeature(feature);
    if (centro) {
        centrosEdificios.push({ id: String(feature.properties.id), ...centro });
    }
}

const puertasFallback = {
    P1: { lat: 25.534749, lng: -103.434492 },
    P2: { lat: 25.533321, lng: -103.434524 },
    P3: { lat: 25.531002, lng: -103.435153 },
    P4: { lat: 25.530236, lng: -103.435404 },
    P5: { lat: 25.5294985, lng: -103.435666 },
    P6: { lat: 25.535214, lng: -103.436398 },
};

const puertas = Object.keys(puertasFallback).map((id) => {
    const centro = centrosEdificios.find((e) => e.id === id);
    return centro ? { id, lat: centro.lat, lng: centro.lng } : { id, ...puertasFallback[id] };
});

let alcanzables = 0;
const totalPares = puertas.length * centrosEdificios.length;

for (const puerta of puertas) {
    const inicio = findClosestNode(puerta.lat, puerta.lng, graph);
    if (!inicio) continue;
    for (const edificio of centrosEdificios) {
        const fin = findClosestNode(edificio.lat, edificio.lng, graph);
        if (!fin) continue;
        const camino = dijkstra(graph, inicio, fin);
        if (camino && camino.length > 0) alcanzables += 1;
    }
}

// ---------- Tamaño del archivo ----------
let featuresRed = 0;
let verticesRed = 0;
for (const feature of caminitos.features || []) {
    const geom = feature.geometry;
    if (!geom) continue;
    if (geom.type === 'LineString') {
        featuresRed += 1;
        verticesRed += geom.coordinates.length;
    } else if (geom.type === 'MultiLineString') {
        featuresRed += geom.coordinates.length;
        for (const parte of geom.coordinates) verticesRed += parte.length;
    }
}

const resultado = {
    nodos: nodos.length,
    aristas: aristas.size,
    grado1,
    componentes,
    mayorComponente,
    rutas: `${alcanzables}/${totalPares}`,
    vertices: verticesRed,
    features: featuresRed,
    // Metadatos de apoyo (no forman parte de la comparación estricta)
    datosEdificios: Object.keys(datos).length,
    featuresGeoTec: features.length,
};

// ---------- Comparación con la línea base ----------
const errores = [];
for (const clave of ['nodos', 'aristas', 'grado1', 'componentes', 'rutas']) {
    if (resultado[clave] !== LINEA_BASE[clave]) {
        errores.push(`${clave}: esperado ${LINEA_BASE[clave]}, obtenido ${resultado[clave]}`);
    }
}

const comprobarGeometria = process.argv.includes('--geometria');
if (comprobarGeometria) {
    for (const clave of ['features', 'vertices']) {
        if (resultado[clave] !== LINEA_BASE_GEOMETRIA[clave]) {
            errores.push(`${clave}: esperado ${LINEA_BASE_GEOMETRIA[clave]}, obtenido ${resultado[clave]}`);
        }
    }
}

if (process.argv.includes('--json')) {
    console.log(JSON.stringify({
        ok: errores.length === 0,
        resultado,
        esperado: LINEA_BASE,
        esperadoGeometria: LINEA_BASE_GEOMETRIA,
        comprobarGeometria,
        errores,
    }, null, 2));
} else {
    console.log(`nodos=${resultado.nodos}`);
    console.log(`aristas=${resultado.aristas}`);
    console.log(`grado1=${resultado.grado1}`);
    console.log(`componentes=${resultado.componentes}`);
    console.log(`rutas=${resultado.rutas}`);
    console.log(`features=${resultado.features}`);
    console.log(`vertices=${resultado.vertices}`);

    if (errores.length === 0) {
        console.log('\nOK: la huella del grafo coincide con la línea base.');
    } else {
        console.error('\nFALLO: la huella del grafo cambió.');
        for (const error of errores) console.error(`- ${error}`);
    }
}

process.exit(errores.length === 0 ? 0 : 1);
