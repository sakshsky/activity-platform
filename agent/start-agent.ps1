$ErrorActionPreference = 'Stop'
try {
    $exe = Join-Path $PSScriptRoot 'activity-agent.exe'
    $configPath = Join-Path $PSScriptRoot 'agent-config.json'

    $running = Get-Process -Name 'activity-agent' -ErrorAction SilentlyContinue | Where-Object { $_.Path -eq $exe }
    if ($running) {
        $config = @{}; if (Test-Path -LiteralPath $configPath) { $config = Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json }
        $listen = $config.listen
        if (!$listen) { $listen = '127.0.0.1:8765' }
        $address = [Uri]("http://" + $listen + '/agent')
        if (!$address.IsLoopback -or $address.UserInfo) { throw 'Invalid local listener address in agent-config.json.' }
        Start-Process $address.AbsoluteUri
        exit 0
    }
    & $exe
    exit $LASTEXITCODE
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
