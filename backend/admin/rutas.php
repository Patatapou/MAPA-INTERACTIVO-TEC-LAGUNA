<?php
// ==========================================
// GESTIÓN DE RUTAS — VISTA PRINCIPAL
// ==========================================
// Listado, filtros, detalle y eliminación de rutas administrativas.
// Las acciones de alta y edición se delegan en backend/admin/editar-ruta.php.
//
// Esta vista no modifica nada: solo consulta `getRutas` y `getRedCaminos`.
// El borrado se hace con `deleteRuta` (POST + CSRF) desde el modal.

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../auth/auth_middleware.php';
requerirAdmin();

$csrfToken = generarTokenCSRF();
$accion = isset($_GET['accion']) ? (string) $_GET['accion'] : '';

// Alta y edición se resuelven en su propia vista.
if ($accion === 'nueva' || $accion === 'editar') {
    require __DIR__ . '/editar-ruta.php';
    exit;
}

// Selección inicial opcional: index.php?p=rutas&accion=ver&id=ruta-xxxxxxxx
$idInicial = '';
if ($accion === 'ver' && isset($_GET['id']) && preg_match('/^ruta-[a-f0-9]{8}$/', (string) $_GET['id']) === 1) {
    $idInicial = (string) $_GET['id'];
}

// Nombres de edificios y accesos para el listado y el detalle.
// Si datos.json falta o está corrupto, la vista sigue funcionando sin nombres.
$datosEdificios = [];
$rutaDatos = PUBLIC_DATA_PATH . '/datos.json';
if (file_exists($rutaDatos)) {
    $contenidoDatos = file_get_contents($rutaDatos);
    $decodificado = $contenidoDatos !== false ? json_decode($contenidoDatos, true) : null;
    if (is_array($decodificado)) {
        foreach ($decodificado as $id => $registro) {
            $datosEdificios[(string) $id] = [
                'nombre' => (string) ($registro['nombre'] ?? $id),
                'tipo' => (string) ($registro['tipo'] ?? 'edificio'),
            ];
        }
    }
}

