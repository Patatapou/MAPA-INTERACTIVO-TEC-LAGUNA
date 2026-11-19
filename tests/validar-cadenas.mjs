#!/usr/bin/env node
/**
 * Pruebas de validación de cadenas de segmentos (plan, 16.3).
 *
 * El cliente (`js/ruta-editor.js`) solo produce `[{id, sentido}]`; la
 * autoridad es el servidor (`PathNetworkService::validarCadena`). Este script
 * reproduce esa misma lógica en JavaScript y comprueba los 9 casos del plan
 * sobre la red real (`caminitos.geojson`).
 *
 * Uso:
 *   node tests/validar-cadenas.mjs           # validación en JavaScript
 *   node tests/validar-cadenas.mjs --php     # además compara con el servidor
 *   node tests/validar-cadenas.mjs --json    # salida legible por máquina
 *
 * Salida: código 0 si todos los casos coinciden con lo esperado.
 */

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const RAIZ = path.resolve(import.meta.dirname, '..');

const opciones = {
    php: process.argv.includes('--php'),
    json: process.argv.includes('--json'),
};

const TOLERANCIA_M = 2.5;
const MAX_SEGMENTOS = 500;

// ---------- Utilidades geométricas (equivalentes a PathNetworkService) ----------

function claveNodo(lat, lng) {
    return Number(lat).toFixed(6) + ',' + Number(lng).toFixed(6);
}

