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
    (new UserService())->eliminar($id, apiUsuariosActor());
    apiUsuariosResponder(['success' => true, 'message' => 'Usuario eliminado.']);
} catch (Throwable $e) {
    apiUsuariosCapturar($e);
}
