@echo off
REM ============================================================================
REM  license-activate.cmd — aktivace licencniho klice z prikazove radky (E4)
REM  Pouziti:  license-activate.cmd MYU-XXXX-XXXX-XXXX-XXXX [--takeover]
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
php "%PROJECT_ROOT%\api\bin\license-activate.php" %*
exit /b %ERRORLEVEL%
