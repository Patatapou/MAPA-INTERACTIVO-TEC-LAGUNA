<?php
// ==========================================
// ENDPOINT: LISTAR / OBTENER RUTAS
// ==========================================
// GET index.php?p=getRutas          → listado hidratado (resumen)
// GET index.php?p=getRutas&id=<id>  → detalle completo
//
// Requiere sesión de administrador. No requiere CSRF (solo lectura).

require_once __DIR__ . '/_api-rutas.php';

requerirAdmin();
apiRutasExigirMetodo('GET');

try {
    $servicio = new RouteService();
    $id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

    if ($id !== '') {
        if (preg_match('/^ruta-[a-f0-9]{8}$/', $id) !== 1) {
            apiRutasError(400, 'ID de ruta inválido.', ['Formato esperado: ruta-xxxxxxxx.']);
        }

        $ruta = $servicio->obtener($id);
        apiRutasResponder([
            'success' => true,
            'ruta' => $ruta,
        ]);
    }

    $listado = $servicio->listar();
    apiRutasResponder([
        'success' => true,
        'schema_version' => $listado['schema_version'],
        'updated_at' => $listado['updated_at'],
        'total' => $listado['total'],
        'rutas' => $listado['rutas'],
    ]);
} catch (Throwable $e) {
    apiRutasCapturar($e);
}
