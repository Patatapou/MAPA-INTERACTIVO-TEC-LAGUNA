<?php
// ==========================================
// PRUEBAS DEL SERVICIO DE RUTAS (16.5)
// ==========================================
// Reglas de negocio sobre la red real (`caminitos.geojson`, solo lectura) y
// persistencia en directorios temporales.
//
// Uso: php tests/rutas-service.php

declare(strict_types=1);

require_once __DIR__ . '/_pruebas.php';
require_once __DIR__ . '/../backend/repositories/RouteRepository.php';
require_once __DIR__ . '/../backend/repositories/AuditLogRepository.php';
require_once __DIR__ . '/../backend/services/PathNetworkService.php';
require_once __DIR__ . '/../backend/services/RouteService.php';

echo "=== Servicio de rutas ===\n";

$raiz = dirname(__DIR__);
$rutaDatos = $raiz . '/frontend/data/datos.json';

// ---------- Contexto compartido ----------

/** @var PathNetworkService $red */
$red = new PathNetworkService();
$indice = $red->indice();
afirmar('0. La red tiene segmentos', count($indice) > 0, 'segmentos: ' . count($indice));

/**
 * Buscar una pareja contigua (a.fin ≈ b.inicio) en la red.
 *
 * @param array<string, array> $indice
 * @return array{0: array, 1: array}|null
 */
function parejaContigua(array $indice): ?array
{
    $valores = array_values($indice);
    foreach ($valores as $a) {
        foreach ($valores as $b) {
            if ($a['id'] === $b['id']) {
                continue;
            }
            [$aLat, $aLng] = array_map('floatval', explode(',', $a['fin']));
            [$bLat, $bLng] = array_map('floatval', explode(',', $b['inicio']));
            if (PathNetworkService::distanciaMetros($aLat, $aLng, $bLat, $bLng) <= 0.5) {
                return [$a, $b];
            }
        }
    }
    return null;
}

/**
 * Buscar un segmento que no sea contiguo al extremo indicado.
 *
 * @param array<string, array> $indice
 * @param array<string, bool> $excluir
 * @param string $extremo Clave `lat,lng` del extremo de la cadena
 */
function segmentoLejano(array $indice, array $excluir, string $extremo): ?array
{
    [$eLat, $eLng] = array_map('floatval', explode(',', $extremo));
    foreach ($indice as $segmento) {
        if (isset($excluir[$segmento['id']])) {
            continue;
        }
        [$sLat, $sLng] = array_map('floatval', explode(',', $segmento['inicio']));
        if (PathNetworkService::distanciaMetros($eLat, $eLng, $sLat, $sLng) > 3.0) {
            return $segmento;
        }
    }
    return null;
}

$pareja = parejaContigua($indice);
if ($pareja === null) {
    fwrite(STDERR, "La red no contiene dos segmentos contiguos.\n");
    exit(1);
}
[$segmentoA, $segmentoB] = $pareja;

$datosEdificios = json_decode((string) file_get_contents($rutaDatos), true);
$refEdificio = null;
$refAcceso = null;
foreach ((array) $datosEdificios as $clave => $registro) {
    $tipo = (string) ($registro['tipo'] ?? 'edificio');
    if ($tipo === 'acceso' && $refAcceso === null) {
        $refAcceso = (string) $clave;
    }
    if ($tipo !== 'acceso' && $refEdificio === null) {
        $refEdificio = (string) $clave;
    }
}
if ($refEdificio === null || $refAcceso === null) {
    fwrite(STDERR, "datos.json no contiene edificios y accesos utilizables.\n");
    exit(1);
}

/**
 * Crear un servicio aislado (datos y auditoría en directorios temporales).
 *
 * @return array{servicio: RouteService, repo: RouteRepository, dir: string, dirAudit: string}
 */
function nuevoServicio(string $prefijo, ?string $rutaDatos = null): array
{
    $dir = crearDirTemporal($prefijo);
    $dirAudit = crearDirTemporal($prefijo . '-audit');
    $repo = new RouteRepository($dir);
    $auditoria = new class ($dirAudit) extends AuditLogRepository {
        public function __construct(string $dir)
        {
            parent::__construct();
            $this->dataDir = $dir;
        }
    };
    $servicio = new RouteService(
        $repo,
        new PathNetworkService(),
        $auditoria,
        $rutaDatos ?? (dirname(__DIR__) . '/frontend/data/datos.json')
    );

    return ['servicio' => $servicio, 'repo' => $repo, 'dir' => $dir, 'dirAudit' => $dirAudit];
}

