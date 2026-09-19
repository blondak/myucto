<#
.SYNOPSIS
    Vytáhne mzdy z datového souboru programu PAMICA do ZIPu pro převod do MyÚčta.

.DESCRIPTION
    PAMICA nemá XML rozhraní jako POHODA, takže se čte přímo z datového souboru
    (Mzdy*.mdb). Skript z něj udělá ZIP ve tvaru, který čeká průvodce „Přechod
    z PAMICA" v MyÚčtu:

      <IČO>_<rok>\91_mzdy.xml      zaměstnanci, pracovní poměry, zpracované mzdy,
                                   srážky a exekuce, podání pro ČSSZ a zdravotní
                                   pojišťovny, platby a číselníky mezd

    Pro každý rok, který je v datech, vznikne vlastní složka. Kmenové údaje
    (zaměstnanci, pracovní poměry, pracovní místa) a číselníky jsou v každém roce
    celé, záznamy vázané na rok (mzdy a vše, co z nich visí) jen za ten rok.

    Skript data jen čte, nic v nich nemění (připojení v režimu Read). Vynechává
    sloupce se systémovými údaji (kdo a kdy záznam založil nebo změnil, značky,
    zámky). U podání a plateb bere jen sloupce, které převod potřebuje - jméno,
    rodné číslo ani adresu osoby z nich netahá, převod osobu páruje přes pracovní
    poměr.

    Před spuštěním PAMICU zavřete.

    Požadavky: Windows a ovladač Microsoft Access Database Engine (instaluje se
    s PAMICOU); skript si podle potřeby sám zvolí 32bitový PowerShell.

.PARAMETER Mdb
    Cesta k datovému souboru PAMICA (Mzdy*.mdb). Bez zadání si ho skript najde sám
    v obvyklých umístěních.

.PARAMETER Rok
    Roky k vytažení. Bez zadání všechny roky, které jsou ve zpracovaných mzdách.

.PARAMETER Ico
    IČO firmy pro název složky. Bez zadání se bere z datového souboru.

.PARAMETER Vystup
    Složka, kam se zapíše ZIP a souhrn. Bez zadání složka vedle skriptu.

.EXAMPLE
    .\Export-Pamica.ps1

.EXAMPLE
    .\Export-Pamica.ps1 -Mdb "C:\ProgramData\STORMWARE\PAMICA\Data\Mzdy.mdb" -Rok 2025,2026
#>
[CmdletBinding()]
param(
    [string]$Mdb,
    [int[]]$Rok,
    [string]$Ico,
    [string]$Vystup
)

$ErrorActionPreference = 'Stop'

# Rozhodné období nemocenských dávek: dvanáct měsíců, každý s datem, příjmem a vyloučenými dny.
$PamicaNempriObdobi = @()
foreach ($i in 1..12) { $PamicaNempriObdobi += @("DatR$i", "KcPrijR$i", "VyldnyR$i") }

<#
    Tabulky, které se vytahují, v pořadí zápisu do XML. Chybějící tabulka (jiná verze
    PAMICY) se přeskočí bez chyby.

      Sloupce   jen vyjmenované sloupce (ochrana osobních údajů), jinak všechny
      Kde       podmínka roku; `{rok}` se nahradí rokem. Bez ní je tabulka v každém
                roce celá (kmen, číselníky).
      Zavisi    tabulka, kterou podmínka potřebuje; když chybí, přeskočí se obojí
      KdeNebo   sloupec a podmínka navíc (spojí se přes OR), použije se jen když
                ten sloupec v tabulce je
