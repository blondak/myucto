@echo off
setlocal

where pwsh.exe >nul 2>nul
if errorlevel 1 (
    echo JMHZ XSD download failed: PowerShell 7 ^(pwsh.exe^) was not found. 1>&2
    endlocal
    exit /b 1
)

pwsh.exe -NoLogo -NoProfile -NonInteractive -File "%~dp0download-jmhz-xsd.ps1"
set "EXIT_CODE=%ERRORLEVEL%"
endlocal & exit /b %EXIT_CODE%