/**
 * Entrada válida reutilizable.
 *
 * @return array<string, mixed>
 */
function entradaValida(string $nombre, array $cadena, string $refOrigen, string $refDestino, ?string $id = null, string $updatedAt = ''): array
{
    $entrada = [
        'nombre' => $nombre,
        'descripcion' => 'Ruta de prueba automatizada.',
        'tipo' => 'principal',
        'estado' => 'borrador',
        'accesible' => '1',
        'origen' => ['tipo' => 'edificio', 'ref' => $refOrigen],
        'destino' => ['tipo' => 'acceso', 'ref' => $refDestino],
        'segmentos' => $cadena,
    ];
    if ($id !== null) {
        $entrada['id'] = $id;
        $entrada['updated_at'] = $updatedAt;
    }
    return $entrada;
}

// ---------- Caso 1: nombre duplicado ----------
$ctx1 = nuevoServicio('svc-duplicado', $rutaDatos);
try {
    $servicio = $ctx1['servicio'];
    $servicio->guardar(
        entradaValida('Ruta repetida', [['id' => $segmentoA['id'], 'sentido' => 'normal']], $refEdificio, $refAcceso),
        ['id' => 'test_001', 'username' => 'pruebas']
    );

    $excepcion = null;
    try {
        $servicio->guardar(
            entradaValida('RUTA REPETIDA', [['id' => $segmentoA['id'], 'sentido' => 'normal']], $refEdificio, $refAcceso),
            ['id' => 'test_001', 'username' => 'pruebas']
        );
    } catch (RouteValidationException $e) {
        $excepcion = $e;
    }

    afirmar('1. Nombre duplicado: se rechaza', $excepcion !== null);
    afirmarIgual('1. Nombre duplicado: código 400', 400, $excepcion ? $excepcion->statusCode() : 0);
    afirmar(
        '1. Nombre duplicado: el mensaje lo explica',
        $excepcion !== null && str_contains(implode(' ', $excepcion->detalles()), 'está en uso'),
        $excepcion ? implode(' | ', $excepcion->detalles()) : ''
    );
} catch (Throwable $e) {
    afirmar('1. Nombre duplicado: sin excepciones inesperadas', false, $e->getMessage());
}

// ---------- Caso 2: segmento inexistente ----------
$ctx2 = nuevoServicio('svc-inexistente', $rutaDatos);
try {
    $excepcion = null;
    try {
        $ctx2['servicio']->guardar(
            entradaValida('Ruta con segmento fantasma', [['id' => 'seg-00000000', 'sentido' => 'normal']], $refEdificio, $refAcceso),
            ['id' => 'test_001', 'username' => 'pruebas']
        );
    } catch (RouteValidationException $e) {
        $excepcion = $e;
    }

    afirmar('2. Segmento inexistente: se rechaza', $excepcion !== null);
    afirmar(
        '2. Segmento inexistente: el detalle lo indica',
        $excepcion !== null && str_contains(implode(' ', $excepcion->detalles()), 'inexistente'),
        $excepcion ? implode(' | ', $excepcion->detalles()) : ''
    );
    afirmarIgual('2. Segmento inexistente: no se escribe ninguna ruta', 0, count($ctx2['repo']->listar()['rutas']));
} catch (Throwable $e) {
    afirmar('2. Segmento inexistente: sin excepciones inesperadas', false, $e->getMessage());
}

// ---------- Caso 3: cadena discontinua ----------
$ctx3 = nuevoServicio('svc-discontinua', $rutaDatos);
try {
    $lejano = segmentoLejano($indice, [$segmentoA['id'] => true, $segmentoB['id'] => true], $segmentoA['fin']);
    if ($lejano === null) {
        throw new RuntimeException('No se encontró un segmento lejano para la prueba.');
    }

    $excepcion = null;
    try {
        $ctx3['servicio']->guardar(
            entradaValida(
                'Ruta discontinua',
                [
                    ['id' => $segmentoA['id'], 'sentido' => 'normal'],
                    ['id' => $lejano['id'], 'sentido' => 'normal'],
                ],
                $refEdificio,
                $refAcceso
            ),
            ['id' => 'test_001', 'username' => 'pruebas']
        );
    } catch (RouteValidationException $e) {
        $excepcion = $e;
    }

    afirmar('3. Cadena discontinua: se rechaza', $excepcion !== null);
    afirmar(
        '3. Cadena discontinua: el detalle lo indica',
        $excepcion !== null && str_contains(implode(' ', $excepcion->detalles()), 'discontinua'),
        $excepcion ? implode(' | ', $excepcion->detalles()) : ''
    );
} catch (Throwable $e) {
    afirmar('3. Cadena discontinua: sin excepciones inesperadas', false, $e->getMessage());
}

