<?php
// ==========================================
// FRONT CONTROLLER
// ==========================================
// Único PHP que debe ejecutarse desde el navegador. Carga las constantes
// del proyecto y delega toda la decisión de rutas en `backend/router.php`.
//
// En la Etapa 4 este archivo se moverá a `frontend/index.php` y solo cambiará
// la ruta del bootstrap (`__DIR__ . '/../backend/bootstrap.php'`).

require_once __DIR__ . '/backend/bootstrap.php';
require_once BACKEND_PATH . '/router.php';
