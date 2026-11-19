<?php
// ==========================================
// REPOSITORIO PERSISTENTE DE LA RED (FORMATO V2)
// ==========================================
// Extiende PathNetworkRepository (módulo de rutas) para añadir lo que exige
// el editor de red:
//
//  - `schema_version = 2`, `revision` monotónica y `next_seq`.
//  - Lectura fail-closed también para los metadatos v2: un archivo viejo o
//    corrupto lanza excepción; nunca se devuelve una red vacía que luego se
//    sobrescriba.
//  - Guardado con bloqueo exclusivo, comprobación de revisión DENTRO del
//    bloqueo (409 si el cliente editó sobre una revisión vieja), respaldo en
//    `PHP/data/backups/caminitos/`, archivo temporal y `rename` atómico.
//
// El archivo de datos sigue siendo `caminitos.geojson` en la raíz: es la
// única fuente de verdad y el mapa público lo consume sin cambios.

declare(strict_types=1);

require_once __DIR__ . '/PathNetworkRepository.php';

/**
 * La revisión enviada por el cliente ya no es la vigente.
 */
class RevisionObsoletaException extends RuntimeException
{
    private int $revisionActual;

    public function __construct(int $revisionActual)
    {
        $this->revisionActual = $revisionActual;
        parent::__construct(
            'La red fue modificada por otra sesión. Revisión vigente: ' . $revisionActual . '.',
            409
        );
    }

    public function revisionActual(): int
    {
        return $this->revisionActual;
    }
}

class NetworkRepository extends PathNetworkRepository
{
    public const SCHEMA_VERSION = 2;
    public const MAX_RESPALDOS = 30;

    private string $dirRespaldos;

    public function __construct(?string $rutaRed = null, ?string $dirRespaldos = null)
    {
        parent::__construct($rutaRed);
        $this->dirRespaldos = $dirRespaldos
            ?? BACKUPS_PATH . '/caminitos';
    }

    public function dirRespaldos(): string
    {
        return $this->dirRespaldos;
    }

    /**
     * Leer la red y exigir el formato v2.
     *
     * @return array
     * @throws RuntimeException
     */
    public function leerRed(): array
    {
        $geojson = $this->leer();
        $errores = $this->erroresFormatoV2($geojson);

        if ($errores !== []) {
            throw new RuntimeException(
                'La red no está en formato v2: ' . implode('; ', array_slice($errores, 0, 5))
                . '. Ejecute scripts/migrar_red_v2.php --apply.'
            );
        }

        return $geojson;
    }

    public function revisionActual(): int
    {
        return (int) ($this->leerRed()['revision'] ?? 0);
    }

