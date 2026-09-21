<#
.SYNOPSIS
    Vytáhne z datového souboru POHODY majetek a mzdy, které XML export POHODY neobsahuje.

.DESCRIPTION
    XML rozhraní POHODY nevrací dlouhodobý majetek (karty, odpisové plány) ani mzdy
    (zaměstnanci, pracovní poměry, zpracované mzdy). Tento skript je čte přímo z datového
    souboru a uloží je jako XML vedle souborů exportu, aby je MyÚčto při převodu načetlo:

      90_majetek.xml   karty majetku, daňové odpisy po letech, účetní odpisy po měsících
      91_mzdy.xml      zaměstnanci, pracovní poměry, zpracované mzdy, srážky a exekuce,
                       podání pro ČSSZ a zdravotní pojišťovny, platby a číselníky mezd
                       (bez jmen a rodných čísel)

    Skupina mzdy je omezená na rok agendy (podle složky <IČO>_<rok> nebo názvu datového
    souboru) - kmenové údaje a číselníky jsou celé, záznamy vázané na rok jen za ten rok.

    Skript data jen čte, nic v nich nemění. Vynechává sloupce se systémovými údaji
    (kdo a kdy záznam založil nebo změnil, značky, zámky). U podání a plateb bere jen
    sloupce, které převod potřebuje - jméno, rodné číslo ani adresu osoby z nich netahá,
    převod osobu páruje přes pracovní poměr.

    Na konci vypíše a zapíše vedle každého XML souboru (`<soubor>-souhrn.txt`) přehled:
    které tabulky ze seznamu v datovém souboru nejsou, které jsou prázdné, a kolik řádků
    má každá vytažená tabulka.

    Zdrojem může být:
      - datový soubor POHODY nebo PAMICA (.mdb) - parametr -Mdb,
      - databáze POHODA SQL - parametry -SqlServer a -Databaze.

    Export-Pohoda.ps1 tento skript volá sám pro každou exportovanou agendu. Samostatně ho
    spusťte, když máte jen datový soubor, nebo mzdy vede jiný program (PAMICA).

    Požadavky: Windows. Pro .mdb ovladač Microsoft Access Database Engine (instaluje se
    s POHODOU); skript si podle potřeby sám zvolí 32bitový PowerShell.

.PARAMETER Mdb
    Cesta k datovému souboru POHODY nebo PAMICA (.mdb).

.PARAMETER SqlServer
    Instance SQL serveru POHODA SQL, například .\POHODA.

.PARAMETER Databaze
    Název databáze agendy v POHODA SQL (StwPh_<IČO>_<rok>).

.PARAMETER Vystup
    Složka agendy exportu (<IČO>_<rok>), kam se soubory zapíšou. Bez zadání složka vedle skriptu.

.PARAMETER Skupiny
    Co vytáhnout: majetek, mzdy (výchozí obojí).

.EXAMPLE
    .\Export-PohodaMdb.ps1 -Mdb "C:\ProgramData\STORMWARE\POHODA\Data\StwPh_12345678_2026.mdb" -Vystup .\pohoda_export\12345678_2026

.EXAMPLE
    .\Export-PohodaMdb.ps1 -Mdb "D:\PAMICA\Data\Mzdy.mdb" -Skupiny mzdy -Vystup .\mzdy
#>
[CmdletBinding()]
param(
    [string]$Mdb,
    [string]$SqlServer,
    [string]$Databaze,
    [string]$Vystup,
    [ValidateSet('majetek', 'mzdy')][string[]]$Skupiny = @('majetek', 'mzdy')
)

$ErrorActionPreference = 'Stop'

# Rozhodné období nemocenských dávek: dvanáct měsíců, každý s datem, příjmem a vyloučenými dny.
$PohodaNempriObdobi = @()
foreach ($i in 1..12) { $PohodaNempriObdobi += @("DatR$i", "KcPrijR$i", "VyldnyR$i") }

<#
    Tabulky datového souboru po skupinách, v pořadí zápisu do XML. Chybějící tabulka (jiná
    verze nebo program) se přeskočí bez chyby.

      Sloupce   jen vyjmenované sloupce (ochrana osobních údajů u podání a plateb), jinak všechny
      Kde       podmínka roku (jen skupina mzdy); `{rok}` se nahradí rokem agendy. Bez ní je
                tabulka v každém roce celá (kmen, číselníky). Bez známého roku se nepoužije -
                tabulka se vytáhne celá jako dřív.
      Zavisi    tabulka, kterou podmínka potřebuje; když v souboru chybí, přeskočí se obojí
      KdeNebo   sloupec a podmínka navíc (spojí se přes OR), použije se jen když ten sloupec
                v tabulce je - trvalé mzdové složky visí na pracovním poměru, ne na mzdě
