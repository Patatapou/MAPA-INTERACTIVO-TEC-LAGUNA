<?php
// ==========================================
// GRAFO DE LA RED DE CAMINOS (PHP)
// ==========================================
// Réplica en PHP de `buildGraph()` de `js/pathfinding.js`. El grafo del mapa
// público se deriva en el navegador; el servidor necesita la misma huella
// para validar que un guardado no rompe la alcanzabilidad de los edificios
// (error E08 del plan de gestión de la red).
//
// Reglas que se reproducen exactamente:
//  - Tolerancia de snapping de 2,5 m.
//  - Clave de nodo = lat.toFixed(6) + "," + lng.toFixed(6).
//  - Snapping "al primero registrado", no "al más cercano".
//  - División de cada segmento por todos los nodos que proyectan a ≤2,5 m.
//
// Este servicio NO modifica la red: solo calcula.

declare(strict_types=1);

final class NetworkGraph
{
    public const TOLERANCIA_SNAPPING_M = 2.5;
    public const RADIO_TIERRA_M = 6371000.0;

    // ==========================================
    // GEOMETRÍA
    // ==========================================

    public static function distanciaMetros(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $dLat = ($lat2 - $lat1) * M_PI / 180;
        $dLng = ($lng2 - $lng1) * M_PI / 180;
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos($lat1 * M_PI / 180) * cos($lat2 * M_PI / 180)
            * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return self::RADIO_TIERRA_M * $c;
    }

    /**
     * Clave de nodo con el mismo formato que usa `buildGraph()` en JS.
     */
    public static function claveNodo(float $lat, float $lng): string
    {
        return sprintf('%.6f,%.6f', $lat, $lng);
    }

    /**
     * Proyección perpendicular de un punto sobre el segmento u-v.
     *
     * @return array{dist: float, t: float}
     */
    public static function proyectarPuntoEnSegmento(
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
            return [
                'dist' => self::distanciaMetros($pLat, $pLng, $uLat, $uLng),
                't' => 0.0,
            ];
        }

        $t = ($px * $dx + $py * $dy) / $lenSq;
        $t = max(0.0, min(1.0, $t));

        $projX = $dx * $t;
        $projY = $dy * $t;
        $dist = sqrt(($px - $projX) * ($px - $projX) + ($py - $projY) * ($py - $projY));

