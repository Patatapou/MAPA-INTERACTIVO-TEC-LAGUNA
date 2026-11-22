// ==========================================
// HUELLA DEL GRAFO DE CAMINOS
// ==========================================
// Script de solo lectura. Verifica que la red de caminos conserve las
// invariantes del grafo que usa Dijkstra. Las cifras se calculan del propio
// `caminitos.geojson`, así que no caducan cuando la red crece.
//
// Uso:
//   node tests/grafo-huella.mjs
//   node tests/grafo-huella.mjs --json
//   node tests/grafo-huella.mjs --geometria
//
// Comprueba:
//   - el grafo es conexo (una sola componente);
//   - las 6 puertas alcanzan todos los edificios (N/N);
//   - nodos, aristas, features y vértices son coherentes entre sí;
//   - con --geometria, cada segmento tiene >= 2 vértices y coordenadas finitas.
//
// Código de salida: 0 si las invariantes se cumplen; 1 si alguna falla.

import fs from 'node:fs';
import vm from 'node:vm';

const RAIZ = process.cwd();
const leer = (rel) => fs.readFileSync(`${RAIZ}/${rel}`, 'utf8');

// Cargar utilidades existentes en el contexto global (igual que el navegador).
vm.runInThisContext(leer('frontend/js/shared/geo-utils.js'));
vm.runInThisContext(leer('frontend/js/public/beacons-simulation.js'));
vm.runInThisContext(leer('frontend/js/shared/pathfinding.js'));

// GeoTec.js declara `var datosTec = {...};`
vm.runInThisContext(leer('frontend/data/GeoTec.js'));

const caminitos = JSON.parse(leer('frontend/data/caminitos.geojson'));
const datos = JSON.parse(leer('frontend/data/datos.json'));

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

// ---------- Invariantes (no dependen del tamaño de la red) ----------
const errores = [];

if (resultado.features < 1) {
    errores.push('la red no tiene segmentos');
}
if (resultado.vertices < resultado.features * 2) {
    errores.push(`vértices insuficientes: ${resultado.vertices} para ${resultado.features} segmentos`);
}
if (resultado.nodos < 1) {
    errores.push('el grafo no tiene nodos');
}
if (resultado.nodos > resultado.vertices) {
    errores.push(`nodos (${resultado.nodos}) > vértices (${resultado.vertices})`);
}
if (resultado.aristas < resultado.nodos - 1) {
    errores.push(`aristas insuficientes (${resultado.aristas}) para ${resultado.nodos} nodos`);
}
if (resultado.componentes !== 1) {
    errores.push(`componentes: esperado 1, obtenido ${resultado.componentes}`);
}
if (alcanzables !== totalPares) {
    errores.push(`rutas: esperado ${totalPares}/${totalPares}, obtenido ${alcanzables}/${totalPares}`);
}

const comprobarGeometria = process.argv.includes('--geometria');
if (comprobarGeometria) {
    for (const feature of caminitos.features || []) {
        const id = (feature.properties && feature.properties.id) || '(sin id)';
        const geom = feature.geometry;

        if (!geom || (geom.type !== 'LineString' && geom.type !== 'MultiLineString')) {
            errores.push(`${id}: geometría ausente o no lineal`);
            continue;
        }

        const partes = geom.type === 'LineString' ? [geom.coordinates] : geom.coordinates;
        if (!Array.isArray(partes) || partes.length === 0) {
            errores.push(`${id}: sin coordenadas`);
            continue;
        }

        for (const parte of partes) {
            if (!Array.isArray(parte) || parte.length < 2) {
                errores.push(`${id}: tramo con menos de 2 vértices`);
                continue;
            }
            for (const punto of parte) {
                if (!Array.isArray(punto) || punto.length < 2
                    || !Number.isFinite(punto[0]) || !Number.isFinite(punto[1])) {
                    errores.push(`${id}: coordenada inválida`);
                    break;
                }
            }
        }
    }
}

if (process.argv.includes('--json')) {
    console.log(JSON.stringify({
        ok: errores.length === 0,
        resultado,
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
        console.log('\nOK: la red cumple las invariantes del grafo (huella actual arriba).');
    } else {
        console.error('\nFALLO: alguna invariante de la red no se cumple.');
        for (const error of errores) console.error(`- ${error}`);
    }
}

process.exit(errores.length === 0 ? 0 : 1);
