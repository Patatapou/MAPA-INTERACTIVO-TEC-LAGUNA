<?php
// ==========================================
// GUARDAR METADATOS DE EDIFICIO (panel administrativo)
// ==========================================
// Actualiza nombre, descripción, aulas e imágenes de un edificio existente.
//
// IMPORTANTE: la carpeta `PHP/public` (hoy en `DUMP/`) está obsoleta. Este
// endpoint trabaja SIEMPRE contra las rutas del bootstrap:
//   - `PUBLIC_DATA_PATH/datos.json` (metadatos)
//   - `IMG_PATH/<id>/` (imágenes)
//
// La geometría NO se modifica aquí: se gestiona en `save-geometria.php`.

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../auth/auth_middleware.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../services/BuildingService.php';

requerirAdmin();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

/**
 * Devuelve una respuesta JSON y termina la ejecución.
 */
function responderJson(int $codigo, array $payload): void
{
    http_response_code($codigo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(405, ['success' => false, 'message' => 'Método no permitido']);
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    responderJson(403, ['success' => false, 'message' => 'Token CSRF inválido. Recarga la página e inténtalo de nuevo.']);
}

$raiz = FRONTEND_PATH;
$imgDir = IMG_PATH;

$id = trim($_POST['id'] ?? '');
$nombre = trim($_POST['nombre'] ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');
$aulasTexto = trim($_POST['aulas'] ?? '');

if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
    responderJson(400, ['success' => false, 'message' => 'ID de edificio inválido.']);
}

if ($nombre === '') {
    responderJson(400, ['success' => false, 'message' => 'El nombre del edificio es requerido']);
}

$aulas = array_values(array_filter(
    array_map('trim', explode("\n", $aulasTexto)),
    fn($a) => $a !== ''
));

$carpetaImagenes = $imgDir . DIRECTORY_SEPARATOR . $id;

try {
    $buildingService = new BuildingService();

    // Verificar que el edificio existe (sin tocar GeoTec.js: la geometría no
    // es responsabilidad de este endpoint).
    $existentes = $buildingService->listarEdificios();
    if (!isset($existentes[$id])) {
        responderJson(404, ['success' => false, 'message' => "El edificio '$id' no existe."]);
    }

    // ---------------------------------------------------------------
    // 1. Eliminar las imágenes marcadas para borrar
    // ---------------------------------------------------------------
    $eliminar = json_decode($_POST['imagenes_eliminar'] ?? '[]', true);
    if (is_array($eliminar)) {
        $carpetaReal = realpath($carpetaImagenes);

        foreach ($eliminar as $rutaRelativa) {
            if (!is_string($rutaRelativa) || $carpetaReal === false) {
                continue;
            }

            // Normalizar y confinar la ruta dentro de img/<id>/
            $rutaRelativa = str_replace('\\', '/', $rutaRelativa);
            $rutaRelativa = ltrim($rutaRelativa, '/');
            $ruta = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rutaRelativa);
            $objetivo = realpath($ruta);

            if (
                $objetivo !== false
                && str_starts_with($objetivo, $carpetaReal . DIRECTORY_SEPARATOR)
                && is_file($objetivo)
            ) {
                if (!@unlink($objetivo)) {
                    error_log('saveEdificio.php: no se pudo eliminar la imagen ' . $objetivo);
                }
            }
        }
    }

    // ---------------------------------------------------------------
    // 2. Subir las nuevas imágenes a img/<id>/
    // ---------------------------------------------------------------
    if (isset($_FILES['imagenes']) && is_array($_FILES['imagenes']['name'] ?? null)) {
        $permitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $total = count($_FILES['imagenes']['name']);

        for ($i = 0; $i < $total; $i++) {
            $error = $_FILES['imagenes']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }

            $extension = strtolower(pathinfo($_FILES['imagenes']['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($extension, $permitidas, true)) {
                continue;
            }

            if (!is_dir($carpetaImagenes) && !mkdir($carpetaImagenes, 0755, true) && !is_dir($carpetaImagenes)) {
                throw new RuntimeException('No se pudo crear la carpeta de imágenes del edificio.');
            }

            $destino = $carpetaImagenes . DIRECTORY_SEPARATOR . uniqid('img_') . '.' . $extension;
            if (!move_uploaded_file($_FILES['imagenes']['tmp_name'][$i], $destino)) {
                throw new RuntimeException('Error al subir la imagen: ' . $_FILES['imagenes']['name'][$i]);
            }
        }
    }

    // ---------------------------------------------------------------
    // 3. Sincronizar datos.json["imagenes"] con la carpeta en disco
    // ---------------------------------------------------------------
    // La carpeta es la fuente de verdad: así el carrusel del panel (que lista
    // archivos) y el popup del mapa público (que lee datos.json) coinciden.
    $imagenes = [];
    if (is_dir($carpetaImagenes)) {
        foreach (scandir($carpetaImagenes) ?: [] as $archivo) {
            if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $archivo)) {
                $imagenes[] = 'img/' . $id . '/' . $archivo;
            }
        }
        sort($imagenes, SORT_NATURAL | SORT_FLAG_CASE);
    }

    $resultado = $buildingService->actualizarMetadatos($id, [
        'nombre' => $nombre,
        'descripcion' => $descripcion,
        'aulas' => $aulas,
        'carpeta' => [$id],
        'imagenes' => $imagenes,
    ], $_SESSION['admin_user'] ?? []);

    responderJson($resultado['success'] ? 200 : 400, $resultado);

} catch (Throwable $e) {
    error_log('saveEdificio.php: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    responderJson(500, [
        'success' => false,
        'message' => 'No se pudo guardar el edificio: ' . $e->getMessage(),
    ]);
}
