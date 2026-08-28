[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$engineRoot = if ($env:MD2PDF_HOME) { $env:MD2PDF_HOME } else { 'C:\work\MD2PDF' }
$engine = Join-Path $engineRoot 'md2pdf.php'
$config = Join-Path $PSScriptRoot 'md2pdf.config.php'
$php = 'C:\inetpub\php\php.exe'

if (-not (Test-Path -LiteralPath $engine)) { throw "MD2PDF nebyl nalezen: $engine" }
if (-not (Test-Path -LiteralPath $php)) { throw "PHP nebylo nalezeno: $php" }

& $php $engine "--config=$config"
if ($LASTEXITCODE -ne 0) { throw "MD2PDF selhal (exit $LASTEXITCODE)." }
