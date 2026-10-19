$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Push-Location (Join-Path $projectRoot 'agent')
try {
    go test ./...
    if ($LASTEXITCODE -ne 0) { throw 'Agent tests failed.' }
    go vet ./...
    if ($LASTEXITCODE -ne 0) { throw 'Agent static checks failed.' }
    go build -trimpath -o activity-agent.exe .
    if ($LASTEXITCODE -ne 0) { throw 'Agent build failed.' }
} finally { Pop-Location }
