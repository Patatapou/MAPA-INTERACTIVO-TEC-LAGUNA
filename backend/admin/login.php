<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../auth/auth_middleware.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../auth/rate_limit.php';
require_once __DIR__ . '/../services/AuthService.php';

// Cookies de sesión seguras
session_set_cookie_params([
    'lifetime' => 7200, // 2 horas
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$authService = new AuthService();
$error = '';
$csrfToken = generarTokenCSRF();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    $csrfPost = $_POST['csrf_token'] ?? '';
    if (!validarTokenCSRF($csrfPost)) {
        $error = 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.';
    } else {
        // Rate limiting
        if (!registrarIntentoFallido()) {
            $error = 'Demasiados intentos fallidos. Intenta nuevamente más tarde.';
        } else if (
            isset($_POST['usuario']) &&
            isset($_POST['password'])
        ) {
            // Autenticar contra users.json (única fuente de credenciales)
            $usuario = $authService->autenticar($_POST['usuario'], $_POST['password']);

            if ($usuario !== null) {
                // Credenciales válidas
                resetIntentosFallidos();
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                $_SESSION['auth_time'] = time();
                $_SESSION['auth_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
                $_SESSION['admin_user'] = $usuario;
                header('Location: index.php?p=editar');
                exit;
            } else {
                $error = 'Credenciales incorrectas';
            }
        } else {
            $error = 'Credenciales incorrectas';
        }
    }
    // Renovar token CSRF tras intento (fallido o exitoso que no redirigió)
    $csrfToken = generarTokenCSRF();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login Admin ITL</title>
    <link rel="stylesheet" type="text/css" href="css/login.css">
</head>
<body>


<div class="login-wrap">
    <div class="login-html">
        <input id="tab-1" type="radio" name="tab" class="sign-in" checked>
        <label for="tab-1" class="tab">Acceso Administrador ITL</label>
        <p style="color:red"><?= $error ?></p> 
        <div class="login-form">
            <form method="post" class="sign-in-htm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="group">
                    <label for="usuario" class="label">Nombre del usuario</label>
                    <input id="usuario" name="usuario" type="text" class="input" placeholder="Usuario" required>
                </div>
                <div class="group">
                    <label for="pass" class="label">Contraseña</label>
                    <input id="pass" name="password" type="password" class="input" data-type="password" placeholder="Contraseña" required>
                </div>
                <div class="group">
                    <input type="submit" class="button" value="Entrar">
                </div>
                <div class="group" style="text-align: center; margin-top: 20px;">
                    <a href="index.html" class="button" style="background-color: #6c757d; text-decoration: none; display: inline-block; width: auto; padding: 10px 20px;">← Volver al mapa</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
