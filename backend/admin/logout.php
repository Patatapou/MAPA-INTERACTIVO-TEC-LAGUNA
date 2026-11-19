<?php
require_once __DIR__ . '/../auth/auth_middleware.php';
requerirAdmin();

// Cerrar sesión de forma segura
$_SESSION = [];
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}
session_destroy();

header('Location: index.php?p=login');
exit;