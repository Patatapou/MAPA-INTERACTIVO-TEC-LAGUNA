<?php
// ==========================================
// SERVICIO DE GESTIÓN DE LA RED DE CAMINOS
// ==========================================
// Orquesta la validación (E01–E08 / W01–W06), la asignación de identidades,
// la comprobación de alcanzabilidad, el guardado con control de revisión y
// la auditoría. El cliente nunca es la autoridad: todo se revalida aquí.

declare(strict_types=1);

require_once __DIR__ . '/../repositories/NetworkRepository.php';
require_once __DIR__ . '/../repositories/BuildingGeometryRepository.php';
require_once __DIR__ . '/../repositories/AuditLogRepository.php';
require_once __DIR__ . '/NetworkGraph.php';

/**
 * Error de negocio con código HTTP y lista de errores estructurados.
 */
class NetworkServiceException extends RuntimeException
{
    private int $statusCode;
    private array $errores;

    public function __construct(string $mensaje, int $statusCode = 400, array $errores = [])
    {
        parent::__construct($mensaje, $statusCode);
        $this->statusCode = $statusCode;
        $this->errores = $errores;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errores(): array
    {
        return $this->errores;
    }
}

class NetworkService
{
    public const MAX_SEGMENTOS = 2000;
    public const MAX_BYTES = 2097152; // 2 MB
    public const MARGEN_RECUADRO_M = 300.0;
    public const RADIO_CASI_CONECTADO_M = 8.0;
    public const MAX_AVISOS_POR_TIPO = 20;

    private const PATRON_TMP = '/^tmp-[a-z0-9][a-z0-9-]{0,63}$/i';
    private const PATRON_ID = '/^seg-(?:[a-f0-9]{8}(?:-\d{2})?|\d{6})$/';

    /** Recuadro de reserva si la red actual no se puede leer. */
    private const RECUADRO_RESERVA = [
        'minLat' => 25.5280,
        'maxLat' => 25.5368,
        'minLng' => -103.4383,
        'maxLng' => -103.4333,
    ];

    /** @var array<int, string> Nombres de puertas de respaldo (igual que main.js). */
    private const PUERTAS_RESPALDO = [
        'P1' => ['lat' => 25.534749, 'lng' => -103.434492],
        'P2' => ['lat' => 25.533321, 'lng' => -103.434524],
        'P3' => ['lat' => 25.531002, 'lng' => -103.435153],
        'P4' => ['lat' => 25.530236, 'lng' => -103.435404],
        'P5' => ['lat' => 25.5294985, 'lng' => -103.435666],
        'P6' => ['lat' => 25.535214, 'lng' => -103.436398],
    ];

    private NetworkRepository $repo;
    private AuditLogRepository $auditoria;
    private ?array $paresReferenciaCache = null;

    public function __construct(?NetworkRepository $repo = null, ?AuditLogRepository $auditoria = null)
    {
        $this->repo = $repo ?? new NetworkRepository();
        $this->auditoria = $auditoria ?? new AuditLogRepository();
    }

    // ==========================================
    // ESTADO
    // ==========================================

    /**
     * Red vigente y su revisión.
     *
     * @return array{revision: int, geojson: array, segmentos: int}
     */
    public function estado(): array
    {
        $geojson = $this->repo->leerRed();

        return [
            'revision' => (int) $geojson['revision'],
            'geojson' => $geojson,
            'segmentos' => count($geojson['features'] ?? []),
        ];
    }

    public function redActual(): array
    {
        return $this->repo->leerRed();
    }

    // ==========================================
    // PARES DE REFERENCIA (E08)
    // ==========================================

