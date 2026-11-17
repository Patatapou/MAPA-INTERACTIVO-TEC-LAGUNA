<?php
// ==========================================
// SERVICIO DE RUTAS ADMINISTRATIVAS
// ==========================================
// Reglas de negocio del módulo de rutas:
//  - Validación de esquema y de la cadena de segmentos.
//  - Nombre único (case-insensitive).
//  - Origen y destino existentes en datos.json o en la red.
//  - Conflicto de versión con `updated_at`.
//  - Hidratación en lectura (geometría, nodos, distancia).
//  - Auditoría best-effort.
//
// Excepciones con código HTTP asociado:
//  - RouteValidationException (400)
//  - RouteNotFoundException  (404)
//  - RouteConflictException  (409)

require_once __DIR__ . '/../repositories/RouteRepository.php';
require_once __DIR__ . '/../repositories/AuditLogRepository.php';
require_once __DIR__ . '/PathNetworkService.php';

class RouteServiceException extends RuntimeException
{
    /** @var array<int, string> */
    protected array $detalles = [];

    public function __construct(string $message, int $statusCode = 400, array $detalles = [])
    {
        parent::__construct($message, $statusCode);
        $this->detalles = array_values($detalles);
    }

    public function statusCode(): int
    {
        return (int) $this->getCode();
    }

    /** @return array<int, string> */
    public function detalles(): array
    {
        return $this->detalles;
    }
}

class RouteValidationException extends RouteServiceException
{
    public function __construct(string $message = 'Datos inválidos.', array $detalles = [])
    {
        parent::__construct($message, 400, $detalles);
    }
}

class RouteNotFoundException extends RouteServiceException
{
    public function __construct(string $message = 'La ruta no existe.')
    {
        parent::__construct($message, 404);
    }
}

class RouteConflictException extends RouteServiceException
{
    public function __construct(string $message = 'La ruta fue modificada por otra sesión.')
    {
        parent::__construct($message, 409);
    }
}

class RouteService
{
    public const TIPOS = ['principal', 'alterna', 'acceso', 'emergencia', 'institucional'];
    public const ESTADOS = ['activa', 'inactiva', 'borrador'];
    public const TIPOS_REFERENCIA = ['acceso', 'edificio', 'nodo'];

    private RouteRepository $repo;
    private PathNetworkService $red;
    private AuditLogRepository $auditoria;
    private string $rutaDatos;
    private array $config;

    /** @var array|null */
    private ?array $datosCache = null;

    public function __construct(
        ?RouteRepository $repo = null,
        ?PathNetworkService $red = null,
        ?AuditLogRepository $auditoria = null,
        ?string $rutaDatos = null,
        ?array $config = null
    ) {
        $this->repo = $repo ?? new RouteRepository();
        $this->red = $red ?? new PathNetworkService();
        $this->auditoria = $auditoria ?? new AuditLogRepository();
        $this->rutaDatos = $rutaDatos ?? dirname(__DIR__, 2) . '/datos.json';
        $this->config = array_merge($this->configPorDefecto(), $this->configDesdeSettings(), $config ?? []);
    }

    public function config(): array
    {
        return $this->config;
    }

    /**
     * Listado de rutas hidratadas.
     *
     * @return array{schema_version: int, updated_at: string, total: int, rutas: array<int, array>}
     */
    public function listar(): array
    {
        $documento = $this->repo->listar();
        $rutas = [];

        foreach ($documento['rutas'] as $ruta) {
            $rutas[] = $this->presentar($ruta, true);
        }

        usort($rutas, static function ($a, $b) {
            return strcasecmp($a['nombre'], $b['nombre']);
        });

        return [
            'schema_version' => (int) $documento['schema_version'],
            'updated_at' => $documento['updated_at'],
            'total' => count($rutas),
            'rutas' => $rutas,
        ];
    }

    /**
     * Detalle hidratado de una ruta.
     *
     * @throws RouteNotFoundException
     */
    public function obtener(string $id): array
    {
        $ruta = $this->repo->obtener($id);
        if ($ruta === null) {
            throw new RouteNotFoundException('No existe la ruta ' . $id . '.');
        }
        return $this->presentar($ruta, false);
    }

