@echo off
REM ============================================================================
REM  download-okec.cmd — aktualizace snapshotu ciselniku CINNOSTI (CZ-NACE)
REM
REM  Stahne aktualni ciselnik z rozhrani ciselniku Danoveho portalu a prepise
REM  api\resources\ciselniky\okec.txt. Proti nemu se kanonizuje CZ-NACE
REM  (c_okec v priznani k DPH) a jede naseptavac v Nastaveni.
REM
REM  NENI to cron uloha — ciselnik se meni zridka (posledni velka zmena:
REM  prechod na NACE rev. 2.1 k 1. 1. 2026). Poustej rucne, vysledek zkontroluj
REM  pres `git diff` a commitni.
REM
REM  Pouziti:
REM    cmd\download-okec.cmd              stahne a prepise snapshot
REM    cmd\download-okec.cmd --dry-run    jen porovna a vypise rozdil
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
php "%PROJECT_ROOT%\api\bin\download-okec.php" %*
exit /b %ERRORLEVEL%
