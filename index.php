<?php
// 1. Incluir middleware de autenticación para rutas protegidas
require_once 'PHP/auth/auth_middleware.php';

// 2. Definimos la carpeta donde guardaremos las secciones (por orden)

// 3. Capturamos la variable de la URL (ej: index.php?p=pagina1)
// // Si no hay variable, por defecto cargamos 'inicio'
// $pagina = isset($_GET['p']) ? $_GET['p'] : 'mapa';
if(!isset($_GET['p'])){
    $pagina = 'mapa';
    $archivo = $pagina . '.php';
}else if($_GET['p']=='editar'){
    // Ruta protegida: requiere autenticación de administrador
    requerirAdmin();
    $archivo = 'PHP/admin/panel.php';
}else if($_GET['p']=='login'){
    // Ruta pública: puerta de entrada, no requiere autenticación previa
    $archivo = 'PHP/admin/login.php';
}else if($_GET['p']=='logout'){
    // Ruta protegida: requiere autenticación para cerrar sesión
    requerirAdmin();
    $archivo = 'PHP/admin/logout.php';
}else if($_GET['p']=='getimages'){
    // Ruta protegida: requiere autenticación de administrador
    requerirAdmin();
    $archivo = 'PHP/admin/imagelist.php';
}else if($_GET['p']=='saveData'){
    // Ruta protegida: requiere autenticación de administrador
    requerirAdmin();
    $archivo = 'PHP/admin/saveEdificio.php';
}else if($_GET['p']=='saveGeometria'){
    // Ruta protegida: guarda geometría de edificio
    requerirAdmin();
    $archivo = __DIR__ . '/saveGeometria.php';
}else if($_GET['p']=='deleteEdificio'){
    // Ruta protegida: elimina edificio
    requerirAdmin();
    $archivo = __DIR__ . '/deleteEdificio.php';
}else if($_GET['p']=='editar-geometria'){
    // Ruta protegida: editor de geometrías (página completa)
    requerirAdmin();
    $archivo = 'PHP/admin/editar-geometria.php';
}else{
    $archivo = __DIR__ . '/404.php';
}

if (file_exists($archivo)) {
    include($archivo);
} else {
    $notFound = '404.php';
    if (file_exists($notFound)) {
        include($notFound);
    }
    http_response_code(404);
}
?>