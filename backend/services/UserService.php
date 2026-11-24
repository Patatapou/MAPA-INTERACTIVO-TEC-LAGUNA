<?php

require_once __DIR__ . '/../repositories/UserRepository.php';
require_once __DIR__ . '/../repositories/AuditLogRepository.php';
require_once __DIR__ . '/UserServiceException.php';

class UserService
{
    private UserRepository $repo;
    private AuditLogRepository $auditoria;

    public function __construct(?string $dataDir = null)
    {
        $this->repo = new UserRepository($dataDir);
        $this->auditoria = new AuditLogRepository($dataDir);
    }

    public function listar(): array
    {
        $usuarios = $this->repo->listar();
        $salida = [];
        foreach ($usuarios as $id => $usuario) {
            $salida[$id] = $this->usuarioPublico($usuario);
        }
        return $salida;
    }

    public function usuarioPublico(array $usuario): array
    {
        $permitidos = [
            'id', 'username', 'email', 'role', 'status', 'must_change_password',
            'password_changed_at', 'session_version', 'created_by', 'created_at',
            'updated_at', 'last_login_at', 'schema_version',
        ];
        $salida = [];
        foreach ($permitidos as $campo) {
            if (array_key_exists($campo, $usuario)) {
                $salida[$campo] = $usuario[$campo];
            }
        }
        return $salida;
    }

    public function generarPasswordTemporal(): string
    {
        $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $bytes = random_bytes(16);
        $salida = '';
        for ($i = 0; $i < 16; $i++) {
            $salida .= $chars[ord($bytes[$i]) % strlen($chars)];
        }
        return $salida;
    }

    public function validarUsuario(string $username): void
    {
        if (preg_match('/^[a-z0-9._-]{3,32}$/D', $username) !== 1) {
            throw new UserServiceException(
                'El nombre de usuario no es válido.',
                400,
                ['Use de 3 a 32 caracteres: letras minúsculas, números, punto, guion bajo o guion.']
            );
        }
    }

    public function validarEmail(?string $email): void
    {
        if ($email === null || trim($email) === '') {
            return;
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new UserServiceException('El correo no es válido.', 400, ['Ingrese un correo válido o deje el campo vacío.']);
        }
    }

    public function validarPassword(string $password, string $username = ''): void
    {
        $errores = [];
        if (strlen($password) < 12) {
            $errores[] = 'La contraseña debe tener al menos 12 caracteres.';
        }
        if (strlen($password) > 72) {
            $errores[] = 'La contraseña no puede exceder 72 bytes.';
        }
        if ($username !== '' && strtolower($password) === strtolower($username)) {
            $errores[] = 'La contraseña debe ser distinta del nombre de usuario.';
        }
        $comunes = ['password123', '123456789012', 'contrasena123', 'administrador', 'qwerty123456'];
        if (in_array(strtolower($password), $comunes, true)) {
            $errores[] = 'La contraseña pertenece a una lista corta de contraseñas comunes.';
        }
        if ($errores !== []) {
            throw new UserServiceException('La contraseña no cumple la política.', 400, $errores);
        }
    }

