<?php
require_once __DIR__ . '/_api-usuarios.php';
requerirAdmin();
apiUsuariosExigirMetodo('POST');
apiUsuariosExigirCsrf();
try {
    $entrada = apiUsuariosEntrada();
    $servicio = new UserService();
    if (isset($entrada['id']) && trim($entrada['id']) !== '') {
        $usuario = $servicio->actualizarEmail(trim($entrada['id']), $entrada, apiUsuariosActor());
        apiUsuariosResponder(['success' => true, 'message' => 'Correo actualizado.', 'usuario' => $usuario]);
    }
    $resultado = $servicio->crear($entrada, apiUsuariosActor());
    apiUsuariosResponder([
        'success' => true,
        'message' => 'Usuario creado.',
        'usuario' => $resultado['usuario'],
        'password_temporal' => $resultado['password_temporal'],
    ]);
} catch (Throwable $e) {
    apiUsuariosCapturar($e);
}
