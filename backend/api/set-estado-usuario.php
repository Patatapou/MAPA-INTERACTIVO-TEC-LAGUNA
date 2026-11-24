<?php
require_once __DIR__ . '/_api-usuarios.php';
requerirAdmin();
apiUsuariosExigirMetodo('POST');
apiUsuariosExigirCsrf();
try {
    $entrada = apiUsuariosEntrada();
    $id = trim((string) ($entrada['id'] ?? ''));
    $estado = trim((string) ($entrada['status'] ?? $entrada['estado'] ?? ''));
    if ($id === '' || $estado === '') {
        apiUsuariosError(400, 'Se requieren id y estado.');
    }
    $usuario = (new UserService())->setEstado($id, $estado, apiUsuariosActor());
    apiUsuariosResponder(['success' => true, 'message' => 'Estado actualizado.', 'usuario' => $usuario]);
} catch (Throwable $e) {
    apiUsuariosCapturar($e);
}