#>
$PohodaMdbGroups = [ordered]@{
    majetek = @{
        Soubor  = '90_majetek.xml'
        Klic    = @('IM', 'DM')
        Tabulky = [ordered]@{
            IM = @{}; IModpis = @{}; IModpisM = @{}; IMuodpis = @{}; IMpohyb = @{}; IMpredm = @{}
            IMclen = @{}; IMmist = @{}; sIMO = @{}; sIMOpol = @{}; DM = @{}; DMpohyb = @{}
        }
    }
    mzdy = @{
        Soubor  = '91_mzdy.xml'
        Klic    = @('ZAM', 'MZ')
        Tabulky = [ordered]@{
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
                    'KodOSSZ', 'NazevOSSZ', 'DruhDavky', 'ZamOd', 'ZamDo', 'RelDruhZ', 'RozObdOd', 'RozObdDo') + $PohodaNempriObdobi + @(
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
    }
}

# Zdrojový doklad karty drobného majetku: DM.RelAgID je kód agendy, DM.RefPol záznam v ní
# (položka dokladu, případně doklad). Kódy agend odpovídají pUD.RelUdAg (deník).
$PohodaDmSources = @{
    2  = @('FA', 'FApol', 'FA')
    27 = @('HO', 'HOpol', 'HO')
    29 = @('pINT', 'pINTpol', 'INT')
}

# Systémové sloupce, které se nevytahují: kdo a kdy záznam založil a změnil, výběr, značky, zámky.
$PohodaMdbSkipColumns = @('Oznacil', 'Ucetni', 'Creator', 'DatCreate', 'DatSave', 'Sel', 'Labels', 'Lock', 'Lock1', 'UsrOrder')

function Open-PohodaSource([string]$Mdb, [string]$SqlServer, [string]$Databaze) {
    if ($Mdb) {
        if (-not (Test-Path $Mdb)) { throw "Datový soubor $Mdb neexistuje." }
        foreach ($p in 'Microsoft.ACE.OLEDB.16.0', 'Microsoft.ACE.OLEDB.12.0', 'Microsoft.Jet.OLEDB.4.0') {
            try {
                $c = New-Object System.Data.OleDb.OleDbConnection "Provider=$p;Data Source=$Mdb;Mode=Read;Persist Security Info=False"
                $c.Open()
                return $c
            } catch { }
        }
        return $null
    }
    if ($SqlServer -and $Databaze) {
        $c = New-Object System.Data.SqlClient.SqlConnection "Server=$SqlServer;Database=$Databaze;Integrated Security=True;TrustServerCertificate=True;ApplicationIntent=ReadOnly"
        $c.Open()
        return $c
    }
    throw 'Zadejte datový soubor (-Mdb) nebo databázi POHODA SQL (-SqlServer a -Databaze).'
}

function Get-PohodaTables($Conn) {
    if ($Conn -is [System.Data.OleDb.OleDbConnection]) {
        return @($Conn.GetSchema('Tables') | Where-Object { $_.TABLE_TYPE -eq 'TABLE' } | ForEach-Object { $_.TABLE_NAME })
    }
    return @($Conn.GetSchema('Tables') | Where-Object { $_.TABLE_TYPE -eq 'BASE TABLE' } | ForEach-Object { $_.TABLE_NAME })
}

function Get-PohodaRows($Conn, [string]$Sql) {
    $cmd = $Conn.CreateCommand()
    $cmd.CommandText = $Sql
    $table = New-Object System.Data.DataTable
    $r = $cmd.ExecuteReader()
    try { $table.Load($r) } finally { $r.Close() }
    return , $table
}

<#
    Zdroj karty drobného majetku: číslo, datum, text a částka položky dokladu (nejdřív
    položka podle RefPol, jinak přímo doklad). $null = zdroj neznámý.
#>
function Resolve-PohodaDmSource($Conn, $Agenda, $Ref) {
    $agendaId = 0; $refId = 0
    if (-not [int]::TryParse([string]$Agenda, [ref]$agendaId) -or -not [int]::TryParse([string]$Ref, [ref]$refId)) { return $null }
    if ($refId -le 0 -or -not $PohodaDmSources.ContainsKey($agendaId)) { return $null }
    $doc, $pol, $code = $PohodaDmSources[$agendaId]
    $existing = Get-PohodaTables $Conn
    if ($existing -notcontains $doc) { return $null }
    if ($existing -contains $pol) {
        $t = Get-PohodaRows $Conn "SELECT d.Cislo, d.Datum, p.SText, p.Kc FROM [$pol] p INNER JOIN [$doc] d ON d.ID = p.RefAg WHERE p.ID = $refId"
        if ($t.Rows.Count -gt 0) {
            return @{ Agenda = $code; Cislo = $t.Rows[0][0]; Datum = $t.Rows[0][1]; Text = $t.Rows[0][2]; Kc = $t.Rows[0][3] }
        }
    }
    $t = Get-PohodaRows $Conn "SELECT Cislo, Datum FROM [$doc] WHERE ID = $refId"
    if ($t.Rows.Count -gt 0) {
        return @{ Agenda = $code; Cislo = $t.Rows[0][0]; Datum = $t.Rows[0][1]; Text = $null; Kc = $null }
    }
    return $null
}

function Write-PohodaValue($Writer, [string]$Name, $Value) {
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
    Vytáhne skupinu tabulek do XML souboru `$Cil`. Vrátí objekt s počtem řádků celkem
    (`Zaznamu`; 0 = soubor nevznikl, skupina v datovém souboru nemá data), počty řádků
    po jednotlivých vytažených tabulkách (`Pocty`, uspořádaný slovník) a seznamem tabulek,
    které v datovém souboru chybí (`Chybejici`).
#>
function Export-PohodaMdbGroup($Conn, [string]$Group, [string]$Cil, [string]$Ico, [string]$Rok) {
    $def = $PohodaMdbGroups[$Group]
    $existing = Get-PohodaTables $Conn
    $chybejici = @($def.Tabulky.Keys | Where-Object { $existing -notcontains $_ })
    # Skupina bez karet (majetek) nebo zaměstnanců a mezd se nevytahuje - samotné číselníky nic nepřevedou.
    $keyRows = 0
    foreach ($t in $def.Klic) {
        if ($existing -contains $t) {
            $cmd = $Conn.CreateCommand()
            $cmd.CommandText = "SELECT COUNT(*) FROM [$t]"
            $keyRows += [int]$cmd.ExecuteScalar()
        }
    }
    if ($keyRows -eq 0) {
        if (Test-Path $Cil) { Remove-Item -Force $Cil }
        return [pscustomobject]@{ Zaznamu = 0; Pocty = [ordered]@{}; Chybejici = $chybejici }
    }
    $tmp = "$Cil.tmp"
    $settings = New-Object System.Xml.XmlWriterSettings
    $settings.Encoding = New-Object System.Text.UTF8Encoding($false)
    $settings.Indent = $true
    $w = [System.Xml.XmlWriter]::Create($tmp, $settings)
    $rows = 0
    $counts = [ordered]@{}
    try {
        $w.WriteStartDocument()
        $w.WriteStartElement('mdbExport')
        $w.WriteAttributeString('version', '1')
        $w.WriteAttributeString('group', $Group)
        if ($Ico) { $w.WriteAttributeString('ico', $Ico) }
        if ($Rok) { $w.WriteAttributeString('year', $Rok) }
        $w.WriteAttributeString('source', 'POHODA')
        $w.WriteAttributeString('state', 'ok')
        $w.WriteAttributeString('created', (Get-Date -Format 'yyyy-MM-ddTHH:mm:ss'))
        foreach ($t in $def.Tabulky.Keys) {
            if ($existing -notcontains $t) { continue }
            $tdef = $def.Tabulky[$t]
            if ($tdef.Zavisi -and $existing -notcontains $tdef.Zavisi) { continue }
            # Celá tabulka do paměti: u drobného majetku se během zápisu dotazuje zdrojový doklad
            # a otevřený reader by druhý dotaz na tomtéž spojení nepustil.
            $present = @((Get-PohodaRows $Conn "SELECT * FROM [$t] WHERE 1 = 0").Columns | ForEach-Object { $_.ColumnName })
            $select = '*'
            if ($tdef.Sloupce) {
                $wanted = @($tdef.Sloupce | Where-Object { $present -contains $_ })
                if ($wanted.Count -eq 0) { continue }
                $select = ($wanted | ForEach-Object { "[$_]" }) -join ', '
            }
            $sql = "SELECT $select FROM [$t]"
            if ($tdef.Kde -and $Rok) {
                $kde = $tdef.Kde
                if ($t -eq 'MZdavky' -and $present -notcontains 'Rok') {
                    $mzColumns = @()
                    if ($existing -contains 'MZ') {
                        $mzColumns = @((Get-PohodaRows $Conn 'SELECT * FROM [MZ] WHERE 1 = 0').Columns | ForEach-Object { $_.ColumnName })
                    }
                    if ($present -notcontains 'RefAg' -or $mzColumns -notcontains 'ID' -or $mzColumns -notcontains 'Rok') {
                        throw "Tabulka MZdavky neobsahuje Rok ani použitelnou vazbu RefAg na MZ.ID; nelze ji bezpečně omezit na rok $Rok."
                    }
                    $kde = 'RefAg IN (SELECT ID FROM [MZ] WHERE Rok = {rok})'
                }
                if ($tdef.KdeNebo -and $present -contains $tdef.KdeNebo[0]) { $kde = "($kde) OR ($($tdef.KdeNebo[1]))" }
                $sql += ' WHERE ' + ($kde -replace '\{rok\}', [string]$Rok)
            }
            $table = Get-PohodaRows $Conn $sql
            foreach ($row in $table.Rows) {
                $w.WriteStartElement($t)
                foreach ($col in $table.Columns) {
                    $name = $col.ColumnName
                    if ($PohodaMdbSkipColumns -contains $name -or $name -notmatch '^[A-Za-z_][A-Za-z0-9_]*$') { continue }
                    Write-PohodaValue $w $name $row[$col]
                }
                if ($t -eq 'DM' -and $table.Columns.Contains('RelAgID') -and $table.Columns.Contains('RefPol')) {
                    $src = Resolve-PohodaDmSource $Conn $row['RelAgID'] $row['RefPol']
                    if ($src) {
                        Write-PohodaValue $w 'SrcAgenda' $src.Agenda
                        Write-PohodaValue $w 'SrcCislo' $src.Cislo
                        Write-PohodaValue $w 'SrcDatum' $src.Datum
                        Write-PohodaValue $w 'SrcText' $src.Text
                        Write-PohodaValue $w 'SrcKc' $src.Kc
                    }
                }
                $w.WriteEndElement()
                $rows++
            }
            $counts[$t] = $table.Rows.Count
        }
        $w.WriteEndElement()
        $w.WriteEndDocument()
    } finally { $w.Close() }
    if ($rows -gt 0) {
        Move-Item -Force $tmp $Cil
    } else {
        Remove-Item -Force $tmp
    }
    return [pscustomobject]@{ Zaznamu = $rows; Pocty = $counts; Chybejici = $chybejici }
}

<#
    Souhrn skupiny (počty řádků po tabulkách, chybějící a prázdné tabulky) do textového
    souboru vedle XML, ve stejném tvaru jako u Export-Pamica.ps1.
#>
function Write-PohodaMdbSummary([string]$Path, [string]$Group, $Result) {
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add("Export skupiny $Group z datového souboru POHODY")
    $lines.Add('Vytvořeno: ' + (Get-Date -Format 'yyyy-MM-dd HH:mm'))
    $lines.Add('')
    if ($Result.Chybejici.Count -gt 0) {
        $lines.Add('V datovém souboru nejsou tabulky: ' + ($Result.Chybejici -join ', '))
        $lines.Add('')
    }
    $prazdne = @($Result.Pocty.Keys | Where-Object { $Result.Pocty[$_] -eq 0 })
    if ($prazdne.Count -gt 0) {
        $lines.Add('Prázdné tabulky (v datovém souboru jsou, ale nic v nich není): ' + ($prazdne -join ', '))
        $lines.Add('')
    }
    $lines.Add('Řádky po tabulkách:')
    foreach ($t in $Result.Pocty.Keys) {
        $lines.Add(("  {0,-18} {1,8}" -f $t, $Result.Pocty[$t]))
    }
    Set-Content -LiteralPath $Path -Value $lines -Encoding UTF8
}

<#
    Pro Export-Pohoda.ps1: vytáhne všechny skupiny do složky agendy, včetně souhrnu vedle
    každého XML souboru (`<soubor>-souhrn.txt`). Vrací řádky souhrnu.
#>
function Export-PohodaMdbData([string]$Mdb, [string]$SqlServer, [string]$Databaze, [string]$Cil, [string]$Ico, [string]$Rok, [string[]]$Skupiny) {
    $conn = Open-PohodaSource $Mdb $SqlServer $Databaze
    if ($null -eq $conn) {
        throw 'Datový soubor nejde otevřít: chybí ovladač Microsoft Access Database Engine pro tuto verzi PowerShellu.'
    }
    try {
        foreach ($g in $Skupiny) {
            $soubor = $PohodaMdbGroups[$g].Soubor
            $file = Join-Path $Cil $soubor
            $result = Export-PohodaMdbGroup $conn $g $file $Ico $Rok
            $souhrnPath = Join-Path $Cil ($soubor -replace '\.xml$', '-souhrn.txt')
            Write-PohodaMdbSummary $souhrnPath $g $result
            [pscustomobject]@{ Skupina = $g; Soubor = $soubor; Zaznamu = $result.Zaznamu; Pocty = $result.Pocty; Chybejici = $result.Chybejici }
        }
    } finally { $conn.Close() }
}

# --- samostatné spuštění (při načtení z Export-Pohoda.ps1 se neprovede) ---
if ($MyInvocation.InvocationName -eq '.') { return }

if ($env:OS -ne 'Windows_NT') {
    Write-Host 'Skript běží jen na Windows.' -ForegroundColor Red
    exit 1
}
if (-not $Vystup) { $Vystup = Join-Path $PSScriptRoot ('pohoda_mdb_' + (Get-Date -Format 'yyyyMMdd_HHmmss')) }
New-Item -ItemType Directory -Force $Vystup | Out-Null
$Vystup = (Resolve-Path $Vystup).Path

$ico = ''
$rok = ''
if ((Split-Path $Vystup -Leaf) -match '^(\d{6,8})_(\d{4})$') { $ico = $matches[1]; $rok = $matches[2] }
elseif ($Mdb -and ([IO.Path]::GetFileNameWithoutExtension($Mdb) -match '(\d{6,8})_(\d{4})$')) { $ico = $matches[1]; $rok = $matches[2] }

if ($Mdb) {
    $probe = Open-PohodaSource $Mdb $null $null
    if ($null -eq $probe -and [Environment]::Is64BitProcess) {
        # Ovladač Access bývá jen 32bitový (instaluje ho 32bitová POHODA) - zkusíme 32bitový PowerShell.
        $ps32 = Join-Path $env:WINDIR 'SysWOW64\WindowsPowerShell\v1.0\powershell.exe'
        Write-Host 'Ovladač pro .mdb v 64bitovém PowerShellu chybí, spouštím 32bitový...'
        $q = { param($s) "'" + ($s -replace "'", "''") + "'" }
        & $ps32 -NoProfile -ExecutionPolicy Bypass -Command ("& {0} -Mdb {1} -Vystup {2} -Skupiny {3}" -f (& $q $PSCommandPath), (& $q $Mdb), (& $q $Vystup), ($Skupiny -join ','))
        exit $LASTEXITCODE
    }
    if ($probe) { $probe.Close() }
}

if (-not $rok -and $Skupiny -contains 'mzdy') {
    Write-Host 'Rok agendy se nepodařilo určit ze složky ani z názvu datového souboru - mzdy se vytáhnou bez omezení na rok.' -ForegroundColor Yellow
}

Write-Host "Výstup: $Vystup"
foreach ($row in (Export-PohodaMdbData $Mdb $SqlServer $Databaze $Vystup $ico $rok $Skupiny)) {
    if ($row.Zaznamu -gt 0) {
        Write-Host ("  {0,-8} {1,-16} {2,8} záznamů" -f $row.Skupina, $row.Soubor, $row.Zaznamu) -ForegroundColor Green
        foreach ($t in $row.Pocty.Keys) {
            Write-Host ("      {0,-18} {1,8}" -f $t, $row.Pocty[$t])
        }
    } else {
        Write-Host ("  {0,-8} v datovém souboru nic není" -f $row.Skupina) -ForegroundColor Yellow
    }
    if ($row.Chybejici.Count -gt 0) {
        Write-Host ("      chybí tabulky: " + ($row.Chybejici -join ', ')) -ForegroundColor Yellow
    }
}
Write-Host 'Hotovo. Soubory přidejte do ZIP exportu ke složce agendy (IČO_rok), nebo spusťte znovu Export-Pohoda.ps1.' -ForegroundColor Cyan
