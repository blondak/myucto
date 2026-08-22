@echo off
REM ============================================================================
REM  license-activate.cmd — aktivace licencniho klice z prikazove radky (E4)
REM  Pouziti:  license-activate.cmd MYU-XXXX-XXXX-XXXX-XXXX [--takeover]
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\license-activate.php" %*
exit /b %ERRORLEVEL%
