@echo off
REM ============================================================================
REM  cron-vat-clearing.cmd — interni doklad zuctovani DPH
REM  Frekvence: 1x mesicne, 1. den v mesici 04:30
REM
REM  Prevede dan zdanovaciho obdobi z analytik 343.100 (vstup) a 343.200
REM  (vystup) na zuctovaci ucet 343.900 (migrace 1323/1324):
REM      MD 343.200 / D 343.900     MD 343.900 / D 343.100
REM  Po dokladu jsou vstup i vystup za obdobi nulove a na 343.900 lezi presne
REM  to, co se odvadi - bankovni uhrada (kontace vat.payment) ho pak vynuluje.
REM
REM  Resi obdobi PREDCHOZI. Mesicnimu platci vyjde minuly mesic, ctvrtletnimu
REM  cele ctvrtleti - a to az pote, co skonci. Zauctovani je idempotentni,
REM  opakovany beh zapis prepocita, nikdy nezdvoji.
REM
REM  Jen platci (nebo identifikovane osoby) v podvojnem ucetnictvi. Uzavrene
REM  obdobi ani chyba u jedne firmy beh neshodi - skonci v reportu
REM  (System -> Planovane ulohy).
REM
REM  Volitelne argumenty:
REM    --dry-run           jen vypise, co by se zauctovalo
REM    --supplier=ID       jen jeden dodavatel
REM    --period=RRRR-MM    dohnat konkretni obdobi
REM    --force             i pro dosud neuzavrene obdobi
REM
REM  Task Scheduler (1. den v mesici 04:30):
REM    schtasks /create /tn "MyUcto VAT Clearing" ^
REM      /tr "%~f0" /sc monthly /d 1 /st 04:30 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-vat-clearing.php" %* >> "%LOG_DIR%\vat-clearing-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
