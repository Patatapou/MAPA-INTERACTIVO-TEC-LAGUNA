<?php
// ==========================================
// MIGRACIÓN DE LA RED A FORMATO V2
// ==========================================
// La red ya está normalizada (313 LineString con ID `seg-*`). Este script
// añade los metadatos que exige el editor de red:
//
//   schema_version = 2
//   revision       = 1 (si falta)
//   next_seq       = contador de IDs nuevos (nunca reutiliza)
//
// No toca geometrías, IDs ni propiedades. Antes de escribir compara la
// huella del grafo (nodos, aristas, grado 1, componentes y conjunto de
// aristas) y aborta si algo cambia.
//
// Uso:
//   php scripts/migrar_red_v2.php            # simulación (no escribe)
//   php scripts/migrar_red_v2.php --apply    # escribe y respalda
//   php scripts/migrar_red_v2.php --entrada=otro.geojson --salida=tmp.geojson --apply
//
// Es idempotente: ejecutarlo sobre una red v2 no modifica el archivo.

declare(strict_types=1);

require_once __DIR__ . '/../backend/bootstrap.php';
require_once BACKEND_PATH . '/services/NetworkGraph.php';

$raiz = ROOT_PATH;
$rutaRed = PUBLIC_DATA_PATH . '/caminitos.geojson';
$entrada = $rutaRed;
$salida = null;
$aplicar = in_array('--apply', $argv, true);

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--entrada=')) {
        $entrada = substr($arg, strlen('--entrada='));
    }
    if (str_starts_with($arg, '--salida=')) {
        $salida = substr($arg, strlen('--salida='));
    }
}

$entradaReal = realpath($entrada);
$redReal = realpath($rutaRed);
$esRedActiva = $entradaReal !== false && $redReal !== false && $entradaReal === $redReal;
$destino = $salida ?? ($esRedActiva ? $rutaRed : null);
$escribir = $aplicar && $destino !== null;

if (!file_exists($entrada)) {
    fwrite(STDERR, "No existe el archivo de entrada: {$entrada}\n");
    exit(1);
}

$contenido = file_get_contents($entrada);
$geojson = $contenido !== false ? json_decode($contenido, true) : null;
if (!is_array($geojson) || ($geojson['type'] ?? '') !== 'FeatureCollection') {
    fwrite(STDERR, "El archivo de entrada no es una FeatureCollection válida.\n");
    exit(1);
}

$features = $geojson['features'] ?? null;
if (!is_array($features) || $features === []) {
    fwrite(STDERR, "La red de entrada no tiene features.\n");
    exit(1);
}

$erroresEstructura = [];
$ids = [];
foreach ($features as $i => $feature) {
    $geom = $feature['geometry'] ?? null;
    if (!is_array($geom) || ($geom['type'] ?? '') !== 'LineString') {
        $erroresEstructura[] = "features[{$i}]: no es LineString.";
        continue;
    }
    $coords = $geom['coordinates'] ?? null;
    if (!is_array($coords) || count($coords) < 2) {
        $erroresEstructura[] = "features[{$i}]: menos de 2 vértices.";
    }
    $id = $feature['properties']['id'] ?? ($feature['id'] ?? null);
    if (!is_string($id) || preg_match('/^seg-(?:[a-f0-9]{8}(?:-\d{2})?|\d{6})$/', $id) !== 1) {
        $erroresEstructura[] = "features[{$i}]: ID inválido.";
        continue;
    }
    if (isset($ids[$id])) {
        $erroresEstructura[] = "ID duplicado: {$id}.";
    }
    $ids[$id] = true;
}

