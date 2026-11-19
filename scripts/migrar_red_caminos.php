<?php
// ==========================================
// MIGRACIÓN Y NORMALIZACIÓN DE LA RED DE CAMINOS
// ==========================================
// Convierte `caminitos.geojson` (un único MultiLineString con 235 partes)
// en una FeatureCollection de LineString con ID estable y conectividad
// explícita por extremos compartidos.
//
// Uso:
//   php scripts/migrar_red_caminos.php --dry-run
//   php scripts/migrar_red_caminos.php
//
// Reglas (aprobadas en D1 y D11):
//  - Los nodos canónicos son los 238 nodos que produce `buildGraph()`.
//  - Cada parte se corta en los nodos que proyectan sobre ella (≤2.5 m).
//  - Los extremos se ajustan a la coordenada canónica del nodo.
//  - Un feature ya migrado conserva su `properties.id` (idempotencia).
//  - El ID es inmutable: solo se genera al crear o dividir un segmento.
//
// El script NO usa el servidor web ni escribe en la base de datos privada.
// Solo escribe `caminitos.geojson` (y su respaldo `.bak`).

declare(strict_types=1);

require_once __DIR__ . '/../backend/bootstrap.php';

$raiz = ROOT_PATH;
$rutaRed = PUBLIC_DATA_PATH . '/caminitos.geojson';
$dryRun = in_array('--dry-run', $argv, true);
$rutaSalida = null;
$rutaEntrada = $rutaRed;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--salida=')) {
        $rutaSalida = substr($arg, strlen('--salida='));
    }
    if (str_starts_with($arg, '--entrada=')) {
        $rutaEntrada = substr($arg, strlen('--entrada='));
    }
}

// Cuando se analiza otro archivo (por ejemplo el respaldo) nunca se escribe.
$entradaReal = realpath($rutaEntrada);
$redReal = realpath($rutaRed);
$soloLectura = $entradaReal === false || $redReal === false || $entradaReal !== $redReal;

$TOLERANCIA_SNAPPING = 2.5;   // igual que buildGraph()
$TOLERANCIA_CORTE = 0.05;     // metros a lo largo de la polilínea
$TOLERANCIA_DUPLICADO = 0.001;
$TOLERANCIA_TRAMO_CORTO = 0.5; // tramos menores no pueden ser aristas del grafo
$RADIO_TIERRA = 6371000.0;

// ==========================================
// GEOMETRÍA (réplica exacta de js/pathfinding.js)
// ==========================================

function distanciaMetros(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    global $RADIO_TIERRA;
    $dLat = ($lat2 - $lat1) * M_PI / 180;
    $dLng = ($lng2 - $lng1) * M_PI / 180;
    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos($lat1 * M_PI / 180) * cos($lat2 * M_PI / 180) *
        sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $RADIO_TIERRA * $c;
}

/**
 * Proyección de un punto sobre un segmento.
 *
 * @return array{dist: float, t: float}
 */
function projectPointOnSegment(
    float $pLat,
    float $pLng,
    float $uLat,
    float $uLng,
    float $vLat,
    float $vLng
): array {
    $dy = ($vLat - $uLat) * 111139;
    $dx = ($vLng - $uLng) * 111139 * cos($uLat * M_PI / 180);

    $py = ($pLat - $uLat) * 111139;
    $px = ($pLng - $uLng) * 111139 * cos($uLat * M_PI / 180);

    $lenSq = $dx * $dx + $dy * $dy;
    if ($lenSq == 0.0) {
        return ['dist' => distanciaMetros($pLat, $pLng, $uLat, $uLng), 't' => 0.0];
    }

    $t = ($px * $dx + $py * $dy) / $lenSq;
    $t = max(0.0, min(1.0, $t));

    $projX = $dx * $t;
    $projY = $dy * $t;
    $dist = sqrt(($px - $projX) * ($px - $projX) + ($py - $projY) * ($py - $projY));

    return ['dist' => $dist, 't' => $t];
}

function claveNodo(float $lat, float $lng): string
{
    return sprintf('%.6f,%.6f', $lat, $lng);
}