// Configuración del módulo (tolerancias) expuesta solo como dato de lectura.
$configRutas = [
    'tolerancia_continuidad_m' => 2.5,
    'max_segmentos_por_ruta' => 500,
    'max_salto_enlace_m' => 80,
];
$modoUbicacion = 'simulada';
$rutaModoUbicacion = PUBLIC_DATA_PATH . '/location-config.js';
if (file_exists($rutaModoUbicacion)) {
    $contenidoModoUbicacion = file_get_contents($rutaModoUbicacion);
    if (
        $contenidoModoUbicacion !== false
        && preg_match(
            '/\Awindow\.MODO_UBICACION\s*=\s*[\'"](real|simulada)[\'"];\s*\z/',
            trim($contenidoModoUbicacion),
            $coincidenciaModo
        ) === 1
    ) {
        $modoUbicacion = $coincidenciaModo[1];
    }
}
$rutaSettings = RUNTIME_PATH . '/settings.json';
if (file_exists($rutaSettings)) {
    $contenidoSettings = file_get_contents($rutaSettings);
    $settings = $contenidoSettings !== false ? json_decode($contenidoSettings, true) : null;
    if (is_array($settings)) {
        if (is_array($settings['rutas'] ?? null)) {
            $configRutas = array_merge($configRutas, $settings['rutas']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rutas del campus - ITL Laguna</title>
<link href="https://fonts.googleapis.com/css?family=Open+Sans:600" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="" />
<link rel="stylesheet" href="css/admin/panel.css">
<link rel="stylesheet" href="css/admin/rutas.css">
<link rel="icon" type="image/x-icon" href="img/logo-tecnm.png">
</head>
<body>

<div class="rutas-container">
    <div class="admin-header">
        <div>
            <h1>Rutas del campus</h1>
            <p style="margin: 4px 0 0; font-size: 12px; opacity: .85;">
                Gestiona rutas y el modo de ubicación.
            </p>
        </div>
        <div class="admin-actions" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <div class="modo-ubicacion-control">
                <label for="modo-ubicacion">Ubicación: </label>
                <select id="modo-ubicacion">
                    <option value="simulada" <?= $modoUbicacion === 'simulada' ? 'selected' : '' ?>>Simulada (beacons)</option>
                    <option value="real" <?= $modoUbicacion === 'real' ? 'selected' : '' ?>>Real (GPS)</option>
                </select>
                <button type="button" class="btn btn-primary" id="guardar-modo-ubicacion">Guardar</button>
                <span class="modo-ubicacion-estado" id="modo-ubicacion-estado" role="status" aria-live="polite">
                    
                </span>
            </div>
            <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php?p=editar'">← Panel</button>
            <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php?p=editar-red'">🗺️ Red de caminos</button>
            <a href="index.php?p=rutas&accion=nueva" class="btn btn-primary">➕ Nueva ruta</a>
            <form action="index.php?p=logout" method="post" style="display: inline; margin: 0;">
                <button type="submit" class="btn btn-danger">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="rutas-layout">
        <!-- Panel lateral: buscador, filtros y listado -->
        <aside class="rutas-sidebar" aria-label="Listado de rutas">
            <div class="rutas-sidebar-header">
                <h2>📍 Rutas</h2>
                <span class="rutas-contador" id="rutas-contador">Cargando…</span>
            </div>

            <div class="rutas-filtros">
                <label for="buscar-rutas">Buscar ruta</label>
                <input type="search" id="buscar-rutas" placeholder="Nombre, origen, destino, tipo…" autocomplete="off">

                <div class="rutas-filtros-fila">
                    <div>
                        <label for="filtro-tipo">Tipo</label>
                        <select id="filtro-tipo">
                            <option value="">Todos</option>
                            <option value="principal">Principal</option>
                            <option value="alterna">Alterna</option>
                            <option value="acceso">Acceso</option>
                            <option value="emergencia">Emergencia</option>
                            <option value="institucional">Institucional</option>
                        </select>
                    </div>
                    <div>
                        <label for="filtro-estado">Estado</label>
                        <select id="filtro-estado">
                            <option value="">Todos</option>
                            <option value="activa">Activa</option>
                            <option value="inactiva">Inactiva</option>
                            <option value="borrador">Borrador</option>
                        </select>
                    </div>
                </div>

                <label class="rutas-check" for="filtro-accesible">
                    <input type="checkbox" id="filtro-accesible"> Solo accesibles
                </label>
            </div>

            <div class="rutas-estado" id="rutas-cargando">Cargando rutas…</div>
            <div class="rutas-estado error oculto" id="rutas-error"></div>
            <div class="rutas-estado oculto" id="rutas-vacio">
                Todavía no hay rutas. Usa “➕ Nueva ruta” para crear la primera.
            </div>
            <div class="rutas-estado oculto" id="rutas-sin-resultados">
                No se encontraron rutas que coincidan con los filtros.
            </div>
            <ul class="rutas-lista" id="rutas-lista"></ul>
        </aside>

        <!-- Mapa -->
        <section class="rutas-mapa" aria-label="Mapa de rutas">
            <div id="map-rutas"></div>

            <div class="mapa-leyenda">
                <span><i class="legend-swatch legend-red"></i> Red de caminos</span>
                <span><i class="legend-swatch legend-ruta"></i> Ruta seleccionada</span>
                <span><i class="legend-swatch legend-inicio"></i> Inicio</span>
                <span><i class="legend-swatch legend-fin"></i> Fin</span>
            </div>

            <aside class="detalle-panel" id="detalle-panel" aria-live="polite">
                <button type="button" class="detalle-cerrar" id="detalle-cerrar" title="Cerrar detalle">×</button>
                <h3 id="detalle-nombre"></h3>
                <p class="detalle-trayecto" id="detalle-trayecto"></p>
                <dl class="detalle-datos" id="detalle-datos"></dl>
                <div class="detalle-descripcion" id="detalle-descripcion" hidden></div>
                <ul class="detalle-avisos" id="detalle-avisos" hidden></ul>
                <h4 style="font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin: 0 0 6px;">
                    Segmentos
                </h4>
                <ol class="detalle-segmentos" id="detalle-segmentos"></ol>
                <div class="detalle-acciones">
                    <a href="#" class="btn btn-primary" id="detalle-editar">Editar</a>
                    <button type="button" class="btn btn-danger" id="detalle-eliminar">Eliminar</button>
                </div>
            </aside>
        </section>
    </div>
</div>

<!-- Modal de confirmación de borrado -->
<div class="modal-fondo" id="modal-eliminar" role="dialog" aria-modal="true" aria-labelledby="modal-eliminar-titulo">
    <div class="modal-caja">
        <h3 id="modal-eliminar-titulo">Eliminar ruta</h3>
        <p id="modal-eliminar-pregunta"></p>
        <p class="modal-aviso">
            Se eliminará únicamente la definición de la ruta. Los caminos de la red y
            otras rutas que los compartan <strong>no</strong> se modificarán.
        </p>
        <div class="modal-error" id="modal-eliminar-error"></div>
        <div class="modal-acciones">
            <button type="button" class="btn btn-secondary" id="modal-cancelar">Cancelar</button>
            <button type="button" class="btn btn-danger" id="modal-confirmar">Eliminar ruta</button>
        </div>
    </div>
</div>

<script>
    window.RUTAS_ADMIN = <?= json_encode([
        'csrfToken' => $csrfToken,
        'idInicial' => $idInicial,
        'datos' => $datosEdificios,
        'config' => $configRutas,
        'locationMode' => $modoUbicacion,
        'tipos' => ['principal', 'alterna', 'acceso', 'emergencia', 'institucional'],
        'estados' => ['activa', 'inactiva', 'borrador'],
        'urls' => [
            'getRutas' => 'index.php?p=getRutas',
            'deleteRuta' => 'index.php?p=deleteRuta',
            'getRedCaminos' => 'index.php?p=getRedCaminos',
            'locationMode' => 'index.php?p=get-location-mode',
            'editor' => 'index.php?p=rutas&accion=editar&id=',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin=""></script>
<!-- Geometrías de edificios como referencia visual (global datosTec) -->
<script src="data/GeoTec.js"></script>
<script src="js/admin/rutas-admin.js"></script>

</body>
</html>
