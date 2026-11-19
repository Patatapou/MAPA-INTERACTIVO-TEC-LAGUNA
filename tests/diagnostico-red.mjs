/**
 * Diagnóstico de la red: puntos donde una cadena de extremos puede quedarse
 * atascada (huecos mayores que la tolerancia y uniones en T).
 *
 * Script de solo lectura. No recibe argumentos.
 *
 * Uso:
 *   node tests/diagnostico-red.mjs
 *
 * Salida esperada (línea base con tolerancia 2,5 m, red revision=2):
 *   Extremos sin pareja por extremo: 21
 *   Extremos "atascados": 0
 *   Extremos realmente aislados: 21
 *
 * La red es editable: si cambia de forma deliberada, actualiza la línea base.
 *
 * Código de salida: siempre 0 (es un informe, no una prueba con umbral).
 */

import fs from 'node:fs';
import path from 'node:path';

const RAIZ = path.resolve(import.meta.dirname, '..');
const geojson = JSON.parse(fs.readFileSync(path.join(RAIZ, 'frontend', 'data', 'caminitos.geojson'), 'utf8'));

const distanciaMetros = (lat1, lng1, lat2, lng2) => {
    const radio = 6371000;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLng = ((lng2 - lng1) * Math.PI) / 180;
    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos((lat1 * Math.PI) / 180) * Math.cos((lat2 * Math.PI) / 180) * Math.sin(dLng / 2) ** 2;
    return 2 * radio * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
};

const distanciaPuntoSegmento = (p, a, b) => {
    const latRef = (p[1] * Math.PI) / 180;
    const kx = 111320 * Math.cos(latRef);
    const ky = 110540;
    const px = p[0] * kx;
    const py = p[1] * ky;
    const ax = a[0] * kx;
    const ay = a[1] * ky;
    const bx = b[0] * kx;
    const by = b[1] * ky;
    const dx = bx - ax;
    const dy = by - ay;
    const len2 = dx * dx + dy * dy;
    if (len2 === 0) return Math.hypot(px - ax, py - ay);
    const t = Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / len2));
    return Math.hypot(px - (ax + t * dx), py - (ay + t * dy));
};

const segmentos = [];
for (const f of geojson.features) {
    const id = f.properties?.id;
    const coords = f.geometry?.coordinates;
    if (!id || !Array.isArray(coords) || coords.length < 2) continue;
    segmentos.push({ id, coords, inicio: coords[0], fin: coords[coords.length - 1] });
}

const TOL = 2.5;

// Extremos de cada segmento, con referencia para excluir el propio segmento.
const extremos = [];
for (const s of segmentos) {
    extremos.push({ id: s.id, punto: s.inicio, extremo: 'inicio' });
    extremos.push({ id: s.id, punto: s.fin, extremo: 'fin' });
}

function distanciaAOtroExtremo(e) {
    let mejor = Infinity;
    for (const o of extremos) {
        if (o.id === e.id) continue;
        const d = distanciaMetros(e.punto[1], e.punto[0], o.punto[1], o.punto[0]);
        if (d < mejor) mejor = d;
    }
    return mejor;
}

function interiorMasCercano(e) {
    let mejor = Infinity;
    let quien = null;
    for (const s of segmentos) {
        if (s.id === e.id) continue;
        let d = Infinity;
        for (let k = 1; k < s.coords.length; k += 1) {
            const dd = distanciaPuntoSegmento(e.punto, s.coords[k - 1], s.coords[k]);
            if (dd < d) d = dd;
        }
        if (d < mejor) {
            mejor = d;
            quien = s.id;
        }
    }
    return { d: mejor, id: quien };
}

const huerfanos = [];
for (const e of extremos) {
    const dExt = distanciaAOtroExtremo(e);
    if (dExt <= TOL) continue; // conectado por extremo
    const interior = interiorMasCercano(e);
    const conectadoAlInterior = interior.d <= TOL;
    huerfanos.push({
        extremo: `${e.id}:${e.extremo}`,
        punto: e.punto,
        dExtremo: Number(dExt.toFixed(2)),
        dInterior: Number(interior.d.toFixed(2)),
        interior: interior.id,
        conectadoAlInterior,
    });
}

console.log(`Tolerancia de análisis: ${TOL} m`);
console.log(`Extremos sin pareja por extremo: ${huerfanos.length}`);
console.log('');
console.log('--- Extremos "atascados" (sin extremo cercano, con interior cercano) ---');
const t = huerfanos.filter((h) => h.conectadoAlInterior);
console.log(`Total: ${t.length}`);
for (const h of t) {
    console.log(
        `  ${h.extremo}  interior de ${h.interior} a ${h.dInterior} m  (extremo más cercano a ${h.dExtremo} m)`,
    );
}
console.log('');
console.log('--- Extremos realmente aislados (>2,5 m de todo) ---');
const aislados = huerfanos.filter((h) => !h.conectadoAlInterior);
console.log(`Total: ${aislados.length}`);
for (const h of aislados.slice(0, 25)) {
    console.log(`  ${h.extremo}  extremo ${h.dExtremo} m / interior ${h.dInterior} m`);
}
