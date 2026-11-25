// ==========================================
// PRUEBA DE SITIO PUBLICABLE
// ==========================================
// Verifica que `frontend/` pueda publicarse como sitio estático en GitHub
// Pages sin filtrar archivos privados y sin depender de PHP.
//
// Uso:
//   node tests/publicacion.mjs
//
// Código de salida: 0 si el sitio es publicable; 1 si algo falla.

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const aquí = path.dirname(fileURLToPath(import.meta.url));
const raíz = path.resolve(aquí, '..');
const frontend = path.join(raíz, 'frontend');

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

function listarArchivos(omitirDir = []) {
    const salida = [];
    const pila = [frontend];

    while (pila.length > 0) {
        const actual = pila.pop();
        for (const entrada of fs.readdirSync(actual, { withFileTypes: true })) {
            const absoluta = path.join(actual, entrada.name);
            const relativa = path.relative(frontend, absoluta).split(path.sep).join('/');

            if (entrada.isDirectory()) {
                if (!omitirDir.includes(relativa)) pila.push(absoluta);
            } else if (entrada.isFile()) {
                salida.push(relativa);
            }
        }
    }

    return salida.sort();
}

function leer(relativa) {
    return fs.readFileSync(path.join(frontend, relativa), 'utf8');
}

function esExterno(valor) {
    return /^(?:[a-z][a-z0-9+.-]*:|\/\/|#)/i.test(valor);
}

function normalizar(valor) {
    return valor.split(/[?#]/)[0];
}

function existeArchivo(relativa) {
    const ruta = path.join(frontend, relativa);
    return fs.existsSync(ruta) && fs.statSync(ruta).isFile();
}

const camposPrivados = /(?:creado_por|actualizado_por|password|audit|csrf|session)/i;

function contieneCampoPrivado(valor) {
    if (Array.isArray(valor)) {
        return valor.some(contieneCampoPrivado);
    }
    if (!valor || typeof valor !== 'object') {
        return false;
    }
    return Object.entries(valor).some(([clave, contenido]) =>
        camposPrivados.test(clave) || contieneCampoPrivado(contenido)
    );
}

console.log('\n[1] Archivos que se publican');
const archivosSinImagenes = listarArchivos(['img', 'tiles']);
const php = archivosSinImagenes.filter((f) => f.endsWith('.php'));
comprobar('el único .php es index.php', php.every((f) => f === 'index.php'), php.join(', '));

// ---------- 2. Nada privado ----------
const todos = listarArchivos();
const privados = todos.filter((f) => {
    const base = f.split('/').pop() || '';
    return /\.bak(?:$|[-.])/i.test(base)
        || /\.lock$/i.test(base)
        || /\.tmp/i.test(base)
        || /\.log$/i.test(base)
        || base === '.env'
        || base === 'users.json'
        || /\.local\.php$/i.test(base);
});
comprobar('sin respaldos, bloqueos, temporales, logs ni datos privados', privados.length === 0,
    privados.slice(0, 8).join(', '));

// ---------- 3. Recursos referenciados ----------
const html = leer('index.html');
const referencias = [];

for (const coincidencia of html.matchAll(/(?:src|href)\s*=\s*["']([^"']+)["']/gi)) {
    referencias.push({ origen: 'index.html', valor: coincidencia[1] });
}

const jsPublicos = todos.filter((f) => /^js\/(?:public|shared)\//.test(f));
for (const archivo of jsPublicos) {
    const contenido = leer(archivo);
    for (const coincidencia of contenido.matchAll(/fetch\s*\(\s*['"`]([^'"`]+)['"`]/g)) {
        referencias.push({ origen: archivo, valor: coincidencia[1] });
    }
}

const inexistentes = [];
for (const referencia of referencias) {
    if (esExterno(referencia.valor)) continue;
    const relativa = normalizar(referencia.valor).replace(/^\/+/, '');
    if (relativa === '' || !existeArchivo(relativa)) {
        inexistentes.push(`${referencia.origen}: ${referencia.valor}`);
    }
}
comprobar('todos los recursos relativos existen', inexistentes.length === 0,
    inexistentes.slice(0, 8).join(', '));

// ---------- 4. Sin rutas absolutas ----------
const absolutas = [];
for (const referencia of referencias) {
    if (referencia.valor.startsWith('/') && !referencia.valor.startsWith('//')) {
        absolutas.push(`${referencia.origen}: ${referencia.valor}`);
    }
}

const hojas = todos.filter((f) => /\.css$/i.test(f) && f.startsWith('css/'));
for (const archivo of hojas) {
    for (const coincidencia of leer(archivo).matchAll(/url\(\s*['"]?([^'")]+)['"]?\s*\)/gi)) {
        const valor = coincidencia[1].trim();
        if (valor.startsWith('/') && !valor.startsWith('//')) {
            absolutas.push(`${archivo}: ${valor}`);
        }
    }
}

for (const archivo of jsPublicos) {
    const contenido = leer(archivo);
    const patron = /(?:fetch|src|href)\s*[(=:]\s*['"`](\/[^/][^'"`]*)['"`]/g;
    for (const coincidencia of contenido.matchAll(patron)) {
        absolutas.push(`${archivo}: ${coincidencia[1]}`);
    }
}
comprobar('sin rutas absolutas en HTML, CSS ni JS públicos', absolutas.length === 0,
    absolutas.slice(0, 8).join(', '));

// ---------- 5. Sin referencias a index.php ----------
const conPhp = [];
for (const archivo of ['index.html', ...jsPublicos]) {
    if (leer(archivo).includes('index.php')) conPhp.push(archivo);
}
comprobar('el sitio público no menciona index.php', conPhp.length === 0, conPhp.join(', '));

// ---------- 6. Datos válidos ----------
let datos = null;
let caminitos = null;
let rutasPublicas = null;
let geotec = null;

try {
    const contexto = { window: {} };
    vm.runInNewContext(leer('data/location-config.js'), contexto, {
        filename: 'location-config.js',
        timeout: 1000,
    });
    comprobar('location-config.js define un modo válido',
        contexto.window.MODO_UBICACION === 'real' || contexto.window.MODO_UBICACION === 'simulada');
    if (contexto.window.CONFIG_MAPA !== undefined) {
        comprobar('location-config.js contiene configuración visual saneada',
            contexto.window.CONFIG_MAPA && contexto.window.CONFIG_MAPA.schema_version === 1
            && contexto.window.CONFIG_MAPA.estilos && contexto.window.CONFIG_MAPA.rutas);
    }
} catch (error) {
    comprobar('location-config.js define un modo válido', false, error.message);
}

try {
    rutasPublicas = JSON.parse(leer('data/rutas-publicas.json'));
    comprobar('rutas-publicas.json tiene el esquema estático esperado',
        rutasPublicas && rutasPublicas.schema_version === 1 && Array.isArray(rutasPublicas.rutas));
    comprobar('rutas-publicas.json no expone campos privados',
        !contieneCampoPrivado(rutasPublicas));
    comprobar('el detector encuentra campos privados anidados',
        contieneCampoPrivado({ ruta: { creado_por: 'admin' } }));
} catch (error) {
    comprobar('rutas-publicas.json tiene el esquema estático esperado', false, error.message);
    comprobar('rutas-publicas.json no expone campos privados', false, error.message);
    comprobar('el detector encuentra campos privados anidados', false, error.message);
}

try {
    datos = JSON.parse(leer('data/datos.json'));
    comprobar('datos.json es JSON válido', typeof datos === 'object' && datos !== null);
} catch (error) {
    comprobar('datos.json es JSON válido', false, error.message);
}

try {
    caminitos = JSON.parse(leer('data/caminitos.geojson'));
    comprobar('caminitos.geojson es una FeatureCollection',
        caminitos && caminitos.type === 'FeatureCollection' && Array.isArray(caminitos.features));
} catch (error) {
    comprobar('caminitos.geojson es una FeatureCollection', false, error.message);
}

try {
    const contexto = {};
    vm.runInNewContext(leer('data/GeoTec.js'), contexto, { filename: 'GeoTec.js', timeout: 10000 });
    geotec = contexto.datosTec;
    comprobar('GeoTec.js expone datosTec con features',
        geotec && Array.isArray(geotec.features) && geotec.features.length > 0);
} catch (error) {
    comprobar('GeoTec.js expone datosTec con features', false, error.message);
}

// ---------- 7. IDs coincidentes ----------
if (datos && geotec && Array.isArray(geotec.features)) {
    const clavesDatos = Object.keys(datos).sort();
    const idsGeotec = [...new Set(geotec.features
        .map((f) => (f.properties && f.properties.id != null ? String(f.properties.id) : null))
        .filter((id) => id !== null))].sort();

    const faltanEnGeotec = clavesDatos.filter((id) => !idsGeotec.includes(id));
    const faltanEnDatos = idsGeotec.filter((id) => !clavesDatos.includes(id));

    comprobar('los IDs de datos.json y GeoTec.js coinciden uno a uno',
        faltanEnGeotec.length === 0 && faltanEnDatos.length === 0,
        `sin geometría: ${faltanEnGeotec.join(', ') || '—'}; sin metadatos: ${faltanEnDatos.join(', ') || '—'}`);
}

// ---------- 8. Imágenes existentes y rutas relativas ----------
if (datos) {
    const imagenesAusentes = [];
    const imagenesInvalidas = [];
    let imagenes = 0;

    for (const [id, metadatos] of Object.entries(datos)) {
        const lista = metadatos && Array.isArray(metadatos.imagenes) ? metadatos.imagenes : [];
        for (const valor of lista) {
            if (typeof valor !== 'string' || valor.trim() === '') {
                imagenesInvalidas.push(`${id}: valor vacío o no textual`);
                continue;
            }

            imagenes += 1;
            const ruta = valor.trim();

            // El sitio puede publicarse en un subdirectorio de GitHub Pages:
            // una barra inicial apuntaría a la raíz del dominio y rompería la
            // imagen. Es un error, no algo que se tolere.
            if (ruta.startsWith('/')) {
                imagenesInvalidas.push(`${id}: ${valor} (empieza por /)`);
                continue;
            }

            const idSeguro = id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const patron = new RegExp(`^img/${idSeguro}/[A-Za-z0-9][A-Za-z0-9._-]*$`);
            if (!patron.test(ruta)) {
                imagenesInvalidas.push(`${id}: ${valor} (no cumple img/<id>/<archivo>)`);
                continue;
            }

            if (!existeArchivo(ruta)) imagenesAusentes.push(`${id}: ${valor}`);
        }
    }

    comprobar(`las ${imagenes} imágenes de datos.json existen`,
        imagenesAusentes.length === 0, imagenesAusentes.slice(0, 8).join(', '));
    comprobar('las rutas de imágenes son relativas y válidas (img/<id>/<archivo>)',
        imagenesInvalidas.length === 0, imagenesInvalidas.slice(0, 8).join(', '));
}

// ---------- 9. Enlace de sugerencias (Google Forms) ----------
const configJs = leer('js/public/config.js');
const coincidenciaUrl = /SUGGESTION_FORM_URL\s*=\s*'([^']*)'/.exec(configJs);
const urlSugerencias = coincidenciaUrl ? coincidenciaUrl[1] : '';

comprobar('SUGGESTION_FORM_URL apunta a un Google Forms público',
    /^https:\/\/docs\.google\.com\/forms\//.test(urlSugerencias), urlSugerencias);

comprobar('SUGGESTION_FORM_URL no incluye parámetros de edición ni secretos',
    !/[?&](?:edit|edit2|token|key|apikey|api_key|secret|password|access_token)=/i.test(urlSugerencias)
    && !/\/(?:edit|copy)(?:[?#]|$)/i.test(urlSugerencias), urlSugerencias);

const etiquetaSugerencias = /<a\b[^>]*id="admin-access-btn"[^>]*>/i.exec(html);
comprobar('el enlace de sugerencias abre en otra pestaña sin opener',
    Boolean(etiquetaSugerencias)
    && /target="_blank"/.test(etiquetaSugerencias[0])
    && /rel="noopener noreferrer"/.test(etiquetaSugerencias[0]),
    etiquetaSugerencias ? etiquetaSugerencias[0] : 'no encontrado');

// ---------- 10. CSP, sin CDN y sin inline peligroso ----------
const csp = /<meta[^>]+http-equiv="Content-Security-Policy"[^>]+content="([^"]+)"/i.exec(html);
comprobar('index.html define una Content-Security-Policy', Boolean(csp));
if (csp) {
    comprobar("la CSP restringe scripts a 'self'", /script-src 'self'/.test(csp[1]), csp[1]);
    comprobar("la CSP limita conexiones a 'self'", /connect-src 'self'/.test(csp[1]), csp[1]);
    comprobar("la CSP bloquea formularios (form-action 'none')", /form-action 'none'/.test(csp[1]), csp[1]);
}

comprobar('Leaflet se sirve desde el repositorio, sin CDN',
    !/(?:unpkg\.com|cdn\.jsdelivr|cdnjs\.cloudflare)/i.test(html));

const inlinePeligroso = [];
for (const coincidencia of html.matchAll(/<[^>]+\s(on[a-z]+|style)\s*=/gi)) {
    inlinePeligroso.push(coincidencia[0].slice(0, 80));
}
comprobar('index.html no usa manejadores inline ni atributos style=',
    inlinePeligroso.length === 0, inlinePeligroso.join(', '));

const jsPublico = jsPublicos.map((archivo) => leer(archivo)).join('\n');
comprobar('el JS público no usa onclick= ni style= en cadenas HTML',
    !/\bonclick\s*=/.test(jsPublico) && !/\sstyle\s*=\s*["']/.test(jsPublico));

console.log('\n' + (fallos === 0
    ? `OK: ${pruebas} comprobaciones correctas. El sitio es publicable.`
    : `FALLOS: ${fallos} de ${pruebas} comprobaciones.`) + '\n');

process.exit(fallos === 0 ? 0 : 1);
