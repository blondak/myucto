@echo off
REM ============================================================================
REM  cron-vat-status-apply.cmd — denni propsani historie platcovstvi DPH
REM  Frekvence: 1x denne 00:30
REM
REM  Zmeny platcovstvi DPH lze v Nastaveni planovat s budouci ucinnosti -
REM  radek supplier_vat_status_history s effective_from > dnes se do zive
REM  cache (supplier.is_vat_payer, supplier.is_identified) propise az v den
REM  ucinnosti timto cronem. Jediny set-based UPDATE, idempotentni.
REM
REM  Volitelne argumenty:
REM    --dry-run    jen vypise pocet firem k aktualizaci
REM
REM  Task Scheduler (denne 00:30):
REM    schtasks /create /tn "MyUcto VatStatusApply" ^
REM      /tr "%~f0" /sc daily /st 00:30 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-vat-status-apply.php" %* >> "%LOG_DIR%\vat-status-apply-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