    /**
     * Crear o actualizar una ruta.
     *
     * @param array $entrada Datos del formulario (sin geometría)
     * @param array $usuario ['id' => string, 'username' => string]
     * @return array Ruta hidratada
     * @throws RouteValidationException|RouteNotFoundException|RouteConflictException
     */
    public function guardar(array $entrada, array $usuario): array
    {
        $limpio = $this->validarEntrada($entrada);
        $esEdicion = $limpio['id'] !== null;
        $versionEsperada = isset($entrada['updated_at']) && is_string($entrada['updated_at'])
            ? trim($entrada['updated_at'])
            : '';

        $usuarioId = (string) ($usuario['id'] ?? '');
        $usuarioNombre = (string) ($usuario['username'] ?? '');

        $resultado = $this->repo->actualizarConBloqueo(function (array $documento) use (
            $limpio,
            $esEdicion,
            $versionEsperada,
            $usuarioId
        ): array {
            $rutas = $documento['rutas'];
            $id = $limpio['id'];

            $anterior = null;
            $evento = 'ruta_creada';

            if ($esEdicion) {
                if (!isset($rutas[$id])) {
                    throw new RouteNotFoundException('No existe la ruta ' . $id . '.');
                }
                $anterior = $rutas[$id];
                if ($versionEsperada !== '' && ($anterior['actualizado_en'] ?? '') !== $versionEsperada) {
                    throw new RouteConflictException(
                        'La ruta fue modificada por otra sesión. Recargue antes de guardar.'
                    );
                }
                $evento = 'ruta_actualizada';
            } else {
                $id = $this->generarIdUnico($rutas);
            }

            $this->verificarNombreUnico($rutas, $limpio['nombre'], $esEdicion ? $id : null);

            $ahora = date('c');

            if ($esEdicion) {
                $ruta = array_merge($anterior, [
                    'nombre' => $limpio['nombre'],
                    'descripcion' => $limpio['descripcion'],
                    'tipo' => $limpio['tipo'],
                    'estado' => $limpio['estado'],
                    'accesible' => $limpio['accesible'],
                    'origen' => $limpio['origen'],
                    'destino' => $limpio['destino'],
                    'segmentos' => $limpio['segmentos'],
                    'actualizado_en' => $ahora,
                    'actualizado_por' => $usuarioId,
                ]);
            } else {
                $ruta = [
                    'id' => $id,
                    'nombre' => $limpio['nombre'],
                    'descripcion' => $limpio['descripcion'],
                    'tipo' => $limpio['tipo'],
                    'estado' => $limpio['estado'],
                    'accesible' => $limpio['accesible'],
                    'origen' => $limpio['origen'],
                    'destino' => $limpio['destino'],
                    'segmentos' => $limpio['segmentos'],
                    'creado_en' => $ahora,
                    'actualizado_en' => $ahora,
                    'creado_por' => $usuarioId,
                    'actualizado_por' => $usuarioId,
                ];
            }

            $rutas[$id] = $ruta;
            $documento['rutas'] = $rutas;

            return [
                'datos' => $documento,
                'resultado' => ['ruta' => $ruta, 'evento' => $evento, 'anterior' => $anterior],
            ];
        });

        $ruta = $resultado['ruta'];

        $this->auditar($resultado['evento'], [
            'ruta_id' => $ruta['id'],
            'nombre' => $ruta['nombre'],
            'user_id' => $usuarioId,
            'username' => $usuarioNombre,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'segmentos' => count($ruta['segmentos']),
            'estado' => $ruta['estado'],
            'tipo' => $ruta['tipo'],
        ]);

        return $this->presentar($ruta, false);
    }

