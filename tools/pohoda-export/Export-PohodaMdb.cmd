@echo off
rem Majetek, mzdy a sklad z datoveho souboru POHODY nebo PAMICA (XML export POHODY je nema), napr.:
rem   Export-PohodaMdb.cmd -Mdb "C:\...\StwPh_12345678_2026.mdb" -Vystup .\pohoda_export\12345678_2026
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Export-PohodaMdb.ps1" %*
echo.
pause
