<?php
// ==========================================
// REPOSITORIO DE RUTAS (PHP/data/rutas.json)
// ==========================================
// Persistencia de las rutas administrativas.
//
// Garantías (apartados 8.5 y 16.4 del plan):
//  - Lectura fail-closed: un archivo corrupto o con estructura inválida
//    lanza excepción y NUNCA se devuelve un arreglo vacío.
//  - Archivo ausente: se devuelve una estructura vacía válida.
//  - Escritura bloqueada (lock pesimista) y atómica (tmp + verificación + rename).
//  - Respaldo `rutas.json.bak` antes de cada escritura.
//  - `schema_version` con migración idempotente.
//
// El lock se toma sobre `rutas.json.lock`, no sobre el archivo de datos: el
// reemplazo atómico cambia el inodo y un lock sobre el archivo original
// dejaría de proteger a los procesos que abran la ruta después.

require_once __DIR__ . '/BaseRepository.php';

class RouteRepository extends BaseRepository
{
    protected string $filename = 'rutas.json';

    /** Versión de esquema soportada por este código. */
    public const SCHEMA_ACTUAL = 1;

    /** Patrón de ID de ruta aprobado (D14). */
    private const PATRON_ID = '/^ruta-[a-f0-9]{8}$/';

    public function __construct(?string $dataDir = null)
    {
        parent::__construct();
        if ($dataDir !== null && $dataDir !== '') {
            $this->dataDir = rtrim($dataDir, '/\\');
        }
    }

    public function rutaArchivo(): string
    {
        return $this->dataDir . '/' . $this->filename;
    }

    /**
     * Estructura vacía válida.
     *
     * @return array{schema_version: int, updated_at: string, rutas: array}
     */
    public function estructuraVacia(): array
    {
        return [
            'schema_version' => self::SCHEMA_ACTUAL,
            'updated_at' => date('c'),
            'rutas' => [],
        ];
    }

    /**
     * Leer el documento completo.
     *
     * @return array{schema_version: int, updated_at: string, rutas: array<string, array>}
     * @throws RuntimeException si el archivo es inválido
     */
    public function listar(): array
    {
        return $this->cargar();
    }

    /**
     * Obtener una ruta por ID.
     *
     * @return array|null
     * @throws RuntimeException si el archivo es inválido
     */
    public function obtener(string $id): ?array
    {
        $datos = $this->cargar();
        return $datos['rutas'][$id] ?? null;
    }

