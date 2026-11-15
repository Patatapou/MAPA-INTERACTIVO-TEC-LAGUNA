<?php
// ==========================================
// GUARDAR GEOMETRÍA DE EDIFICIO
// ==========================================
// Recibe metadatos y geometría de un edificio y los persiste
// en datos.json (metadatos) y GeoTec.js (geometría).
// Requiere autenticación de administrador y token CSRF.

require_once __DIR__ . '/../auth/auth_middleware.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../services/BuildingService.php';

requerirAdmin();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$buildingService = new BuildingService();
$usuario = $_SESSION['admin_user'] ?? null;

// Depuración inicial
error_log("=== saveGeometria.php INICIO ===");
error_log("Método: " . $_SERVER['REQUEST_METHOD']);
error_log("REMOTE_ADDR: " . ($_SERVER['REMOTE_ADDR'] ?? 'desconocido'));

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

// Obtener y sanitizar entradas
$id = trim($_POST['id'] ?? '');
$nombre = trim($_POST['nombre'] ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');
$aulasTexto = trim($_POST['aulas'] ?? '');
$geometryJson = trim($_POST['geometry'] ?? '');
$esNuevo = isset($_POST['es_nuevo']) && $_POST['es_nuevo'] === '1';

// Depuración detallada
error_log("=== saveGeometria.php Datos recibidos ===");
error_log("ID: '" . $id . "'");
error_log("Nombre: '" . $nombre . "'");
error_log("Descripcion: '" . $descripcion . "'");
error_log("Aulas texto: '" . $aulasTexto . "'");
error_log("esNuevo: " . ($esNuevo ? 'true' : 'false'));
error_log("geometryJson length: " . strlen($geometryJson));
error_log("geometryJson preview: " . substr($geometryJson, 0, 500));

// Validaciones básicas
if (empty($id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'El ID del edificio es requerido.']);
    exit;
}

if (empty($nombre)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'El nombre del edificio es requerido.']);
    exit;
}

// Procesar aulas
$aulas = array_filter(
    array_map('trim', explode("\n", $aulasTexto)),
    fn($a) => !empty($a)
);
error_log("Aulas procesadas: " . json_encode($aulas));

// Decodificar geometría
$geometry = null;
if (!empty($geometryJson)) {
    $decoded = json_decode($geometryJson, true);
    if ($decoded === null) {
        error_log("ERROR: json_decode falló para geometryJson: " . json_last_error_msg());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'La geometría no es un JSON válido.']);
        exit;
    }
    if (!is_array($decoded)) {
        error_log("ERROR: geometryJson no es un array");
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'La geometría no es un objeto válido.']);
        exit;
    }
    $geometry = $decoded;
    
    error_log("Geometría decodificada:");
    error_log("  type: " . ($geometry['type'] ?? 'missing'));
    error_log("  coordinates rings: " . (isset($geometry['coordinates']) ? count($geometry['coordinates']) : 'missing'));
    if (isset($geometry['coordinates'][0])) {
        error_log("  first ring points: " . count($geometry['coordinates'][0]));
        error_log("  first 3 coords: " . json_encode(array_slice($geometry['coordinates'][0], 0, 3)));
    }
}

if ($esNuevo) {
    error_log("Llamando a crearEdificio");
    $resultado = $buildingService->crearEdificio($id, $nombre, $descripcion, $aulas, $geometry, $usuario ?? []);
} else {
    error_log("Llamando a actualizarEdificio");
    $resultado = $buildingService->actualizarEdificio($id, $nombre, $descripcion, $aulas, $geometry, $usuario ?? []);
}

error_log("=== saveGeometria.php Resultado ===");
error_log("success: " . ($resultado['success'] ? 'true' : 'false'));
error_log("message: " . ($resultado['message'] ?? 'sin mensaje'));

http_response_code($resultado['success'] ? 200 : 400);
echo json_encode($resultado);