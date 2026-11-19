<?php
// ==========================================
// LISTAR IMÁGENES DE UN EDIFICIO
// ==========================================
// Devuelve las imágenes de `IMG_PATH/<folder>/` (frontend/img/<id>/).
// La carpeta `PHP/public` (hoy en `DUMP/`) está obsoleta y no se usa.

header('Content-Type: application/json');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../auth/auth_middleware.php';
requerirAdmin();

$folder = $_POST['folder'] ?? '';

if (!is_string($folder) || $folder === '') {
    echo json_encode([]);
    exit;
}

// Sanitizar folder contra path traversal: solo alfanumérico, guion bajo y guion medio
if (!preg_match('/^[A-Za-z0-9_-]+$/', $folder)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Carpeta inválida.']);
    exit;
}

$dir = IMG_PATH . DIRECTORY_SEPARATOR . $folder;
$images = [];

if (is_dir($dir)) {
    foreach (scandir($dir) ?: [] as $file) {
        if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file)) {
            $images[] = 'img/' . $folder . '/' . $file;
        }
    }
    sort($images, SORT_NATURAL | SORT_FLAG_CASE);
}

echo json_encode($images);
