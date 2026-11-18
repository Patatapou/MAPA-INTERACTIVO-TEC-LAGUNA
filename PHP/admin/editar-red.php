<?php
// ==========================================
// EDITOR DE LA RED DE CAMINOS
// ==========================================
// Página completa con Leaflet + Leaflet-Geoman. Permite consultar, dibujar,
// editar y eliminar segmentos de `caminitos.geojson` (formato v2) sin editar
// el archivo a mano.
//
// La vista solo pinta; toda la validación de guardado ocurre en
// `save-red.php` / `NetworkService` (E01–E08, W01–W06).

require_once __DIR__ . '/../auth/auth_middleware.php';
require_once __DIR__ . '/../services/NetworkService.php';

requerirAdmin();

$csrfToken = generarTokenCSRF();

$errorCarga = null;
$red = null;
$pares = [];
$metadatos = [];

try {
    $servicio = new NetworkService();
    $estado = $servicio->estado();
    $red = $estado['geojson'];
    try {
        $pares = $servicio->paresReferencia();
    } catch (Throwable $e) {
        error_log('editar-red.php: no se pudieron calcular los pares de referencia: ' . $e->getMessage());
        $pares = [];
    }
} catch (Throwable $e) {
    error_log('editar-red.php: ' . $e->getMessage());
    $errorCarga = $e->getMessage();
}

$rutaDatos = dirname(__DIR__, 2) . '/datos.json';
if (file_exists($rutaDatos)) {
    $contenidoDatos = file_get_contents($rutaDatos);
    $decodificado = $contenidoDatos !== false ? json_decode($contenidoDatos, true) : null;
    if (is_array($decodificado)) {
        foreach ($decodificado as $id => $registro) {
            $metadatos[(string) $id] = [
                'nombre' => (string) ($registro['nombre'] ?? $id),
                'tipo' => (string) ($registro['tipo'] ?? 'edificio'),
            ];
        }
    }
}

$revision = is_array($red) ? (int) ($red['revision'] ?? 0) : 0;
$totalSegmentos = is_array($red) ? count($red['features'] ?? []) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Red de caminos - ITL Laguna</title>
<link href="https://fonts.googleapis.com/css?family=Open+Sans:600" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="">
<link rel="stylesheet" href="css/vendor/leaflet-geoman/leaflet-geoman.css">
<link rel="stylesheet" href="css/panel.css">
<link rel="stylesheet" href="css/red-editor.css">
<link rel="icon" type="image/x-icon" href="img/logo-tecnm.png">
</head>
<body class="red-body">

<?php if ($errorCarga !== null): ?>
<div class="red-container">
    <div class="admin-header red-header">
        <div>
            <h1>Red de caminos</h1>
            <p class="red-subtitle">No se pudo abrir el editor.</p>
        </div>
        <div class="red-header-actions">
            <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php?p=rutas'">← Volver a rutas</button>
        </div>
    </div>
    <div class="red-error-carga">
        <h2>La red no se puede leer</h2>
        <p><?= htmlspecialchars($errorCarga) ?></p>
        <p>Si el archivo está en formato antiguo, ejecuta <code>php scripts/migrar_red_v2.php --apply</code>.
           Si está corrupto, restaura el último respaldo de <code>PHP/data/backups/caminitos/</code>.</p>
    </div>
</div>
<?php else: ?>