        return ['dist' => $dist, 't' => $t];
    }

    /**
     * Primer nodo registrado dentro de la tolerancia (comportamiento de JS).
     *
     * @param array<int, string> $claves
     */
    public static function buscarNodoSnap(
        float $lat,
        float $lng,
        array $claves,
        float $tolerancia = self::TOLERANCIA_SNAPPING_M
    ): ?string {
        foreach ($claves as $clave) {
            [$eLat, $eLng] = array_map('floatval', explode(',', $clave));
            if (self::distanciaMetros($lat, $lng, $eLat, $eLng) <= $tolerancia) {
                return $clave;
            }
        }
        return null;
    }

    // ==========================================
    // CONSTRUCCIÓN DEL GRAFO
    // ==========================================

    /**
     * Construir el grafo desde una FeatureCollection (LineString o MultiLineString).
     *
     * @param array $geojson
     * @return array<string, array<string, float>>
     */
    public static function construir(
        array $geojson,
        float $tolerancia = self::TOLERANCIA_SNAPPING_M
    ): array {
        $grafo = [];
        $claves = [];
        $segmentos = [];

        $features = isset($geojson['features']) && is_array($geojson['features'])
            ? $geojson['features']
            : [];

        // Paso 1: extraer segmentos y registrar vértices con snapping.
        foreach ($features as $feature) {
            $geom = $feature['geometry'] ?? null;
            if (!is_array($geom)) {
                continue;
            }

            $lineas = [];
            $tipo = $geom['type'] ?? '';
            if ($tipo === 'LineString') {
                $lineas[] = $geom['coordinates'] ?? [];
            } elseif ($tipo === 'MultiLineString') {
                $lineas = $geom['coordinates'] ?? [];
            }

            foreach ($lineas as $coords) {
                if (!is_array($coords)) {
                    continue;
                }
                $total = count($coords);
                for ($i = 0; $i < $total - 1; $i++) {
                    $p1 = $coords[$i];
                    $p2 = $coords[$i + 1];
                    if (!is_array($p1) || !is_array($p2) || count($p1) < 2 || count($p2) < 2) {
                        continue;
                    }

                    $lat1 = (float) $p1[1];
                    $lng1 = (float) $p1[0];
                    $lat2 = (float) $p2[1];
                    $lng2 = (float) $p2[0];

                    $segmentos[] = [$lat1, $lng1, $lat2, $lng2];

                    $k1 = self::buscarNodoSnap($lat1, $lng1, $claves, $tolerancia);
                    if ($k1 === null) {
                        $k1 = self::claveNodo($lat1, $lng1);
                        $claves[] = $k1;
                    }

                    $k2 = self::buscarNodoSnap($lat2, $lng2, $claves, $tolerancia);
                    if ($k2 === null) {
                        $k2 = self::claveNodo($lat2, $lng2);
                        $claves[] = $k2;
                    }
                }
            }
        }

        // Paso 2: dividir cada segmento por los nodos que lo tocan.
        foreach ($segmentos as [$uLat, $uLng, $vLat, $vLng]) {
            $cortes = [];
            foreach ($claves as $clave) {
                [$nLat, $nLng] = array_map('floatval', explode(',', $clave));
                $proy = self::proyectarPuntoEnSegmento($nLat, $nLng, $uLat, $uLng, $vLat, $vLng);
                if ($proy['dist'] <= $tolerancia) {
                    $cortes[] = ['key' => $clave, 't' => $proy['t']];
                }
            }

            usort($cortes, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);

            $cuenta = count($cortes);
            for ($i = 0; $i < $cuenta - 1; $i++) {
                $k1 = $cortes[$i]['key'];
                $k2 = $cortes[$i + 1]['key'];
                if ($k1 === $k2) {
                    continue;
                }

                [$lat1, $lng1] = array_map('floatval', explode(',', $k1));
                [$lat2, $lng2] = array_map('floatval', explode(',', $k2));
                $dist = self::distanciaMetros($lat1, $lng1, $lat2, $lng2);

                if (!isset($grafo[$k1])) {
                    $grafo[$k1] = [];
                }
                if (!isset($grafo[$k2])) {
                    $grafo[$k2] = [];
                }

                $grafo[$k1][$k2] = $dist;
                $grafo[$k2][$k1] = $dist;
            }
        }

        return $grafo;
    }

    // ==========================================
    // CONSULTAS
    // ==========================================

    /**
     * @param array<string, array<string, float>> $grafo
     * @param array{lat: float|int, lng: float|int}|string $punto
     */
    public static function nodoMasCercano(array $grafo, array|string $punto): ?string
    {
        if (is_string($punto)) {
            [$lat, $lng] = array_map('floatval', explode(',', $punto));
        } else {
            $lat = (float) ($punto['lat'] ?? 0);
            $lng = (float) ($punto['lng'] ?? 0);
        }

        $mejor = null;
        $mejorDist = INF;

        foreach (array_keys($grafo) as $clave) {
            [$nLat, $nLng] = array_map('floatval', explode(',', $clave));
            $dist = self::distanciaMetros($lat, $lng, $nLat, $nLng);
            if ($dist < $mejorDist) {
                $mejorDist = $dist;
                $mejor = $clave;
            }
        }

        return $mejor;
    }

    /**
     * Dijkstra no dirigido, igual que `dijkstra()` de pathfinding.js.
     *
     * @param array<string, array<string, float>> $grafo
     * @return array<int, string>|null
     */
    public static function dijkstra(array $grafo, string $inicio, string $fin): ?array
    {
        if (!isset($grafo[$inicio]) || !isset($grafo[$fin])) {
            return null;
        }

        $distancias = [];
        $previos = [];
        foreach (array_keys($grafo) as $nodo) {
            $distancias[$nodo] = INF;
            $previos[$nodo] = null;
        }
        $distancias[$inicio] = 0.0;

        // Cola de prioridad con SplPriorityQueue (prioridad negativa = mínima).
        $cola = new SplPriorityQueue();
        $cola->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $cola->insert($inicio, 0.0);

        $visitados = [];

        while (!$cola->isEmpty()) {
            $extraido = $cola->extract();
            $actual = (string) $extraido['data'];

            if (isset($visitados[$actual])) {
                continue;
            }
            $visitados[$actual] = true;

            if ($actual === $fin) {
                $camino = [];
                $u = $actual;
                while ($u !== null) {
                    array_unshift($camino, $u);
                    $u = $previos[$u] ?? null;
                }
                return $camino;
            }

            foreach (($grafo[$actual] ?? []) as $vecino => $peso) {
                if (isset($visitados[$vecino])) {
                    continue;
                }
                $alternativa = $distancias[$actual] + $peso;
                if ($alternativa < $distancias[$vecino]) {
                    $distancias[$vecino] = $alternativa;
                    $previos[$vecino] = $actual;
                    $cola->insert((string) $vecino, -$alternativa);
                }
            }
        }

        return null;
    }

    // ==========================================
    // TOPOLOGÍA
    // ==========================================

    /**
     * Componentes conexas.
     *
     * @param array<string, array<string, float>> $grafo
     * @return array{total: int, tamanos: array<int, int>, mayor: int, componentes: array<int, array{nodos: array<int, string>, tamano: int, punto: array{lat: float, lng: float}|null}>}
     */
    public static function componentes(array $grafo): array
    {
        $visitados = [];
        $componentes = [];

        foreach (array_keys($grafo) as $inicio) {
            if (isset($visitados[$inicio])) {
                continue;
            }

            $nodos = [];
            $pila = [$inicio];
            $visitados[$inicio] = true;

            while ($pila !== []) {
                $actual = array_pop($pila);
                $nodos[] = $actual;
                foreach (array_keys($grafo[$actual] ?? []) as $vecino) {
                    if (!isset($visitados[$vecino])) {
                        $visitados[$vecino] = true;
                        $pila[] = (string) $vecino;
                    }
                }
            }

            [$lat, $lng] = array_map('floatval', explode(',', $nodos[0]));
            $componentes[] = [
                'nodos' => $nodos,
                'tamano' => count($nodos),
                'punto' => ['lat' => $lat, 'lng' => $lng],
            ];
        }

        $tamanos = array_map(static fn(array $c): int => $c['tamano'], $componentes);
        rsort($tamanos);

        return [
            'total' => count($componentes),
            'tamanos' => $tamanos,
            'mayor' => $tamanos[0] ?? 0,
            'componentes' => $componentes,
        ];
    }

    /**
     * Nodos de grado 1 (extremos libres).
     *
     * @param array<string, array<string, float>> $grafo
     * @return array<int, string>
     */
    public static function extremosLibres(array $grafo): array
    {
        $extremos = [];
        foreach ($grafo as $nodo => $vecinos) {
            if (count($vecinos) === 1) {
                $extremos[] = (string) $nodo;
            }
        }
        return $extremos;
    }

    /**
     * Aristas únicas con clave canónica `nodoA|nodoB`.
     *
     * @param array<string, array<string, float>> $grafo
     * @return array<string, array{0: string, 1: string, distancia: float}>
     */
    public static function aristas(array $grafo): array
    {
        $aristas = [];
        foreach ($grafo as $nodo => $vecinos) {
            foreach ($vecinos as $vecino => $distancia) {
                $a = (string) $nodo;
                $b = (string) $vecino;
                $clave = strcmp($a, $b) < 0 ? $a . '|' . $b : $b . '|' . $a;
                $aristas[$clave] = ['a' => $a, 'b' => $b, 'distancia' => (float) $distancia];
            }
        }
        return $aristas;
    }

    /**
     * Métricas de resumen del grafo.
     *
     * @param array<string, array<string, float>> $grafo
     * @return array{nodos: int, aristas: int, grado1: int, componentes: int, distribucion: array<int, int>}
     */
    public static function metricas(array $grafo): array
    {
        $distribucion = [];
        $grado1 = 0;

        foreach ($grafo as $vecinos) {
            $grado = count($vecinos);
            if ($grado === 1) {
                $grado1++;
            }
            $distribucion[$grado] = ($distribucion[$grado] ?? 0) + 1;
        }
        ksort($distribucion);

        return [
            'nodos' => count($grafo),
            'aristas' => count(self::aristas($grafo)),
            'grado1' => $grado1,
            'componentes' => self::componentes($grafo)['total'],
            'distribucion' => $distribucion,
        ];
    }

    // ==========================================
    // ALCANZABILIDAD
    // ==========================================

    /**
     * Evaluar pares origen→destino con el criterio de `calcularRuta()`:
     * nodo más cercano (sin umbral) + Dijkstra.
     *
     * @param array<string, array<string, float>> $grafo
     * @param array<int, array{id: string, origen: array{lat: float|int, lng: float|int}, destino: array{lat: float|int, lng: float|int}}> $pares
     * @return array{alcanzables: array<int, string>, inalcanzables: array<int, string>}
     */
    public static function alcanzabilidad(array $grafo, array $pares): array
    {
        $alcanzables = [];
        $inalcanzables = [];

        foreach ($pares as $par) {
            $id = (string) ($par['id'] ?? '');
            $origen = $par['origen'] ?? null;
            $destino = $par['destino'] ?? null;
            if ($id === '' || !is_array($origen) || !is_array($destino)) {
                continue;
            }

            $inicio = self::nodoMasCercano($grafo, $origen);
            $fin = self::nodoMasCercano($grafo, $destino);

            if ($inicio === null || $fin === null) {
                $inalcanzables[] = $id;
                continue;
            }

            $camino = self::dijkstra($grafo, $inicio, $fin);
            if ($camino === null || $camino === []) {
                $inalcanzables[] = $id;
            } else {
                $alcanzables[] = $id;
            }
        }

        return [
            'alcanzables' => $alcanzables,
            'inalcanzables' => $inalcanzables,
        ];
    }
}