/**
 * Busca el primer nodo registrado dentro de la tolerancia.
 * Reproduce findSnappedNode() de js/pathfinding.js (devuelve el primero, no el más cercano).
 *
 * @param array<int, string> $claves
 */
function findSnappedNode(float $lat, float $lng, array $claves, float $tolerancia): ?string
{
    foreach ($claves as $clave) {
        [$eLat, $eLng] = array_map('floatval', explode(',', $clave));
        if (distanciaMetros($lat, $lng, $eLat, $eLng) <= $tolerancia) {
            return $clave;
        }
    }
    return null;
}

/**
 * Extrae las partes (LineString) de un GeoJSON y si ya están migradas.
 *
 * "Migrada" significa que la feature ya tiene un ID `seg-xxxxxxxx`. Se usa
 * solo para decidir si un micro-tramo se conserva (idempotencia) o se
 * descarta (ruido del MultiLineString original).
 *
 * @return array<int, array{coords: array<int, array{0: float, 1: float}>, migrado: bool}>
 */
function extraerPartesConMeta(array $geojson): array
{
    $partes = [];
    foreach (($geojson['features'] ?? []) as $feature) {
        $geom = $feature['geometry'] ?? null;
        if (!is_array($geom)) {
            continue;
        }
        $idPrevio = $feature['properties']['id'] ?? ($feature['id'] ?? null);
        $migrado = is_string($idPrevio) && preg_match('/^seg-[a-f0-9]{8}(-\d{2})?$/', $idPrevio) === 1;

        if (($geom['type'] ?? '') === 'LineString') {
            $partes[] = ['coords' => $geom['coordinates'], 'migrado' => $migrado];
        } elseif (($geom['type'] ?? '') === 'MultiLineString') {
            foreach ($geom['coordinates'] as $linea) {
                $partes[] = ['coords' => $linea, 'migrado' => $migrado];
            }
        }
    }
    return $partes;
}

/**
 * Extrae las partes (LineString) de un GeoJSON.
 *
 * @return array<int, array<int, array{0: float, 1: float}>>
 */
function extraerPartes(array $geojson): array
{
    return array_map(
        static fn(array $parte): array => $parte['coords'],
        extraerPartesConMeta($geojson)
    );
}

// ==========================================
// NODOS CANÓNICOS (réplica de buildGraph paso 1)
// ==========================================

/**
 * @param array<int, array<int, array{0: float, 1: float}>> $partes
 * @return array<int, string> Lista ordenada de claves de nodo
 */
function calcularNodosCanonicos(array $partes, float $tolerancia): array
{
    $claves = [];
    foreach ($partes as $coords) {
        $total = count($coords);
        for ($i = 0; $i < $total - 1; $i++) {
            $p1 = $coords[$i];
            $p2 = $coords[$i + 1];

            $k1 = findSnappedNode((float) $p1[1], (float) $p1[0], $claves, $tolerancia);
            if ($k1 === null) {
                $k1 = claveNodo((float) $p1[1], (float) $p1[0]);
                $claves[] = $k1;
            }

            $k2 = findSnappedNode((float) $p2[1], (float) $p2[0], $claves, $tolerancia);
            if ($k2 === null) {
                $clave2 = claveNodo((float) $p2[1], (float) $p2[0]);
                $claves[] = $clave2;
            }
        }
    }
    return $claves;
}

/**
 * Construye el grafo (nodos → vecinos) a partir de features LineString.
 * Réplica de buildGraph() para verificación.
 *
 * @return array<string, array<string, float>>
 */
