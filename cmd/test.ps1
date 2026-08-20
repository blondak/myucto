# Spusti PHPUnit testovou sadu (api/tests/).
#
# Pouziti:
#   .\cmd\test.ps1                  # vsechny testy
#   .\cmd\test.ps1 tests/Unit       # jen Unit
#   .\cmd\test.ps1 --testsuite=Unit
#   .\cmd\test.ps1 --filter=GpcParser
[CmdletBinding()]
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$Args
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location (Join-Path $ProjectRoot 'api')

if (-not (Test-Path 'vendor/bin/phpunit')) {
    Write-Error "vendor/bin/phpunit chybi. Spust: cd api ; composer install"
}

if ($Args.Count -eq 0) {
    & php bin/test-parallel.php
}
else {
    & php vendor/phpunit/phpunit/phpunit --colors=auto @Args
}
if ($LASTEXITCODE -ne 0) {
    Write-Error "PHPUnit failed (exit $LASTEXITCODE)"
}
