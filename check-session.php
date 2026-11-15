<?php
// Verifica si el usuario tiene sesión de administrador activa.
// Devuelve JSON: { isAdmin: bool, csrf_token: string }
header('Content-Type: application/json');
session_set_cookie_params(0, '/');
session_start();

// Generar/renovar token CSRF para el formulario de sugerencias
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isAdmin = isset($_SESSION['admin']) && $_SESSION['admin'] === true;

echo json_encode([
    'isAdmin' => $isAdmin,
    'csrf_token' => $_SESSION['csrf_token']
]);