// ---------- Caso 4: origen inexistente ----------
$ctx4 = nuevoServicio('svc-origen', $rutaDatos);
try {
    $excepcion = null;
    try {
        $ctx4['servicio']->guardar(
            entradaValida('Ruta con origen fantasma', [['id' => $segmentoA['id'], 'sentido' => 'normal']], 'NO-EXISTE', $refAcceso),
            ['id' => 'test_001', 'username' => 'pruebas']
        );
    } catch (RouteValidationException $e) {
        $excepcion = $e;
    }

    afirmar('4. Origen inexistente: se rechaza', $excepcion !== null);
    afirmar(
        '4. Origen inexistente: el detalle lo indica',
        $excepcion !== null && str_contains(implode(' ', $excepcion->detalles()), 'no existe en datos.json'),
        $excepcion ? implode(' | ', $excepcion->detalles()) : ''
    );

    // Referencia de tipo equivocado (un acceso usado como edificio) también falla.
    $excepcionTipo = null;
    try {
        $ctx4['servicio']->guardar(
            entradaValida('Ruta con tipo cambiado', [['id' => $segmentoA['id'], 'sentido' => 'normal']], $refAcceso, $refAcceso),
            ['id' => 'test_001', 'username' => 'pruebas']
        );
    } catch (RouteValidationException $e) {
        $excepcionTipo = $e;
    }
    afirmar('4. Origen inexistente: el tipo de referencia se valida', $excepcionTipo !== null);
} catch (Throwable $e) {
    afirmar('4. Origen inexistente: sin excepciones inesperadas', false, $e->getMessage());
}

// ---------- Caso 5: actualizar sin cambios ----------
$ctx5 = nuevoServicio('svc-sin-cambios', $rutaDatos);
try {
    $servicio = $ctx5['servicio'];
    $cadena = [['id' => $segmentoA['id'], 'sentido' => 'normal']];
    $creada = $servicio->guardar(
        entradaValida('Ruta estable', $cadena, $refEdificio, $refAcceso),
        ['id' => 'test_001', 'username' => 'pruebas']
    );

    $actualizada = $servicio->guardar(
        entradaValida('Ruta estable', $cadena, $refEdificio, $refAcceso, $creada['id'], $creada['updated_at']),
        ['id' => 'test_001', 'username' => 'pruebas']
    );

    afirmarIgual('5. Sin cambios: el nombre se mantiene', 'Ruta estable', $actualizada['nombre']);
    afirmarIgual('5. Sin cambios: los segmentos se mantienen', 1, $actualizada['n_segmentos']);
    afirmarIgual('5. Sin cambios: `creado_en` no cambia', $creada['creado_en'], $actualizada['creado_en']);
    afirmar(
        '5. Sin cambios: `actualizado_en` se refresca',
        $actualizada['actualizado_en'] !== '' && $actualizada['actualizado_en'] >= $creada['actualizado_en']
    );
} catch (Throwable $e) {
    afirmar('5. Sin cambios: sin excepciones', false, $e->getMessage());
}

// ---------- Caso 6: eliminar ruta inexistente ----------
$ctx6 = nuevoServicio('svc-eliminar', $rutaDatos);
try {
    $excepcion = null;
    try {
        $ctx6['servicio']->eliminar('ruta-00000000', ['id' => 'test_001', 'username' => 'pruebas']);
    } catch (RouteNotFoundException $e) {
        $excepcion = $e;
    }

    afirmar('6. Eliminar inexistente: se rechaza', $excepcion !== null);
    afirmarIgual('6. Eliminar inexistente: código 404', 404, $excepcion ? $excepcion->statusCode() : 0);

    // El ID con formato inválido se rechaza antes de tocar el archivo.
    $excepcionId = null;
    try {
        $ctx6['servicio']->eliminar('../../etc/passwd');
    } catch (RouteValidationException $e) {
        $excepcionId = $e;
    }
    afirmar('6. Eliminar inexistente: el ID se valida', $excepcionId !== null);
} catch (Throwable $e) {
    afirmar('6. Eliminar inexistente: sin excepciones inesperadas', false, $e->getMessage());
}

