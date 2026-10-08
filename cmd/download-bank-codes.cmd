@echo off
REM ============================================================================
REM  download-bank-codes.cmd — aktualizace ciselniku kodu bank (registr CNB)
REM
REM  Stahne aktualni registr kodu platebniho styku CNB a prepise
REM  api\resources\ciselniky\kody_bank_CR.csv. Proti nemu se kontroluje kod
REM  banky v platebnim spojeni oznameni NEMPRI (C_KODBANKY).
REM
REM  NENI to cron uloha — registr se meni zridka. Poustej rucne, vysledek
REM  zkontroluj pres `git diff` a commitni.
REM
REM  Pouziti:
REM    cmd\download-bank-codes.cmd              stahne a prepise ciselnik
REM    cmd\download-bank-codes.cmd --dry-run    jen vypise rozdil
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\download-bank-codes.php" %*
exit /b %ERRORLEVEL%
