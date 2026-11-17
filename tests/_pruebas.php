<?php
// ==========================================
// AYUDAS PARA PRUEBAS CLI (sin dependencias)
// ==========================================
// Mini harness para los scripts de `tests/`. No hay PHPUnit ni Composer:
// cada script imprime su propio informe y devuelve código 0/1.
//
// Uso:
//   require_once __DIR__ . '/_pruebas.php';
//   afirmar('Caso', $condicion, 'detalle');
//   salirConResumen('rutas-repository');

declare(strict_types=1);

$GLOBALS['__pruebas'] = ['ok' => 0, 'fallos' => []];

/**
 * Registrar una comprobación.
 */
function afirmar(string $caso, bool $condicion, string $detalle = ''): bool
{
    if ($condicion) {
        $GLOBALS['__pruebas']['ok']++;
        echo 'OK    ' . $caso . "\n";
        return true;
    }

    $GLOBALS['__pruebas']['fallos'][] = $caso;
    echo 'FALLO ' . $caso . ($detalle !== '' ? ' | ' . $detalle : '') . "\n";
    return false;
}

/**
 * Registrar una comprobación de igualdad.
 *
 * @param mixed $esperado
 * @param mixed $obtenido
 */
function afirmarIgual(string $caso, $esperado, $obtenido): bool
{
    $iguales = $esperado === $obtenido;
    $detalle = $iguales
        ? ''
        : 'esperado ' . var_export($esperado, true) . ', obtenido ' . var_export($obtenido, true);
    return afirmar($caso, $iguales, $detalle);
}

/**
 * Crear un directorio temporal aislado para el caso.
 */
function crearDirTemporal(string $prefijo): string
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mapa-itl-' . $prefijo . '-' . bin2hex(random_bytes(4));
    if (!mkdir($base, 0775, true) && !is_dir($base)) {
        throw new RuntimeException('No se pudo crear el directorio temporal: ' . $base);
    }
    return $base;
}

/**
 * Eliminar recursivamente un directorio temporal.
 */
function borrarDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $ruta = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($ruta)) {
            borrarDir($ruta);
        } else {
            @unlink($ruta);
        }
    }
    @rmdir($dir);
}

/**
 * Imprimir el resumen y terminar con el código adecuado.
 */
function salirConResumen(string $nombre): void
{
    $ok = (int) $GLOBALS['__pruebas']['ok'];
    $fallos = $GLOBALS['__pruebas']['fallos'];
    $total = $ok + count($fallos);
    echo "\n" . $nombre . ': ' . $ok . '/' . $total . " comprobaciones correctas\n";

    if ($fallos !== []) {
        echo "\nFallos:\n";
        foreach ($fallos as $fallo) {
            echo '  - ' . $fallo . "\n";
        }
        exit(1);
    }

    echo "OK\n";
    exit(0);
}
