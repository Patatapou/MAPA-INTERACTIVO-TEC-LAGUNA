<?php
// ==========================================
// PRUEBAS DE VALIDACIÓN DE LA RED (E5.2)
// ==========================================
// Un caso por cada error E01–E08 y por cada advertencia W01–W06 del
// `NetworkService`, incluida la regresión de alcanzabilidad (E08) y la
// asignación de IDs definitivos a los segmentos `tmp-`.
//
// Usa un repositorio temporal y una auditoría temporal: no toca la red real
// ni `database/runtime/audit-log.jsonl`.
//
// Uso: php tests/red/validacion-red.php

declare(strict_types=1);

require_once __DIR__ . '/../_pruebas.php';
require_once __DIR__ . '/../../backend/services/NetworkService.php';
require_once __DIR__ . '/../../backend/services/NetworkGraph.php';

echo "=== Validación de la red de caminos ===\n";

// ---------- Ayudas ----------

/**
 * @param array<int, array> $features
 * @return array
 */
function redPropuesta(array $features, int $revision = 1, int $nextSeq = 2): array
{
    return [
        'type' => 'FeatureCollection',
        'schema_version' => 2,
        'revision' => $revision,
        'next_seq' => $nextSeq,
        'features' => $features,
    ];
}

/**
 * @return array
 */
function segRed(string $id, array $desde, array $hasta): array
{
    return [
        'type' => 'Feature',
        'id' => $id,
        'properties' => ['id' => $id],
        'geometry' => [
            'type' => 'LineString',
            'coordinates' => [
                [(float) $desde[1], (float) $desde[0]],
                [(float) $hasta[1], (float) $hasta[0]],
            ],
        ],
    ];
}

/**
 * @param array<int, array{codigo: string}> $errores
 * @return array<int, string>
 */
function codigosDe(array $errores): array
{
    return array_values(array_map(static fn (array $e): string => $e['codigo'], $errores));
}

/** Red mínima de referencia para el recuadro. */
$redBase = redPropuesta([
    segRed('seg-000001', [25.5300, -103.4360], [25.5301, -103.4361]),
]);

$servicio = new NetworkService();

// ---------- E01–E05 / E07 ----------
echo "\n[E01–E05 / E07]\n";

afirmar(
    'E01: tipo raíz distinto de FeatureCollection',
    in_array('E01', codigosDe($servicio->validarEstructura(['type' => 'Point', 'features' => []], $redBase)), true)
);

afirmar(
    'E02: geometría que no es LineString',
    in_array('E02', codigosDe($servicio->validarEstructura(redPropuesta([
        ['type' => 'Feature', 'properties' => ['id' => 'tmp-1'], 'geometry' => ['type' => 'Point', 'coordinates' => [-103.4360, 25.5300]]],
    ]), $redBase)), true)
);

afirmar(
    'E03: segmento con un solo vértice',
    in_array('E03', codigosDe($servicio->validarEstructura(redPropuesta([
        ['type' => 'Feature', 'properties' => ['id' => 'tmp-1'], 'geometry' => ['type' => 'LineString', 'coordinates' => [[-103.4360, 25.5300]]]],
    ]), $redBase)), true)
);

afirmar(
    'E03: segmento de longitud cero',
    in_array('E03', codigosDe($servicio->validarEstructura(redPropuesta([
        segRed('tmp-1', [25.5300, -103.4360], [25.5300, -103.4360]),
    ]), $redBase)), true)
);

afirmar(
    'E04: coordenada fuera del recuadro del campus',
    in_array('E04', codigosDe($servicio->validarEstructura(redPropuesta([
        segRed('tmp-1', [25.5400, -103.4000], [25.5401, -103.4001]),
    ]), $redBase)), true)
);

afirmar(
    'E05: ID con formato inválido',
    in_array('E05', codigosDe($servicio->validarEstructura(redPropuesta([
        segRed('segmento-1', [25.5300, -103.4360], [25.5301, -103.4361]),
    ]), $redBase)), true)
);

afirmar(
    'E05: ID duplicado',
    in_array('E05', codigosDe($servicio->validarEstructura(redPropuesta([
        segRed('tmp-1', [25.5300, -103.4360], [25.5301, -103.4361]),
        segRed('tmp-1', [25.5301, -103.4361], [25.5302, -103.4362]),
    ]), $redBase)), true)
);

