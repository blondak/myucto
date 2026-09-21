@echo off
rem Ucetnictvi z datoveho souboru POHODY do XML pro prevod do MyUcta, napr.:
rem   Export-PohodaMdbAccounting.cmd -Mdb "C:\...\StwPh_12345678_2026.mdb" -Vystup .\export -Ico 12345678 -Rok 2026
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Export-PohodaMdbAccounting.ps1" %*
set "POHODA_EXPORT_EXIT=%ERRORLEVEL%"
echo.
pause
exit /b %POHODA_EXPORT_EXIT%
