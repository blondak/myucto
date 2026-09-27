<#
.SYNOPSIS
    Vyexportuje účetní agendu z POHODA SQL (Microsoft SQL Server) do ZIPu pro převod do MyÚčta.

.DESCRIPTION
    Výstup je stejný jako u převodu datového souboru (Export-PohodaMdbAccounting.ps1),
    jen zdrojem je databáze agendy na SQL Serveru:

      <Vystup>\<IČO>_<rok>\89_ucetnictvi_mdb.xml   účetnictví
      <Vystup>\<IČO>_<rok>\90_majetek.xml          majetek (jen když ho agenda vede)
      <Vystup>\<IČO>_<rok>\91_mzdy.xml             mzdy (jen když je agenda vede)
      <Vystup>\<IČO>_<rok>.zip                     ZIP k nahrání do průvodce Přechod z POHODA

    Skript posílá jen dotazy SELECT přes spojení jen pro čtení (ApplicationIntent=ReadOnly,
    šifrované). Stačí přihlašovací jméno s rolí db_datareader v databázi agendy.

    Připojení se bere z konfiguračního souboru JSON (parametr -Config, jinak soubor
    pohoda-sql.json vedle skriptu, pokud existuje). Vzor je pohoda-sql.example.json:
    zkopírujte ho jako pohoda-sql.json a vyplňte. Klíče:

      host                    server, například SERVER nebo SERVER\POHODA
      port                    TCP port; prázdné = výchozí 1433 nebo pojmenovaná instance
                              přes službu SQL Browser
      database                databáze agendy StwPh_<IČO>_<rok>; prázdné = výběr ze seznamu
      user, password          přihlášení SQL; prázdný user = účet Windows
      trustServerCertificate  true = certifikát serveru se neověřuje (výchozí)
      driver                  sqlclient (výchozí, součást Windows) nebo odbc
      odbcDriver              jen pro driver odbc, výchozí ODBC Driver 18 for SQL Server

    Na klíč, který v souboru chybí, se skript zeptá; bez souboru se zeptá na všechno.
    Heslo se zadává skrytě a nikam se nevypisuje. Databázi agendy nabídne ze seznamu
    agend na serveru (registr StwPh_sys, jinak názvy databází); parametry -Ico a -Rok
    ji vyberou bez dotazu.

    Požadavky: Windows PowerShell 5.1 (součást Windows 10 a 11) nebo PowerShell 7 a přístup
    na SQL Server. Ovladač SqlClient je součástí Windows; Microsoft ODBC Driver for SQL
    Server je potřeba jen při volbě driver odbc.

.PARAMETER Config
    Konfigurační soubor JSON s připojením. Bez zadání pohoda-sql.json vedle skriptu, nebo dotazy.

.PARAMETER Databaze
    Databáze agendy (StwPh_<IČO>_<rok>). Přebije klíč database z konfigurace.

.PARAMETER Vystup
    Kořenová výstupní složka. Skript v ní vytvoří složku <IČO>_<rok>. Bez zadání
    složka skriptu.

.PARAMETER Ico
    IČO účetní jednotky, 6 až 8 číslic. Vybere agendu ze seznamu a ověří ji proti sKonfig.

.PARAMETER Rok
    Účetní rok. Vybere agendu ze seznamu a ověří ho proti sKonfig.

.PARAMETER BezZip
    Nevytvářet ZIP archiv.

.PARAMETER PotvrditMetadata
    Před exportem se zeptat na potvrzení IČO a roku agendy.

.EXAMPLE
    .\Export-PohodaSQL.ps1 -Config .\pohoda-sql.json

.EXAMPLE
    .\Export-PohodaSQL.ps1 -Ico 12345678 -Rok 2026 -Vystup .\export
#>
[CmdletBinding()]
param(
    [string]$Config,
    [string]$Databaze,
    [string]$Vystup,
    [string]$Ico,
    [int]$Rok,
    [switch]$BezZip,
    [switch]$PotvrditMetadata
)

$ErrorActionPreference = 'Stop'
# Před tečkovým načtením knihoven, které $PSBoundParameters přepíšou.
$yearWasSpecified = $PSBoundParameters.ContainsKey('Rok')

if ($env:OS -ne 'Windows_NT') {
    throw 'Skript běží jen na Windows.'
}

foreach ($required in 'Pohoda-Common.ps1', 'PohodaSql-Common.ps1', 'Export-PohodaMdb.ps1') {
    if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot $required) -PathType Leaf)) {
        throw "Chybí $required. Stáhněte a rozbalte celý ZIP převodníku, aby všechny skripty ležely ve stejné složce."
    }
}
. (Join-Path $PSScriptRoot 'Pohoda-Common.ps1')
. (Join-Path $PSScriptRoot 'PohodaSql-Common.ps1')
# Export-PohodaMdb.ps1 má vlastní parametry; tečkové načtení je naváže v tomto skriptu,
# proto se mu předají hodnoty -Vystup a -Databaze, jinak by je vynulovalo.
. (Join-Path $PSScriptRoot 'Export-PohodaMdb.ps1') -Vystup $Vystup -Databaze $Databaze

$Ico = ConvertTo-PohodaAgendaIco $Ico
if ($yearWasSpecified) {
    Assert-PohodaAgendaYear $Rok
} else {
    $Rok = 0
}
if ([string]::IsNullOrWhiteSpace($Vystup)) {
    $Vystup = $PSScriptRoot
}
if ([string]::IsNullOrWhiteSpace($Config)) {
    $defaultConfig = Join-Path $PSScriptRoot 'pohoda-sql.json'
    if (Test-Path -LiteralPath $defaultConfig -PathType Leaf) {
        $Config = $defaultConfig
    }
}

if ($Config) {
    Write-Host "Nastavení připojení: $Config"
    $settings = Read-PohodaSqlConfig $Config
} else {
    Write-Host 'Konfigurační soubor pohoda-sql.json není, zadejte připojení k SQL Serveru.'
    $settings = New-PohodaSqlSettings
}
$settings = Complete-PohodaSqlSettings $settings
if ($Databaze) {
    $settings.Database = $Databaze.Trim()
}

$potvrdit = [bool]$PotvrditMetadata -or -not $Config
$connection = Open-PohodaSqlConnection $settings $settings.Database
try {
    if (-not $settings.Database) {
        $agendas = Get-PohodaSqlAgendas $connection
        $settings.Database = Select-PohodaSqlDatabase $agendas $Ico $Rok 'agendu POHODA SQL'
        $connection.ChangeDatabase($settings.Database)
        if (-not ($Ico -and $Rok)) {
            $potvrdit = $true
        }
    }
    Write-Host "Připojeno: $(Get-PohodaSqlConnectionInfo $settings $settings.Database)"

    $null = Invoke-PohodaAccountingExport -Connection $connection -Vystup $Vystup -Ico $Ico -Rok $Rok `
        -Potvrdit $potvrdit -BezZip $BezZip -Zdroj 'Databáze' -VeZdroji 'v databázi' `
        -Doplnky {
            param($agendaDir, $agendaIco, $agendaYear)
            foreach ($row in (Export-PohodaMdbGroups $connection $agendaDir $agendaIco ([string]$agendaYear) @('majetek', 'mzdy'))) {
                Write-PohodaMdbGroupReport $row
            }
        }
} finally {
    $connection.Close()
    $connection.Dispose()
}
