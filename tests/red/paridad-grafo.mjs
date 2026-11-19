// ==========================================
// PARIDAD JS ↔ PHP DEL GRAFO DE CAMINOS
// ==========================================
// Compara `buildGraph()` (js/pathfinding.js) con `NetworkGraph::construir()`
// (backend/services/NetworkGraph.php) sobre:
//   - la red real (`caminitos.geojson`), y
//   - 200 redes aleatorias deterministas que ejercitan snapping, vértices
//     compartidos y segmentos casi conectados.
//
// Uso:
//   node tests/red/paridad-grafo.mjs
//   node tests/red/paridad-grafo.mjs --rapido   # 25 redes aleatorias
//
// Código de salida: 0 si hay paridad total; 1 si difiere.

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { spawnSync } from 'node:child_process';

const RAIZ = process.cwd();
const leer = (rel) => fs.readFileSync(path.join(RAIZ, rel), 'utf8');

// Mismo orden que el navegador: utilidades → pathfinding.
vm.runInThisContext(leer('frontend/js/shared/geo-utils.js'));
vm.runInThisContext(leer('frontend/js/shared/pathfinding.js'));

const rapido = process.argv.includes('--rapido');
const CANTIDAD_ALEATORIAS = rapido ? 25 : 200;

// ---------- Huella del grafo en JS ----------

function huellaJs(geojson) {
    const grafo = buildGraph(geojson);
    const nodos = Object.keys(grafo).sort();

    const aristas = [];
    const distancias = {};
    for (const nodo of nodos) {
        for (const vecino of Object.keys(grafo[nodo] || {})) {
            const clave = nodo < vecino ? `${nodo}|${vecino}` : `${vecino}|${nodo}`;
            if (!(clave in distancias)) {
                aristas.push(clave);
                distancias[clave] = Number(grafo[nodo][vecino].toFixed(6));
            }
        }
    }
    aristas.sort();

    let grado1 = 0;
    for (const nodo of nodos) {
        if (Object.keys(grafo[nodo] || {}).length === 1) grado1 += 1;
    }

    // Componentes (DFS iterativo).
    const visitados = new Set();
    let componentes = 0;
    for (const inicio of nodos) {
        if (visitados.has(inicio)) continue;
        componentes += 1;
        const pila = [inicio];
        visitados.add(inicio);
        while (pila.length > 0) {
            const actual = pila.pop();
            for (const vecino of Object.keys(grafo[actual] || {})) {
                if (!visitados.has(vecino)) {
                    visitados.add(vecino);
                    pila.push(vecino);
                }
            }
        }
    }

    return {
        nodos: nodos.length,
        aristas: aristas.length,
        grado1,
        componentes,
        listaNodos: nodos,
        listaAristas: aristas,
        distancias,
    };
}

// ---------- Generador determinista ----------

function generadorLineal(semilla) {
    let estado = semilla >>> 0;
    return () => {
        estado = (Math.imul(estado, 1664525) + 1013904223) >>> 0;
        return estado / 4294967296;
    };
}

function redAleatoria(semilla) {
    const azar = generadorLineal(semilla);
    const centroLat = 25.5324;
    const centroLng = -103.436;
    const extension = 0.005;

    const punto = () => [
        Number((centroLng + (azar() - 0.5) * extension).toFixed(6)),
        Number((centroLat + (azar() - 0.5) * extension).toFixed(6)),
    ];

    const base = Array.from({ length: 12 + Math.floor(azar() * 20) }, punto);
    const features = [];
    const total = 3 + Math.floor(azar() * 30);

    for (let i = 0; i < total; i++) {
        const a = base[Math.floor(azar() * base.length)];
        let b = base[Math.floor(azar() * base.length)];

        // A veces b está a menos de 2,5 m de a (ejercita el snapping).
        if (azar() < 0.1) {
            b = [
                Number((a[0] + (azar() - 0.5) * 0.00003).toFixed(6)),
                Number((a[1] + (azar() - 0.5) * 0.00003).toFixed(6)),
            ];
        }

        const coords = [a, b];

        // Vértices intermedios para probar la división de segmentos.
        if (azar() < 0.5) {
            const medio = [
                Number(((a[0] + b[0]) / 2 + (azar() - 0.5) * 0.00004).toFixed(6)),
                Number(((a[1] + b[1]) / 2 + (azar() - 0.5) * 0.00004).toFixed(6)),
            ];
            coords.splice(1, 0, medio);
        }

        // A veces el segmento es aislado (no comparte extremos).
        if (azar() < 0.08) {
            coords[0] = punto();
            coords[coords.length - 1] = punto();
        }

        features.push({
            type: 'Feature',
            properties: { id: `seg-${String(i + 1).padStart(6, '0')}` },
            geometry: { type: 'LineString', coordinates: coords },
        });
    }

    return {
        type: 'FeatureCollection',
        name: 'prueba-paridad',
        features,
    };
}