if ($erroresEstructura !== []) {
    fwrite(STDERR, "La red de entrada no se puede migrar:\n");
    foreach (array_slice($erroresEstructura, 0, 10) as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

// No se permite un schema_version desconocido (podría ser de otra versión).
if (isset($geojson['schema_version']) && (int) $geojson['schema_version'] !== 2) {
    fwrite(STDERR, 'schema_version desconocida: ' . (string) $geojson['schema_version'] . "\n");
    exit(1);
}

echo "=== Migración de la red a formato v2 ===\n";
echo 'Entrada: ' . basename($entrada) . "\n";
echo 'Modo: ' . ($escribir ? "ESCRITURA\n" : "SIMULACIÓN (no escribe)\n");

$grafoAntes = NetworkGraph::construir($geojson);
$metricasAntes = NetworkGraph::metricas($grafoAntes);
$aristasAntes = NetworkGraph::aristas($grafoAntes);

echo sprintf(
    "Grafo antes: nodos=%d aristas=%d grado1=%d componentes=%d\n",
    $metricasAntes['nodos'],
    $metricasAntes['aristas'],
    $metricasAntes['grado1'],
    $metricasAntes['componentes']
);

$nuevo = $geojson;
$nuevo['schema_version'] = 2;
$nuevo['revision'] = max(1, (int) ($geojson['revision'] ?? 1));
$nuevo['next_seq'] = max(
    (int) ($geojson['next_seq'] ?? 1),
    count($features) + 1
);

$grafoDespues = NetworkGraph::construir($nuevo);
$metricasDespues = NetworkGraph::metricas($grafoDespues);
$aristasDespues = NetworkGraph::aristas($grafoDespues);

echo sprintf(
    "Grafo después: nodos=%d aristas=%d grado1=%d componentes=%d\n",
    $metricasDespues['nodos'],
    $metricasDespues['aristas'],
    $metricasDespues['grado1'],
    $metricasDespues['componentes']
);
echo 'schema_version=' . $nuevo['schema_version']
    . ' revision=' . $nuevo['revision']
    . ' next_seq=' . $nuevo['next_seq'] . "\n";

$errores = [];
foreach (['nodos', 'aristas', 'grado1', 'componentes'] as $clave) {
    if ($metricasAntes[$clave] !== $metricasDespues[$clave]) {
        $errores[] = "cambió {$clave}: {$metricasAntes[$clave]} → {$metricasDespues[$clave]}";
    }
}

$faltantes = array_diff_key($aristasAntes, $aristasDespues);
$sobrantes = array_diff_key($aristasDespues, $aristasAntes);
if ($faltantes !== [] || $sobrantes !== []) {
    $errores[] = sprintf(
        'cambió el conjunto de aristas (%d faltantes, %d sobrantes)',
        count($faltantes),
        count($sobrantes)
    );
}

if ($errores !== []) {
    fwrite(STDERR, "\nFALLO: la migración alteraría el grafo; no se escribió nada.\n");
    foreach ($errores as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

$yaV2 = (int) ($geojson['schema_version'] ?? 0) === 2
    && isset($geojson['revision'], $geojson['next_seq'])
    && (int) $geojson['revision'] === $nuevo['revision']
    && (int) $geojson['next_seq'] === $nuevo['next_seq'];

if ($yaV2) {
    echo "\nLa red ya está en formato v2; no hay cambios que aplicar.\n";
    exit(0);
}

if (!$escribir) {
    echo "\nSIMULACIÓN: no se escribió ningún archivo. Usa --apply para aplicar.\n";
    exit(0);
}

$json = json_encode($nuevo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, 'No se pudo serializar la red: ' . json_last_error_msg() . "\n");
    exit(1);
}

$respaldo = null;
if ($esRedActiva) {
    $dirRespaldos = BACKUPS_PATH . '/caminitos';
    if (!is_dir($dirRespaldos) && !@mkdir($dirRespaldos, 0775, true) && !is_dir($dirRespaldos)) {
        fwrite(STDERR, "No se pudo crear el directorio de respaldos.\n");
        exit(1);
    }
    $respaldo = $dirRespaldos . '/caminitos-v1-' . date('Ymd-His') . '.geojson';
    if (!@copy($entrada, $respaldo)) {
        fwrite(STDERR, "No se pudo crear el respaldo v1.\n");
        exit(1);
    }
    echo 'Respaldo v1: ' . basename($respaldo) . "\n";
}

$temporal = $destino . '.tmp.' . uniqid('', true);
if (@file_put_contents($temporal, $json) === false) {
    fwrite(STDERR, "No se pudo escribir el archivo temporal.\n");
    exit(1);
}

$verificacion = json_decode((string) @file_get_contents($temporal), true);
if (!is_array($verificacion)
    || (int) ($verificacion['schema_version'] ?? 0) !== 2
    || count($verificacion['features'] ?? []) !== count($features)) {
    @unlink($temporal);
    fwrite(STDERR, "La verificación del archivo temporal falló.\n");
    exit(1);
}

if (!@rename($temporal, $destino)) {
    @unlink($temporal);
    fwrite(STDERR, "No se pudo reemplazar el archivo de destino.\n");
    exit(1);
}

echo 'Escrito: ' . basename($destino) . ' (' . count($features) . " features)\n";

// Auditoría best-effort (mismo formato que el resto del proyecto).
if ($esRedActiva) {
    $rutaAuditoria = RUNTIME_PATH . '/audit-log.jsonl';
    if (is_dir(dirname($rutaAuditoria))) {
        $evento = [
            'event' => 'red_migrada_v2',
            'features' => count($features),
            'schema_version' => 2,
            'revision' => $nuevo['revision'],
            'next_seq' => $nuevo['next_seq'],
            'nodos' => $metricasDespues['nodos'],
            'aristas' => $metricasDespues['aristas'],
            'componentes' => $metricasDespues['componentes'],
            'respaldo' => $respaldo !== null ? basename($respaldo) : null,
            'user_id' => 'cli',
            'username' => 'migracion-red-v2',
            'ip' => '-',
            'timestamp' => date('c'),
        ];
        $linea = json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($linea !== false) {
            @file_put_contents($rutaAuditoria, $linea . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}

echo "OK: red en formato v2.\n";
exit(0);
