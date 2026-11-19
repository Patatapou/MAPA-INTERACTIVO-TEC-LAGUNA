<?php
// 1. Cargar constantes de rutas e incluir middleware de autenticación
require_once __DIR__ . '/PHP/bootstrap.php';
require_once BACKEND_PATH . '/auth/auth_middleware.php';

// 2. Definimos la carpeta donde guardaremos las secciones (por orden)

// 3. Capturamos la variable de la URL (ej: index.php?p=pagina1)
// // Si no hay variable, por defecto cargamos 'inicio'
// $pagina = isset($_GET['p']) ? $_GET['p'] : 'mapa';
if(!isset($_GET['p'])){
    $pagina = 'mapa';
    $archivo = ROOT_PATH . '/' . $pagina . '.php';
}else if($_GET['p']=='editar'){
    // Ruta protegida: requiere autenticación de administrador
    requerirAdmin();
    $archivo = BACKEND_PATH . '/admin/panel.php';
}else if($_GET['p']=='login'){
    // Ruta pública: puerta de entrada, no requiere autenticación previa
    $archivo = BACKEND_PATH . '/admin/login.php';
}else if($_GET['p']=='logout'){
    // Ruta protegida: requiere autenticación para cerrar sesión
    requerirAdmin();
    $archivo = BACKEND_PATH . '/admin/logout.php';
}else if($_GET['p']=='getimages'){
    // Ruta protegida: requiere autenticación de administrador
    requerirAdmin();
    $archivo = BACKEND_PATH . '/admin/imagelist.php';
}else if($_GET['p']=='saveData'){
    // Ruta protegida: requiere autenticación de administrador
    requerirAdmin();
    $archivo = BACKEND_PATH . '/admin/saveEdificio.php';
}else if($_GET['p']=='saveGeometria'){
    // Ruta protegida: guarda geometría de edificio
    requerirAdmin();
    $archivo = ROOT_PATH . '/saveGeometria.php';
}else if($_GET['p']=='deleteEdificio'){
    // Ruta protegida: elimina edificio
    requerirAdmin();
    $archivo = ROOT_PATH . '/deleteEdificio.php';
}else if($_GET['p']=='editar-geometria'){
    // Ruta protegida: editor de geometrías (página completa)
    requerirAdmin();
    $archivo = BACKEND_PATH . '/admin/editar-geometria.php';
}else if($_GET['p']=='rutas'){
    // Ruta protegida: gestión de rutas (listado, detalle, alta y edición)
    requerirAdmin();
    $archivo = BACKEND_PATH . '/admin/rutas.php';
}else if($_GET['p']=='getRutas'){
    // Ruta protegida: endpoint de consulta de rutas
    requerirAdmin();
    $archivo = BACKEND_PATH . '/admin/getRutas.php';
}else if($_GET['p']=='saveRuta'){
    // Ruta protegida: endpoint de alta/edición de rutas
    requerirAdmin();
    $archivo = BACKEND_PATH . '/admin/saveRuta.php';
}else if($_GET['p']=='deleteRuta'){
    // Ruta protegida: endpoint de eliminación de rutas
    requerirAdmin();
    $archivo = BACKEND_PATH . '/admin/deleteRuta.php';
}else if($_GET['p']=='getRedCaminos'){
    // Ruta protegida: endpoint de consulta de la red de caminos
    requerirAdmin();
    $archivo = BACKEND_PATH . '/admin/getRedCaminos.php';
}else if($_GET['p']=='editar-red'){
    // Ruta protegida: editor gráfico de la red de caminos (página completa)
    requerirAdmin();
    $archivo = BACKEND_PATH . '/admin/editar-red.php';
}else if($_GET['p']=='getRed'){
    // Ruta protegida: estado vigente de caminitos.geojson
    requerirAdmin();
    $archivo = BACKEND_PATH . '/admin/getRed.php';
}else if($_GET['p']=='save-red'){
    // Ruta protegida: guardado atómico de la red (CSRF, revisión y validación)
    requerirAdmin();
    $archivo = ROOT_PATH . '/save-red.php';
}else{
    $archivo = ROOT_PATH . '/404.php';
}

if (file_exists($archivo)) {
    include($archivo);
} else {
    $notFound = ROOT_PATH . '/404.php';
    if (file_exists($notFound)) {
        include($notFound);
    }
    http_response_code(404);
}
?>