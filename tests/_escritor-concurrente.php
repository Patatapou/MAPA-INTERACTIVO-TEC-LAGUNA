<?php
// ==========================================
// ESCRITOR CONCURRENTE (soporte de pruebas)
// ==========================================
// Escribe N rutas en bucle a través de `RouteRepository`, cada una con su
// propio bloqueo. Lo usan las pruebas de concurrencia y de recuperación.
//
// Uso: php tests/_escritor-concurrente.php <dir-datos> <prefijo> <cantidad> [pausa-us]

declare(strict_types=1);

require_once __DIR__ . '/../backend/repositories/RouteRepository.php';

$dir = $argv[1] ?? '';
$prefijo = $argv[2] ?? 'a';
$cantidad = (int) ($argv[3] ?? 10);
$pausaUs = (int) ($argv[4] ?? 5000);

if ($dir === '') {
    fwrite(STDERR, "Uso: php tests/_escritor-concurrente.php <dir-datos> <prefijo> <cantidad>\n");
    exit(1);
}

$repo = new RouteRepository($dir);
$fallos = 0;

for ($i = 0; $i < $cantidad; $i++) {
    try {
        $repo->actualizarConBloqueo(static function (array $documento) use ($prefijo, $i): array {
            $id = 'ruta-' . str_pad(dechex(crc32($prefijo . '-' . $i) & 0xFFFFFFFF), 8, '0', STR_PAD_LEFT);
            $documento['rutas'][$id] = [
                'id' => $id,
                'nombre' => 'Ruta ' . $prefijo . ' ' . $i,
                'segmentos' => [],
                'creado_en' => date('c'),
                'actualizado_en' => date('c'),
            ];
            return ['datos' => $documento, 'resultado' => $id];
        });
    } catch (Throwable $e) {
        $fallos++;
        fwrite(STDERR, 'Error en ' . $prefijo . '-' . $i . ': ' . $e->getMessage() . "\n");
    }

    if ($pausaUs > 0) {
        usleep($pausaUs);
    }
}

exit($fallos === 0 ? 0 : 1);
