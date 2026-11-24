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
    requerirAdmin();

    $metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($metodo !== 'GET' && $metodo !== 'POST') {
        header('Allow: GET, POST');
        responderModoUbicacion([
            'success' => false,
            'message' => 'Método no permitido. Use GET o POST.',
        ], 405);
    }

    $rutaConfiguracion = PUBLIC_DATA_PATH . '/location-config.js';
    if ($metodo === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !validarTokenCSRF($token)) {
            responderModoUbicacion([
                'success' => false,
                'message' => 'Token CSRF inválido o expirado. Recargue la página.',
            ], 403);
        }

        $modo = $_POST['modo'] ?? '';
        if (!is_string($modo) || !in_array($modo, ['real', 'simulada'], true)) {
            responderModoUbicacion([
                'success' => false,
                'message' => 'El modo debe ser «real» o «simulada».',
            ], 400);
        }

        $contenido = "window.MODO_UBICACION = '" . $modo . "';\n";
        $rutaTemporal = $rutaConfiguracion . '.tmp.' . bin2hex(random_bytes(8));
        $escrito = file_put_contents($rutaTemporal, $contenido, LOCK_EX);
        if ($escrito !== strlen($contenido)) {
            if (is_file($rutaTemporal)) {
                unlink($rutaTemporal);
            }
            responderModoUbicacion([
                'success' => false,
                'message' => 'No se pudo guardar el modo de ubicación.',
            ], 500);
        }
        if (!rename($rutaTemporal, $rutaConfiguracion)) {
            unlink($rutaTemporal);
            responderModoUbicacion([
                'success' => false,
                'message' => 'No se pudo aplicar el modo de ubicación.',
            ], 500);
        }

        responderModoUbicacion(['success' => true, 'modo' => $modo]);
    }

    $contenido = is_file($rutaConfiguracion) ? file_get_contents($rutaConfiguracion) : false;
    if (
        $contenido === false
        || preg_match(
            '/\Awindow\.MODO_UBICACION\s*=\s*[\'"](real|simulada)[\'"];\s*\z/',
            trim($contenido),
            $coincidenciaModo
        ) !== 1
    ) {
        error_log('Modo de ubicación inválido en location-config.js.');
        responderModoUbicacion([
            'success' => false,
            'message' => 'La configuración del modo de ubicación no es válida.',
        ], 500);
    }

    responderModoUbicacion(['success' => true, 'modo' => $coincidenciaModo[1]]);
} catch (Throwable $e) {
    error_log('API de modo de ubicación: ' . $e->getMessage());
    responderModoUbicacion([
        'success' => false,
        'message' => 'No se pudo completar la operación. Revise el registro del servidor.',
    ], 500);
}