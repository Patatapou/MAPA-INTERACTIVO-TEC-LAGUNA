<?php
// ==========================================
// PRUEBAS DE INTEGRIDAD DE LA RED (E5.1)
// ==========================================
// Verifica el repositorio del editor de red:
//   - lectura fail-closed (archivo ausente, corrupto, formato v1),
//   - guardado con respaldo y `rename` atómico,
//   - conflicto de revisión (409) sin sobrescribir,
//   - rechazo de IDs provisionales en el repositorio,
//   - retención de 30 respaldos.
//
// Todo ocurre en directorios temporales: no toca `caminitos.geojson` real.
//
// Uso: php tests/red/integridad-red.php

declare(strict_types=1);

require_once __DIR__ . '/../_pruebas.php';
require_once __DIR__ . '/../../PHP/repositories/NetworkRepository.php';

echo "=== Integridad de la red de caminos ===\n";

// ---------- Ayudas ----------

/**
 * @param array<int, array> $features
 * @return array
 */
function redV2(array $features, int $revision = 1, int $nextSeq = 2): array
{
    return [
        'type' => 'FeatureCollection',
        'name' => 'RedPrueba',
        'schema_version' => 2,
        'revision' => $revision,
        'next_seq' => $nextSeq,
        'features' => $features,
    ];
}

/**
 * @return array
 */
function segmentoRed(
    string $id,
    float $aLat = 25.5300,
    float $aLng = -103.4360,
    float $bLat = 25.5301,
    float $bLng = -103.4361
): array {
    return [
        'type' => 'Feature',
        'id' => $id,
        'properties' => ['id' => $id],
        'geometry' => [
            'type' => 'LineString',
            'coordinates' => [[$aLng, $aLat], [$bLng, $bLat]],
        ],
    ];
}

/**
 * @return array{repo: NetworkRepository, archivo: string, respaldos: string, dir: string}
 */
function repoNuevo(string $prefijo, ?array $contenido = null): array
{
    $dir = crearDirTemporal($prefijo);
    $archivo = $dir . '/caminitos.geojson';
    $respaldos = $dir . '/backups';

    if ($contenido !== null) {
        file_put_contents($archivo, json_encode($contenido, JSON_PRETTY_PRINT));
    }

    return [
        'repo' => new NetworkRepository($archivo, $respaldos),
        'archivo' => $archivo,
        'respaldos' => $respaldos,
        'dir' => $dir,
    ];
}

// ---------- 1. Archivo ausente ----------
$ctx = repoNuevo('red-ausente');
try {
    $excepcion = null;
    try {
        $ctx['repo']->leerRed();
    } catch (RuntimeException $e) {
        $excepcion = $e;
    }
    afirmar('1. Archivo ausente: leerRed() lanza excepción', $excepcion instanceof RuntimeException);
    afirmar(
        '1. Archivo ausente: no crea archivo vacío',
        !file_exists($ctx['archivo'])
    );
} finally {
    borrarDir($ctx['dir']);
}

// ---------- 2. JSON corrupto ----------
$ctx = repoNuevo('red-corrupto');
file_put_contents($ctx['archivo'], '{"type":"FeatureCollection","features":[');
$original = (string) file_get_contents($ctx['archivo']);
try {
    $excepcion = null;
    try {
        $ctx['repo']->leerRed();
    } catch (RuntimeException $e) {
        $excepcion = $e;
    }
    afirmar('2. JSON corrupto: leerRed() lanza excepción', $excepcion instanceof RuntimeException);
    afirmar(
        '2. JSON corrupto: el archivo queda intacto',
        (string) file_get_contents($ctx['archivo']) === $original
    );
} finally {
    borrarDir($ctx['dir']);
}

// ---------- 3. Formato v1 (sin metadatos v2) ----------
$ctx = repoNuevo('red-v1', [
    'type' => 'FeatureCollection',
    'features' => [segmentoRed('seg-000001')],
]);
try {
    $excepcion = null;
    try {
        $ctx['repo']->leerRed();
    } catch (RuntimeException $e) {
        $excepcion = $e;
    }
    afirmar('3. Formato v1: leerRed() lanza excepción', $excepcion instanceof RuntimeException);
    afirmar(
        '3. Formato v1: el mensaje pide migrar a v2',
        $excepcion !== null && str_contains($excepcion->getMessage(), 'formato v2'),
        $excepcion ? $excepcion->getMessage() : 'sin excepción'
    );
} finally {
    borrarDir($ctx['dir']);
}