function construirGrafo(array $geojson, float $tolerancia): array
{
    $partes = extraerPartes($geojson);
    $claves = calcularNodosCanonicos($partes, $tolerancia);

    $segmentos = [];
    foreach ($partes as $coords) {
        $total = count($coords);
        for ($i = 0; $i < $total - 1; $i++) {
            $segmentos[] = [
                (float) $coords[$i][1],
                (float) $coords[$i][0],
                (float) $coords[$i + 1][1],
                (float) $coords[$i + 1][0],
            ];
        }
    }

    $grafo = [];
    foreach ($segmentos as [$uLat, $uLng, $vLat, $vLng]) {
        $cortes = [];
        foreach ($claves as $clave) {
            [$nLat, $nLng] = array_map('floatval', explode(',', $clave));
            $proy = projectPointOnSegment($nLat, $nLng, $uLat, $uLng, $vLat, $vLng);
            if ($proy['dist'] <= $tolerancia) {
                $cortes[] = ['key' => $clave, 't' => $proy['t']];
            }
        }
        usort($cortes, static fn($a, $b) => $a['t'] <=> $b['t']);

        $cuenta = count($cortes);
        for ($i = 0; $i < $cuenta - 1; $i++) {
            $k1 = $cortes[$i]['key'];
            $k2 = $cortes[$i + 1]['key'];
            if ($k1 === $k2) {
                continue;
            }
            [$lat1, $lng1] = array_map('floatval', explode(',', $k1));
            [$lat2, $lng2] = array_map('floatval', explode(',', $k2));
            $dist = distanciaMetros($lat1, $lng1, $lat2, $lng2);
            $grafo[$k1][$k2] = $dist;
            $grafo[$k2][$k1] = $dist;
        }
    }
    return $grafo;
}

/**
 * @param array<string, array<string, float>> $grafo
 * @return array{nodos:int, aristas:int, grado1:int, componentes:int, distribucion:array<int,int>}
 */
function metricasGrafo(array $grafo): array
{
    $nodos = array_keys($grafo);
    $aristas = [];
    $grado1 = 0;
    $distribucion = [];

    foreach ($grafo as $nodo => $vecinos) {
        $grado = count($vecinos);
        if ($grado === 1) {
            $grado1++;
        }
        $distribucion[$grado] = ($distribucion[$grado] ?? 0) + 1;
        foreach (array_keys($vecinos) as $vecino) {
            $par = strcmp($nodo, $vecino) < 0 ? "$nodo|$vecino" : "$vecino|$nodo";
            $aristas[$par] = true;
        }
    }

    $visitados = [];
    $componentes = 0;
    foreach ($nodos as $inicio) {
        if (isset($visitados[$inicio])) {
            continue;
        }
        $componentes++;
        $pila = [$inicio];
        $visitados[$inicio] = true;
        while ($pila) {
            $actual = array_pop($pila);
            foreach (array_keys($grafo[$actual] ?? []) as $vecino) {
                if (!isset($visitados[$vecino])) {
                    $visitados[$vecino] = true;
                    $pila[] = $vecino;
                }
            }
        }
    }

    ksort($distribucion);

    return [
        'nodos' => count($nodos),
        'aristas' => count($aristas),
        'grado1' => $grado1,
        'componentes' => $componentes,
        'distribucion' => $distribucion,
    ];
}

/**
 * Conjunto de aristas expresado en claves canónicas.
 *
 * Cada nodo del grafo se identifica con el nodo canónico más próximo (≤2.5 m).
 * Sirve para comparar la topología antes y después de migrar sin depender de
 * cómo se hayan nombrado los nodos.
 *
 * @param array<string, array<string, float>> $grafo
 * @param array<int, string> $clavesCanonicas
 * @return array<string, true>
 */
function aristasCanonicas(array $grafo, array $clavesCanonicas, float $tolerancia): array
{
    $identidad = [];
    foreach (array_keys($grafo) as $nodo) {
        [$lat, $lng] = array_map('floatval', explode(',', $nodo));
        $mejor = null;
        $mejorDist = INF;
        foreach ($clavesCanonicas as $clave) {
            [$nLat, $nLng] = array_map('floatval', explode(',', $clave));
            $dist = distanciaMetros($lat, $lng, $nLat, $nLng);
            if ($dist < $mejorDist) {
                $mejorDist = $dist;
                $mejor = $clave;
            }
        }
        $identidad[$nodo] = ($mejor !== null && $mejorDist <= $tolerancia) ? $mejor : $nodo;
    }

    $aristas = [];
    foreach ($grafo as $nodo => $vecinos) {
        $a = $identidad[$nodo];
        foreach (array_keys($vecinos) as $vecino) {
            $b = $identidad[$vecino] ?? $vecino;
            if ($a === $b) {
                continue;
            }
            $aristas[strcmp($a, $b) < 0 ? "$a|$b" : "$b|$a"] = true;
        }
    }

    return $aristas;
}

