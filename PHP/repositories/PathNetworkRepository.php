<?php
// ==========================================
// REPOSITORIO DE LA RED DE CAMINOS
// ==========================================
// Lectura, validación y escritura atómica de `caminitos.geojson`.
//
// Diferencias con BaseRepository:
//  - La red vive en la raíz del proyecto, no en PHP/data/.
//  - La lectura es fail-closed: un archivo corrupto o inválido lanza
//    excepción (nunca devuelve una red vacía que oculte el problema).
//  - La escritura respalda la versión anterior en `.bak` y verifica el
//    archivo antes de reemplazarlo.
//
// En el MVP este repositorio se usa en modo lectura. La escritura queda
// preparada para la fase 6 (edición de red).

class PathNetworkRepository
{
    private string $rutaRed;

    /**
     * Patrón de ID de segmento aprobado (D11).
     *
     * Se admiten dos formatos:
     *  - `seg-` + 8 hex (+ sufijo `-NN`): IDs históricos de la migración.
     *  - `seg-` + 6 dígitos: IDs secuenciales que asigna el editor de red
     *    (formato v2, `next_seq`).
     */
    private const PATRON_ID = '/^seg-(?:[a-f0-9]{8}(?:-\d{2})?|\d{6})$/';

    public function __construct(?string $rutaRed = null)
    {
        $this->rutaRed = $rutaRed ?? dirname(__DIR__, 2) . '/caminitos.geojson';
    }

    public function rutaArchivo(): string
    {
        return $this->rutaRed;
    }

    public function existe(): bool
    {
        return file_exists($this->rutaRed);
    }

    /**
     * Leer y validar la red.
     *
     * @return array GeoJSON decodificado y validado
     * @throws RuntimeException si falta el archivo o es inválido
     */
    public function leer(): array
    {
        if (!$this->existe()) {
            throw new RuntimeException('No existe la red de caminos: ' . basename($this->rutaRed));
        }

        $contenido = file_get_contents($this->rutaRed);
        if ($contenido === false || trim($contenido) === '') {
            throw new RuntimeException('La red de caminos está vacía o no se puede leer.');
        }

        $geojson = json_decode($contenido, true);
        if (!is_array($geojson)) {
            throw new RuntimeException('La red de caminos no es JSON válido: ' . json_last_error_msg());
        }

        $validacion = $this->validar($geojson);
        if (!$validacion['ok']) {
            throw new RuntimeException(
                'La red de caminos no es válida: ' . implode('; ', array_slice($validacion['errores'], 0, 5))
            );
        }

        return $geojson;
    }

    /**
     * Validar estructura y metadatos mínimos de la red.
     *
     * @return array{ok: bool, errores: array<int, string>, features: int, ids: array<int, string>}
     */
    public function validar(array $geojson): array
    {
        $errores = [];
        $ids = [];

        if (($geojson['type'] ?? '') !== 'FeatureCollection') {
            $errores[] = 'El tipo raíz debe ser FeatureCollection.';
        }

        $features = $geojson['features'] ?? null;
        if (!is_array($features)) {
            $errores[] = 'Falta el arreglo features.';
            return ['ok' => false, 'errores' => $errores, 'features' => 0, 'ids' => []];
        }

        if (count($features) === 0) {
            $errores[] = 'La red no tiene segmentos.';
        }

        foreach ($features as $indice => $feature) {
            $etiqueta = 'features[' . $indice . ']';
            $geom = $feature['geometry'] ?? null;
            if (!is_array($geom) || ($geom['type'] ?? '') !== 'LineString') {
                $errores[] = $etiqueta . ': la geometría debe ser LineString.';
                continue;
            }

            $coords = $geom['coordinates'] ?? null;
            if (!is_array($coords) || count($coords) < 2) {
                $errores[] = $etiqueta . ': la geometría necesita al menos 2 coordenadas.';
                continue;
            }

            $puntoInvalido = false;
            foreach ($coords as $punto) {
                if (!is_array($punto) || count($punto) < 2
                    || !is_numeric($punto[0]) || !is_numeric($punto[1])
                    || (float) $punto[1] < -90.0 || (float) $punto[1] > 90.0
                    || (float) $punto[0] < -180.0 || (float) $punto[0] > 180.0) {
                    $puntoInvalido = true;
                    break;
                }
            }
            if ($puntoInvalido) {
                $errores[] = $etiqueta . ': coordenadas fuera de rango o mal formadas.';
                continue;
            }

            $id = $feature['properties']['id'] ?? ($feature['id'] ?? null);
            if (!is_string($id) || preg_match(self::PATRON_ID, $id) !== 1) {
                $errores[] = $etiqueta . ': falta un id de segmento válido.';
                continue;
            }

            if (isset($ids[$id])) {
                $errores[] = 'ID de segmento duplicado: ' . $id;
                continue;
            }
            $ids[$id] = $id;
        }

        return [
            'ok' => $errores === [],
            'errores' => $errores,
            'features' => count($features),
            'ids' => array_values($ids),
        ];
    }

    /**
     * Guardar la red con respaldo, escritura atómica y verificación.
     *
     * @return array{features: int, respaldo: ?string}
     * @throws RuntimeException si la red no es válida o la escritura falla
     */
    public function guardar(array $geojson): array
    {
        $validacion = $this->validar($geojson);
        if (!$validacion['ok']) {
            throw new RuntimeException(
                'Se rechazó la escritura de la red: ' . implode('; ', array_slice($validacion['errores'], 0, 5))
            );
        }

        $json = json_encode($geojson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar la red de caminos.');
        }

        $respaldo = null;
        if ($this->existe()) {
            $respaldo = $this->rutaRed . '.bak';
            if (!copy($this->rutaRed, $respaldo)) {
                throw new RuntimeException('No se pudo crear el respaldo de la red de caminos.');
            }
        }

        $temporal = $this->rutaRed . '.tmp.' . uniqid('', true);
        if (file_put_contents($temporal, $json) === false) {
            throw new RuntimeException('No se pudo escribir el archivo temporal de la red.');
        }

        $verificacion = json_decode((string) file_get_contents($temporal), true);
        if (!is_array($verificacion) || !$this->validar($verificacion)['ok']) {
            @unlink($temporal);
            throw new RuntimeException('La verificación del archivo temporal de la red falló.');
        }

        if (!rename($temporal, $this->rutaRed)) {
            @unlink($temporal);
            throw new RuntimeException('No se pudo reemplazar la red de caminos.');
        }

        return [
            'features' => $validacion['features'],
            'respaldo' => $respaldo !== null ? basename($respaldo) : null,
        ];
    }
}
