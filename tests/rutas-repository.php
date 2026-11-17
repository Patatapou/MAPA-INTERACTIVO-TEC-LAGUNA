<?php
// ==========================================
// PRUEBAS DEL REPOSITORIO DE RUTAS (16.4)
// ==========================================
// Verifica persistencia, fail-closed, respaldo, bloqueo, concurrencia y
// migración de `PHP/data/rutas.json`. Todo se ejecuta en directorios
// temporales: no toca los datos reales.
//
// Uso: php tests/rutas-repository.php

declare(strict_types=1);

require_once __DIR__ . '/_pruebas.php';
require_once __DIR__ . '/../PHP/repositories/RouteRepository.php';
require_once __DIR__ . '/../PHP/repositories/PathNetworkRepository.php';
require_once __DIR__ . '/../PHP/services/PathNetworkService.php';
require_once __DIR__ . '/../PHP/services/RouteService.php';

echo "=== Repositorio de rutas ===\n";

// ---------- Caso 1: archivo ausente ----------
$dir1 = crearDirTemporal('repo-ausente');
try {
    $repo = new RouteRepository($dir1);
    $documento = $repo->listar();
    afirmar('1. Archivo ausente: listar() no lanza', true);
    afirmarIgual('1. Archivo ausente: rutas vacías', [], $documento['rutas']);
    afirmarIgual('1. Archivo ausente: schema_version', RouteRepository::SCHEMA_ACTUAL, $documento['schema_version']);
} catch (Throwable $e) {
    afirmar('1. Archivo ausente: listar() no lanza', false, $e->getMessage());
}

// ---------- Caso 2: archivo corrupto ----------
$dir2 = crearDirTemporal('repo-corrupto');
try {
    $rutaCorrupta = $dir2 . '/rutas.json';
    file_put_contents($rutaCorrupta, '{"schema_version":1,"rutas":{"roto":');
    $original = file_get_contents($rutaCorrupta);

    $repo = new RouteRepository($dir2);
    $excepcion = null;
    try {
        $repo->listar();
    } catch (RuntimeException $e) {
        $excepcion = $e;
    }

    afirmar('2. Archivo corrupto: listar() lanza excepción', $excepcion instanceof RuntimeException);
    afirmar(
        '2. Archivo corrupto: el mensaje explica el fallo',
        $excepcion !== null && $excepcion->getMessage() !== '',
        $excepcion ? $excepcion->getMessage() : 'sin excepción'
    );
    afirmar(
        '2. Archivo corrupto: el archivo queda intacto',
        file_get_contents($rutaCorrupta) === $original
    );
} catch (Throwable $e) {
    afirmar('2. Archivo corrupto: el archivo queda intacto', false, $e->getMessage());
}

// ---------- Caso 3: crear + respaldo ----------
$dir3 = crearDirTemporal('repo-crear');
try {
    $repo = new RouteRepository($dir3);
    $id = 'ruta-00000001';

    // Primera escritura: el archivo no existía, no hay .bak todavía.
    $repo->actualizarConBloqueo(static function (array $documento) use ($id): array {
        $documento['rutas'][$id] = [
            'id' => $id,
            'nombre' => 'Ruta de prueba',
            'segmentos' => [['id' => 'seg-00000000', 'sentido' => 'normal']],
            'creado_en' => date('c'),
            'actualizado_en' => date('c'),
        ];
        return ['datos' => $documento, 'resultado' => true];
    });

    $rutaArchivo = $dir3 . '/rutas.json';
    afirmar('3. Crear: el archivo existe', file_exists($rutaArchivo));
    $decodificado = json_decode((string) file_get_contents($rutaArchivo), true);
    afirmar('3. Crear: el archivo es JSON válido', is_array($decodificado));
    afirmar(
        '3. Crear: la ruta está presente',
        is_array($decodificado) && isset($decodificado['rutas'][$id])
    );
    afirmar('3. Crear: sin .bak en la primera escritura', !file_exists($rutaArchivo . '.bak'));

    // Segunda escritura: ya debe existir respaldo.
    $repo->actualizarConBloqueo(static function (array $documento) use ($id): array {
        $documento['rutas'][$id]['nombre'] = 'Ruta de prueba (v2)';
        return ['datos' => $documento, 'resultado' => true];
    });
    afirmar('3. Crear: se crea rutas.json.bak', file_exists($rutaArchivo . '.bak'));

    $respaldo = json_decode((string) file_get_contents($rutaArchivo . '.bak'), true);
    afirmar(
        '3. Crear: el respaldo conserva la versión anterior',
        is_array($respaldo) && ($respaldo['rutas'][$id]['nombre'] ?? '') === 'Ruta de prueba'
    );
    afirmar('3. Crear: no quedan archivos temporales', glob($rutaArchivo . '.tmp.*') === []);
} catch (Throwable $e) {
    afirmar('3. Crear: sin excepciones', false, $e->getMessage());
}

