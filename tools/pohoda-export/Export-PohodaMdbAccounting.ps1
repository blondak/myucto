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

$PohodaDocumentColumns = @(
    'ID', 'Cislo', 'Datum', 'DatZdPln', 'DatUcP', 'DatSplat', 'DatKHDPH',
    'SText', 'VarSym', 'KonstSym', 'SpecSym', 'PDoklad', 'CisloKHDPH',
    'RelPk', 'RelTpDPH', 'RefAD', 'Firma', 'Jmeno', 'Utvar', 'Ulice', 'Obec',
    'PSC', 'ICO', 'DIC', 'RefZeme', 'Ucet', 'KodBanky', 'RelForUh',
    'KcLikv', 'DatLikv', 'Pozn', 'RefCM', 'CmKurs', 'CmMnoz', 'CmCelkem',
    'Kc0', 'KcZaokr', 'Kc1', 'KcDPH1', 'Kc2', 'KcDPH2', 'Kc3', 'KcDPH3',
    'KcCelkem', 'RelStorn', 'TpStorn', 'TpUD', 'RelDruhUD', 'HistSzDPH'
)

$PohodaItemColumns = @(
    'ID', 'RefAg', 'RelAgID', 'RefPol', 'SText', 'Mnozstvi', 'MJ', 'RelSzDPH',
    'ProcentoDPH', 'KcJedn', 'Kc', 'KcDPH', 'RelTpDPH', 'OrderFld'
)

$PohodaAccountingTables = [ordered]@{
    FA       = $PohodaDocumentColumns + @('RelTpFak')
    FApol    = $PohodaItemColumns
    pUD      = @('ID', 'RelUdAg', 'Cislo', 'Datum', 'DatZdPln', 'SText', 'Kc', 'UMD', 'UD')
    pOS      = @('Ucet', 'Nazev')
    pPK      = @('ID', 'IDS', 'SText', 'UMD', 'UD', 'RelPkAg')
    sDPH     = @('ID', 'IDS', 'SText', 'RefTpDph', 'RelVlivKHDPH')
    sDPHTp   = @('ID', 'Radky')
    AD       = @('ID', 'Firma', 'Jmeno', 'Utvar', 'Ulice', 'Obec', 'PSC', 'ICO', 'DIC', 'RefZeme', 'Email', 'GSM', 'Tel')
    sUcet    = @('ID', 'RelJeUcet', 'IDS', 'SText', 'KodBanky', 'Banka', 'IBAN', 'AUcet')
    sZeme    = @('ID', 'IDS')
    sCMeny   = @('ID', 'Kod')
    sFormUh  = @('ID', 'RelTyp')
    BV       = $PohodaDocumentColumns + @('RelTpBV', 'RefUcet', 'DatPlat', 'Vypis')
    BVpol    = $PohodaItemColumns
    HO       = $PohodaDocumentColumns + @('RelTpHO', 'RefUcet', 'DatPlat')
    HOpol    = $PohodaItemColumns
    pINT     = $PohodaDocumentColumns
    pINTpol  = $PohodaItemColumns
    Uhrady   = @('ID', 'RelIDH', 'RelAgH', 'RelAgU', 'DatumU', 'RelIDU', 'CisloU', 'KcU')
    sKonfig  = @('ICO', 'Rok', 'RelRokTp', 'DatRokOd', 'DatRokDo', 'RelUTyp')
    Verze    = @('ID', 'Verze')
}

$PohodaRequiredTables = @(
    'pUD', 'pOS', 'sDPH', 'sDPHTp', 'FA', 'FApol', 'AD', 'pPK', 'sUcet',
    'sZeme', 'sCMeny', 'sFormUh', 'BV', 'BVpol', 'HO', 'HOpol', 'pINT',
    'pINTpol', 'Uhrady', 'sKonfig'
)

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

function Get-PohodaTableMap($Connection) {
    $tables = @{}
    foreach ($row in $Connection.GetSchema('Tables').Rows) {
        if ([string]$row['TABLE_TYPE'] -ne 'TABLE') {
            continue
        }
        $name = [string]$row['TABLE_NAME']
        if ($name -ne '') {
            $tables[$name.ToLowerInvariant()] = $name
        }
    }
    return $tables
}

