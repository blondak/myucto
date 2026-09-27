<#
    Společné připojení k Microsoft SQL Serveru pro Export-PohodaSQL.ps1 a Export-PamicaSQL.ps1.
    Soubor se načítá tečkou, sám nic nespouští.

    Nastavení připojení (JSON, klíče nerozlišují velikost písmen, klíče začínající `_` se
    ignorují):

      host                    server, například SERVER nebo SERVER\POHODA (pojmenovaná instance)
      port                    TCP port; prázdné = výchozí instance (1433) nebo pojmenovaná
                              instance přes službu SQL Browser
      database                databáze agendy; prázdné = výběr ze seznamu
      user                    přihlašovací jméno SQL; prázdné = účet Windows
      password                heslo k přihlašovacímu jménu
      trustServerCertificate  true = certifikát serveru se neověřuje (výchozí)
      driver                  sqlclient (výchozí, součást Windows) nebo odbc
      odbcDriver              název ODBC ovladače, výchozí ODBC Driver 18 for SQL Server

    Chybějící klíč se skript zeptá. Heslo se čte skrytě a drží se jen jako SecureString;
    do výstupu, spojení ani logu se nevypisuje. Připojení je vždy šifrované, jen pro čtení
    (ApplicationIntent=ReadOnly) a skripty posílají jen dotazy SELECT.
#>

$PohodaSqlDefaultOdbcDriver = 'ODBC Driver 18 for SQL Server'
$PohodaSqlApplicationName = 'MyUcto export (jen cteni)'
$PohodaSqlKeys = @('host', 'port', 'database', 'user', 'password', 'trustServerCertificate', 'driver', 'odbcDriver')

function New-PohodaSqlSettings {
    return [pscustomobject]@{
        Host                   = ''
        Port                   = 0
        Database               = ''
        User                   = ''
        Password               = $null
        TrustServerCertificate = $true
        Driver                 = 'sqlclient'
        OdbcDriver             = $PohodaSqlDefaultOdbcDriver
        Present                = @{}
    }
}

function ConvertTo-PohodaSqlBool($Value, [string]$Key) {
    if ($Value -is [bool]) { return $Value }
    $text = ([string]$Value).Trim().ToLowerInvariant()
    if ($text -in @('1', 'true', 'yes', 'ano', 'a', 'y')) { return $true }
    if ($text -in @('0', 'false', 'no', 'ne', 'n')) { return $false }
    throw "Klíč $Key musí být true nebo false."
}

function ConvertTo-PohodaSqlPort($Value) {
    $text = ([string]$Value).Trim()
    if ($text -eq '') { return 0 }
    $port = 0
    if (-not [int]::TryParse($text, [ref]$port) -or $port -lt 1 -or $port -gt 65535) {
        throw 'Port musí být číslo od 1 do 65535, nebo prázdný.'
    }
    return $port
}

function ConvertTo-PohodaSqlDriver([string]$Value) {
    $driver = $Value.Trim().ToLowerInvariant()
    if ($driver -eq '') { return 'sqlclient' }
    if ($driver -notin @('sqlclient', 'odbc')) { throw 'Klíč driver musí být sqlclient nebo odbc.' }
    return $driver
}

<#
    Načte nastavení připojení z JSON souboru. Chybová hláška nikdy neobsahuje obsah
    souboru - byl by v ní i heslo.
