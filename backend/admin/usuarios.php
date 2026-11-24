<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../auth/auth_middleware.php';
requerirAdmin();
$csrfToken = generarTokenCSRF();
$usuarioActual = $_SESSION['admin_user'] ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Usuarios - ITL Laguna</title>
<link rel="stylesheet" href="css/admin/panel.css">
<link rel="stylesheet" href="css/admin/usuarios.css">
</head>
<body>
<main class="usuarios-container">
    <div class="admin-header">
        <h1>Gestión de usuarios</h1>
        <div class="admin-actions">
            <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php?p=editar'">← Panel</button>
            <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php?p=cambiar-contrasena'">Cambiar mi contraseña</button>
            <button type="button" class="btn btn-primary" id="usuarios-nuevo">Nuevo usuario</button>
        </div>
    </div>

    <section class="usuarios-toolbar">
        <label for="usuarios-buscar">Buscar</label>
        <input id="usuarios-buscar" type="search" placeholder="Usuario o correo">
        <label for="usuarios-filtro">Estado</label>
        <select id="usuarios-filtro">
            <option value="">Todos</option>
            <option value="activo">Activos</option>
            <option value="inactivo">Inactivos</option>
            <option value="bloqueado">Bloqueados</option>
        </select>
    </section>

    <p id="usuarios-mensaje" class="usuarios-mensaje" role="status"></p>
    <div class="usuarios-table-wrap">
        <table class="usuarios-table">
            <thead>
                <tr><th>Usuario</th><th>Correo</th><th>Estado</th><th>Último acceso</th><th>Creación</th><th>Acciones</th></tr>
            </thead>
            <tbody id="usuarios-lista"></tbody>
        </table>
    </div>
</main>

<dialog id="usuarios-dialogo" class="usuarios-dialogo">
    <form id="usuarios-form" method="dialog" class="usuarios-form">
        <h2 id="usuarios-dialogo-titulo">Nuevo usuario</h2>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="id" value="">
        <label for="usuarios-username">Usuario</label>
        <input id="usuarios-username" name="username" pattern="[a-z0-9._-]{3,32}" maxlength="32" required>
        <label for="usuarios-email">Correo (opcional)</label>
        <input id="usuarios-email" name="email" type="email" maxlength="254">
        <p id="usuarios-form-error" class="usuarios-alert usuarios-alert-error"></p>
        <div class="usuarios-form-actions">
            <button type="submit" class="btn btn-primary" id="usuarios-form-submit">Crear</button>
            <button type="button" class="btn btn-secondary" id="usuarios-form-cancelar">Cancelar</button>
        </div>
    </form>
</dialog>

<dialog id="usuarios-temporal" class="usuarios-dialogo">
    <div class="usuarios-form">
        <h2>Contraseña temporal</h2>
        <p>Se muestra una sola vez. Copie la contraseña antes de cerrar.</p>
        <code id="usuarios-password-temporal"></code>
        <div class="usuarios-form-actions">
            <button type="button" class="btn btn-primary" id="usuarios-copiar">Copiar</button>
            <button type="button" class="btn btn-secondary" id="usuarios-temporal-cerrar">Cerrar</button>
        </div>
    </div>
</dialog>

<script>
window.USUARIOS_ADMIN = {
    csrf: <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>,
    usuarioActualId: <?= json_encode((string) ($usuarioActual['id'] ?? ''), JSON_UNESCAPED_SLASHES) ?>,
    urls: {
        get: 'index.php?p=getUsuarios',
        save: 'index.php?p=saveUsuario',
        estado: 'index.php?p=setEstadoUsuario',
        reset: 'index.php?p=resetPasswordUsuario',
        eliminar: 'index.php?p=deleteUsuario'
    }
};
</script>
<script src="js/admin/usuarios-admin.js"></script>
</body>
</html>
