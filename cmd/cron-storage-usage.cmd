@echo off
REM ============================================================================
REM  cron-storage-usage.cmd — mereni spotreby mista instance (H-10)
REM  Frekvence: 1x za hodinu.
REM
REM  Zmeri velikost databaze (information_schema) a datoveho prostoru BEZ
REM  adresare zaloh a ulozi vysledek do `instance_storage_usage`. /api/health
REM  a middleware rezimu jen pro cteni pak ctou hotove cislo — strom souboru
REM  se prochazi vyhradne tady, nikdy pri requestu.
REM
REM  Zalohy se do kvoty NEZAPOCITAVAJI (hosting je z ni taky vyjima).
REM  Dokud mereni neprobehne, je spotreba NEZMERENA (null) — ne nula — a
REM  nespousti ani upozorneni, ani rezim jen pro cteni.
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyUcto Storage Usage" ^
REM      /tr "%~f0" /sc hourly /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-storage-usage.php" %* >> "%LOG_DIR%\storage-usage-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