    /**
     * Eliminar una ruta (borrado físico, sin tocar la red).
     *
     * @throws RouteNotFoundException
     */
    public function eliminar(string $id, array $usuario = []): array
    {
        if (preg_match('/^ruta-[a-f0-9]{8}$/', $id) !== 1) {
            throw new RouteValidationException('ID de ruta inválido.', ['Formato esperado: ruta-xxxxxxxx.']);
        }

        $ruta = $this->repo->actualizarConBloqueo(function (array $documento) use ($id): array {
            if (!isset($documento['rutas'][$id])) {
                throw new RouteNotFoundException('No existe la ruta ' . $id . '.');
            }
            $ruta = $documento['rutas'][$id];
            unset($documento['rutas'][$id]);

            return ['datos' => $documento, 'resultado' => $ruta];
        });

        $this->auditar('ruta_eliminada', [
            'ruta_id' => $ruta['id'],
            'nombre' => $ruta['nombre'],
            'user_id' => (string) ($usuario['id'] ?? ''),
            'username' => (string) ($usuario['username'] ?? ''),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        return [
            'id' => $ruta['id'],
            'nombre' => $ruta['nombre'],
            'eliminada' => true,
        ];
    }

    /**
     * Comprobar integridad de todas las rutas contra la red actual.
     *
     * @return array{rutas: int, inconsistentes: array<int, string>, advertencias: array<int, string>}
     */
    public function verificarIntegridad(): array
    {
        $documento = $this->repo->listar();
        $inconsistentes = [];
        $advertencias = [];

        foreach ($documento['rutas'] as $id => $ruta) {
            $hidratado = $this->red->hidratar($ruta);
            if ($hidratado['inconsistente']) {
                $inconsistentes[] = (string) $id;
            }
            foreach ($hidratado['advertencias'] as $aviso) {
                $advertencias[] = $id . ': ' . $aviso;
            }
        }

        return [
            'rutas' => count($documento['rutas']),
            'inconsistentes' => $inconsistentes,
            'advertencias' => $advertencias,
        ];
    }

    // ==========================================
    // VALIDACIÓN
    // ==========================================

    /**
     * @return array{id: ?string, nombre: string, descripcion: string, tipo: string, estado: string, accesible: bool, origen: array, destino: array, segmentos: array<int, array{id: string, sentido: string}>}
     * @throws RouteValidationException
     */
    private function validarEntrada(array $entrada): array
    {
        $errores = [];

        $id = null;
        if (isset($entrada['id']) && $entrada['id'] !== '') {
            if (!is_string($entrada['id']) || preg_match('/^ruta-[a-f0-9]{8}$/', $entrada['id']) !== 1) {
                $errores[] = 'ID de ruta inválido.';
            } else {
                $id = $entrada['id'];
            }
        }

        $nombre = is_string($entrada['nombre'] ?? null) ? trim($entrada['nombre']) : '';
        $longitudNombre = function_exists('mb_strlen') ? mb_strlen($nombre) : strlen($nombre);
        if ($longitudNombre < 3 || $longitudNombre > 80) {
            $errores[] = 'El nombre debe tener entre 3 y 80 caracteres.';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $nombre) === 1) {
            $errores[] = 'El nombre contiene caracteres de control.';
        }

        $descripcion = is_string($entrada['descripcion'] ?? null) ? trim($entrada['descripcion']) : '';
        $longitudDescripcion = function_exists('mb_strlen') ? mb_strlen($descripcion) : strlen($descripcion);
        if ($longitudDescripcion > 500) {
            $errores[] = 'La descripción no puede superar los 500 caracteres.';
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $descripcion) === 1) {
            $errores[] = 'La descripción contiene caracteres de control.';
        }

        $tipo = is_string($entrada['tipo'] ?? null) ? $entrada['tipo'] : '';
        if (!in_array($tipo, self::TIPOS, true)) {
            $errores[] = 'Tipo de ruta inválido. Valores permitidos: ' . implode(', ', self::TIPOS) . '.';
        }

        $estado = is_string($entrada['estado'] ?? null) ? $entrada['estado'] : '';
        if (!in_array($estado, self::ESTADOS, true)) {
            $errores[] = 'Estado de ruta inválido. Valores permitidos: ' . implode(', ', self::ESTADOS) . '.';
        }

        $accesible = $this->normalizarBooleano($entrada['accesible'] ?? true, $errores);

        $origen = $this->validarReferencia($entrada['origen'] ?? null, 'origen', $errores);
        $destino = $this->validarReferencia($entrada['destino'] ?? null, 'destino', $errores);

        $segmentos = $this->normalizarSegmentos($entrada['segmentos'] ?? null, $errores);

        if ($segmentos !== []) {
            $max = (int) ($this->config['max_segmentos_por_ruta'] ?? 500);
            $tolerancia = (float) ($this->config['tolerancia_continuidad_m'] ?? 2.5);
            $validacion = $this->red->validarCadena($segmentos, $tolerancia, $max);
            foreach ($validacion['errores'] as $error) {
                $errores[] = $error;
            }
        }

        if ($errores !== []) {
            throw new RouteValidationException('No se pudo guardar la ruta.', $errores);
        }

        return [
            'id' => $id,
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'tipo' => $tipo,
            'estado' => $estado,
            'accesible' => $accesible,
            'origen' => $origen,
            'destino' => $destino,
            'segmentos' => $segmentos,
        ];
    }

