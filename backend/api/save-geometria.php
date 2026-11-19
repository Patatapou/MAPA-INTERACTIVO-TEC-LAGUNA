<?php
// ==========================================
// GUARDAR GEOMETRÍA DE EDIFICIO
// ==========================================
// Recibe metadatos y geometría de un edificio y los persiste
// en datos.json (metadatos) y GeoTec.js (geometría).
// Requiere autenticación de administrador y token CSRF.

// Siempre devolver JSON, incluso en errores
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

function jsonError($code, $message) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

try {
    require_once __DIR__ . '/../bootstrap.php';
    require_once BACKEND_PATH . '/auth/auth_middleware.php';
    require_once BACKEND_PATH . '/auth/csrf.php';
    require_once BACKEND_PATH . '/services/BuildingService.php';

    requerirAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError(405, 'Método no permitido');
    }

    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        jsonError(403, 'Token CSRF inválido. Recarga la página e inténtalo de nuevo.');
    }

    $id = trim($_POST['id'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $aulasTexto = trim($_POST['aulas'] ?? '');
    $geometryJson = trim($_POST['geometry'] ?? '');
    $esNuevo = isset($_POST['es_nuevo']) && $_POST['es_nuevo'] === '1';
    $tipo = strtolower(trim($_POST['tipo'] ?? 'edificio'));
    if (!in_array($tipo, ['edificio', 'acceso'], true)) {
        $tipo = 'edificio';
    }

    if ($id === '') {
        jsonError(400, 'El ID del edificio es requerido.');
    }

    // Mismo formato que usa saveEdificio.php para las carpetas de imágenes
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
        jsonError(400, 'El ID del edificio solo puede contener letras, números, guion y guion bajo.');
    }

    if ($nombre === '') {
        jsonError(400, 'El nombre del edificio es requerido.');
    }

    $aulas = array_values(array_filter(
        array_map('trim', explode("\n", $aulasTexto)),
        fn($a) => $a !== ''
    ));

    $geometry = null;
    if ($geometryJson !== '') {
        $decoded = json_decode($geometryJson, true);
        if (!is_array($decoded)) {
            jsonError(400, 'La geometría no es un JSON válido: ' . json_last_error_msg());
        }
        $geometry = $decoded;
    }

    $buildingService = new BuildingService();
    $usuario = $_SESSION['admin_user'] ?? [];

    if ($esNuevo) {
        $resultado = $buildingService->crearEdificio($id, $nombre, $descripcion, $aulas, $geometry ?? [], $usuario, $tipo);
    } else {
        $resultado = $buildingService->actualizarEdificio($id, $nombre, $descripcion, $aulas, $geometry, $usuario, $tipo);
    }

    http_response_code($resultado['success'] ? 200 : 400);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('saveGeometria.php: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo guardar el edificio: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