// ---------- Caso 7: hidratar ----------
$ctx7 = nuevoServicio('svc-hidratar', $rutaDatos);
try {
    $servicio = $ctx7['servicio'];
    $cadena = [
        ['id' => $segmentoA['id'], 'sentido' => 'normal'],
        ['id' => $segmentoB['id'], 'sentido' => 'normal'],
    ];
    $creada = $servicio->guardar(
        entradaValida('Ruta hidratada', $cadena, $refEdificio, $refAcceso),
        ['id' => 'test_001', 'username' => 'pruebas']
    );

    $detalle = $servicio->obtener($creada['id']);

    $distanciaEsperada = round($segmentoA['longitud_m'] + $segmentoB['longitud_m'], 1);
    afirmarIgual('7. Hidratar: la distancia es la suma de segmentos', $distanciaEsperada, (float) $detalle['distancia_m']);
    afirmarIgual('7. Hidratar: n_segmentos', 2, $detalle['n_segmentos']);

    $nodosEsperados = count($segmentoA['coordenadas']) + count($segmentoB['coordenadas']) - 1;
    afirmarIgual('7. Hidratar: número de nodos', $nodosEsperados, count($detalle['nodos']));

    $coordenadas = $detalle['geometria']['coordinates'];
    afirmarIgual('7. Hidratar: la geometría empieza en el primer vértice', $segmentoA['coordenadas'][0], $coordenadas[0]);
    afirmarIgual(
        '7. Hidratar: la geometría termina en el último vértice',
        $segmentoB['coordenadas'][count($segmentoB['coordenadas']) - 1],
        $coordenadas[count($coordenadas) - 1]
    );
    afirmarIgual(
        '7. Hidratar: se elimina el vértice de unión duplicado',
        count($segmentoA['coordenadas']) + count($segmentoB['coordenadas']) - 1,
        count($coordenadas)
    );
    afirmar('7. Hidratar: la ruta es consistente', $detalle['inconsistente'] === false);
} catch (Throwable $e) {
    afirmar('7. Hidratar: sin excepciones', false, $e->getMessage());
}

// ---------- Caso 8: auditoría ----------
$ctx8 = nuevoServicio('svc-auditoria', $rutaDatos);
try {
    $servicio = $ctx8['servicio'];
    $creada = $servicio->guardar(
        entradaValida('Ruta auditada', [['id' => $segmentoA['id'], 'sentido' => 'normal']], $refEdificio, $refAcceso),
        ['id' => 'test_001', 'username' => 'pruebas']
    );
    $servicio->eliminar($creada['id'], ['id' => 'test_001', 'username' => 'pruebas']);

    $archivoAudit = $ctx8['dirAudit'] . '/audit-log.jsonl';
    afirmar('8. Auditoría: se crea el registro', file_exists($archivoAudit));

    $eventos = [];
    foreach (file($archivoAudit, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
        $decodificado = json_decode($linea, true);
        if (is_array($decodificado)) {
            $eventos[] = $decodificado;
        }
    }

    $creados = array_values(array_filter($eventos, static fn(array $e): bool => ($e['event'] ?? '') === 'ruta_creada'));
    $eliminados = array_values(array_filter($eventos, static fn(array $e): bool => ($e['event'] ?? '') === 'ruta_eliminada'));

    afirmarIgual('8. Auditoría: evento ruta_creada', 1, count($creados));
    afirmarIgual('8. Auditoría: evento ruta_eliminada', 1, count($eliminados));
    afirmarIgual(
        '8. Auditoría: el evento apunta a la ruta',
        $creada['id'],
        $creados[0]['ruta_id'] ?? null
    );
    afirmarIgual(
        '8. Auditoría: se registra el usuario',
        'test_001',
        $creados[0]['user_id'] ?? null
    );
} catch (Throwable $e) {
    afirmar('8. Auditoría: sin excepciones', false, $e->getMessage());
}

// ---------- Limpieza ----------
foreach ([$ctx1, $ctx2, $ctx3, $ctx4, $ctx5, $ctx6, $ctx7, $ctx8] as $ctx) {
    borrarDir($ctx['dir']);
    borrarDir($ctx['dirAudit']);
}

salirConResumen('rutas-service');
