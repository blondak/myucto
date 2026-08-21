@echo off
REM ============================================================================
REM  license-status.cmd — vypis aktualniho stavu licence (E4)
REM  Pouziti:  license-status.cmd
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\license-status.php" %*
exit /b %ERRORLEVEL%
