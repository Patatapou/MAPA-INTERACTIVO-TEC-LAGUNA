<?php
// ==========================================
// HELPER DE PARIDAD: GRAFO EN PHP
// ==========================================
// Lee de STDIN un JSON `{ "redes": [ FeatureCollection, ... ] }` y devuelve
// una huella por red. Lo usa `tests/red/paridad-grafo.mjs` para comparar la
// implementación PHP (`NetworkGraph`) con `buildGraph()` de JavaScript.
//
// Uso interno (no es un endpoint web):
//   echo '{"redes":[...]}' | php tests/red/_grafo-php.php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/services/NetworkGraph.php';

$contenido = stream_get_contents(STDIN);
$peticion = $contenido !== false ? json_decode($contenido, true) : null;

if (!is_array($peticion) || !isset($peticion['redes']) || !is_array($peticion['redes'])) {
    fwrite(STDERR, "Entrada inválida: se esperaba {\"redes\":[...]}.\n");
    exit(1);
}

$salida = [];

foreach ($peticion['redes'] as $red) {
    if (!is_array($red)) {
        $salida[] = null;
        continue;
    }

    $grafo = NetworkGraph::construir($red);
    $nodos = array_keys($grafo);
    sort($nodos, SORT_STRING);

    $aristas = [];
    $distancias = [];
    foreach (NetworkGraph::aristas($grafo) as $clave => $arista) {
        $aristas[] = (string) $clave;
        $distancias[$clave] = round((float) $arista['distancia'], 6);
    }
    sort($aristas, SORT_STRING);
    ksort($distancias, SORT_STRING);

    $metricas = NetworkGraph::metricas($grafo);

    $salida[] = [
        'nodos' => $metricas['nodos'],
        'aristas' => $metricas['aristas'],
        'grado1' => $metricas['grado1'],
        'componentes' => $metricas['componentes'],
        'listaNodos' => $nodos,
        'listaAristas' => $aristas,
        'distancias' => $distancias,
        'huella' => sha1(json_encode([$nodos, $aristas, $distancias])),
    ];
}

echo json_encode(['grafo' => $salida], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
