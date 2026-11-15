<?php
// ==========================================
// REPOSITORIO DE USUARIOS (users.json)
// ==========================================
// Persistencia y consulta de cuentas de administrador.
// Operaciones CRUD sobre archivo JSON con bloqueo y escritura atómica.

require_once __DIR__ . '/../repositories/BaseRepository.php';

class UserRepository extends BaseRepository
{
    protected string $filename = 'users.json';

    /**
     * Buscar usuario por ID.
     *
     * @param string $id
     * @return array|null
     */
    public function buscarPorId(string $id): ?array
    {
        $data = $this->leer();
        return $data[$id] ?? null;
    }

    /**
     * Buscar usuario por nombre de usuario.
     *
     * @param string $username
     * @return array|null
     */
    public function buscarPorUsername(string $username): ?array
    {
        $data = $this->leer();
        foreach ($data as $id => $usuario) {
            if ($usuario['username'] === $username) {
                return ['id' => $id] + $usuario;
            }
        }
        return null;
    }

    /**
     * Crear nuevo usuario.
     *
     * @param array $datos Datos del usuario (sin id)
     * @return string ID del usuario creado
     */
    public function crear(array $datos): string
    {
        $data = $this->leer();
        $id = $datos['id'] ?? uniqid('user_');
        $datos['id'] = $id;
        $datos['schema_version'] = $datos['schema_version'] ?? 1;
        $datos['created_at'] = $datos['created_at'] ?? date('c');
        $datos['updated_at'] = date('c');
        $datos['last_login_at'] = $datos['last_login_at'] ?? null;

        $data[$id] = $datos;
        $this->escribir($data);
        return $id;
    }

    /**
     * Actualizar campos de usuario.
     *
     * @param string $id
     * @param array $datos
     * @return bool
     */
    public function actualizar(string $id, array $datos): bool
    {
        $data = $this->leer();
        if (!isset($data[$id])) {
            return false;
        }
        $data[$id] = array_merge($data[$id], $datos);
        $data[$id]['updated_at'] = date('c');
        $this->escribir($data);
        return true;
    }

    /**
     * Eliminar usuario.
     *
     * @param string $id
     * @return bool
     */
    public function eliminar(string $id): bool
    {
        $data = $this->leer();
        if (!isset($data[$id])) {
            return false;
        }
        unset($data[$id]);
        $this->escribir($data);
        return true;
    }

    /**
     * Listar todos los usuarios.
     *
     * @return array
     */
    public function listar(): array
    {
        return $this->leer();
    }

    /**
     * Actualizar fecha de último login.
     *
     * @param string $id
     * @return bool
     */
    public function actualizarUltimoLogin(string $id): bool
    {
        return $this->actualizar($id, ['last_login_at' => date('c')]);
    }
}