// ==========================================
// NORMALIZACIÓN
// ==========================================

/**
 * Punto de la polilínea en una posición (metros desde el inicio).
 *
 * Los cortes se materializan con este punto y NO con la coordenada canónica
 * del nodo: si se usara la del nodo, el vértice quedaría fuera de la línea
 * original y la geometría se "quebraría", alterando las proyecciones del
 * resto de nodos (y por tanto la topología del grafo).
 *
 * @param array<int, array{0: float, 1: float}> $coords
 * @param array<int, float> $acum
 * @return array{0: float, 1: float}
 */
function puntoEnPosicion(array $coords, array $acum, float $pos): array
{
    $total = count($coords);
    if ($total === 0) {
        return [0.0, 0.0];
    }
    if ($pos <= 0.0) {
        return $coords[0];
    }
    if ($pos >= $acum[$total - 1]) {
        return $coords[$total - 1];
    }

    for ($i = 0; $i < $total - 1; $i++) {
        if ($pos > $acum[$i + 1]) {
            continue;
        }
        $largo = $acum[$i + 1] - $acum[$i];
        if ($largo <= 0.0) {
            return $coords[$i];
        }
        // Vértice exacto: conservar la coordenada original sin interpolar.
        if (abs($pos - $acum[$i]) < 1e-9) {
            return $coords[$i];
        }
        if (abs($pos - $acum[$i + 1]) < 1e-9) {
            return $coords[$i + 1];
        }
        $f = ($pos - $acum[$i]) / $largo;
        return [
            $coords[$i][0] + ($coords[$i + 1][0] - $coords[$i][0]) * $f,
            $coords[$i][1] + ($coords[$i + 1][1] - $coords[$i][1]) * $f,
        ];
    }

    return $coords[$total - 1];
}

/**
 * @param array<int, array<int, array{0: float, 1: float}>> $partes
 * @param array<int, string> $clavesCanonicas
 * @param array<int, bool> $partesMigradas Si la parte ya tenía ID `seg-`
 * @return array<int, array<int, array{0: float, 1: float}>>
 */
