$ErrorActionPreference = "Stop"

$launcher = Join-Path $PSScriptRoot "start-admin.ps1"
if (-not (Test-Path -LiteralPath $launcher -PathType Leaf)) {
    throw "No se encontró el lanzador local: $launcher"
}

$hiddenLauncher = Join-Path $PSScriptRoot "iniciar-admin-oculto.vbs"
if (-not (Test-Path -LiteralPath $hiddenLauncher -PathType Leaf)) {
    throw "No se encontró el lanzador oculto: $hiddenLauncher"
}

$desktop = [Environment]::GetFolderPath("Desktop")
$shortcutPath = Join-Path $desktop "Panel Admin ITL.lnk"
$wscript = Join-Path $env:SystemRoot "System32\wscript.exe"
$powershell = Join-Path $env:SystemRoot "System32\WindowsPowerShell\v1.0\powershell.exe"

$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = $wscript
$shortcut.Arguments = "//B //NoLogo `"$hiddenLauncher`""
$shortcut.WorkingDirectory = $PSScriptRoot
$shortcut.Description = "Inicia el panel de administración local del Mapa ITL"
$shortcut.IconLocation = "$powershell,0"
$shortcut.WindowStyle = 1
$shortcut.Save()

Write-Host "Acceso directo creado: $shortcutPath" -ForegroundColor Green
