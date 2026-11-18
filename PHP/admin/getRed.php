<?php
// ==========================================
// ENDPOINT: RED DE CAMINOS VIGENTE
// ==========================================
// GET index.php?p=getRed
// Requiere sesión de administrador.
//
// Devuelve la red completa con su revisión. Lo usa el editor para:
//  - "Descartar" sin recargar la página,
//  - refrescar tras un conflicto 409,
//  - mostrar el estado real al abrir la vista (la vista también lo embebe).

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function redEstadoResponder(array $payload, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    require_once __DIR__ . '/../auth/auth_middleware.php';
    require_once __DIR__ . '/../services/NetworkService.php';

    requerirAdmin();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        redEstadoResponder(['success' => false, 'message' => 'Método no permitido. Use GET.'], 405);
    }

    $servicio = new NetworkService();
    $estado = $servicio->estado();

    redEstadoResponder([
        'success' => true,
        'revision' => $estado['revision'],
        'segmentos' => $estado['segmentos'],
        'geojson' => $estado['geojson'],
    ]);
} catch (NetworkServiceException $e) {
    redEstadoResponder([
        'success' => false,
        'message' => $e->getMessage(),
        'errors' => $e->errores(),
    ], $e->statusCode());
} catch (Throwable $e) {
    error_log('getRed.php: ' . $e->getMessage());
    redEstadoResponder([
        'success' => false,
        'message' => 'No se pudo leer la red de caminos.',
    ], 500);
}
