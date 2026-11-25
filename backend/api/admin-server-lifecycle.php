<?php
declare(strict_types=1);

require_once BACKEND_PATH . '/auth/csrf.php';
require_once BACKEND_PATH . '/services/AdminServerLifecycle.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responderCicloServidor(array $payload, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$direccion = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($direccion, ['127.0.0.1', '::1'], true)) {
    responderCicloServidor(['success' => false, 'message' => 'Solo se permite acceso local.'], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderCicloServidor(['success' => false, 'message' => 'Método no permitido.'], 405);
}

$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || !validarTokenCSRF($token)) {
    responderCicloServidor(['success' => false, 'message' => 'Token CSRF inválido.'], 403);
}

$accion = $_POST['action'] ?? '';
if (!is_string($accion) || !in_array($accion, ['heartbeat', 'disconnect'], true)) {
    responderCicloServidor(['success' => false, 'message' => 'Acción no válida.'], 400);
}

try {
    $cicloServidor = new AdminServerLifecycle();
    if ($accion === 'heartbeat') {
        $cicloServidor->heartbeat();
    } else {
        $cicloServidor->requestDisconnect();
    }
} catch (Throwable $e) {
    error_log('Ciclo de vida del servidor local: ' . $e->getMessage());
    responderCicloServidor([
        'success' => false,
        'message' => 'No se pudo actualizar el estado del servidor local.',
    ], 500);
}

responderCicloServidor(['success' => true]);