afirmar(
    'E05: ID definitivo desconocido (propuesta manipulada)',
    in_array('E05', codigosDe($servicio->validarEstructura(redPropuesta([
        segRed('seg-000999', [25.5300, -103.4360], [25.5301, -103.4361]),
    ]), $redBase)), true)
);

$muchas = [];
for ($i = 1; $i <= NetworkService::MAX_SEGMENTOS + 1; $i++) {
    $muchas[] = segRed(sprintf('tmp-%d', $i), [25.5300, -103.4360], [25.5301, -103.4361]);
}
afirmar(
    'E07: supera el máximo de segmentos',
    in_array('E07', codigosDe($servicio->validarEstructura(redPropuesta($muchas), $redBase)), true)
);
unset($muchas);

// ---------- Advertencias W01–W06 ----------
echo "\n[W01–W06]\n";

/**
 * @return array<int, string>
 */
function avisosDe(array $geojson): array
{
    $servicioLocal = new NetworkService();
    $avisos = $servicioLocal->calcularAdvertencias($geojson, NetworkGraph::construir($geojson));
    return array_values(array_unique(array_map(static fn (array $a): string => $a['codigo'], $avisos)));
}

$dosIslas = redPropuesta([
    segRed('seg-000001', [25.5300, -103.4360], [25.5301, -103.4360]),
    segRed('seg-000002', [25.5340, -103.4340], [25.5341, -103.4340]),
]);
afirmar('W01: dos componentes conexas', in_array('W01', avisosDe($dosIslas), true));

$casiConectado = redPropuesta([
    segRed('seg-000001', [25.5300, -103.4360], [25.5301, -103.4360]),
    segRed('seg-000002', [25.5299, -103.43605], [25.5302, -103.43605]),
]);
afirmar('W02: extremo libre casi conectado (2,5–8 m)', in_array('W02', avisosDe($casiConectado), true));

$cruzan = redPropuesta([
    segRed('seg-000001', [25.5300, -103.4360], [25.5300, -103.4358]),
    segRed('seg-000002', [25.5299, -103.4359], [25.5301, -103.4359]),
]);
afirmar('W03: cruce sin nodo', in_array('W03', avisosDe($cruzan), true));

$cortos = redPropuesta([
    segRed('seg-000001', [25.5300, -103.4360], [25.530001, -103.436001]),
]);
afirmar('W04: segmento de menos de 2,5 m', in_array('W04', avisosDe($cortos), true));

$solapados = redPropuesta([
    segRed('seg-000001', [25.5300, -103.4360], [25.5301, -103.4361]),
    segRed('seg-000002', [25.5300, -103.4360], [25.5301, -103.4361]),
]);
afirmar('W05: segmentos solapados', in_array('W05', avisosDe($solapados), true));

$duplicados = redPropuesta([
    [
        'type' => 'Feature',
        'id' => 'seg-000001',
        'properties' => ['id' => 'seg-000001'],
        'geometry' => [
            'type' => 'LineString',
            'coordinates' => [
                [-103.4360, 25.5300],
                [-103.4360, 25.5300],
                [-103.4361, 25.5301],
            ],
        ],
    ],
]);
afirmar('W06: vértices consecutivos duplicados', in_array('W06', avisosDe($duplicados), true));

// ---------- E08 y guardado ----------
echo "\n[E08 y guardado]\n";

$dir = crearDirTemporal('red-servicio');
$dirAudit = crearDirTemporal('red-servicio-audit');
$archivo = $dir . '/caminitos.geojson';

// Cadena A—B—C—D—E: el par A→E necesita toda la cadena.
$nodos = [
    [25.53000, -103.43700],
    [25.53000, -103.43680],
    [25.53000, -103.43660],
    [25.53000, -103.43640],
    [25.53000, -103.43620],
];
$cadena = [];
for ($i = 0; $i < 4; $i++) {
    $cadena[] = segRed(sprintf('seg-%06d', $i + 1), $nodos[$i], $nodos[$i + 1]);
}
$redInicial = redPropuesta($cadena, 1, 5);
file_put_contents($archivo, json_encode($redInicial, JSON_PRETTY_PRINT));

