@echo off
REM ============================================================================
REM  cron-cnb-rates.cmd — denni stazeni kurzovniho listku CNB
REM  Frekvence: 1x denne 15:00 (CNB vyhlasuje kurz kolem 14:30, jen pracovni dny)
REM
REM  Bez teto ulohy se exchange_rates plni jen jako ad-hoc cache pri prvnim
REM  dotazu na konkretni den, takze historie zustava derava a cizomenova uhrada
REM  ke dni bez kurzu nema cim ocenit pohyb. Skript dohani i mezery za poslednich
REM  30 dnu; dny, ktere kurz uz maji, preskoci bez HTTP volani. Idempotentni.
REM
REM  Volitelne argumenty:
REM    --days=N     jak daleko zpet dohanet mezery (default 30, max 400)
REM    --dry-run    jen vypise, ktere dny chybi
REM
REM  Jednorazove doplneni cele historie: api\bin\backfill-cnb-rates.php
REM
REM  Task Scheduler (denne 15:00):
REM    schtasks /create /tn "MyUcto CnbRates" ^
REM      /tr "%~f0" /sc daily /st 15:00 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-cnb-rates.php" %* >> "%LOG_DIR%\cnb-rates-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
