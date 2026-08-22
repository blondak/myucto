@echo off
REM ============================================================================
REM  license-deactivate.cmd — deaktivace licence (E4)
REM  Pouziti:  license-deactivate.cmd
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\license-deactivate.php" %*
exit /b %ERRORLEVEL%
