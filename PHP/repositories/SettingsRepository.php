<?php
// ==========================================
// REPOSITORIO DE CONFIGURACIÓN (settings.json)
// ==========================================
// Persistencia y consulta de configuración general del sistema.

require_once __DIR__ . '/BaseRepository.php';

class SettingsRepository extends BaseRepository
{
    protected string $filename = 'settings.json';

    /**
     * Obtener un valor de configuración por clave.
     *
     * @param string $clave
     * @param mixed $default
     * @return mixed
     */
    public function obtener(string $clave, mixed $default = null): mixed
    {
        $data = $this->leer();
        return $data[$clave] ?? $default;
    }

    /**
     * Obtener todas las configuraciones.
     *
     * @return array
     */
    public function obtenerTodas(): array
    {
        return $this->leer();
    }

    /**
     * Guardar o actualizar una configuración.
     *
     * @param string $clave
     * @param mixed $valor
     * @return bool
     */
    public function guardar(string $clave, $valor): bool
    {
        $data = $this->leer();
        $data[$clave] = $valor;
        $data['schema_version'] = $data['schema_version'] ?? 1;
        $data['updated_at'] = date('c');
        return $this->escribir($data);
    }

    /**
     * Eliminar una configuración.
     *
     * @param string $clave
     * @return bool
     */
    public function eliminar(string $clave): bool
    {
        $data = $this->leer();
        if (!isset($data[$clave])) {
            return false;
        }
        unset($data[$clave]);
        $this->escribir($data);
        return true;
    }
}
