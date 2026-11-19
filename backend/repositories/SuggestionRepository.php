<?php
// ==========================================
// REPOSITORIO DE SUGERENCIAS (suggestions.json)
// ==========================================
// Persistencia y consulta de sugerencias enviadas desde el mapa público.

require_once __DIR__ . '/BaseRepository.php';

class SuggestionRepository extends BaseRepository
{
    protected string $filename = 'suggestions.json';

    /**
     * Buscar sugerencia por ID.
     *
     * @param string $id
     * @return array|null
     */
    public function buscarPorId(string $id): ?array
    {
        $data = $this->leer();
        return $data[$id] ?? null;
    }

    /**
     * Crear nueva sugerencia.
     *
     * @param array $datos Datos de la sugerencia (sin id)
     * @return string ID de la sugerencia creada
     */
    public function crear(array $datos): string
    {
        $data = $this->leer();
        $id = $datos['id'] ?? uniqid('sug_');
        $datos['id'] = $id;
        $datos['schema_version'] = $datos['schema_version'] ?? 1;
        $datos['created_at'] = $datos['created_at'] ?? date('c');
        $datos['reviewed_at'] = $datos['reviewed_at'] ?? null;
        $datos['reviewed_by'] = $datos['reviewed_by'] ?? null;

        $data[$id] = $datos;
        $this->escribir($data);
        return $id;
    }

    /**
     * Actualizar estado de revisión de una sugerencia.
     *
     * @param string $id
     * @param string $estado
     * @param string|null $revisadoPor
     * @return bool
     */
    public function actualizarEstado(string $id, string $estado, ?string $revisadoPor = null): bool
    {
        $data = $this->leer();
        if (!isset($data[$id])) {
            return false;
        }
        $data[$id]['status'] = $estado;
        $data[$id]['reviewed_at'] = date('c');
        $data[$id]['reviewed_by'] = $revisadoPor;
        $this->escribir($data);
        return true;
    }

    /**
     * Listar sugerencias, opcionalmente filtrar por estado.
     *
     * @param string|null $estado
     * @return array
     */
    public function listar(?string $estado = null): array
    {
        $data = $this->leer();
        if ($estado === null) {
            return $data;
        }

        return array_filter($data, function ($s) use ($estado) {
            return ($s['status'] ?? '') === $estado;
        });
    }

    /**
     * Eliminar sugerencia.
     *
     * @param string $id
     * @return bool
     */
    public function eliminar(string $id): bool
    {
        $data = $this->leer();
        if (!isset($data[$id])) {
            return false;
        }
        unset($data[$id]);
        $this->escribir($data);
        return true;
    }
}
