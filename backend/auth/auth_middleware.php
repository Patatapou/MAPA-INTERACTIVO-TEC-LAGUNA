<?php
// ==========================================
// MIDDLEWARE DE AUTENTICACIÓN Y AUTORIZACIÓN
// ==========================================
// Verifica que el usuario tenga sesión de administrador activa.
// Se incluye al inicio de cada ruta protegida (panel, saveData, getimages, logout).

require_once __DIR__ . '/csrf.php';

/**
 * Requiere autenticación de administrador.
 * Si el usuario no está autenticado:
 * - Si es petición AJAX (X-Requested-With), devuelve HTTP 403 con JSON.
 * - Si es navegador normal, redirige a login.
 *
 * @return void
 */
function requerirAdmin(): void
{
    // Configurar parámetros de cookie solo si la sesión no está activa
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 7200, // 2 horas de inactividad
            'path' => '/',
            'domain' => '',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    $esAdmin = isset($_SESSION['admin']) && $_SESSION['admin'] === true;

    // Verificar expiración de sesión por inactividad (2 horas)
    if ($esAdmin && isset($_SESSION['auth_time'])) {
        $inactividad = time() - (int)$_SESSION['auth_time'];
        if ($inactividad > 7200) {
            session_destroy();
            setcookie(session_name(), '', time() - 3600, '/');
            $esAdmin = false;
        }
    }

    if (!$esAdmin) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Sesión no autenticada. Inicie sesión nuevamente.']);
            exit;
        }
        header('Location: index.php?p=login');
        exit;
    }

    // Renovar timestamp de actividad en cada request protegido
    $_SESSION['auth_time'] = time();
}
