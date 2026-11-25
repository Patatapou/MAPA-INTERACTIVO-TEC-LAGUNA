<?php
declare(strict_types=1);

final class AdminServerLifecycle
{
    private string $signalPath;

    public function __construct(?string $signalPath = null)
    {
        if ($signalPath === null) {
            $port = filter_var($_SERVER['SERVER_PORT'] ?? 8000, FILTER_VALIDATE_INT);
            if ($port === false || $port < 1 || $port > 65535) {
                throw new InvalidArgumentException('El puerto del servidor local no es válido.');
            }

            $signalPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . 'itl-admin-server-' . $port . '.signal';
        }

        $this->signalPath = $signalPath;
    }

    public function heartbeat(): void
    {
        $this->writeSignal($this->signalPath, 'heartbeat');
    }

    public function requestDisconnect(): void
    {
        $this->writeSignal($this->signalPath, 'disconnect');
    }

    public function requestLogout(): void
    {
        $this->writeSignal($this->signalPath . '.logout', 'logout');
    }

    private function writeSignal(string $path, string $signal): void
    {
        if (file_put_contents($path, $signal, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo actualizar la señal de ciclo de vida del servidor local.');
        }
    }
}
