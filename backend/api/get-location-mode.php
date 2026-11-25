<?php
// ==========================================
// API: LEER / ACTUALIZAR EL MODO DE UBICACIÓN
// ==========================================
// GET y POST protegidos: consulta y cambia el modo desde el panel.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function responderModoUbicacion(array $payload, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    require_once __DIR__ . '/../bootstrap.php';
    require_once BACKEND_PATH . '/auth/auth_middleware.php';
    require_once BACKEND_PATH . '/services/RouteSettingsService.php';
    requerirAdmin();
    $servicio = new RouteSettingsService();

    $metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($metodo !== 'GET' && $metodo !== 'POST') {
        header('Allow: GET, POST');
        responderModoUbicacion([
            'success' => false,
            'message' => 'Método no permitido. Use GET o POST.',
        ], 405);
    }

    if ($metodo === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !validarTokenCSRF($token)) {
            responderModoUbicacion([
                'success' => false,
                'message' => 'Token CSRF inválido o expirado. Recargue la página.',
            ], 403);
        }

        $entrada = null;
        if (isset($_POST['settings_json']) && is_string($_POST['settings_json'])) {
            $entrada = json_decode($_POST['settings_json'], true);
        } elseif (isset($_POST['modo']) && is_string($_POST['modo'])) {
            $entrada = $servicio->obtener();
            $entrada['modo_ubicacion'] = $_POST['modo'];
        }
        if (!is_array($entrada)) {
            responderModoUbicacion([
                'success' => false,
                'message' => 'La configuración enviada no es válida.',
            ], 400);
        }

        $configuracion = $servicio->guardar($entrada);
        responderModoUbicacion([
            'success' => true,
            'modo' => $configuracion['modo_ubicacion'],
            'settings' => $configuracion,
        ]);
    }

    $configuracion = $servicio->obtener();
    responderModoUbicacion([
        'success' => true,
        'modo' => $configuracion['modo_ubicacion'],
        'settings' => $configuracion,
    ]);
} catch (InvalidArgumentException $e) {
    responderModoUbicacion([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
} catch (Throwable $e) {
    error_log('API de modo de ubicación: ' . $e->getMessage());
    responderModoUbicacion([
        'success' => false,
        'message' => 'No se pudo completar la operación. Revise el registro del servidor.',
    ], 500);
}