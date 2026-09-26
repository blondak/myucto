# Přešifrování mzdového archivu a přebalení mzdových hodnot na aktuální klíč.
# Volby se předávají beze změny, viz api/bin/payroll-archive-reencrypt.php.
#   pwsh -File cmd/payroll-archive-reencrypt.ps1 --dry-run
#   pwsh -File cmd/payroll-archive-reencrypt.ps1 --rewrap
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpBin = if ($env:MYINVOICE_PHP_BIN) { $env:MYINVOICE_PHP_BIN } else { 'php' }
& $phpBin (Join-Path $projectRoot 'api\bin\payroll-archive-reencrypt.php') @args
exit $LASTEXITCODE
