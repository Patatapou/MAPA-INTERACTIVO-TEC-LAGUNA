<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../auth/auth_middleware.php';
require_once __DIR__ . '/../auth/rate_limit.php';
require_once __DIR__ . '/../services/UserService.php';
requerirAdmin();

$csrfToken = generarTokenCSRF();
$error = '';
$mensaje = '';
$idSesion = (string) ($_SESSION['admin_user']['id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfPost = $_POST['csrf_token'] ?? '';
    if (!is_string($csrfPost) || !validarTokenCSRF($csrfPost)) {
        $error = 'Token de seguridad inválido.';
    } elseif (!registrarIntentoFallido(5, 3600)) {
        $error = 'Demasiados intentos fallidos. Intente nuevamente más tarde.';
    } else {
        try {
            $servicio = new UserService();
            $usuario = $servicio->cambiarPassword($idSesion, [
                'password_actual' => $_POST['password_actual'] ?? null,
                'password_nueva' => $_POST['password_nueva'] ?? null,
                'password_confirmacion' => $_POST['password_confirmacion'] ?? null,
            ], [
                'id' => $idSesion,
                'username' => (string) ($_SESSION['admin_user']['username'] ?? ''),
            ]);
            resetIntentosFallidos();
            session_regenerate_id(true);
            $_SESSION['admin_user']['must_change_password'] = false;
            $_SESSION['admin_user']['session_version'] = (int) ($usuario['session_version'] ?? 1);
            renovarTokenCSRF();
            $csrfToken = generarTokenCSRF();
            $mensaje = 'Contraseña cambiada correctamente.';
            header('Location: index.php?p=editar');
            exit;
        } catch (UserServiceException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Cambio de contraseña: ' . $e->getMessage());
            $error = 'No se pudo cambiar la contraseña.';
        }
    }
    $csrfToken = generarTokenCSRF();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cambiar contraseña - ITL Laguna</title>
<link rel="stylesheet" href="css/admin/panel.css">
<link rel="stylesheet" href="css/admin/usuarios.css">
</head>
<body>
<main class="usuarios-container">
    <div class="admin-header">
        <h1>Cambiar mi contraseña</h1>
    </div>
    <section class="usuarios-panel" aria-label="Formulario de contraseña">
        <?php if ($error !== ''): ?><p class="usuarios-alert usuarios-alert-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <?php if ($mensaje !== ''): ?><p class="usuarios-alert usuarios-alert-success"><?= htmlspecialchars($mensaje) ?></p><?php endif; ?>
        <form method="post" class="usuarios-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <label for="password_actual">Contraseña actual</label>
            <input id="password_actual" name="password_actual" type="password" autocomplete="current-password" required>
            <label for="password_nueva">Contraseña nueva</label>
            <input id="password_nueva" name="password_nueva" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
            <label for="password_confirmacion">Confirmar contraseña nueva</label>
            <input id="password_confirmacion" name="password_confirmacion" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
            <div class="usuarios-form-actions">
                <button type="submit" class="btn btn-primary">Cambiar contraseña</button>
                <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php?p=editar'">Cancelar</button>
            </div>
        </form>
    </section>
</main>
</body>
</html>