    /**
     * @return array{tipo: string, ref: string}
     */
    private function validarReferencia($referencia, string $campo, array &$errores): array
    {
        if (!is_array($referencia)) {
            $errores[] = 'Falta la referencia de ' . $campo . '.';
            return ['tipo' => '', 'ref' => ''];
        }

        $tipo = is_string($referencia['tipo'] ?? null) ? $referencia['tipo'] : '';
        $ref = is_string($referencia['ref'] ?? null) ? trim($referencia['ref']) : '';

        if (!in_array($tipo, self::TIPOS_REFERENCIA, true)) {
            $errores[] = 'Tipo de ' . $campo . ' inválido. Valores permitidos: ' . implode(', ', self::TIPOS_REFERENCIA) . '.';
        }
        if ($ref === '') {
            $errores[] = 'Falta la referencia de ' . $campo . '.';
        }

        if ($tipo !== '' && $ref !== '' && in_array($tipo, self::TIPOS_REFERENCIA, true)) {
            if ($tipo === 'nodo') {
                $nodos = $this->red->nodos();
                if (!isset($nodos[$ref])) {
                    $errores[] = 'El nodo de ' . $campo . ' no existe en la red: ' . $ref . '.';
                }
            } else {
                $datos = $this->datosEdificios();
                if (!isset($datos[$ref])) {
                    $errores[] = 'El ' . $tipo . ' de ' . $campo . ' no existe en datos.json: ' . $ref . '.';
                } else {
                    $tipoReal = (string) ($datos[$ref]['tipo'] ?? 'edificio');
                    if ($tipoReal !== $tipo) {
                        $errores[] = sprintf(
                            'El %s de %s es de tipo "%s", no "%s".',
                            $campo,
                            $ref,
                            $tipoReal,
                            $tipo
                        );
                    }
                }
            }
        }

        return ['tipo' => $tipo, 'ref' => $ref];
    }

    /**
     * @return array<int, array{id: string, sentido: string}>
     */
    private function normalizarSegmentos($segmentos, array &$errores): array
    {
        if (!is_array($segmentos)) {
            $errores[] = 'La ruta no tiene segmentos.';
            return [];
        }

        if ($segmentos === []) {
            $errores[] = 'La ruta no tiene segmentos.';
            return [];
        }

        $limpios = [];
        foreach (array_values($segmentos) as $posicion => $segmento) {
            $numero = $posicion + 1;
            if (!is_array($segmento)) {
                $errores[] = 'Segmento ' . $numero . ': formato inválido.';
                continue;
            }
            $id = is_string($segmento['id'] ?? null) ? trim($segmento['id']) : '';
            $sentido = is_string($segmento['sentido'] ?? null) ? $segmento['sentido'] : '';

            if ($id === '') {
                $errores[] = 'Segmento ' . $numero . ': falta el id.';
                continue;
            }
            if ($sentido !== 'normal' && $sentido !== 'inverso') {
                $errores[] = 'Segmento ' . $id . ': sentido inválido.';
                continue;
            }

            // Se ignoran explícitamente los campos extra (solo id y sentido).
            $limpios[] = ['id' => $id, 'sentido' => $sentido];
        }

        return $limpios;
    }

