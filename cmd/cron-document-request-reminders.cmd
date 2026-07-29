@echo off
REM ============================================================================
REM  cron-document-request-reminders.cmd — e-mailova urgence klientovi na
REM  otevrene vyzadani chybejiciho dokladu (Faze F, audit 2026-07).
REM  Frekvence: 1x denne, doporuceno 09:00 v pracovni dny (Po-Pa)
REM
REM  Volitelne argumenty (predej jako parametry .cmd):
REM    --days=N      pozadavek musi byt starsi nez N dni (default 3)
REM    --cooldown=N  cooldown mezi urgencemi (default 7)
REM    --dry-run     jen vypise, co by se odeslalo
REM
REM  Task Scheduler (kazdy pracovni den 09:00):
REM    schtasks /create /tn "MyUcto Document Request Reminders" ^
REM      /tr "%~f0" /sc daily /st 09:00 /d MON,TUE,WED,THU,FRI /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-document-request-reminders.php" %* >> "%LOG_DIR%\document-request-reminders-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
