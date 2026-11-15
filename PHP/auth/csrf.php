<?php
// ==========================================
// PROTECCIÓN CSRF
// ==========================================

/**
 * Genera un token CSRF y lo almacena en la sesión.
 *
 * @return string Token CSRF
 */
function generarTokenCSRF(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida un token CSRF contra el almacenado en sesión.
 *
 * @param string $token Token recibido del cliente
 * @return bool true si es válido
 */
function validarTokenCSRF(string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Renueva el token CSRF (para usarlo después de acciones sensibles).
 */
function renovarTokenCSRF(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
