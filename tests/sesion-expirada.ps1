<#
    Verifica la expiración de sesión por inactividad (plan, S-10).

    Crea a mano dos archivos de sesión de PHP (uno caducado y otro vigente)
    y comprueba cómo responde el servidor con cada cookie. No modifica datos.

    Uso:
        powershell -NoProfile -ExecutionPolicy Bypass -File tests/sesion-expirada.ps1
#>

$ErrorActionPreference = 'Continue'

$raiz = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$base = 'http://127.0.0.1:8020'
$puerto = 8020
$tmp = [System.IO.Path]::GetTempPath()

$sidCaducada = 'pruebamapacaducada123456'
$sidVigente = 'pruebamapavigente1234567'

$ahora = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()

function EscribirSesion($sid, $authTime) {
    $camino = Join-Path $tmp "sess_$sid"
    $contenido = "admin|b:1;auth_time|i:$authTime;"
    Set-Content -Path $camino -Value $contenido -NoNewline -Encoding ascii
    return $camino
}

$archivoCaducado = EscribirSesion $sidCaducada ($ahora - 7300)
$archivoVigente = EscribirSesion $sidVigente ($ahora - 60)

$job = Start-Job -ScriptBlock {
    param($dir, $p)
    Set-Location $dir
    php -S "127.0.0.1:$p" router.php 2>$null
} -ArgumentList $raiz, $puerto
Start-Sleep -Seconds 3

function Llamar($url, $sid, $ajax) {
    # `-Headers @{ Cookie = ... }` no funciona con Invoke-WebRequest: la cookie
    # debe viajar en una WebRequestSession. Se crea una sesión nueva por llamada
    # para que un `Set-Cookie` del servidor no altere la cookie bajo prueba.
    $ses = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $cookie = New-Object System.Net.Cookie('PHPSESSID', $sid, '/', '127.0.0.1')
    $ses.Cookies.Add($cookie)

    $params = @{
        Uri                = $url
        Method             = 'GET'
        UseBasicParsing    = $true
        MaximumRedirection = 0
        WebSession         = $ses
    }
    if ($ajax) { $params['Headers'] = @{ 'X-Requested-With' = 'XMLHttpRequest' } }
    try {
        $r = Invoke-WebRequest @params
        return @{ code = [int]$r.StatusCode; body = $r.Content }
    } catch {
        $resp = $_.Exception.Response
        if ($null -ne $resp) {
            $contenido = ''
            try {
                $lector = New-Object System.IO.StreamReader($resp.GetResponseStream())
                $contenido = $lector.ReadToEnd()
            } catch { }
            return @{ code = [int]$resp.StatusCode; body = $contenido }
        }
        return @{ code = 'ERR'; body = $_.Exception.Message }
    }
}

$resultados = New-Object System.Collections.Generic.List[string]
function Comprobar($nombre, $esperado, $obtenido, $detalle) {
    $ok = ($esperado -eq $obtenido)
    $marca = if ($ok) { 'OK   ' } else { 'FALLO' }
    $resumen = ($detalle -replace '\s+', ' ')
    if ($resumen.Length -gt 80) { $resumen = $resumen.Substring(0, 80) }
    $resultados.Add("$marca $nombre -> esperado $esperado, obtenido $obtenido | $resumen")
}

try {
    $r = Llamar "$base/index.php?p=getRutas" $sidCaducada $true
    Comprobar 'S-10 sesión caducada (AJAX) devuelve 403' 403 $r.code $r.body

    $r = Llamar "$base/index.php?p=getRutas" $sidVigente $true
    Comprobar 'S-10 sesión vigente devuelve 200' 200 $r.code $r.body

    $r = Llamar "$base/index.php?p=rutas" $sidVigente $false
    Comprobar 'S-10 sesión vigente abre el módulo' 200 $r.code ''

    # Tras la expiración, el middleware destruye la sesión: una segunda
    # petición con la misma cookie ya no está autenticada.
    $r = Llamar "$base/index.php?p=getRutas" $sidCaducada $true
    Comprobar 'S-10 la sesión caducada queda destruida' 403 $r.code $r.body
}
finally {
    $resultados | ForEach-Object { Write-Host $_ }
    $fallos = @($resultados | Where-Object { $_ -like 'FALLO*' }).Count
    Write-Host ''
    if ($fallos -eq 0) { Write-Host 'TODO OK' } else { Write-Host "HAY $fallos FALLOS" }
    Stop-Job $job -ErrorAction SilentlyContinue
    Remove-Job $job -Force -ErrorAction SilentlyContinue
    foreach ($archivo in @($archivoCaducado, $archivoVigente)) {
        if (Test-Path $archivo) { Remove-Item $archivo -Force -ErrorAction SilentlyContinue }
    }
    exit $(if ($fallos -eq 0) { 0 } else { 1 })
}