#>
function Read-PohodaSqlConfig([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Konfigurační soubor $Path neexistuje."
    }
    try {
        $json = [IO.File]::ReadAllText((Resolve-Path -LiteralPath $Path).Path) | ConvertFrom-Json
    } catch {
        throw "Konfigurační soubor $Path není platný JSON."
    }
    if ($null -eq $json -or $json -isnot [pscustomobject]) {
        throw "Konfigurační soubor $Path musí obsahovat objekt JSON."
    }
    $settings = New-PohodaSqlSettings
    foreach ($property in $json.PSObject.Properties) {
        if ($property.Name.StartsWith('_')) { continue }
        $key = $PohodaSqlKeys | Where-Object { $_ -eq $property.Name } | Select-Object -First 1
        if (-not $key) {
            throw "Konfigurační soubor obsahuje neznámý klíč $($property.Name). Povolené klíče: $($PohodaSqlKeys -join ', ')."
        }
        $value = $property.Value
        $settings.Present[$key] = $true
        switch ($key) {
            'host'                   { $settings.Host = ([string]$value).Trim() }
            'port'                   { $settings.Port = ConvertTo-PohodaSqlPort $value }
            'database'               { $settings.Database = ([string]$value).Trim() }
            'user'                   { $settings.User = ([string]$value).Trim() }
            'password'               {
                if ([string]$value -ne '') {
                    $settings.Password = ConvertTo-SecureString -String ([string]$value) -AsPlainText -Force
                }
            }
            'trustServerCertificate' { $settings.TrustServerCertificate = ConvertTo-PohodaSqlBool $value $key }
            'driver'                 { $settings.Driver = ConvertTo-PohodaSqlDriver ([string]$value) }
            'odbcDriver'             {
                $name = ([string]$value).Trim()
                if ($name -ne '') { $settings.OdbcDriver = $name }
            }
        }
        $value = $null
    }
    return $settings
}

<# Doptá se na klíče, které v nastavení chybí (bez konfiguračního souboru na všechny). #>
function Complete-PohodaSqlSettings($Settings) {
    if (-not $Settings.Present['host'] -or $Settings.Host -eq '') {
        $Settings.Host = ([string](Read-Host 'SQL server (například SERVER nebo SERVER\POHODA)')).Trim()
        if ($Settings.Host -eq '') { throw 'Bez názvu SQL serveru se nejde připojit.' }
    }
    if (-not $Settings.Present['port']) {
        $Settings.Port = ConvertTo-PohodaSqlPort (Read-Host 'Port (prázdné = výchozí 1433, u pojmenované instance ho zjistí SQL Browser)')
    }
    if (-not $Settings.Present['user']) {
        $Settings.User = ([string](Read-Host 'Uživatel SQL (prázdné = přihlásit se účtem Windows)')).Trim()
    }
    if ($Settings.User -ne '' -and $null -eq $Settings.Password) {
        $Settings.Password = Read-Host "Heslo uživatele $($Settings.User)" -AsSecureString
    }
    if (-not $Settings.Present['trustServerCertificate']) {
        $answer = ([string](Read-Host 'Důvěřovat certifikátu serveru bez ověření? [A/n]')).Trim()
        $Settings.TrustServerCertificate = $answer -eq '' -or (ConvertTo-PohodaSqlBool $answer 'trustServerCertificate')
    }
    return $Settings
}

function Get-PohodaSqlServerName($Settings) {
    if ($Settings.Port -gt 0) { return '{0},{1}' -f $Settings.Host, $Settings.Port }
    return $Settings.Host
}

<# Popis připojení pro výpis: server, databáze, ovladač a způsob přihlášení, nikdy heslo. #>
function Get-PohodaSqlConnectionInfo($Settings, [string]$Database) {
    $login = if ($Settings.User -ne '') { "uživatel SQL $($Settings.User)" } else { 'účet Windows' }
    $driver = if ($Settings.Driver -eq 'odbc') { "ODBC ($($Settings.OdbcDriver))" } else { 'SqlClient' }
    $target = if ($Database) { ", databáze $Database" } else { '' }
    return "server $(Get-PohodaSqlServerName $Settings)$target, přihlášení $login, ovladač $driver"
}

<#
    Připravené (neotevřené) spojení. SqlClient dostane heslo přes SqlCredential, takže
    v textu spojení vůbec není; ODBC ho v textu spojení mít musí a Persist Security Info
    ho po otevření zahodí.
