<?php

if (!defined('USUARIOS_API')) {
    define('USUARIOS_API', true);
}

require_once __DIR__ . '/../auth/auth_middleware.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../services/UserService.php';

function apiUsuariosResponder(array $payload, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiUsuariosError(int $codigo, string $mensaje, array $detalles = []): void
{
    $payload = ['success' => false, 'message' => $mensaje];
    if ($detalles !== []) {
        $payload['errores'] = array_values($detalles);
    }
    apiUsuariosResponder($payload, $codigo);
}

function apiUsuariosCapturar(Throwable $e): void
{
    if ($e instanceof UserServiceException) {
        apiUsuariosError($e->statusCode(), $e->getMessage(), $e->detalles());
    }
    error_log('API de usuarios: ' . $e->getMessage());
    apiUsuariosError(500, 'No se pudo completar la operación.');
}

function apiUsuariosExigirMetodo(string $metodo): void
{
    $actual = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($actual !== strtoupper($metodo)) {
        header('Allow: ' . strtoupper($metodo));
        apiUsuariosError(405, 'Método no permitido.');
    }
}

function apiUsuariosExigirCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !validarTokenCSRF($token)) {
        apiUsuariosError(403, 'Token CSRF inválido o expirado.');
    }
}

function apiUsuariosEntrada(): array
{
    $entrada = [];
    foreach ($_POST as $clave => $valor) {
        if (is_array($valor) || is_object($valor)) {
            apiUsuariosError(400, 'Los parámetros deben enviarse como cadenas.');
        }
        $entrada[(string) $clave] = (string) $valor;
    }
    return $entrada;
}

function apiUsuariosActor(): array
{
    $usuario = $_SESSION['admin_user'] ?? null;
    return is_array($usuario)
        ? ['id' => (string) ($usuario['id'] ?? ''), 'username' => (string) ($usuario['username'] ?? '')]
        : ['id' => '', 'username' => ''];
}