function normalizarPartes(array $partes, array $clavesCanonicas, array $partesMigradas = []): array
{
    global $TOLERANCIA_SNAPPING, $TOLERANCIA_CORTE, $TOLERANCIA_DUPLICADO, $TOLERANCIA_TRAMO_CORTO;

    // Caché de vértice original → nodo canónico más cercano (≤2.5 m)
    $cacheVertice = [];

    $canonizarVertice = static function (array $punto) use (&$cacheVertice, $clavesCanonicas, $TOLERANCIA_SNAPPING): string {
        $lat = (float) $punto[1];
        $lng = (float) $punto[0];
        $claveOriginal = claveNodo($lat, $lng);
        if (isset($cacheVertice[$claveOriginal])) {
            return $cacheVertice[$claveOriginal];
        }

        $mejor = null;
        $mejorDist = INF;
        foreach ($clavesCanonicas as $clave) {
            [$nLat, $nLng] = array_map('floatval', explode(',', $clave));
            $dist = distanciaMetros($lat, $lng, $nLat, $nLng);
            if ($dist < $mejorDist) {
                $mejorDist = $dist;
                $mejor = $clave;
            }
        }

        $resultado = ($mejor !== null && $mejorDist <= $TOLERANCIA_SNAPPING)
            ? $mejor
            : $claveOriginal;

        $cacheVertice[$claveOriginal] = $resultado;
        return $resultado;
    };

    $salida = [];

    foreach ($partes as $indiceParte => $coords) {
        $total = count($coords);
        if ($total < 2) {
            continue;
        }

        // Distancias acumuladas
        $acum = [0.0];
        for ($i = 1; $i < $total; $i++) {
            $acum[$i] = $acum[$i - 1] + distanciaMetros(
                (float) $coords[$i - 1][1],
                (float) $coords[$i - 1][0],
                (float) $coords[$i][1],
                (float) $coords[$i][0]
            );
        }
        $longitud = $acum[$total - 1];

        // Un tramo de menos de 0.5 m no puede representar una arista del grafo:
        // sus dos extremos caen en el mismo nodo (tolerancia de 2.5 m). Reglas:
        //  - Red original (sin ID `seg-`): se descarta. Son micro-tramos de
        //    dibujo del MultiLineString y no aportan conectividad.
        //  - Red ya migrada (con ID `seg-`): se conserva tal cual. Algunos
        //    tramos cortos los genera la propia normalización al cortar; si se
        //    descartaran, re-ejecutar la migración no sería idempotente.
        if ($longitud < $TOLERANCIA_TRAMO_CORTO) {
            if ($partesMigradas[$indiceParte] ?? false) {
                $salida[] = $coords;
            }
            continue;
        }

        $cortes = [];

        $claveInicio = $canonizarVertice($coords[0]);
        $claveFin = $canonizarVertice($coords[$total - 1]);
        $cortes[] = ['pos' => 0.0, 'key' => $claveInicio];
        $cortes[] = ['pos' => $longitud, 'key' => $claveFin];

        for ($i = 0; $i < $total - 1; $i++) {
            $u = $coords[$i];
            $v = $coords[$i + 1];
            $longitudSegmento = $acum[$i + 1] - $acum[$i];

            foreach ($clavesCanonicas as $clave) {
                [$nLat, $nLng] = array_map('floatval', explode(',', $clave));
                $proy = projectPointOnSegment(
                    $nLat,
                    $nLng,
                    (float) $u[1],
                    (float) $u[0],
                    (float) $v[1],
                    (float) $v[0]
                );
                if ($proy['dist'] <= $TOLERANCIA_SNAPPING) {
                    $cortes[] = [
                        'pos' => $acum[$i] + $proy['t'] * $longitudSegmento,
                        'key' => $clave,
                    ];
                }
            }
        }

        usort($cortes, static fn($a, $b) => $a['pos'] <=> $b['pos']);

        // Deduplicar por clave y por posición (se conserva el último dentro de 0.05 m).
        // Nota: dos cortes con la misma clave en posiciones distintas se colapsan;
        // conservarlos duplicaba cortes por proyecciones repetidas del mismo nodo.
        $unicos = [];
        foreach ($cortes as $corte) {
            if (!$unicos) {
                $unicos[] = $corte;
                continue;
            }
            $ultimo = $unicos[count($unicos) - 1];
            if ($corte['key'] === $ultimo['key']) {
                continue;
            }
            if (abs($corte['pos'] - $ultimo['pos']) <= $TOLERANCIA_CORTE) {
                $unicos[count($unicos) - 1] = $corte;
            } else {
                $unicos[] = $corte;
            }
        }

        $cuenta = count($unicos);
        for ($i = 0; $i < $cuenta - 1; $i++) {
            $a = $unicos[$i];
            $b = $unicos[$i + 1];
            if (($b['pos'] - $a['pos']) < $TOLERANCIA_CORTE) {
                continue;
            }

            $sub = [puntoEnPosicion($coords, $acum, $a['pos'])];
            for ($j = 0; $j < $total; $j++) {
                if ($acum[$j] > $a['pos'] + $TOLERANCIA_CORTE && $acum[$j] < $b['pos'] - $TOLERANCIA_CORTE) {
                    $sub[] = $coords[$j];
                }
            }
            $sub[] = puntoEnPosicion($coords, $acum, $b['pos']);

            // Eliminar puntos consecutivos duplicados
            $limpio = [];
            foreach ($sub as $punto) {
                if (!$limpio) {
                    $limpio[] = $punto;
                    continue;
                }
                $previo = $limpio[count($limpio) - 1];
                $dist = distanciaMetros((float) $previo[1], (float) $previo[0], (float) $punto[1], (float) $punto[0]);
                if ($dist > $TOLERANCIA_DUPLICADO) {
                    $limpio[] = $punto;
                }
            }

            if (count($limpio) >= 2) {
                $salida[] = $limpio;
            }
        }
    }

    return $salida;
}

