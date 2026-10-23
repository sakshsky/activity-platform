param([string]$ServerPath = '')
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
if (!$ServerPath) { $ServerPath = Join-Path $projectRoot 'server' }
$ServerPath = (Resolve-Path -LiteralPath $ServerPath).Path
if (!(Test-Path -LiteralPath (Join-Path $ServerPath 'artisan'))) { throw 'Choose the Laravel server folder.' }
$version = (Get-Content -LiteralPath (Join-Path $projectRoot 'VERSION') -Raw).Trim()
if ($version -notmatch '^\d+\.\d+\.\d+$') { throw 'Invalid release version' }
$agent = Join-Path $projectRoot 'agent'
$destination = Join-Path $ServerPath 'storage/app/agent-release'
New-Item -ItemType Directory -Force -Path $destination | Out-Null
$exeName = "activity-agent-v$version-windows-x64.exe"
$zipName = "activity-agent-v$version-windows-x64.zip"
Copy-Item -LiteralPath (Join-Path $agent 'activity-agent.exe') -Destination (Join-Path $destination $exeName)
$files = @('activity-agent.exe','agent-config.example.json','Setup Desktop Shortcut.cmd','Start Activity Tracker.cmd','setup-shortcut.ps1','start-agent.ps1','EMPLOYEE-SETUP.md') | ForEach-Object { Join-Path $agent $_ }
if (Test-Path -LiteralPath (Join-Path $agent 'ffmpeg.exe')) {
    if (!(Test-Path -LiteralPath (Join-Path $agent 'ffmpeg-notices/LICENSE'))) { throw 'FFmpeg publisher notices must accompany its runtime.' }
    $files += Join-Path $agent 'ffmpeg.exe'
    $files += Join-Path $agent 'ffmpeg-notices'
}
Compress-Archive -LiteralPath $files -DestinationPath (Join-Path $destination $zipName) -Force
$manifest = @{version=$version;files=@{}}
foreach ($kind in @('exe','zip')) { $name = if ($kind -eq 'exe') {$exeName} else {$zipName}; $manifest.files[$kind] = @{name=$name;sha256=(Get-FileHash -LiteralPath (Join-Path $destination $name) -Algorithm SHA256).Hash.ToLower()} }
$manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $destination 'release.json') -Encoding utf8NoBOM
Write-Output "Verified release files prepared at $destination. Admins can download them from Downloads."
