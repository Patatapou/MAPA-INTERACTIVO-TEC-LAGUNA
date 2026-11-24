# Servidor local temporal - Mapa Interactivo ITL
# Escucha UNICAMENTE en localhost (127.0.0.1). No exponer a Internet.

$serverHost = "127.0.0.1"
$serverPort = 8000
$baseUrl    = "http://${serverHost}:${serverPort}"

Write-Host "================================================" -ForegroundColor Cyan
Write-Host " Servidor PHP local - Mapa Interactivo ITL" -ForegroundColor Cyan
Write-Host "================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Iniciando servidor en $baseUrl ..." -ForegroundColor Green
Write-Host ""
Write-Host "  Mapa principal : $baseUrl/index.html" -ForegroundColor Green
Write-Host "  Panel admin    : $baseUrl/index.php?p=login" -ForegroundColor Green
Write-Host ""
Write-Host "Presiona Ctrl+C para detener el servidor." -ForegroundColor Yellow
Write-Host "Los errores y peticiones se registran en server.err" -ForegroundColor DarkGray
Write-Host ""
Write-Host "Proteccion: router.php bloquea backend, database, DUMP, scripts, tests y" -ForegroundColor DarkGray
Write-Host "respaldos/logs (.bak, .jsonl, .log). Solo para desarrollo local." -ForegroundColor DarkGray
Write-Host ""
Write-Host "Document root: frontend/ (backend/ y database/ no se sirven por HTTP)" -ForegroundColor DarkGray
Write-Host ""

php -S "${serverHost}:${serverPort}" -t frontend router.php 2> server.err