@echo off
REM ============================================================================
REM  cron-purchase-approval-reminders.cmd: pripominky schvalovatelum prijatych
REM  dokladu (schvalovani manazerem strediska), kteri jeste nerozhodli.
REM  Frekvence: 1x denne, doporuceno 09:20 v pracovni dny (Po-Pa)
REM
REM  Pripominka jde N dni po zadosti nebo posledni pripomince (vychozi
REM  cfg.purchase_approval.reminder_after_days = 3), nejvys
REM  cfg.purchase_approval.max_reminders (vychozi 3) a vzdy s novym odkazem.
REM
REM  Volitelne argumenty (predaj jako parametry .cmd):
REM    --days=N    override reminder_after_days
REM    --dry-run   jen vypise, co by se odeslalo
REM
REM  Task Scheduler (kazdy pracovni den 09:20):
REM    schtasks /create /tn "MyUcto PurchaseApprovalReminders" ^
REM      /tr "%~f0" /sc weekly /d MON,TUE,WED,THU,FRI /st 09:20 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-purchase-approval-reminders.php" %* >> "%LOG_DIR%\purchase-approval-reminders-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
