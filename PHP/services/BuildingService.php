<?php
// ==========================================
// SERVICIO DE EDIFICIOS (BuildingService)
// ==========================================
// Lógica de negocio para edificios: coordina los repositorios de
// geometrías (GeoTec.js) y los metadatos (datos.json).

require_once __DIR__ . '/../repositories/BuildingGeometryRepository.php';
require_once __DIR__ . '/../repositories/AuditLogRepository.php';

class BuildingService
{
    private BuildingGeometryRepository $geometryRepo;
    private AuditLogRepository $auditRepo;
    private string $datosJsonPath;

    public function __construct()
    {
        $this->geometryRepo = new BuildingGeometryRepository();
        $this->auditRepo = new AuditLogRepository();
        // datos.json está en la raíz del proyecto (dos niveles arriba de PHP/services/)
        $this->datosJsonPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'datos.json';
    }

    /**
     * Crea un nuevo edificio con metadatos y geometría.
     *
     * @param string $id ID del edificio
     * @param string $nombre Nombre del edificio
     * @param string $descripcion Descripción
     * @param array $aulas Lista de aulas
     * @param array $geometry Geometría GeoJSON (Polygon)
     * @param array $usuario Usuario autenticado
     * @param string $tipo 'edificio' o 'acceso' (las puertas P1–P6 son accesos)
     * @return array ['success' => bool, 'message' => string, 'id' => string|null]
     */
    public function crearEdificio(
        string $id,
        string $nombre,
        string $descripcion,
        array $aulas,
        array $geometry,
        array $usuario,
        string $tipo = 'edificio'
    ): array {
        // Validar que el ID no exista ya en datos.json
        $datos = $this->leerDatosJson();
        if (isset($datos[$id])) {
            $nombreExistente = $datos[$id]['nombre'] ?? 'sin nombre';
            return [
                'success' => false,
                'message' => "El ID '$id' ya existe en el sistema (edificio: $nombreExistente). Usa un ID diferente, por ejemplo: " . $this->generarIdSugerido($id),
                'id' => null,
            ];
        }

        // Validar que el ID no exista ya en GeoTec.js
        $featureExistente = $this->geometryRepo->buscarFeaturePorId($id);
        if ($featureExistente !== null) {
            $nombreGeo = $featureExistente['properties']['Nombre'] ?? 'sin nombre';
            return [
                'success' => false,
                'message' => "El ID '$id' ya tiene geometría registrada (edificio: $nombreGeo). Usa un ID diferente, por ejemplo: " . $this->generarIdSugerido($id),
                'id' => null,
            ];
        }

        if (!$this->geometriaValida($geometry)) {
            return [
                'success' => false,
                'message' => 'La geometría no es válida (debe ser un polígono con coordenadas).',
                'id' => null,
            ];
        }

        // 1. Guardar geometría en GeoTec.js (conserva todas las existentes)
        if (!$this->geometryRepo->agregarOActualizarFeature($id, $nombre, $geometry)) {
            return [
                'success' => false,
                'message' => 'Error al guardar la geometría del edificio. No se modificó ningún archivo.',
                'id' => null,
            ];
        }

        // 2. Guardar metadatos en datos.json
        $datos[$id] = [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'aulas' => $aulas,
            'carpeta' => [$id],
            'tipo' => $this->normalizarTipo($tipo),
            'imagenes' => [],
        ];

        if (!$this->escribirDatosJson($datos)) {
            // Revertir la geometría recién agregada
            $this->geometryRepo->eliminarFeature($id);
            return [
                'success' => false,
                'message' => 'Error al guardar los metadatos del edificio.',
                'id' => null,
            ];
        }

        $this->auditRepo->registrar([
            'event' => 'building_created',
            'user_id' => $usuario['id'] ?? null,
            'username' => $usuario['username'] ?? '',
            'building_id' => $id,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        return [
            'success' => true,
            'message' => 'Edificio creado correctamente.',
            'id' => $id,
        ];
    }

    /**
     * Actualiza un edificio existente (metadatos y/o geometría).
     *
     * @param string $id
     * @param string $nombre
     * @param string $descripcion
     * @param array $aulas
     * @param array|null $geometry Geometría GeoJSON o null si no se cambia
     * @param array $usuario
     * @param string|null $tipo Tipo nuevo ('edificio'|'acceso') o null para conservarlo
     * @return array
     */
    public function actualizarEdificio(
        string $id,
        string $nombre,
        string $descripcion,
        array $aulas,
        ?array $geometry,
        array $usuario,
        ?string $tipo = null
    ): array {
        $datos = $this->leerDatosJson();

        if (!isset($datos[$id])) {
            return [
                'success' => false,
                'message' => "El edificio '$id' no existe.",
                'id' => null,
            ];
        }

        if ($geometry !== null && !$this->geometriaValida($geometry)) {
            return [
                'success' => false,
                'message' => 'La geometría no es válida.',
                'id' => null,
            ];
        }

        if ($geometry !== null) {
            if (!$this->geometryRepo->agregarOActualizarFeature($id, $nombre, $geometry)) {
                return [
                    'success' => false,
                    'message' => 'Error al guardar la geometría.',
                    'id' => null,
                ];
            }
        }

        // Conservar los campos que este flujo no gestiona (imagenes, tipo y
        // cualquier campo futuro) en lugar de reconstruir la entrada desde cero.
        $entrada = $datos[$id];
        $entrada['nombre'] = $nombre;
        $entrada['descripcion'] = $descripcion;
        $entrada['aulas'] = $aulas;
        $entrada['carpeta'] = $entrada['carpeta'] ?? [$id];
        if ($tipo !== null) {
            $entrada['tipo'] = $this->normalizarTipo($tipo);
        } elseif (!isset($entrada['tipo'])) {
            $entrada['tipo'] = 'edificio';
        }
        $datos[$id] = $entrada;

        if (!$this->escribirDatosJson($datos)) {
            return [
                'success' => false,
                'message' => 'Error al guardar los metadatos.',
                'id' => null,
            ];
        }

        $this->auditRepo->registrar([
            'event' => 'building_updated',
            'user_id' => $usuario['id'] ?? null,
            'username' => $usuario['username'] ?? '',
            'building_id' => $id,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        return [
            'success' => true,
            'message' => 'Edificio actualizado correctamente.',
            'id' => $id,
        ];
    }

    /**
     * Elimina un edificio (metadatos + geometría).
     *
     * @param string $id
     * @param array $usuario
     * @return array
     */
    public function eliminarEdificio(string $id, array $usuario): array
    {
        $datos = $this->leerDatosJson();

        if (!isset($datos[$id])) {
            return [
                'success' => false,
                'message' => "El edificio '$id' no existe.",
                'id' => null,
            ];
        }

        // Se guarda la geometría antes de borrarla para poder revertir si falla
        // la escritura de los metadatos.
        $feature = $this->geometryRepo->buscarFeaturePorId($id);

        // 1. Eliminar la geometría (si existe). Sin geometría que borrar, seguimos.
        if ($feature !== null && !$this->geometryRepo->eliminarFeature($id)) {
            return [
                'success' => false,
                'message' => 'No se pudo eliminar la geometría. No se modificó ningún archivo.',
                'id' => null,
            ];
        }

        // 2. Eliminar los metadatos
        unset($datos[$id]);
        if (!$this->escribirDatosJson($datos)) {
            // Revertir la geometría eliminada
            if ($feature !== null) {
                $this->geometryRepo->agregarOActualizarFeature(
                    $id,
                    $feature['properties']['Nombre'] ?? $id,
                    $feature['geometry']
                );
            }
            return [
                'success' => false,
                'message' => 'Error al eliminar los metadatos. Se restauró la geometría.',
                'id' => null,
            ];
        }

        $this->auditRepo->registrar([
            'event' => 'building_deleted',
            'user_id' => $usuario['id'] ?? null,
            'username' => $usuario['username'] ?? '',
            'building_id' => $id,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        return [
            'success' => true,
            'message' => 'Edificio eliminado correctamente.',
            'id' => $id,
        ];
    }

    /**
     * Actualiza solo los metadatos de un edificio existente.
     *
     * Se usa desde el panel administrativo, que gestiona nombre, descripción,
     * aulas e imágenes, pero no la geometría. Hace merge para no perder campos
     * que el panel no conoce (por ejemplo `tipo`).
     *
     * @param string $id
     * @param array $cambios Campos a actualizar (nombre, descripcion, aulas, carpeta, imagenes)
     * @param array $usuario
     * @return array
     */
    public function actualizarMetadatos(string $id, array $cambios, array $usuario): array
    {
        $datos = $this->leerDatosJson();

        if (!isset($datos[$id])) {
            return [
                'success' => false,
                'message' => "El edificio '$id' no existe.",
                'id' => null,
            ];
        }

        $permitidos = ['nombre', 'descripcion', 'aulas', 'carpeta', 'imagenes'];
        $entrada = $datos[$id];

        foreach ($permitidos as $campo) {
            if (array_key_exists($campo, $cambios)) {
                $entrada[$campo] = $cambios[$campo];
            }
        }

        if (trim((string)($entrada['nombre'] ?? '')) === '') {
            return [
                'success' => false,
                'message' => 'El nombre del edificio es requerido.',
                'id' => null,
            ];
        }

        $datos[$id] = $entrada;

        if (!$this->escribirDatosJson($datos)) {
            return [
                'success' => false,
                'message' => 'Error al guardar los metadatos.',
                'id' => null,
            ];
        }

        $this->auditRepo->registrar([
            'event' => 'building_updated',
            'user_id' => $usuario['id'] ?? null,
            'username' => $usuario['username'] ?? '',
            'building_id' => $id,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        return [
            'success' => true,
            'message' => 'Cambios guardados correctamente.',
            'id' => $id,
        ];
    }

    /**
     * Obtiene un edificio con metadatos y geometría.
     *
     * @param string $id
     * @return array|null
     */
    public function obtenerEdificio(string $id): ?array
    {
        $datos = $this->leerDatosJson();
        if (!isset($datos[$id])) {
            return null;
        }

        $metadata = $datos[$id];
        $feature = $this->geometryRepo->buscarFeaturePorId($id);

        return [
            'id' => $id,
            'nombre' => $metadata['nombre'] ?? '',
            'descripcion' => $metadata['descripcion'] ?? '',
            'aulas' => $metadata['aulas'] ?? [],
            'carpeta' => $metadata['carpeta'] ?? [$id],
            'tipo' => $metadata['tipo'] ?? 'edificio',
            'imagenes' => $metadata['imagenes'] ?? [],
            'geometry' => $feature['geometry'] ?? null,
        ];
    }

    /**
     * Lista todos los edificios con sus metadatos.
     *
     * @return array
     */
    public function listarEdificios(): array
    {
        return $this->leerDatosJson();
    }

    /**
     * Lee datos.json.
     *
     * @return array
     * @throws RuntimeException si el archivo existe pero no se puede interpretar
     */
    private function leerDatosJson(): array
    {
        if (!file_exists($this->datosJsonPath)) {
            return [];
        }

        $contenido = file_get_contents($this->datosJsonPath);
        if ($contenido === false) {
            throw new RuntimeException('No se pudo leer datos.json');
        }

        $datos = json_decode($contenido, true);
        if (!is_array($datos)) {
            // No devolver un array vacío: borraría todos los metadatos al guardar
            throw new RuntimeException('No se pudo interpretar datos.json: ' . json_last_error_msg());
        }

        return $datos;
    }

    /**
     * Escribe datos.json de forma atómica.
     *
     * @param array $datos
     * @return bool
     */
    private function escribirDatosJson(array $datos): bool
    {
        $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            error_log('BuildingService: json_encode falló al escribir datos.json: ' . json_last_error_msg());
            return false;
        }

        // En Windows rename() no puede sobrescribir archivos existentes:
        // se usa file_put_contents con LOCK_EX.
        $resultado = file_put_contents($this->datosJsonPath, $json . "\n", LOCK_EX);

        if ($resultado === false) {
            error_log('BuildingService: no se pudo escribir datos.json: ' . (error_get_last()['message'] ?? 'desconocido'));
            return false;
        }

        return true;
    }

    /**
     * Normaliza el tipo de registro. Solo se admiten 'edificio' y 'acceso';
     * cualquier otro valor cae en 'edificio'.
     */
    private function normalizarTipo(string $tipo): string
    {
        $tipo = strtolower(trim($tipo));
        return $tipo === 'acceso' ? 'acceso' : 'edificio';
    }

    /**
     * Genera un ID sugerido basado en uno existente (para evitar duplicados).
     */
    private function generarIdSugerido(string $idExistente): string
    {
        $numero = preg_replace('/\D/', '', $idExistente);
        if ($numero) {
            return (string)($numero + 1);
        }
        return $idExistente . '_1';
    }

    /**
     * Valida que una geometría sea un polígono GeoJSON válido.
     */
    private function geometriaValida(array $geometry): bool
    {
        if (($geometry['type'] ?? null) !== 'Polygon') {
            error_log('BuildingService: geometría inválida, tipo: ' . ($geometry['type'] ?? 'ausente'));
            return false;
        }

        $coordinates = $geometry['coordinates'] ?? null;
        if (!is_array($coordinates) || count($coordinates) === 0) {
            error_log('BuildingService: geometría sin coordenadas');
            return false;
        }

        $anilloExterno = $coordinates[0];
        if (!is_array($anilloExterno) || count($anilloExterno) < 4) {
            error_log('BuildingService: el anillo externo necesita al menos 4 puntos');
            return false;
        }

        foreach ($anilloExterno as $i => $punto) {
            if (!is_array($punto) || count($punto) < 2 || !is_numeric($punto[0]) || !is_numeric($punto[1])) {
                error_log("BuildingService: punto $i inválido: " . json_encode($punto));
                return false;
            }
        }

        $primero = $anilloExterno[0];
        $ultimo = end($anilloExterno);
        if ($primero[0] != $ultimo[0] || $primero[1] != $ultimo[1]) {
            error_log('BuildingService: el polígono no está cerrado');
            return false;
        }

        return true;
    }
}