#>
function New-PohodaSqlConnection($Settings, [string]$Database) {
    if ($Settings.User -ne '' -and $null -eq $Settings.Password) {
        throw "Chybí heslo uživatele $($Settings.User)."
    }
    $server = Get-PohodaSqlServerName $Settings
    if ($Settings.Driver -eq 'odbc') {
        $builder = New-Object System.Data.Odbc.OdbcConnectionStringBuilder
        $builder.Driver = $Settings.OdbcDriver
        $builder['Server'] = $server
        if ($Database) { $builder['Database'] = $Database }
        $builder['Encrypt'] = 'Yes'
        $builder['TrustServerCertificate'] = $(if ($Settings.TrustServerCertificate) { 'Yes' } else { 'No' })
        $builder['ApplicationIntent'] = 'ReadOnly'
        $builder['APP'] = $PohodaSqlApplicationName
        if ($Settings.User -ne '') {
            $builder['UID'] = $Settings.User
            $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Settings.Password)
            try {
                $builder['PWD'] = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
            } finally {
                [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
            }
        } else {
            $builder['Trusted_Connection'] = 'Yes'
        }
        $connection = New-Object System.Data.Odbc.OdbcConnection ([string]$builder.ConnectionString)
        $builder.Clear()
        $connection.ConnectionTimeout = 20
        return $connection
    }
    $builder = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
    $builder['Data Source'] = $server
    if ($Database) { $builder['Initial Catalog'] = $Database }
    $builder['Encrypt'] = $true
    $builder['TrustServerCertificate'] = [bool]$Settings.TrustServerCertificate
    $builder['ApplicationIntent'] = 'ReadOnly'
    $builder['Application Name'] = $PohodaSqlApplicationName
    $builder['Connect Timeout'] = 20
    $builder['Persist Security Info'] = $false
    if ($Settings.User -eq '') {
        $builder['Integrated Security'] = $true
        return New-Object System.Data.SqlClient.SqlConnection ([string]$builder.ConnectionString)
    }
    $password = $Settings.Password.Copy()
    $password.MakeReadOnly()
    $credential = New-Object System.Data.SqlClient.SqlCredential($Settings.User, $password)
    return New-Object System.Data.SqlClient.SqlConnection -ArgumentList @([string]$builder.ConnectionString, $credential)
}

function Open-PohodaSqlConnection($Settings, [string]$Database) {
    $connection = New-PohodaSqlConnection $Settings $Database
    try {
        $connection.Open()
    } catch {
        $connection.Dispose()
        $reason = $_.Exception.GetBaseException().Message
        throw "K SQL serveru se nepodařilo připojit ($(Get-PohodaSqlConnectionInfo $Settings $Database)): $reason"
    }
    return $connection
}

function Invoke-PohodaSqlQuery($Connection, [string]$Sql, [int]$Timeout = 600) {
    $command = $Connection.CreateCommand()
    $command.CommandText = $Sql
    $command.CommandTimeout = $Timeout
    $table = New-Object System.Data.DataTable
    $reader = $null
    try {
        $reader = $command.ExecuteReader()
        $table.Load($reader)
    } finally {
        if ($reader) { $reader.Close() }
        $command.Dispose()
    }
    return , $table
}

function ConvertTo-PohodaSqlLiteral([string]$Value) {
    return "N'" + $Value.Replace("'", "''") + "'"
}

<# Databáze na serveru, ke kterým má přihlášený uživatel přístup (bez systémových). #>
function Get-PohodaSqlAccessibleDatabases($Connection) {
    $rows = Invoke-PohodaSqlQuery $Connection "SELECT name FROM sys.databases WHERE database_id > 4 AND state_desc = 'ONLINE' AND HAS_DBACCESS(name) = 1 ORDER BY name"
    return @($rows.Rows | ForEach-Object { [string]$_[0] })
}

<#
    Agendy POHODA SQL: databáze StwPh_<IČO>_<rok>. Název firmy se doplní z registru agend
    StwPh_sys.dbo.Firma, pokud je čitelný; bez něj stačí názvy databází.
#>
function Get-PohodaSqlAgendas($Connection) {
    $names = @{}
    try {
        $firms = Invoke-PohodaSqlQuery $Connection 'SELECT [Soubor], [Firma] FROM [StwPh_sys].[dbo].[Firma]' 60
        foreach ($row in $firms.Rows) {
            $db = [IO.Path]::GetFileNameWithoutExtension(([string]$row[0]).Trim())
            if ($db -ne '' -and -not $names.ContainsKey($db)) { $names[$db] = ([string]$row[1]).Trim() }
        }
    } catch {
        $names = @{}
    }
    $agendas = foreach ($name in (Get-PohodaSqlAccessibleDatabases $Connection)) {
        if ($name -notmatch '^StwPh_(\d{6,8})_(\d{4})$') { continue }
        [pscustomobject]@{
            Name    = $name
            Ico     = $matches[1].PadLeft(8, '0')
            Year    = [int]$matches[2]
            Company = $(if ($names.ContainsKey($name)) { $names[$name] } else { '' })
        }
    }
    return @($agendas | Sort-Object Ico, @{ Expression = 'Year'; Descending = $true })
}

