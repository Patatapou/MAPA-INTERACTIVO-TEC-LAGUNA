<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/repositories/UserRepository.php';

$fallos = [];
function comprobarRepoUsuarios(bool $condicion, string $mensaje): void
{
    global $fallos;
    if (!$condicion) $fallos[] = $mensaje;
}

$tmp = sys_get_temp_dir() . '/itl-users-' . bin2hex(random_bytes(6));
mkdir($tmp, 0777, true);

try {
    $repo = new UserRepository($tmp);
    $ganador = null;
    $disputas = 0;
    for ($i = 0; $i < 2; $i++) {
        try {
            $repo->transaccion(function (array $datos) use (&$ganador) {
                foreach ($datos as $usuario) {
                    if (strtolower((string) ($usuario['username'] ?? '')) === 'prueba.uno') {
                        throw new RuntimeException('duplicado');
                    }
                }
                $id = 'user_' . bin2hex(random_bytes(8));
                $datos[$id] = [
                    'id' => $id,
                    'username' => 'prueba.uno',
                    'email' => null,
                    'password_hash' => password_hash('Contraseña#2026', PASSWORD_DEFAULT),
                    'role' => 'administrador',
                    'status' => 'activo',
                    'schema_version' => 1,
                ];
                $ganador = $id;
                return ['datos' => $datos, 'resultado' => $id];
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'duplicado') $disputas++;
        }
    }
    comprobarRepoUsuarios($disputas === 1, 'Una de dos altas duplicadas debe ser rechazada.');
    comprobarRepoUsuarios($repo->buscarPorUsername('PRUEBA.UNO') !== null, 'La búsqueda debe aceptar mayúsculas sin distinguir.');

    file_put_contents($tmp . '/users.json', '{archivo corrupto');
    $corruptoPreservado = file_get_contents($tmp . '/users.json');
    try {
        $repo->listar();
        comprobarRepoUsuarios(false, 'El archivo corrupto debía fallar.');
    } catch (RuntimeException $e) {
        comprobarRepoUsuarios(true, '');
    }
    comprobarRepoUsuarios(file_get_contents($tmp . '/users.json') === $corruptoPreservado, 'El archivo corrupto no debe sobrescribirse al leer.');

    file_put_contents($tmp . '/users.json', json_encode([
        'user_1111111111111111' => [
            'id' => 'user_1111111111111111',
            'username' => 'v1.admin',
            'email' => null,
            'password_hash' => password_hash('Contraseña#2026', PASSWORD_DEFAULT),
            'role' => 'administrador',
            'status' => 'activo',
            'schema_version' => 1,
        ],
    ], JSON_PRETTY_PRINT));
    $v1 = $repo->buscarPorId('user_1111111111111111');
    comprobarRepoUsuarios(($v1['schema_version'] ?? null) === 2, 'La lectura debe normalizar el esquema v2.');
    comprobarRepoUsuarios(($v1['session_version'] ?? null) === 1, 'La migración perezosa debe asignar session_version.');
} finally {
    foreach (glob($tmp . '/*') ?: [] as $archivo) {
        unlink($archivo);
    }
    @rmdir($tmp);
}

if ($fallos !== []) {
    foreach ($fallos as $fallo) fwrite(STDERR, "FALLO: {$fallo}\n");
    exit(1);
}
echo "OK: pruebas de repositorio de usuarios\n";
