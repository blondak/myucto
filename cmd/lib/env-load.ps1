# MyUcto.cz - bezpecny loader `.env` pro PowerShell skripty.
#
# Windows protejsek `cmd/lib/env-load.sh` - MUSI se chovat stejne (AGENTS.md,
# sekce Multiplatformnost). Duvod vzniku a plna semantika hodnot jsou popsane
# v hlavicce `.sh` varianty; strucne:
#
#   FOO=bar baz            -> `bar baz`   (mezery jsou soucast hodnoty!)
#   FOO="bar baz"          -> `bar baz`
#   FOO='bar baz'          -> `bar baz`
#   FOO=bar   # komentar   -> `bar`       (inline komentar = bily znak + '#')
#   FOO="a#b"              -> `a#b`
#   FOO="a\"b"             -> `a"b`       (v dvojitych uvozovkach jen \" a \\)
#   FOO=$HOME              -> `$HOME`     (BEZ expanze - je to data, ne kod)
#   export FOO=bar         -> `bar`
#   # komentar / prazdny / radek bez '=' -> ignoruje se
#
# Stary parser (`$_ -match '^\s*([A-Z_]+)\s*=\s*(.*)\s*$'`) neumel sundat
# uvozovky, neznal klice s cislici a inline komentar bral jako soucast hodnoty.
#
# Pouziti (dot-source):
#   . "$PSScriptRoot\lib\env-load.ps1"
#   $envVars = Read-DotEnvFile '.env'
#   Set-DotEnvValue -Path '.env' -Key 'DB_PORT' -Value '3308'
#
# Prime spusteni (test suite):
#   pwsh -NoProfile -File cmd/lib/env-load.ps1 -Print <soubor>

[CmdletBinding()]
param([string]$Print)

# Promenne, ktere z `.env` NIKDY neprebirame - prepsani by rozbilo bezici skript
# nebo umoznilo podstrcit kod.
$script:DotEnvProtectedKeys = @(
    'PATH', 'IFS', 'ENV', 'BASH_ENV', 'SHELLOPTS', 'BASHOPTS', 'CDPATH',
    'PS1', 'PS4', 'LD_PRELOAD', 'LD_LIBRARY_PATH', 'PSModulePath'
)

