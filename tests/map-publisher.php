<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/services/MapPublisher.php';

$pruebas = 0;
$fallos = 0;

function comprobarPublisher(bool $condicion, string $mensaje): void
{
    global $pruebas, $fallos;
    $pruebas++;
    if ($condicion) {
        fwrite(STDOUT, "  ok    {$mensaje}\n");
    } else {
        $fallos++;
        fwrite(STDERR, "  FALLA {$mensaje}\n");
    }
}

function ejecutarPublisher(array $comando, string $directorio): array
{
    $descriptores = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proceso = proc_open($comando, $descriptores, $pipes, $directorio, null, ['bypass_shell' => true]);
    if (!is_resource($proceso)) {
        throw new RuntimeException('No se pudo iniciar: ' . implode(' ', $comando));
    }
    fclose($pipes[0]);
    $salida = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigo = proc_close($proceso);
    return ['status' => $codigo, 'output' => (string) $salida . (string) $error];
}

function gitPublisher(array $argumentos, string $directorio): string
{
    $resultado = ejecutarPublisher(array_merge(['git'], $argumentos), $directorio);
    if ($resultado['status'] !== 0) {
        throw new RuntimeException('Git falló: ' . $resultado['output']);
    }
    return trim($resultado['output']);
}

function eliminarPublisher(string $ruta): void
{
    if (is_link($ruta) || is_file($ruta)) {
        @chmod($ruta, 0666);
        unlink($ruta);
        return;
    }
    if (!is_dir($ruta)) {
        return;
    }
    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterador as $entrada) {
        if ($entrada->isLink() || $entrada->isFile()) {
            @chmod($entrada->getPathname(), 0666);
            unlink($entrada->getPathname());
        } else {
            @chmod($entrada->getPathname(), 0777);
            rmdir($entrada->getPathname());
        }
    }
    @chmod($ruta, 0777);
    rmdir($ruta);
}

$tempBase = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'itl-map-publisher-' . bin2hex(random_bytes(8));
$repo = $tempBase . DIRECTORY_SEPARATOR . 'repo';
$remoto = $tempBase . DIRECTORY_SEPARATOR . 'origin.git';

if (!mkdir($tempBase, 0777, true) && !is_dir($tempBase)) {
    fwrite(STDERR, "No se pudo preparar el directorio temporal de pruebas.\n");
    exit(1);
}

