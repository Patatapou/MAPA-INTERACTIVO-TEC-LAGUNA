<?php
// ==========================================
// FRONT CONTROLLER (document root: frontend/)
// ==========================================
// Único PHP que debe ejecutarse desde el navegador. Carga las constantes
// del proyecto y delega toda la decisión de rutas en `backend/router.php`.
//
// `frontend/` es el document root: el código, los datos privados y los
// respaldos quedan fuera de él (`backend/`, `database/`).

require_once __DIR__ . '/../backend/bootstrap.php';
require_once BACKEND_PATH . '/router.php';
