<?php
declare(strict_types=1);

require_once __DIR__ . '/RouteService.php';
require_once __DIR__ . '/RouteSettingsService.php';

final class PublicMapExporter
{
    private RouteService $rutas;
    private RouteSettingsService $configuracion;
    private string $publicDataPath;

    public function __construct(
        ?RouteService $rutas = null,
        ?RouteSettingsService $configuracion = null,
        ?string $publicDataPath = null
    ) {
        $this->rutas = $rutas ?? new RouteService();
        $this->configuracion = $configuracion ?? new RouteSettingsService();
        $this->publicDataPath = rtrim($publicDataPath ?? PUBLIC_DATA_PATH, '/\\');
    }

    /**
     * Regenerar el catálogo y la configuración seguros para el mapa estático.
     *
     * @return array{rutas: int}
     */
    public function generar(): array
    {
        $documento = $this->obtenerCatalogo();
        $json = json_encode(
            $documento,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar rutas-publicas.json.');
        }

        if (!is_dir($this->publicDataPath) && !mkdir($this->publicDataPath, 0775, true) && !is_dir($this->publicDataPath)) {
            throw new RuntimeException('No se pudo preparar la carpeta de datos públicos.');
        }
        $this->escribirAtomico($this->publicDataPath . DIRECTORY_SEPARATOR . 'rutas-publicas.json', $json . "\n");
        $this->configuracion->exportarConfiguracionPublica();

        return ['rutas' => count($documento['rutas'])];
    }

    public function obtenerCatalogo(): array
    {
        $listado = $this->rutas->listar();
        $publicas = [];

        foreach ($listado['rutas'] as $ruta) {
            if (($ruta['estado'] ?? '') !== 'activa') {
                continue;
            }

            if (!empty($ruta['inconsistente']) || !empty($ruta['advertencias'])) {
                $detalles = is_array($ruta['advertencias'] ?? null) ? implode(' ', $ruta['advertencias']) : '';
                throw new RuntimeException(
                    'No se puede exportar la ruta activa «' . (string) ($ruta['nombre'] ?? $ruta['id']) . '»: '
                    . ($detalles !== '' ? $detalles : 'la geometría es inconsistente.')
                );
            }

            $origenUsuario = (($ruta['origen']['tipo'] ?? '') === 'ubicacion_usuario');
            $geometria = $ruta['geometria'] ?? null;
            $dinamica = $origenUsuario && empty($ruta['segmentos']);
            if (!$dinamica && (
                !is_array($geometria)
                || ($geometria['type'] ?? '') !== 'LineString'
                || !is_array($geometria['coordinates'] ?? null)
                || count($geometria['coordinates']) < 2
            )) {
                throw new RuntimeException(
                    'No se puede exportar la ruta activa «' . (string) ($ruta['nombre'] ?? $ruta['id'])
                    . '»: no tiene una geometría válida.'
                );
            }

            $destino = is_array($ruta['destino'] ?? null) ? $ruta['destino'] : [];
            $tipoDestino = (string) ($destino['tipo'] ?? '');
            $refDestino = (string) ($destino['ref'] ?? '');
            if (!in_array($tipoDestino, ['acceso', 'edificio', 'nodo'], true) || $refDestino === '') {
                throw new RuntimeException(
                    'No se puede exportar la ruta activa «' . (string) ($ruta['nombre'] ?? $ruta['id'])
                    . '»: el destino no es válido.'
                );
            }

            $publicas[] = [
                'id' => (string) $ruta['id'],
                'nombre' => (string) $ruta['nombre'],
                'descripcion' => (string) ($ruta['descripcion'] ?? ''),
                'tipo' => (string) ($ruta['tipo'] ?? ''),
                'destino' => [
                    'tipo' => $tipoDestino,
                    'ref' => $refDestino,
                    'nombre' => (string) ($ruta['destino_nombre'] ?? $refDestino),
                ],
                'distancia_m' => $dinamica ? null : (float) $ruta['distancia_m'],
                'origen_usuario' => $origenUsuario,
                'dinamica' => $dinamica,
                'geometria' => $dinamica ? null : $geometria,
            ];
        }

        return [
            'schema_version' => 1,
            'rutas' => $publicas,
        ];
    }

    private function escribirAtomico(string $ruta, string $contenido): void
    {
        $temporal = $ruta . '.tmp.' . bin2hex(random_bytes(8));
        $escrito = file_put_contents($temporal, $contenido, LOCK_EX);
        if ($escrito !== strlen($contenido)) {
            if (is_file($temporal)) {
                unlink($temporal);
            }
            throw new RuntimeException('No se pudo escribir la exportación pública.');
        }
        if (!rename($temporal, $ruta)) {
            unlink($temporal);
            throw new RuntimeException('No se pudo reemplazar la exportación pública.');
        }
    }
}
