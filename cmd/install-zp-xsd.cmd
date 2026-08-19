@echo off
setlocal

if "%~1"=="" (
    echo Usage: install-zp-xsd.cmd ^<directory with downloaded XSD^> 1>&2
    endlocal
    exit /b 1
)

where pwsh.exe >nul 2>nul
if errorlevel 1 (
    echo ZP XSD install failed: PowerShell 7 ^(pwsh.exe^) was not found. 1>&2
    endlocal
    exit /b 1
)

pwsh.exe -NoLogo -NoProfile -NonInteractive -File "%~dp0install-zp-xsd.ps1" "%~1"
set "EXIT_CODE=%ERRORLEVEL%"
endlocal & exit /b %EXIT_CODE%
