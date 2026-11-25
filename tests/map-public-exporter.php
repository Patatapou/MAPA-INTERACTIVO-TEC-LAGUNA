<?php
declare(strict_types=1);

require_once __DIR__ . '/_pruebas.php';
require_once __DIR__ . '/../backend/services/PublicMapExporter.php';

final class RouteServiceExportFixture extends RouteService
{
    private array $documento;

    public function __construct(array $documento)
    {
        $this->documento = $documento;
    }

    public function listar(): array
    {
        return $this->documento;
    }
}

$base = crearDirTemporal('map-public-exporter');
$runtime = $base . DIRECTORY_SEPARATOR . 'runtime';
$publicData = $base . DIRECTORY_SEPARATOR . 'public';
mkdir($runtime, 0775, true);
mkdir($publicData, 0775, true);

try {
    file_put_contents($publicData . DIRECTORY_SEPARATOR . 'location-config.js', "window.MODO_UBICACION = 'simulada';\n");
    $settings = new RouteSettingsService($runtime, $publicData);
    afirmarIgual('Modo legado se lee si settings no define modo', 'simulada', $settings->obtener()['modo_ubicacion']);

    $defaults = $settings->valoresPorDefecto();
    $saved = $settings->guardar($defaults);
    $configJs = (string) file_get_contents($publicData . DIRECTORY_SEPARATOR . 'location-config.js');
    afirmar('Se exporta configuración visual pública', strpos($configJs, 'window.CONFIG_MAPA =') !== false);
    afirmar('La configuración pública no contiene datos administrativos', strpos($configJs, 'audit') === false);
    preg_match('/window\.CONFIG_MAPA = (\{.*\});/', $configJs, $configCoincidencia);
    $configPublica = isset($configCoincidencia[1]) ? json_decode($configCoincidencia[1], true) : null;
    afirmarIgual('La red de caminos pública queda independiente y oculta por defecto', 0, $configPublica['estilos']['caminos_publicos']['opacidad'] ?? null);
    afirmarIgual('La red administrativa conserva su estilo visible', 0.85, $configPublica['estilos']['caminos']['opacidad'] ?? null);
    afirmarIgual('Se persiste el modo elegido', 'real', $saved['modo_ubicacion']);

    $invalid = $defaults;
    $invalid['rutas']['distancia_inicio_ruta_m'] = 1000;
    $rechazada = false;
    try {
        $settings->guardar($invalid);
    } catch (InvalidArgumentException $e) {
        $rechazada = true;
    }
    afirmar('Se rechazan tolerancias fuera de rango', $rechazada);

    $rutaActiva = [
        'id' => 'ruta-1234abcd',
        'nombre' => 'Ruta de prueba',
        'descripcion' => 'Recorrido público',
        'tipo' => 'principal',
        'estado' => 'activa',
        'origen' => ['tipo' => 'edificio', 'ref' => '5'],
        'destino' => ['tipo' => 'acceso', 'ref' => 'P1'],
        'destino_nombre' => 'Puerta norte',
        'segmentos' => [['id' => 'seg-abcdef12', 'sentido' => 'normal']],
        'distancia_m' => 42.5,
        'geometria' => ['type' => 'LineString', 'coordinates' => [[-103.4, 25.5], [-103.401, 25.501]]],
        'inconsistente' => false,
        'advertencias' => [],
        'creado_por' => 'admin_privado',
        'actualizado_por' => 'admin_privado',
    ];
    $rutaDinamica = [
        'id' => 'ruta-5678abcd',
        'nombre' => 'Ruta dinámica',
        'descripcion' => '',
        'tipo' => 'acceso',
        'estado' => 'activa',
        'origen' => ['tipo' => 'ubicacion_usuario', 'ref' => ''],
        'destino' => ['tipo' => 'edificio', 'ref' => '5'],
        'destino_nombre' => 'Edificio 5',
        'segmentos' => [],
        'distancia_m' => 0,
        'geometria' => null,
        'inconsistente' => false,
        'advertencias' => [],
    ];
    $rutaInactiva = array_merge($rutaActiva, [
        'id' => 'ruta-aabbccdd',
        'nombre' => 'Ruta inactiva',
        'estado' => 'inactiva',
    ]);

    $servicioRutas = new RouteServiceExportFixture([
        'rutas' => [$rutaActiva, $rutaDinamica, $rutaInactiva],
    ]);
    $exportador = new PublicMapExporter($servicioRutas, $settings, $publicData);
    $catalogoEnVivo = $exportador->obtenerCatalogo();
    afirmarIgual('El catálogo en vivo incluye solo rutas activas', 2, count($catalogoEnVivo['rutas']));
    afirmar('El catálogo en vivo excluye datos de auditoría', strpos(json_encode($catalogoEnVivo), 'admin_privado') === false);
    $resultado = $exportador->generar();
    $catalogo = json_decode((string) file_get_contents($publicData . DIRECTORY_SEPARATOR . 'rutas-publicas.json'), true);

    afirmarIgual('Se exportan únicamente rutas activas', 2, $resultado['rutas']);
    afirmarIgual('El catálogo conserva una geometría normal', 'LineString', $catalogo['rutas'][0]['geometria']['type']);
    afirmar('El catálogo admite origen dinámico sin geometría', $catalogo['rutas'][1]['dinamica'] === true && $catalogo['rutas'][1]['geometria'] === null);
    $jsonCatalogo = (string) file_get_contents($publicData . DIRECTORY_SEPARATOR . 'rutas-publicas.json');
    afirmar('Se excluyen campos privados y de auditoría', strpos($jsonCatalogo, 'admin_privado') === false && strpos($jsonCatalogo, 'creado_por') === false);

    $rutaInconsistente = array_merge($rutaActiva, ['advertencias' => ['Cadena discontinua']]);
    $exportadorInvalido = new PublicMapExporter(
        new RouteServiceExportFixture(['rutas' => [$rutaInconsistente]]),
        $settings,
        $publicData
    );
    $rechazoGeometria = false;
    try {
        $exportadorInvalido->generar();
    } catch (RuntimeException $e) {
        $rechazoGeometria = strpos($e->getMessage(), 'Cadena discontinua') !== false;
    }
    afirmar('No se exportan rutas activas inconsistentes', $rechazoGeometria);
} finally {
    borrarDir($base);
}

salirConResumen('map-public-exporter');
