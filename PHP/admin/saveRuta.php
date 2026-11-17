<?php
// ==========================================
// ENDPOINT: CREAR / ACTUALIZAR RUTA
// ==========================================
// POST index.php?p=saveRuta
// Requiere sesión de administrador y token CSRF.
//
// Acepta formulario (FormData / URLSearchParams). El campo `segmentos` puede
// llegar como JSON (campo oculto) o como arreglo de índices.
//
// El servidor NO acepta geometría del cliente: reconstruye la polilínea desde
// `caminitos.geojson`.

require_once __DIR__ . '/_api-rutas.php';

requerirAdmin();
apiRutasExigirMetodo('POST');
apiRutasExigirCsrf();

try {
    $entrada = $_POST;

    // Normalizar `segmentos` si llega serializado como JSON.
    if (isset($entrada['segmentos']) && is_string($entrada['segmentos'])) {
        $decodificado = json_decode($entrada['segmentos'], true);
        if (!is_array($decodificado)) {
            apiRutasError(400, 'El campo segmentos no es un JSON válido.');
        }
        $entrada['segmentos'] = $decodificado;
    }

    // Origen y destino pueden llegar serializados o como campos sueltos.
    foreach (['origen', 'destino'] as $campo) {
        if (isset($entrada[$campo]) && is_string($entrada[$campo])) {
            $decodificado = json_decode($entrada[$campo], true);
            if (is_array($decodificado)) {
                $entrada[$campo] = $decodificado;
            }
        }
    }

    // Se descarta cualquier geometría enviada por el cliente.
    unset($entrada['geometria'], $entrada['geometry'], $entrada['nodos'], $entrada['distancia_m']);

    $esEdicion = isset($entrada['id']) && trim((string) $entrada['id']) !== '';

    $servicio = new RouteService();
    $ruta = $servicio->guardar($entrada, apiRutasUsuario());

    apiRutasResponder([
        'success' => true,
        'message' => $esEdicion ? 'Ruta actualizada.' : 'Ruta creada.',
        'ruta' => $ruta,
    ]);
} catch (Throwable $e) {
    apiRutasCapturar($e);
}
