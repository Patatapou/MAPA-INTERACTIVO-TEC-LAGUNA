// ==========================================
// VERIFICACIÓN COMPLETA DEL PROYECTO
// ==========================================
// Comando único, sin dependencias, que ejecuta:
//   1. `php -l` sobre todo el PHP del repositorio.
//   2. La batería de pruebas PHP (sin PHPUnit).
//   3. La batería de pruebas Node (red, cadenas y sitio publicable).
//
// Uso:
//   node scripts/verificar.mjs            # batería completa
//   node scripts/verificar.mjs --rapido   # paridad de grafo con 25 redes
//
// Código de salida: 0 solo si todo pasa. No escribe en `database/runtime/`:
// las pruebas usan directorios temporales.

import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const aquí = path.dirname(fileURLToPath(import.meta.url));
const raíz = path.resolve(aquí, '..');
const rápido = process.argv.includes('--rapido');

const resultados = [];

function registrar(nombre, ok, detalle = '', ms = 0) {
    resultados.push({ nombre, ok, detalle, ms });
    const marca = ok ? 'OK   ' : 'FALLO';
    const tiempo = ms > 0 ? ` (${ms} ms)` : '';
    console.log(`${marca} ${nombre}${tiempo}`);
    if (!ok && detalle) {
        for (const línea of String(detalle).trim().split(/\r?\n/).slice(-25)) {
            console.log('      ' + línea);
        }
    }
}

function ejecutar(nombre, comando, argumentos) {
    const inicio = Date.now();
    const resultado = spawnSync(comando, argumentos, {
        cwd: raíz,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    const ms = Date.now() - inicio;

    if (resultado.error) {
        registrar(nombre, false, resultado.error.message, ms);
        return false;
    }

    const salida = `${resultado.stdout || ''}${resultado.stderr || ''}`;
    const ok = resultado.status === 0;
    registrar(nombre, ok, ok ? '' : salida || `código ${resultado.status}`, ms);
    return ok;
}

function listarPhp(relativa) {
    const absoluta = path.join(raíz, relativa);
    if (!fs.existsSync(absoluta)) return [];

    const salida = [];
    const pila = [absoluta];

    while (pila.length > 0) {
        const actual = pila.pop();
        const entrada = fs.statSync(actual);
        if (entrada.isFile()) {
            if (actual.endsWith('.php')) salida.push(actual);
            continue;
        }
        for (const hijo of fs.readdirSync(actual)) {
            pila.push(path.join(actual, hijo));
        }
    }

    return salida.sort();
}

console.log('=== Verificación del proyecto ===\n');

// ---------- Entorno ----------
const php = spawnSync('php', ['-v'], { cwd: raíz, encoding: 'utf8' });
if (php.error || php.status !== 0) {
    registrar('PHP disponible', false, php.error ? php.error.message : `${php.stdout}${php.stderr}`);
    console.log('\nNo se puede ejecutar `php`. Instala PHP 8.4 o añádelo al PATH.');
    process.exit(1);
}
registrar('PHP disponible', true, '', 0);

registrar('Node disponible', Number(process.versions.node.split('.')[0]) >= 18,
    `versión ${process.versions.node}`, 0);

// ---------- Carpetas de datos ----------
// `database/runtime/` y `database/backups/` están en .gitignore: un clon
// limpio no las tiene y `bootstrap.php` falla a propósito. Las creamos vacías
// (sin datos reales) para poder ejecutar la batería; la instalación real las
// siembra con `scripts/instalar.php` (Fase 4).
const carpetasDatos = ['database/runtime', 'database/backups'];
const carpetasCreadas = [];

for (const carpeta of carpetasDatos) {
    const absoluta = path.join(raíz, carpeta);
    if (!fs.existsSync(absoluta)) {
        fs.mkdirSync(absoluta, { recursive: true });
        carpetasCreadas.push(carpeta);
    }
}

if (carpetasCreadas.length > 0) {
    console.log(`INFO  carpetas de datos creadas vacías: ${carpetasCreadas.join(', ')}`);
}
registrar('carpetas de datos disponibles', true, '', 0);

// ---------- php -l ----------
const archivosPhp = [
    ...listarPhp('backend'),
    ...listarPhp('frontend'),
    ...listarPhp('scripts'),
    ...listarPhp('tests'),
    ...(fs.existsSync(path.join(raíz, 'router.php')) ? [path.join(raíz, 'router.php')] : []),
];

const erroresSintaxis = [];
for (const archivo of archivosPhp) {
    const resultado = spawnSync('php', ['-l', archivo], { cwd: raíz, encoding: 'utf8' });
    if (resultado.status !== 0) {
        erroresSintaxis.push(path.relative(raíz, archivo));
    }
}
registrar(`php -l (${archivosPhp.length} archivos)`, erroresSintaxis.length === 0,
    erroresSintaxis.join(', '));

// ---------- Batería PHP ----------
const pruebasPhp = [
    'tests/rutas-repository.php',
    'tests/rutas-service.php',
    'tests/red/integridad-red.php',
    'tests/red/validacion-red.php',
    'tests/usuarios-repository.php',
    'tests/usuarios-service.php',
];

for (const prueba of pruebasPhp) {
    ejecutar(`php ${prueba}`, 'php', [prueba]);
}

// ---------- Batería Node ----------
const pruebasNode = [
    { archivo: 'tests/grafo-huella.mjs', args: ['--geometria'] },
    { archivo: 'tests/validar-cadenas.mjs', args: ['--php'] },
    { archivo: 'tests/diagnostico-red.mjs', args: [] },
    { archivo: 'tests/red/paridad-grafo.mjs', args: rápido ? ['--rapido'] : [] },
    { archivo: 'tests/red/analisis-cliente.mjs', args: [] },
    { archivo: 'tests/dom-seguro.mjs', args: [] },
    { archivo: 'tests/publicacion.mjs', args: [] },
];

for (const prueba of pruebasNode) {
    ejecutar(`node ${prueba.archivo}${prueba.args.length ? ' ' + prueba.args.join(' ') : ''}`,
        process.execPath, [prueba.archivo, ...prueba.args]);
}

// Este caso levanta repositorios Git temporales y puede consumir CPU/disco.
// Se ejecuta al final para no alterar el umbral temporal de los análisis de red.
ejecutar('php tests/map-publisher.php', 'php', ['tests/map-publisher.php']);

// ---------- Resumen ----------
const fallos = resultados.filter((r) => !r.ok);
const total = resultados.length;

console.log(`\n${fallos.length === 0
    ? `OK: ${total} comprobaciones correctas.`
    : `FALLOS: ${fallos.length} de ${total} comprobaciones.`}\n`);

if (fallos.length > 0) {
    for (const fallo of fallos) console.log(`  - ${fallo.nombre}`);
    process.exit(1);
}
