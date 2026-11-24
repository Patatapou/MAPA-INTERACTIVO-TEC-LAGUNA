<?php
// ==========================================
// SERVICIO DE AUTENTICACIÓN (AuthService)
// ==========================================
// Reglas de negocio para autenticación de administradores.
// Coordina UserRepository y AuditLogRepository.
// Autentica ÚNICAMENTE contra users.json (no usa credenciales.php).

require_once __DIR__ . '/../repositories/UserRepository.php';
require_once __DIR__ . '/../repositories/AuditLogRepository.php';

class AuthService
{
    private UserRepository $userRepo;
    private AuditLogRepository $auditRepo;

    public function __construct(?string $dataDir = null)
    {
        $this->userRepo = new UserRepository($dataDir);
        $this->auditRepo = new AuditLogRepository($dataDir);
    }

    /**
     * Autenticar usuario contra users.json.
     *
     * @param string $username
     * @param string $password
     * @return array|null Datos del usuario con id, o null si falla
     */
    public function autenticar(string $username, string $password): ?array
    {
        $usuario = $this->userRepo->buscarPorUsername($username);
        $hashFicticio = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';
        $hashVigente = $usuario['password_hash'] ?? $hashFicticio;
        $passwordValida = password_verify($password, $hashVigente);

        if ($usuario === null) {
            $this->auditRepo->registrar([
                'event' => 'login_failed',
                'username' => $username,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'reason' => 'usuario_no_encontrado',
            ]);
            return null;
        }

        if (!$passwordValida) {
            $this->auditRepo->registrar([
                'event' => 'login_failed',
                'user_id' => $usuario['id'],
                'username' => $usuario['username'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'reason' => 'contraseña_incorrecta',
            ]);
            return null;
        }

        if ($usuario['status'] !== 'activo') {
            $this->auditRepo->registrar([
                'event' => 'login_failed',
                'user_id' => $usuario['id'],
                'username' => $usuario['username'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'reason' => 'cuenta_no_activa',
            ]);
            return null;
        }

        // Login exitoso
        $this->userRepo->actualizarUltimoLogin($usuario['id']);
        $this->auditRepo->registrar([
            'event' => 'login_success',
            'user_id' => $usuario['id'],
            'username' => $usuario['username'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        return [
            'id' => $usuario['id'],
            'username' => $usuario['username'],
            'role' => $usuario['role'],
            'must_change_password' => (bool) ($usuario['must_change_password'] ?? false),
            'session_version' => (int) ($usuario['session_version'] ?? 1),
        ];
    }

    /**
     * Registrar cierre de sesión en auditoría.
     *
     * @param array|null $usuario
     */
    public function registrarLogout(?array $usuario): void
    {
        if ($usuario === null) {
            return;
        }
        $this->auditRepo->registrar([
            'event' => 'logout',
            'user_id' => $usuario['id'] ?? null,
            'username' => $usuario['username'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    }

    /**
     * Obtener datos de un usuario por ID.
     *
     * @param string $userId
     * @return array|null
     */
    public function obtenerUsuario(string $userId): ?array
    {
        return $this->userRepo->buscarPorId($userId);
    }
}