#>
$PamicaTables = [ordered]@{
    # --- kmen a vztahy ---
    ZAM            = @{}
    ZAMpomer       = @{}
    ZAMpDet        = @{}
    ZAMucet        = @{}
    ZAMpoj         = @{}
    ZAMzp          = @{ Sloupce = @('ID', 'RefAg', 'RefPomer', 'RelKod', 'RefPoj', 'RefStav', 'DatStav', 'Datum') }
    ZAMzivPoj      = @{}
    PracMista      = @{}
    # --- mzdy ---
    MZ             = @{ Kde = 'Rok = {rok}' }
    MZ2            = @{ Kde = 'RefAg IN (SELECT ID FROM [MZ] WHERE Rok = {rok})'; Zavisi = 'MZ' }
    # Trvalé mzdové složky visí na pracovním poměru, ne na mzdě - patří do každého roku.
    MZslozky       = @{ Kde = 'RefAg IN (SELECT ID FROM [MZ] WHERE Rok = {rok})'; Zavisi = 'MZ'
        KdeNebo = @('Trvale', 'Trvale <> 0') }
    MZneprit       = @{ Kde = 'RefAg IN (SELECT ID FROM [MZ] WHERE Rok = {rok})'; Zavisi = 'MZ' }
    MZsrazky       = @{ Kde = 'RefAg IN (SELECT ID FROM [MZ] WHERE Rok = {rok})'; Zavisi = 'MZ' }
    MZdavky        = @{ Kde = 'Rok = {rok}' }
    MZnahr         = @{ Kde = 'RefAg IN (SELECT ID FROM [MZ] WHERE Rok = {rok})'; Zavisi = 'MZ' }
    MZdoch         = @{ Kde = 'Rok = {rok}' }
    MZzauct        = @{ Kde = 'Rok = {rok}' }
    MZzauctRoz     = @{ Kde = 'Rok = {rok}' }
    Dovolena       = @{ Kde = 'Rok = {rok}' }
    zalZAM         = @{}
    # --- srážky a exekuce (včetně příjemce a rozpadu nezabavitelné částky) ---
    ZAMsrazky      = @{}
    rpZAMprijemSraz = @{}
    # --- podání a hlášení ---
    RegZAM         = @{ Sloupce = @('ID', 'RelStavDP', 'DatPod', 'DatPrij', 'ElOdeslano') }
    RegZAMitems    = @{ Sloupce = @('ID', 'RefAg', 'RefZAM', 'RefPomer', 'Sqnr', 'RelTyp', 'OIC', 'IDPPV') }
    ONZ            = @{ Sloupce = @('ID', 'RelStavDP', 'DatPod', 'DatPrij', 'ElOdeslano') }
    ONZpol         = @{ Sloupce = @('ID', 'RefAg', 'RefPomer', 'RelTyp', 'PlatneOd', 'PojisteniOd', 'DatVstup', 'DatOdch', 'OSSZ',
            'DuvodUkonceni', 'DuvodUkonceniSP', 'Odstupne1', 'Odstupne2', 'Odchodne', 'Odbytne', 'PrumVydelek', 'DDrDuch', 'DatDuchodOd') }
    ELDP           = @{ Sloupce = @('ID', 'Rok', 'RefStavDP', 'DatPod', 'DatPrij', 'ElOdeslano'); Kde = 'Rok = {rok}' }
    ELDPpol        = @{ Sloupce = @('ID', 'RefAg', 'Rok', 'TypELDP', 'RefStavDP', 'RefZAMpomer1', 'RefZAMpomer2', 'RefZAMpomer3'); Kde = 'Rok = {rok}' }
    MH             = @{ Sloupce = @('ID', 'RefID', 'RelTyp', 'RelStavDP', 'RelMesic', 'Rok', 'DatPod', 'DatPrij', 'ElOdeslano',
            'DatPodDZMH', 'DatPrijDZMH', 'RelStavDPDZMH'); Kde = 'Rok = {rok}' }
    MHitems        = @{ Sloupce = @('ID', 'RefAg', 'RefZAM', 'RefPomer', 'RelDruhZ', 'OIC', 'RelTyp', 'Soubeh')
        Kde = 'RefAg IN (SELECT ID FROM [MH] WHERE Rok = {rok})'; Zavisi = 'MH' }
    NEMPRI         = @{ Sloupce = @('ID', 'RefStavDP', 'DatPod', 'DatPrij', 'NEMPRI25', 'ElOdeslano')
        Kde = 'ID IN (SELECT RefAg FROM [NEMPRIpol] WHERE RokMZ = {rok})'; Zavisi = 'NEMPRIpol' }
    NEMPRIpol      = @{ Sloupce = @('ID', 'RefAg', 'PoradCis', 'RokMZ', 'MesicMZ', 'RefZAMpomer', 'RefMZ', 'RefNeprit', 'CisPotvrz',
            'KodOSSZ', 'NazevOSSZ', 'DruhDavky', 'ZamOd', 'ZamDo', 'RelDruhZ', 'RozObdOd', 'RozObdDo') + $PamicaNempriObdobi + @(
            'PravVysPrij', 'PocOdHod', 'PracDob', 'Pracoval', 'KcPrijMR', 'PobiraDuch', 'DruhDuch', 'JeStudent', 'SpadaDoPrazd',
            'VolnPrvZamest', 'VolnoBezNahr', 'VolnoBezNahrOd', 'VolnoBezNahrDo', 'NastupujePPM', 'NerDVZPPM', 'PrJinPrace',
            'Srazka', 'Insolvence', 'PrevodDatum', 'PocetPriloh', 'RefStavDP', 'JeOpravne', 'RelDuvodPece', 'RelDuvodOtcovske',
            'OdeDne', 'DoDne', 'RelKodVztah', 'Onemocnela', 'NarizenKaran', 'NemuzePecovat', 'ZarizeniUzavreno', 'SpolecDoma',
            'JeOsamely', 'DiteDo16Let', 'JeStridani', 'NarokPPM', 'NarokRP', 'JinaFOParagraf57', 'PecovalOsobne', 'RelKodRodVztah',
            'PracovalPoslDenPD', 'PocetHodinPoslDenPD', 'PracDobaPoslDenPD', 'DatNavratDoPrace', 'PlanovSmeny', 'PlanovSmenyOdprac',
            'MaVolno', 'Vznik', 'Trvani', 'Ukonceni')
        Kde = 'RokMZ = {rok}' }
    HZUPN          = @{ Sloupce = @('ID', 'RefStavDP', 'DatPod', 'DatPrij', 'ElOdeslano')
        Kde = 'ID IN (SELECT RefAg FROM [HZUPNpol] WHERE RokMZ = {rok})'; Zavisi = 'HZUPNpol' }
    HZUPNpol       = @{ Sloupce = @('ID', 'RefAg', 'RefZAMpomer', 'RefMZ', 'RefNeprit', 'RefStavDP', 'PoradCis', 'RokMZ', 'MesicMZ',
            'Zahranicni', 'CisloPotvrzeni', 'KodOSSZ', 'NazevOSSZ', 'DatumVystaveni', 'OpravnePodani', 'NavratDoPrace',
            'DuvodNavratuDoPrace', 'DatumNavratuDoPrace', 'PocetOdpracHodinPoslDenPD', 'PracovniDobaPoslDenPD', 'DuvodPisemnehoVystaveni')
        Kde = 'RokMZ = {rok}' }
    RocniZuctovani = @{ Sloupce = @('ID', 'RefZAM', 'Rok', 'RelMes', 'DatumVystaveni', 'DatumUzavreni', 'Uzavreno', 'CelkemRZ',
            'Preplatek', 'Doplatek', 'UpravaDane', 'ResStr'); Kde = 'Rok = {rok}' }
    # --- platby (příkazy k úhradě mezd, odvodů a srážek) ---
    Doklady        = @{ Sloupce = @('ID', 'RelTpDokl', 'Cislo', 'RelMes', 'Rok', 'Datum', 'DatUcP', 'DatSplat', 'DatPrik', 'VarSym',
            'ParSym', 'SpecSym', 'KonstSym', 'KcCelkem', 'KcP', 'RefCM', 'CmMnoz', 'CmKurs', 'CmCelkem', 'CmP', 'Firma', 'Ucet',
            'KodBanky', 'RelForUh', 'SOperace', 'ResPk', 'ResStr', 'ResCin', 'ResZak', 'RelStav', 'RelVyriz', 'DatVyriz', 'RefZAM', 'RelCR')
        Kde = 'Rok = {rok}' }
    DokladyPol     = @{ Sloupce = @('ID', 'RefAg', 'Kc', 'ResPk', 'ResStr', 'ResCin', 'ResZak', 'OrderFld')
        Kde = 'RefAg IN (SELECT ID FROM [Doklady] WHERE Rok = {rok})'; Zavisi = 'Doklady' }
    BP             = @{ Sloupce = @('ID', 'RelTpBP', 'SEPA', 'Polozky', 'Datum', 'DatSplat', 'DatExport', 'RefUcet', 'KcCelkem',
            'CmCelkem', 'KonstSym'); Kde = 'YEAR(Datum) = {rok}' }
    BPpol          = @{ Sloupce = @('ID', 'RefAg', 'RelAgH', 'RelIDH', 'Cislo', 'Firma', 'DIC', 'Ucet', 'KodBanky', 'KonstSym',
            'SpecSym', 'VarSym', 'Kc', 'Cm', 'BpVrac', 'OrderFld', 'RelPoplTp', 'RefPoplUcet', 'PlatTitul', 'RefCM', 'Cizozemec',
            'PrijNazev', 'BankaNazev')
        Kde = 'RefAg IN (SELECT ID FROM [BP] WHERE YEAR(Datum) = {rok})'; Zavisi = 'BP' }
    # --- číselníky ---
    sMZslozky      = @{}
    sMZneprit      = @{}
    sMZsrazky      = @{}
    sMzPoj         = @{}
    sMzFond        = @{}
    sMzMist        = @{}
    sMzZivPj       = @{}
    sMzDIP         = @{}
    sMzPDP         = @{}
    sSTR           = @{}
    pPK            = @{}
    pOS            = @{}
    # --- metadata ---
    Verze          = @{}
}