    /**
     * Pares puerta→edificio con el mismo criterio que `calcularRuta()` del
     * mapa público: centro del bounding box de cada geometría.
     *
     * @return array<int, array{id: string, origen: array{lat: float, lng: float}, destino: array{lat: float, lng: float}}>
     */
    public function paresReferencia(): array
    {
        if ($this->paresReferenciaCache !== null) {
            return $this->paresReferenciaCache;
        }

        $raiz = dirname(__DIR__, 2);
        $rutaDatos = $raiz . '/datos.json';
        $datos = [];
        if (file_exists($rutaDatos)) {
            $contenido = file_get_contents($rutaDatos);
            $decodificado = $contenido !== false ? json_decode($contenido, true) : null;
            if (!is_array($decodificado)) {
                throw new NetworkServiceException(
                    'No se pudo leer datos.json; no es posible comprobar la alcanzabilidad.',
                    500
                );
            }
            $datos = $decodificado;
        }

        $geometrias = [];
        try {
            $geometrias = (new BuildingGeometryRepository())->leerGeometrias();
        } catch (Throwable $e) {
            throw new NetworkServiceException(
                'No se pudo leer GeoTec.js; no es posible comprobar la alcanzabilidad. ' . $e->getMessage(),
                500
            );
        }

        $centros = [];
        foreach (($geometrias['features'] ?? []) as $feature) {
            $id = (string) ($feature['properties']['id'] ?? ($feature['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $centro = $this->centroBoundingBox($feature['geometry'] ?? null);
            if ($centro !== null) {
                $centros[$id] = $centro;
            }
        }

        $puertas = [];
        $edificios = [];
        foreach ($datos as $id => $registro) {
            $id = (string) $id;
            $tipo = (string) ($registro['tipo'] ?? 'edificio');
            $centro = $centros[$id] ?? (self::PUERTAS_RESPALDO[$id] ?? null);
            if ($centro === null) {
                continue;
            }
            if ($tipo === 'acceso') {
                $puertas[$id] = $centro;
            } else {
                $edificios[$id] = $centro;
            }
        }

        $pares = [];
        foreach ($puertas as $idPuerta => $origen) {
            foreach ($edificios as $idEdificio => $destino) {
                $pares[] = [
                    'id' => $idPuerta . '→' . $idEdificio,
                    'origen' => $origen,
                    'destino' => $destino,
                ];
            }
        }

        $this->paresReferenciaCache = $pares;
        return $pares;
    }

    /**
     * Fijar pares (pruebas unitarias sin depender de datos.json/GeoTec.js).
     *
     * @param array<int, array{id: string, origen: array, destino: array}> $pares
     */
    public function usarParesReferencia(array $pares): void
    {
        $this->paresReferenciaCache = $pares;
    }

    // ==========================================
    // GUARDADO
    // ==========================================

    /**
     * Validar, normalizar y guardar una propuesta completa de red.
     *
     * @param array $propuesta FeatureCollection enviada por el editor
     * @param int $baseRevision Revisión sobre la que se editó
     * @param array{id?: string, username?: string} $usuario
     * @return array Respuesta para el cliente
     * @throws NetworkServiceException
     * @throws RevisionObsoletaException
     */
    public function guardar(array $propuesta, int $baseRevision, array $usuario = []): array
    {
        $actual = $this->repo->leerRed();
        $actualIds = [];
        $actualPorId = [];
        foreach (($actual['features'] ?? []) as $feature) {
            $id = (string) ($feature['properties']['id'] ?? ($feature['id'] ?? ''));
            if ($id !== '') {
                $actualIds[$id] = true;
                $actualPorId[$id] = $feature;
            }
        }

        $errores = $this->validarEstructura($propuesta, $actual);
        if ($errores !== []) {
            throw new NetworkServiceException(
                'La propuesta de red no es válida.',
                400,
                $errores
            );
        }

        [$features, $idMap, $nextSeq] = $this->asignarIdentidades(
            $propuesta['features'],
            $actualPorId,
            (int) ($actual['next_seq'] ?? 1)
        );

        $final = $actual;
        $final['features'] = $features;
        $final['next_seq'] = $nextSeq;
        $final['schema_version'] = NetworkRepository::SCHEMA_VERSION;

        $grafoActual = NetworkGraph::construir($actual);
        $grafoNuevo = NetworkGraph::construir($final);

        // E08: nunca romper la alcanzabilidad de un par que hoy funciona.
        $pares = $this->paresReferencia();
        if ($pares !== []) {
            $antes = NetworkGraph::alcanzabilidad($grafoActual, $pares);
            $despues = NetworkGraph::alcanzabilidad($grafoNuevo, $pares);
            $perdidos = array_values(array_diff($antes['alcanzables'], $despues['alcanzables']));

            if ($perdidos !== []) {
                $lista = array_slice($perdidos, 0, self::MAX_AVISOS_POR_TIPO);
                throw new NetworkServiceException(
                    'La propuesta dejaría sin ruta accesible a ' . count($perdidos)
                    . ' par(es) puerta→edificio. Corrige la red antes de guardar.',
                    400,
                    [[
                        'codigo' => 'E08',
                        'mensaje' => 'Regresión de alcanzabilidad: ' . implode(', ', $lista)
                            . (count($perdidos) > count($lista) ? '…' : ''),
                        'pares' => $lista,
                        'total' => count($perdidos),
                    ]]
                );
            }
        }

        $advertencias = $this->calcularAdvertencias($final, $grafoNuevo);
        $cambios = $this->diferencias($actualPorId, $features);

        $guardado = $this->repo->guardarRevision($final, $baseRevision);

        $this->auditar([
            'event' => 'red_actualizada',
            'user_id' => (string) ($usuario['id'] ?? ''),
            'username' => (string) ($usuario['username'] ?? ''),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
            'revision_anterior' => (int) $actual['revision'],
            'revision_nueva' => (int) $guardado['revision'],
            'segmentos' => count($features),
            'anadidos' => $cambios['anadidos'],
            'modificados' => $cambios['modificados'],
            'eliminados' => $cambios['eliminados'],
            'advertencias' => array_values(array_unique(array_column($advertencias, 'codigo'))),
            'respaldo' => $guardado['respaldo'] !== null ? basename($guardado['respaldo']) : null,
        ]);

        return [
            'success' => true,
            'message' => 'Red guardada. Revisión ' . $guardado['revision'] . '.',
            'revision' => $guardado['revision'],
            'id_map' => $idMap,
            'warnings' => $advertencias,
            'segmentos' => count($features),
            'cambios' => $cambios,
        ];
    }

    // ==========================================
    // E01–E05 / E07
    // ==========================================

    /**
     * @return array<int, array{codigo: string, mensaje: string, indice?: int}>
     */
    public function validarEstructura(array $propuesta, ?array $redActual = null): array
    {
        $errores = [];

        if (($propuesta['type'] ?? '') !== 'FeatureCollection') {
            return [[
                'codigo' => 'E01',
                'mensaje' => 'El documento debe ser una FeatureCollection de GeoJSON.',
            ]];
        }

        $features = $propuesta['features'] ?? null;
        if (!is_array($features)) {
            return [[
                'codigo' => 'E01',
                'mensaje' => 'Falta el arreglo features.',
            ]];
        }

        if (count($features) > self::MAX_SEGMENTOS) {
            $errores[] = [
                'codigo' => 'E07',
                'mensaje' => 'La red supera el máximo de ' . self::MAX_SEGMENTOS . ' segmentos.',
            ];
            return $errores;
        }

        $redVigente = $redActual ?? $this->repo->leerRed();
        $recuadro = $this->recuadroValido($redVigente);
        $idsActuales = [];
        foreach (($redVigente['features'] ?? []) as $feature) {
            $id = (string) ($feature['properties']['id'] ?? ($feature['id'] ?? ''));
            if ($id !== '') {
                $idsActuales[$id] = true;
            }
        }

        $vistos = [];
        foreach ($features as $indice => $feature) {
            $geom = $feature['geometry'] ?? null;
            if (!is_array($geom) || ($geom['type'] ?? '') !== 'LineString') {
                $errores[] = [
                    'codigo' => 'E02',
                    'mensaje' => 'El segmento debe ser un LineString.',
                    'indice' => $indice,
                ];
                continue;
            }

            $coords = $geom['coordinates'] ?? null;
            if (!is_array($coords) || count($coords) < 2) {
                $errores[] = [
                    'codigo' => 'E03',
                    'mensaje' => 'El segmento necesita al menos 2 vértices.',
                    'indice' => $indice,
                ];
                continue;
            }

            $longitud = 0.0;
            $puntoInvalido = null;
            foreach ($coords as $punto) {
                if (!is_array($punto) || count($punto) < 2
                    || !is_numeric($punto[0]) || !is_numeric($punto[1])
                    || !is_finite((float) $punto[0]) || !is_finite((float) $punto[1])) {
                    $puntoInvalido = 'Coordenada no numérica o no finita.';
                    break;
                }
                $lng = (float) $punto[0];
                $lat = (float) $punto[1];
                if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                    $puntoInvalido = 'Coordenada fuera de rango global.';
                    break;
                }
                if ($lat < $recuadro['minLat'] || $lat > $recuadro['maxLat']
                    || $lng < $recuadro['minLng'] || $lng > $recuadro['maxLng']) {
                    $puntoInvalido = 'Coordenada fuera del recuadro del campus.';
                    break;
                }
            }

            if ($puntoInvalido !== null) {
                $errores[] = [
                    'codigo' => 'E04',
                    'mensaje' => $puntoInvalido,
                    'indice' => $indice,
                ];
                continue;
            }

            for ($i = 0; $i < count($coords) - 1; $i++) {
                $longitud += NetworkGraph::distanciaMetros(
                    (float) $coords[$i][1],
                    (float) $coords[$i][0],
                    (float) $coords[$i + 1][1],
                    (float) $coords[$i + 1][0]
                );
            }

            if ($longitud <= 0.0) {
                $errores[] = [
                    'codigo' => 'E03',
                    'mensaje' => 'El segmento tiene longitud 0.',
                    'indice' => $indice,
                ];
                continue;
            }

            $id = $feature['properties']['id'] ?? ($feature['id'] ?? null);
            if (!is_string($id) || $id === ''
                || (preg_match(self::PATRON_TMP, $id) !== 1 && preg_match(self::PATRON_ID, $id) !== 1)) {
                $errores[] = [
                    'codigo' => 'E05',
                    'mensaje' => 'ID de segmento inválido (usa tmp-… para segmentos nuevos).',
                    'indice' => $indice,
                ];
                continue;
            }

            if (isset($vistos[$id])) {
                $errores[] = [
                    'codigo' => 'E05',
                    'mensaje' => 'ID de segmento duplicado: ' . $id . '.',
                    'indice' => $indice,
                ];
                continue;
            }
            $vistos[$id] = true;

            // Un ID definitivo que no existe en la red vigente solo puede venir
            // de una propuesta manipulada: el servidor es quien asigna IDs.
            if (preg_match(self::PATRON_ID, $id) === 1 && !isset($idsActuales[$id])) {
                $errores[] = [
                    'codigo' => 'E05',
                    'mensaje' => 'ID definitivo desconocido: ' . $id . '. Los segmentos nuevos deben usar tmp-….',
                    'indice' => $indice,
                ];
            }

            if (count($errores) >= 50) {
                break;
            }
        }

        return $errores;
    }

    // ==========================================
    // IDENTIDADES
    // ==========================================

    /**
     * Asignar IDs definitivos y conservar los metadatos del servidor.
     *
     * @param array<int, array> $features
     * @param array<string, array> $actualPorId
     * @return array{0: array<int, array>, 1: array<string, string>, 2: int}
     */
    public function asignarIdentidades(array $features, array $actualPorId, int $nextSeq): array
    {
        $idMap = [];
        $salida = [];
        $usados = [];
        $secuencia = max(1, $nextSeq);
        $ahora = date('c');

        foreach ($features as $indice => $feature) {
            $idEntrada = (string) ($feature['properties']['id'] ?? ($feature['id'] ?? ''));
            $coords = $feature['geometry']['coordinates'];
            $id = $idEntrada;

            if (preg_match(self::PATRON_TMP, $idEntrada) === 1) {
                do {
                    $id = sprintf('seg-%06d', $secuencia);
                    $secuencia++;
                } while (isset($usados[$id]) || isset($actualPorId[$id]));
                $idMap[$idEntrada] = $id;
            }

            $usados[$id] = true;
            $previo = $actualPorId[$id] ?? null;
            $props = is_array($previo['properties'] ?? null) ? $previo['properties'] : [];
            $props['id'] = $id;

            if ($previo === null) {
                $props += [
                    'nombre' => sprintf('Segmento %03d', $indice + 1),
                    'tipo' => 'peatonal',
                    'accesible' => true,
                    'estado' => 'activa',
                    'creado_en' => $ahora,
                    'actualizado_en' => $ahora,
                ];
            } else {
                $coordsPrevias = $previo['geometry']['coordinates'] ?? [];
                if (json_encode($coordsPrevias) !== json_encode($coords)) {
                    $props['actualizado_en'] = $ahora;
                }
            }

            $salida[] = [
                'type' => 'Feature',
                'id' => $id,
                'properties' => $props,
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => $coords,
                ],
            ];
        }

        return [$salida, $idMap, $secuencia];
    }

    // ==========================================
    // W01–W06
    // ==========================================

    /**
     * @param array<string, array<string, float>> $grafo
     * @return array<int, array{codigo: string, mensaje: string, punto: ?array{lat: float, lng: float}, ids: array<int, string>}>
     */
    public function calcularAdvertencias(array $geojson, array $grafo): array
    {
        $features = $this->featuresCrudas($geojson);
        $aristas = $this->aristasCrudas($geojson);

        return array_merge(
            $this->avisoComponentes($grafo, $features),
            $this->avisoExtremosCasiConectados($grafo, $aristas),
            $this->avisoCrucesSinNodo($grafo, $aristas),
            $this->avisoSegmentosCortos($features),
            $this->avisoSolapados($features),
            $this->avisoVerticesDuplicados($features)
        );
    }

    /**
     * @param array<string, array<string, float>> $grafo
     * @param array<int, array{id: string, coords: array}> $features
     * @return array<int, array>
     */
    private function avisoComponentes(array $grafo, array $features): array
    {
        $componentes = NetworkGraph::componentes($grafo);
        if ($componentes['total'] <= 1) {
            return [];
        }

        // Identificar los segmentos fuera de la componente principal.
        $principal = $componentes['componentes'][0]['nodos'] ?? [];
        $enPrincipal = array_fill_keys($principal, true);
        $todasLasClaves = array_keys($grafo);
        $fuera = [];

        foreach ($features as $feature) {
            $nodos = [];
            foreach ($feature['coords'] as $punto) {
                $clave = NetworkGraph::buscarNodoSnap(
                    (float) $punto[1],
                    (float) $punto[0],
                    $todasLasClaves,
                    NetworkGraph::TOLERANCIA_SNAPPING_M
                );
                if ($clave !== null) {
                    $nodos[] = $clave;
                }
            }
            $id = $feature['id'];
            $dentroDePrincipal = false;
            foreach ($nodos as $nodo) {
                if (isset($enPrincipal[$nodo])) {
                    $dentroDePrincipal = true;
                    break;
                }
            }
            if (!$dentroDePrincipal) {
                $fuera[] = $id;
            }
        }

        return [[
            'codigo' => 'W01',
            'mensaje' => sprintf(
                'La red tiene %d componentes conexas (la mayor concentra %d de %d nodos). Segmentos aislados: %d.',
                $componentes['total'],
                $componentes['mayor'],
                count($grafo),
                count($fuera)
            ),
            'punto' => $componentes['componentes'][1]['punto'] ?? null,
            'ids' => array_slice($fuera, 0, 50),
        ]];
    }

    /**
     * W02: extremo libre a entre 2,5 m y 8 m de otro segmento.
     *
     * @param array<string, array<string, float>> $grafo
     * @param array<int, array{id: string, coords: array}> $segmentos
     */
    private function avisoExtremosCasiConectados(array $grafo, array $segmentos): array
    {
        $avisos = [];
        $vistos = [];

        foreach (NetworkGraph::extremosLibres($grafo) as $clave) {
            [$lat, $lng] = array_map('floatval', explode(',', $clave));
            $menor = $this->distanciaMinimaASegmentos($lat, $lng, $segmentos, 2.5);

            if ($menor === null || $menor['distancia'] <= 2.5 || $menor['distancia'] > self::RADIO_CASI_CONECTADO_M) {
                continue;
            }

            $marca = sprintf('%.5f,%.5f', $lat, $lng);
            if (isset($vistos[$marca])) {
                continue;
            }
            $vistos[$marca] = true;

            $avisos[] = [
                'codigo' => 'W02',
                'mensaje' => sprintf(
                    'Extremo libre casi conectado a %s: %.1f m (se conecta por snapping hasta 2,5 m).',
                    $menor['id'],
                    $menor['distancia']
                ),
                'punto' => ['lat' => $lat, 'lng' => $lng],
                'ids' => [$menor['id']],
            ];

            if (count($avisos) >= self::MAX_AVISOS_POR_TIPO) {
                break;
            }
        }

        return $avisos;
    }

    /**
     * W03: dos segmentos se cruzan sin nodo a ≤2,5 m del cruce.
     *
     * @param array<string, array<string, float>> $grafo
     * @param array<int, array{id: string, coords: array}> $segmentos
     */
    private function avisoCrucesSinNodo(array $grafo, array $segmentos): array
    {
        $avisos = [];
        $clavesGrafo = array_keys($grafo);
        $vistos = [];
        $total = count($segmentos);

        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                $a = $segmentos[$i];
                $b = $segmentos[$j];
                if ($a['id'] === $b['id']) {
                    continue;
                }

                $interseccion = $this->interseccionSegmentos(
                    $a['a']['lat'], $a['a']['lng'], $a['b']['lat'], $a['b']['lng'],
                    $b['a']['lat'], $b['a']['lng'], $b['b']['lat'], $b['b']['lng']
                );

                if ($interseccion === null) {
                    continue;
                }

                $marca = sprintf('%.5f,%.5f', $interseccion['lat'], $interseccion['lng']);
                if (isset($vistos[$marca])) {
                    continue;
                }

                $nodoCerca = null;
                foreach ($clavesGrafo as $clave) {
                    [$nLat, $nLng] = array_map('floatval', explode(',', $clave));
                    if (NetworkGraph::distanciaMetros(
                        $interseccion['lat'],
                        $interseccion['lng'],
                        $nLat,
                        $nLng
                    ) <= NetworkGraph::TOLERANCIA_SNAPPING_M) {
                        $nodoCerca = $clave;
                        break;
                    }
                }

                if ($nodoCerca === null) {
                    $vistos[$marca] = true;
                    $avisos[] = [
                        'codigo' => 'W03',
                        'mensaje' => 'Cruce sin nodo entre ' . $a['id'] . ' y ' . $b['id']
                            . ': no hay conexión en ese punto.',
                        'punto' => $interseccion,
                        'ids' => [$a['id'], $b['id']],
                    ];
                }

                if (count($avisos) >= self::MAX_AVISOS_POR_TIPO) {
                    return $avisos;
                }
            }
        }

