<?php
// ==========================================
// ALTA Y EDICIÓN DE RUTAS
// ==========================================
// Vista cargada por backend/admin/rutas.php cuando accion=nueva|editar.
// Mapa a la izquierda (construcción de la cadena de segmentos) y formulario
// de metadatos a la derecha.
//
// El servidor es la autoridad: aquí solo se recogen `[{id, sentido}]` y los
// metadatos. La geometría se reconstruye en `saveRuta`.

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../auth/auth_middleware.php';
requerirAdmin();

$modo = (isset($_GET['accion']) && $_GET['accion'] === 'editar') ? 'editar' : 'nuevo';
$idRuta = isset($_GET['id']) ? (string) $_GET['id'] : '';

if ($modo === 'editar' && preg_match('/^ruta-[a-f0-9]{8}$/', $idRuta) !== 1) {
    header('Location: index.php?p=rutas');
    exit;
}

$csrfToken = generarTokenCSRF();

// Nombres de edificios y accesos para los selectores de origen y destino.
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

$configRutas = [
    'tolerancia_continuidad_m' => 2.5,
    'max_segmentos_por_ruta' => 500,
    'max_salto_enlace_m' => 80,
];
$rutaSettings = RUNTIME_PATH . '/settings.json';
if (file_exists($rutaSettings)) {
    $contenidoSettings = file_get_contents($rutaSettings);
    $settings = $contenidoSettings !== false ? json_decode($contenidoSettings, true) : null;
    if (is_array($settings) && is_array($settings['rutas'] ?? null)) {
        $configRutas = array_merge($configRutas, $settings['rutas']);
    }
}

$titulo = $modo === 'editar' ? 'Editar ruta' : 'Nueva ruta';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $titulo ?> - ITL Laguna</title>
<link href="https://fonts.googleapis.com/css?family=Open+Sans:600" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="" />
<link rel="stylesheet" href="css/admin/panel.css">
<link rel="stylesheet" href="css/admin/rutas.css">
<link rel="icon" type="image/x-icon" href="img/logo-tecnm.png">
</head>
<body>

