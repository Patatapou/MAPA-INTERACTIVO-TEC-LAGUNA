<?php
// ==========================================
// 404 — RUTA NO ENCONTRADA
// ==========================================
// Se incluye desde `backend/router.php` cuando `?p=` no coincide con
// ninguna ruta conocida. El router ya fijó el código HTTP 404.

$esAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if ($esAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Ruta no encontrada.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Página no encontrada — Mapa Interactivo ITL</title>
<style>
    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        background: #f1f5f9;
        color: #1e293b;
    }
    .caja {
        max-width: 520px;
        padding: 2.5rem;
        text-align: center;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, .12);
    }
    h1 { margin: 0 0 .5rem; font-size: 3rem; color: #12306b; }
    p { margin: 0 0 1.5rem; line-height: 1.5; }
    a {
        display: inline-block;
        padding: .7rem 1.4rem;
        border-radius: 8px;
        background: #12306b;
        color: #fff;
        text-decoration: none;
        font-weight: 600;
    }
    a:hover { background: #0b57d0; }
</style>
</head>
<body>
    <div class="caja">
        <h1>404</h1>
        <p>La página que buscas no existe o la dirección está mal escrita.</p>
        <a href="index.html">← Volver al mapa</a>
    </div>
</body>
</html>