    private function usuarioActor(array $actor): array
    {
        return [
            'actor_id' => (string) ($actor['id'] ?? ''),
            'actor_username' => (string) ($actor['username'] ?? ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
    }

    private function auditar(string $evento, array $actor, array $objetivo): void
    {
        $this->auditoria->registrar(array_merge(
            ['event' => $evento],
            $this->usuarioActor($actor),
            [
                'target_user_id' => (string) ($objetivo['id'] ?? ''),
                'target_username' => (string) ($objetivo['username'] ?? ''),
            ]
        ));
    }

    public function crear(array $datos, array $actor): array
    {
        if (!isset($datos['username']) || !is_string($datos['username'])) {
            throw new UserServiceException('Los datos deben enviarse como cadenas.', 400);
        }
        if (array_key_exists('email', $datos) && $datos['email'] !== null && !is_string($datos['email'])) {
            throw new UserServiceException('Los datos deben enviarse como cadenas.', 400);
        }
        $username = strtolower(trim((string) ($datos['username'] ?? '')));
        $this->validarUsuario($username);
        $email = array_key_exists('email', $datos) ? trim((string) $datos['email']) : null;
        $this->validarEmail($email === '' ? null : $email);

        $temporal = $this->generarPasswordTemporal();
        $ahora = date('c');
        $id = '';

        $this->repo->transaccion(function (array $data) use ($username, $email, $temporal, $ahora, $actor, &$id) {
            foreach ($data as $existente) {
                if (strtolower((string) ($existente['username'] ?? '')) === $username) {
                    throw new UserServiceException('Ya existe un usuario con ese nombre.', 409);
                }
            }
            $id = 'user_' . bin2hex(random_bytes(8));
            $usuario = [
                'id' => $id,
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($temporal, PASSWORD_DEFAULT),
                'role' => 'administrador',
                'status' => 'activo',
                'must_change_password' => true,
                'password_changed_at' => null,
                'session_version' => 1,
                'created_by' => (string) ($actor['id'] ?? ''),
                'created_at' => $ahora,
                'updated_at' => $ahora,
                'last_login_at' => null,
                'schema_version' => 2,
            ];
            $data[$id] = $usuario;
            return ['datos' => $data, 'resultado' => $usuario];
        });

        $creado = $this->repo->buscarPorId($id);
        $this->auditar('user_created', $actor, $creado ?? ['id' => $id, 'username' => $username]);
        return [
            'usuario' => $this->usuarioPublico($creado ?? []),
            'password_temporal' => $temporal,
        ];
    }

    public function actualizarEmail(string $id, array $datos, array $actor): array
    {
        if (!isset($datos['email']) || !is_string($datos['email'])) {
            throw new UserServiceException('El correo debe enviarse como cadena.', 400);
        }
        $email = trim($datos['email']);
        $this->validarEmail($email === '' ? null : $email);
        $resultado = null;
        $this->repo->transaccion(function (array $data) use ($id, $email, &$resultado) {
            if (!isset($data[$id])) {
                throw new UserServiceException('El usuario no existe.', 404);
            }
            $data[$id]['email'] = $email === '' ? null : $email;
            $data[$id]['updated_at'] = date('c');
            $resultado = $data[$id];
            return ['datos' => $data, 'resultado' => $resultado];
        });
        $this->auditar('user_email_updated', $actor, $resultado);
        return $this->usuarioPublico($resultado);
    }

    public function contarActivos(array $data): int
    {
        $total = 0;
        foreach ($data as $usuario) {
            if (($usuario['status'] ?? '') === 'activo') {
                $total++;
            }
        }
        return $total;
    }

    public function setEstado(string $id, string $estado, array $actor): array
    {
        if (!in_array($estado, ['activo', 'inactivo', 'bloqueado'], true)) {
            throw new UserServiceException('Estado inválido.', 400);
        }
        $resultado = null;
        $this->repo->transaccion(function (array $data) use ($id, $estado, $actor, &$resultado) {
            if (!isset($data[$id])) {
                throw new UserServiceException('El usuario no existe.', 404);
            }
            $objetivo = $data[$id];
            if ((string) ($actor['id'] ?? '') === $id && $estado !== 'activo') {
                throw new UserServiceException('No puede desactivar ni bloquear su propia cuenta.', 400);
            }
            if ($estado !== 'activo' && ($objetivo['status'] ?? '') === 'activo') {
                if ($this->contarActivos($data) <= 1) {
                    throw new UserServiceException('No puede desactivar al último administrador activo.', 400);
                }
            }
            $objetivo['status'] = $estado;
            $objetivo['updated_at'] = date('c');
            if ($estado !== 'activo') {
                $objetivo['session_version'] = (int) ($objetivo['session_version'] ?? 1) + 1;
            }
            $data[$id] = $objetivo;
            $resultado = $objetivo;
            return ['datos' => $data, 'resultado' => $objetivo];
        });
        $this->auditar($estado === 'activo' ? 'user_activated' : 'user_deactivated', $actor, $resultado);
        return $this->usuarioPublico($resultado);
    }

    public function resetPassword(string $id, array $actor): array
    {
        $temporal = $this->generarPasswordTemporal();
        $resultado = null;
        $this->repo->transaccion(function (array $data) use ($id, $temporal, &$resultado) {
            if (!isset($data[$id])) {
                throw new UserServiceException('El usuario no existe.', 404);
            }
            $usuario = $data[$id];
            $usuario['password_hash'] = password_hash($temporal, PASSWORD_DEFAULT);
            $usuario['must_change_password'] = true;
            $usuario['password_changed_at'] = null;
            $usuario['session_version'] = (int) ($usuario['session_version'] ?? 1) + 1;
            $usuario['updated_at'] = date('c');
            $data[$id] = $usuario;
            $resultado = $usuario;
            return ['datos' => $data, 'resultado' => $usuario];
        });
        $this->auditar('user_password_reset', $actor, $resultado);
        return ['usuario' => $this->usuarioPublico($resultado), 'password_temporal' => $temporal];
    }

    public function cambiarPassword(string $id, array $datos, array $actor): array
    {
        foreach (['password_actual', 'password_nueva', 'password_confirmacion'] as $campo) {
            if (!isset($datos[$campo]) || !is_string($datos[$campo])) {
                throw new UserServiceException('Las contraseñas deben enviarse como cadenas.', 400);
            }
        }
        $actual = $datos['password_actual'];
        $nueva = $datos['password_nueva'];
        $confirmacion = $datos['password_confirmacion'];
        if (!hash_equals($nueva, $confirmacion)) {
            throw new UserServiceException('La confirmación no coincide.', 400);
        }
        $resultado = null;
        $this->repo->transaccion(function (array $data) use ($id, $actual, $nueva, &$resultado) {
            if (!isset($data[$id])) {
                throw new UserServiceException('El usuario no existe.', 404);
            }
            $usuario = $data[$id];
            if (!password_verify($actual, (string) ($usuario['password_hash'] ?? ''))) {
                throw new UserServiceException('La contraseña actual no es correcta.', 403);
            }
            $this->validarPassword($nueva, (string) ($usuario['username'] ?? ''));
            if (password_verify($nueva, (string) $usuario['password_hash'])) {
                throw new UserServiceException('La nueva contraseña debe ser distinta de la actual.', 400);
            }
            $ahora = date('c');
            $usuario['password_hash'] = password_hash($nueva, PASSWORD_DEFAULT);
            $usuario['must_change_password'] = false;
            $usuario['password_changed_at'] = $ahora;
            $usuario['session_version'] = (int) ($usuario['session_version'] ?? 1) + 1;
            $usuario['updated_at'] = $ahora;
            $data[$id] = $usuario;
            $resultado = $usuario;
            return ['datos' => $data, 'resultado' => $usuario];
        });
        $this->auditar('user_password_changed', $actor, $resultado);
        return $this->usuarioPublico($resultado);
    }

    public function eliminar(string $id, array $actor): bool
    {
        $resultado = false;
        $this->repo->transaccion(function (array $data) use ($id, $actor, &$resultado) {
            if (!isset($data[$id])) {
                throw new UserServiceException('El usuario no existe.', 404);
            }
            if ((string) ($actor['id'] ?? '') === $id) {
                throw new UserServiceException('No puede eliminar su propia cuenta.', 400);
            }
            $usuario = $data[$id];
            if (($usuario['status'] ?? '') !== 'inactivo') {
                throw new UserServiceException('Solo se pueden eliminar cuentas inactivas.', 400);
            }
            unset($data[$id]);
            $resultado = true;
            $objetivo = $usuario;
            $this->auditar('user_deleted', $actor, $objetivo);
            return ['datos' => $data, 'resultado' => true];
        });
        return $resultado;
    }
}
