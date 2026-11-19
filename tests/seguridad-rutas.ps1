<#
    Pruebas de seguridad del módulo de rutas (plan, 15.3 / 16.7).

    Arranca un servidor PHP propio en el puerto 8019, ejecuta las
    comprobaciones S-01 a S-09 y lo detiene. Crea y elimina una ruta de
    prueba; no borra ni modifica rutas existentes.

    Uso:
        powershell -NoProfile -ExecutionPolicy Bypass -File tests/seguridad-rutas.ps1

    Nota: los eventos de la ruta de prueba quedan en `PHP/data/audit-log.jsonl`
    (registro append-only por diseño).
#>

$ErrorActionPreference = 'Continue'

$raiz = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$base = 'http://127.0.0.1:8019'
$puerto = 8019

$job = Start-Job -ScriptBlock {
    param($dir, $p)
    Set-Location $dir
    php -S "127.0.0.1:$p" -t frontend router.php 2>$null
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

$idCreada = $null

try {
    # ---------- S-01: sin sesión ----------
    $anon = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $r = Llamar 'GET' "$base/index.php?p=getRutas" $anon $null @{ 'X-Requested-With' = 'XMLHttpRequest' }
    Comprobar 'S-01' 'getRutas sin sesión' 403 $r.code $r.body

    $anonNav = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $r = Llamar 'GET' "$base/index.php?p=rutas" $anonNav
    Comprobar 'S-01' 'vista de rutas sin sesión redirige' 302 $r.code ''

    # ---------- Login ----------
    $ses = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Llamar 'GET' "$base/index.php?p=login" $ses
    $token = [regex]::Match($login.body, 'name="csrf_token" value="([^"]+)"').Groups[1].Value
    $entrada = Llamar 'POST' "$base/index.php?p=login" $ses @{ csrf_token = $token; usuario = 'admin'; password = '123456' }
    Comprobar 'S-00' 'login de administrador' 302 $entrada.code ''
    $token = ((Llamar 'GET' "$base/index.php?p=check-session" $ses).body | ConvertFrom-Json).csrf_token

    $red = (Llamar 'GET' "$base/index.php?p=getRedCaminos" $ses).body | ConvertFrom-Json
    $segmento = $red.segmentos[0]

    # ---------- S-02: POST sin CSRF ----------
    $r = Llamar 'POST' "$base/index.php?p=saveRuta" $ses @{
        nombre    = 'Ruta sin CSRF'
        tipo      = 'principal'
        estado    = 'borrador'
        accesible = '1'
        origen    = '{"tipo":"edificio","ref":"5"}'
        destino   = '{"tipo":"edificio","ref":"6"}'
        segmentos = ConvertTo-Json @(@{ id = $segmento.id; sentido = 'normal' }) -Compress
    }
    Comprobar 'S-02' 'saveRuta sin token CSRF' 403 $r.code $r.body

    # ---------- S-03: método incorrecto ----------
    $r = Llamar 'GET' "$base/index.php?p=saveRuta" $ses
    Comprobar 'S-03' 'GET en saveRuta' 405 $r.code $r.body

    # ---------- S-04: IDs y enums inválidos ----------
    $r = Llamar 'GET' "$base/index.php?p=getRutas&id=../../etc/passwd" $ses
    Comprobar 'S-04' 'ID con path traversal' 400 $r.code $r.body

    $r = Llamar 'POST' "$base/index.php?p=saveRuta" $ses @{
        csrf_token = $token
        nombre     = 'Ruta con tipo inválido'
        tipo       = 'subterranea'
        estado     = 'borrador'
        accesible  = '1'
        origen     = '{"tipo":"edificio","ref":"5"}'
        destino    = '{"tipo":"edificio","ref":"6"}'
        segmentos  = ConvertTo-Json @(@{ id = $segmento.id; sentido = 'normal' }) -Compress
    }
    Comprobar 'S-04' 'enum de tipo inválido' 400 $r.code $r.body

    $r = Llamar 'POST' "$base/index.php?p=saveRuta" $ses @{
        csrf_token = $token
        nombre     = 'Ruta con sentido inválido'
        tipo       = 'principal'
        estado     = 'borrador'
        accesible  = '1'
        origen     = '{"tipo":"edificio","ref":"5"}'
        destino    = '{"tipo":"edificio","ref":"6"}'
        segmentos  = ConvertTo-Json @(@{ id = $segmento.id; sentido = 'diagonal' }) -Compress
    }
    Comprobar 'S-04' 'sentido inválido' 400 $r.code $r.body

    $r = Llamar 'POST' "$base/index.php?p=saveRuta" $ses @{
        csrf_token = $token
        nombre     = 'Ruta con segmentos no JSON'
        tipo       = 'principal'
        estado     = 'borrador'
        accesible  = '1'
        origen     = '{"tipo":"edificio","ref":"5"}'
        destino    = '{"tipo":"edificio","ref":"6"}'
        segmentos  = 'no-es-json'
    }
    Comprobar 'S-04' 'segmentos malformados' 400 $r.code $r.body

    # ---------- S-05: la geometría del cliente se ignora ----------
    $falsa = '[[0,0],[1,1],[2,2]]'
    $r = Llamar 'POST' "$base/index.php?p=saveRuta" $ses @{
        csrf_token = $token
        nombre     = 'Ruta con geometría falsa'
        tipo       = 'principal'
        estado     = 'borrador'
        accesible  = '1'
        origen     = '{"tipo":"edificio","ref":"5"}'
        destino    = '{"tipo":"edificio","ref":"6"}'
        segmentos  = ConvertTo-Json @(@{ id = $segmento.id; sentido = 'normal' }) -Compress
        geometria  = $falsa
        distancia_m = '-1'
    }
    Comprobar 'S-05' 'alta con geometría inyectada' 200 $r.code $r.body

    $creada = $r.body | ConvertFrom-Json
    $idCreada = $creada.ruta.id
    $coordenadas = $creada.ruta.geometria.coordinates
    $primera = $coordenadas[0]
    $real = $segmento.coordenadas[0]
    $sinGeometriaFalsa = ($coordenadas.Count -eq $segmento.coordenadas.Count) -and
        ([math]::Abs([double]$primera[0] - [double]$real[0]) -lt 0.0000001) -and
        ([math]::Abs([double]$primera[1] - [double]$real[1]) -lt 0.0000001)
    Comprobar 'S-05' 'la geometría proviene del servidor' $true $sinGeometriaFalsa ("puntos=" + $coordenadas.Count)
    Comprobar 'S-05' 'la distancia se recalcula' $true ([double]$creada.ruta.distancia_m -gt 0) ("distancia=" + $creada.ruta.distancia_m)

    # ---------- S-06: auditoría ----------
    $auditoria = Join-Path $raiz 'database/runtime/audit-log.jsonl'
    $evento = $null
    if (Test-Path $auditoria) {
        $evento = Select-String -Path $auditoria -Pattern ('"ruta_creada".*' + [regex]::Escape($idCreada)) | Select-Object -Last 1
    }
    Comprobar 'S-06' 'evento ruta_creada en audit-log' $true ($null -ne $evento) ''

    # ---------- S-08: datos privados no accesibles por HTTP ----------
    $r = Llamar 'GET' "$base/database/runtime/rutas.json" $anonNav
    Comprobar 'S-08' 'GET /database/runtime/rutas.json' 403 $r.code $r.body

    $r = Llamar 'GET' "$base/database/runtime/users.json" $anonNav
    Comprobar 'S-08' 'GET /database/runtime/users.json' 403 $r.code $r.body

    # ---------- S-09: errores sin trazas ni rutas de archivo ----------
    $r = Llamar 'POST' "$base/index.php?p=saveRuta" $ses @{
        csrf_token = $token
        nombre     = ''
        tipo       = ''
        estado     = ''
        accesible  = '1'
        origen     = 'no-json'
        destino    = '{"tipo":"edificio","ref":"6"}'
        segmentos  = 'no-json'
    }
    $filtraTrazas = ($r.body -match '(?i)(C:\\\\|\.php|stack trace|Fatal error|Warning:)') -eq $false
    Comprobar 'S-09' 'errores sin trazas ni rutas' $true $filtraTrazas $r.body

    # ---------- Limpieza ----------
    if ($idCreada) {
        $borrado = Llamar 'POST' "$base/index.php?p=deleteRuta" $ses @{ csrf_token = $token; id = $idCreada }
        Comprobar 'Limpieza' 'eliminar ruta de prueba' 200 $borrado.code $borrado.body
    }
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
