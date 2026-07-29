# Prevod dat z MyInvoice do bezici MyUcto.cz Docker instalace.
#
# Obali `api/bin/MyInvoiceMigrate.php` a vyresi to, co v Dockeru navic bolí:
# app kontejner musi na zdrojovou databazi vubec dosahnout.
#
# Tri podporovane zdroje:
#
#   1. MyInvoice v JINEM Docker stacku (Docker -> Docker)
#        .\cmd\docker-migrate-from-myinvoice.ps1 -SourceContainer myinvoice-db-1 `
#             -SourceDb myinvoice -SourceUser root -SourcePassword tajne
#      Skript docasne pripoji zdrojovy kontejner do site MyUcta a po dokonceni
#      ho zase odpoji. Zdrojovy stack zustava beze zmeny.
#
#   2. MyInvoice na HOSTITELI (nativni MariaDB vedle Dockeru)
#        .\cmd\docker-migrate-from-myinvoice.ps1 -SourceHost host.docker.internal `
#             -SourceDb myinvoice -SourceUser root -SourcePassword tajne
#
#   3. Vlastni URL (cokoli dosazitelneho z app kontejneru)
#        .\cmd\docker-migrate-from-myinvoice.ps1 -SourceUrl "mysql://root:tajne@10.0.0.5:3306/myinvoice"
#
# Cilova databaze se bere z konfigurace bezici instance (cfg.php v kontejneru) —
# tady se nezadava. Migrator sam pripravi schema, prenese data a dojede migrace
# MyUcta; spoustet `migrate.php` rucne netreba.
#
# Postup a kontroly po prevodu: manual/06_Prevod_z_MyInvoice.md
[CmdletBinding(DefaultParameterSetName = 'Container')]
param(
    [Parameter(ParameterSetName = 'Container', Mandatory = $true)]
    [string]$SourceContainer,

    [Parameter(ParameterSetName = 'Host', Mandatory = $true)]
    [string]$SourceHost,

    [Parameter(ParameterSetName = 'Url', Mandatory = $true)]
    [string]$SourceUrl,

    [Parameter(ParameterSetName = 'Container')]
    [Parameter(ParameterSetName = 'Host')]
    [string]$SourceDb = 'myinvoice',

    [Parameter(ParameterSetName = 'Container')]
    [Parameter(ParameterSetName = 'Host')]
    [string]$SourceUser = 'root',

    [Parameter(ParameterSetName = 'Container')]
    [Parameter(ParameterSetName = 'Host')]
    [string]$SourcePassword,

    [Parameter(ParameterSetName = 'Container')]
    [Parameter(ParameterSetName = 'Host')]
    [int]$SourcePort = 3306,

    # Bez interaktivniho potvrzeni (POZOR: cilova DB se prepise bez dotazu).
    [switch]$Yes,

    # Predano dal migratoru beze zmeny (napr. --allow-missing, --batch=5000).
    [string[]]$ExtraArgs = @()
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "docker not found in PATH"
}
& docker compose version > $null 2>&1
if ($LASTEXITCODE -ne 0) { Write-Error "'docker compose' (v2) plugin required" }

# Bezici stack: preferuj production compose, jinak default.
$ComposeFile = 'docker-compose.yml'
if (Test-Path 'docker-compose.production.yml') {
    $running = & docker compose -f docker-compose.production.yml ps --status running --format '{{.Name}}' 2>$null
    if ($LASTEXITCODE -eq 0 -and $running) { $ComposeFile = 'docker-compose.production.yml' }
}

$appContainer = & docker compose -f $ComposeFile ps -q app 2>$null
if (-not $appContainer) {
    Write-Error "Sluzba 'app' nebezi. Spust nejdriv stack: docker compose -f $ComposeFile up -d"
}

function Get-EscapedUrlPart([string]$Value) {
    return [System.Uri]::EscapeDataString($Value)
}

# --- Sestav zdrojovou URL a pripadne propoj site --------------------------------
$connectedNetwork = $null
$targetNetwork = $null

switch ($PSCmdlet.ParameterSetName) {
    'Url' {
        $effectiveUrl = $SourceUrl
    }
    'Host' {
        $effectiveUrl = "mysql://$(Get-EscapedUrlPart $SourceUser):$(Get-EscapedUrlPart $SourcePassword)@${SourceHost}:${SourcePort}/${SourceDb}"
    }
    'Container' {
        # Existuje zdrojovy kontejner?
        $srcId = & docker ps -q --filter "name=^/$([regex]::Escape($SourceContainer))$" 2>$null
        if (-not $srcId) {
            $srcId = & docker ps -q --filter "name=$SourceContainer" 2>$null | Select-Object -First 1
        }
        if (-not $srcId) {
            Write-Error "Kontejner '$SourceContainer' nebezi. Zkontroluj: docker ps"
        }

        # Sit app kontejneru — do ni zdroj docasne pripojime.
        $targetNetwork = (& docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}' $appContainer 2>$null |
            Where-Object { $_ -and $_.Trim() } | Select-Object -First 1)
        if (-not $targetNetwork) { Write-Error "Nepodarilo se zjistit sit app kontejneru." }
        $targetNetwork = $targetNetwork.Trim()

        $srcNetworks = & docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' $srcId 2>$null
        if ($srcNetworks -notmatch [regex]::Escape($targetNetwork)) {
            Write-Host "==> Pripojuji '$SourceContainer' do site '$targetNetwork' (docasne)..."
            & docker network connect $targetNetwork $srcId
            if ($LASTEXITCODE -ne 0) { Write-Error "docker network connect selhal" }
            $connectedNetwork = $targetNetwork
        }

        # Uvnitr site se kontejner adresuje svym jmenem, port je vzdy interni 3306.
        $srcName = (& docker inspect -f '{{.Name}}' $srcId).TrimStart('/')
        $effectiveUrl = "mysql://$(Get-EscapedUrlPart $SourceUser):$(Get-EscapedUrlPart $SourcePassword)@${srcName}:3306/${SourceDb}"
    }
}

# --- Spust migrator v app kontejneru -------------------------------------------
$migrateArgs = @('--source-url=' + $effectiveUrl)
if ($Yes) { $migrateArgs += '--yes' }
$migrateArgs += $ExtraArgs

# Heslo do konzole nevypisuj.
$shownUrl = $effectiveUrl -replace '://([^:@/]+):[^@]*@', '://$1:***@'
Write-Host "==> Zdroj:  $shownUrl"
Write-Host "==> Cil:    databaze bezici MyUcto instance (z cfg.php v kontejneru)"
Write-Host ""

$exitCode = 0
try {
    if ($Yes) {
        & docker compose -f $ComposeFile exec -T app php api/bin/MyInvoiceMigrate.php @migrateArgs
    } else {
        # Bez -T, aby fungoval interaktivni dotaz 'ANO'.
        & docker compose -f $ComposeFile exec app php api/bin/MyInvoiceMigrate.php @migrateArgs
    }
    $exitCode = $LASTEXITCODE
} finally {
    if ($connectedNetwork) {
        Write-Host ""
        Write-Host "==> Odpojuji '$SourceContainer' ze site '$connectedNetwork'..."
        & docker network disconnect $connectedNetwork $SourceContainer 2>$null | Out-Null
    }
}

if ($exitCode -ne 0) {
    Write-Host ""
    Write-Host "Prevod skoncil s chybou (exit $exitCode). Viz vypis vyse a manual/06_Prevod_z_MyInvoice.md."
    exit $exitCode
}

Write-Host ""
Write-Host "Hotovo. Soubory ze storage/ (PDF, prilohy, loga) prenes samostatne — databaze je nenese."