// ---------- Caso 4: actualizar ----------
$dir4 = crearDirTemporal('repo-actualizar');
try {
    $repo = new RouteRepository($dir4);
    $id = 'ruta-00000002';
    $repo->actualizarConBloqueo(static function (array $documento) use ($id): array {
        $documento['rutas'][$id] = [
            'id' => $id,
            'nombre' => 'Inicial',
            'segmentos' => [],
            'creado_en' => '2026-01-01T00:00:00+00:00',
            'actualizado_en' => '2026-01-01T00:00:00+00:00',
        ];
        return ['datos' => $documento, 'resultado' => true];
    });

    $repo->actualizarConBloqueo(static function (array $documento) use ($id): array {
        $documento['rutas'][$id]['nombre'] = 'Actualizada';
        $documento['rutas'][$id]['actualizado_en'] = date('c');
        return ['datos' => $documento, 'resultado' => true];
    });

    $recargado = new RouteRepository($dir4);
    $ruta = $recargado->obtener($id);
    afirmarIgual('4. Actualizar: el nombre persiste', 'Actualizada', $ruta['nombre'] ?? null);
    afirmar(
        '4. Actualizar: `creado_en` no cambia',
        ($ruta['creado_en'] ?? '') === '2026-01-01T00:00:00+00:00'
    );
    afirmar(
        '4. Actualizar: `actualizado_en` avanza',
        ($ruta['actualizado_en'] ?? '') !== '2026-01-01T00:00:00+00:00'
    );
} catch (Throwable $e) {
    afirmar('4. Actualizar: sin excepciones', false, $e->getMessage());
}

// ---------- Caso 5: conflicto de versión (detectado por el servicio) ----------
$dir5 = crearDirTemporal('repo-conflicto');
$dir5Audit = crearDirTemporal('repo-conflicto-audit');
try {
    $repo = new RouteRepository($dir5);
    $red = new PathNetworkService();
    $indice = $red->indice();
    $segmento = (string) array_key_first($indice);
    $datosReales = json_decode((string) file_get_contents(__DIR__ . '/../datos.json'), true);
    $referenciaEdificio = '';
    foreach ((array) $datosReales as $clave => $registro) {
        if (($registro['tipo'] ?? 'edificio') !== 'acceso') {
            $referenciaEdificio = (string) $clave;
            break;
        }
    }

    $auditoria = new class ($dir5Audit) extends AuditLogRepository {
        public function __construct(string $dir)
        {
            parent::__construct();
            $this->dataDir = $dir;
        }
    };

    $servicio = new RouteService(
        $repo,
        $red,
        $auditoria,
        __DIR__ . '/../datos.json'
    );

    $creada = $servicio->guardar([
        'nombre' => 'Ruta con conflicto',
        'tipo' => 'principal',
        'estado' => 'borrador',
        'accesible' => '1',
        'origen' => ['tipo' => 'edificio', 'ref' => $referenciaEdificio],
        'destino' => ['tipo' => 'edificio', 'ref' => $referenciaEdificio],
        'segmentos' => [['id' => $segmento, 'sentido' => 'normal']],
    ], ['id' => 'test_001', 'username' => 'pruebas']);

    $id = (string) $creada['id'];
    $versionReal = (string) $creada['actualizado_en'];

    $conflicto = null;
    try {
        $servicio->guardar([
            'id' => $id,
            'updated_at' => '2020-01-01T00:00:00+00:00',
            'nombre' => 'Intento obsoleto',
            'tipo' => 'principal',
            'estado' => 'borrador',
            'accesible' => '1',
            'origen' => ['tipo' => 'edificio', 'ref' => $referenciaEdificio],
            'destino' => ['tipo' => 'edificio', 'ref' => $referenciaEdificio],
            'segmentos' => [['id' => $segmento, 'sentido' => 'normal']],
        ], ['id' => 'test_001', 'username' => 'pruebas']);
    } catch (RouteConflictException $e) {
        $conflicto = $e;
    }

    afirmar('5. Conflicto: se lanza RouteConflictException', $conflicto !== null);
    afirmarIgual('5. Conflicto: el código es 409', 409, $conflicto ? $conflicto->statusCode() : 0);
    afirmar(
        '5. Conflicto: no se sobrescribe el dato vigente',
        ($repo->obtener($id)['nombre'] ?? '') === 'Ruta con conflicto'
    );

    // La versión correcta sí permite actualizar.
    $actualizada = $servicio->guardar([
        'id' => $id,
        'updated_at' => $versionReal,
        'nombre' => 'Versión correcta',
        'tipo' => 'principal',
        'estado' => 'activa',
        'accesible' => '1',
        'origen' => ['tipo' => 'edificio', 'ref' => $referenciaEdificio],
        'destino' => ['tipo' => 'edificio', 'ref' => $referenciaEdificio],
        'segmentos' => [['id' => $segmento, 'sentido' => 'normal']],
    ], ['id' => 'test_001', 'username' => 'pruebas']);
    afirmarIgual('5. Conflicto: la versión vigente permite guardar', 'Versión correcta', $actualizada['nombre']);
} catch (Throwable $e) {
    afirmar('5. Conflicto: sin excepciones inesperadas', false, $e->getMessage());
}

