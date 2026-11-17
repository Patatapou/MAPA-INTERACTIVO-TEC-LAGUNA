$ErrorActionPreference = 'Continue'
$raiz = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$base = 'http://127.0.0.1:8000'

function Llamar($method, $url, $session, $body, $headers) {
    $params = @{ Uri = $url; Method = $method; UseBasicParsing = $true; MaximumRedirection = 0; WebSession = $session }
    if ($body) { $params['Body'] = $body }
    if ($headers) { $params['Headers'] = $headers }
    try {
        $r = Invoke-WebRequest @params
        return @{ code = [int]$r.StatusCode; body = $r.Content }
    } catch {
        $resp = $_.Exception.Response
        if ($null -ne $resp) {
            $contenido = ''
            try { $contenido = (New-Object System.IO.StreamReader($resp.GetResponseStream())).ReadToEnd() } catch { }
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
    if ($resumen.Length -gt 70) { $resumen = $resumen.Substring(0, 70) }
    $resultados.Add("$marca $nombre -> esperado $esperado, obtenido $obtenido | $resumen")
}

$datosAntes = Get-FileHash (Join-Path $raiz 'datos.json') -Algorithm SHA256
$geoTecAntes = Get-FileHash (Join-Path $raiz 'GeoTec.js') -Algorithm SHA256

# ---------- Mapa público ----------
$anon = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$r = Llamar 'GET' "$base/index.html" $anon
Comprobar 'R-01 index.html responde 200' 200 $r.code ''
Comprobar 'R-02 index.html carga Leaflet' $true ($r.body -match 'leaflet@1\.9\.4') ''
Comprobar 'R-03 index.html carga el router del mapa' $true ($r.body -match 'js/main\.js') ''
Comprobar 'R-04 index.html conserva el botón de acceso admin' $true ($r.body -match 'btn-admin|admin') ''

foreach ($recurso in @('datos.json', 'GeoTec.js', 'caminitos.geojson', 'js/main.js', 'js/pathfinding.js', 'js/beacons-simulation.js')) {
    $r = Llamar 'GET' "$base/$recurso" $anon
    Comprobar "R-05 $recurso responde 200" 200 $r.code ''
}

# ---------- Datos intactos ----------
$datos = Get-Content (Join-Path $raiz 'datos.json') -Raw | ConvertFrom-Json
$entradas = @($datos.PSObject.Properties).Count
Comprobar 'R-06 datos.json conserva sus entradas' 58 $entradas ''
$geo = Get-Content (Join-Path $raiz 'caminitos.geojson') -Raw | ConvertFrom-Json
Comprobar 'R-07 caminitos.geojson conserva los segmentos' 313 @($geo.features).Count ''

# ---------- CRUD de edificios sigue protegido ----------
$ses = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$login = Llamar 'GET' "$base/index.php?p=login" $ses
$token = [regex]::Match($login.body, 'name="csrf_token" value="([^"]+)"').Groups[1].Value
Llamar 'POST' "$base/index.php?p=login" $ses @{ csrf_token = $token; usuario = 'admin'; password = '123456' } | Out-Null
$token = ((Llamar 'GET' "$base/check-session.php" $ses).body | ConvertFrom-Json).csrf_token

$r = Llamar 'GET' "$base/index.php?p=editar-geometria&accion=nuevo" $ses
Comprobar 'R-08 editor de geometría con sesión' 200 $r.code ''

$r = Llamar 'GET' "$base/index.php?p=editar" $ses
Comprobar 'R-09 panel con sesión' 200 $r.code ''

$r = Llamar 'POST' "$base/index.php?p=saveGeometria" $ses @{ nombre = 'Sin CSRF' }
Comprobar 'R-10 saveGeometria sin CSRF devuelve 403' 403 $r.code $r.body

$r = Llamar 'POST' "$base/index.php?p=deleteEdificio" $ses @{ id = '5' }
Comprobar 'R-11 deleteEdificio sin CSRF devuelve 403' 403 $r.code $r.body

$r = Llamar 'GET' "$base/index.php?p=saveGeometria" $ses
Comprobar 'R-12 GET en saveGeometria devuelve 405' 405 $r.code $r.body

$r = Llamar 'GET' "$base/index.php?p=getRutas" $ses
$json = $r.body | ConvertFrom-Json
Comprobar 'R-13 getRutas responde 200' 200 $r.code ''
Comprobar 'R-14 rutas.json está vacío tras las pruebas' 0 $json.total ''

# ---------- Los datos no cambiaron ----------
$datosDespues = Get-FileHash (Join-Path $raiz 'datos.json') -Algorithm SHA256
$geoTecDespues = Get-FileHash (Join-Path $raiz 'GeoTec.js') -Algorithm SHA256
Comprobar 'R-15 datos.json sin cambios' $datosAntes.Hash $datosDespues.Hash ''
Comprobar 'R-16 GeoTec.js sin cambios' $geoTecAntes.Hash $geoTecDespues.Hash ''

$resultados | ForEach-Object { Write-Host $_ }
$fallos = @($resultados | Where-Object { $_ -like 'FALLO*' }).Count
Write-Host ''
if ($fallos -eq 0) { Write-Host 'TODO OK' } else { Write-Host "HAY $fallos FALLOS" }
exit $(if ($fallos -eq 0) { 0 } else { 1 })
