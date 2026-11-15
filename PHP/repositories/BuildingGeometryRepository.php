<?php
// ==========================================
// REPOSITORIO DE GEOMETRÍAS DE EDIFICIOS (GeoTec.js)
// ==========================================
// Lee y escribe las geometrías de los edificios en GeoTec.js.
// Mantiene las geometrías separadas de los metadatos (datos.json).
//
// IMPORTANTE: GeoTec.js es un archivo JavaScript ("var datosTec = {...};").
// El objeto puede contener sintaxis válida en JS pero inválida en JSON
// (por ejemplo comillas invertidas alrededor de un número, comas finales,
// NaN...). Por eso se normaliza antes de decodificar. Si aun así no se
// puede interpretar, se lanza una excepción: NUNCA se debe devolver una
// colección vacía, porque eso provocaría que al guardar se pierdan todas
// las geometrías existentes.

require_once __DIR__ . '/BaseRepository.php';

class BuildingGeometryRepository extends BaseRepository
{
    protected string $filename = 'GeoTec.js';
    protected string $geoTecPath;

    public function __construct()
    {
        // Desde PHP/repositories/, subir 2 niveles para llegar a la raíz del proyecto
        $this->dataDir = dirname(__DIR__, 2);
        $this->geoTecPath = $this->dataDir . DIRECTORY_SEPARATOR . $this->filename;
    }

    /**
     * Lee todas las geometrías desde GeoTec.js.
     *
     * @return array FeatureCollection parseada
     * @throws RuntimeException si el archivo no se puede interpretar
     */
    public function leerGeometrias(): array
    {
        if (!file_exists($this->geoTecPath)) {
            return $this->estructuraVacia();
        }

        $contenido = file_get_contents($this->geoTecPath);
        if ($contenido === false) {
            throw new RuntimeException('No se pudo leer el archivo GeoTec.js');
        }

        $json = $this->extraerJsonDelJs($contenido);
        if ($json === null || $json === '') {
            throw new RuntimeException('No se encontró el objeto "datosTec" en GeoTec.js');
        }

        $datos = json_decode($json, true);
        if (!is_array($datos)) {
            // Reparar sintaxis JavaScript que no es JSON estricto
            $reparado = $this->repararJsonJs($json);
            if ($reparado !== $json) {
                $datos = json_decode($reparado, true);
                if (is_array($datos)) {
                    error_log('BuildingGeometryRepository: GeoTec.js contenía sintaxis no estricta de JSON; se interpretó correctamente tras normalizarla.');
                }
            }
        }

        if (!is_array($datos)) {
            throw new RuntimeException('No se pudo interpretar GeoTec.js: ' . json_last_error_msg());
        }

        if (!isset($datos['features']) || !is_array($datos['features'])) {
            throw new RuntimeException('GeoTec.js no contiene una lista "features" válida');
        }

        return $datos;
    }

    /**
     * Escribe las geometrías en GeoTec.js como variable global datosTec.
     *
     * @param array $datos FeatureCollection
     * @return bool
     */
    public function escribirGeometrias(array $datos): bool
    {
        $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            error_log('BuildingGeometryRepository: json_encode falló: ' . json_last_error_msg());
            return false;
        }

        // Verificar que lo que se va a escribir se puede volver a leer
        $verificacion = json_decode($json, true);
        if (!is_array($verificacion) || !isset($verificacion['features'])) {
            error_log('BuildingGeometryRepository: se abortó la escritura porque el JSON generado no es válido.');
            return false;
        }

        // Respaldo del archivo anterior antes de sobrescribir
        if (file_exists($this->geoTecPath)) {
            @copy($this->geoTecPath, $this->geoTecPath . '.bak');
        }

        $jsContenido = "var datosTec = " . $json . ";\n";
        $resultado = file_put_contents($this->geoTecPath, $jsContenido, LOCK_EX);

        if ($resultado === false) {
            error_log('BuildingGeometryRepository: no se pudo escribir GeoTec.js: ' . (error_get_last()['message'] ?? 'desconocido'));
            return false;
        }