<div class="red-container">
    <header class="admin-header red-header">
        <div>
            <h1>Red de caminos</h1>
            <p class="red-subtitle">
                Consulta, dibuja y corrige los segmentos. Los edificios y accesos son solo referencia:
                no se pueden mover ni editar aquí.
            </p>
        </div>
        <div class="red-header-actions">
            <span class="red-revision" title="Revisión persistida de la red">
                rev. <strong id="red-revision"><?= $revision ?></strong>
                · <span id="red-contador"><?= $totalSegmentos ?></span> segmentos
            </span>
            <button type="button" id="btn-descartar" class="btn btn-secondary">Descartar</button>
            <button type="button" id="btn-guardar" class="btn btn-primary">💾 Guardar cambios</button>
            <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php?p=rutas'">← Volver a rutas</button>
        </div>
    </header>

    <div class="red-layout">
        <aside class="red-sidebar" aria-label="Segmentos y validación">
            <section class="red-sidebar-block red-sidebar-segmentos" id="bloque-segmentos">
                <div class="red-sidebar-head">
                    <h2>Segmentos</h2>
                    <span class="red-badge-contador" id="red-total-lista"><?= $totalSegmentos ?></span>
                </div>
                <label class="red-buscar-label" for="red-buscar">Buscar por ID:</label>
                <input type="search" id="red-buscar" class="red-buscar" placeholder="seg-000001…" autocomplete="off">
                <p class="red-ayuda">Clic en un segmento de la lista o del mapa para seleccionarlo.</p>
                <ul id="red-lista" class="red-lista"></ul>
                <p id="red-lista-vacia" class="red-vacio" hidden>No hay segmentos que coincidan.</p>
            </section>

            <div id="red-splitter" class="red-splitter" role="separator" tabindex="0"
                 aria-orientation="horizontal" aria-label="Ajustar la altura de Segmentos y Validación"
                 aria-controls="bloque-segmentos bloque-validacion"
                 title="Arrastra para repartir el alto (↑/↓ con el teclado)"></div>

            <section class="red-sidebar-block red-validacion" id="bloque-validacion">
                <div class="red-sidebar-head">
                    <h2>Validación</h2>
                    <span class="red-badge-contador" id="red-total-avisos">0</span>
                </div>
                <div class="red-validacion-cuerpo">
                    <div id="red-resumen-validacion" class="red-resumen">Analizando la red…</div>
                    <div id="red-errores" class="red-avisos red-avisos-error"></div>
                    <div id="red-advertencias" class="red-avisos red-avisos-warning"></div>
                    <p class="red-ayuda">Los errores bloquean el guardado. Las advertencias son decisiones tuyas.</p>
                </div>
            </section>
        </aside>

        <div class="red-mapa-wrap">
            <div id="map-red"></div>

            <div class="red-toolbar" role="toolbar" aria-label="Modo de edición">
                <button type="button" class="red-mode active" data-modo="consultar" title="Seleccionar y consultar segmentos">🔎 Consultar</button>
                <button type="button" class="red-mode" data-modo="dibujar" title="Dibujar una polilínea nueva (otro clic cancela sin guardar)">✏️ Dibujar</button>
                <button type="button" id="btn-terminar-dibujo" class="red-fin-dibujo" hidden
                        title="Añadir el segmento dibujado a la propuesta (también Enter o doble clic)">✔️ Terminar dibujo</button>
                <button type="button" class="red-mode" data-modo="editar" title="Mover, añadir o quitar vértices del segmento seleccionado">🖐️ Editar</button>
                <button type="button" class="red-mode" data-modo="eliminar" title="Marcar o desmarcar segmentos para eliminar">🗑️ Eliminar</button>
            </div>

            <div class="red-leyenda" aria-label="Leyenda de la red">
                <span><i class="red-swatch red-swatch-existente"></i> Existente</span>
                <span><i class="red-swatch red-swatch-nuevo"></i> Nuevo</span>
                <span><i class="red-swatch red-swatch-modificado"></i> Modificado</span>
                <span><i class="red-swatch red-swatch-seleccionado"></i> Seleccionado</span>
                <span><i class="red-swatch red-swatch-eliminado"></i> Para eliminar</span>
                <span><i class="red-swatch red-swatch-extremo"></i> Extremo libre</span>
            </div>

            <div id="red-estado" class="red-estado">Consultando la red…</div>
            <div id="red-detalle" class="red-detalle" hidden></div>
            <div id="red-mensaje" class="red-mensaje" hidden></div>
        </div>
    </div>
</div>

<script>
    window.RED_INICIAL = <?= json_encode($red, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.RED_REVISION = <?= $revision ?>;
    window.RED_CSRF = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.RED_PAIRS = <?= json_encode($pares, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.RED_METADATOS = <?= json_encode($metadatos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.RED_URL_GUARDAR = 'index.php?p=save-red';
    window.RED_URL_ESTADO = 'index.php?p=getRed';
    window.RED_URL_PANEL = 'index.php?p=rutas';
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin=""></script>
<script src="js/vendor/leaflet-geoman/leaflet-geoman.min.js"></script>
<script src="GeoTec.js"></script>
<script src="js/geo-utils.js"></script>
<script src="js/pathfinding.js"></script>
<script src="js/network-analysis.js"></script>
<script src="js/network-editor.js"></script>
<?php endif; ?>

</body>
</html>
