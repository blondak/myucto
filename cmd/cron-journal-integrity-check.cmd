@echo off
REM ============================================================================
REM  cron-journal-integrity-check.cmd — nocni integrity job nad ucetnim denikem
REM  Frekvence: 1x denne, doporuceno v noci (napr. 02:30)
REM
REM  Pro kazdeho dodavatele v podvojnem ucetnictvi zkontroluje konzistenci
REM  doklad <-> denik (sirotci zapisy, suma MD != suma D, booked_at bez zapisu
REM  a naopak, doklad != zapis castkou). CISTE CTECI — nic neopravuje, jen ulozi
REM  posledni vysledek do journal_integrity_findings pro dashboard.
REM
REM  Volitelne argumenty:
REM    --dry-run          jen vypise nalezy, nic neulozi
REM    --supplier=ID      jen jeden dodavatel
REM
REM  Task Scheduler (kazdy den 02:30):
REM    schtasks /create /tn "MyUcto Journal Integrity" ^
REM      /tr "%~f0" /sc daily /st 02:30 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-journal-integrity-check.php" %* >> "%LOG_DIR%\journal-integrity-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
