@echo off
rem Ucetnictvi z POHODA SQL (Microsoft SQL Server) do XML pro prevod do MyUcta, napr.:
rem   Export-PohodaSQL.cmd -Config .\pohoda-sql.json
rem   Export-PohodaSQL.cmd -Ico 12345678 -Rok 2026
rem Bez parametru -Config pouzije pohoda-sql.json vedle skriptu, jinak se na pripojeni zepta.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Export-PohodaSQL.ps1" %*
set "POHODA_EXPORT_EXIT=%ERRORLEVEL%"
echo.
pause
exit /b %POHODA_EXPORT_EXIT%