try {
    mkdir($repo . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data', 0777, true);
    mkdir($repo . DIRECTORY_SEPARATOR . 'tests', 0777, true);

    $init = ejecutarPublisher(['git', 'init', $repo], $tempBase);
    if ($init['status'] !== 0) {
        throw new RuntimeException('No se pudo inicializar el repositorio de prueba: ' . $init['output']);
    }
    gitPublisher(['checkout', '-b', 'main'], $repo);
    gitPublisher(['config', 'user.name', 'Map Publisher Test'], $repo);
    gitPublisher(['config', 'user.email', 'map-publisher@example.invalid'], $repo);
    $initRemoto = ejecutarPublisher(['git', 'init', '--bare', $remoto], $tempBase);
    if ($initRemoto['status'] !== 0) {
        throw new RuntimeException('No se pudo inicializar el remoto de prueba: ' . $initRemoto['output']);
    }
    gitPublisher(['remote', 'add', 'origin', $remoto], $repo);

    file_put_contents($repo . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'index.html', '<!doctype html><title>Test</title>');
    file_put_contents(
        $repo . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'publicacion.mjs',
        "import fs from 'node:fs';\nprocess.exit(fs.existsSync('frontend/data/fail-publication.json') ? 1 : 0);\n"
    );
    gitPublisher(['add', '.'], $repo);
    gitPublisher(['commit', '-m', 'base'], $repo);
    gitPublisher(['push', 'origin', 'main'], $repo);

    $publisher = new MapPublisher($repo);
    file_put_contents($repo . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'sample.json', '{"ready":true}');
    $preview = $publisher->vistaPrevia();
    comprobarPublisher(
        $preview['has_changes'] && $preview['files'] === ['frontend/data/sample.json'],
        'la vista previa identifica el cambio estático exacto'
    );

    file_put_contents($repo . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'sample.json', '{"ready":false}');
    try {
        $publisher->publicar($preview['snapshot']);
        comprobarPublisher(false, 'rechaza una vista previa obsoleta');
    } catch (MapPublisherException $e) {
        comprobarPublisher($e->httpStatus === 409, 'rechaza una vista previa obsoleta');
    }

    file_put_contents($repo . DIRECTORY_SEPARATOR . 'backend-private.txt', 'private');
    try {
        $publisher->vistaPrevia();
        comprobarPublisher(false, 'rechaza cambios fuera de frontend/');
    } catch (MapPublisherException $e) {
        comprobarPublisher($e->httpStatus === 409, 'rechaza cambios fuera de frontend/');
    }
    unlink($repo . DIRECTORY_SEPARATOR . 'backend-private.txt');

    file_put_contents($repo . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'index.php', '<?php');
    try {
        $publisher->vistaPrevia();
        comprobarPublisher(false, 'rechaza archivos PHP en el artefacto estático');
    } catch (MapPublisherException $e) {
        comprobarPublisher($e->httpStatus === 409, 'rechaza archivos PHP en el artefacto estático');
    }
    unlink($repo . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'index.php');

    $archivoOculto = $repo . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . '.env.local';
    file_put_contents($archivoOculto, 'private');
    try {
        $publisher->vistaPrevia();
        comprobarPublisher(false, 'rechaza archivos ocultos que no deben publicarse');
    } catch (MapPublisherException $e) {
        comprobarPublisher($e->httpStatus === 409, 'rechaza archivos ocultos que no deben publicarse');
    }
    unlink($archivoOculto);

    $archivoNoPublicable = $repo . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'secrets.txt';
    file_put_contents($archivoNoPublicable, 'private');
    try {
        $publisher->vistaPrevia();
        comprobarPublisher(false, 'rechaza extensiones ajenas a los recursos estáticos permitidos');
    } catch (MapPublisherException $e) {
        comprobarPublisher($e->httpStatus === 409, 'rechaza extensiones ajenas a los recursos estáticos permitidos');
    }
    unlink($archivoNoPublicable);

    file_put_contents($repo . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'fail-publication.json', '{}');
    $previewConFallo = $publisher->vistaPrevia();
    $headAntes = gitPublisher(['rev-parse', 'HEAD'], $repo);
    try {
        $publisher->publicar($previewConFallo['snapshot']);
        comprobarPublisher(false, 'no crea commits si falla la validación del sitio');
    } catch (MapPublisherException $e) {
        comprobarPublisher(
            $e->httpStatus === 422 && gitPublisher(['rev-parse', 'HEAD'], $repo) === $headAntes,
            'no crea commits si falla la validación del sitio'
        );
    }
    unlink($repo . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'fail-publication.json');

    $previewFinal = $publisher->vistaPrevia();
    $publicado = $publisher->publicar($previewFinal['snapshot']);
    $jsonRemoto = gitPublisher(['--git-dir=' . $remoto, 'show', 'main:frontend/data/sample.json'], $tempBase);
    comprobarPublisher(
        $publicado['published'] && $jsonRemoto === '{"ready":false}',
        'confirma y envía solo el mapa estático al remoto main'
    );

    $treeRemoto = gitPublisher(['write-tree'], $repo);
    $headLocal = gitPublisher(['rev-parse', 'HEAD'], $repo);
    $commitRemoto = gitPublisher(['commit-tree', $treeRemoto, '-p', $headLocal, '-m', 'avance remoto'], $repo);
    gitPublisher(['push', 'origin', $commitRemoto . ':refs/heads/main'], $repo);
    try {
        $publisher->vistaPrevia();
        comprobarPublisher(false, 'rechaza un origin/main más nuevo que la rama local');
    } catch (MapPublisherException $e) {
        comprobarPublisher($e->httpStatus === 409, 'rechaza un origin/main más nuevo que la rama local');
    }
} catch (Throwable $e) {
    comprobarPublisher(false, 'ejecución de integración del publicador: ' . $e->getMessage());
} finally {
    $baseSegura = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'itl-map-publisher-';
    if (str_starts_with($tempBase, $baseSegura)) {
        eliminarPublisher($tempBase);
    }
}

fwrite(STDOUT, "\n" . ($fallos === 0 ? "OK: {$pruebas} pruebas correctas.\n" : "FALLOS: {$fallos} de {$pruebas} pruebas.\n"));
exit($fallos === 0 ? 0 : 1);
