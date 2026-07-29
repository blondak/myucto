@echo off
REM ============================================================================
REM  license-deactivate.cmd — deaktivace licence (E4)
REM  Pouziti:  license-deactivate.cmd
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
php "%PROJECT_ROOT%\api\bin\license-deactivate.php" %*
exit /b %ERRORLEVEL%
