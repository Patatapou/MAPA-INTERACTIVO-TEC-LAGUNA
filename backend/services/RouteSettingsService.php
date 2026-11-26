<?php
declare(strict_types=1);

final class RouteSettingsService
{
    private string $runtimePath;
    private string $publicDataPath;

    public function __construct(?string $runtimePath = null, ?string $publicDataPath = null)
    {
        $this->runtimePath = rtrim($runtimePath ?? RUNTIME_PATH, '/\\');
        $this->publicDataPath = rtrim($publicDataPath ?? PUBLIC_DATA_PATH, '/\\');
    }

    public function valoresPorDefecto(): array
    {
        return [
            'modo_ubicacion' => 'real',
            'rutas' => [
                'tolerancia_continuidad_m' => 2.5,
                'distancia_inicio_ruta_m' => 80.0,
                'max_segmentos_por_ruta' => 500,
                'max_salto_enlace_m' => 20.0,
            ],
            'ubicacion' => [
                'precision_gps_maxima_m' => 60.0,
            ],
            'estilos' => [
                'ruta_publica' => ['color' => '#1565c0', 'grosor' => 6, 'opacidad' => 0.85],
                'ruta_admin' => ['color' => '#1565c0', 'grosor' => 6, 'opacidad' => 1.0],
                'caminos' => ['color' => '#1565c0', 'grosor' => 4, 'opacidad' => 0.85],
                'caminos_publicos' => ['color' => '#1565c0', 'grosor' => 4, 'opacidad' => 0.0],
                'segmentos' => ['color' => '#1565c0', 'grosor' => 6, 'opacidad' => 1.0],
                'edificios_publicos' => [
                    'contorno' => ['color' => '#2f29d1', 'grosor' => 0, 'opacidad' => 1.0],
                    'relleno' => ['color' => '#3388ff', 'opacidad' => 0.0],
                ],
                'edificios_admin' => [
                    'contorno' => ['color' => '#000000', 'grosor' => 1, 'opacidad' => 1.0],
                    'relleno' => ['color' => '#9aa5b1', 'opacidad' => 0.9],
                ],
            ],
        ];
    }

    public function obtener(): array
    {
        $configuracion = $this->valoresPorDefecto();
        $ruta = $this->runtimePath . DIRECTORY_SEPARATOR . 'settings.json';
        $modoDefinido = false;

        if (is_file($ruta)) {
            $contenido = file_get_contents($ruta);
            $datos = $contenido !== false ? json_decode($contenido, true) : null;
            if (!is_array($datos)) {
                throw new RuntimeException('settings.json no contiene una configuración JSON válida.');
            }
            if (is_array($datos['rutas'] ?? null)) {
                $configuracion['rutas'] = array_replace($configuracion['rutas'], $datos['rutas']);
            }
            if (is_array($datos['ubicacion'] ?? null)) {
                $configuracion['ubicacion'] = array_replace($configuracion['ubicacion'], $datos['ubicacion']);
            }
            if (is_array($datos['mapa'] ?? null)) {
                if (in_array($datos['mapa']['modo_ubicacion'] ?? null, ['real', 'simulada'], true)) {
                    $configuracion['modo_ubicacion'] = $datos['mapa']['modo_ubicacion'];
                    $modoDefinido = true;
                }
                if (is_array($datos['mapa']['estilos'] ?? null)) {
                    $configuracion['estilos'] = array_replace_recursive(
                        $configuracion['estilos'],
                        $datos['mapa']['estilos']
                    );
                }
            }
        }

        if (!$modoDefinido) {
            $configuracion['modo_ubicacion'] = $this->leerModoPublicoLegado();
        }

        return $configuracion;
    }

