<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/services/UserService.php';

$fallos = [];
function comprobarUsuario(bool $condicion, string $mensaje): void
{
    global $fallos;
    if (!$condicion) $fallos[] = $mensaje;
}

$tmp = sys_get_temp_dir() . '/itl-users-service-' . bin2hex(random_bytes(6));
mkdir($tmp, 0777, true);
$ruta = $tmp . '/users.json';
$ahora = date('c');
file_put_contents($ruta, json_encode([
    'admin_001' => [
        'id' => 'admin_001',
        'username' => 'admin',
        'email' => null,
        'password_hash' => password_hash('ContraseñaActual#2026', PASSWORD_DEFAULT),
        'role' => 'administrador',
        'status' => 'activo',
        'must_change_password' => false,
        'password_changed_at' => $ahora,
        'session_version' => 1,
        'created_by' => null,
        'created_at' => $ahora,
        'updated_at' => $ahora,
        'last_login_at' => null,
        'schema_version' => 2,
    ],
], JSON_PRETTY_PRINT));

$servicio = new UserService($tmp);
$actor = ['id' => 'admin_001', 'username' => 'admin'];

try {
    $alta = $servicio->crear(['username' => 'prueba.uno', 'email' => 'prueba@example.com'], $actor);
    comprobarUsuario(isset($alta['password_temporal']) && strlen($alta['password_temporal']) === 16, 'El alta debe devolver una temporal de 16 caracteres.');
    comprobarUsuario($alta['usuario']['username'] === 'prueba.uno', 'El alta debe conservar el usuario normalizado.');
    $id = $alta['usuario']['id'];

    try {
        $servicio->crear(['username' => 'PRUEBA.UNO', 'email' => null], $actor);
        comprobarUsuario(false, 'El duplicado sin distinguir mayúsculas debe rechazarse.');
    } catch (UserServiceException $e) {
        comprobarUsuario($e->statusCode() === 409, 'El duplicado debe ser conflicto 409 (recibido ' . $e->statusCode() . ': ' . $e->getMessage() . ').');
    }

    try {
        $servicio->crear(['username' => 'ab'], ['id' => 'prueba', 'username' => 'prueba']);
        comprobarUsuario(false, 'Se esperaba que el usuario inválido fuera rechazado.');
    } catch (UserServiceException $e) {
        comprobarUsuario($e->statusCode() === 400, 'El usuario corto debe ser inválido.');
    }

    try {
        $servicio->cambiarPassword($id, [
            'password_actual' => 'incorrecta',
            'password_nueva' => 'NuevaContraseña#2026',
            'password_confirmacion' => 'NuevaContraseña#2026',
        ], ['id' => $id, 'username' => 'prueba.uno']);
        comprobarUsuario(false, 'La contraseña actual incorrecta debe rechazarse.');
    } catch (UserServiceException $e) {
        comprobarUsuario($e->statusCode() === 403, 'La contraseña actual incorrecta debe dar 403.');
    }

    try {
        $servicio->setEstado('admin_001', 'inactivo', $actor);
        comprobarUsuario(false, 'No se debe desactivar al último administrador.');
    } catch (UserServiceException $e) {
        comprobarUsuario($e->statusCode() === 400, 'El último administrador debe estar protegido.');
    }

    try {
        $servicio->setEstado('admin_001', 'inactivo', $actor);
        comprobarUsuario(false, 'No se debe permitir auto-desactivarse.');
    } catch (UserServiceException $e) {
        comprobarUsuario(true, '');
    }

    $reset = $servicio->resetPassword($id, $actor);
    comprobarUsuario(isset($reset['password_temporal']) && $reset['usuario']['must_change_password'] === true, 'Restablecer debe exigir cambio obligatorio.');

    $repo = new UserRepository($tmp);
    $usuario = $repo->buscarPorId($id);
    comprobarUsuario((int) ($usuario['session_version'] ?? 0) >= 2, 'Restablecer debe incrementar session_version.');

    $estado = $servicio->setEstado($id, 'inactivo', $actor);
    comprobarUsuario($estado['status'] === 'inactivo', 'La desactivación debe persistir.');
    $eliminado = $servicio->eliminar($id, $actor);
    comprobarUsuario($eliminado, 'Debe eliminarse una cuenta inactiva.');

    try {
        $servicio->eliminar('admin_001', $actor);
        comprobarUsuario(false, 'No se debe eliminar al único administrador activo.');
    } catch (UserServiceException $e) {
        comprobarUsuario(true, '');
    }

    $json = json_encode($servicio->listar(), JSON_UNESCAPED_UNICODE);
    comprobarUsuario(strpos((string) $json, 'password_hash') === false, 'Ninguna salida debe incluir password_hash.');
} catch (Throwable $e) {
    $fallos[] = 'Excepción no esperada: ' . $e->getMessage();
} finally {
    foreach (glob($tmp . '/*') ?: [] as $archivo) {
        unlink($archivo);
    }
    @rmdir($tmp);
}

if ($fallos !== []) {
    foreach ($fallos as $fallo) fwrite(STDERR, "FALLO: {$fallo}\n");
    exit(1);
}
echo "OK: pruebas de servicio de usuarios\n";
