@echo off
REM ============================================================================
REM  cron-license-renew.cmd — denni obnova licencniho tokenu (E4)
REM  Frekvence: 1x denne. Doplnek k obnove, kterou spousti i prvni prihlaseny
REM  request dne (LicenseMiddleware); cron pokryva instalace, ktere pres den
REM  nikdo neotevre. Mutex uvnitr sluzby zajisti max. 1x denne.
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyUcto License Renew" ^
REM      /tr "%~f0" /sc daily /st 05:00 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-license-renew.php" %* >> "%LOG_DIR%\license-renew-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