    public function guardar(array $entrada): array
    {
        $nueva = $this->validar($entrada);
        $this->asegurarDirectorioRuntime();

        $ruta = $this->runtimePath . DIRECTORY_SEPARATOR . 'settings.json';
        $bloqueo = fopen($ruta . '.lock', 'c');
        if ($bloqueo === false) {
            throw new RuntimeException('No se pudo abrir el bloqueo de la configuración.');
        }
        if (!flock($bloqueo, LOCK_EX)) {
            fclose($bloqueo);
            throw new RuntimeException('No se pudo bloquear la configuración.');
        }

        try {
            $datos = [];
            if (is_file($ruta)) {
                $contenido = file_get_contents($ruta);
                $existente = $contenido !== false ? json_decode($contenido, true) : null;
                if (!is_array($existente)) {
                    throw new RuntimeException('settings.json no contiene una configuración JSON válida.');
                }
                $datos = $existente;
            }
            $datos['rutas'] = array_merge(is_array($datos['rutas'] ?? null) ? $datos['rutas'] : [], $nueva['rutas']);
            $datos['ubicacion'] = array_merge(
                is_array($datos['ubicacion'] ?? null) ? $datos['ubicacion'] : [],
                $nueva['ubicacion']
            );
            $mapaExistente = is_array($datos['mapa'] ?? null) ? $datos['mapa'] : [];
            $datos['mapa'] = array_merge($mapaExistente, [
                'modo_ubicacion' => $nueva['modo_ubicacion'],
                'estilos' => $nueva['estilos'],
            ]);

            $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('No se pudo serializar la configuración.');
            }
            $this->escribirAtomico($ruta, $json);
        } finally {
            flock($bloqueo, LOCK_UN);
            fclose($bloqueo);
        }