try {
    $repo = new NetworkRepository($archivo, $dir . '/backups', $dir . '/locks');
    $auditoria = new class ($dirAudit) extends AuditLogRepository {
        public function __construct(string $directorio)
        {
            parent::__construct();
            $this->dataDir = $directorio;
        }
    };

    $servicio = new NetworkService($repo, $auditoria);
    $servicio->usarParesReferencia([[
        'id' => 'P1→1',
        'origen' => ['lat' => $nodos[0][0], 'lng' => $nodos[0][1]],
        'destino' => ['lat' => $nodos[4][0], 'lng' => $nodos[4][1]],
    ]]);

    // Propuesta que rompe la cadena en el medio.
    $rota = redPropuesta([
        $cadena[0],
        $cadena[2],
        $cadena[3],
    ], 1, 5);

    $antes = (string) file_get_contents($archivo);
    $excepcion = null;
    try {
        $servicio->guardar($rota, 1, ['id' => 'admin_001', 'username' => 'admin']);
    } catch (NetworkServiceException $e) {
        $excepcion = $e;
    }

    afirmar('E08: la regresión de alcanzabilidad lanza excepción', $excepcion instanceof NetworkServiceException);
    afirmar('E08: responde 400', $excepcion !== null && $excepcion->statusCode() === 400);
    afirmar(
        'E08: incluye el código E08 y el par perdido',
        $excepcion !== null
            && in_array('E08', codigosDe($excepcion->errores()), true)
            && in_array('P1→1', $excepcion->errores()[0]['pares'] ?? [], true),
        $excepcion ? json_encode($excepcion->errores(), JSON_UNESCAPED_UNICODE) : 'sin excepción'
    );
    afirmar(
        'E08: la red persistida no cambia',
        (string) file_get_contents($archivo) === $antes
    );

    // Propuesta válida: añade un segmento provisional conectado al final.
    $valida = redPropuesta(array_merge($cadena, [
        segRed('tmp-nuevo', $nodos[4], [25.53000, -103.43600]),
    ]), 1, 5);

    $resultado = $servicio->guardar($valida, 1, ['id' => 'admin_001', 'username' => 'admin']);
    afirmar('Guardado: la propuesta válida se acepta', ($resultado['success'] ?? false) === true);
    afirmarIgual('Guardado: revisión incrementada', 2, $resultado['revision'] ?? null);
    afirmarIgual(
        'Guardado: el ID tmp- recibe un ID definitivo',
        'seg-000005',
        $resultado['id_map']['tmp-nuevo'] ?? null
    );
    afirmar(
        'Guardado: el segmento nuevo aparece en los cambios',
        in_array('seg-000005', $resultado['cambios']['anadidos'] ?? [], true)
    );

    $persistida = json_decode((string) file_get_contents($archivo), true);
    afirmarIgual('Guardado: 5 segmentos persistidos', 5, count($persistida['features'] ?? []));
    afirmarIgual('Guardado: next_seq actualizado', 6, $persistida['next_seq'] ?? null);

    $evento = null;
    $lineas = file($dirAudit . '/audit-log.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lineas as $linea) {
        $decodificado = json_decode($linea, true);
        if (($decodificado['event'] ?? '') === 'red_actualizada') {
            $evento = $decodificado;
        }
    }
    afirmar('Auditoría: se registra red_actualizada', $evento !== null);
    afirmar(
        'Auditoría: incluye usuario y revisión nueva',
        $evento !== null && ($evento['username'] ?? '') === 'admin' && ($evento['revision_nueva'] ?? 0) === 2
    );

    // Repositorio: una revisión vieja ya no puede guardar.
    $conflicto = null;
    try {
        $servicio->guardar($valida, 1, []);
    } catch (RevisionObsoletaException $e) {
        $conflicto = $e;
    }
    afirmar('Revisión: la segunda escritura con base vieja da 409', $conflicto instanceof RevisionObsoletaException);
} catch (Throwable $e) {
    afirmar('E08: ejecución sin excepciones inesperadas', false, $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
} finally {
    borrarDir($dir);
    borrarDir($dirAudit);
}

salirConResumen('validacion-red');
