<?php
// ==========================================
// ENDPOINT: GUARDAR LA RED DE CAMINOS
// ==========================================
// POST index.php?p=save-red
//
// Entrada (FormData o JSON):
//   csrf_token     token de sesión
//   base_revision  revisión sobre la que se editó
//   geojson        FeatureCollection completa propuesta
//
// Respuestas:
//   200 {success, revision, id_map, warnings, cambios}
//   400 {success:false, errors[]}   (E01–E05, E07, E08)
//   403 sesión/CSRF
//   409 {success:false, revision}   (E06, revisión obsoleta)
//   500 error interno
//
// El servidor es la autoridad: valida estructura, topología, alcanzabilidad,
// asigna IDs definitivos, respalda y audita. El navegador no decide nada.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

/**
 * @param array $payload
 */
function redResponder(array $payload, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    require_once __DIR__ . '/../bootstrap.php';
    require_once BACKEND_PATH . '/auth/auth_middleware.php';
    require_once BACKEND_PATH . '/services/NetworkService.php';

    requerirAdmin();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        redResponder(['success' => false, 'message' => 'Método no permitido. Use POST.'], 405);
    }

    // Límite de tamaño antes de procesar nada.
    $largoPeticion = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($largoPeticion > NetworkService::MAX_BYTES + 65536) {
        redResponder([
            'success' => false,
            'message' => 'La petición supera el límite de 2 MB.',
            'errors' => [[
                'codigo' => 'E07',
                'mensaje' => 'La petición supera el límite de 2 MB.',
            ]],
        ], 400);
    }

    $tipoContenido = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $esJson = str_contains($tipoContenido, 'application/json');

    if ($esJson) {
        $cuerpo = file_get_contents('php://input');
        if ($cuerpo === false) {
            redResponder(['success' => false, 'message' => 'No se pudo leer el cuerpo de la petición.'], 400);
        }
        $datos = json_decode($cuerpo, true);
        if (!is_array($datos)) {
            redResponder(['success' => false, 'message' => 'El cuerpo JSON no es válido.'], 400);
        }
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($datos['csrf_token'] ?? ''));
        $baseRevision = $datos['base_revision'] ?? null;
        $geojsonBruto = $datos['geojson'] ?? null;
    } else {
        $token = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        $baseRevision = $_POST['base_revision'] ?? null;
        $geojsonBruto = $_POST['geojson'] ?? null;
    }

    if (!validarTokenCSRF($token)) {
        redResponder([
            'success' => false,
            'message' => 'Token CSRF inválido o expirado. Recargue la página e inicie sesión de nuevo.',
        ], 403);
    }

    if (!is_numeric($baseRevision) || (int) $baseRevision < 1) {
        redResponder([
            'success' => false,
            'message' => 'Falta base_revision.',
            'errors' => [[
                'codigo' => 'E06',
                'mensaje' => 'La petición no indica la revisión base.',
            ]],
        ], 400);
    }

    $geojson = null;
    if (is_string($geojsonBruto)) {
        if (strlen($geojsonBruto) > NetworkService::MAX_BYTES) {
            redResponder([
                'success' => false,
                'message' => 'La red propuesta supera el límite de 2 MB.',
                'errors' => [[
                    'codigo' => 'E07',
                    'mensaje' => 'La red propuesta supera el límite de 2 MB.',
                ]],
            ], 400);
        }

        $geojson = json_decode($geojsonBruto, true);
        if (!is_array($geojson)) {
            redResponder([
                'success' => false,
                'message' => 'El GeoJSON enviado no es válido: ' . json_last_error_msg(),
                'errors' => [[
                    'codigo' => 'E01',
                    'mensaje' => 'El GeoJSON enviado no se pudo interpretar.',
                ]],
            ], 400);
        }
    } elseif (is_array($geojsonBruto)) {
        $geojson = $geojsonBruto;
        $serializado = json_encode($geojson);
        if ($serializado === false || strlen($serializado) > NetworkService::MAX_BYTES) {
            redResponder([
                'success' => false,
                'message' => 'La red propuesta supera el límite de 2 MB.',
                'errors' => [[
                    'codigo' => 'E07',
                    'mensaje' => 'La red propuesta supera el límite de 2 MB.',
                ]],
            ], 400);
        }
    } else {
        redResponder([
            'success' => false,
            'message' => 'Falta el campo geojson.',
            'errors' => [[
                'codigo' => 'E01',
                'mensaje' => 'La petición no incluye la red propuesta.',
            ]],
        ], 400);
    }

    $usuario = $_SESSION['admin_user'] ?? [];
    $servicio = new NetworkService();
    $resultado = $servicio->guardar($geojson, (int) $baseRevision, is_array($usuario) ? $usuario : []);

    redResponder($resultado, 200);
} catch (RevisionObsoletaException $e) {
    redResponder([
        'success' => false,
        'message' => $e->getMessage(),
        'revision' => $e->revisionActual(),
        'errors' => [[
            'codigo' => 'E06',
            'mensaje' => $e->getMessage(),
        ]],
    ], 409);
} catch (NetworkServiceException $e) {
    redResponder([
        'success' => false,
        'message' => $e->getMessage(),
        'errors' => $e->errores(),
    ], $e->statusCode());
} catch (Throwable $e) {
    error_log('save-red.php: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    redResponder([
        'success' => false,
        'message' => 'No se pudo guardar la red. Revise el registro del servidor.',
    ], 500);
}
