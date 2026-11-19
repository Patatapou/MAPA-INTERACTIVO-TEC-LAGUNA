<?php
// ==========================================
// REPOSITORIO DE AUDITORÍA (audit-log.jsonl)
// ==========================================
// Registro de eventos en formato JSONL (una línea JSON por registro).
// Se añade al final del archivo sin reescribir el historial completo.

require_once __DIR__ . '/BaseRepository.php';

class AuditLogRepository extends BaseRepository
{
    protected string $filename = 'audit-log.jsonl';

    /**
     * Registrar un evento de auditoría.
     *
     * @param array $evento Datos del evento (sin timestamp)
     * @return bool
     */
    public function registrar(array $evento): bool
    {
        $evento['timestamp'] = $evento['timestamp'] ?? date('c');
        $linea = json_encode($evento, JSON_UNESCAPED_UNICODE);
        if ($linea === false) {
            return false;
        }

        $ruta = $this->dataDir . '/' . $this->filename;
        $fp = fopen($ruta, 'ab');
        if ($fp === false) {
            return false;
        }

        if (flock($fp, LOCK_EX) === false) {
            fclose($fp);
            return false;
        }

        $escrito = fwrite($fp, $linea . "\n") !== false;
        flock($fp, LOCK_UN);
        fclose($fp);

        return $escrito;
    }

    /**
     * Obtener los últimos N eventos.
     *
     * @param int $limite
     * @return array
     */
    public function obtener(int $limite = 100): array
    {
        $ruta = $this->dataDir . '/' . $this->filename;
        if (!file_exists($ruta)) {
            return [];
        }

        $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lineas === false) {
            return [];
        }

        // Tomar los últimos $limite líneas
        $lineas = array_slice($lineas, -$limite);

        $eventos = [];
        foreach ($lineas as $linea) {
            $decodificado = json_decode($linea, true);
            if ($decodificado !== null) {
                $eventos[] = $decodificado;
            }
        }

        return $eventos;
    }

    /**
     * Filtrar eventos por tipo de evento.
     *
     * @param string $tipoEvento
     * @return array
     */
    public function filtrarPorEvento(string $tipoEvento): array
    {
        $todos = $this->obtener(PHP_INT_MAX);
        return array_filter($todos, function ($e) use ($tipoEvento) {
            return ($e['event'] ?? '') === $tipoEvento;
        });
    }

    /**
     * Filtrar eventos por usuario.
     *
     * @param string $userId
     * @return array
     */
    public function filtrarPorUsuario(string $userId): array
    {
        $todos = $this->obtener(PHP_INT_MAX);
        return array_filter($todos, function ($e) use ($userId) {
            return ($e['user_id'] ?? '') === $userId;
        });
    }
}
