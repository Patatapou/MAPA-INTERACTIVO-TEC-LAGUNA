Option Explicit

Dim shell, fileSystem, launcher, powershell, command
Set shell = CreateObject("WScript.Shell")
Set fileSystem = CreateObject("Scripting.FileSystemObject")

launcher = fileSystem.BuildPath(fileSystem.GetParentFolderName(WScript.ScriptFullName), "start-admin.ps1")
If Not fileSystem.FileExists(launcher) Then
    MsgBox "No se encontró el lanzador local: " & launcher, vbCritical, "Panel Admin ITL"
    WScript.Quit 1
End If

powershell = shell.ExpandEnvironmentStrings("%SystemRoot%") & "\System32\WindowsPowerShell\v1.0\powershell.exe"
command = """" & powershell & """ -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File """ & launcher & """"
shell.Run command, 0, False