function ConvertTo-PohodaXmlText($Value) {
    if ($Value -is [DateTime]) {
        return $Value.ToString('yyyy-MM-ddTHH:mm:ss', [Globalization.CultureInfo]::InvariantCulture)
    }
    if ($Value -is [bool]) {
        return $(if ($Value) { 'true' } else { 'false' })
    }
    if ($Value -is [decimal]) {
        return $Value.ToString([Globalization.CultureInfo]::InvariantCulture)
    }
    if ($Value -is [double]) {
        return $Value.ToString('R', [Globalization.CultureInfo]::InvariantCulture)
    }
    if ($Value -is [single]) {
        return $Value.ToString('R', [Globalization.CultureInfo]::InvariantCulture)
    }
    if ($Value -is [IFormattable]) {
        return $Value.ToString($null, [Globalization.CultureInfo]::InvariantCulture)
    }
    return [string]$Value
}

function Get-PohodaProgramVersion($Connection, [hashtable]$Tables) {
    if (-not $Tables.ContainsKey('verze')) {
        return ''
    }
    $table = ([string]$Tables['verze']).Replace(']', ']]')
    $presentColumns = Get-PohodaPresentColumns $Connection $table
    if (-not $presentColumns.ContainsKey('verze')) {
        return ''
    }
    $versionColumn = ([string]$presentColumns['verze']).Replace(']', ']]')
    $command = $Connection.CreateCommand()
    $command.CommandText = "SELECT TOP 1 [$versionColumn] FROM [$table]"
    $reader = $null
    try {
        $reader = $command.ExecuteReader()
        if (-not $reader.Read()) {
            return ''
        }
        if (-not $reader.IsDBNull(0)) {
            return [string]$reader.GetValue(0)
        }
        return ''
    } finally {
        if ($reader) {
            $reader.Close()
            $reader.Dispose()
        }
        $command.Dispose()
    }
}

function Get-PohodaPresentColumns($Connection, [string]$QuotedTableName) {
    $command = $Connection.CreateCommand()
    $command.CommandText = "SELECT * FROM [$QuotedTableName] WHERE 1 = 0"
    $reader = $null
    try {
        $reader = $command.ExecuteReader([System.Data.CommandBehavior]::SchemaOnly)
        $columns = @{}
        for ($i = 0; $i -lt $reader.FieldCount; $i++) {
            $columns[$reader.GetName($i).ToLowerInvariant()] = $reader.GetName($i)
        }
        return $columns
    } finally {
        if ($reader) {
            $reader.Close()
            $reader.Dispose()
        }
        $command.Dispose()
    }
}

function Get-PohodaAgendaMetadata($Connection, [hashtable]$Tables) {
    if (-not $Tables.ContainsKey('skonfig')) {
        throw 'Datový soubor neobsahuje tabulku sKonfig s identifikací účetní agendy.'
    }
    $table = ([string]$Tables['skonfig']).Replace(']', ']]')
    $presentColumns = Get-PohodaPresentColumns $Connection $table
    foreach ($required in 'ICO', 'Rok', 'RelRokTp', 'DatRokOd', 'DatRokDo') {
        if (-not $presentColumns.ContainsKey($required.ToLowerInvariant())) {
            throw "Tabulka sKonfig neobsahuje sloupec $required potřebný k ověření účetní agendy."
        }
    }

    $selected = @('ICO', 'Rok', 'RelRokTp', 'DatRokOd', 'DatRokDo') | ForEach-Object {
        '[' + ([string]$presentColumns[$_.ToLowerInvariant()]).Replace(']', ']]') + ']'
    }
    $command = $Connection.CreateCommand()
    $command.CommandText = 'SELECT TOP 1 ' + ($selected -join ', ') + " FROM [$table]"
    $reader = $null
    try {
        $reader = $command.ExecuteReader()
        if (-not $reader.Read() -or $reader.IsDBNull(0) -or $reader.IsDBNull(1)) {
            throw 'Tabulka sKonfig neobsahuje IČO a rok účetní agendy.'
        }
        $actualIco = (ConvertTo-PohodaXmlText $reader.GetValue(0)).Trim()
        $actualYear = 0
        if ($actualIco -notmatch '^\d{6,8}$' -or
            -not [int]::TryParse((ConvertTo-PohodaXmlText $reader.GetValue(1)), [ref]$actualYear)) {
            throw 'V tabulce sKonfig není platné IČO nebo rok účetní agendy.'
        }
        $actualIco = $actualIco.PadLeft(8, '0')
        return [pscustomobject]@{
            Ico = $actualIco
            Year = $actualYear
        }
    } finally {
        if ($reader) {
            $reader.Close()
            $reader.Dispose()
        }
        $command.Dispose()
    }
}

