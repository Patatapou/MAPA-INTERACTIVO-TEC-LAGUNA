<?php
// ==========================================
// ELIMINAR EDIFICIO
// ==========================================
// Elimina un edificio (metadatos de datos.json y geometría de GeoTec.js).
// Requiere autenticación de administrador y token CSRF.

require_once 'PHP/auth/auth_middleware.php';
require_once 'PHP/auth/csrf.php';
require_once 'PHP/services/BuildingService.php';

requerirAdmin();

header('Content-Type: application/json');

$buildingService = new BuildingService();
$usuario = $_SESSION['admin_user'] ?? null;

// Validar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// Validar CSRF
$csrfToken = $_POST['csrf_token'] ?? '';
if (!validarTokenCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido. Recarga la página e inténtalo de nuevo.']);
    exit;
}

$id = trim($_POST['id'] ?? '');

if (empty($id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'El ID del edificio es requerido.']);
    exit;
}

$resultado = $buildingService->eliminarEdificio($id, $usuario ?? []);

http_response_code($resultado['success'] ? 200 : 400);
echo json_encode($resultado);
