<?php
// ==========================================
// MIDDLEWARE DE AUTENTICACIÓN Y AUTORIZACIÓN
// ==========================================
// Verifica que el usuario tenga sesión de administrador activa.
// Se incluye al inicio de cada ruta protegida (panel, saveData, getimages, logout).

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../repositories/UserRepository.php';

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
        terminarSesionNoValida('Sesión no autenticada. Inicie sesión nuevamente.');
    }

    $sesionUsuario = $_SESSION['admin_user'] ?? null;
    if (!is_array($sesionUsuario) || !isset($sesionUsuario['id'])) {
        terminarSesionNoValida('Sesión no válida.');
    }

    $repo = new UserRepository();
    $usuarioActual = $repo->buscarPorId((string) $sesionUsuario['id']);
    $versionSesion = array_key_exists('session_version', $sesionUsuario)
        ? (int) $sesionUsuario['session_version']
        : null;
    $versionActual = $usuarioActual !== null ? (int) ($usuarioActual['session_version'] ?? 1) : 0;
    if ($versionSesion === null) {
        $versionSesion = $versionActual;
        $_SESSION['admin_user']['session_version'] = $versionActual;
    }
    if (
        $usuarioActual === null
        || ($usuarioActual['status'] ?? '') !== 'activo'
        || $versionActual !== $versionSesion
    ) {
        terminarSesionNoValida('La sesión fue cerrada por la desactivación o el cambio de contraseña.');
    }

    if (!empty($usuarioActual['must_change_password']) && ($_GET['p'] ?? '') !== 'cambiar-contrasena' && ($_GET['p'] ?? '') !== 'logout') {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['success' => false, 'code' => 'must_change_password', 'message' => 'Debe cambiar su contraseña antes de continuar.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: index.php?p=cambiar-contrasena');
        exit;
    }

    // Renovar timestamp de actividad en cada request protegido
    $_SESSION['auth_time'] = time();
}

/**
 * Terminar una sesión inválida y responder según el tipo de petición.
 */
function terminarSesionNoValida(string $mensaje): void
{
    $_SESSION = [];
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();

    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['success' => false, 'message' => $mensaje], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: index.php?p=login');
    exit;
}
