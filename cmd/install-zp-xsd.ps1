[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string] $SourceDirectory
)

$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$installer = Join-Path $projectRoot 'tools\installZpXsd.php'

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

& $phpExecutable $installer $SourceDirectory
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
