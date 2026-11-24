<?php
declare(strict_types=1);

require_once __DIR__ . '/_pruebas.php';
require_once __DIR__ . '/../backend/repositories/BuildingGeometryRepository.php';
require_once __DIR__ . '/../backend/repositories/PathNetworkRepository.php';

echo "=== Respaldos privados de datos públicos ===\n";

$dir = crearDirTemporal('respaldos-privados');
$publico = $dir . DIRECTORY_SEPARATOR . 'public';
$respaldos = $dir . DIRECTORY_SEPARATOR . 'backups';
mkdir($publico, 0775, true);
mkdir($respaldos, 0775, true);

try {
    $geoTec = $publico . DIRECTORY_SEPARATOR . 'GeoTec.js';
    $geoTecOriginal = "var datosTec = {\"type\":\"FeatureCollection\",\"features\":[]};\n";
    file_put_contents($geoTec, $geoTecOriginal);

    $geometrias = new BuildingGeometryRepository($publico, $respaldos);
    afirmar(
        'GeoTec.js se actualiza correctamente',
        $geometrias->escribirGeometrias(['type' => 'FeatureCollection', 'features' => []])
    );
    afirmar(
        'GeoTec.js conserva el respaldo anterior en el directorio privado',
        file_get_contents($respaldos . DIRECTORY_SEPARATOR . 'GeoTec.js.bak') === $geoTecOriginal
    );
    afirmar(
        'no se crea GeoTec.js.bak dentro del directorio público',
        !file_exists($geoTec . '.bak')
    );

    $redPath = $publico . DIRECTORY_SEPARATOR . 'caminitos.geojson';
    $redOriginal = [
        'type' => 'FeatureCollection',
        'features' => [[
            'type' => 'Feature',
            'properties' => ['id' => 'seg-000001'],
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => [[-103.4360, 25.5300], [-103.4361, 25.5301]],
            ],
        ]],
    ];
    file_put_contents($redPath, json_encode($redOriginal));

    $redRepo = new PathNetworkRepository($redPath, $respaldos);
    $guardado = $redRepo->guardar($redOriginal);
    afirmar(
        'caminitos.geojson conserva el respaldo anterior fuera del directorio público',
        file_get_contents($respaldos . DIRECTORY_SEPARATOR . 'caminitos.geojson.bak') === json_encode($redOriginal)
    );
    afirmar(
        'no se crea caminitos.geojson.bak junto al archivo público',
        !file_exists($redPath . '.bak')
    );
    afirmarIgual('el resultado informa el nombre del respaldo', 'caminitos.geojson.bak', $guardado['respaldo'] ?? null);
} finally {
    borrarDir($dir);
}

salirConResumen('Respaldos privados');