// ---------- 4. Guardado correcto con respaldo ----------
$ctx = repoNuevo('red-guardar', redV2([segmentoRed('seg-000001')], 1, 2));
try {
    $resultado = $ctx['repo']->guardarRevision(redV2([segmentoRed('seg-000001')], 1, 2), 1);
    afirmarIgual('4. Guardado: revisión incrementada', 2, $resultado['revision']);
    afirmar(
        '4. Guardado: se creó el respaldo de la revisión anterior',
        is_string($resultado['respaldo']) && file_exists($resultado['respaldo'])
    );

    $guardado = json_decode((string) file_get_contents($ctx['archivo']), true);
    afirmarIgual('4. Guardado: revisión persistida', 2, $guardado['revision'] ?? null);
    afirmarIgual('4. Guardado: schema_version', NetworkRepository::SCHEMA_VERSION, $guardado['schema_version'] ?? null);
    afirmarIgual('4. Guardado: segmentos', 1, count($guardado['features'] ?? []));

    $temporales = glob($ctx['archivo'] . '.tmp.*') ?: [];
    afirmarIgual('4. Guardado: sin archivos temporales huérfanos', 0, count($temporales));
    afirmar('4. Guardado: archivo de bloqueo presente', file_exists($ctx['archivo'] . '.lock'));
} finally {
    borrarDir($ctx['dir']);
}

// ---------- 5. Revisión obsoleta (409) ----------
$ctx = repoNuevo('red-conflicto', redV2([segmentoRed('seg-000001')], 7, 2));
$antes = (string) file_get_contents($ctx['archivo']);
try {
    $excepcion = null;
    try {
        $ctx['repo']->guardarRevision(redV2([segmentoRed('seg-000001'), segmentoRed('seg-000002')], 7, 3), 6);
    } catch (RevisionObsoletaException $e) {
        $excepcion = $e;
    }

    afirmar('5. Conflicto: lanza RevisionObsoletaException', $excepcion instanceof RevisionObsoletaException);
    afirmarIgual('5. Conflicto: informa la revisión vigente', 7, $excepcion ? $excepcion->revisionActual() : null);
    afirmar(
        '5. Conflicto: no se sobrescribe el archivo',
        (string) file_get_contents($ctx['archivo']) === $antes
    );

    // La misma propuesta con la revisión correcta sí se guarda.
    $resultado = $ctx['repo']->guardarRevision(
        redV2([segmentoRed('seg-000001'), segmentoRed('seg-000002')], 7, 3),
        7
    );
    afirmarIgual('5. Conflicto: la revisión correcta guarda', 8, $resultado['revision']);
} finally {
    borrarDir($ctx['dir']);
}

// ---------- 6. IDs provisionales ----------
$ctx = repoNuevo('red-tmp', redV2([segmentoRed('seg-000001')], 1, 2));
$antes = (string) file_get_contents($ctx['archivo']);
try {
    $excepcion = null;
    try {
        $ctx['repo']->guardarRevision(redV2([segmentoRed('tmp-nuevo')], 1, 2), 1);
    } catch (RuntimeException $e) {
        $excepcion = $e;
    }
    afirmar('6. IDs tmp-: el repositorio los rechaza', $excepcion instanceof RuntimeException);
    afirmar(
        '6. IDs tmp-: el archivo no cambia',
        (string) file_get_contents($ctx['archivo']) === $antes
    );
} finally {
    borrarDir($ctx['dir']);
}

// ---------- 7. Retención de respaldos ----------
$ctx = repoNuevo('red-retencion', redV2([segmentoRed('seg-000001')], 1, 2));
try {
    if (!is_dir($ctx['respaldos'])) {
        mkdir($ctx['respaldos'], 0775, true);
    }
    for ($i = 0; $i < 35; $i++) {
        $ruta = sprintf(
            '%s/caminitos-%s-rev%d.geojson',
            $ctx['respaldos'],
            date('Ymd-His', time() - $i),
            $i + 1
        );
        file_put_contents($ruta, json_encode(redV2([segmentoRed('seg-000001')], $i + 1, 2)));
        touch($ruta, time() - $i);
    }
    afirmarIgual(
        '7. Retención: punto de partida con 35 respaldos',
        35,
        count(glob($ctx['respaldos'] . '/caminitos-*.geojson') ?: [])
    );

    $ctx['repo']->guardarRevision(redV2([segmentoRed('seg-000001')], 1, 2), 1);
    $quedan = count(glob($ctx['respaldos'] . '/caminitos-*.geojson') ?: []);
    afirmar('7. Retención: no se superan los 30 respaldos', $quedan <= NetworkRepository::MAX_RESPALDOS, (string) $quedan);
} finally {
    borrarDir($ctx['dir']);
}

salirConResumen('integridad-red');
