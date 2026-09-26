[CmdletBinding()]
param()

# Obsah podání PAMICA (atributové bloby) musí oba exportéry rozepsat stejně: syntetický
# datový soubor s měsíčním hlášením, nečitelným blobem a doručenkou datové schránky.

$ErrorActionPreference = 'Stop'
$testRoot = Join-Path $env:TEMP ('pamica-attribute-export-' + [Guid]::NewGuid().ToString('N'))
$mdbPath = Join-Path $testRoot 'synthetic.mdb'
$connection = $null
$cp1250 = [Text.Encoding]::GetEncoding(1250)

function New-AttributeRecord([int]$Section, [int]$Id, [string]$Value, [int]$Flag, [int]$Order, [int]$TrailerLength) {
    $text = $cp1250.GetBytes($Value)
    $bytes = New-Object System.Collections.Generic.List[byte]
    $bytes.AddRange([BitConverter]::GetBytes([int32]$Section))
    $bytes.AddRange([BitConverter]::GetBytes([int32]$Id))
    $bytes.Add([byte]$text.Length)
    $bytes.AddRange($text)
    $bytes.Add(0)
    $bytes.AddRange([BitConverter]::GetBytes([int32]$Flag))
    $bytes.AddRange([BitConverter]::GetBytes([int32]$Order))
    if ($TrailerLength -eq 12) { $bytes.AddRange([BitConverter]::GetBytes([int32]0)) }
    return , $bytes.ToArray()
}

function New-AttributeBlob($Records) {
    $bytes = New-Object System.Collections.Generic.List[byte]
    $bytes.AddRange([BitConverter]::GetBytes([int32]1))
    $bytes.AddRange([BitConverter]::GetBytes([int32]$Records.Count))
    foreach ($r in $Records) { $bytes.AddRange([byte[]]$r) }
    return , $bytes.ToArray()
}

function Invoke-Sql($Conn, [string]$Sql, $Blob) {
    $command = $Conn.CreateCommand()
    try {
        $command.CommandText = $Sql
        if ($null -ne $Blob) {
            $p = $command.Parameters.Add('@blob', [System.Data.OleDb.OleDbType]::LongVarBinary)
            $p.Value = $Blob
        }
        $command.ExecuteNonQuery() | Out-Null
    } finally {
        $command.Dispose()
    }
}

