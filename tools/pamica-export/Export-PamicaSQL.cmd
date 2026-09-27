@echo off
rem Mzdy z PAMICA SQL (Microsoft SQL Server) do ZIPu pro prevod do MyUcta. Spusteni dvojklikem,
rem nebo s parametry, napr.:
rem   Export-PamicaSQL.cmd -Config .\pamica-sql.json -Rok 2026
rem Bez parametru -Config pouzije pamica-sql.json vedle skriptu, jinak se na pripojeni zepta.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Export-PamicaSQL.ps1" %*
set "PAMICA_EXPORT_EXIT=%ERRORLEVEL%"
echo.
pause
exit /b %PAMICA_EXPORT_EXIT%
