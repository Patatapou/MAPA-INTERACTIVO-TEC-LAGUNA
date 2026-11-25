param(
    [ValidateRange(1, 65535)]
    [int]$ServerPort = 8000,
    [switch]$NoBrowser
)

$ErrorActionPreference = "Stop"

$serverHost = "127.0.0.1"
$baseUrl = "http://${serverHost}:${ServerPort}"
$loginUrl = "${baseUrl}/index.php?p=login"
$root = $PSScriptRoot
$runtime = Join-Path $root "database\runtime"
$backups = Join-Path $root "database\backups"
$frontend = Join-Path $root "frontend"
$router = Join-Path $root "router.php"
$serverOut = Join-Path $root "server.out"
$serverErr = Join-Path $root "server.err"
$signalPath = Join-Path ([System.IO.Path]::GetTempPath()) "itl-admin-server-$ServerPort.signal"
$logoutSignalPath = "$signalPath.logout"
$server = $null
$startedHere = $false

function Test-AdminLogin {
    param([string]$Url)

    try {
        $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 2
        return $response.Content -match '<title>Login Admin ITL</title>'
    } catch {
        return $false
    }
}

function Get-ProjectServerProcess {
    $frontendPath = [regex]::Escape($frontend)
    $routerPath = [regex]::Escape($router)
    $listenPattern = '(?i)-S\s+127\.0\.0\.1:' + $ServerPort + '(?:\s|$)'
    $processes = @(
        Get-CimInstance -ClassName Win32_Process -Filter "Name='php.exe'" |
            Where-Object {
                $_.CommandLine -match $frontendPath -and
                $_.CommandLine -match $routerPath -and
                $_.CommandLine -match $listenPattern
            }
    )

    if ($processes.Count -ne 1) {
        return $null
    }

    return Get-Process -Id ([int]$processes[0].ProcessId) -ErrorAction Stop
}

function Initialize-ServerSignals {
    [System.IO.File]::WriteAllText($signalPath, 'heartbeat', [System.Text.Encoding]::ASCII)
    if (Test-Path -LiteralPath $logoutSignalPath) {
        Remove-Item -LiteralPath $logoutSignalPath
    }
}

function Stop-ServerProcess {
    param([System.Diagnostics.Process]$Process)

    $Process.Refresh()
    if (-not $Process.HasExited) {
        try {
            Stop-Process -Id $Process.Id -ErrorAction Stop
        } catch {
            if (Get-Process -Id $Process.Id -ErrorAction SilentlyContinue) {
                throw
            }
        }
        $Process.WaitForExit(5000) | Out-Null
    }
}

function Wait-ForAdminServer {
    param([System.Diagnostics.Process]$Process)

    $lastHeartbeat = [DateTime]::UtcNow
    $disconnectSince = $null

    while ($true) {
        $Process.Refresh()
        if ($Process.HasExited) {
            return
        }

        $now = [DateTime]::UtcNow
        if (Test-Path -LiteralPath $logoutSignalPath) {
            $logoutAt = (Get-Item -LiteralPath $logoutSignalPath).LastWriteTimeUtc
            if (($now - $logoutAt).TotalSeconds -ge 3) {
                Stop-ServerProcess -Process $Process
                return
            }
        }

        if (Test-Path -LiteralPath $signalPath) {
            $signal = [System.IO.File]::ReadAllText($signalPath)
            if ($signal -eq 'heartbeat') {
                $lastHeartbeat = (Get-Item -LiteralPath $signalPath).LastWriteTimeUtc
                $disconnectSince = $null
            } elseif ($signal -eq 'disconnect') {
                if ($null -eq $disconnectSince) {
                    $disconnectSince = $now
                } elseif (($now - $disconnectSince).TotalSeconds -ge 8) {
                    Stop-ServerProcess -Process $Process
                    return
                }
            }
        }

        if (($now - $lastHeartbeat).TotalSeconds -ge 30) {
            Stop-ServerProcess -Process $Process
            return
        }

        Start-Sleep -Milliseconds 500
    }
}

try {
    foreach ($directory in @($runtime, $backups)) {
        if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
            throw "Falta la carpeta local '$directory'. Ejecuta primero: php scripts\instalar.php"
        }
    }

    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if (-not $phpCommand) {
        throw "No se encontró PHP en PATH. Instala PHP CLI y vuelve a intentarlo."
    }

    $tcp = New-Object System.Net.Sockets.TcpClient
    try {
        $connect = $tcp.BeginConnect($serverHost, $ServerPort, $null, $null)
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
            if (-not (Test-AdminLogin -Url $loginUrl)) {
                throw "El puerto $ServerPort ya está ocupado por otro servicio."
            }

            $server = Get-ProjectServerProcess
            if ($null -eq $server) {
                throw "No se pudo identificar el proceso PHP del proyecto en el puerto $ServerPort."
            }
            Initialize-ServerSignals
        } else {
            Initialize-ServerSignals
            $arguments = "-S ${serverHost}:${ServerPort} -t `"$frontend`" `"$router`""
            $server = Start-Process -FilePath $phpCommand.Source `
                -ArgumentList $arguments `
                -WorkingDirectory $root `
                -WindowStyle Hidden `
                -RedirectStandardOutput $serverOut `
                -RedirectStandardError $serverErr `
                -PassThru
            $startedHere = $true

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
        }
    } finally {
        $tcp.Close()
    }

    if (-not $NoBrowser) {
        Start-Process $loginUrl
    }

    Wait-ForAdminServer -Process $server
} catch {
    $mensaje = $_.Exception.Message
    if ($startedHere -and $null -ne $server) {
        Stop-ServerProcess -Process $server
    }

    if ($NoBrowser) {
        Write-Error $mensaje
        exit 1
    }

    try {
        Add-Type -AssemblyName System.Windows.Forms
        [System.Windows.Forms.MessageBox]::Show(
            $mensaje,
            "Panel Admin ITL",
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Error
        ) | Out-Null
    } catch {
        Write-Error $mensaje
    }
    exit 1
} finally {
    if ($null -ne $server) {
        $server.Refresh()
        if ($server.HasExited) {
            if (Test-Path -LiteralPath $signalPath) {
                Remove-Item -LiteralPath $signalPath
            }
            if (Test-Path -LiteralPath $logoutSignalPath) {
                Remove-Item -LiteralPath $logoutSignalPath
            }
        }
    }
}