<div class="editor-rutas-container">
    <div class="admin-header">
        <div>
            <h1><?= $titulo ?></h1>
            <p style="margin: 4px 0 0; font-size: 12px; opacity: .85;">
                Haz clic en los segmentos de la red para encadenarlos. Empieza por cualquier segmento y
                continúa por uno contiguo.
            </p>
        </div>
        <div class="admin-actions" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="index.php?p=rutas" class="btn btn-secondary" id="btn-volver">← Volver a rutas</a>
        </div>
    </div>

    <div class="editor-rutas-layout" style="margin-top: 16px;">
        <!-- Mapa -->
        <div class="editor-mapa-wrap">
            <div id="map-ruta"></div>

            <div class="editor-toolbar">
                <button type="button" id="btn-deshacer" title="Quitar el último segmento de la cadena" disabled>↶ Deshacer</button>
                <button type="button" id="btn-invertir" title="Invertir el orden y el sentido de la cadena" disabled>⇄ Invertir</button>
                <button type="button" id="btn-limpiar" title="Vaciar la cadena" disabled>🗑️ Limpiar</button>
                <button type="button" id="btn-zoom" title="Ajustar el mapa a la cadena" disabled>🔍 Ver cadena</button>
            </div>

            <div class="mapa-leyenda">
                <span><i class="legend-swatch legend-red"></i> Red (clic para encadenar)</span>
                <span><i class="legend-swatch legend-cadena"></i> Cadena actual</span>
                <span><i class="legend-swatch legend-inicio"></i> Inicio</span>
                <span><i class="legend-swatch legend-fin"></i> Fin</span>
            </div>
        </div>

        <!-- Formulario -->
        <aside class="editor-panel" aria-label="Metadatos de la ruta">
            <h2 id="editor-titulo-form">Datos de la ruta</h2>

            <div class="cadena-resumen" id="cadena-resumen">
                <div>Segmentos: <strong id="cadena-segmentos">0</strong> ·
                    Distancia: <strong id="cadena-distancia">0 m</strong> ·
                    Puntos: <strong id="cadena-nodos">0</strong></div>
                <p class="cadena-vacia" id="cadena-vacia">
                    Todavía no hay segmentos. Haz clic en un camino del mapa para empezar.
                </p>
                <ol class="cadena-lista" id="cadena-lista"></ol>
            </div>

            <form id="ruta-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="id" id="ruta-id" value="<?= htmlspecialchars($idRuta) ?>">
                <input type="hidden" name="updated_at" id="ruta-updated-at" value="">
                <input type="hidden" name="segmentos" id="ruta-segmentos" value="[]">

                <div class="form-group">
                    <label for="ruta-nombre">Nombre de la ruta *</label>
                    <input type="text" id="ruta-nombre" name="nombre" maxlength="80" required
                           placeholder="Ej: Acceso norte al Edificio 5">
                    <div class="form-error" id="error-nombre"></div>
                </div>

                <div class="form-group">
                    <label for="ruta-descripcion">Descripción</label>
                    <textarea id="ruta-descripcion" name="descripcion" maxlength="500" rows="2"
                              placeholder="Opcional (máximo 500 caracteres)"></textarea>
                </div>

                <div class="rutas-filtros-fila">
                    <div class="form-group">
                        <label for="ruta-tipo">Tipo *</label>
                        <select id="ruta-tipo" name="tipo">
                            <option value="principal">Principal</option>
                            <option value="alterna">Alterna</option>
                            <option value="acceso">Acceso</option>
                            <option value="emergencia">Emergencia</option>
                            <option value="institucional">Institucional</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ruta-estado">Estado *</label>
                        <select id="ruta-estado" name="estado">
                            <option value="borrador">Borrador</option>
                            <option value="activa">Activa</option>
                            <option value="inactiva">Inactiva</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="rutas-check" for="ruta-accesible">
                        <input type="checkbox" id="ruta-accesible" name="accesible" value="1" checked>
                        Ruta accesible
                    </label>
                </div>

                <div class="form-group">
                    <label for="origen-tipo">Origen *</label>
                    <div class="rutas-filtros-fila">
                        <select id="origen-tipo" name="origen_tipo">
                            <option value="edificio">Edificio</option>
                            <option value="acceso">Acceso</option>
                            <option value="nodo">Nodo de la red</option>
                        </select>
                        <select id="origen-ref" name="origen_ref"></select>
                    </div>
                    <input type="text" id="origen-nodo" placeholder="lat,lng del nodo" hidden>
                    <div class="form-error" id="error-origen"></div>
                </div>

                <div class="form-group">
                    <label for="destino-tipo">Destino *</label>
                    <div class="rutas-filtros-fila">
                        <select id="destino-tipo" name="destino_tipo">
                            <option value="edificio">Edificio</option>
                            <option value="acceso">Acceso</option>
                            <option value="nodo">Nodo de la red</option>
                        </select>
                        <select id="destino-ref" name="destino_ref"></select>
                    </div>
                    <input type="text" id="destino-nodo" placeholder="lat,lng del nodo" hidden>
                    <div class="form-error" id="error-destino"></div>
                </div>

                <div class="editor-acciones">
                    <button type="submit" class="btn btn-primary" id="btn-guardar" disabled>💾 Guardar ruta</button>
                    <button type="button" class="btn btn-secondary" id="btn-cancelar">Cancelar</button>
                </div>

                <div class="editor-mensaje" id="editor-mensaje" role="status"></div>
            </form>
        </aside>
    </div>
</div>

<script>
    window.RUTA_EDITOR = <?= json_encode([
        'modo' => $modo,
        'id' => $idRuta,
        'csrfToken' => $csrfToken,
        'datos' => $datosEdificios,
        'config' => $configRutas,
        'urls' => [
            'getRutas' => 'index.php?p=getRutas',
            'getRedCaminos' => 'index.php?p=getRedCaminos',
            'saveRuta' => 'index.php?p=saveRuta',
            'listado' => 'index.php?p=rutas',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin=""></script>
<script src="data/GeoTec.js"></script>
<script src="js/admin/ruta-editor.js"></script>

</body>
</html>
