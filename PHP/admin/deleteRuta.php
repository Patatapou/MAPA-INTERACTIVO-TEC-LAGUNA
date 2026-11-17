<?php
// ==========================================
// ENDPOINT: ELIMINAR RUTA
// ==========================================
// POST index.php?p=deleteRuta
// Requiere sesión de administrador y token CSRF.
//
// Borrado físico de la definición de la ruta. No modifica la red de caminos
// ni otras rutas que compartan segmentos.

require_once __DIR__ . '/_api-rutas.php';

requerirAdmin();
apiRutasExigirMetodo('POST');
apiRutasExigirCsrf();

try {
    $id = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
    if ($id === '') {
        apiRutasError(400, 'Falta el ID de la ruta.');
    }

    $servicio = new RouteService();
    $resultado = $servicio->eliminar($id, apiRutasUsuario());

    apiRutasResponder([
        'success' => true,
        'message' => 'Ruta eliminada.',
        'id' => $resultado['id'],
        'nombre' => $resultado['nombre'],
    ]);
} catch (Throwable $e) {
    apiRutasCapturar($e);
}
