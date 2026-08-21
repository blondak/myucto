@echo off
REM cron-bank-email-notices.cmd — auto-import bankovnich emailovych aviz
setlocal
set "PROJECT_ROOT=%~dp0.."
set "LOG_DIR=%PROJECT_ROOT%\log"
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set TODAY=%%i
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-bank-email-notices.php" %* >> "%LOG_DIR%\bank-email-notices-%TODAY%.log" 2>&1