    /**
     * Ejecutar una modificación bajo bloqueo exclusivo.
     *
     * El callback recibe el documento actual y debe devolver:
     *   ['datos' => <documento modificado>, 'resultado' => <valor a devolver>]
     *
     * El repositorio valida el documento antes de escribir y actualiza
     * `schema_version` y `updated_at`.
     *
     * @param callable(array): array $fn
     * @return mixed Valor devuelto en `resultado`
     * @throws RuntimeException
     */
    public function actualizarConBloqueo(callable $fn)
    {
        $this->asegurarDirectorio();

        $rutaBloqueo = $this->rutaArchivo() . '.lock';
        $fp = fopen($rutaBloqueo, 'c');
        if ($fp === false) {
            throw new RuntimeException('No se pudo abrir el bloqueo de rutas.');
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException('No se pudo obtener el bloqueo de rutas.');
        }

        try {
            $datos = $this->cargar();
            $resultado = $fn($datos);

            if (!is_array($resultado) || !array_key_exists('datos', $resultado) || !is_array($resultado['datos'])) {
                throw new LogicException('El callback de actualización debe devolver ["datos" => array].');
            }

            $nuevos = $resultado['datos'];
            $nuevos['schema_version'] = self::SCHEMA_ACTUAL;
            $nuevos['updated_at'] = date('c');

            $this->validarEstructura($nuevos);
            $this->escribirConRespaldo($nuevos);

            return $resultado['resultado'] ?? null;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Migración idempotente del esquema.
     *
     * @return array{migrado: bool, schema_version: int, motivo: string}
     * @throws RuntimeException
     */
    public function migrar(): array
    {
        if (!file_exists($this->rutaArchivo())) {
            return ['migrado' => false, 'schema_version' => self::SCHEMA_ACTUAL, 'motivo' => 'archivo ausente'];
        }

        $this->asegurarDirectorio();
        $rutaBloqueo = $this->rutaArchivo() . '.lock';
        $fp = fopen($rutaBloqueo, 'c');
        if ($fp === false) {
            throw new RuntimeException('No se pudo abrir el bloqueo de rutas.');
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException('No se pudo obtener el bloqueo de rutas.');
        }

        try {
            $datos = $this->cargar();
            if ((int) $datos['schema_version'] >= self::SCHEMA_ACTUAL) {
                return ['migrado' => false, 'schema_version' => (int) $datos['schema_version'], 'motivo' => 'ya migrado'];
            }

            $datos['schema_version'] = self::SCHEMA_ACTUAL;
            $datos['updated_at'] = date('c');
            $this->escribirConRespaldo($datos);

            return ['migrado' => true, 'schema_version' => self::SCHEMA_ACTUAL, 'motivo' => 'actualizado'];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // ==========================================
    // INTERNO
    // ==========================================

    /**
     * @return array{schema_version: int, updated_at: string, rutas: array}
     * @throws RuntimeException
     */
    private function cargar(): array
    {
        $ruta = $this->rutaArchivo();
        if (!file_exists($ruta)) {
            return $this->estructuraVacia();
        }

        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            throw new RuntimeException('No se pudo leer rutas.json.');
        }

        if (trim($contenido) === '') {
            throw new RuntimeException('rutas.json está vacío; se rechaza para no sobrescribir datos.');
        }

        $datos = json_decode($contenido, true);
        if (!is_array($datos)) {
            throw new RuntimeException('rutas.json no es JSON válido: ' . json_last_error_msg());
        }

        $datos = $this->normalizar($datos);
        $this->validarEstructura($datos);

        return $datos;
    }

    /**
     * Completar valores por defecto y migrar en memoria.
     */
    private function normalizar(array $datos): array
    {
        if (!isset($datos['schema_version'])) {
            $datos['schema_version'] = 0;
        }
        if (!isset($datos['updated_at']) || !is_string($datos['updated_at'])) {
            $datos['updated_at'] = date('c');
        }
        if (!isset($datos['rutas']) || !is_array($datos['rutas'])) {
            $datos['rutas'] = [];
        }

        return $datos;
    }

    /**
     * @throws RuntimeException
     */
    private function validarEstructura(array $datos): void
    {
        $versionBruta = $datos['schema_version'] ?? null;
        $versionValida = is_int($versionBruta)
            || (is_string($versionBruta) && $versionBruta !== '' && ctype_digit($versionBruta));
        if (!$versionValida) {
            throw new RuntimeException('rutas.json: schema_version ausente o inválido.');
        }

        $version = (int) $versionBruta;
        if ($version > self::SCHEMA_ACTUAL) {
            throw new RuntimeException(
                'rutas.json: schema_version ' . $version . ' es mayor que la soportada (' . self::SCHEMA_ACTUAL . ').'
            );
        }

        if (!isset($datos['rutas']) || !is_array($datos['rutas'])) {
            throw new RuntimeException('rutas.json: falta el objeto rutas.');
        }

        foreach ($datos['rutas'] as $clave => $ruta) {
            if (!is_string($clave) || preg_match(self::PATRON_ID, $clave) !== 1) {
                throw new RuntimeException('rutas.json: clave de ruta inválida: ' . (string) $clave);
            }
            if (!is_array($ruta)) {
                throw new RuntimeException('rutas.json: la ruta ' . $clave . ' no es un objeto.');
            }
            if (($ruta['id'] ?? null) !== $clave) {
                throw new RuntimeException('rutas.json: el id de la ruta ' . $clave . ' no coincide con su clave.');
            }
            if (!isset($ruta['nombre']) || !is_string($ruta['nombre']) || trim($ruta['nombre']) === '') {
                throw new RuntimeException('rutas.json: la ruta ' . $clave . ' no tiene nombre.');
            }
            if (!isset($ruta['segmentos']) || !is_array($ruta['segmentos'])) {
                throw new RuntimeException('rutas.json: la ruta ' . $clave . ' no tiene segmentos.');
            }
        }
    }

    /**
     * @throws RuntimeException
     */
    private function escribirConRespaldo(array $datos): void
    {
        $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar rutas.json.');
        }

        $ruta = $this->rutaArchivo();

        if (file_exists($ruta)) {
            if (!copy($ruta, $ruta . '.bak')) {
                throw new RuntimeException('No se pudo crear el respaldo rutas.json.bak.');
            }
        }

        $temporal = $ruta . '.tmp.' . uniqid('', true);
        if (file_put_contents($temporal, $json) === false) {
            throw new RuntimeException('No se pudo escribir el archivo temporal de rutas.');
        }

        $verificacion = json_decode((string) file_get_contents($temporal), true);
        if (!is_array($verificacion)) {
            @unlink($temporal);
            throw new RuntimeException('La verificación del archivo temporal de rutas falló.');
        }
        try {
            $this->validarEstructura($this->normalizar($verificacion));
        } catch (RuntimeException $e) {
            @unlink($temporal);
            throw new RuntimeException('La verificación de rutas.json falló: ' . $e->getMessage());
        }

        if (!rename($temporal, $ruta)) {
            @unlink($temporal);
            throw new RuntimeException('No se pudo reemplazar rutas.json.');
        }
    }

    /**
     * @throws RuntimeException
     */
    private function asegurarDirectorio(): void
    {
        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0775, true) && !is_dir($this->dataDir)) {
            throw new RuntimeException('No se pudo crear el directorio de datos: ' . $this->dataDir);
        }
    }
}
