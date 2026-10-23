param([switch]$SourceOnly)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
$projectRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$prefix = if ($SourceOnly) { 'activity-platform-github-source-' } else { 'activity-platform-' }
$archivePath = Join-Path (Split-Path -Parent $projectRoot) ($prefix + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.zip')
$archive = [IO.Compression.ZipFile]::Open($archivePath, [IO.Compression.ZipArchiveMode]::Create)
# build-web.mjs writes identical index/assets to server and agent.
$currentAssets = @([regex]::Matches([IO.File]::ReadAllText((Join-Path $projectRoot 'server/public/ui/index.html')), '/ui/assets/[^" ]+') | ForEach-Object { $_.Value.Replace('/ui/', '') })
function Add-ProjectFolder([string]$folder) {
    foreach ($entry in Get-ChildItem -LiteralPath $folder -Force) {
        $relative = [IO.Path]::GetRelativePath($projectRoot, $entry.FullName).Replace('\','/')
        if ($entry.PSIsContainer) {
            if ($entry.Name -in @('vendor','node_modules','.git','data','dist','.idea','.vscode','.phpunit.cache')) { continue }
            if ($relative -eq 'server/storage') { continue }
            Add-ProjectFolder $entry.FullName
        } else {
            if ($entry.Name -like 'PROJECT-REVIEW-*.md' -or $entry.Name -eq 'REVIEW-RESOLUTION.md') { continue }
            if ($relative -match '^(server/public/ui|agent/ui)/(assets/.+)$' -and $Matches[2] -notin $currentAssets) { continue }
            if ($SourceOnly -and $entry.Extension -in @('.exe','.zip','.pem','.key','.pfx','.p12')) { continue }
            if ($entry.Name -in @('auth.json','.DS_Store','Thumbs.db')) { continue }
            if ($entry.Name -eq '.env' -or ($entry.Name.StartsWith('.env.') -and $entry.Name -ne '.env.example')) { continue }
            if ($entry.Name -eq 'agent-config.json' -or $entry.Name.StartsWith('database.sqlite') -or $entry.Name.EndsWith('.log') -or $entry.Name -eq '.phpunit.result.cache') { continue }
            if ($relative.StartsWith('server/bootstrap/cache/') -and $entry.Name -ne '.gitignore') { continue }
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $entry.FullName, 'activity-platform/' + $relative, [IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    }
}
try {
    Add-ProjectFolder $projectRoot
    foreach ($folder in @('app/private','framework/cache/data','framework/sessions','framework/views','logs')) {
        $entry = $archive.CreateEntry('activity-platform/server/storage/' + $folder + '/.gitignore')
        $writer = [IO.StreamWriter]::new($entry.Open())
        try { $writer.Write("*`n!.gitignore`n") } finally { $writer.Dispose() }
    }
} finally { $archive.Dispose() }
Write-Output $archivePath
Get-FileHash -LiteralPath $archivePath -Algorithm SHA256