// ==========================================
// IDENTIDAD DE SEGMENTO
// ==========================================

/**
 * ID determinista a partir de la geometría normalizada.
 *
 * @param array<int, array{0: float, 1: float}> $coords
 */
function generarIdSegmento(array $coords): string
{
    $partes = [];
    foreach ($coords as $punto) {
        $partes[] = sprintf('%.6f,%.6f', (float) $punto[1], (float) $punto[0]);
    }
    return 'seg-' . substr(sha1(implode(';', $partes)), 0, 8);
}

// ==========================================
// EJECUCIÓN
// ==========================================

if (!file_exists($rutaEntrada)) {
    fwrite(STDERR, "No existe el archivo de entrada: {$rutaEntrada}\n");
    exit(1);
}

$contenido = file_get_contents($rutaEntrada);
if ($contenido === false) {
    fwrite(STDERR, "No se pudo leer {$rutaEntrada}\n");
    exit(1);
}

$geojson = json_decode($contenido, true);
if (!is_array($geojson) || !isset($geojson['features'])) {
    fwrite(STDERR, "El archivo de entrada no es un GeoJSON válido\n");
    exit(1);
}

$partesConMeta = extraerPartesConMeta($geojson);
$partes = array_map(static fn(array $parte): array => $parte['coords'], $partesConMeta);
$partesMigradas = array_map(static fn(array $parte): bool => $parte['migrado'], $partesConMeta);
$verticesOriginales = array_sum(array_map('count', $partes));

echo "=== Migración de la red de caminos ===\n";
echo 'Entrada: ' . basename($rutaEntrada) . "\n";
echo 'Modo: ' . ($dryRun ? "DRY-RUN (no escribe)\n" : ($soloLectura ? "SOLO LECTURA (entrada distinta a la red)\n" : "ESCRITURA\n"));
echo "Partes originales: " . count($partes) . "\n";
echo "Vértices originales: {$verticesOriginales}\n";

$grafoAntes = construirGrafo($geojson, $TOLERANCIA_SNAPPING);
$metricasAntes = metricasGrafo($grafoAntes);
echo "Grafo antes: nodos={$metricasAntes['nodos']} aristas={$metricasAntes['aristas']} "
    . "grado1={$metricasAntes['grado1']} componentes={$metricasAntes['componentes']}\n";

$clavesCanonicas = calcularNodosCanonicos($partes, $TOLERANCIA_SNAPPING);
echo "Nodos canónicos: " . count($clavesCanonicas) . "\n";

$partesNormalizadas = normalizarPartes($partes, $clavesCanonicas, $partesMigradas);
$verticesNormalizados = array_sum(array_map('count', $partesNormalizadas));

echo "Partes normalizadas: " . count($partesNormalizadas) . "\n";
echo "Vértices normalizados: {$verticesNormalizados}\n";
echo "Partes divididas: " . (count($partesNormalizadas) - count($partes)) . " netas\n";

// Conservar IDs y propiedades existentes (idempotencia real: volver a ejecutar
// la migración sobre una red ya migrada no debe cambiar IDs ni `creado_en`).
$previosPorFirma = [];
foreach ($geojson['features'] as $feature) {
    $idPrevio = $feature['properties']['id'] ?? ($feature['id'] ?? null);
    if (!is_string($idPrevio) || preg_match('/^seg-[a-f0-9]{8}(-\d{2})?$/', $idPrevio) !== 1) {
        continue;
    }
    $geom = $feature['geometry'] ?? null;
    if (!is_array($geom) || ($geom['type'] ?? '') !== 'LineString') {
        continue;
    }
    $firma = generarIdSegmento($geom['coordinates']);
    $previosPorFirma[$firma][] = $feature;
}

