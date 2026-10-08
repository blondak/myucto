<#
.SYNOPSIS
    Vyexportuje účetní data z datového souboru POHODY do proudového XML pro převod do MyÚčta.

.DESCRIPTION
    Skript čte pouze pevně povolené tabulky z databáze POHODY přes Microsoft ACE/Jet OLE DB.
    Zdrojový soubor nijak nemění. Výsledek zapisuje proudově do dočasného souboru a pod
    konečným názvem ho zveřejní až po úspěšném dokončení všech tabulek.

    Výstup:
      <Vystup>\<Ico>_<Rok>\89_ucetnictvi_mdb.xml

    Pro každou povolenou tabulku vznikne element <table>. Tabulka, která v konkrétní verzi
    POHODY neexistuje, se zapíše s atributem missing="true". Binární sloupce se neexportují.

    Zápis je společný s Export-PohodaSQL.ps1 (Pohoda-Common.ps1), výstup z MDB i POHODA SQL
    je stejný.

.PARAMETER Mdb
    Datový soubor účetní agendy POHODY (.mdb). Bez zadání se otevře výběr souboru.

.PARAMETER Vystup
    Kořenová výstupní složka. Skript v ní vytvoří složku <Ico>_<Rok>. Bez zadání
    se použije složka exportního nástroje.

.PARAMETER Ico
    Volitelná kontrola IČO účetní jednotky, 6 až 8 číslic. Skutečné IČO se vždy
    načte z tabulky sKonfig a odlišná hodnota export zastaví.

.PARAMETER Rok
    Volitelná kontrola účetního roku. Skutečný rok se vždy načte z tabulky sKonfig
    a odlišná hodnota export zastaví.

.PARAMETER BezZip
    Nevytvářet ZIP archiv. Výchozí chování je vytvořit ZIP vedle složky agendy.

.EXAMPLE
    .\Export-PohodaMdbAccounting.ps1 -Mdb "C:\POHODA\Data\StwPh_12345678_2026.mdb" -Vystup .\export -Ico 12345678 -Rok 2026
#>
[CmdletBinding()]
param(
    [string]$Mdb,
    [string]$Vystup,
    [string]$Ico,
    [int]$Rok,
    [switch]$BezZip,
    [switch]$PotvrditMetadata
)

$ErrorActionPreference = 'Stop'
$icoWasSpecified = $PSBoundParameters.ContainsKey('Ico') -and -not [string]::IsNullOrWhiteSpace($Ico)
$yearWasSpecified = $PSBoundParameters.ContainsKey('Rok')

function Open-PohodaMdb([string]$Path) {
    foreach ($provider in 'Microsoft.ACE.OLEDB.16.0', 'Microsoft.ACE.OLEDB.12.0', 'Microsoft.Jet.OLEDB.4.0') {
        try {
            $builder = New-Object System.Data.OleDb.OleDbConnectionStringBuilder
            $builder.Provider = $provider
            $builder['Data Source'] = $Path
            $builder['Mode'] = 'Read'
            $builder['Persist Security Info'] = $false
            $connection = New-Object System.Data.OleDb.OleDbConnection ([string]$builder.ConnectionString)
            $connection.Open()
            return $connection
        } catch {
            if ($connection) {
                $connection.Dispose()
            }
        }
    }
    return $null
}

function Select-PohodaMdb {
    Add-Type -AssemblyName System.Windows.Forms
    $dialog = New-Object System.Windows.Forms.OpenFileDialog
    $dialog.Title = 'Vyberte datový soubor účetní agendy POHODY'
    $dialog.Filter = 'Datový soubor POHODY (*.mdb)|*.mdb|Všechny soubory (*.*)|*.*'
    $dialog.Multiselect = $false
    try {
        if ($dialog.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) {
            throw 'Nebyl vybrán žádný datový soubor.'
        }
        return $dialog.FileName
    } finally {
        $dialog.Dispose()
    }
}

if ($env:OS -ne 'Windows_NT') {
    throw 'Skript běží jen na Windows.'
}

foreach ($required in 'Pohoda-Common.ps1', 'Export-PohodaMdb.ps1') {
    if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot $required) -PathType Leaf)) {
        throw "Chybí $required. Stáhněte a rozbalte celý ZIP převodníku, aby všechny skripty ležely ve stejné složce."
    }
}
. (Join-Path $PSScriptRoot 'Pohoda-Common.ps1')
$companion = Join-Path $PSScriptRoot 'Export-PohodaMdb.ps1'

$interactive = [string]::IsNullOrWhiteSpace($Mdb)
if ($interactive) {
    $Mdb = Select-PohodaMdb
}
$mdbFile = Get-Item -LiteralPath $Mdb -ErrorAction Stop
if ($mdbFile.PSIsContainer) {
    throw "Cesta $Mdb není datový soubor."
}
[string]$mdbPath = [string]$mdbFile.FullName

$Ico = ConvertTo-PohodaAgendaIco $(if ($icoWasSpecified) { $Ico } else { '' })
if ($yearWasSpecified) {
    Assert-PohodaAgendaYear $Rok
}
if ([string]::IsNullOrWhiteSpace($Vystup)) {
    $Vystup = $PSScriptRoot
}

$connection = Open-PohodaMdb $mdbPath
if ($null -eq $connection -and [Environment]::Is64BitProcess) {
    $powershell32 = Join-Path $env:WINDIR 'SysWOW64\WindowsPowerShell\v1.0\powershell.exe'
    if (-not (Test-Path -LiteralPath $powershell32)) {
        throw 'Datový soubor nejde otevřít a 32bitový Windows PowerShell není dostupný.'
    }
    Write-Host 'Ovladač pro MDB v 64bitovém PowerShellu není dostupný, spouštím 32bitový PowerShell.'
    $arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $PSCommandPath, '-Mdb', $mdbPath, '-Vystup', $Vystup)
    if ($icoWasSpecified) {
        $arguments += @('-Ico', $Ico)
    }
    if ($yearWasSpecified) {
        $arguments += @('-Rok', [string]$Rok)
    }
    if ($interactive -or $PotvrditMetadata) {
        $arguments += '-PotvrditMetadata'
    }
    if ($BezZip) {
        $arguments += '-BezZip'
    }
    & $powershell32 @arguments
    exit $LASTEXITCODE
}
if ($null -eq $connection) {
    throw 'Datový soubor nejde otevřít. Nainstalujte Microsoft 365 Access Runtime s ACE OLE DB/ODBC ve stejné architektuře jako Microsoft Office: https://support.microsoft.com/en-us/access/download-and-install-microsoft-365-access-runtime'
}

try {
    # Majetek, mzdy a sklad čte Export-PohodaMdb.ps1 vlastním spojením, proto se účetní spojení
    # před jeho spuštěním zavře.
    $null = Invoke-PohodaAccountingExport -Connection $connection -Vystup $Vystup -Ico $Ico `
        -Rok $(if ($yearWasSpecified) { $Rok } else { 0 }) -Potvrdit ($interactive -or $PotvrditMetadata) -BezZip $BezZip `
        -Doplnky {
            param($agendaDir)
            $connection.Close()
            & $companion -Mdb $mdbPath -Vystup $agendaDir -Skupiny @('majetek', 'mzdy', 'sklad')
        }
} finally {
    $connection.Close()
    $connection.Dispose()
}
