# Servidor local temporal - Mapa Interactivo ITL
# Escucha ÚNICAMENTE en localhost (127.0.0.1). No exponer a Internet.

$host = "127.0.0.1"
$port = 8000

Write-Host "================================================" -ForegroundColor Cyan
Write-Host " Servidor PHP local - Mapa Interactivo ITL" -ForegroundColor Cyan
Write-Host "================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Iniciando servidor en http://$host:$port ..." -ForegroundColor Green
Write-Host ""
Write-Host "  Mapa principal : http://$host:$port/index.html" -ForegroundColor Green
Write-Host "  Panel admin    : http://$host:$port/index.php?p=editar" -ForegroundColor Green
Write-Host ""
Write-Host "Presiona Ctrl+C para detener el servidor." -ForegroundColor Yellow
Write-Host "Los errores y peticiones se registran en server.err" -ForegroundColor DarkGray
Write-Host ""
Write-Host "Protección: router.php bloquea backend, database, DUMP, scripts, tests y" -ForegroundColor DarkGray
Write-Host "respaldos/logs (.bak, .jsonl, .log). Solo para desarrollo local." -ForegroundColor DarkGray
Write-Host ""

php -S "$host`:$port" router.php 2> server.err