        $this->exportarConfiguracionPublica($nueva);
        return $nueva;
    }

    public function exportarConfiguracionPublica(?array $configuracion = null): void
    {
        $configuracion = $configuracion ?? $this->obtener();
        $publica = [
            'schema_version' => 1,
            'rutas' => $configuracion['rutas'],
            'ubicacion' => $configuracion['ubicacion'],
            'estilos' => $configuracion['estilos'],
        ];
        $json = json_encode(
            $publica,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar la configuración pública.');
        }

        $contenido = "window.MODO_UBICACION = '" . $configuracion['modo_ubicacion'] . "';\n"
            . 'window.CONFIG_MAPA = ' . $json . ";\n";
        $ruta = $this->publicDataPath . DIRECTORY_SEPARATOR . 'location-config.js';
        if (!is_dir($this->publicDataPath) && !mkdir($this->publicDataPath, 0775, true) && !is_dir($this->publicDataPath)) {
            throw new RuntimeException('No se pudo preparar la carpeta de datos públicos.');
        }
        $this->escribirAtomico($ruta, $contenido);
    }

    private function validar(array $entrada): array
    {
        $errores = [];
        $porDefecto = $this->valoresPorDefecto();
        $modo = $entrada['modo_ubicacion'] ?? null;
        if (!in_array($modo, ['real', 'simulada'], true)) {
            $errores[] = 'El modo de ubicación debe ser real o simulada.';
        }

        $rutasEntrada = is_array($entrada['rutas'] ?? null) ? $entrada['rutas'] : [];
        $rutas = [];
        foreach ([
            'tolerancia_continuidad_m' => [0.1, 25.0],
            'distancia_inicio_ruta_m' => [0.1, 100.0],
            'max_segmentos_por_ruta' => [1, 5000],
            'max_salto_enlace_m' => [0.0, 1000.0],
        ] as $clave => [$minimo, $maximo]) {
            $valor = $rutasEntrada[$clave] ?? null;
            if (!is_numeric($valor) || !is_finite((float) $valor) || (float) $valor < $minimo || (float) $valor > $maximo) {
                $errores[] = 'El ajuste ' . $clave . ' debe estar entre ' . $minimo . ' y ' . $maximo . '.';
                continue;
            }
            $rutas[$clave] = $clave === 'max_segmentos_por_ruta' ? (int) $valor : (float) $valor;
        }

        $ubicacionEntrada = is_array($entrada['ubicacion'] ?? null) ? $entrada['ubicacion'] : [];
        $ubicacion = [
            'precision_gps_maxima_m' => $this->validarNumero(
                $ubicacionEntrada['precision_gps_maxima_m'] ?? null,
                0.1,
                500.0,
                'ubicacion.precision_gps_maxima_m',
                $errores
            ),
        ];

        $estilosEntrada = is_array($entrada['estilos'] ?? null) ? $entrada['estilos'] : [];
        $estilos = [];
        foreach ($porDefecto['estilos'] as $grupo => $estiloDefecto) {
            $entradaGrupo = is_array($estilosEntrada[$grupo] ?? null) ? $estilosEntrada[$grupo] : [];
            if (isset($estiloDefecto['contorno'], $estiloDefecto['relleno'])) {
                $estilos[$grupo] = [
                    'contorno' => $this->validarTrazo($entradaGrupo['contorno'] ?? [], $estiloDefecto['contorno'], $grupo . '.contorno', $errores),
                    'relleno' => $this->validarRelleno($entradaGrupo['relleno'] ?? [], $estiloDefecto['relleno'], $grupo . '.relleno', $errores),
                ];
            } else {
                $estilos[$grupo] = $this->validarTrazo($entradaGrupo, $estiloDefecto, $grupo, $errores);
            }
        }

        if ($errores !== []) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        return [
            'modo_ubicacion' => $modo,
            'rutas' => $rutas,
            'ubicacion' => $ubicacion,
            'estilos' => $estilos,
        ];
    }

    private function validarTrazo(array $entrada, array $defecto, string $campo, array &$errores): array
    {
        return [
            'color' => $this->validarColor($entrada['color'] ?? null, $campo . '.color', $errores),
            'grosor' => $this->validarNumero($entrada['grosor'] ?? null, 0, 20, $campo . '.grosor', $errores),
            'opacidad' => $this->validarNumero($entrada['opacidad'] ?? null, 0, 1, $campo . '.opacidad', $errores),
        ];
    }

    private function validarRelleno(array $entrada, array $defecto, string $campo, array &$errores): array
    {
        return [
            'color' => $this->validarColor($entrada['color'] ?? null, $campo . '.color', $errores),
            'opacidad' => $this->validarNumero($entrada['opacidad'] ?? null, 0, 1, $campo . '.opacidad', $errores),
        ];
    }

    private function validarColor($valor, string $campo, array &$errores): string
    {
        if (!is_string($valor) || preg_match('/^#[a-f0-9]{6}$/i', $valor) !== 1) {
            $errores[] = 'El color de ' . $campo . ' debe usar formato #RRGGBB.';
            return '#000000';
        }
        return strtolower($valor);
    }

    private function validarNumero($valor, float $minimo, float $maximo, string $campo, array &$errores): float
    {
        if (!is_numeric($valor) || !is_finite((float) $valor) || (float) $valor < $minimo || (float) $valor > $maximo) {
            $errores[] = 'El valor de ' . $campo . ' debe estar entre ' . $minimo . ' y ' . $maximo . '.';
            return $minimo;
        }
        return (float) $valor;
    }

    private function leerModoPublicoLegado(): string
    {
        $ruta = $this->publicDataPath . DIRECTORY_SEPARATOR . 'location-config.js';
        $contenido = is_file($ruta) ? file_get_contents($ruta) : false;
        if (
            $contenido !== false
            && preg_match('/window\.MODO_UBICACION\s*=\s*[\'"](real|simulada)[\'"]/', $contenido, $coincidencia) === 1
        ) {
            return $coincidencia[1];
        }
        return 'real';
    }

    private function asegurarDirectorioRuntime(): void
    {
        if (!is_dir($this->runtimePath)) {
            throw new RuntimeException('No existe la carpeta privada de configuración.');
        }
    }

    private function escribirAtomico(string $ruta, string $contenido): void
    {
        $temporal = $ruta . '.tmp.' . bin2hex(random_bytes(8));
        $escrito = file_put_contents($temporal, $contenido, LOCK_EX);
        if ($escrito !== strlen($contenido)) {
            if (is_file($temporal)) {
                unlink($temporal);
            }
            throw new RuntimeException('No se pudo escribir la configuración temporal.');
        }
        if (!rename($temporal, $ruta)) {
            unlink($temporal);
            throw new RuntimeException('No se pudo reemplazar la configuración.');
        }
    }
}
