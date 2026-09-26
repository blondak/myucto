@echo off
rem Přešifrování mzdového archivu a přebalení mzdových hodnot na aktuální klíč.
rem Volby se předávají beze změny, viz api\bin\payroll-archive-reencrypt.php.
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\payroll-archive-reencrypt.php" %*
exit /b %ERRORLEVEL%
