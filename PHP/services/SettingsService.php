<?php
// ==========================================
// SERVICIO DE CONFIGURACIÓN (SettingsService)
// ==========================================
// Reglas de negocio para configuración del sistema.
// Coordina SettingsRepository.

require_once __DIR__ . '/../repositories/SettingsRepository.php';

class SettingsService
{
    private SettingsRepository $settingsRepo;

    public function __construct()
    {
        $this->settingsRepo = new SettingsRepository();
    }

    /**
     * Obtener un valor de configuración con fallback.
     *
     * @param string $clave
     * @param mixed $default
     * @return mixed
     */
    public function obtener(string $clave, mixed $default = null): mixed
    {
        return $this->settingsRepo->obtener($clave, $default);
    }

    /**
     * Obtener todas las configuraciones.
     *
     * @return array
     */
    public function obtenerTodas(): array
    {
        return $this->settingsRepo->obtenerTodas();
    }

    /**
     * Actualizar una configuración.
     *
     * @param string $clave
     * @param mixed $valor
     * @return bool
     */
    public function actualizar(string $clave, $valor): bool
    {
        return $this->settingsRepo->guardar($clave, $valor);
    }
}
