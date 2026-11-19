<?php
// ==========================================
// EDITOR DE GEOMETRÍAS DE EDIFICIOS
// ==========================================
// Permite dibujar y editar polígonos de edificios sobre el mapa.
// Usa Leaflet 1.9.4 + Leaflet-Geoman (vendorizado en js/vendor/, sin CDN).
// Requiere autenticación de administrador.

require_once __DIR__ . '/../auth/auth_middleware.php';
require_once __DIR__ . '/../repositories/BuildingGeometryRepository.php';
requerirAdmin();

$accion = $_GET['accion'] ?? 'nuevo'; // 'nuevo' o 'editar'
$edificioId = $_GET['id'] ?? '';
$edificioNombre = '';
$edificioDescripcion = '';
$edificioAulas = [];
$edificioTipo = 'edificio';
$edificioGeometria = null;

// Si es edición, cargar datos del edificio
if ($accion === 'editar' && !empty($edificioId)) {
    require_once __DIR__ . '/../services/BuildingService.php';
    $buildingService = new BuildingService();
    $edificio = $buildingService->obtenerEdificio($edificioId);
    if ($edificio !== null) {
        $edificioNombre = $edificio['nombre'];
        $edificioDescripcion = $edificio['descripcion'];
        $edificioAulas = $edificio['aulas'];
        $edificioTipo = $edificio['tipo'] ?? 'edificio';
        $edificioGeometria = $edificio['geometry'];
    }
}

// Geometrías existentes: capa de referencia de solo lectura.
// Si GeoTec.js está corrupto, no se rompe el editor: se registra el error y se
// continúa sin referencia (nunca se devuelve una colección vacía que se vaya a
// guardar encima).
$geometriasExistentes = ['type' => 'FeatureCollection', 'features' => []];
try {
    $geometryRepo = new BuildingGeometryRepository();
    $leidas = $geometryRepo->leerGeometrias();
    if (isset($leidas['features']) && is_array($leidas['features'])) {
        $geometriasExistentes = $leidas;
    }
} catch (Throwable $e) {
    error_log('editar-geometria.php: no se pudieron cargar las geometrías de referencia: ' . $e->getMessage());
}

$csrfToken = generarTokenCSRF();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $accion === 'nuevo' ? 'Agregar edificio' : 'Editar edificio' ?> - Mapa ITL</title>
<!-- Leaflet 1.9.4 -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="" />
<!-- Leaflet-Geoman vendorizado localmente (no depende de CDN) -->
<link rel="stylesheet" href="css/vendor/leaflet-geoman/leaflet-geoman.css">
<link rel="stylesheet" href="css/editor.css">
<link rel="icon" type="image/x-icon" href="img/logo-tecnm.png">
</head>
<body>

<div class="editor-container">
    <header class="editor-header">
        <h1><?= $accion === 'nuevo' ? 'Agregar edificio' : 'Editar edificio' ?></h1>
        <div class="editor-actions">
            <button type="button" class="btn btn-secondary" id="btn-volver">← Volver al panel</button>
            <button type="button" class="btn btn-danger" id="btn-limpiar">Limpiar dibujo</button>
        </div>
        <div id="editor-status" class="editor-status">Modo dibujo listo.</div>
    </header>

    <div class="editor-body">
        <!-- Mapa -->
        <div class="map-wrapper">
            <div id="map-editor"></div>
            <!-- Toolbar de dibujo -->
            <div class="draw-toolbar" id="draw-toolbar">
                <button type="button" class="draw-btn draw-polygon" id="btn-draw-polygon" title="Dibujar polígono (clic para vértices, doble clic para terminar)">🟥 Dibujar</button>
                <button type="button" class="draw-btn draw-edit" id="btn-edit-mode" title="Editar vértices del polígono">✏️ Editar</button>
                <button type="button" class="draw-btn draw-delete" id="btn-delete-mode" title="Eliminar polígono actual">🗑️ Eliminar</button>
            </div>
            <!-- Leyenda de capas -->
            <div class="map-legend">
                <span><i class="legend-swatch legend-reference"></i> Geometrías existentes (referencia, no editables)</span>
                <span><i class="legend-swatch legend-editable"></i> Geometría en edición</span>
            </div>
        </div>

        <!-- Formulario -->
        <div class="form-wrapper">
            <form id="edificio-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="es_nuevo" value="<?= $accion === 'nuevo' ? '1' : '0' ?>">
                <input type="hidden" name="geometry" id="geometry-input">

                <div class="form-group">
                    <label for="id">ID del edificio:</label>
                    <input type="text" id="id" name="id" value="<?= htmlspecialchars($edificioId) ?>" required
                           <?= $accion === 'editar' ? 'readonly' : '' ?>
                           pattern="[A-Za-z0-9_-]+"
                           placeholder="Ej: 5, 1A, P1">
                    <?php if ($accion === 'editar'): ?>
                        <small class="form-help">El ID no se puede cambiar desde el editor. Elimina el registro y créalo de nuevo si necesitas otro ID.</small>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="nombre">Nombre del edificio:</label>
                    <input type="text" id="nombre" name="nombre" value="<?= htmlspecialchars($edificioNombre) ?>" required
                           placeholder="Ej: Edificio 5">
                </div>

                <div class="form-group">
                    <label for="tipo">Tipo de registro:</label>
                    <select id="tipo" name="tipo">
                        <option value="edificio" <?= $edificioTipo === 'edificio' ? 'selected' : '' ?>>Edificio</option>
                        <option value="acceso" <?= $edificioTipo === 'acceso' ? 'selected' : '' ?>>Acceso / puerta</option>
                    </select>
                    <small class="form-help">Los accesos (P1–P6) son puertas del campus y se muestran con su popup propio en el mapa.</small>
                </div>

                <div class="form-group">
                    <label for="descripcion">Descripción:</label>
                    <textarea id="descripcion" name="descripcion" rows="3"><?= htmlspecialchars($edificioDescripcion) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="aulas">Aulas (una por línea):</label>
                    <textarea id="aulas" name="aulas" rows="4"><?= htmlspecialchars(implode("\n", $edificioAulas)) ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="btn-cancelar">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btn-guardar">💾 Guardar edificio</button>
                </div>
            </form>
            <div id="form-message" class="form-message"></div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin=""></script>
<script src="js/vendor/leaflet-geoman/leaflet-geoman.min.js"></script>
<script>
    // Geometría del edificio que se está editando (null al crear)
    window.EDITOR_GEOMETRIA_INICIAL = <?= json_encode($edificioGeometria, JSON_UNESCAPED_UNICODE) ?>;
    // ID actual: se excluye de la capa de referencia para no dibujarlo dos veces
    window.EDITOR_EDIFICIO_ID = <?= json_encode($edificioId, JSON_UNESCAPED_UNICODE) ?>;
    // Todas las geometrías existentes (solo lectura, como referencia visual)
    window.EDITOR_GEOMETRIAS_EXISTENTES = <?= json_encode($geometriasExistentes, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="js/editor.js"></script>

</body>
</html>
