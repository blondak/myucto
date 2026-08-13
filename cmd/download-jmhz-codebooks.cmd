@echo off
setlocal

where pwsh.exe >nul 2>nul
if errorlevel 1 (
    echo Obnova ciselniku JMHZ selhala: PowerShell 7 ^(pwsh.exe^) nebyl nalezen. 1>&2
    endlocal
    exit /b 1
)

pwsh.exe -NoLogo -NoProfile -NonInteractive -File "%~dp0download-jmhz-codebooks.ps1" %*
set "EXIT_CODE=%ERRORLEVEL%"
endlocal & exit /b %EXIT_CODE%