// ---------- Comparación ----------

function comparar(indice, js, php) {
    const errores = [];

    for (const clave of ['nodos', 'aristas', 'grado1', 'componentes']) {
        if (js[clave] !== php[clave]) {
            errores.push(`${clave}: JS=${js[clave]} PHP=${php[clave]}`);
        }
    }

    if (JSON.stringify(js.listaNodos) !== JSON.stringify(php.listaNodos)) {
        const faltan = js.listaNodos.filter((n) => !php.listaNodos.includes(n)).slice(0, 3);
        const sobran = php.listaNodos.filter((n) => !js.listaNodos.includes(n)).slice(0, 3);
        errores.push(`nodos distintos (faltan en PHP: ${faltan.join(' / ') || '-'}; sobran: ${sobran.join(' / ') || '-'})`);
    }

    if (JSON.stringify(js.listaAristas) !== JSON.stringify(php.listaAristas)) {
        const faltan = js.listaAristas.filter((a) => !php.listaAristas.includes(a)).slice(0, 3);
        const sobran = php.listaAristas.filter((a) => !js.listaAristas.includes(a)).slice(0, 3);
        errores.push(`aristas distintas (faltan en PHP: ${faltan.join(' / ') || '-'}; sobran: ${sobran.join(' / ') || '-'})`);
    }

    for (const [clave, distancia] of Object.entries(js.distancias)) {
        if (!(clave in php.distancias)) continue;
        if (Math.abs(distancia - php.distancias[clave]) > 1e-6) {
            errores.push(`distancia distinta en ${clave}: JS=${distancia} PHP=${php.distancias[clave]}`);
            break;
        }
    }

    return { ok: errores.length === 0, errores, indice };
}

// ---------- Ejecución ----------

const redReal = JSON.parse(leer('frontend/data/caminitos.geojson'));
const redes = [redReal];
for (let i = 0; i < CANTIDAD_ALEATORIAS; i++) {
    redes.push(redAleatoria(0x9e3779b9 + i * 2654435761));
}

console.log(`Redes a comparar: ${redes.length} (1 real + ${CANTIDAD_ALEATORIAS} aleatorias)`);

const huellasJs = redes.map(huellaJs);

const proceso = spawnSync('php', [path.join('tests', 'red', '_grafo-php.php')], {
    cwd: RAIZ,
    input: JSON.stringify({ redes }),
    encoding: 'utf8',
    maxBuffer: 256 * 1024 * 1024,
});

if (proceso.error) {
    console.error('No se pudo ejecutar PHP:', proceso.error.message);
    process.exit(1);
}
if (proceso.status !== 0) {
    console.error('PHP terminó con error:', proceso.stderr || proceso.stdout);
    process.exit(1);
}

let respuesta;
try {
    respuesta = JSON.parse(proceso.stdout);
} catch (err) {
    console.error('Respuesta de PHP no válida:', proceso.stdout.slice(0, 500));
    process.exit(1);
}

const huellasPhp = respuesta.grafo || [];
const fallos = [];

for (let i = 0; i < redes.length; i++) {
    const resultado = comparar(i, huellasJs[i], huellasPhp[i] || {});
    if (!resultado.ok) {
        fallos.push(resultado);
    }
}

if (fallos.length === 0) {
    console.log(`\nOK: paridad JS/PHP en ${redes.length} redes.`);
    console.log(`Red real: nodos=${huellasJs[0].nodos} aristas=${huellasJs[0].aristas} grado1=${huellasJs[0].grado1} componentes=${huellasJs[0].componentes}`);
    process.exit(0);
}

console.error(`\nFALLO: ${fallos.length} de ${redes.length} redes no coinciden.`);
for (const fallo of fallos.slice(0, 5)) {
    console.error(`\nRed #${fallo.indice}:`);
    for (const error of fallo.errores) {
        console.error(' - ' + error);
    }
}
process.exit(1);
