[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$downloader = Join-Path $projectRoot 'tools\downloadJmhzXsd.php'

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

& $phpExecutable $downloader
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