function Write-PohodaTable($Writer, $Connection, [string]$RequestedName, [string[]]$AllowedColumns, [hashtable]$Tables) {
    $lookup = $RequestedName.ToLowerInvariant()
    if (-not $Tables.ContainsKey($lookup)) {
        $Writer.WriteStartElement('table')
        $Writer.WriteAttributeString('name', $RequestedName)
        $Writer.WriteAttributeString('missing', 'true')
        $Writer.WriteEndElement()
        return 0
    }

    $actualName = [string]$Tables[$lookup]
    $quotedName = $actualName.Replace(']', ']]')
    $presentColumns = Get-PohodaPresentColumns $Connection $quotedName
    $columns = @()
    foreach ($allowed in $AllowedColumns) {
        $key = $allowed.ToLowerInvariant()
        if ($presentColumns.ContainsKey($key)) {
            $actualColumn = [string]$presentColumns[$key]
            $columns += [pscustomobject]@{
                Source = $actualColumn
                Name = [Xml.XmlConvert]::EncodeLocalName($actualColumn)
            }
        }
    }
    if ($columns.Count -eq 0) {
        throw "Tabulka $RequestedName neobsahuje žádný sloupec potřebný pro převod."
    }

    $command = $Connection.CreateCommand()
    $select = ($columns | ForEach-Object { '[' + $_.Source.Replace(']', ']]') + ']' }) -join ', '
    $command.CommandText = "SELECT $select FROM [$quotedName]"
    $reader = $null
    $rows = 0
    try {
        $reader = $command.ExecuteReader([System.Data.CommandBehavior]::SequentialAccess)
        $Writer.WriteStartElement('table')
        $Writer.WriteAttributeString('name', $RequestedName)
        while ($reader.Read()) {
            $Writer.WriteStartElement('row')
            for ($index = 0; $index -lt $columns.Count; $index++) {
                $column = $columns[$index]
                if ($reader.IsDBNull($index)) {
                    $Writer.WriteStartElement([string]$column.Name)
                    $Writer.WriteAttributeString('nil', 'true')
                    $Writer.WriteEndElement()
                    continue
                }
                $value = $reader.GetValue($index)
                if ($value -is [byte[]]) {
                    continue
                }
                $Writer.WriteElementString([string]$column.Name, (ConvertTo-PohodaXmlText $value))
            }
            $Writer.WriteEndElement()
            $rows++
        }
        $Writer.WriteEndElement()
        return $rows
    } finally {
        if ($reader) {
            $reader.Close()
            $reader.Dispose()
        }
        $command.Dispose()
    }
}

