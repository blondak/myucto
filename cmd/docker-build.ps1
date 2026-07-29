# Build (or rebuild) the MyUcto.cz Docker image.
#
# Usage:  .\cmd\docker-build.ps1 [-NoCache] [-Pull]
[CmdletBinding()]
param(
    [switch]$NoCache,
    [switch]$Pull
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "docker not found in PATH"
}
$composeCheck = & docker compose version 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Error "'docker compose' (v2) plugin required — install Docker Desktop"
}

$buildArgs = @('compose', 'build')
if ($NoCache) { $buildArgs += '--no-cache' }
if ($Pull)    { $buildArgs += '--pull' }
$buildArgs += 'app'

Write-Host "==> Building MyUcto.cz image (this can take a few minutes on first run)…"
& docker @buildArgs
if ($LASTEXITCODE -ne 0) { Write-Error "docker compose build failed" }

Write-Host ""
Write-Host "==> Done. Next steps:"
Write-Host "    .\cmd\docker-install.ps1   # first-time setup (creates cfg.php, runs migrations)"
Write-Host "    docker compose up -d       # start stack (after install)"
