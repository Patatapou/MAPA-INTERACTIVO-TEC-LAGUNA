<?php
// ==========================================
// SERVICIO DE SUGERENCIAS (SuggestionService)
// ==========================================
// Reglas de negocio para sugerencias del mapa público.
// Coordina SuggestionRepository y AuditLogRepository.

require_once __DIR__ . '/../repositories/SuggestionRepository.php';
require_once __DIR__ . '/../repositories/AuditLogRepository.php';

class SuggestionService
{
    private SuggestionRepository $suggestionRepo;
    private AuditLogRepository $auditRepo;

    public function __construct()
    {
        $this->suggestionRepo = new SuggestionRepository();
        $this->auditRepo = new AuditLogRepository();
    }

    /**
     * Crear una nueva sugerencia.
     *
     * @param array $datos
     * @return string ID de la sugerencia creada
     */
    public function crearSugerencia(array $datos): string
    {
        $datos['status'] = $datos['status'] ?? 'pendiente';
        $id = $this->suggestionRepo->crear($datos);

        $this->auditRepo->registrar([
            'event' => 'suggestion_received',
            'suggestion_id' => $id,
            'building_id' => $datos['building_id'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        return $id;
    }

    /**
     * Obtener sugerencias pendientes de revisión.
     *
     * @return array
     */
    public function obtenerPendientes(): array
    {
        return $this->suggestionRepo->listar('pendiente');
    }

    /**
     * Revisar una sugerencia (cambiar estado).
     *
     * @param string $id
     * @param string $estado
     * @param string|null $revisadoPor
     * @return bool
     */
    public function revisarSugerencia(string $id, string $estado, ?string $revisadoPor = null): bool
    {
        $resultado = $this->suggestionRepo->actualizarEstado($id, $estado, $revisadoPor);

        if ($resultado) {
            $this->auditRepo->registrar([
                'event' => 'suggestion_reviewed',
                'suggestion_id' => $id,
                'status' => $estado,
                'reviewed_by' => $revisadoPor,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
        }

        return $resultado;
    }

    /**
     * Listar sugerencias, opcionalmente filtrar por estado.
     *
     * @param string|null $estado
     * @return array
     */
    public function listar(?string $estado = null): array
    {
        return $this->suggestionRepo->listar($estado);
    }
}