function distanciaMetros(lat1, lng1, lat2, lng2) {
    const radio = 6371000;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLng = ((lng2 - lng1) * Math.PI) / 180;
    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos((lat1 * Math.PI) / 180) *
            Math.cos((lat2 * Math.PI) / 180) *
            Math.sin(dLng / 2) ** 2;
    return 2 * radio * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function longitudPolilinea(coords) {
    let total = 0;
    for (let i = 1; i < coords.length; i += 1) {
        total += distanciaMetros(
            coords[i - 1][1],
            coords[i - 1][0],
            coords[i][1],
            coords[i][0],
        );
    }
    return total;
}

function mismaPosicion(claveA, claveB) {
    if (!claveA || !claveB) return false;
    const a = claveA.split(',').map(Number);
    const b = claveB.split(',').map(Number);
    return distanciaMetros(a[0], a[1], b[0], b[1]) <= TOLERANCIA_M;
}

// ---------- Índice de la red ----------

function indiceRed() {
    const ruta = path.join(RAIZ, 'frontend', 'data', 'caminitos.geojson');
    const geojson = JSON.parse(fs.readFileSync(ruta, 'utf8'));
    const indice = new Map();

    for (const feature of geojson.features || []) {
        const geometria = feature.geometry || {};
        if (geometria.type !== 'LineString') continue;
        const id = feature.properties?.id ?? feature.id;
        if (!id) continue;
        const coords = geometria.coordinates;
        if (!Array.isArray(coords) || coords.length < 2) continue;
        indice.set(id, {
            id,
            coords,
            inicio: claveNodo(coords[0][1], coords[0][0]),
            fin: claveNodo(coords[coords.length - 1][1], coords[coords.length - 1][0]),
            longitud_m: longitudPolilinea(coords),
        });
    }

    return indice;
}

// ---------- Validación (espejo de PathNetworkService::validarCadena) ----------

function validarCadena(cadena, indice, tolerancia = TOLERANCIA_M, maxSegmentos = MAX_SEGMENTOS) {
    const errores = [];

    if (cadena.length === 0) {
        return { ok: false, errores: ['La ruta no tiene segmentos.'] };
    }

    if (cadena.length > maxSegmentos) {
        errores.push('La ruta supera el máximo de ' + maxSegmentos + ' segmentos.');
    }

    const vistos = new Set();
    let extremoActual = null;
    let distancia = 0;

    cadena.forEach((ref, posicion) => {
        const numero = posicion + 1;
        const id = ref && typeof ref === 'object' ? ref.id : null;
        const sentido = ref && typeof ref === 'object' ? ref.sentido ?? 'normal' : null;

        if (typeof id !== 'string' || id === '') {
            errores.push('Segmento ' + numero + ': falta el id.');
            return;
        }
        if (sentido !== 'normal' && sentido !== 'inverso') {
            errores.push('Segmento ' + id + ': sentido inválido (use normal o inverso).');
            return;
        }
        if (vistos.has(id)) {
            errores.push('Segmento duplicado en la ruta: ' + id + '.');
            return;
        }
        vistos.add(id);

        const segmento = indice.get(id);
        if (!segmento) {
            errores.push('Segmento inexistente: ' + id + '.');
            return;
        }

        const inicio = sentido === 'normal' ? segmento.inicio : segmento.fin;
        const fin = sentido === 'normal' ? segmento.fin : segmento.inicio;

        if (extremoActual !== null) {
            const [aLat, aLng] = extremoActual.split(',').map(Number);
            const [bLat, bLng] = inicio.split(',').map(Number);
            const salto = distanciaMetros(aLat, aLng, bLat, bLng);
            if (salto > tolerancia) {
                errores.push(
                    'Cadena discontinua antes del segmento ' +
                        id +
                        ': separación de ' +
                        salto.toFixed(2) +
                        ' m (máximo ' +
                        tolerancia.toFixed(2) +
                        ' m).',
                );
            }
        }

        extremoActual = fin;
        distancia += segmento.longitud_m;
    });

    return { ok: errores.length === 0, errores, distancia_m: Math.round(distancia * 10) / 10 };
}

// ---------- Construcción de fixtures ----------

function buscarParejaContigua(indice, modo) {
    const valores = [...indice.values()];
    for (const a of valores) {
        for (const b of valores) {
            if (a.id === b.id) continue;
            if (modo === 'normal') {
                // a.fin debe coincidir con b.inicio para encadenar en sentido normal.
                if (mismaPosicion(a.fin, b.inicio)) return { a, b, sentidoB: 'normal' };
            } else if (modo === 'inverso') {
                // a.fin debe coincidir con b.fin → b entra en sentido inverso.
                if (mismaPosicion(a.fin, b.fin) && !mismaPosicion(a.fin, b.inicio)) {
                    return { a, b, sentidoB: 'inverso' };
                }
            } else if (modo === 'discontinua') {
                if (mismaPosicion(a.fin, b.inicio)) continue;
                const [aLat, aLng] = a.fin.split(',').map(Number);
                const [bLat, bLng] = b.inicio.split(',').map(Number);
                const salto = distanciaMetros(aLat, aLng, bLat, bLng);
                if (salto > TOLERANCIA_M && salto < 10) return { a, b, salto };
            }
        }
    }
    return null;
}

function construirCasos(indice) {
    const parejaNormal = buscarParejaContigua(indice, 'normal');
    const parejaInversa = buscarParejaContigua(indice, 'inverso');
    const parejaDiscontinua = buscarParejaContigua(indice, 'discontinua');

    if (!parejaNormal) throw new Error('La red no contiene dos segmentos contiguos.');
    if (!parejaInversa) throw new Error('La red no contiene un par encadenable en sentido inverso.');

    const primero = parejaNormal.a;
    const segundo = parejaNormal.b;

    // 501 referencias: los IDs disponibles más repeticiones, para superar el máximo.
    const ids = [...indice.keys()];
    const exceso = [];
    for (let i = 0; i < MAX_SEGMENTOS + 1; i += 1) {
        exceso.push({ id: ids[i % ids.length], sentido: 'normal' });
    }

    return [
        {
            caso: 'Válida',
            descripcion: '2 segmentos contiguos',
            esperado: true,
            esperadoError: null,
            cadena: [
                { id: primero.id, sentido: 'normal' },
                { id: segundo.id, sentido: parejaNormal.sentidoB },
            ],
        },
        {
            caso: 'Un segmento',
            descripcion: '1 segmento',
            esperado: true,
            esperadoError: null,
            cadena: [{ id: primero.id, sentido: 'normal' }],
        },
        {
            caso: 'Inversa',
            descripcion: 'segmento con sentido inverso',
            esperado: true,
            esperadoError: null,
            cadena: [
                { id: parejaInversa.a.id, sentido: 'normal' },
                { id: parejaInversa.b.id, sentido: 'inverso' },
            ],
        },
        {
            caso: 'Discontinua',
            descripcion: parejaDiscontinua
                ? 'salto real de ' + parejaDiscontinua.salto.toFixed(2) + ' m'
                : 'salto > tolerancia',
            esperado: false,
            esperadoError: 'Cadena discontinua',
            cadena: parejaDiscontinua
                ? [
                      { id: parejaDiscontinua.a.id, sentido: 'normal' },
                      { id: parejaDiscontinua.b.id, sentido: 'normal' },
                  ]
                : [
                      { id: primero.id, sentido: 'normal' },
                      { id: [...indice.keys()].at(-1), sentido: 'normal' },
                  ],
        },
        {
            caso: 'Duplicada',
            descripcion: 'mismo ID dos veces',
            esperado: false,
            esperadoError: 'duplicado',
            cadena: [
                { id: primero.id, sentido: 'normal' },
                { id: primero.id, sentido: 'normal' },
            ],
        },
        {
            caso: 'Inexistente',
            descripcion: 'ID inventado',
            esperado: false,
            esperadoError: 'inexistente',
            cadena: [{ id: 'seg-00000000', sentido: 'normal' }],
        },
        {
            caso: 'Vacía',
            descripcion: '[]',
            esperado: false,
            esperadoError: 'no tiene segmentos',
            cadena: [],
        },
        {
            caso: 'Exceso',
            descripcion: MAX_SEGMENTOS + 1 + ' segmentos',
            esperado: false,
            esperadoError: 'supera el máximo',
            cadena: exceso,
        },
        {
            caso: 'Sentido inválido',
            descripcion: '"diagonal"',
            esperado: false,
            esperadoError: 'sentido inválido',
            cadena: [{ id: primero.id, sentido: 'diagonal' }],
        },
    ];
}

// ---------- Comparación con el servidor ----------

function validarConPhp(casos) {
    const archivo = path.join(os.tmpdir(), 'validar-cadenas-' + process.pid + '.json');
    fs.writeFileSync(
        archivo,
        JSON.stringify(casos.map((c) => ({ caso: c.caso, cadena: c.cadena }))),
        'utf8',
    );
    try {
        const salida = execFileSync(
            'php',
            [path.join(RAIZ, 'tests', '_validar-cadenas.php'), archivo],
            { encoding: 'utf8' },
        );
        return JSON.parse(salida);
    } finally {
        fs.unlinkSync(archivo);
    }
}

// ---------- Ejecución ----------

function main() {
    const indice = indiceRed();
    if (indice.size === 0) throw new Error('No se pudo indexar la red de caminos.');

    const casos = construirCasos(indice);
    const resultados = casos.map((caso) => {
        const salida = validarCadena(caso.cadena, indice);
        const errorEsperado = caso.esperadoError
            ? salida.errores.some((e) => e.toLowerCase().includes(caso.esperadoError.toLowerCase()))
            : salida.errores.length === 0;
        return {
            caso: caso.caso,
            descripcion: caso.descripcion,
            esperado: caso.esperado,
            obtenido: salida.ok,
            errores: salida.errores,
            correcto: salida.ok === caso.esperado && errorEsperado,
        };
    });

    let paridadPhp = null;
    if (opciones.php) {
        const php = validarConPhp(casos);
        paridadPhp = resultados.map((resultado, i) => ({
            caso: resultado.caso,
            js: resultado.obtenido,
            php: Boolean(php[i]?.ok),
            coincide: Boolean(php[i]?.ok) === resultado.obtenido,
        }));
    }

    const fallos = resultados.filter((r) => !r.correcto);
    const desacuerdos = paridadPhp ? paridadPhp.filter((p) => !p.coincide) : [];

    if (opciones.json) {
        console.log(
            JSON.stringify(
                { resultados, paridadPhp, fallos: fallos.length, desacuerdos: desacuerdos.length },
                null,
                2,
            ),
        );
    } else {
        console.log('=== Validación de cadenas (apartado 16.3) ===');
        console.log(`Red: ${indice.size} segmentos\n`);
        for (const r of resultados) {
            const marca = r.correcto ? 'OK   ' : 'FALLO';
            console.log(
                `${marca} ${r.caso.padEnd(16)} esperado ${String(r.esperado).padEnd(5)} obtenido ${String(
                    r.obtenido,
                ).padEnd(5)} | ${r.descripcion}`,
            );
            // En el caso "Exceso" el validador acumula un error por segmento:
            // se muestran solo los primeros para no inundar la salida.
            const mostrados = r.errores.slice(0, 3);
            for (const error of mostrados) console.log('        · ' + error);
            if (r.errores.length > mostrados.length) {
                console.log(`        · … y ${r.errores.length - mostrados.length} error(es) más`);
            }
        }
        console.log('');

        if (paridadPhp) {
            console.log('=== Paridad con el servidor (PathNetworkService) ===');
            for (const p of paridadPhp) {
                console.log(
                    `${p.coincide ? 'OK   ' : 'FALLO'} ${p.caso.padEnd(16)} js=${String(p.js).padEnd(5)} php=${p.php}`,
                );
            }
            console.log('');
        }
    }

    if (fallos.length > 0 || desacuerdos.length > 0) {
        console.error(
            `Hay ${fallos.length} caso(s) fuera de lo esperado y ${desacuerdos.length} desacuerdo(s) con el servidor.`,
        );
        process.exit(1);
    }

    console.log('OK: los 9 casos de validación de cadenas coinciden con lo esperado.');
}

main();
