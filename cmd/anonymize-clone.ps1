# =============================================================================
#  anonymize-clone.ps1 — anonymizovaná kopie databáze pro testovací instanci
#
#  Originál se jen čte; kopie vznikne jako nová databáze na témž serveru.
#
#  Použití:
#    .\anonymize-clone.ps1 --from=myucto --to=myucto_anon
#    .\anonymize-clone.ps1 --to=myucto_anon --replace --dump=C:\tmp\anon.sql
#    .\anonymize-clone.ps1 --to=myucto_anon --files-out=D:\test\storage
#
#  Návratový kód: 0 = hotovo, 1 = chyba běhu, 2 = chyba argumentů.
# =============================================================================
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$script = Join-Path $projectRoot 'api\bin\anonymize-clone.php'

if ($env:MYINVOICE_PHP_BIN) {
    $phpExecutable = $env:MYINVOICE_PHP_BIN
} else {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $phpCommand) {
        $windowsPhp = 'C:\inetpub\php\php.exe'
        if (Test-Path -LiteralPath $windowsPhp -PathType Leaf) {
            $phpExecutable = $windowsPhp
        } else {
            throw 'PHP CLI nebylo nalezeno na PATH.'
        }
    } else {
        $phpExecutable = $phpCommand.Source
    }
}

& $phpExecutable $script @args
exit $LASTEXITCODE