$consumidos = [];
$usados = [];
$features = [];
$ahora = date('c');
$indice = 0;

foreach ($partesNormalizadas as $coords) {
    $indice++;
    $idBase = generarIdSegmento($coords);

    $previo = null;
    if (!empty($previosPorFirma[$idBase])) {
        $posicion = $consumidos[$idBase] ?? 0;
        if (isset($previosPorFirma[$idBase][$posicion])) {
            $previo = $previosPorFirma[$idBase][$posicion];
            $consumidos[$idBase] = $posicion + 1;
        }
    }

    $id = $idBase;
    if ($previo !== null) {
        $idPrevio = $previo['properties']['id'] ?? ($previo['id'] ?? null);
        if (is_string($idPrevio) && $idPrevio !== '') {
            $id = $idPrevio;
        }
    }

    if (isset($usados[$id])) {
        $sufijo = 1;
        while (isset($usados[sprintf('%s-%02d', $idBase, $sufijo)])) {
            $sufijo++;
        }
        $id = sprintf('%s-%02d', $idBase, $sufijo);
    }
    $usados[$id] = true;

    // Propiedades: se reutilizan las previas para no alterar `creado_en`.
    $props = is_array($previo['properties'] ?? null) ? $previo['properties'] : [];
    $props['id'] = $id;
    if (!isset($props['nombre']) || !is_string($props['nombre']) || $props['nombre'] === '') {
        $props['nombre'] = sprintf('Segmento %03d', $indice);
    }
    if (!isset($props['tipo'])) {
        $props['tipo'] = 'peatonal';
    }
    if (!isset($props['accesible'])) {
        $props['accesible'] = true;
    }
    if (!isset($props['estado'])) {
        $props['estado'] = 'activa';
    }
    if (!isset($props['creado_en'])) {
        $props['creado_en'] = $ahora;
    }
    if (!isset($props['actualizado_en'])) {
        $props['actualizado_en'] = $props['creado_en'];
    }

    $features[] = [
        'type' => 'Feature',
        'id' => $id,
        'properties' => $props,
        'geometry' => [
            'type' => 'LineString',
            'coordinates' => $coords,
        ],
    ];
}

$nuevo = [
    'type' => 'FeatureCollection',
    'name' => $geojson['name'] ?? 'caminitos2',
    'crs' => $geojson['crs'] ?? [
        'type' => 'name',
        'properties' => ['name' => 'urn:ogc:def:crs:OGC:1.3:CRS84'],
    ],
    'features' => $features,
];

$grafoDespues = construirGrafo($nuevo, $TOLERANCIA_SNAPPING);
$metricasDespues = metricasGrafo($grafoDespues);

echo "Grafo después: nodos={$metricasDespues['nodos']} aristas={$metricasDespues['aristas']} "
    . "grado1={$metricasDespues['grado1']} componentes={$metricasDespues['componentes']}\n";
echo 'Distribución de grado: ' . json_encode($metricasDespues['distribucion']) . "\n";

if ($rutaSalida !== null) {
    $jsonDepuracion = json_encode($nuevo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonDepuracion === false || file_put_contents($rutaSalida, $jsonDepuracion) === false) {
        fwrite(STDERR, "No se pudo escribir la salida de depuración: {$rutaSalida}\n");
        exit(1);
    }
    echo "Salida de depuración: {$rutaSalida}\n";
}

$errores = [];
if ($metricasDespues['nodos'] !== $metricasAntes['nodos']) {
    $errores[] = 'cambió el número de nodos';
}
if ($metricasDespues['aristas'] !== $metricasAntes['aristas']) {
    $errores[] = 'cambió el número de aristas';
}
if ($metricasDespues['grado1'] !== $metricasAntes['grado1']) {
    $errores[] = 'cambió el número de nodos de grado 1';
}
if ($metricasDespues['componentes'] !== $metricasAntes['componentes']) {
    $errores[] = 'cambió el número de componentes';
}
if ($metricasDespues['distribucion'] !== $metricasAntes['distribucion']) {
    $errores[] = 'cambió la distribución de grado';
}

