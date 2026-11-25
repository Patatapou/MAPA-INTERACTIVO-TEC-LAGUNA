<?php
require_once __DIR__ . '/../auth/auth_middleware.php';
require_once __DIR__ . '/../services/AdminServerLifecycle.php';
requerirAdmin();

// Cerrar sesión de forma segura
try {
    (new AdminServerLifecycle())->requestLogout();
} catch (RuntimeException $e) {
    error_log('No se pudo detener el servidor local al cerrar sesión: ' . $e->getMessage());
}

$_SESSION = [];
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}
session_destroy();

header('Location: index.php?p=login');
exit;