# Systémové sloupce, které se nevytahují: kdo a kdy záznam založil a změnil, výběr, značky, zámky.
$PamicaSkipColumns = @('Oznacil', 'Ucetni', 'Creator', 'DatCreate', 'DatSave', 'Sel', 'Labels', 'Lock', 'Lock1', 'UsrOrder')

# Obvyklá umístění datového souboru PAMICA.
function Get-PamicaDataDirs {
    $dirs = New-Object System.Collections.Generic.List[string]
    foreach ($base in @($env:ProgramData, ${env:ProgramFiles(x86)}, $env:ProgramFiles, 'C:\')) {
        if ($base) { $dirs.Add((Join-Path $base 'STORMWARE\PAMICA\Data')) }
    }
    foreach ($drive in [IO.DriveInfo]::GetDrives()) {
        if (-not $drive.IsReady) { continue }
        if (@('Fixed', 'Network', 'Removable') -notcontains $drive.DriveType.ToString()) { continue }
        $dirs.Add((Join-Path $drive.RootDirectory.FullName 'STORMWARE\PAMICA\Data'))
    }
    return @($dirs | Select-Object -Unique)
}

function Find-PamicaMdb {
    $found = New-Object System.Collections.Generic.List[string]
    foreach ($dir in (Get-PamicaDataDirs)) {
        if (-not (Test-Path -LiteralPath $dir)) { continue }
        foreach ($file in (Get-ChildItem -LiteralPath $dir -Filter 'Mzdy*.mdb' -File -ErrorAction SilentlyContinue)) {
            $found.Add($file.FullName)
        }
    }
    return @($found | Sort-Object -Unique)
}

function Open-PamicaSource([string]$Mdb) {
    if (-not (Test-Path -LiteralPath $Mdb)) { throw "Datový soubor $Mdb neexistuje." }
    foreach ($p in 'Microsoft.ACE.OLEDB.16.0', 'Microsoft.ACE.OLEDB.12.0', 'Microsoft.Jet.OLEDB.4.0') {
        try {
            $c = New-Object System.Data.OleDb.OleDbConnection "Provider=$p;Data Source=$Mdb;Mode=Read;Persist Security Info=False"
            $c.Open()
            return $c
        } catch { }
    }
    return $null
}

function Get-PamicaTables($Conn) {
    return @($Conn.GetSchema('Tables') | Where-Object { $_.TABLE_TYPE -eq 'TABLE' } | ForEach-Object { $_.TABLE_NAME })
}

function Get-PamicaRows($Conn, [string]$Sql) {
    $cmd = $Conn.CreateCommand()
    $cmd.CommandText = $Sql
    $table = New-Object System.Data.DataTable
    $r = $cmd.ExecuteReader()
    try { $table.Load($r) } finally { $r.Close() }
    return , $table
}

function Get-PamicaColumns($Conn, [string]$Table) {
    return @((Get-PamicaRows $Conn "SELECT * FROM [$Table] WHERE 1 = 0").Columns | ForEach-Object { $_.ColumnName })
}

function Get-PamicaScalar($Conn, [string]$Sql) {
    $cmd = $Conn.CreateCommand()
    $cmd.CommandText = $Sql
    return $cmd.ExecuteScalar()
}

<#
    IČO firmy z datového souboru (nastavení účetní jednotky). Prázdný řetězec = nenašlo se
    a uživatel ho musí zadat parametrem -Ico.
#>
function Get-PamicaIco($Conn, $Existing) {
    foreach ($pair in @(@('sKonfig', 'ICO'), @('sKonfig', 'IC'), @('Firma', 'ICO'), @('Firma', 'IC'), @('Verze', 'ICO'), @('Verze', 'IC'))) {
        $table = $pair[0]
        $column = $pair[1]
        if ($Existing -notcontains $table) { continue }
        if ((Get-PamicaColumns $Conn $table) -notcontains $column) { continue }
        $rows = Get-PamicaRows $Conn "SELECT [$column] FROM [$table]"
        foreach ($row in $rows.Rows) {
            $digits = ([string]$row[0]) -replace '\D', ''
            if ($digits.Length -ge 6 -and $digits.Length -le 8) { return $digits }
        }
    }
    return ''
}

<# Verze programu do hlavičky exportu, například `PAMICA 14226.2`. #>
function Get-PamicaProgram($Conn, $Existing) {
    if ($Existing -notcontains 'Verze') { return 'PAMICA' }
    $cols = Get-PamicaColumns $Conn 'Verze'
    $rows = Get-PamicaRows $Conn 'SELECT * FROM [Verze]'
    if ($rows.Rows.Count -eq 0) { return 'PAMICA' }
    $row = $rows.Rows[0]
    $name = if ($cols -contains 'Nazev') { [string]$row['Nazev'] } else { 'PAMICA' }
    $release = if ($cols -contains 'Release') { [string]$row['Release'] } else { '' }
    return (($name, $release) -join ' ').Trim()
}

function Write-PamicaValue($Writer, [string]$Name, $Value) {
    if ($Value -is [DBNull] -or $null -eq $Value) { return }
    if ($Value -is [byte[]]) { return }
    $text = switch ($Value.GetType().Name) {
        'DateTime' { $Value.ToString('yyyy-MM-dd') }
        'Boolean'  { if ($Value) { '1' } else { '0' } }
        'Decimal'  { $Value.ToString([Globalization.CultureInfo]::InvariantCulture) }
        'Double'   { $Value.ToString('R', [Globalization.CultureInfo]::InvariantCulture) }
        'Single'   { $Value.ToString('R', [Globalization.CultureInfo]::InvariantCulture) }
        default    { [string]$Value }
    }
    if ($text -eq '') { return }
    $Writer.WriteElementString($Name, $text)
}

<#
    Mzdy jednoho roku do `91_mzdy.xml`. Vrací počty řádků po tabulkách (uspořádaný slovník).
#>
function Export-PamicaYear($Conn, [string]$Cil, [string]$Ico, [int]$Rok, [string]$Program, $Existing) {
    $tmp = "$Cil.tmp"
    $settings = New-Object System.Xml.XmlWriterSettings
    $settings.Encoding = New-Object System.Text.UTF8Encoding($false)
    $settings.Indent = $true
    $w = [System.Xml.XmlWriter]::Create($tmp, $settings)
    $counts = [ordered]@{}
    try {
        $w.WriteStartDocument()
        $w.WriteStartElement('mdbExport')
        $w.WriteAttributeString('version', '1')
        $w.WriteAttributeString('group', 'mzdy')
        $w.WriteAttributeString('ico', $Ico)
        $w.WriteAttributeString('year', [string]$Rok)
        $w.WriteAttributeString('source', 'PAMICA')
        $w.WriteAttributeString('programVersion', $Program)
        $w.WriteAttributeString('state', 'ok')
        $w.WriteAttributeString('created', (Get-Date -Format 'yyyy-MM-ddTHH:mm:ss'))
        foreach ($name in $PamicaTables.Keys) {
            if ($Existing -notcontains $name) { continue }
            $def = $PamicaTables[$name]
            if ($def.Zavisi -and $Existing -notcontains $def.Zavisi) { continue }
            $present = Get-PamicaColumns $Conn $name
            $select = '*'
            if ($def.Sloupce) {
                $wanted = @($def.Sloupce | Where-Object { $present -contains $_ })
                if ($wanted.Count -eq 0) { continue }
                $select = ($wanted | ForEach-Object { "[$_]" }) -join ', '
            }
            $sql = "SELECT $select FROM [$name]"
            if ($def.Kde) {
                $kde = $def.Kde
                if ($def.KdeNebo -and $present -contains $def.KdeNebo[0]) { $kde = "($kde) OR ($($def.KdeNebo[1]))" }
                $sql += ' WHERE ' + ($kde -replace '\{rok\}', [string]$Rok)
            }
            $table = Get-PamicaRows $Conn $sql
            $skip = @($table.Columns | Where-Object { $PamicaSkipColumns -contains $_.ColumnName -or $_.ColumnName -notmatch '^[A-Za-z_][A-Za-z0-9_]*$' })
            $take = @($table.Columns | Where-Object { $skip -notcontains $_ })
            foreach ($row in $table.Rows) {
                $w.WriteStartElement($name)
                foreach ($col in $take) {
                    Write-PamicaValue $w $col.ColumnName $row[$col]
                }
                $w.WriteEndElement()
            }
            $counts[$name] = $table.Rows.Count
        }
        $w.WriteEndElement()
        $w.WriteEndDocument()
    } finally { $w.Close() }
    Move-Item -Force $tmp $Cil
    return $counts
}

# --- spuštění ---

if ($env:OS -ne 'Windows_NT') {
    Write-Host 'Skript běží jen na Windows.' -ForegroundColor Red
    exit 1
}

Write-Host 'Export mezd z PAMICY. Než budete pokračovat, PAMICU zavřete - ze souboru se jen čte, ale otevřený program ho může zamykat.' -ForegroundColor Yellow

if (-not $Mdb) {
    $nalezene = Find-PamicaMdb
    if ($nalezene.Count -eq 0) {
        Write-Host 'Datový soubor PAMICY (Mzdy*.mdb) se nenašel. Zadejte ho parametrem -Mdb, cestu najdete v PAMICE v Soubor - Databáze.' -ForegroundColor Red
        exit 1
    }
    if ($nalezene.Count -gt 1) {
        Write-Host 'Datových souborů PAMICY je víc, vyberte jeden parametrem -Mdb:' -ForegroundColor Yellow
        foreach ($f in $nalezene) { Write-Host "  $f" }
        exit 1
    }
    $Mdb = $nalezene[0]
}
$Mdb = (Resolve-Path -LiteralPath $Mdb).Path
Write-Host "Datový soubor: $Mdb"

if (-not $Vystup) { $Vystup = $PSScriptRoot }
New-Item -ItemType Directory -Force $Vystup | Out-Null
$Vystup = (Resolve-Path -LiteralPath $Vystup).Path

$probe = Open-PamicaSource $Mdb
if ($null -eq $probe -and [Environment]::Is64BitProcess) {
    # Ovladač Accessu bývá jen 32bitový (instaluje ho 32bitová PAMICA) - zkusíme 32bitový PowerShell.
    $ps32 = Join-Path $env:WINDIR 'SysWOW64\WindowsPowerShell\v1.0\powershell.exe'
    if (-not (Test-Path -LiteralPath $ps32)) {
        Write-Host 'Datový soubor nejde otevřít: chybí ovladač Microsoft Access Database Engine.' -ForegroundColor Red
        exit 1
    }
    Write-Host 'Ovladač pro .mdb v 64bitovém PowerShellu chybí, spouštím 32bitový...'
    $q = { param($s) "'" + ($s -replace "'", "''") + "'" }
    $args32 = "-Mdb {0} -Vystup {1}" -f (& $q $Mdb), (& $q $Vystup)
    if ($Rok) { $args32 += ' -Rok ' + (($Rok | ForEach-Object { [string]$_ }) -join ',') }
    if ($Ico) { $args32 += ' -Ico ' + (& $q $Ico) }
    & $ps32 -NoProfile -ExecutionPolicy Bypass -Command ("& {0} {1}" -f (& $q $PSCommandPath), $args32)
    exit $LASTEXITCODE
}
if ($null -eq $probe) {
    Write-Host 'Datový soubor nejde otevřít: chybí ovladač Microsoft Access Database Engine.' -ForegroundColor Red
    exit 1
}
$conn = $probe

try {
    $existing = Get-PamicaTables $conn
    if ($existing -notcontains 'MZ' -or $existing -notcontains 'ZAM') {
        throw "V $Mdb nejsou mzdové tabulky (ZAM, MZ) - není to datový soubor PAMICY."
    }

    if (-not $Ico) { $Ico = Get-PamicaIco $conn $existing }
    $Ico = $Ico -replace '\D', ''
    if ($Ico.Length -lt 6 -or $Ico.Length -gt 8) {
        throw 'IČO firmy se v datovém souboru nenašlo. Spusťte skript znovu s parametrem -Ico <IČO firmy>.'
    }

    $program = Get-PamicaProgram $conn $existing
    $dostupneRoky = @((Get-PamicaRows $conn 'SELECT DISTINCT Rok FROM [MZ] WHERE Rok BETWEEN 1990 AND 2100 ORDER BY Rok').Rows | ForEach-Object { [int]$_[0] })
    if ($dostupneRoky.Count -eq 0) { throw 'V datovém souboru nejsou zpracované mzdy (tabulka MZ je prázdná).' }

    $roky = if ($Rok) { @($Rok | Where-Object { $dostupneRoky -contains $_ } | Sort-Object -Unique) } else { $dostupneRoky }
    if ($roky.Count -eq 0) {
        throw ('Zadané roky v datovém souboru nejsou. K dispozici: ' + ($dostupneRoky -join ', ') + '.')
    }

    $stamp = Get-Date -Format 'yyyyMMdd-HHmm'
    $stage = Join-Path $Vystup "pamica_export_$stamp"
    if (Test-Path -LiteralPath $stage) { Remove-Item -Recurse -Force -LiteralPath $stage }
    New-Item -ItemType Directory -Force $stage | Out-Null

    $zam = [int](Get-PamicaScalar $conn 'SELECT COUNT(*) FROM [ZAM]')
    $pomery = [int](Get-PamicaScalar $conn 'SELECT COUNT(*) FROM [ZAMpomer]')
    $nastup = Get-PamicaScalar $conn 'SELECT MIN(DatNast) FROM [ZAMpomer] WHERE DatNast IS NOT NULL'
    $odchod = Get-PamicaScalar $conn 'SELECT MAX(DatOdch) FROM [ZAMpomer] WHERE DatOdch IS NOT NULL'

    $souhrn = New-Object System.Collections.Generic.List[string]
    $souhrn.Add("Export mezd z PAMICY")
    $souhrn.Add("Datový soubor: $Mdb")
    $souhrn.Add("Program: $program")
    $souhrn.Add("IČO: $Ico")
    $souhrn.Add("Vytvořeno: " + (Get-Date -Format 'yyyy-MM-dd HH:mm'))
    $souhrn.Add('')
    $souhrn.Add("Zaměstnanců: $zam")
    $souhrn.Add("Pracovních poměrů: $pomery")
    if ($nastup -isnot [DBNull] -and $null -ne $nastup) { $souhrn.Add("Nejstarší nástup: " + ([datetime]$nastup).ToString('yyyy-MM-dd')) }
    if ($odchod -isnot [DBNull] -and $null -ne $odchod) { $souhrn.Add("Poslední ukončení: " + ([datetime]$odchod).ToString('yyyy-MM-dd')) }
    $souhrn.Add('')

    Write-Host ''
    Write-Host ("IČO {0}, {1}" -f $Ico, $program)
    Write-Host ("Zaměstnanců: {0}, pracovních poměrů: {1}" -f $zam, $pomery)

    $chybejici = @($PamicaTables.Keys | Where-Object { $existing -notcontains $_ })
    if ($chybejici.Count -gt 0) {
        $souhrn.Add('V datovém souboru nejsou tabulky: ' + ($chybejici -join ', '))
        $souhrn.Add('')
        Write-Host ('V datovém souboru nejsou tabulky: ' + ($chybejici -join ', ')) -ForegroundColor Yellow
    }

    foreach ($r in $roky) {
        $slozka = Join-Path $stage ("{0}_{1}" -f $Ico, $r)
        New-Item -ItemType Directory -Force $slozka | Out-Null
        $cil = Join-Path $slozka '91_mzdy.xml'
        $counts = Export-PamicaYear $conn $cil $Ico $r $program $existing

        $mesice = @((Get-PamicaRows $conn "SELECT DISTINCT RelMes FROM [MZ] WHERE Rok = $r ORDER BY RelMes").Rows | ForEach-Object { [int]$_[0] })
        $obdobi = if ($mesice.Count -gt 0) { '{0:0000}-{1:00} .. {0:0000}-{2:00}' -f $r, $mesice[0], $mesice[-1] } else { 'bez mezd' }
        $mezd = if ($counts.Contains('MZ')) { $counts['MZ'] } else { 0 }
        $velikost = [math]::Round((Get-Item -LiteralPath $cil).Length / 1KB)

        Write-Host ("  {0}  mezd {1,6}  obdobi {2}  ({3} kB)" -f (Split-Path $slozka -Leaf), $mezd, $obdobi, $velikost) -ForegroundColor Green

        $souhrn.Add("=== rok $r ===")
        $souhrn.Add("Složka: " + (Split-Path $slozka -Leaf) + '\91_mzdy.xml')
        $souhrn.Add("Zpracovaných mezd: $mezd")
        $souhrn.Add("Období: $obdobi")
        $souhrn.Add('Řádky po tabulkách:')
        foreach ($t in $counts.Keys) {
            $souhrn.Add(("  {0,-18} {1,8}" -f $t, $counts[$t]))
        }
        $souhrn.Add('')
    }

    $zip = Join-Path $Vystup "pamica_export_$stamp.zip"
    if (Test-Path -LiteralPath $zip) { Remove-Item -Force -LiteralPath $zip }
    Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $zip -CompressionLevel Optimal
    Remove-Item -Recurse -Force -LiteralPath $stage

    $souhrnPath = Join-Path $Vystup "pamica_export_$stamp-souhrn.txt"
    Set-Content -LiteralPath $souhrnPath -Value $souhrn -Encoding UTF8

    Write-Host ''
    Write-Host "Souhrn: $souhrnPath"
    Write-Host "Nahrajte do MyÚčta soubor: $zip" -ForegroundColor Cyan
} catch {
    # Uživateli stačí věta, co je špatně; výpis volání by ho jen zmátl.
    Write-Host ''
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
} finally {
    $conn.Close()
}
