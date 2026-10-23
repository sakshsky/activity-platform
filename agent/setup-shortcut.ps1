$ErrorActionPreference = 'Stop'
try {
    $exe = Join-Path $PSScriptRoot 'activity-agent.exe'
    if (!(Test-Path -LiteralPath $exe)) { throw 'activity-agent.exe is missing. Place the built executable in this folder first.' }
    $desktop = [Environment]::GetFolderPath('Desktop')
    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut((Join-Path $desktop 'Activity Tracker.lnk'))
    $shortcut.TargetPath = Join-Path $PSScriptRoot 'Start Activity Tracker.cmd'
    $shortcut.WorkingDirectory = $PSScriptRoot
    $shortcut.IconLocation = "$exe,0"
    $shortcut.Description = 'Open your employee activity tracker'
    $shortcut.Save()
    Write-Host 'Ready. Double-click Activity Tracker on your desktop. Keep this agent folder in its current location.'
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
