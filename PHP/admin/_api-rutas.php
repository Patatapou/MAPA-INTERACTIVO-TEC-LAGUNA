<?php
// ==========================================
// UTILIDADES COMPARTIDAS DE LA API DE RUTAS
// ==========================================
// Archivo interno: no es un endpoint. Lo incluyen getRutas.php, saveRuta.php,
// deleteRuta.php y getRedCaminos.php.
//
// Todas las respuestas son JSON. Nunca se devuelve HTML en un endpoint AJAX.

if (!defined('RUTAS_API')) {
    define('RUTAS_API', true);
}

require_once __DIR__ . '/../auth/auth_middleware.php';
require_once __DIR__ . '/../services/RouteService.php';
require_once __DIR__ . '/../services/PathNetworkService.php';
require_once __DIR__ . '/../services/NetworkTopologyService.php';

/**
 * Enviar una respuesta JSON correcta.
 */
function apiRutasResponder(array $payload, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Enviar una respuesta JSON de error.
 *
 * @param array<int, string> $detalles
 */
function apiRutasError(int $codigo, string $mensaje, array $detalles = []): void
{
    $payload = ['success' => false, 'message' => $mensaje];
    if ($detalles !== []) {
        $payload['errores'] = array_values($detalles);
    }
    apiRutasResponder($payload, $codigo);
}

/**
 * Traducir una excepción a una respuesta JSON sin filtrar trazas.
 */
function apiRutasCapturar(Throwable $e): void
{
    if ($e instanceof RouteServiceException) {
        apiRutasError($e->statusCode(), $e->getMessage(), $e->detalles());
    }

    error_log('API de rutas: ' . $e->getMessage());
    apiRutasError(500, 'No se pudo completar la operación. Revise el registro del servidor.');
}

/**
 * Exigir el método HTTP esperado.
 */
function apiRutasExigirMetodo(string $metodo): void
{
    $actual = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($actual !== strtoupper($metodo)) {
        header('Allow: ' . strtoupper($metodo));
        apiRutasError(405, 'Método no permitido. Use ' . strtoupper($metodo) . '.');
    }
}

/**
 * Exigir token CSRF válido en el cuerpo POST.
 */
function apiRutasExigirCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !validarTokenCSRF($token)) {
        apiRutasError(403, 'Token CSRF inválido o expirado. Recargue la página.');
    }
}

/**
 * Datos del usuario autenticado para auditoría.
 *
 * @return array{id: string, username: string}
 */
function apiRutasUsuario(): array
{
    $usuario = $_SESSION['admin_user'] ?? null;
    if (!is_array($usuario)) {
        return ['id' => '', 'username' => ''];
    }
    return [
        'id' => (string) ($usuario['id'] ?? ''),
        'username' => (string) ($usuario['username'] ?? ''),
    ];
}
