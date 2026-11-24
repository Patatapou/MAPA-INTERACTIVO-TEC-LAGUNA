<?php
require_once __DIR__ . '/_api-usuarios.php';
requerirAdmin();
apiUsuariosExigirMetodo('POST');
apiUsuariosExigirCsrf();
try {
    $entrada = apiUsuariosEntrada();
    $id = trim((string) ($entrada['id'] ?? ''));
    if ($id === '') {
        apiUsuariosError(400, 'Se requiere el ID del usuario.');
    }
    $resultado = (new UserService())->resetPassword($id, apiUsuariosActor());
    apiUsuariosResponder([
        'success' => true,
        'message' => 'Contraseña restablecida.',
        'usuario' => $resultado['usuario'],
        'password_temporal' => $resultado['password_temporal'],
    ]);
} catch (Throwable $e) {
    apiUsuariosCapturar($e);
}