function ConvertFrom-DotEnvText {
    <#
      .SYNOPSIS
      Naparsuje obsah `.env` na uporadanou hashtable. Zadny Invoke-Expression,
      zadna expanze promennych - obsah `.env` je vzdy jen data.
    #>
    [CmdletBinding()]
    param([Parameter(Mandatory)][AllowEmptyString()][string]$Text)

    $result = [ordered]@{}
    if ([string]::IsNullOrEmpty($Text)) { return $result }

    # CRLF i osamocene CR; pripadny BOM na zacatku.
    $Text = $Text -replace "`r`n", "`n" -replace "`r", "`n"
    $Text = $Text.TrimStart([char]0xFEFF)
    $lines = $Text -split "`n"

    for ($i = 0; $i -lt $lines.Count; $i++) {
        $line = $lines[$i].TrimStart()
        if ($line -eq '' -or $line.StartsWith('#')) { continue }
        if ($line -match '^export\s+(.*)$') { $line = $Matches[1].TrimStart() }

        $eq = $line.IndexOf('=')
        # Radek bez '=' neni prirazeni - preskocit (v bashi ho `source` spoustel
        # jako prikaz, to je presne pad z issue #14).
        if ($eq -lt 1) { continue }

        $key = $line.Substring(0, $eq).TrimEnd()
        if ($key -notmatch '^[A-Za-z_][A-Za-z0-9_]*$') {
            Write-Warning ".env: preskakuji nevalidni nazev promenne '$key'"
            continue
        }

        $rest = $line.Substring($eq + 1).TrimStart()
        $quote = $null
        if ($rest.Length -gt 0 -and ($rest[0] -eq '"' -or $rest[0] -eq "'")) { $quote = $rest[0] }

        if ($null -ne $quote) {
            $sb = [System.Text.StringBuilder]::new()
            $closed = $false
            $pos = 1
            while (-not $closed) {
                while ($pos -lt $rest.Length) {
                    $ch = $rest[$pos]
                    if ($quote -eq '"' -and $ch -eq '\' -and ($pos + 1) -lt $rest.Length -and
                        ($rest[$pos + 1] -eq '"' -or $rest[$pos + 1] -eq '\')) {
                        [void]$sb.Append($rest[$pos + 1]); $pos += 2; continue
                    }
                    if ($ch -eq $quote) { $closed = $true; break }
                    [void]$sb.Append($ch); $pos++
                }
                if ($closed) { break }
                # Viceradkova hodnota v uvozovkach - docti dalsi radek.
                if ($i + 1 -ge $lines.Count) {
                    Write-Warning ".env: neuzavrena uvozovka u '$key' - beru zbytek souboru"
                    break
                }
                $i++
                [void]$sb.Append("`n")
                $rest = $lines[$i]
                $pos = 0
            }
            $result[$key] = $sb.ToString()
        }
        else {
            $value = $rest
            # Inline komentar jen po bilem znaku ('a#b' zustava 'a#b').
            $m = [regex]::Match($value, '\s#')
            if ($m.Success) { $value = $value.Substring(0, $m.Index) }
            $result[$key] = $value.TrimEnd()
        }
    }

    return $result
}

function Read-DotEnvFile {
    <#
      .SYNOPSIS
      Nacte `.env` ze souboru. Neexistujici soubor = terminating error, aby
      volajici skript nikdy nepokracoval s poloprazdnou konfiguraci.
    #>
    [CmdletBinding()]
    param([Parameter(Mandatory)][string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        throw ".env soubor '$Path' neexistuje"
    }
    $text = [System.IO.File]::ReadAllText((Resolve-Path -LiteralPath $Path).Path)
    return ConvertFrom-DotEnvText -Text $text
}

function Format-DotEnvValue {
    <#
      .SYNOPSIS
      Zapisovatelna podoba hodnoty - uvozovky prida jen kdyz je to nutne.
      (Parser je zvladne i bez nich, ale ostatni nastroje uz ne.)
    #>
    [CmdletBinding()]
    param([Parameter(Mandatory)][AllowEmptyString()][string]$Value)

    if ($Value -match '^[A-Za-z0-9_.:/@+-]*$') { return $Value }
    return '"' + ($Value -replace '\\', '\\' -replace '"', '\"') + '"'
}

function Set-DotEnvValue {
    <#
      .SYNOPSIS
      Prepise (nebo doplni) jeden klic v `.env`. Zapisuje UTF-8 bez BOM a s LF -
      `Set-Content -Encoding UTF8` pise ve Windows PowerShellu 5.1 BOM.
    #>
    [CmdletBinding()]
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$Key,
        [Parameter(Mandatory)][AllowEmptyString()][string]$Value
    )

    $formatted = Format-DotEnvValue -Value $Value
    $lines = @()
    if (Test-Path -LiteralPath $Path) {
        $lines = [System.IO.File]::ReadAllText((Resolve-Path -LiteralPath $Path).Path) -replace "`r`n", "`n" -split "`n"
        # Trailing prazdny radek ze zaveracneho LF neduplikovat.
        if ($lines.Count -gt 0 -and $lines[-1] -eq '') { $lines = $lines[0..($lines.Count - 2)] }
    }
    $hit = $false
    $out = foreach ($ln in $lines) {
        if ($ln -match "^\s*(export\s+)?$([regex]::Escape($Key))\s*=") { $hit = $true; "$Key=$formatted" } else { $ln }
    }
    if (-not $hit) { $out = @($out) + "$Key=$formatted" }
    [System.IO.File]::WriteAllText(
        $Path,
        (($out -join "`n") + "`n"),
        (New-Object System.Text.UTF8Encoding $false)
    )
}

# Prime spusteni (test suite / rucni kontrola, co skript z `.env` opravdu vycte).
if ($Print) {
    $pairs = Read-DotEnvFile -Path $Print
    foreach ($k in $pairs.Keys) {
        $v = ($pairs[$k] -replace '\\', '\\') -replace "`n", '\n'
        Write-Output "$k=$v"
    }
}