<#
    Mzdové databáze PAMICA SQL: každá dostupná databáze s tabulkami zaměstnanců, pracovních
    poměrů a mezd (dbo.ZAM, dbo.ZAMpomer, dbo.MZ) a aspoň jednou zpracovanou mzdou. Název
    databáze se neověřuje, rozhoduje obsah. Agenda POHODA SQL samostatné pracovní poměry
    nemá, prázdné šablony a systémové databáze STORMWARE nemají mzdy - obojí se vynechá.
#>
function Get-PohodaSqlPayrollDatabases($Connection) {
    $found = foreach ($name in (Get-PohodaSqlAccessibleDatabases $Connection)) {
        $quoted = '[' + $name.Replace(']', ']]') + '].[dbo].'
        $check = "SELECT CASE WHEN OBJECT_ID($(ConvertTo-PohodaSqlLiteral ($quoted + '[ZAM]')), 'U') IS NOT NULL AND OBJECT_ID($(ConvertTo-PohodaSqlLiteral ($quoted + '[ZAMpomer]')), 'U') IS NOT NULL AND OBJECT_ID($(ConvertTo-PohodaSqlLiteral ($quoted + '[MZ]')), 'U') IS NOT NULL THEN 1 ELSE 0 END"
        try {
            $has = Invoke-PohodaSqlQuery $Connection $check 60
            if ([int]$has.Rows[0][0] -ne 1) { continue }
            $rows = Invoke-PohodaSqlQuery $Connection "SELECT CASE WHEN EXISTS (SELECT 1 FROM $($quoted)[MZ]) THEN 1 ELSE 0 END" 60
            if ([int]$rows.Rows[0][0] -ne 1) { continue }
        } catch {
            continue
        }
        $ico = ''
        $year = 0
        if ($name -match '^StwP[a-z]_(\d{6,8})_(\d{4})$') {
            $ico = $matches[1].PadLeft(8, '0')
            $year = [int]$matches[2]
        }
        [pscustomobject]@{ Name = $name; Ico = $ico; Year = $year; Company = '' }
    }
    return @($found)
}

<#
    Vybere databázi ze seznamu. Parametry -Ico a -Rok vybírají bez dotazu; jediná
    kandidátka se vezme rovnou, jinak se skript zeptá číslem.
#>
function Select-PohodaSqlDatabase([object[]]$Candidates, [string]$Ico, [int]$Rok, [string]$What = 'databázi') {
    $list = @($Candidates)
    if ($Ico) { $list = @($list | Where-Object { $_.Ico -eq $Ico.PadLeft(8, '0') }) }
    if ($Rok) { $list = @($list | Where-Object { $_.Year -eq $Rok }) }
    if ($list.Count -eq 0) {
        $filter = @()
        if ($Ico) { $filter += "IČO $Ico" }
        if ($Rok) { $filter += "rok $Rok" }
        $suffix = if ($filter.Count -gt 0) { ' pro ' + ($filter -join ' a ') } else { '' }
        throw "Na serveru jsem nenašel žádnou dostupnou $What$suffix. Zadejte databázi v konfiguraci (database) nebo parametrem -Databaze."
    }
    if ($list.Count -eq 1) { return $list[0].Name }
    Write-Host 'Dostupné databáze:'
    for ($i = 0; $i -lt $list.Count; $i++) {
        $item = $list[$i]
        $detail = @()
        if ($item.Ico) { $detail += "IČO $($item.Ico)" }
        if ($item.Year) { $detail += "rok $($item.Year)" }
        if ($item.Company) { $detail += $item.Company }
        $suffix = if ($detail.Count -gt 0) { '  (' + ($detail -join ', ') + ')' } else { '' }
        Write-Host ("  {0,3}. {1}{2}" -f ($i + 1), $item.Name, $suffix)
    }
    $answer = ([string](Read-Host "Číslo databáze [1-$($list.Count)]")).Trim()
    $index = 0
    if (-not [int]::TryParse($answer, [ref]$index) -or $index -lt 1 -or $index -gt $list.Count) {
        throw 'Nebyla vybrána žádná databáze.'
    }
    return $list[$index - 1].Name
}
