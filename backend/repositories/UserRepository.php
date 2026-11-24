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
    public const SCHEMA_ACTUAL = 2;

    public function __construct(?string $dataDir = null)
    {
        parent::__construct($dataDir);
    }

    public function rutaArchivo(): string
    {
        return $this->dataDir . '/' . $this->filename;
    }

    public static function normalizarUsuario(array $usuario): array
    {
        $usuario['must_change_password'] = (bool) ($usuario['must_change_password'] ?? false);
        $usuario['password_changed_at'] = $usuario['password_changed_at'] ?? null;
        $usuario['session_version'] = max(1, (int) ($usuario['session_version'] ?? 1));
        $usuario['created_by'] = $usuario['created_by'] ?? null;
        $usuario['schema_version'] = self::SCHEMA_ACTUAL;
        return $usuario;
    }

    private function cargarSeguro(): array
    {
        $ruta = $this->rutaArchivo();
        if (!file_exists($ruta)) {
            return [];
        }
        $contenido = file_get_contents($ruta);
        if ($contenido === false || trim($contenido) === '') {
            throw new RuntimeException('El archivo users.json está vacío o no se puede leer.');
        }
        $datos = json_decode($contenido, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($datos)) {
            throw new RuntimeException('El archivo users.json está corrupto.');
        }
        foreach ($datos as $id => $usuario) {
            if (!is_array($usuario)) {
                throw new RuntimeException('El archivo users.json contiene un usuario inválido.');
            }
            $datos[$id] = self::normalizarUsuario($usuario);
        }
        return $datos;
    }

    /**
     * Ejecutar una modificación bajo bloqueo exclusivo del archivo.
     *
     * @param callable(array): array $fn
     * @return mixed
     */
    public function transaccion(callable $fn)
    {
        if (!is_dir($this->dataDir)) {
            throw new RuntimeException('El directorio de datos no existe.');
        }
        $rutaBloqueo = $this->rutaArchivo() . '.lock';
        $fp = fopen($rutaBloqueo, 'c');
        if ($fp === false) {
            throw new RuntimeException('No se pudo abrir el bloqueo de usuarios.');
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException('No se pudo bloquear users.json.');
        }
        try {
            $datos = $this->cargarSeguro();
            $resultado = $fn($datos);
            if (!is_array($resultado) || !array_key_exists('datos', $resultado) || !is_array($resultado['datos'])) {
                throw new LogicException('La transacción debe devolver ["datos" => array].');
            }
            foreach ($resultado['datos'] as $id => $usuario) {
                if (is_array($usuario)) {
                    $resultado['datos'][$id] = self::normalizarUsuario($usuario);
                }
            }
            if (!$this->escribir($resultado['datos'])) {
                throw new RuntimeException('No se pudo escribir users.json.');
            }
            return $resultado['resultado'] ?? null;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Buscar usuario por ID.
     *
     * @param string $id
     * @return array|null
     */
    public function buscarPorId(string $id): ?array
    {
        $data = $this->cargarSeguro();
        $usuario = $data[$id] ?? null;
        return is_array($usuario) ? ['id' => $id] + $usuario : null;
    }

    /**
     * Buscar usuario por nombre de usuario.
     *
     * @param string $username
     * @return array|null
     */
    public function buscarPorUsername(string $username): ?array
    {
        $data = $this->cargarSeguro();
        $buscado = strtolower(trim($username));
        foreach ($data as $id => $usuario) {
            if (strtolower((string) ($usuario['username'] ?? '')) === $buscado) {
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
        $data = $this->cargarSeguro();
        $id = $datos['id'] ?? ('user_' . bin2hex(random_bytes(8)));
        foreach ($data as $usuario) {
            if (strtolower((string) ($usuario['username'] ?? '')) === strtolower((string) ($datos['username'] ?? ''))) {
                throw new RuntimeException('El nombre de usuario ya existe.');
            }
        }
        $datos['id'] = $id;
        $datos['schema_version'] = self::SCHEMA_ACTUAL;
        $datos['created_at'] = $datos['created_at'] ?? date('c');
        $datos['updated_at'] = date('c');
        $datos['last_login_at'] = $datos['last_login_at'] ?? null;
        $datos = self::normalizarUsuario($datos);
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
        $data = $this->cargarSeguro();
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
        $data = $this->cargarSeguro();
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
        $data = $this->cargarSeguro();
        $resultado = [];
        foreach ($data as $id => $usuario) {
            $resultado[$id] = ['id' => $id] + self::normalizarUsuario($usuario);
        }
        return $resultado;
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
