<?php
declare(strict_types=1);

final class MapPublisherException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

final class MapPublisher
{
    private string $root;
    private ?Closure $prepararExportacion;

    public function __construct(?string $rootPath = null, ?Closure $prepararExportacion = null)
    {
        $root = realpath($rootPath ?? ROOT_PATH);
        if ($root === false || !is_dir($root)) {
            throw new MapPublisherException('No se pudo localizar la raíz del repositorio.', 500);
        }
        $this->root = $root;
        $this->prepararExportacion = $prepararExportacion;
    }

    public function vistaPrevia(): array
    {
        $this->prepararExportacion();
        $estado = $this->obtenerEstadoSeguro();
        return [
            'files' => $estado['files'],
            'snapshot' => $estado['snapshot'],
            'has_changes' => $estado['files'] !== [],
        ];
    }

    public function publicar(string $snapshotEsperado): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $snapshotEsperado)) {
            throw new MapPublisherException('La confirmación de publicación no es válida.', 400);
        }

        $this->prepararExportacion();
        $estado = $this->obtenerEstadoSeguro();
        if (!hash_equals($estado['snapshot'], $snapshotEsperado)) {
            throw new MapPublisherException(
                'Los archivos o el estado de Git cambiaron desde la vista previa. Vuelve a revisar la publicación.',
                409
            );
        }

        if ($estado['files'] === []) {
            return ['published' => false, 'message' => 'No hay cambios del mapa para publicar.'];
        }

        $validacion = $this->ejecutar(['node', 'tests/publicacion.mjs']);
        if ($validacion['status'] !== 0) {
            throw new MapPublisherException(
                'Falló la validación del sitio publicable. No se creó ningún commit.',
                422,
                ['validation_output' => $this->sanitizarSalida($validacion['output'])]
            );
        }

        $this->prepararExportacion();
        $estadoValidado = $this->obtenerEstadoSeguro();
        if (!hash_equals($estadoValidado['snapshot'], $snapshotEsperado)) {
            throw new MapPublisherException(
                'Los archivos cambiaron durante la validación. Vuelve a revisar la publicación.',
                409
            );
        }

        $argumentosAdd = ['add', '-A', '--'];
        foreach ($estado['files'] as $archivo) {
            $argumentosAdd[] = $archivo;
        }
        $this->ejecutarGit($argumentosAdd, 'No se pudieron preparar los archivos del mapa.');

        $staged = $this->ejecutarGit(
            ['diff', '--cached', '--name-only', '-z'],
            'No se pudo revisar la lista de archivos preparados.'
        );
        $archivosPreparados = array_values(array_filter(explode("\0", $staged), static fn(string $path): bool => $path !== ''));
        foreach ($archivosPreparados as $archivo) {
            $this->validarArchivoPublicable($archivo);
        }

        $cambiosSinPreparar = $this->ejecutar(['git', 'diff', '--quiet', '--', 'frontend']);
        if ($cambiosSinPreparar['status'] !== 0) {
            throw new MapPublisherException(
                'Los archivos cambiaron mientras se preparaba el commit. No se publicó; vuelve a revisar la vista previa.',
                409
            );
        }

        if ($archivosPreparados === []) {
            return ['published' => false, 'message' => 'No hay cambios nuevos del mapa para publicar.'];
        }

        $commit = $this->ejecutar(['git', 'commit', '-m', 'Actualizar mapa público']);
        if ($commit['status'] !== 0) {
            throw new MapPublisherException(
                'Git no pudo crear el commit. Revisa la identidad de Git y el estado del repositorio.',
                500,
                ['command_output' => $this->sanitizarSalida($commit['output'])]
            );
        }

        $hash = trim($this->ejecutarGit(['rev-parse', '--short', 'HEAD'], 'No se pudo verificar el commit.'));
        $push = $this->ejecutar(['git', 'push', 'origin', 'HEAD:refs/heads/main']);
        if ($push['status'] !== 0) {
            throw new MapPublisherException(
                'El commit ' . $hash . ' quedó localmente, pero GitHub rechazó el push. No se confirmó la publicación; revisa el detalle de Git y sincroniza main manualmente antes de volver a intentarlo.',
                502,
                ['commit' => $hash, 'command_output' => $this->sanitizarSalida($push['output'])]
            );
        }

        return [
            'published' => true,
            'commit' => $hash,
            'files' => $archivosPreparados,
            'message' => 'Cambios enviados a main. GitHub Actions iniciará la publicación del mapa.',
        ];
    }

    private function prepararExportacion(): void
    {
        if ($this->prepararExportacion === null) {
            return;
        }

        try {
            ($this->prepararExportacion)();
        } catch (MapPublisherException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MapPublisherException(
                'No se pudo generar la exportación pública: ' . $e->getMessage(),
                422
            );
        }
    }

    private function obtenerEstadoSeguro(): array
    {
        $raizGit = trim($this->ejecutarGit(['rev-parse', '--show-toplevel'], 'Esta carpeta no es un repositorio Git.'));
        $raizReal = realpath($raizGit);
        if ($raizReal === false || !$this->mismasRutas($raizReal, $this->root)) {
            throw new MapPublisherException('El repositorio Git no coincide con la carpeta de la aplicación.', 409);
        }

        $rama = trim($this->ejecutarGit(['branch', '--show-current'], 'No se pudo identificar la rama actual.'));
        if ($rama !== 'main') {
            throw new MapPublisherException('La publicación solo está habilitada desde la rama main.', 409);
        }

        $remoto = $this->ejecutar(['git', 'remote', 'get-url', 'origin']);
        if ($remoto['status'] !== 0 || trim($remoto['output']) === '') {
            throw new MapPublisherException('No hay un remoto origin configurado para publicar.', 409);
        }

        $fetch = $this->ejecutar(['git', '-c', 'credential.interactive=never', 'fetch', '--quiet', 'origin', 'main']);
        if ($fetch['status'] !== 0) {
            throw new MapPublisherException(
                'No se pudo consultar origin/main. Comprueba la conexión y la autenticación de Git.',
                502,
                ['command_output' => $this->sanitizarSalida($fetch['output'])]
            );
        }

        $local = trim($this->ejecutarGit(['rev-parse', 'HEAD'], 'No se pudo identificar el commit local.'));
        $remotoMain = trim($this->ejecutarGit(['rev-parse', 'refs/remotes/origin/main'], 'No se encontró origin/main.'));
        if ($local === '' || $local !== $remotoMain) {
            throw new MapPublisherException(
                'La rama local main no coincide con origin/main. Sincroniza el repositorio manualmente y vuelve a intentar.',
                409
            );
        }

        $estadoGit = $this->ejecutarGit(
            ['status', '--porcelain=v1', '-z', '--untracked-files=all'],
            'No se pudo leer el estado del repositorio.'
        );
        $archivos = $this->interpretarEstado($estadoGit);

        $indice = trim($this->ejecutarGit(['rev-parse', '--git-path', 'index'], 'No se pudo verificar el índice de Git.'));
        if (!$this->rutaDentroDelRepo($indice)) {
            $indice = $this->root . DIRECTORY_SEPARATOR . $indice;
        }
        $hashIndice = is_file($indice) ? hash_file('sha256', $indice) : '';

        $partesSnapshot = [$local, $remotoMain, $estadoGit, (string) $hashIndice];
        foreach ($archivos as $archivo) {
            $absoluta = $this->rutaArchivo($archivo);
            $contenido = is_file($absoluta) ? hash_file('sha256', $absoluta) : 'deleted';
            $partesSnapshot[] = $archivo . "\0" . (string) $contenido;
        }

        return [
            'files' => $archivos,
            'snapshot' => hash('sha256', implode("\0", $partesSnapshot)),
        ];
    }

    private function interpretarEstado(string $estado): array
    {
        if ($estado === '') {
            return [];
        }

        $entradas = explode("\0", $estado);
        if (end($entradas) === '') {
            array_pop($entradas);
        }

        $archivos = [];
        foreach ($entradas as $entrada) {
            if (strlen($entrada) < 4) {
                throw new MapPublisherException('Git devolvió un estado que no se pudo interpretar.', 500);
            }

            $cambio = substr($entrada, 0, 2);
            $archivo = substr($entrada, 3);
            if (strpos($cambio, 'R') !== false || strpos($cambio, 'C') !== false) {
                throw new MapPublisherException('No se publican cambios de nombre o copias automáticamente.', 409);
            }
            if (strpos($cambio, 'U') !== false || $cambio === 'AA' || $cambio === 'DD') {
                throw new MapPublisherException('Resuelve primero los conflictos de Git.', 409);
            }

            $this->validarArchivoPublicable($archivo);
            $archivos[] = $archivo;
        }

        sort($archivos, SORT_STRING);
        return array_values(array_unique($archivos));
    }

    private function validarArchivoPublicable(string $archivo): void
    {
        $tipoArchivo = null;
        $extensionesPermitidas = [];
        if ($archivo === 'frontend/index.html') {
            $tipoArchivo = 'html';
        } elseif (preg_match('#^frontend/css/#', $archivo) === 1) {
            $tipoArchivo = 'static';
            $extensionesPermitidas = ['css', 'gif', 'jpeg', 'jpg', 'png', 'svg', 'webp'];
        } elseif (preg_match('#^frontend/data/#', $archivo) === 1) {
            $tipoArchivo = 'static';
            $extensionesPermitidas = ['geojson', 'js', 'json'];
        } elseif (preg_match('#^frontend/img/#', $archivo) === 1 || preg_match('#^frontend/tiles/#', $archivo) === 1) {
            $tipoArchivo = 'static';
            $extensionesPermitidas = ['gif', 'jpeg', 'jpg', 'png', 'svg', 'webp'];
        } elseif (preg_match('#^frontend/js/#', $archivo) === 1) {
            $tipoArchivo = 'static';
            $extensionesPermitidas = ['js'];
        }

        $componentes = explode('/', $archivo);
        $extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
        $licenciaVendor = $archivo === 'frontend/js/vendor/leaflet/LICENSE';
        if (
            $tipoArchivo === null
            || preg_match('/[\x00-\x1f]/', $archivo) === 1
            || preg_match('#(^|/)\.\.?(/|$)#', $archivo) === 1
            || array_filter($componentes, static fn(string $part): bool => str_starts_with($part, '.')) !== []
            || ($tipoArchivo !== 'html' && !$licenciaVendor && !in_array($extension, $extensionesPermitidas, true))
            || strtolower(basename($archivo)) === 'users.json'
        ) {
            throw new MapPublisherException(
                'Hay cambios fuera del contenido estático permitido o un archivo privado. No se preparó ningún commit.',
                409,
                ['file' => $archivo]
            );
        }

        $absoluta = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $archivo);
        $directorio = dirname($absoluta);
        while ($directorio !== $this->root && str_starts_with(strtolower($directorio), strtolower($this->root . DIRECTORY_SEPARATOR))) {
            if (is_link($directorio)) {
                throw new MapPublisherException('No se publican archivos que atraviesen enlaces simbólicos.', 409);
            }
            $directorio = dirname($directorio);
        }
        if (is_link($absoluta)) {
            throw new MapPublisherException('No se publican enlaces simbólicos.', 409);
        }
    }

    private function rutaArchivo(string $archivo): string
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $archivo);
    }

    private function rutaDentroDelRepo(string $ruta): bool
    {
        return str_starts_with(strtolower($ruta), strtolower($this->root . DIRECTORY_SEPARATOR));
    }

    private function mismasRutas(string $primera, string $segunda): bool
    {
        return strtolower(rtrim($primera, '/\\')) === strtolower(rtrim($segunda, '/\\'));
    }

    private function ejecutarGit(array $argumentos, string $mensajeError): string
    {
        $resultado = $this->ejecutar(array_merge(['git'], $argumentos));
        if ($resultado['status'] !== 0) {
            throw new MapPublisherException(
                $mensajeError,
                500,
                ['command_output' => $this->sanitizarSalida($resultado['output'])]
            );
        }
        return $resultado['output'];
    }

    private function ejecutar(array $comando): array
    {
        $stdout = tempnam(sys_get_temp_dir(), 'itl-publish-out-');
        $stderr = tempnam(sys_get_temp_dir(), 'itl-publish-err-');
        if ($stdout === false || $stderr === false) {
            if (is_string($stdout)) {
                @unlink($stdout);
            }
            if (is_string($stderr)) {
                @unlink($stderr);
            }
            throw new MapPublisherException('No se pudieron crear archivos temporales para ejecutar la validación.', 500);
        }

        $descriptores = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdout, 'ab'],
            2 => ['file', $stderr, 'ab'],
        ];

        $promptAnterior = getenv('GIT_TERMINAL_PROMPT');
        putenv('GIT_TERMINAL_PROMPT=0');
        try {
            $proceso = proc_open($comando, $descriptores, $pipes, $this->root, null, ['bypass_shell' => true]);
            if (!is_resource($proceso)) {
                throw new MapPublisherException('No se pudo iniciar el proceso de validación/publicación.', 500);
            }
            fclose($pipes[0]);
            $status = proc_close($proceso);
            $salida = (string) @file_get_contents($stdout) . (string) @file_get_contents($stderr);
            return ['status' => $status, 'output' => $salida];
        } finally {
            if ($promptAnterior === false) {
                putenv('GIT_TERMINAL_PROMPT');
            } else {
                putenv('GIT_TERMINAL_PROMPT=' . $promptAnterior);
            }
            @unlink($stdout);
            @unlink($stderr);
        }
    }

    private function sanitizarSalida(string $salida): string
    {
        $salida = preg_replace('#(https?://)[^/@\s]+@#i', '$1[redacted]@', trim($salida)) ?? '';
        return substr($salida, 0, 2000);
    }
}