// ---------- Caso 6: eliminar ----------
$dir6 = crearDirTemporal('repo-eliminar');
try {
    $repo = new RouteRepository($dir6);
    $ids = ['ruta-0000000a', 'ruta-0000000b'];
    foreach ($ids as $indiceId => $id) {
        $repo->actualizarConBloqueo(static function (array $documento) use ($id, $indiceId): array {
            $documento['rutas'][$id] = [
                'id' => $id,
                'nombre' => 'Ruta ' . $indiceId,
                'segmentos' => [],
                'creado_en' => date('c'),
                'actualizado_en' => date('c'),
            ];
            return ['datos' => $documento, 'resultado' => true];
        });
    }

    $repo->actualizarConBloqueo(static function (array $documento) use ($ids): array {
        unset($documento['rutas'][$ids[0]]);
        return ['datos' => $documento, 'resultado' => true];
    });

    afirmar('6. Eliminar: la ruta ya no está', $repo->obtener($ids[0]) === null);
    afirmar('6. Eliminar: el resto queda intacto', $repo->obtener($ids[1]) !== null);
    $documento = $repo->listar();
    afirmarIgual('6. Eliminar: queda una sola ruta', 1, count($documento['rutas']));
} catch (Throwable $e) {
    afirmar('6. Eliminar: sin excepciones', false, $e->getMessage());
}

// ---------- Caso 7: concurrencia ----------
$dir7 = crearDirTemporal('repo-concurrencia');
try {
    $escritor = __DIR__ . '/_escritor-concurrente.php';
    $procesos = [];
    foreach (['a', 'b'] as $prefijo) {
        $comando = [PHP_BINARY, $escritor, $dir7, $prefijo, '12', '3000'];
        $descriptores = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proceso = proc_open($comando, $descriptores, $tuberias, null, null);
        if (!is_resource($proceso)) {
            throw new RuntimeException('No se pudo lanzar el proceso de escritura.');
        }
        $procesos[] = [$proceso, $tuberias];
    }

    $codigos = [];
    $errores = '';
    foreach ($procesos as [$proceso, $tuberias]) {
        $errores .= stream_get_contents($tuberias[2]);
        fclose($tuberias[0]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);
        $codigos[] = proc_close($proceso);
    }

    afirmar('7. Concurrencia: los procesos terminan sin error', $codigos === [0, 0], implode(',', $codigos) . ' ' . $errores);

    $repo = new RouteRepository($dir7);
    $documento = $repo->listar();
    afirmarIgual('7. Concurrencia: las 24 rutas sobreviven', 24, count($documento['rutas']));

    $contenido = (string) file_get_contents($dir7 . '/rutas.json');
    afirmar('7. Concurrencia: el JSON final es válido', json_decode($contenido, true) !== null);
    afirmar('7. Concurrencia: no quedan temporales', glob($dir7 . '/rutas.json.tmp.*') === []);
} catch (Throwable $e) {
    afirmar('7. Concurrencia: sin excepciones', false, $e->getMessage());
}

// ---------- Caso 8: migración de esquema ----------
$dir8 = crearDirTemporal('repo-migracion');
try {
    $rutaArchivo = $dir8 . '/rutas.json';
    file_put_contents($rutaArchivo, json_encode([
        'schema_version' => 0,
        'updated_at' => '2026-01-01T00:00:00+00:00',
        'rutas' => [],
    ], JSON_PRETTY_PRINT));

    $repo = new RouteRepository($dir8);
    $primera = $repo->migrar();
    afirmar('8. Migración: la primera llamada migra', $primera['migrado'] === true);
    afirmarIgual('8. Migración: schema_version actualizado', RouteRepository::SCHEMA_ACTUAL, $primera['schema_version']);

    $segunda = $repo->migrar();
    afirmar('8. Migración: es idempotente', $segunda['migrado'] === false);

    $documento = $repo->listar();
    afirmarIgual('8. Migración: el archivo queda en la versión actual', RouteRepository::SCHEMA_ACTUAL, (int) $documento['schema_version']);
} catch (Throwable $e) {
    afirmar('8. Migración: sin excepciones', false, $e->getMessage());
}

// ---------- Limpieza ----------
foreach ([$dir1, $dir2, $dir3, $dir4, $dir5, $dir5Audit, $dir6, $dir7, $dir8] as $dir) {
    borrarDir($dir);
}

salirConResumen('rutas-repository');