// Verificación fuerte: mismo conjunto de aristas en identidad canónica.
$aristasAntes = aristasCanonicas($grafoAntes, $clavesCanonicas, $TOLERANCIA_SNAPPING);
$aristasDespues = aristasCanonicas($grafoDespues, $clavesCanonicas, $TOLERANCIA_SNAPPING);
$faltantes = array_diff_key($aristasAntes, $aristasDespues);
$sobrantes = array_diff_key($aristasDespues, $aristasAntes);

if ($faltantes || $sobrantes) {
    $errores[] = sprintf(
        'cambió el conjunto de aristas (%d faltantes, %d sobrantes)',
        count($faltantes),
        count($sobrantes)
    );
    foreach (array_slice(array_keys($faltantes), 0, 5) as $arista) {
        fwrite(STDERR, "   falta: {$arista}\n");
    }
    foreach (array_slice(array_keys($sobrantes), 0, 5) as $arista) {
        fwrite(STDERR, "   sobra: {$arista}\n");
    }
}

if ($errores) {
    fwrite(STDERR, "\nFALLO: la normalización alteró la huella del grafo.\n");
    foreach ($errores as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    fwrite(STDERR, "No se escribió ningún archivo.\n");
    exit(1);
}

$json = json_encode($nuevo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "No se pudo serializar el GeoJSON normalizado.\n");
    exit(1);
}

if ($dryRun) {
    echo "\nDRY-RUN: no se escribió ningún archivo de la red.\n";
    exit($errores ? 1 : 0);
}

if ($soloLectura) {
    echo "\nSOLO LECTURA: la entrada no es la red activa; no se escribió nada.\n";
    exit($errores ? 1 : 0);
}

// Respaldo (no sobrescribir un respaldo previo)
$respaldo = $rutaRed . '.bak';
if (file_exists($respaldo)) {
    $respaldo = $rutaRed . '.bak-' . date('YmdHis');
}
if (!copy($rutaRed, $respaldo)) {
    fwrite(STDERR, "No se pudo crear el respaldo {$respaldo}\n");
    exit(1);
}
echo "Respaldo: " . basename($respaldo) . "\n";

// Escritura atómica
$temporal = $rutaRed . '.tmp.' . uniqid('', true);
if (file_put_contents($temporal, $json) === false) {
    fwrite(STDERR, "No se pudo escribir el archivo temporal.\n");
    exit(1);
}

$verificacion = json_decode((string) file_get_contents($temporal), true);
if (!is_array($verificacion) || count($verificacion['features'] ?? []) !== count($features)) {
    @unlink($temporal);
    fwrite(STDERR, "La verificación del archivo temporal falló.\n");
    exit(1);
}

if (!rename($temporal, $rutaRed)) {
    @unlink($temporal);
    fwrite(STDERR, "No se pudo reemplazar caminitos.geojson.\n");
    exit(1);
}

echo "Escrito: caminitos.geojson (" . count($features) . " features)\n";

// Auditoría best-effort del evento de migración (apartado 12.6).
$rutaAuditoria = RUNTIME_PATH . '/audit-log.jsonl';
if (is_dir(dirname($rutaAuditoria))) {
    $evento = [
        'event' => 'red_migrada',
        'features_antes' => count($geojson['features'] ?? []),
        'partes_antes' => count($partes),
        'features_despues' => count($features),
        'hash_antes' => (string) @hash_file('sha256', $respaldo),
        'hash_despues' => (string) @hash_file('sha256', $rutaRed),
        'nodos_antes' => $metricasAntes['nodos'],
        'aristas_antes' => $metricasAntes['aristas'],
        'nodos_despues' => $metricasDespues['nodos'],
        'aristas_despues' => $metricasDespues['aristas'],
        'user_id' => 'cli',
        'username' => 'migracion-red',
        'ip' => '-',
        'timestamp' => date('c'),
    ];
    $linea = json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($linea !== false) {
        @file_put_contents($rutaAuditoria, $linea . "\n", FILE_APPEND | LOCK_EX);
    }
}

echo "OK: migración completada.\n";
exit(0);
