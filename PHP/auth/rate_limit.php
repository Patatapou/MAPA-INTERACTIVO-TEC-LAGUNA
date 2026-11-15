<?php
// ==========================================
// LIMITACIÓN DE INTENTOS DE LOGIN (RATE LIMITING)
// ==========================================

/**
 * Registra un intento fallido de login y verifica si se ha excedido el límite.
 *
 * @param int $maxAttempts Máximo de intentos permitidos (por defecto 5)
 * @param int $windowSeconds Ventana de tiempo en segundos (por defecto 3600 = 1 hora)
 * @return bool true si se puede seguir intentando, false si se ha excedido el límite
 */
function registrarIntentoFallido(int $maxAttempts = 5, int $windowSeconds = 3600): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $now = time();
    $intentos = $_SESSION['login_attempts'] ?? [];
    $intentos = array_filter($intentos, function ($t) use ($now, $windowSeconds) {
        return (int)$t > $now - $windowSeconds;
    });

    if (count($intentos) >= $maxAttempts) {
        return false;
    }

    $intentos[] = $now;
    $_SESSION['login_attempts'] = $intentos;
    return true;
}

/**
 * Reinicia el contador de intentos fallidos (tras login exitoso).
 */
function resetIntentosFallidos(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['login_attempts'] = [];
}
