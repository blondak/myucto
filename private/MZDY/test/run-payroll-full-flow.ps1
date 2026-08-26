<#
.SYNOPSIS
Spustí syntetický mzdový full-flow výhradně nad lokální testovací databází.

.PARAMETER WithTestTransport
Přidá mockované kontraktní testy ISDS/VREP pro prostředí TEST. Síť se nepoužije.
#>
[CmdletBinding()]
param(
    [switch]$WithTestTransport
)

$ErrorActionPreference = 'Stop'
$runner = Join-Path $PSScriptRoot 'run-payroll-full-flow.php'
if (-not (Test-Path -LiteralPath $runner -PathType Leaf)) {
    throw "PHP runner nebyl nalezen: $runner"
}

$payrollPhp = $null
if ($env:MYUCTO_TEST_PHP_BINARY) {
    $payrollPhp = $env:MYUCTO_TEST_PHP_BINARY
} elseif (Test-Path -LiteralPath 'C:\inetpub\php\php.exe' -PathType Leaf) {
    $payrollPhp = 'C:\inetpub\php\php.exe'
} else {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) {
        $payrollPhp = $phpCommand.Source
    }
}
if (-not $payrollPhp) {
    throw 'PHP nebylo nalezeno. Nastavte MYUCTO_TEST_PHP_BINARY nebo přidejte php do PATH.'
}

$runnerArguments = @($runner)
if ($WithTestTransport) {
    $runnerArguments += '--with-test-transport'
}

& $payrollPhp @runnerArguments
$runnerExitCode = $LASTEXITCODE
if ($runnerExitCode -ne 0) {
    throw "Syntetický mzdový full-flow selhal (exit $runnerExitCode)."
}
