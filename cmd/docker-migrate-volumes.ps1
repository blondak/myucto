# Migrate MyUcto.cz Docker volumes z 3-volume layoutu (3.5.x a starsi)
# na single-volume layout (od 3.6.0 default).
#
# Single-volume layout (`MYINVOICE_DATA_DIR=/data`) drzi VSECHEN stateful
# obsah pod jednim volumem - log/, storage/, private/dkim/ **a cfg.local.php**.
# Per-instance konfigurace (app.url z setup wizardu, auth.require_totp) tak
# prezije image update.
#
# Stary 3-volume layout (<= 3.5.x):
#   - app-log     -> /var/www/html/log
#   - app-storage -> /var/www/html/storage
#   - app-private -> /var/www/html/private
#
# Novy single-volume layout (>= 3.6.0):
#   - app-data    -> /data   (log/, storage/, private/, cfg.local.php)
#
# Bez migrace by `docker compose up -d` po pull 3.6.0 image namountnul PRAZDNY
# `app-data` a aplikace by nevidela existujici faktury/uploady/sessions/DKIM.
#
# Skript:
#   1. Snapshot cfg.local.php z bezici 3.5.x app kontejneru (`docker cp`).
#   2. Zastavi stack (`docker compose down` - DB volume zustane).
#   3. Vytvori novy `app-data` volume.
#   4. Sidecar alpine `cp -a` zkopiruje data ze starych volumes.
#   5. Drop snapshot cfg.local.php do /data.
#   6. Nastartuje stack zpet (`up -d`).
#   7. Vypise prikaz pro smazani starych volumes (mazani nedela automaticky).
#
# Idempotent. Vola se automaticky z docker-update.ps1 pri detekci stareho layoutu.
[CmdletBinding()]
param()

# PS 5.1 nezna $PSNativeCommandUseErrorActionPreference - 'Stop' by trigovalo
# NativeCommandError pri kazdem stderr radku z `docker compose down/up` (progress
# napsane do stderr). Misto Stop drzime Continue a kontrolujeme $LASTEXITCODE
# rucne po kazde docker call. PS 7+ se chova stejne, pripadne $PSNativeCommand...
# pomaha mensim noisem.
$ErrorActionPreference = 'Continue'
$PSNativeCommandUseErrorActionPreference = $false
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

function Assert-LastExitOk {
    param([string]$Step)
    if ($LASTEXITCODE -ne 0) {
        Write-Host ""
        Write-Host "ERROR: $Step selhal (rc=$LASTEXITCODE)" -ForegroundColor Red
        exit 1
    }
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Host "ERROR: docker not found in PATH" -ForegroundColor Red; exit 1
}
& docker compose version > $null 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: 'docker compose' (v2) plugin required" -ForegroundColor Red; exit 1
}

# Detect compose project name.
$Project = $env:COMPOSE_PROJECT_NAME
if (-not $Project) {
    $Project = (Split-Path -Leaf $ProjectRoot).ToLower() -replace '[^a-z0-9_-]', ''
}
$OldLog     = "${Project}_app-log"
$OldStorage = "${Project}_app-storage"
$OldPrivate = "${Project}_app-private"
$NewData    = "${Project}_app-data"

# Pick compose file
$ComposeArgs = @()
$prodRunning = & docker compose -f docker-compose.production.yml ps --format json app 2>$null | Select-String '"State":"running"' -Quiet
if ($prodRunning) {
    $ComposeArgs = @('-f', 'docker-compose.production.yml')
} elseif ((Test-Path docker-compose.production.yml) -and (-not (Test-Path docker-compose.yml))) {
    $ComposeArgs = @('-f', 'docker-compose.production.yml')
}

Write-Host "==> Compose project: $Project"
Write-Host "    Old volumes:  $OldLog, $OldStorage, $OldPrivate"
Write-Host "    New volume:   $NewData"
Write-Host ""

# --- 1. detect old volumes -----------------------------------------------
$existing = @()
foreach ($v in @($OldLog, $OldStorage, $OldPrivate)) {
    & docker volume inspect $v *>$null
    if ($LASTEXITCODE -eq 0) { $existing += $v }
}

if ($existing.Count -eq 0) {
    Write-Host "==> Zadny ze starych volumes neexistuje - patrne uz jsi migroval, nebo"
    Write-Host "    je to fresh instalace. Nic k delani."
    exit 0
}

