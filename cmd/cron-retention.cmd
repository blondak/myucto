@echo off
REM ============================================================================
REM  cron-retention.cmd: denní úklid záloh, logů a dočasných souborů
REM  Frekvence: 1x denne, doporuceno 03:15 (po nocnich zalohach a cron-cleanup)
REM
REM  Ve spravovanem provozu povinna, na self-hostu volitelna
REM  (cron.retention.enabled = true v cfg.php). Limity v cron.retention.*:
REM  DB dumpy 7 dni (48 h vsechny, pak 1 denne), PDF/Dokumenty/Mzdy 3 posledni,
REM  logy 14 dni, docasne soubory 48 h, Twig cache 30 dni, archivy kompletniho
REM  exportu po jejich platnosti (export.instance.ttl_days).
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyUcto Retention" ^
REM      /tr "%~f0" /sc daily /st 03:15 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-retention.php" %* >> "%LOG_DIR%\retention-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
