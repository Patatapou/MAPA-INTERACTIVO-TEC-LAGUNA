<?php
// ==========================================
// ENDPOINT: RED DE CAMINOS
// ==========================================
// GET index.php?p=getRedCaminos
// Requiere sesión de administrador.
//
// Devuelve los segmentos con ID y metadatos, más un resumen topológico.
// El editor de rutas usa esta respuesta para encadenar segmentos sin
// descargarse el GeoJSON completo.

require_once __DIR__ . '/_api-rutas.php';

requerirAdmin();
apiRutasExigirMetodo('GET');

try {
    $red = new PathNetworkService();
    $indice = $red->indice();
    $topologia = new NetworkTopologyService($red);
    $validacion = $topologia->validarRed();

    $config = (new RouteService())->config();

    apiRutasResponder([
        'success' => true,
        'total' => count($indice),
        'segmentos' => array_values($indice),
        'topologia' => $validacion['metricas'],
        'advertencias' => $validacion['advertencias'],
        'errores' => $validacion['errores'],
        'config' => [
            'tolerancia_continuidad_m' => (float) ($config['tolerancia_continuidad_m'] ?? 2.5),
            'max_segmentos_por_ruta' => (int) ($config['max_segmentos_por_ruta'] ?? 500),
            'max_salto_enlace_m' => (float) ($config['max_salto_enlace_m'] ?? 80),
        ],
    ]);
} catch (Throwable $e) {
    apiRutasCapturar($e);
}
