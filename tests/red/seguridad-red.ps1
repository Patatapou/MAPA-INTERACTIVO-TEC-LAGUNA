<#
    Pruebas de seguridad del editor de red de caminos (E5.3).

    Arranca un servidor PHP propio en el puerto 8021 y comprueba:
      - rutas protegidas sin sesión (403 / redirección al login),
      - CSRF obligatorio en `save-red`,
      - método incorrecto (405),
      - revisión obsoleta (409) sin sobrescribir,
      - estructura inválida (400) y petición sobredimensionada (400),
      - `PHP/data/`, respaldos y `.bak` no accesibles por HTTP,
      - respuestas de error sin trazas ni rutas de archivo.

    No guarda ninguna red: todas las peticiones de escritura son rechazadas,
    así que `caminitos.geojson` y su revisión quedan intactos.

    Uso:
        powershell -NoProfile -ExecutionPolicy Bypass -File tests/red/seguridad-red.ps1
#>

$ErrorActionPreference = 'Continue'

$raiz = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path))
$base = 'http://127.0.0.1:8021'
$puerto = 8021

$job = Start-Job -ScriptBlock {
    param($dir, $p)
    Set-Location $dir
    php -S "127.0.0.1:$p" router.php 2>$null
} -ArgumentList $raiz, $puerto
Start-Sleep -Seconds 3

