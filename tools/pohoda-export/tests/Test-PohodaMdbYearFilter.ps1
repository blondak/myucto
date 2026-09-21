[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$testRoot = Join-Path $env:TEMP ('pohoda-mdb-year-filter-' + [Guid]::NewGuid().ToString('N'))
$mdbPath = Join-Path $testRoot 'synthetic.mdb'
$outputPath = Join-Path $testRoot '12345678_2026'
$connection = $null

New-Item -ItemType Directory -Path $testRoot | Out-Null
try {
    $catalog = New-Object -ComObject ADOX.Catalog
    $catalog.Create("Provider=Microsoft.ACE.OLEDB.16.0;Data Source=$mdbPath;Jet OLEDB:Engine Type=5") | Out-Null
    $catalogConnection = $catalog.ActiveConnection
    $catalogConnection.Close()
    [Runtime.InteropServices.Marshal]::FinalReleaseComObject($catalogConnection) | Out-Null
    [Runtime.InteropServices.Marshal]::FinalReleaseComObject($catalog) | Out-Null
    $catalogConnection = $null
    $catalog = $null

    $connection = New-Object System.Data.OleDb.OleDbConnection "Provider=Microsoft.ACE.OLEDB.16.0;Data Source=$mdbPath"
    $connection.Open()
    foreach ($sql in @(
        'CREATE TABLE [MZ] ([ID] INTEGER, [Rok] INTEGER)',
        'CREATE TABLE [MZdavky] ([ID] INTEGER, [RefAg] INTEGER, [DatZac] DATETIME, [DatKon] DATETIME)',
        'INSERT INTO [MZ] ([ID], [Rok]) VALUES (1, 2026)',
        'INSERT INTO [MZ] ([ID], [Rok]) VALUES (2, 2025)',
        'INSERT INTO [MZdavky] ([ID], [RefAg], [DatZac], [DatKon]) VALUES (101, 1, #2026-02-01#, #2026-02-10#)',
        'INSERT INTO [MZdavky] ([ID], [RefAg], [DatZac], [DatKon]) VALUES (202, 2, #2025-03-01#, #2025-03-10#)'
    )) {
        $command = $connection.CreateCommand()
        try {
            $command.CommandText = $sql
            $command.ExecuteNonQuery() | Out-Null
        } finally {
            $command.Dispose()
        }
    }
    $connection.Close()
    $connection.Dispose()
    $connection = $null

    $exporter = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\Export-PohodaMdb.ps1'))
    & $exporter -Mdb $mdbPath -Vystup $outputPath -Skupiny mzdy

    $xmlPath = Join-Path $outputPath '91_mzdy.xml'
    [xml]$xml = Get-Content -LiteralPath $xmlPath -Raw
    $benefits = @($xml.mdbExport.MZdavky)
    if ($benefits.Count -ne 1) {
        throw "Expected one MZdavky row for 2026, got $($benefits.Count)."
    }
    if ([string]$benefits[0].ID -ne '101' -or [string]$benefits[0].RefAg -ne '1') {
        throw 'MZdavky export contains a row from a different payroll year.'
    }
    Write-Output 'POHODA_MZDAVKY_YEAR_FILTER_OK'
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
