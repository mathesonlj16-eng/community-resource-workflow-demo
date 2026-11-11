# Starts the LOCAL Docker demo. Requires Docker Desktop; no network-facing production deployment.
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker CLI was not found. Install/start Docker Desktop, then rerun this script.'
}
& docker info --format '{{.ServerVersion}}' *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker engine is not running. Start Docker Desktop first.' }

if (-not (Test-Path -LiteralPath '.env')) {
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $bytes1 = New-Object 'Byte[]' 32
        $bytes2 = New-Object 'Byte[]' 32
        $rng.GetBytes($bytes1)
        $rng.GetBytes($bytes2)
        $dbPassword = [Convert]::ToBase64String($bytes1)
        $rootPassword = [Convert]::ToBase64String($bytes2)
    } finally { $rng.Dispose() }
    # .env is gitignored. This file is for local Docker credentials, not a public server.
    @("DEMO_DB_PASSWORD=$dbPassword", "DEMO_ROOT_PASSWORD=$rootPassword") |
        Set-Content -LiteralPath '.env' -Encoding ascii
    Write-Host 'Generated distinct local database passwords in .env (ignored by Git).'
}
& docker compose up -d
if ($LASTEXITCODE -ne 0) { throw 'Docker Compose startup failed; inspect docker compose logs.' }
Write-Host 'WordPress local demo: http://127.0.0.1:8088/'
Write-Host 'Complete the initial WordPress installation with a unique DEMO-ONLY admin password.'
Write-Host 'Then activate Community Workflow Demo under Plugins and follow README.md.'
