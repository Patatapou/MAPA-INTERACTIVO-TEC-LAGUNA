<?php
// ==========================================
// BOOTSTRAP DEL PROYECTO
// ==========================================
// Único lugar donde se definen las rutas físicas del proyecto.
//
// Ningún archivo debe calcular rutas con `dirname(__DIR__, n)`, `__DIR__`
// ni rutas relativas al directorio de trabajo: todas las rutas se derivan
// de estas constantes. Así, mover carpetas (reestructuración a
// `backend/`, `frontend/`, `database/`) solo exige cambiar este archivo.
//
// Estructura vigente (Etapa 4 del plan):
//   frontend/   document root (HTML, CSS, JS, imágenes, teselas y datos públicos)
//   backend/    código PHP privado (no se sirve por HTTP)
//   database/   JSON privado y respaldos (no se sirve por HTTP)

if (!defined('ROOT_PATH')) {
    // Raíz del proyecto (donde viven `.git`, `backend/`, `frontend/`, ...).
    define('ROOT_PATH', dirname(__DIR__));
}

if (!defined('BACKEND_PATH')) {
    // Código PHP privado (router, API, servicios, repositorios, vistas).
    define('BACKEND_PATH', __DIR__);
}

if (!defined('FRONTEND_PATH')) {
    // Document root público: lo único que debe servirse por HTTP.
    define('FRONTEND_PATH', ROOT_PATH . '/frontend');
}

if (!defined('PUBLIC_DATA_PATH')) {
    // Datos públicos consumidos por el mapa (datos.json, GeoTec.js,
    // caminitos.geojson). PHP los escribe y el navegador los lee.
    define('PUBLIC_DATA_PATH', FRONTEND_PATH . '/data');
}

if (!defined('IMG_PATH')) {
    // Imágenes de carrusel por edificio (`img/<id>/`).
    define('IMG_PATH', FRONTEND_PATH . '/img');
}

if (!defined('DATABASE_PATH')) {
    // Datos privados del sistema (usuarios, ajustes, auditoría, rutas).
    define('DATABASE_PATH', ROOT_PATH . '/database');
}

if (!defined('RUNTIME_PATH')) {
    // Archivos JSON de escritura en tiempo de ejecución.
    define('RUNTIME_PATH', DATABASE_PATH . '/runtime');
}

if (!defined('BACKUPS_PATH')) {
    // Respaldos automáticos (red de caminos, rutas).
    define('BACKUPS_PATH', DATABASE_PATH . '/backups');
}

// Fallar de forma visible: sin estas carpetas no hay datos privados y la
// aplicación NO debe arrancar (nunca crear un users.json vacío en silencio).
foreach ([RUNTIME_PATH, BACKUPS_PATH] as $directorioDatos) {
    if (!is_dir($directorioDatos)) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Falta la carpeta de datos: {$directorioDatos}\n");
            exit(1);
        }

        http_response_code(500);
        exit("Falta la carpeta de datos: {$directorioDatos}");
    }
}
