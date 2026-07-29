@echo off
REM ============================================================================
REM  license-status.cmd — vypis aktualniho stavu licence (E4)
REM  Pouziti:  license-status.cmd
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
php "%PROJECT_ROOT%\api\bin\license-status.php" %*
exit /b %ERRORLEVEL%
