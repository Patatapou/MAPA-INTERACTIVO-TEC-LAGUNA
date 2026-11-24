$ErrorActionPreference = "Stop"

$serverHost = "127.0.0.1"
$serverPort = 8000
$baseUrl = "http://${serverHost}:${serverPort}"
$loginUrl = "${baseUrl}/index.php?p=login"
$root = $PSScriptRoot
$runtime = Join-Path $root "database\runtime"
$backups = Join-Path $root "database\backups"

foreach ($directory in @($runtime, $backups)) {
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
        throw "Falta la carpeta local '$directory'. Ejecuta primero: php scripts\instalar.php"
    }
}

$phpCommand = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpCommand) {
    throw "No se encontró PHP en PATH. Instala PHP CLI y vuelve a intentarlo."
}

function Test-AdminLogin {
    param([string]$Url)

    try {
        $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 2
        return $response.Content -match '<title>Login Admin ITL</title>'
    } catch {
        return $false
    }
}

$tcp = New-Object System.Net.Sockets.TcpClient
try {
    $connect = $tcp.BeginConnect($serverHost, $serverPort, $null, $null)
    $conectado = $connect.AsyncWaitHandle.WaitOne(300)
    if ($conectado) {
        try {
            $tcp.EndConnect($connect)
        } catch {
            $conectado = $false
        }
    }
    $connect.AsyncWaitHandle.Close()
    if ($conectado) {
        if (Test-AdminLogin -Url $loginUrl) {
            Write-Host "El panel local ya está iniciado. Abriendo el login..." -ForegroundColor Green
            Start-Process $loginUrl
            exit 0
        }
        throw "El puerto $serverPort ya está ocupado por otro servicio. Cierra ese proceso o configura un puerto diferente."
    }
} finally {
    $tcp.Close()
}

$frontend = Join-Path $root "frontend"
$router = Join-Path $root "router.php"
$serverOut = Join-Path $root "server.out"
$serverErr = Join-Path $root "server.err"
$arguments = "-S ${serverHost}:${serverPort} -t `"$frontend`" `"$router`""
$server = Start-Process -FilePath $phpCommand.Source `
    -ArgumentList $arguments `
    -WorkingDirectory $root `
    -WindowStyle Hidden `
    -RedirectStandardOutput $serverOut `
    -RedirectStandardError $serverErr `
    -PassThru

try {
    $listo = $false
    for ($attempt = 0; $attempt -lt 100; $attempt++) {
        if ($server.HasExited) {
            throw "PHP terminó antes de iniciar el panel. Revisa '$serverErr'."
        }
        if (Test-AdminLogin -Url $loginUrl) {
            $listo = $true
            break
        }
        Start-Sleep -Milliseconds 250
    }

    if (-not $listo) {
        throw "El panel no respondió dentro del tiempo esperado. Revisa '$serverErr'."
    }

    Write-Host "Panel local iniciado en $loginUrl" -ForegroundColor Green
    Write-Host "El servidor permanece activo mientras esta ventana esté abierta." -ForegroundColor Yellow
    Write-Host "Presiona Ctrl+C para detenerlo." -ForegroundColor Yellow
    Start-Process $loginUrl
    $server.WaitForExit()
} finally {
    if (-not $server.HasExited) {
        Stop-Process -Id $server.Id
        $server.WaitForExit()
    }
}