        return $avisos;
    }

    /**
     * W04: segmentos de menos de 2,5 m.
     */
    private function avisoSegmentosCortos(array $segmentos): array
    {
        $avisos = [];
        foreach ($segmentos as $segmento) {
            if ($segmento['longitud'] >= NetworkGraph::TOLERANCIA_SNAPPING_M) {
                continue;
            }
            $avisos[] = [
                'codigo' => 'W04',
                'mensaje' => sprintf(
                    'Segmento muy corto (%s): %.2f m. Sus extremos se fusionarán por snapping.',
                    $segmento['id'],
                    $segmento['longitud']
                ),
                'punto' => $segmento['a'],
                'ids' => [$segmento['id']],
            ];
            if (count($avisos) >= self::MAX_AVISOS_POR_TIPO) {
                break;
            }
        }
        return $avisos;
    }

    /**
     * W05: segmentos solapados o casi idénticos.
     *
     * @param array<int, array{id: string, coords: array}> $segmentos
     */
    private function avisoSolapados(array $segmentos): array
    {
        $avisos = [];
        $total = count($segmentos);

        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                $a = $segmentos[$i];
                $b = $segmentos[$j];
                if ($a['id'] === $b['id']) {
                    continue;
                }

                $mismoSentido = $this->polilineasSimilares($a['coords'], $b['coords']);
                $inverso = $this->polilineasSimilares($a['coords'], array_reverse($b['coords']));

                if ($mismoSentido || $inverso) {
                    $avisos[] = [
                        'codigo' => 'W05',
                        'mensaje' => 'Segmentos solapados o casi idénticos: ' . $a['id'] . ' y ' . $b['id'] . '.',
                        'punto' => $a['a'],
                        'ids' => [$a['id'], $b['id']],
                    ];
                }

                if (count($avisos) >= self::MAX_AVISOS_POR_TIPO) {
                    return $avisos;
                }
            }
        }

        return $avisos;
    }

    /**
     * W06: vértices consecutivos duplicados.
     */
    private function avisoVerticesDuplicados(array $segmentos): array
    {
        $avisos = [];
        foreach ($segmentos as $segmento) {
            $coords = $segmento['coords'];
            for ($i = 0; $i < count($coords) - 1; $i++) {
                $d = NetworkGraph::distanciaMetros(
                    (float) $coords[$i][1],
                    (float) $coords[$i][0],
                    (float) $coords[$i + 1][1],
                    (float) $coords[$i + 1][0]
                );
                if ($d <= 0.01) {
                    $avisos[] = [
                        'codigo' => 'W06',
                        'mensaje' => 'Vértices consecutivos duplicados en ' . $segmento['id'] . '.',
                        'punto' => ['lat' => (float) $coords[$i][1], 'lng' => (float) $coords[$i][0]],
                        'ids' => [$segmento['id']],
                    ];
                    break;
                }
            }
            if (count($avisos) >= self::MAX_AVISOS_POR_TIPO) {
                break;
            }
        }
        return $avisos;
    }

    // ==========================================
    // DIFERENCIAS Y AUDITORÍA
    // ==========================================

    /**
     * @param array<string, array> $antes
     * @param array<int, array> $despues
     * @return array{anadidos: array<int, string>, modificados: array<int, string>, eliminados: array<int, string>}
     */
    public function diferencias(array $antes, array $despues): array
    {
        $idsAntes = array_keys($antes);
        $idsDespues = [];
        $modificados = [];

        foreach ($despues as $feature) {
            $id = (string) ($feature['properties']['id'] ?? '');
            $idsDespues[] = $id;
            if (!isset($antes[$id])) {
                continue;
            }
            $coordsAntes = $antes[$id]['geometry']['coordinates'] ?? [];
            $coordsDespues = $feature['geometry']['coordinates'] ?? [];
            if (json_encode($coordsAntes) !== json_encode($coordsDespues)) {
                $modificados[] = $id;
            }
        }

        return [
            'anadidos' => array_values(array_diff($idsDespues, $idsAntes)),
            'modificados' => $modificados,
            'eliminados' => array_values(array_diff($idsAntes, $idsDespues)),
        ];
    }

    private function auditar(array $evento): void
    {
        try {
            $this->auditoria->registrar($evento);
        } catch (Throwable $e) {
            // La auditoría es auxiliar: no debe impedir un guardado ya aplicado.
            error_log('NetworkService: no se pudo registrar la auditoría: ' . $e->getMessage());
        }
    }

    // ==========================================
    // UTILIDADES GEOMÉTRICAS
    // ==========================================

    /**
     * Recuadro válido = bounding box de la red vigente + 300 m de margen.
     *
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    public function recuadroValido(array $geojson): array
    {
        $minLat = INF;
        $maxLat = -INF;
        $minLng = INF;
        $maxLng = -INF;

        foreach (($geojson['features'] ?? []) as $feature) {
            $coords = $feature['geometry']['coordinates'] ?? [];
            if (!is_array($coords)) {
                continue;
            }
            foreach ($coords as $punto) {
                if (!is_array($punto) || count($punto) < 2) {
                    continue;
                }
                $lat = (float) $punto[1];
                $lng = (float) $punto[0];
                if ($lat < $minLat) {
                    $minLat = $lat;
                }
                if ($lat > $maxLat) {
                    $maxLat = $lat;
                }
                if ($lng < $minLng) {
                    $minLng = $lng;
                }
                if ($lng > $maxLng) {
                    $maxLng = $lng;
                }
            }
        }

        if (!is_finite($minLat) || $maxLat <= $minLat || $maxLng <= $minLng) {
            return self::RECUADRO_RESERVA;
        }

        $margenLat = self::MARGEN_RECUADRO_M / 111320.0;
        $cos = cos($minLat * M_PI / 180);
        $margenLng = self::MARGEN_RECUADRO_M / max(1.0, 111320.0 * abs($cos));

        return [
            'minLat' => $minLat - $margenLat,
            'maxLat' => $maxLat + $margenLat,
            'minLng' => $minLng - $margenLng,
            'maxLng' => $maxLng + $margenLng,
        ];
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function centroBoundingBox(?array $geometria): ?array
    {
        if (!is_array($geometria)) {
            return null;
        }

        $minLat = INF;
        $maxLat = -INF;
        $minLng = INF;
        $maxLng = -INF;

        $recorrer = function ($coords) use (&$recorrer, &$minLat, &$maxLat, &$minLng, &$maxLng): void {
            if (!is_array($coords) || $coords === []) {
                return;
            }
            if (is_numeric($coords[0])) {
                $lng = (float) $coords[0];
                $lat = (float) $coords[1];
                if ($lat < $minLat) {
                    $minLat = $lat;
                }
                if ($lat > $maxLat) {
                    $maxLat = $lat;
                }
                if ($lng < $minLng) {
                    $minLng = $lng;
                }
                if ($lng > $maxLng) {
                    $maxLng = $lng;
                }
                return;
            }
            foreach ($coords as $parte) {
                $recorrer($parte);
            }
        };

        $recorrer($geometria['coordinates'] ?? []);

        if (!is_finite($minLat)) {
            return null;
        }

        return [
            'lat' => ($minLat + $maxLat) / 2,
            'lng' => ($minLng + $maxLng) / 2,
        ];
    }

    /**
     * Features con su longitud total (para W04, W05 y W06).
     *
     * @return array<int, array{id: string, coords: array, a: array{lat: float, lng: float}, b: array{lat: float, lng: float}, longitud: float}>
     */
    private function featuresCrudas(array $geojson): array
    {
        $salida = [];
        foreach (($geojson['features'] ?? []) as $feature) {
            $id = (string) ($feature['properties']['id'] ?? ($feature['id'] ?? ''));
            $coords = $feature['geometry']['coordinates'] ?? [];
            if ($id === '' || !is_array($coords) || count($coords) < 2) {
                continue;
            }
            $salida[] = [
                'id' => $id,
                'coords' => $coords,
                'a' => ['lat' => (float) $coords[0][1], 'lng' => (float) $coords[0][0]],
                'b' => [
                    'lat' => (float) $coords[count($coords) - 1][1],
                    'lng' => (float) $coords[count($coords) - 1][0],
                ],
                'longitud' => $this->longitudPolilinea($coords),
            ];
        }
        return $salida;
    }

    /**
     * Aristas individuales de cada segmento (para W02 y W03).
     *
     * @return array<int, array{id: string, a: array{lat: float, lng: float}, b: array{lat: float, lng: float}}>
     */
    private function aristasCrudas(array $geojson): array
    {
        $salida = [];
        foreach (($geojson['features'] ?? []) as $feature) {
            $id = (string) ($feature['properties']['id'] ?? ($feature['id'] ?? ''));
            $coords = $feature['geometry']['coordinates'] ?? [];
            if ($id === '' || !is_array($coords) || count($coords) < 2) {
                continue;
            }
            for ($i = 0; $i < count($coords) - 1; $i++) {
                $salida[] = [
                    'id' => $id,
                    'a' => ['lat' => (float) $coords[$i][1], 'lng' => (float) $coords[$i][0]],
                    'b' => ['lat' => (float) $coords[$i + 1][1], 'lng' => (float) $coords[$i + 1][0]],
                ];
            }
        }
        return $salida;
    }

    private function longitudPolilinea(array $coords): float
    {
        $total = 0.0;
        for ($i = 0; $i < count($coords) - 1; $i++) {
            $total += NetworkGraph::distanciaMetros(
                (float) $coords[$i][1],
                (float) $coords[$i][0],
                (float) $coords[$i + 1][1],
                (float) $coords[$i + 1][0]
            );
        }
        return $total;
    }

    /**
     * Distancia mínima de un punto a las aristas de la red.
     *
     * @param array<int, array{id: string, a: array, b: array}> $segmentos
     * @return array{id: string, distancia: float}|null
     */
    private function distanciaMinimaASegmentos(
        float $lat,
        float $lng,
        array $segmentos,
        float $ignorarMenorQue = 0.0
    ): ?array {
        $mejor = null;
        $mejorDist = INF;

        foreach ($segmentos as $segmento) {
            $proy = NetworkGraph::proyectarPuntoEnSegmento(
                $lat,
                $lng,
                $segmento['a']['lat'],
                $segmento['a']['lng'],
                $segmento['b']['lat'],
                $segmento['b']['lng']
            );
            if ($proy['dist'] < $ignorarMenorQue) {
                continue;
            }
            if ($proy['dist'] < $mejorDist) {
                $mejorDist = $proy['dist'];
                $mejor = ['id' => $segmento['id'], 'distancia' => $proy['dist']];
            }
        }

        return $mejor;
    }

    /**
     * Intersección propia de dos segmentos (excluye extremos compartidos).
     *
     * @return array{lat: float, lng: float}|null
     */
    private function interseccionSegmentos(
        float $aLat,
        float $aLng,
        float $bLat,
        float $bLng,
        float $cLat,
        float $cLng,
        float $dLat,
        float $dLng
    ): ?array {
        // Plano local en metros: suficiente a escala de campus.
        $cos = cos($aLat * M_PI / 180);
        $ax = $aLng * 111320 * $cos;
        $ay = $aLat * 111320;
        $bx = $bLng * 111320 * $cos;
        $by = $bLat * 111320;
        $cx = $cLng * 111320 * $cos;
        $cy = $cLat * 111320;
        $dx = $dLng * 111320 * $cos;
        $dy = $dLat * 111320;

        $rx = $bx - $ax;
        $ry = $by - $ay;
        $sx = $dx - $cx;
        $sy = $dy - $cy;

        $denominador = $rx * $sy - $ry * $sx;
        if (abs($denominador) < 1e-9) {
            return null;
        }

        $qx = $cx - $ax;
        $qy = $cy - $ay;
        $t = ($qx * $sy - $qy * $sx) / $denominador;
        $u = ($qx * $ry - $qy * $rx) / $denominador;

        if ($t <= 1e-9 || $t >= 1 - 1e-9 || $u <= 1e-9 || $u >= 1 - 1e-9) {
            return null;
        }

        return [
            'lat' => ($ay + $t * $ry) / 111320,
            'lng' => ($ax + $t * $rx) / (111320 * $cos),
        ];
    }

    /**
     * ¿Dos polilíneas son casi idénticas (mismos vértices a ≤0,5 m)?
     */
    private function polilineasSimilares(array $a, array $b): bool
    {
        if (count($a) !== count($b) || count($a) < 2) {
            return false;
        }

        for ($i = 0; $i < count($a); $i++) {
            if (NetworkGraph::distanciaMetros(
                (float) $a[$i][1],
                (float) $a[$i][0],
                (float) $b[$i][1],
                (float) $b[$i][0]
            ) > 0.5) {
                return false;
            }
        }

        return true;
    }
}
