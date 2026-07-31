# Sestaví jednosouborový build MCP serveru do MCP/dist/myucto-mcp.mjs.
#
# Výsledek je jeden .mjs bez závislostí — uživatel ho zkopíruje kamkoliv a spustí
# `node myucto-mcp.mjs`. Node 20+ je potřeba pořád; build odstraňuje jen
# `npm install` a adresář node_modules, ne runtime.
#
# Linuxová obdoba: cmd/build-mcp.sh (drž je v synchronizaci).
#
# Použití:
#   pwsh -File cmd/build-mcp.ps1
#   pwsh -File cmd/build-mcp.ps1 -Clean     # smaže node_modules a nainstaluje načisto

[CmdletBinding()]
param(
    [switch]$Clean
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [Text.Encoding]::UTF8

$mcpDir = Join-Path (Split-Path -Parent $PSScriptRoot) 'MCP'
if (-not (Test-Path $mcpDir)) {
    throw "Složka MCP nenalezena: $mcpDir"
}

# Ověření Node — bez něj build ani běh nedávají smysl a chyba z npm by byla méně čitelná.
$node = Get-Command node -ErrorAction SilentlyContinue
if (-not $node) {
    throw 'Node.js nenalezen v PATH. Nainstalujte Node 20 nebo novější.'
}
$nodeVersion = (& node --version).TrimStart('v')
$major = [int]($nodeVersion -split '\.')[0]
if ($major -lt 20) {
    throw "Node $nodeVersion je příliš starý, potřeba je 20 nebo novější."
}
Write-Host "Node $nodeVersion" -ForegroundColor DarkGray

Push-Location $mcpDir
try {
    if ($Clean -and (Test-Path 'node_modules')) {
        Write-Host 'Mažu node_modules…' -ForegroundColor DarkGray
        Remove-Item 'node_modules' -Recurse -Force
    }

    if (-not (Test-Path 'node_modules')) {
        Write-Host 'Instaluji závislosti…' -ForegroundColor Cyan
        & npm install --no-audit --no-fund
        if ($LASTEXITCODE -ne 0) { throw "npm install selhal (exit $LASTEXITCODE)." }
    }

    Write-Host 'Sestavuji…' -ForegroundColor Cyan
    & npm run build
    if ($LASTEXITCODE -ne 0) { throw "npm run build selhal (exit $LASTEXITCODE)." }

    $out = Join-Path $mcpDir 'dist/myucto-mcp.mjs'
    if (-not (Test-Path $out)) { throw "Build neskončil chybou, ale $out neexistuje." }

    # Kouřová zkouška: soubor se musí dát načíst. Bez toho by se rozbitý bundle
    # poznal až u uživatele — server nic nevypíše, dokud nedostane první zprávu.
    & node --check $out
    if ($LASTEXITCODE -ne 0) { throw 'Sestavený soubor není platný JavaScript.' }

    $kb = [int]((Get-Item $out).Length / 1KB)
    Write-Host ''
    Write-Host "Hotovo: MCP/dist/myucto-mcp.mjs ($kb kB)" -ForegroundColor Green
    Write-Host 'Spuštění: node MCP/dist/myucto-mcp.mjs' -ForegroundColor DarkGray
}
finally {
    Pop-Location
}
