<?php
require_once __DIR__ . '/_api-usuarios.php';
requerirAdmin();
apiUsuariosExigirMetodo('GET');
try {
    $servicio = new UserService();
    apiUsuariosResponder(['success' => true, 'usuarios' => $servicio->listar()]);
} catch (Throwable $e) {
    apiUsuariosCapturar($e);
}
