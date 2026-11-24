$ErrorActionPreference = "Stop"

$launcher = Join-Path $PSScriptRoot "start-admin.ps1"
if (-not (Test-Path -LiteralPath $launcher -PathType Leaf)) {
    throw "No se encontró el lanzador local: $launcher"
}

$desktop = [Environment]::GetFolderPath("Desktop")
$shortcutPath = Join-Path $desktop "Panel Admin ITL.lnk"
$powershell = Join-Path $env:SystemRoot "System32\WindowsPowerShell\v1.0\powershell.exe"

$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = $powershell
$shortcut.Arguments = "-ExecutionPolicy Bypass -File `"$launcher`""
$shortcut.WorkingDirectory = $PSScriptRoot
$shortcut.Description = "Inicia el panel de administración local del Mapa ITL"
$shortcut.IconLocation = "$powershell,0"
$shortcut.WindowStyle = 1
$shortcut.Save()

Write-Host "Acceso directo creado: $shortcutPath" -ForegroundColor Green