        return true;
    }

    /**
     * Busca un feature por su id de propiedad.
     *
     * @param string $id
     * @return array|null Feature encontrado o null
     */
    public function buscarFeaturePorId(string $id): ?array
    {
        $datos = $this->leerGeometrias();
        foreach ($datos['features'] as $feature) {
            if ((string)($feature['properties']['id'] ?? '') === $id) {
                return $feature;
            }
        }
        return null;
    }

    /**
     * Agrega o actualiza un feature en la colección.
     *
     * @param string $id ID del edificio
     * @param string $nombre Nombre del edificio
     * @param array $geometry Geometría GeoJSON (Polygon)
     * @return bool
     */
    public function agregarOActualizarFeature(string $id, string $nombre, array $geometry): bool
    {
        $datos = $this->leerGeometrias();
        $idsAntes = array_map(
            fn($f) => (string)($f['properties']['id'] ?? ''),
            $datos['features']
        );

        $encontrado = false;
        foreach ($datos['features'] as &$existente) {
            if ((string)($existente['properties']['id'] ?? '') === $id) {
                $existente['properties'] = array_merge(
                    $existente['properties'] ?? [],
                    ['id' => $id, 'Nombre' => $nombre]
                );
                $existente['geometry'] = $geometry;
                $existente['type'] = 'Feature';
                $encontrado = true;
                break;
            }
        }
        unset($existente);

        if (!$encontrado) {
            $datos['features'][] = [
                'type' => 'Feature',
                'properties' => [
                    'id' => $id,
                    'Nombre' => $nombre,
                ],
                'geometry' => $geometry,
            ];
            $idsAntes[] = $id;
        }

        // Red de seguridad: todas las geometrías previas deben seguir presentes
        $idsDespues = array_map(
            fn($f) => (string)($f['properties']['id'] ?? ''),
            $datos['features']
        );
        $perdidos = array_diff($idsAntes, $idsDespues);
        if (!empty($perdidos)) {
            error_log('BuildingGeometryRepository: se abortó el guardado, se perderían geometrías: ' . implode(', ', $perdidos));
            return false;
        }

        return $this->escribirGeometrias($datos);
    }

    /**
     * Elimina un feature por su id.
     *
     * @param string $id
     * @return bool
     */
    public function eliminarFeature(string $id): bool
    {
        $datos = $this->leerGeometrias();
        $originalCount = count($datos['features']);

        $datos['features'] = array_values(array_filter(
            $datos['features'],
            fn($f) => (string)($f['properties']['id'] ?? '') !== $id
        ));

        if (count($datos['features']) === $originalCount) {
            return false;
        }

        return $this->escribirGeometrias($datos);
    }

    /**
     * Extrae el objeto JSON de la sentencia "var datosTec = {...};".
     *
     * Recorre el texto contando llaves (ignorando las que están dentro de
     * cadenas) para devolver exactamente el objeto, sin depender de que el
     * archivo termine en un punto y coma concreto ni de espacios.
     *
     * @param string $contenido
     * @return string|null
     */
    private function extraerJsonDelJs(string $contenido): ?string
    {
        $posDatos = strpos($contenido, 'datosTec');
        if ($posDatos === false) {
            return null;
        }

        $inicio = strpos($contenido, '{', $posDatos);
        if ($inicio === false) {
            return null;
        }

        $len = strlen($contenido);
        $profundidad = 0;
        $enCadena = false;
        $escapado = false;

        for ($i = $inicio; $i < $len; $i++) {
            $ch = $contenido[$i];

            if ($enCadena) {
                if ($escapado) {
                    $escapado = false;
                } elseif ($ch === '\\') {
                    $escapado = true;
                } elseif ($ch === '"') {
                    $enCadena = false;
                }
                continue;
            }

            if ($ch === '"') {
                $enCadena = true;
            } elseif ($ch === '{') {
                $profundidad++;
            } elseif ($ch === '}') {
                $profundidad--;
                if ($profundidad === 0) {
                    return substr($contenido, $inicio, $i - $inicio + 1);
                }
            }
        }

        return null;
    }

    /**
     * Normaliza sintaxis propia de JavaScript que no es JSON estricto.
     *
     * @param string $json
     * @return string
     */
    private function repararJsonJs(string $json): string
    {
        // Comillas invertidas usadas como delimitadores (ej: `25.529297`)
        $json = str_replace('`', '', $json);

        // Comas finales antes de cerrar objeto o arreglo
        $json = preg_replace('/,\s*([}\]])/', '$1', $json) ?? $json;

        // Valores no admitidos en JSON
        $json = preg_replace('/\b(?:NaN|Infinity|-Infinity|undefined)\b/', 'null', $json) ?? $json;

        return $json;
    }

    private function estructuraVacia(): array
    {
        return [
            'type' => 'FeatureCollection',
            'name' => 'GeometriaITL',
            'crs' => ['type' => 'name', 'properties' => ['name' => 'urn:ogc:def:crs:OGC:1.3:CRS84']],
            'xy_coordinate_resolution' => 1e-06,
            'features' => [],
        ];
    }
}
