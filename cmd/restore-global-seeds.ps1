# Dotah globálních seedů z migrací (svátky, katalogy, příjemci podání), které
# instalace ztratila. Bez voleb jen náhled, viz api/bin/restore-global-seeds.php.
#   pwsh -File cmd/restore-global-seeds.ps1
#   pwsh -File cmd/restore-global-seeds.ps1 --apply
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpBin = if ($env:MYINVOICE_PHP_BIN) { $env:MYINVOICE_PHP_BIN } else { 'php' }
& $phpBin (Join-Path $projectRoot 'api\bin\restore-global-seeds.php') @args
exit $LASTEXITCODE
