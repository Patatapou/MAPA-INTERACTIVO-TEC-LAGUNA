<?php
declare(strict_types=1);

$raiz = dirname(__DIR__);
$runtime = $raiz . '/database/runtime';
$backups = $raiz . '/database/backups';
foreach ([$runtime, $backups] as $directorio) {
    if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
        fwrite(STDERR, "No se pudo crear {$directorio}\n");
        exit(1);
    }
}

require_once $raiz . '/backend/bootstrap.php';

$rutaUsers = RUNTIME_PATH . '/users.json';
if (file_exists($rutaUsers)) {
    fwrite(STDOUT, "La instalación ya existe: users.json no fue modificado.\n");
    exit(0);
}

$ahora = date('c');
$usuario = [
    'id' => 'admin_001',
    'username' => 'admin',
    'email' => null,
    'password_hash' => password_hash('123456', PASSWORD_DEFAULT),
    'role' => 'administrador',
    'status' => 'activo',
    'must_change_password' => true,
    'password_changed_at' => null,
    'session_version' => 1,
    'created_by' => null,
    'created_at' => $ahora,
    'updated_at' => $ahora,
    'last_login_at' => null,
    'schema_version' => 2,
];

$json = json_encode(['admin_001' => $usuario], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$temporal = $rutaUsers . '.tmp.' . bin2hex(random_bytes(6));
if ($json === false || file_put_contents($temporal, $json, LOCK_EX) === false || !rename($temporal, $rutaUsers)) {
    if (file_exists($temporal)) {
        unlink($temporal);
    }
    fwrite(STDERR, "No se pudo crear users.json.\n");
    exit(1);
}

fwrite(STDOUT, "Instalación completada. Usuario inicial: admin / 123456 (deberá cambiar la contraseña).\n");
