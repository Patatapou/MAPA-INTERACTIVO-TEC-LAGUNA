<?php
// ==========================================
// SERVICIO DE RED DE CAMINOS
// ==========================================
// Índice de segmentos, validación de cadenas e hidratación de rutas.
//
// Autoridad del servidor: la geometría de una ruta SIEMPRE se reconstruye
// desde `caminitos.geojson`. El cliente solo envía IDs y sentidos.
//
// Reglas del plan:
//  - Identidad de nodo = `lat.toFixed(6) + "," + lng.toFixed(6)` (apartado 9.2).
//  - Continuidad de cadena: ≤2,5 m (la misma tolerancia de snapping que usa el
//    grafo público en `js/pathfinding.js`).
//  - Hidratación: inversión por sentido y sin duplicar el nodo de unión.

require_once __DIR__ . '/../repositories/PathNetworkRepository.php';

class PathNetworkService
{
    private PathNetworkRepository $repo;

    /** @var array<string, array>|null */
    private ?array $indiceCache = null;

    /** @var array|null */
    private ?array $geojsonCache = null;

    public const TOLERANCIA_CONTINUIDAD_M = 2.5;

    public function __construct(?PathNetworkRepository $repo = null)
    {
        $this->repo = $repo ?? new PathNetworkRepository();
    }

    /**
     * GeoJSON de la red (cacheado).
     *
     * @throws RuntimeException si la red falta o es inválida
     */
    public function geojson(bool $recargar = false): array
    {
        if ($this->geojsonCache === null || $recargar) {
            $this->geojsonCache = $this->repo->leer();
            $this->indiceCache = null;
        }
        return $this->geojsonCache;
    }

    public function recargar(): void
    {
        $this->geojsonCache = null;
        $this->indiceCache = null;
    }

