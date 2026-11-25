<?php
// ==========================================
// ROUTER DE DESARROLLO (php -S)
// ==========================================
// Uso:  php -S 127.0.0.1:8000 -t frontend router.php
//
// Objetivo: impedir que el servidor embebido sirva archivos que no deben
// exponerse por HTTP (datos privados, código interno, respaldos y logs).
//
// El document root es `frontend/`, así que `backend/` y `database/` quedan
// fuera del alcance del servidor. Este router añade una barrera extra por URI
// y bloquea respaldos, logs y archivos ocultos. El catálogo público de rutas
// se sirve en vivo desde el modelo local; GitHub Pages usa el JSON exportado.
//
// Este archivo es SOLO para el entorno local. En producción deben usarse
// reglas del servidor web (Apache/Nginx) o mover los datos fuera del docroot.

/**
 * Devuelve 403 y termina la petición.
 */
function denegarAcceso(): void
{
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'success' => false,
        'message' => 'Acceso denegado por el router de desarrollo.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($uri) || $uri === '') {
    $uri = '/';
}

// Normalizar: decodificar, unificar separadores y pasar a minúsculas.
$ruta = '/' . ltrim(str_replace('\\', '/', rawurldecode($uri)), '/');
$rutaMin = strtolower($ruta);

// 1) Directorios que nunca deben servirse por HTTP.
$directoriosBloqueados = [
    '/backend',
    '/database',
    '/dump',
    '/php/data',
    '/php/public',
    '/php/auth',
    '/php/services',
    '/php/repositories',
    '/scripts',
    '/tests',
    '/docs',
];

foreach ($directoriosBloqueados as $directorio) {
    if ($rutaMin === $directorio || strpos($rutaMin, $directorio . '/') === 0) {
        denegarAcceso();
    }
}

// 2) Extensiones sensibles (respaldos, logs, auditoría, configuración).
$extensionesBloqueadas = ['bak', 'jsonl', 'log', 'ini', 'env', 'tmp', 'sql', 'md', 'lock'];
$extension = strtolower(pathinfo($rutaMin, PATHINFO_EXTENSION));
if ($extension !== '' && in_array($extension, $extensionesBloqueadas, true)) {
    denegarAcceso();
}

// 3) Respaldos con marca de tiempo (`caminitos.geojson.bak-20260101010101`).
if (strpos($rutaMin, '.bak') !== false) {
    denegarAcceso();
}

// 4) Archivos y directorios ocultos (por ejemplo `.env`, `.git`).
if (preg_match('#(^|/)\.[^/]#', $rutaMin) === 1) {
    denegarAcceso();
}

// 5) Nombres con sufijo de archivo temporal de escritura atómica.
if (strpos($rutaMin, '.tmp.') !== false) {
    denegarAcceso();
}

// En local, el mapa refleja de inmediato las rutas activas del panel. En
// GitHub Pages esta URL se resuelve al archivo estático publicado.
if ($rutaMin === '/data/rutas-publicas.json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        require_once __DIR__ . '/backend/bootstrap.php';
        require_once BACKEND_PATH . '/services/PublicMapExporter.php';
        $catalogo = (new PublicMapExporter())->obtenerCatalogo();
        echo json_encode(
            $catalogo,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (Throwable $e) {
        error_log('router.php: no se pudo cargar el catálogo público de rutas: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo cargar el catálogo público de rutas.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Cualquier otra petición: que la atienda el servidor embebido con normalidad
// (incluye la ejecución de archivos .php y el índice del directorio raíz).
return false;
