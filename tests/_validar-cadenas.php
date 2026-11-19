<?php
// ==========================================
// VALIDADOR DE CADENAS (soporte de pruebas)
// ==========================================
// Recibe un archivo JSON con `[{caso, cadena}]`, valida cada cadena con el
// servicio real y devuelve `[{caso, ok, errores}]` en JSON.
//
// Lo usa `tests/validar-cadenas.mjs --php` para comprobar que la lógica del
// cliente y la del servidor coinciden (apartado 16.3 del plan).

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/services/PathNetworkService.php';

$archivo = $argv[1] ?? '';
if ($archivo === '' || !is_file($archivo)) {
    fwrite(STDERR, "Uso: php tests/_validar-cadenas.php <fixtures.json>\n");
    exit(1);
}

$contenido = file_get_contents($archivo);
$casos = json_decode((string) $contenido, true);
if (!is_array($casos)) {
    fwrite(STDERR, "El archivo de fixtures no es JSON válido.\n");
    exit(1);
}

$servicio = new PathNetworkService();
$salida = [];

foreach ($casos as $caso) {
    $cadena = isset($caso['cadena']) && is_array($caso['cadena']) ? $caso['cadena'] : [];
    $resultado = $servicio->validarCadena($cadena);
    $salida[] = [
        'caso' => (string) ($caso['caso'] ?? ''),
        'ok' => (bool) $resultado['ok'],
        'errores' => $resultado['errores'],
        'distancia_m' => $resultado['distancia_m'],
    ];
}

echo json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