function Llamar($method, $url, $session, $body, $headers) {
    $params = @{
        Uri                = $url
        Method             = $method
        UseBasicParsing    = $true
        MaximumRedirection = 0
        WebSession         = $session
    }
    if ($body) { $params['Body'] = $body }
    if ($headers) { $params['Headers'] = $headers }
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
function Comprobar($id, $nombre, $esperado, $obtenido, $detalle) {
    $ok = ($esperado -eq $obtenido)
    $marca = if ($ok) { 'OK   ' } else { 'FALLO' }
    $resumen = ($detalle -replace '\s+', ' ')
    if ($resumen.Length -gt 90) { $resumen = $resumen.Substring(0, 90) }
    $resultados.Add("$marca $id $nombre -> esperado $esperado, obtenido $obtenido | $resumen")
}

try {
    # ---------- S-01: sin sesión ----------
    $anon = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $r = Llamar 'GET' "$base/index.php?p=getRed" $anon $null @{ 'X-Requested-With' = 'XMLHttpRequest' }
    Comprobar 'S-01' 'getRed sin sesión' 403 $r.code $r.body

    $anonNav = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $r = Llamar 'GET' "$base/index.php?p=editar-red" $anonNav
    Comprobar 'S-01' 'vista del editor sin sesión redirige' 302 $r.code ''

    $r = Llamar 'POST' "$base/index.php?p=save-red" $anonNav @{ csrf_token = 'x'; base_revision = '1'; geojson = '{}' } @{ 'X-Requested-With' = 'XMLHttpRequest' }
    Comprobar 'S-01' 'save-red sin sesión (AJAX)' 403 $r.code $r.body

    # ---------- Login ----------
    $ses = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Llamar 'GET' "$base/index.php?p=login" $ses
    $token = [regex]::Match($login.body, 'name="csrf_token" value="([^"]+)"').Groups[1].Value
    $entrada = Llamar 'POST' "$base/index.php?p=login" $ses @{ csrf_token = $token; usuario = 'admin'; password = '123456' }
    Comprobar 'S-00' 'login de administrador' 302 $entrada.code ''
    $token = ((Llamar 'GET' "$base/index.php?p=check-session" $ses).body | ConvertFrom-Json).csrf_token

    $estado = (Llamar 'GET' "$base/index.php?p=getRed" $ses).body | ConvertFrom-Json
    Comprobar 'S-00' 'getRed devuelve la red vigente' $true ([bool]$estado.success) $estado.message
    $revision = [int]$estado.revision
    $total = @($estado.geojson.features).Count

    # ---------- R-01: POST sin CSRF ----------
    $r = Llamar 'POST' "$base/index.php?p=save-red" $ses @{
        base_revision = "$revision"
        geojson       = ($estado.geojson | ConvertTo-Json -Depth 20 -Compress)
    }
    Comprobar 'R-01' 'save-red sin token CSRF' 403 $r.code $r.body

    # ---------- R-02: método incorrecto ----------
    $r = Llamar 'GET' "$base/index.php?p=save-red" $ses
    Comprobar 'R-02' 'GET en save-red' 405 $r.code $r.body

    # ---------- R-03: falta base_revision ----------
    $r = Llamar 'POST' "$base/index.php?p=save-red" $ses @{
        csrf_token = $token
        geojson    = ($estado.geojson | ConvertTo-Json -Depth 20 -Compress)
    }
    Comprobar 'R-03' 'save-red sin base_revision' 400 $r.code $r.body

    # ---------- R-04: geojson ilegible ----------
    $r = Llamar 'POST' "$base/index.php?p=save-red" $ses @{
        csrf_token    = $token
        base_revision = "$revision"
        geojson       = 'no-es-json'
    }
    Comprobar 'R-04' 'geojson que no es JSON' 400 $r.code $r.body

    # ---------- R-05: estructura inválida (E02) ----------
    $invalida = @{
        type     = 'FeatureCollection'
        features = @(
            @{
                type       = 'Feature'
                properties = @{ id = 'tmp-1' }
                geometry   = @{ type = 'Point'; coordinates = @(-103.436, 25.53) }
            }
        )
    } | ConvertTo-Json -Depth 10 -Compress

    $r = Llamar 'POST' "$base/index.php?p=save-red" $ses @{
        csrf_token    = $token
        base_revision = "$revision"
        geojson       = $invalida
    }
    $incluyeE02 = ($r.body -match '"codigo":"E02"')
    Comprobar 'R-05' 'geometría inválida rechazada' 400 $r.code $r.body
    Comprobar 'R-05' 'la respuesta identifica E02' $true $incluyeE02 $r.body

    # ---------- R-06: revisión obsoleta ----------
    $r = Llamar 'POST' "$base/index.php?p=save-red" $ses @{
        csrf_token    = $token
        base_revision = "$($revision + 5)"
        geojson       = ($estado.geojson | ConvertTo-Json -Depth 20 -Compress)
    }
    Comprobar 'R-06' 'revisión desfasada' 409 $r.code $r.body

    # ---------- R-07: petición sobredimensionada ----------
    $enorme = 'x' * 2200000
    $r = Llamar 'POST' "$base/index.php?p=save-red" $ses @{
        csrf_token    = $token
        base_revision = "$revision"
        geojson       = $enorme
    }
    Comprobar 'R-07' 'cuerpo de más de 2 MB' 400 $r.code ('HTTP ' + $r.code)

    # ---------- R-08: la red real no cambió ----------
    $despues = (Llamar 'GET' "$base/index.php?p=getRed" $ses).body | ConvertFrom-Json
    Comprobar 'R-08' 'la revisión no cambió' $revision ([int]$despues.revision) ''
    Comprobar 'R-08' 'el número de segmentos no cambió' $total (@($despues.geojson.features).Count) ''

    # ---------- S-08: datos privados y respaldos fuera de HTTP ----------
    $r = Llamar 'GET' "$base/database/runtime/users.json" $anonNav
    Comprobar 'S-08' 'GET /database/runtime/users.json' 403 $r.code $r.body

    $r = Llamar 'GET' "$base/database/backups/caminitos/" $anonNav
    Comprobar 'S-08' 'GET respaldos de la red' 403 $r.code $r.body

    $r = Llamar 'GET' "$base/DUMP/caminitos.geojson.bak" $anonNav
    Comprobar 'S-08' 'GET respaldo .bak de la red' 403 $r.code $r.body

    # ---------- S-09: errores sin trazas ----------
    $sinTrazas = ($r.body -match '(?i)(C:\\\\|stack trace|Fatal error|Warning:)') -eq $false
    Comprobar 'S-09' 'el 403 no filtra rutas ni trazas' $true $sinTrazas $r.body
}
finally {
    $resultados | ForEach-Object { Write-Host $_ }
    $fallos = @($resultados | Where-Object { $_ -like 'FALLO*' }).Count
    Write-Host ''
    if ($fallos -eq 0) { Write-Host 'TODO OK' } else { Write-Host "HAY $fallos FALLOS" }
    Stop-Job $job -ErrorAction SilentlyContinue
    Remove-Job $job -Force -ErrorAction SilentlyContinue
    exit $(if ($fallos -eq 0) { 0 } else { 1 })
}
