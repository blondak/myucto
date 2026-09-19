@echo off
rem Mzdy z datoveho souboru PAMICA do ZIPu pro prevod do MyUcta. Spusteni dvojklikem,
rem nebo s parametry, napr.:
rem   Export-Pamica.cmd -Mdb "C:\ProgramData\STORMWARE\PAMICA\Data\Mzdy.mdb" -Rok 2025,2026
rem Pred spustenim PAMICU zavrete.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Export-Pamica.ps1" %*
echo.
pause