    private function normalizarBooleano($valor, array &$errores): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }
        if ($valor === 1 || $valor === '1' || $valor === 'true' || $valor === 'on') {
            return true;
        }
        if ($valor === 0 || $valor === '0' || $valor === 'false' || $valor === '') {
            return false;
        }
        $errores[] = 'El campo accesible debe ser booleano.';
        return true;
    }

    /**
     * @throws RouteValidationException
     */
    private function verificarNombreUnico(array $rutas, string $nombre, ?string $idExcluir): void
    {
        $objetivo = $this->normalizarNombre($nombre);
        foreach ($rutas as $id => $ruta) {
            if ($idExcluir !== null && $id === $idExcluir) {
                continue;
            }
            if ($this->normalizarNombre((string) ($ruta['nombre'] ?? '')) === $objetivo) {
                throw new RouteValidationException(
                    'Ya existe una ruta con ese nombre.',
                    ['El nombre "' . $nombre . '" está en uso por la ruta ' . $id . '.']
                );
            }
        }
    }

    private function normalizarNombre(string $nombre): string
    {
        $nombre = trim($nombre);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($nombre, 'UTF-8');
        }
        return strtolower($nombre);
    }

    private function generarIdUnico(array $rutas): string
    {
        do {
            $id = 'ruta-' . bin2hex(random_bytes(4));
        } while (isset($rutas[$id]));
        return $id;
    }

    // ==========================================
    // PRESENTACIÓN
    // ==========================================

    private function presentar(array $ruta, bool $resumen): array
    {
        $hidratado = $this->red->hidratar($ruta);
        $vinculos = $this->resolverVinculos($ruta);

        $salida = [
            'id' => $ruta['id'],
            'nombre' => $ruta['nombre'],
            'descripcion' => (string) ($ruta['descripcion'] ?? ''),
            'tipo' => (string) ($ruta['tipo'] ?? 'principal'),
            'estado' => (string) ($ruta['estado'] ?? 'borrador'),
            'accesible' => (bool) ($ruta['accesible'] ?? true),
            'origen' => $ruta['origen'] ?? ['tipo' => '', 'ref' => ''],
            'destino' => $ruta['destino'] ?? ['tipo' => '', 'ref' => ''],
            'origen_nombre' => $vinculos['origen'],
            'destino_nombre' => $vinculos['destino'],
            'n_segmentos' => (int) ($hidratado['n_segmentos'] ?? 0),
            'distancia_m' => (float) $hidratado['distancia_m'],
            'inconsistente' => (bool) $hidratado['inconsistente'],
            'advertencias' => $hidratado['advertencias'],
            'creado_en' => (string) ($ruta['creado_en'] ?? ''),
            'actualizado_en' => (string) ($ruta['actualizado_en'] ?? ''),
            'updated_at' => (string) ($ruta['actualizado_en'] ?? ''),
            'creado_por' => (string) ($ruta['creado_por'] ?? ''),
            'actualizado_por' => (string) ($ruta['actualizado_por'] ?? ''),
            'geometria' => $hidratado['geometria'],
        ];

        if (!$resumen) {
            $salida['nodos'] = $hidratado['nodos'];
            $salida['segmentos_detalle'] = $hidratado['segmentos_detalle'];
            $salida['segmentos'] = $ruta['segmentos'];
        }

        return $salida;
    }

    /**
     * @return array{origen: string, destino: string}
     */
    private function resolverVinculos(array $ruta): array
    {
        $datos = $this->datosEdificios();
        $resolver = static function ($referencia) use ($datos): string {
            if (!is_array($referencia)) {
                return '';
            }
            $tipo = (string) ($referencia['tipo'] ?? '');
            $ref = (string) ($referencia['ref'] ?? '');
            if ($tipo === 'nodo') {
                return 'Nodo ' . $ref;
            }
            return (string) ($datos[$ref]['nombre'] ?? $ref);
        };

        return [
            'origen' => $resolver($ruta['origen'] ?? null),
            'destino' => $resolver($ruta['destino'] ?? null),
        ];
    }

    /**
     * @return array<string, array>
     * @throws RuntimeException si datos.json no se puede leer
     */
    private function datosEdificios(): array
    {
        if ($this->datosCache !== null) {
            return $this->datosCache;
        }

        if (!file_exists($this->rutaDatos)) {
            throw new RuntimeException('No existe datos.json; no se pueden validar origen y destino.');
        }

        $contenido = file_get_contents($this->rutaDatos);
        $datos = $contenido !== false ? json_decode($contenido, true) : null;
        if (!is_array($datos)) {
            throw new RuntimeException('datos.json no es JSON válido; no se pueden validar origen y destino.');
        }

        $this->datosCache = $datos;
        return $datos;
    }

    private function auditar(string $evento, array $datos): void
    {
        try {
            $this->auditoria->registrar(array_merge(['event' => $evento], $datos));
        } catch (Throwable $e) {
            // La auditoría es best-effort: no revierte la operación principal.
            error_log('Auditoría de rutas falló: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function configPorDefecto(): array
    {
        return [
            'tolerancia_continuidad_m' => 2.5,
            'max_segmentos_por_ruta' => 500,
            'max_salto_enlace_m' => 80,
            'permitir_rutas_sin_edificio' => true,
        ];
    }

    private function configDesdeSettings(): array
    {
        $ruta = dirname(__DIR__) . '/data/settings.json';
        if (!file_exists($ruta)) {
            return [];
        }
        $contenido = file_get_contents($ruta);
        $datos = $contenido !== false ? json_decode($contenido, true) : null;
        if (!is_array($datos) || !is_array($datos['rutas'] ?? null)) {
            return [];
        }
        return $datos['rutas'];
    }
}