    /**
     * Índice `id → segmento`.
     *
     * @return array<string, array{id: string, nombre: string, tipo: string, accesible: bool, estado: string, coordenadas: array, inicio: string, fin: string, longitud_m: float, vertices: int}>
     * @throws RuntimeException
     */
    public function indice(bool $recargar = false): array
    {
        if ($this->indiceCache !== null && !$recargar) {
            return $this->indiceCache;
        }

        $geojson = $this->geojson($recargar);
        $indice = [];

        foreach ($geojson['features'] as $feature) {
            $coords = $feature['geometry']['coordinates'] ?? [];
            if (!is_array($coords) || count($coords) < 2) {
                continue;
            }

            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $id = (string) ($props['id'] ?? ($feature['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $primero = $coords[0];
            $ultimo = $coords[count($coords) - 1];

            $indice[$id] = [
                'id' => $id,
                'nombre' => (string) ($props['nombre'] ?? $id),
                'tipo' => (string) ($props['tipo'] ?? 'peatonal'),
                'accesible' => (bool) ($props['accesible'] ?? true),
                'estado' => (string) ($props['estado'] ?? 'activa'),
                'coordenadas' => $coords,
                'inicio' => self::claveNodo((float) $primero[1], (float) $primero[0]),
                'fin' => self::claveNodo((float) $ultimo[1], (float) $ultimo[0]),
                'longitud_m' => self::longitudPolilinea($coords),
                'vertices' => count($coords),
            ];
        }

        $this->indiceCache = $indice;
        return $indice;
    }

    public function segmento(string $id): ?array
    {
        $indice = $this->indice();
        return $indice[$id] ?? null;
    }

    /**
     * Extremos canónicos por segmento (fusión de nodos con tolerancia).
     *
     * @return array<string, array{inicio: string, fin: string}>
     */
    public function extremosCanonicos(float $tolerancia = self::TOLERANCIA_CONTINUIDAD_M): array
    {
        $indice = $this->indice();
        $claves = [];
        $extremos = [];

        foreach ($indice as $id => $segmento) {
            $par = [];
            foreach ([$segmento['inicio'], $segmento['fin']] as $claveBruta) {
                [$lat, $lng] = array_map('floatval', explode(',', $claveBruta));
                $clave = $this->claveExistente($lat, $lng, $claves, $tolerancia) ?? $claveBruta;
                if (!in_array($clave, $claves, true)) {
                    $claves[] = $clave;
                }
                $par[] = $clave;
            }
            $extremos[$id] = ['inicio' => $par[0], 'fin' => $par[1]];
        }

        return $extremos;
    }

    /**
     * Nodos de la red con los segmentos que los tocan.
     *
     * Los extremos se fusionan con la tolerancia indicada (por defecto 2,5 m,
     * la misma que exige la validación de cadenas y que usa el grafo público).
     *
     * @return array<string, array{lat: float, lng: float, segmentos: array<int, string>, grado: int}>
     */
    public function nodos(float $tolerancia = self::TOLERANCIA_CONTINUIDAD_M): array
    {
        $nodos = [];

        foreach ($this->extremosCanonicos($tolerancia) as $id => $extremos) {
            foreach ([$extremos['inicio'], $extremos['fin']] as $clave) {
                if (!isset($nodos[$clave])) {
                    [$lat, $lng] = array_map('floatval', explode(',', $clave));
                    $nodos[$clave] = ['lat' => $lat, 'lng' => $lng, 'segmentos' => [], 'grado' => 0];
                }
                if (!in_array($id, $nodos[$clave]['segmentos'], true)) {
                    $nodos[$clave]['segmentos'][] = $id;
                }
            }
        }

        foreach ($nodos as $clave => $nodo) {
            $nodos[$clave]['grado'] = count($nodo['segmentos']);
        }

        return $nodos;
    }

    /**
     * Validar una cadena de referencias `{id, sentido}`.
     *
     * @param array<int, array{id?: mixed, sentido?: mixed}> $cadena
     * @return array{ok: bool, errores: array<int, string>, advertencias: array<int, string>, nodos: array<int, string>, distancia_m: float}
     */
    public function validarCadena(array $cadena, float $tolerancia = self::TOLERANCIA_CONTINUIDAD_M, int $maxSegmentos = 500): array
    {
        $errores = [];
        $advertencias = [];
        $indice = $this->indice();

        if ($cadena === []) {
            return [
                'ok' => false,
                'errores' => ['La ruta no tiene segmentos.'],
                'advertencias' => [],
                'nodos' => [],
                'distancia_m' => 0.0,
            ];
        }

        if (count($cadena) > $maxSegmentos) {
            $errores[] = 'La ruta supera el máximo de ' . $maxSegmentos . ' segmentos.';
        }

        $vistos = [];
        $extremoActual = null;
        $nodos = [];
        $distancia = 0.0;

        foreach ($cadena as $posicion => $ref) {
            $numero = $posicion + 1;
            $id = is_array($ref) ? ($ref['id'] ?? null) : null;
            $sentido = is_array($ref) ? ($ref['sentido'] ?? 'normal') : null;

            if (!is_string($id) || $id === '') {
                $errores[] = 'Segmento ' . $numero . ': falta el id.';
                continue;
            }
            if ($sentido !== 'normal' && $sentido !== 'inverso') {
                $errores[] = 'Segmento ' . $id . ': sentido inválido (use normal o inverso).';
                continue;
            }
            if (isset($vistos[$id])) {
                $errores[] = 'Segmento duplicado en la ruta: ' . $id . '.';
                continue;
            }
            $vistos[$id] = true;

            if (!isset($indice[$id])) {
                $errores[] = 'Segmento inexistente: ' . $id . '.';
                continue;
            }

            $segmento = $indice[$id];
            $inicio = $sentido === 'normal' ? $segmento['inicio'] : $segmento['fin'];
            $fin = $sentido === 'normal' ? $segmento['fin'] : $segmento['inicio'];

            if ($extremoActual !== null) {
                [$aLat, $aLng] = array_map('floatval', explode(',', $extremoActual));
                [$bLat, $bLng] = array_map('floatval', explode(',', $inicio));
                $salto = self::distanciaMetros($aLat, $aLng, $bLat, $bLng);
                if ($salto > $tolerancia) {
                    $errores[] = sprintf(
                        'Cadena discontinua antes del segmento %s: separación de %.2f m (máximo %.2f m).',
                        $id,
                        $salto,
                        $tolerancia
                    );
                }
            }

            $extremoActual = $fin;
            $distancia += $segmento['longitud_m'];

            $coords = $sentido === 'inverso' ? array_reverse($segmento['coordenadas']) : $segmento['coordenadas'];
            foreach ($coords as $indiceCoord => $punto) {
                if ($posicion > 0 && $indiceCoord === 0) {
                    continue; // nodo de unión ya registrado
                }
                $nodos[] = self::claveNodo((float) $punto[1], (float) $punto[0]);
            }
        }

        return [
            'ok' => $errores === [],
            'errores' => $errores,
            'advertencias' => $advertencias,
            'nodos' => $nodos,
            'distancia_m' => round($distancia, 1),
        ];
    }

    /**
     * Hidratar una ruta: geometría, nodos, distancia y detalle de segmentos.
     *
     * Tolera referencias rotas: las marca como faltantes en lugar de fallar,
     * para que el listado pueda señalar rutas inconsistentes (apartado 8.5).
     *
     * @param array{segmentos?: array, estado?: string, accesible?: bool} $ruta
     * @return array
     */
    public function hidratar(array $ruta): array
    {
        $indice = $this->indice();
        $referencias = is_array($ruta['segmentos'] ?? null) ? $ruta['segmentos'] : [];

        $coordenadas = [];
        $nodos = [];
        $detalle = [];
        $distancia = 0.0;
        $faltantes = [];
        $inactivos = [];
        $noAccesibles = [];
        $saltos = [];

        $extremoEsperado = null;

        foreach ($referencias as $ref) {
            $id = is_array($ref) ? (string) ($ref['id'] ?? '') : '';
            $sentido = is_array($ref) && ($ref['sentido'] ?? '') === 'inverso' ? 'inverso' : 'normal';

            if (!isset($indice[$id])) {
                if ($id !== '') {
                    $faltantes[] = $id;
                }
                continue;
            }

            $segmento = $indice[$id];
            $coords = $sentido === 'inverso' ? array_reverse($segmento['coordenadas']) : $segmento['coordenadas'];
            $inicioSegmento = $sentido === 'inverso' ? $segmento['fin'] : $segmento['inicio'];
            $finSegmento = $sentido === 'inverso' ? $segmento['inicio'] : $segmento['fin'];

            if ($extremoEsperado !== null) {
                [$aLat, $aLng] = array_map('floatval', explode(',', $extremoEsperado));
                [$bLat, $bLng] = array_map('floatval', explode(',', $inicioSegmento));
                $salto = self::distanciaMetros($aLat, $aLng, $bLat, $bLng);
                if ($salto > self::TOLERANCIA_CONTINUIDAD_M) {
                    $saltos[] = ['segmento' => $id, 'metros' => round($salto, 2)];
                }
            }
            $extremoEsperado = $finSegmento;

            if ($coordenadas !== []) {
                array_shift($coords);
            }
            foreach ($coords as $punto) {
                $coordenadas[] = $punto;
                $nodos[] = self::claveNodo((float) $punto[1], (float) $punto[0]);
            }

            $detalle[] = [
                'id' => $id,
                'nombre' => $segmento['nombre'],
                'tipo' => $segmento['tipo'],
                'estado' => $segmento['estado'],
                'accesible' => $segmento['accesible'],
                'sentido' => $sentido,
                'longitud_m' => round($segmento['longitud_m'], 1),
            ];

            $distancia += $segmento['longitud_m'];

            if ($segmento['estado'] !== 'activa') {
                $inactivos[] = $id;
            }
            if (!$segmento['accesible']) {
                $noAccesibles[] = $id;
            }
        }

        $advertencias = [];
        if ($faltantes !== []) {
            $advertencias[] = 'Referencias a segmentos inexistentes: ' . implode(', ', $faltantes) . '.';
        }
        if (($ruta['estado'] ?? 'activa') === 'activa' && $inactivos !== []) {
            $advertencias[] = 'La ruta está activa pero usa segmentos inactivos: ' . implode(', ', $inactivos) . '.';
        }
        if (($ruta['accesible'] ?? true) === true && $noAccesibles !== []) {
            $advertencias[] = 'La ruta es accesible pero incluye segmentos no accesibles: ' . implode(', ', $noAccesibles) . '.';
        }
        if ($saltos !== []) {
            $advertencias[] = 'La cadena hidratada presenta saltos de continuidad.';
        }

        return [
            'geometria' => [
                'type' => 'LineString',
                'coordinates' => $coordenadas,
            ],
            'nodos' => $nodos,
            'distancia_m' => round($distancia, 1),
            'segmentos_detalle' => $detalle,
            'n_segmentos' => count($referencias),
            'inconsistente' => $faltantes !== [],
            'segmentos_faltantes' => $faltantes,
            'saltos' => $saltos,
            'advertencias' => $advertencias,
        ];
    }

    // ==========================================
    // UTILIDADES GEOMÉTRICAS
    // ==========================================

    public static function claveNodo(float $lat, float $lng): string
    {
        return number_format($lat, 6, '.', '') . ',' . number_format($lng, 6, '.', '');
    }

    public static function distanciaMetros(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $radio = 6371000.0;
        $dLat = ($lat2 - $lat1) * M_PI / 180;
        $dLng = ($lng2 - $lng1) * M_PI / 180;
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos($lat1 * M_PI / 180) * cos($lat2 * M_PI / 180)
            * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $radio * $c;
    }

    /**
     * Longitud de una polilínea en metros.
     *
     * @param array<int, array{0: float|int, 1: float|int}> $coords [lng, lat]
     */
    public static function longitudPolilinea(array $coords): float
    {
        $total = 0.0;
        $cuenta = count($coords);
        for ($i = 1; $i < $cuenta; $i++) {
            $total += self::distanciaMetros(
                (float) $coords[$i - 1][1],
                (float) $coords[$i - 1][0],
                (float) $coords[$i][1],
                (float) $coords[$i][0]
            );
        }
        return $total;
    }

    /**
     * Buscar una clave de nodo ya registrada dentro de la tolerancia.
     *
     * @param array<int, string> $claves
     */
    private function claveExistente(float $lat, float $lng, array $claves, float $tolerancia): ?string
    {
        foreach ($claves as $clave) {
            [$nLat, $nLng] = array_map('floatval', explode(',', $clave));
            if (self::distanciaMetros($lat, $lng, $nLat, $nLng) <= $tolerancia) {
                return $clave;
            }
        }
        return null;
    }
}
