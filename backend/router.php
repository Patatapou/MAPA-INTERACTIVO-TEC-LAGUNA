<?php
// ==========================================
// ROUTER DE LA APLICACIÓN
// ==========================================
// Único punto que decide qué archivo atiende cada petición.
// El front controller (`index.php`) solo carga el bootstrap y este router.
//
// Rutas públicas: mapa y login.
// Rutas protegidas: el resto; se valida la sesión ANTES de incluir el archivo.
// Los endpoints vuelven a comprobar la sesión por sí mismos (defensa en
// profundidad si se accediera al archivo directamente).
//
// `check-session` y `send-suggestion` ya no se enrutan: el mapa público es
// estático y no usa PHP (los archivos se conservan sin uso, ver plan).
//
// `frontend/` es el document root: `index.html` (mapa) se sirve directamente.

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once BACKEND_PATH . '/auth/auth_middleware.php';

// Sin `?p=` la aplicación no tiene página propia: el mapa es `index.html`.
// (El antiguo `mapa.php` es legacy y ya está en `DUMP/`.)
if (!isset($_GET['p'])) {
    header('Location: index.html', true, 302);
    exit;
}

$pagina = (string) $_GET['p'];

/**
 * Mapa de rutas: clave de `?p=` → archivo + si exige sesión de administrador.
 *
 * @var array<string, array{archivo: string, auth: bool}>
 */
$rutas = [
    // Públicas
    'login'           => ['archivo' => BACKEND_PATH . '/admin/login.php', 'auth' => false],

    // Panel y vistas protegidas
    'editar'            => ['archivo' => BACKEND_PATH . '/admin/panel.php', 'auth' => true],
    'logout'            => ['archivo' => BACKEND_PATH . '/admin/logout.php', 'auth' => true],
    'editar-geometria'  => ['archivo' => BACKEND_PATH . '/admin/editar-geometria.php', 'auth' => true],
    'rutas'             => ['archivo' => BACKEND_PATH . '/admin/rutas.php', 'auth' => true],
    'editar-red'        => ['archivo' => BACKEND_PATH . '/admin/editar-red.php', 'auth' => true],
    'usuarios'          => ['archivo' => BACKEND_PATH . '/admin/usuarios.php', 'auth' => true],
    'cambiar-contrasena'=> ['archivo' => BACKEND_PATH . '/admin/cambiar-contrasena.php', 'auth' => true],

    // Endpoints protegidos
    'getimages'         => ['archivo' => BACKEND_PATH . '/api/list-images.php', 'auth' => true],
    'saveData'          => ['archivo' => BACKEND_PATH . '/api/save-edificio.php', 'auth' => true],
    'saveGeometria'     => ['archivo' => BACKEND_PATH . '/api/save-geometria.php', 'auth' => true],
    'deleteEdificio'    => ['archivo' => BACKEND_PATH . '/api/delete-edificio.php', 'auth' => true],
    'getRutas'          => ['archivo' => BACKEND_PATH . '/api/get-rutas.php', 'auth' => true],
    'saveRuta'          => ['archivo' => BACKEND_PATH . '/api/save-ruta.php', 'auth' => true],
    'deleteRuta'        => ['archivo' => BACKEND_PATH . '/api/delete-ruta.php', 'auth' => true],
    'getRedCaminos'     => ['archivo' => BACKEND_PATH . '/api/get-red-caminos.php', 'auth' => true],
    'getRed'            => ['archivo' => BACKEND_PATH . '/api/get-red.php', 'auth' => true],
    'save-red'          => ['archivo' => BACKEND_PATH . '/api/save-red.php', 'auth' => true],
    'getUsuarios'       => ['archivo' => BACKEND_PATH . '/api/get-usuarios.php', 'auth' => true],
    'saveUsuario'       => ['archivo' => BACKEND_PATH . '/api/save-usuario.php', 'auth' => true],
    'setEstadoUsuario'  => ['archivo' => BACKEND_PATH . '/api/set-estado-usuario.php', 'auth' => true],
    'resetPasswordUsuario'=> ['archivo' => BACKEND_PATH . '/api/reset-password-usuario.php', 'auth' => true],
    'deleteUsuario'     => ['archivo' => BACKEND_PATH . '/api/delete-usuario.php', 'auth' => true],
];

$ruta = $rutas[$pagina] ?? null;

if ($ruta === null) {
    http_response_code(404);
    require BACKEND_PATH . '/views/404.php';
    exit;
}

if ($ruta['auth']) {
    requerirAdmin();
}

require $ruta['archivo'];