function New-PohodaZip([array]$Files, [string]$Destination) {
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $stream = [IO.File]::Open($Destination, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    $archive = $null
    try {
        $archive = New-Object -TypeName IO.Compression.ZipArchive -ArgumentList @(
            $stream,
            [IO.Compression.ZipArchiveMode]::Create,
            $false
        )
        foreach ($file in $Files) {
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                [string]$file.Source,
                [string]$file.Entry,
                [IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    } finally {
        if ($archive) {
            $archive.Dispose()
        }
        $stream.Dispose()
    }
}

if ($env:OS -ne 'Windows_NT') {
    throw 'Skript běží jen na Windows.'
}

$companion = Join-Path $PSScriptRoot 'Export-PohodaMdb.ps1'
if (-not (Test-Path -LiteralPath $companion -PathType Leaf)) {
    throw 'Chybí Export-PohodaMdb.ps1. Stáhněte a rozbalte celý ZIP převodníku, aby oba skripty ležely ve stejné složce.'
}

$icoWasSpecified = $PSBoundParameters.ContainsKey('Ico') -and -not [string]::IsNullOrWhiteSpace($Ico)
$yearWasSpecified = $PSBoundParameters.ContainsKey('Rok')
$interactive = [string]::IsNullOrWhiteSpace($Mdb)
if ($interactive) {
    $Mdb = Select-PohodaMdb
}
$mdbFile = Get-Item -LiteralPath $Mdb -ErrorAction Stop
if ($mdbFile.PSIsContainer) {
    throw "Cesta $Mdb není datový soubor."
}
[string]$mdbPath = [string]$mdbFile.FullName

if ($icoWasSpecified -and $Ico -notmatch '^\d{6,8}$') {
    throw 'IČO musí obsahovat 6 až 8 číslic.'
}
if ($icoWasSpecified) {
    $Ico = $Ico.PadLeft(8, '0')
}
$maxYear = (Get-Date).Year + 1
if ($yearWasSpecified -and ($Rok -lt 1990 -or $Rok -gt $maxYear)) {
    throw "Rok musí být celé číslo od 1990 do $maxYear."
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

$tables = Get-PohodaTableMap $connection
$missingRequired = @($PohodaRequiredTables | Where-Object { -not $tables.ContainsKey($_.ToLowerInvariant()) })
if ($missingRequired.Count -gt 0) {
    $connection.Close()
    $connection.Dispose()
    throw 'Datový soubor neobsahuje povinné účetní tabulky: ' + ($missingRequired -join ', ')
}
try {
    $metadata = Get-PohodaAgendaMetadata $connection $tables
} catch {
    $connection.Close()
    $connection.Dispose()
    throw
}
if ($metadata.Year -lt 1990 -or $metadata.Year -gt $maxYear) {
    $connection.Close()
    $connection.Dispose()
    throw "Datový soubor uvádí nepodporovaný účetní rok $($metadata.Year)."
}
if ($icoWasSpecified -and $Ico -ne $metadata.Ico) {
    $connection.Close()
    $connection.Dispose()
    throw "Zadané IČO $Ico neodpovídá IČO $($metadata.Ico) uloženému v datovém souboru."
}
if ($yearWasSpecified -and $Rok -ne $metadata.Year) {
    $connection.Close()
    $connection.Dispose()
    throw "Zadaný rok $Rok neodpovídá roku $($metadata.Year) uloženému v datovém souboru."
}
$Ico = $metadata.Ico
$Rok = $metadata.Year
if ($interactive -or $PotvrditMetadata) {
    Write-Host "Datový soubor obsahuje IČO $Ico a účetní rok $Rok."
    $answer = (Read-Host 'Pokračovat s těmito údaji? [A/n]').Trim()
    if ($answer -notin @('', 'a', 'A', 'ano', 'Ano', 'ANO')) {
        $connection.Close()
        $connection.Dispose()
        throw 'Export byl zrušen uživatelem.'
    }
}
$programVersion = Get-PohodaProgramVersion $connection $tables

$outputRoot = [IO.Path]::GetFullPath($Vystup)
if (-not (Test-Path -LiteralPath $outputRoot)) {
    New-Item -ItemType Directory -Path $outputRoot | Out-Null
}
$agendaDir = Join-Path $outputRoot ("{0}_{1}" -f $Ico, $Rok)
$target = Join-Path $agendaDir '89_ucetnictvi_mdb.xml'
$assetTarget = Join-Path $agendaDir '90_majetek.xml'
$payrollTarget = Join-Path $agendaDir '91_mzdy.xml'
foreach ($existingTarget in @($target, $assetTarget, $payrollTarget)) {
    if (Test-Path -LiteralPath $existingTarget) {
        $connection.Close()
        $connection.Dispose()
        throw "Výstup $existingTarget už existuje. Smažte ho nebo zvolte jinou výstupní složku."
    }
}
$zipTarget = $agendaDir + '.zip'
if (-not $BezZip -and (Test-Path -LiteralPath $zipTarget)) {
    $connection.Close()
    $connection.Dispose()
    throw "ZIP $zipTarget už existuje. Smažte ho nebo zvolte jinou výstupní složku."
}
if (-not (Test-Path -LiteralPath $agendaDir)) {
    New-Item -ItemType Directory -Path $agendaDir | Out-Null
}

$temp = Join-Path $agendaDir ('89_ucetnictvi_mdb.xml.' + [Guid]::NewGuid().ToString('N') + '.tmp')
$writer = $null
try {
    $settings = New-Object System.Xml.XmlWriterSettings
    $settings.Encoding = New-Object System.Text.UTF8Encoding($false)
    $settings.Indent = $true
    $settings.CheckCharacters = $true
    $writer = [Xml.XmlWriter]::Create($temp, $settings)
    $writer.WriteStartDocument()
    $writer.WriteStartElement('pohodaMdbAccounting')
    $writer.WriteAttributeString('formatVersion', '1')
    $writer.WriteAttributeString('ico', $Ico)
    $writer.WriteAttributeString('year', [string]$Rok)
    $writer.WriteAttributeString('programVersion', $programVersion)

    $totalRows = 0
    foreach ($table in $PohodaAccountingTables.Keys) {
        $count = Write-PohodaTable $writer $connection $table $PohodaAccountingTables[$table] $tables
        $totalRows += $count
        Write-Host ("  {0,-12} {1,8} řádků" -f $table, $count)
    }

    $writer.WriteEndElement()
    $writer.WriteEndDocument()
    $writer.Flush()
    $writer.Close()
    $writer = $null
    $connection.Close()
    $connection.Dispose()
    $connection = $null

    Move-Item -LiteralPath $temp -Destination $target
    Write-Host "Hotovo: $target ($totalRows řádků)" -ForegroundColor Green

    Write-Host 'Exportuji majetek a mzdy...'
    try {
        & $companion -Mdb $mdbPath -Vystup $agendaDir -Skupiny @('majetek', 'mzdy')
    } catch {
        foreach ($createdTarget in @($target, $assetTarget, $payrollTarget)) {
            if (Test-Path -LiteralPath $createdTarget) {
                Remove-Item -LiteralPath $createdTarget -Force
            }
        }
        throw
    }

    if (-not $BezZip) {
        $zipTemp = $agendaDir + '.' + [Guid]::NewGuid().ToString('N') + '.tmp.zip'
        try {
            $agendaName = Split-Path $agendaDir -Leaf
            $zipFiles = @(
                [pscustomobject]@{ Source = $target; Entry = $agendaName + '/89_ucetnictvi_mdb.xml' }
            )
            foreach ($optionalTarget in @($assetTarget, $payrollTarget)) {
                if (Test-Path -LiteralPath $optionalTarget -PathType Leaf) {
                    $zipFiles += [pscustomobject]@{
                        Source = $optionalTarget
                        Entry = $agendaName + '/' + (Split-Path $optionalTarget -Leaf)
                    }
                }
            }
            New-PohodaZip -Files $zipFiles -Destination $zipTemp
            Move-Item -LiteralPath $zipTemp -Destination $zipTarget
            Write-Host "ZIP: $zipTarget" -ForegroundColor Green
        } finally {
            if (Test-Path -LiteralPath $zipTemp) {
                Remove-Item -LiteralPath $zipTemp -Force
            }
        }
    }
} finally {
    if ($writer) {
        $writer.Close()
    }
    if ($connection) {
        $connection.Close()
        $connection.Dispose()
    }
    if (Test-Path -LiteralPath $temp) {
        Remove-Item -LiteralPath $temp -Force
    }
}