New-Item -ItemType Directory -Path $testRoot | Out-Null
try {
    $provider = $null
    foreach ($candidate in 'Microsoft.ACE.OLEDB.16.0', 'Microsoft.ACE.OLEDB.12.0') {
        try {
            $catalog = New-Object -ComObject ADOX.Catalog
            $catalog.Create("Provider=$candidate;Data Source=$mdbPath;Jet OLEDB:Engine Type=5") | Out-Null
            $catalogConnection = $catalog.ActiveConnection
            $catalogConnection.Close()
            [Runtime.InteropServices.Marshal]::FinalReleaseComObject($catalogConnection) | Out-Null
            [Runtime.InteropServices.Marshal]::FinalReleaseComObject($catalog) | Out-Null
            $provider = $candidate
            break
        } catch {
            if (Test-Path -LiteralPath $mdbPath) { Remove-Item -LiteralPath $mdbPath -Force }
        }
    }
    if ($null -eq $provider) { throw 'Ovladač Microsoft Access Database Engine není k dispozici.' }

    $header = New-AttributeBlob @(
        (New-AttributeRecord 1 10001 '11111111-2222-3333-4444-555555555555' 1 0 12),
        (New-AttributeRecord 14 10029 '12345' 1 0 12)
    )
    $form = New-AttributeBlob @(
        (New-AttributeRecord 6 10228 '1234567890123' 1 0 12),
        (New-AttributeRecord 5 1 'bezPriznaku' 1 0 12),
        (New-AttributeRecord 6 10053 'Zkušební' 1 0 12),
        (New-AttributeRecord 13 10259 '160.000' 0 0 12),
        (New-AttributeRecord 3 10435 'Adéla' 1 0 12),
        (New-AttributeRecord 3 10435 'Bořek' 1 1 12),
        (New-AttributeRecord 2 10453 '' 1 0 12)
    )
    $registration = New-AttributeBlob @(
        (New-AttributeRecord 9 10234 '41101' 1 0 8),
        (New-AttributeRecord 10 10386 '01.01.2026' 0 1 8)
    )
    $garbage = [byte[]](1, 0, 0, 0, 2, 0, 0, 0, 255, 255, 255, 255, 7, 7)

    $connection = New-Object System.Data.OleDb.OleDbConnection "Provider=$provider;Data Source=$mdbPath"
    $connection.Open()
    foreach ($sql in @(
        'CREATE TABLE [ZAM] ([ID] INTEGER, [OsCislo] TEXT(20), [Sel] BIT, [Lock1] BIT)',
        'CREATE TABLE [ZAMpomer] ([ID] INTEGER, [RefZAM] INTEGER, [DatNast] DATETIME, [DatOdch] DATETIME)',
        'CREATE TABLE [MZ] ([ID] INTEGER, [Rok] INTEGER, [RelMes] INTEGER)',
        'INSERT INTO [ZAMpomer] ([ID], [RefZAM], [DatNast]) VALUES (1, 1, #2025-01-01#)',
        'CREATE TABLE [MH] ([ID] INTEGER, [Rok] INTEGER, [RelMesic] INTEGER, [RelTyp] INTEGER, [RefID] INTEGER, [ElOdeslano] BIT, [DatPod] DATETIME, [DatSave] DATETIME, [Oznacil] TEXT(20), [DataAll] LONGBINARY)',
        'CREATE TABLE [MHitems] ([ID] INTEGER, [RefAg] INTEGER, [RefPomer] INTEGER, [Data] LONGBINARY)',
        'CREATE TABLE [RegZAMitems] ([ID] INTEGER, [RefAg] INTEGER, [Data] LONGBINARY)',
        'CREATE TABLE [DataBoxSent] ([ID] INTEGER, [RefID] INTEGER, [Dorucenka] LONGBINARY)',
        "INSERT INTO [ZAM] ([ID], [OsCislo], [Sel], [Lock1]) VALUES (1, 'S1', 1, 1)",
        'INSERT INTO [MZ] ([ID], [Rok], [RelMes]) VALUES (1, 2026, 2)',
        "INSERT INTO [MH] ([ID], [Rok], [RelMesic], [RelTyp], [RefID], [ElOdeslano], [DatPod], [DatSave], [Oznacil]) VALUES (7, 2026, 2, 1, 0, 1, #2026-03-15 10:30:00#, #2026-03-15#, 'X')"
    )) {
        Invoke-Sql $connection $sql $null
    }
    Invoke-Sql $connection 'UPDATE [MH] SET [DataAll] = ? WHERE [ID] = 7' $header
    Invoke-Sql $connection 'INSERT INTO [MHitems] ([ID], [RefAg], [RefPomer], [Data]) VALUES (70, 7, 1, ?)' $form
    Invoke-Sql $connection 'INSERT INTO [MHitems] ([ID], [RefAg], [RefPomer], [Data]) VALUES (71, 7, 1, ?)' $garbage
    Invoke-Sql $connection 'INSERT INTO [RegZAMitems] ([ID], [RefAg], [Data]) VALUES (5, 1, ?)' $registration
    Invoke-Sql $connection 'INSERT INTO [DataBoxSent] ([ID], [RefID], [Dorucenka]) VALUES (3, 7, ?)' $garbage
    $connection.Close()
    $connection.Dispose()
    $connection = $null

    # Export přes nástroj POHODY (skupina mzdy) i přes samostatný nástroj PAMICA.
    $pohodaOut = Join-Path $testRoot '12345678_2026'
    $pohodaExporter = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\Export-PohodaMdb.ps1'))
    & $pohodaExporter -Mdb $mdbPath -Vystup $pohodaOut -Skupiny mzdy | Out-Null
    $pamicaOut = Join-Path $testRoot 'pamica'
    $pamicaExporter = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\pamica-export\Export-Pamica.ps1'))
    & $pamicaExporter -Mdb $mdbPath -Vystup $pamicaOut -Ico 12345678 | Out-Null
    $zip = Get-ChildItem -LiteralPath $pamicaOut -Filter '*.zip' | Select-Object -First 1
    if ($null -eq $zip) { throw 'Export-Pamica nevytvořil ZIP.' }
    $unzipped = Join-Path $testRoot 'pamica-unzipped'
    Expand-Archive -LiteralPath $zip.FullName -DestinationPath $unzipped

    [xml]$pohoda = Get-Content -LiteralPath (Join-Path $pohodaOut '91_mzdy.xml') -Raw -Encoding UTF8
    [xml]$pamica = Get-Content -LiteralPath (Join-Path $unzipped '12345678_2026\91_mzdy.xml') -Raw -Encoding UTF8

    foreach ($doc in $pohoda, $pamica) {
        $mh = $doc.mdbExport.MH
        if ([string]$mh.DatPod -ne '2026-03-15T10:30:00') { throw "Okamžik podání ztratil čas: $($mh.DatPod)." }
        if ([string]$mh.DatSave -ne '2026-03-15') { throw 'Datum uložení podání chybí nebo nese čas.' }
        if ($null -ne $mh.SelectSingleNode('Oznacil')) { throw 'Systémový sloupec Oznacil se exportoval.' }
        if ($null -ne $doc.mdbExport.ZAM.SelectSingleNode('Sel') -or $null -ne $doc.mdbExport.ZAM.SelectSingleNode('Lock1')) {
            throw 'Systémové sloupce Sel a Lock1 se exportovaly.'
        }
        if ([string]$mh.DataAll.a[0].id -ne '10001' -or [string]$mh.DataAll.a[0].'#text' -ne '11111111-2222-3333-4444-555555555555') {
            throw 'Hlavička měsíčního hlášení (MH.DataAll) se nepřečetla.'
        }
        $items = @($doc.mdbExport.MHitems)
        if ($items.Count -ne 2) { throw "Čekají se dvě položky hlášení, jsou $($items.Count)." }
        $readable = $items | Where-Object { [string]$_.ID -eq '70' }
        $unreadable = $items | Where-Object { [string]$_.ID -eq '71' }
        if ($null -ne $unreadable.SelectSingleNode('Data')) { throw 'Nečitelný blob se zapsal do exportu.' }
        $attributes = @($readable.Data.a)
        if ($attributes.Count -ne 7) { throw "Formulář má mít 7 atributů, má $($attributes.Count)." }
        if ([string]$attributes[2].'#text' -ne 'Zkušební') { throw 'Text v cp1250 se převedl špatně.' }
        if ([string]$attributes[3].f -ne '0' -or [string]$attributes[3].t -ne '13') { throw 'Příznak nebo oddíl atributu chybí.' }
        if ($null -ne $attributes[4].Attributes['i'] -or [string]$attributes[5].i -ne '1') { throw 'Pořadí opakované skupiny (dítě) se nezapsalo.' }
        if ([string]$attributes[6].id -ne '10453' -or [string]$attributes[6].InnerText -ne '') { throw 'Prázdný atribut se ztratil.' }
        $reg = @($doc.mdbExport.RegZAMitems.Data.a)
        if ($reg.Count -ne 2 -or [string]$reg[1].i -ne '1' -or [string]$reg[1].'#text' -ne '01.01.2026') {
            throw 'Registrace s osmibajtovou koncovkou se nepřečetla.'
        }
        if ($null -ne $doc.mdbExport.DataBoxSent.SelectSingleNode('Dorucenka')) { throw 'Doručenka datové schránky se exportovala.' }
    }

    $body = { param($doc) ($doc.mdbExport.ChildNodes | ForEach-Object { $_.OuterXml }) -join "`n" }
    if ((& $body $pohoda) -ne (& $body $pamica)) { throw 'Export-PohodaMdb a Export-Pamica vytáhly mzdovou skupinu každý jinak.' }

    $summary = Get-Content -LiteralPath (Join-Path $pohodaOut '91_mzdy-souhrn.txt') -Raw -Encoding UTF8
    if ($summary -notmatch 'MHitems\.Data\s+1\s+1') { throw 'Souhrn nehlásí přečtený a nečitelný blob.' }
    Write-Output 'PAMICA_ATTRIBUTE_EXPORT_OK'
} finally {
    if ($connection) {
        $connection.Close()
        $connection.Dispose()
    }
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
    if (Test-Path -LiteralPath $testRoot) {
        $resolvedTemp = [IO.Path]::GetFullPath($env:TEMP).TrimEnd('\') + '\'
        $resolvedTestRoot = [IO.Path]::GetFullPath($testRoot)
        if (-not $resolvedTestRoot.StartsWith($resolvedTemp, [StringComparison]::OrdinalIgnoreCase)) {
            throw "Refusing to remove unexpected test path $resolvedTestRoot."
        }
        Remove-Item -LiteralPath $testRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
