<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function publicarMapaResponder(array $payload, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    require_once __DIR__ . '/../bootstrap.php';
    require_once BACKEND_PATH . '/auth/auth_middleware.php';
    require_once BACKEND_PATH . '/services/MapPublisher.php';
    require_once BACKEND_PATH . '/services/PublicMapExporter.php';

    requerirAdmin();

    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        publicarMapaResponder(['success' => false, 'message' => 'La publicación solo se permite desde la computadora local.'], 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        publicarMapaResponder(['success' => false, 'message' => 'Método no permitido. Use POST.'], 405);
    }
    if (!validarTokenCSRF((string) ($_POST['csrf_token'] ?? ''))) {
        publicarMapaResponder([
            'success' => false,
            'message' => 'Token CSRF inválido o expirado. Recarga el panel e inténtalo de nuevo.',
        ], 403);
    }

    $accion = (string) ($_POST['action'] ?? '');
    $publisher = new MapPublisher(null, static function (): void {
        (new PublicMapExporter())->generar();
    });
    if ($accion === 'preview') {
        publicarMapaResponder(array_merge(['success' => true], $publisher->vistaPrevia()));
    }
    if ($accion === 'publish') {
        publicarMapaResponder(array_merge(
            ['success' => true],
            $publisher->publicar((string) ($_POST['snapshot'] ?? ''))
        ));
    }

    publicarMapaResponder(['success' => false, 'message' => 'Acción de publicación no válida.'], 400);
} catch (MapPublisherException $e) {
    publicarMapaResponder(array_merge(
        ['success' => false, 'message' => $e->getMessage()],
        $e->details
    ), $e->httpStatus);
} catch (Throwable $e) {
    error_log('publish-map.php: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    publicarMapaResponder([
        'success' => false,
        'message' => 'No se pudo actualizar el mapa. Revisa server.err para más detalles.',
    ], 500);
}
