@echo off
REM ============================================================================
REM  cron-backup-personnel.cmd — denni zaloha personalnich spisu
REM  (storage/payroll-personnel/) do storage/backup/{dbname}-personnel-YYYY-MM-DD.zip
REM
REM  Oddelene od cron-backup-payroll.cmd zamerne: personalni spisy (pracovni
REM  smlouvy, dodatky) jsou soukroma data zamestnancu a jejich zalohu jde drzet
REM  jinde a s jinymi pravy nez ostatni zalohy.
REM  Frekvence: 1x denne, doporuceno 02:45 (PO cron-backup-payroll)
REM  Retention: 30 dennich + mesicni (1. v mesici) drzeny 365 dni
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyUcto BackupPersonnel" ^
REM      /tr "%~f0" /sc daily /st 02:45 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-backup-personnel.php" %* >> "%LOG_DIR%\backup-personnel-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
