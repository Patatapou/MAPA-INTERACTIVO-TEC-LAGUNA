<?php
// ==========================================
// CLASE BASE PARA REPOSITORIOS JSON
// ==========================================
// Proporciona operaciones comunes de lectura y escritura
// con bloqueo de archivo (flock) y reemplazo atómico.

require_once __DIR__ . '/../bootstrap.php';

abstract class BaseRepository
{
    protected string $dataDir;
    protected string $filename;

    public function __construct(?string $dataDir = null)
    {
        $this->dataDir = $dataDir !== null && $dataDir !== ''
            ? rtrim($dataDir, '/\\')
            : RUNTIME_PATH;
    }

    /**
     * Leer todo el contenido del archivo JSON.
     *
     * @return array
     */
    protected function leer(): array
    {
        $ruta = $this->dataDir . '/' . $this->filename;
        if (!file_exists($ruta)) {
            return [];
        }

        $contenido = file_get_contents($ruta);
        if ($contenido === false || trim($contenido) === '') {
            return [];
        }

        $datos = json_decode($contenido, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($datos)) {
            return [];
        }

        return $datos;
    }

    /**
     * Escribir datos al archivo JSON con bloqueo y reemplazo atómico.
     *
     * @param array $datos
     * @return bool
     */
    protected function escribir(array $datos): bool
    {
        $ruta = $this->dataDir . '/' . $this->filename;
        $rutaTmp = $ruta . '.tmp.' . uniqid('', true);

        $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $fp = fopen($rutaTmp, 'wb');
        if ($fp === false) {
            return false;
        }

        // Bloqueo exclusivo durante la escritura
        if (flock($fp, LOCK_EX) === false) {
            fclose($fp);
            unlink($rutaTmp);
            return false;
        }

        $escrito = fwrite($fp, $json) !== false;
        flock($fp, LOCK_UN);
        fclose($fp);

        if (!$escrito) {
            unlink($rutaTmp);
            return false;
        }

        // Reemplazo atómico
        return rename($rutaTmp, $ruta);
    }
}
