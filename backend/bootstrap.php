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
// En esta etapa (Etapa 2 del plan) el backend vive en `backend/` y el
// document root sigue siendo la raíz; las etapas siguientes solo ajustan
// los valores de `FRONTEND_PATH`, `PUBLIC_DATA_PATH`, `IMG_PATH` y
// `DATABASE_PATH`.

if (!defined('ROOT_PATH')) {
    // Raíz del proyecto (donde viven `.git`, `backend/`, `js/`, `css/`, ...).
    define('ROOT_PATH', dirname(__DIR__));
}

if (!defined('BACKEND_PATH')) {
    // Código PHP privado (router, API, servicios, repositorios, vistas).
    define('BACKEND_PATH', __DIR__);
}

if (!defined('FRONTEND_PATH')) {
    // Document root público: lo único que debe servirse por HTTP.
    // Etapa 4: pasará a `ROOT_PATH . '/frontend'`.
    define('FRONTEND_PATH', ROOT_PATH);
}

if (!defined('PUBLIC_DATA_PATH')) {
    // Datos públicos consumidos por el mapa (datos.json, GeoTec.js,
    // caminitos.geojson). Etapa 4: `FRONTEND_PATH . '/data'`.
    define('PUBLIC_DATA_PATH', ROOT_PATH);
}

if (!defined('IMG_PATH')) {
    // Imágenes de carrusel por edificio (`img/<id>/`).
    // Etapa 4: `FRONTEND_PATH . '/img'`.
    define('IMG_PATH', ROOT_PATH . '/img');
}

if (!defined('DATABASE_PATH')) {
    // Datos privados del sistema (usuarios, ajustes, auditoría, rutas).
    // Etapa 3: pasará a `ROOT_PATH . '/database'`.
    define('DATABASE_PATH', ROOT_PATH . '/PHP/data');
}

if (!defined('RUNTIME_PATH')) {
    // Archivos JSON de escritura en tiempo de ejecución.
    // Etapa 3: `DATABASE_PATH . '/runtime'`.
    define('RUNTIME_PATH', DATABASE_PATH);
}

if (!defined('BACKUPS_PATH')) {
    // Respaldos automáticos (red de caminos, rutas).
    // Etapa 3: `DATABASE_PATH . '/backups'`.
    define('BACKUPS_PATH', DATABASE_PATH . '/backups');
}
