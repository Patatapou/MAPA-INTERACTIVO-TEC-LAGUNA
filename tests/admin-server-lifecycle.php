<?php
declare(strict_types=1);

require_once __DIR__ . '/_pruebas.php';
require_once __DIR__ . '/../backend/services/AdminServerLifecycle.php';

$signalPath = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'itl-admin-lifecycle-test-' . bin2hex(random_bytes(8)) . '.signal';
$service = new AdminServerLifecycle($signalPath);

try {
    $service->heartbeat();
    afirmarIgual('El heartbeat actualiza la señal de actividad', 'heartbeat', file_get_contents($signalPath));

    $service->requestDisconnect();
    afirmarIgual('El cierre de pestaña marca desconexión pendiente', 'disconnect', file_get_contents($signalPath));

    $service->requestLogout();
    afirmarIgual('El logout deja una señal terminal separada', 'logout', file_get_contents($signalPath . '.logout'));
    afirmarIgual('La señal terminal no es sobrescrita por la actividad', 'disconnect', file_get_contents($signalPath));
} finally {
    if (is_file($signalPath)) {
        unlink($signalPath);
    }
    if (is_file($signalPath . '.logout')) {
        unlink($signalPath . '.logout');
    }
}