    /**
     * Guardar una propuesta completa de red con control de revisión.
     *
     * El llamador ya validó el contenido; aquí solo se comprueba la huella
     * estructural y la revisión.
     *
     * @param array $geojson Propuesta completa (features con IDs definitivos)
     * @param int $baseRevision Revisión sobre la que se editó
     * @return array{revision: int, respaldo: ?string, features: int}
     * @throws RevisionObsoletaException si la revisión está desfasada
     * @throws RuntimeException si no se puede leer o escribir
     */
    public function guardarRevision(array $geojson, int $baseRevision): array
    {
        $ruta = $this->rutaArchivo();
        $rutaBloqueo = $ruta . '.lock';

        $bloqueo = @fopen($rutaBloqueo, 'c');
        if ($bloqueo === false) {
            throw new RuntimeException('No se pudo abrir el archivo de bloqueo de la red.');
        }

        try {
            if (!flock($bloqueo, LOCK_EX)) {
                throw new RuntimeException('No se pudo obtener el bloqueo exclusivo de la red.');
            }

            // Releer DENTRO del bloqueo: la revisión que vale es la del disco.
            $actual = $this->leer();
            $revisionActual = (int) ($actual['revision'] ?? 0);

            if ($revisionActual !== $baseRevision) {
                throw new RevisionObsoletaException($revisionActual);
            }

            $geojson['schema_version'] = self::SCHEMA_VERSION;
            $geojson['revision'] = $revisionActual + 1;

            $validacion = $this->validar($geojson);
            $erroresV2 = $this->erroresFormatoV2($geojson);
            $errores = array_merge($validacion['errores'], $erroresV2);
            if ($errores !== []) {
                throw new RuntimeException(
                    'Se rechazó la escritura de la red: ' . implode('; ', array_slice($errores, 0, 5))
                );
            }

            $json = json_encode(
                $geojson,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($json === false) {
                throw new RuntimeException('No se pudo serializar la red: ' . json_last_error_msg());
            }

            $respaldo = $this->respaldar($revisionActual);

            $temporal = $ruta . '.tmp.' . uniqid('', true);
            if (@file_put_contents($temporal, $json) === false) {
                throw new RuntimeException('No se pudo escribir el archivo temporal de la red.');
            }

            $contenido = @file_get_contents($temporal);
            $verificacion = $contenido !== false ? json_decode($contenido, true) : null;
            if (!is_array($verificacion)
                || !$this->validar($verificacion)['ok']
                || $this->erroresFormatoV2($verificacion) !== []
                || count($verificacion['features'] ?? []) !== count($geojson['features'] ?? [])) {
                @unlink($temporal);
                throw new RuntimeException('La verificación del archivo temporal de la red falló.');
            }

            if (!@rename($temporal, $ruta)) {
                @unlink($temporal);
                throw new RuntimeException('No se pudo reemplazar la red de caminos.');
            }

            $this->limpiarRespaldos();

            return [
                'revision' => (int) $geojson['revision'],
                'respaldo' => $respaldo,
                'features' => count($geojson['features']),
            ];
        } finally {
            flock($bloqueo, LOCK_UN);
            fclose($bloqueo);
        }
    }

    /**
     * Errores específicos del formato v2.
     *
     * @return array<int, string>
     */
    public function erroresFormatoV2(array $geojson): array
    {
        $errores = [];

        if (!isset($geojson['schema_version'])) {
            $errores[] = 'falta schema_version';
        } elseif ((int) $geojson['schema_version'] !== self::SCHEMA_VERSION) {
            $errores[] = 'schema_version desconocida: ' . (string) $geojson['schema_version'];
        }

        if (!isset($geojson['revision']) || !is_int($geojson['revision']) || $geojson['revision'] < 1) {
            $errores[] = 'revision inválida';
        }

        if (!isset($geojson['next_seq']) || !is_int($geojson['next_seq']) || $geojson['next_seq'] < 1) {
            $errores[] = 'next_seq inválido';
        }

        return $errores;
    }

    /**
     * Copiar la red vigente al directorio de respaldos.
     *
     * @return string|null Ruta del respaldo, o null si no existía la red
     */
    private function respaldar(int $revision): ?string
    {
        $ruta = $this->rutaArchivo();
        if (!file_exists($ruta)) {
            return null;
        }

        if (!is_dir($this->dirRespaldos)
            && !@mkdir($this->dirRespaldos, 0775, true)
            && !is_dir($this->dirRespaldos)) {
            throw new RuntimeException('No se pudo crear el directorio de respaldos de la red.');
        }

        $nombre = sprintf(
            'caminitos-%s-rev%d.geojson',
            date('Ymd-His'),
            $revision
        );
        $destino = $this->dirRespaldos . DIRECTORY_SEPARATOR . $nombre;

        if (file_exists($destino)) {
            $destino = $this->dirRespaldos . DIRECTORY_SEPARATOR
                . sprintf('caminitos-%s-rev%d-%s.geojson', date('Ymd-His'), $revision, uniqid());
        }

        if (!@copy($ruta, $destino)) {
            throw new RuntimeException('No se pudo crear el respaldo de la red antes de guardar.');
        }

        return $destino;
    }

    /**
     * Conservar solo los últimos MAX_RESPALDOS.
     */
    private function limpiarRespaldos(): void
    {
        $archivos = glob($this->dirRespaldos . DIRECTORY_SEPARATOR . 'caminitos-*.geojson');
        if ($archivos === false || count($archivos) <= self::MAX_RESPALDOS) {
            return;
        }

        usort($archivos, static function (string $a, string $b): int {
            return (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0);
        });

        foreach (array_slice($archivos, self::MAX_RESPALDOS) as $viejo) {
            @unlink($viejo);
        }
    }
}