Write-Host "==> Nalezeno $($existing.Count) starych volumes k migraci:"
foreach ($v in $existing) { Write-Host "    - $v" }
Write-Host ""

# --- 2. snapshot cfg.local.php z bezici 3.5.x app kontejneru -------------
$CfgSnapshot = ""
$appCid = (& docker compose @ComposeArgs ps -q app 2>$null | Out-String).Trim()
if ($appCid) {
    $tmpSnapshot = [System.IO.Path]::Combine([System.IO.Path]::GetTempPath(), "myucto-cfglocal-" + [System.Guid]::NewGuid().ToString("N") + ".php")
    & docker cp "${appCid}:/var/www/html/cfg.local.php" $tmpSnapshot *>$null
    if (($LASTEXITCODE -eq 0) -and (Test-Path $tmpSnapshot) -and ((Get-Item $tmpSnapshot).Length -gt 0)) {
        $CfgSnapshot = $tmpSnapshot
        Write-Host "==> Snapshot cfg.local.php z bezici instalace ($tmpSnapshot)"
    } else {
        if (Test-Path $tmpSnapshot) { Remove-Item -Force $tmpSnapshot -ErrorAction SilentlyContinue }
    }
}

# --- 3. stop stack -------------------------------------------------------
Write-Host "==> Zastavuji stack (DB volume zustane nedotcen)..."
& docker compose @ComposeArgs down
# down vraci rc=0 i kdyz stack uz neni; netreba Assert-LastExitOk
Write-Host ""

# --- 3. ensure new volume exists -----------------------------------------
& docker volume inspect $NewData *>$null
if ($LASTEXITCODE -ne 0) {
    Write-Host "==> Vytvarim novy volume: $NewData"
    & docker volume create $NewData | Out-Null
}

# --- 4. copy data via sidecar alpine container ---------------------------
Write-Host "==> Kopiruji data pres docasny alpine kontejner..."
$mounts = @('-v', "${NewData}:/new")
$copyParts = @()
foreach ($v in $existing) {
    switch ($v) {
        $OldLog     { $mounts += @('-v', "${v}:/old/log:ro");     $copyParts += "mkdir -p /new/log && cp -a /old/log/. /new/log/" }
        $OldStorage { $mounts += @('-v', "${v}:/old/storage:ro"); $copyParts += "mkdir -p /new/storage && cp -a /old/storage/. /new/storage/" }
        $OldPrivate { $mounts += @('-v', "${v}:/old/private:ro"); $copyParts += "mkdir -p /new/private && cp -a /old/private/. /new/private/" }
    }
}
$copyCmd = ($copyParts -join ' && ') + ' && chown -R 33:33 /new && echo OK'

& docker run --rm @mounts alpine sh -c $copyCmd
Assert-LastExitOk "Kopirovani dat (alpine sidecar)"
Write-Host "    Hotovo."
Write-Host ""

# --- 4b. drop cfg.local.php snapshot do noveho volumu --------------------
if ($CfgSnapshot -and (Test-Path $CfgSnapshot)) {
    Write-Host "==> Obnovuji cfg.local.php (app.url / auth.require_totp) v novem volumu..."
    & docker run --rm `
        -v "${NewData}:/new" `
        -v "${CfgSnapshot}:/snapshot.php:ro" `
        alpine sh -c 'cp /snapshot.php /new/cfg.local.php && chown 33:33 /new/cfg.local.php'
    Remove-Item -Force $CfgSnapshot -ErrorAction SilentlyContinue
    Write-Host "    Hotovo."
    Write-Host ""
}

# --- 5. start stack -------------------------------------------------------
Write-Host "==> Startuji stack (docker compose $($ComposeArgs -join ' ') up -d)..."
& docker compose @ComposeArgs up -d --remove-orphans
Assert-LastExitOk "docker compose up -d"
Write-Host ""

# --- 6. report -----------------------------------------------------------
Write-Host "============================================================"
Write-Host " Migrace volumes dokoncena. Stack bezi na novem app-data volumu."
Write-Host ""
Write-Host " Over:"
Write-Host "   - aplikace vidi faktury / uploady / sessions"
Write-Host "   - setup-time overrides (app.url, auth.require_totp) zustaly"
Write-Host ""
Write-Host " Po overeni muzes smazat stare volumes (NEVRATNE):"
foreach ($v in $existing) {
    Write-Host "        docker volume rm $v"
}
Write-Host ""
Write-Host " (Skript NEMAZAL stare volumes automaticky - rucne po overeni.)"
Write-Host "============================================================"
