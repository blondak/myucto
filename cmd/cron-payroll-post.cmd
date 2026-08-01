@echo off
REM ============================================================================
REM  cron-payroll-post.cmd — automaticke mesicni zauctovani mezd
REM  Frekvence: 1x mesicne, 1. den v mesici 04:00
REM
REM  Zauctuje mzdovou rekapitulaci za PREDCHOZI mesic vsem aktivnim zamestnancum,
REM  kteri maji na karte zapnute "Uctovat automaticky" a vyplnenou pravidelnou
REM  hrubou mzdu (payroll_employees.auto_post + monthly_gross, migrace 1175).
REM  Datum uctovani je posledni den uctovaneho mesice, takze zapis padne do
REM  spravneho obdobi i pri zpozdenem behu.
REM
REM  Jen firmy v podvojnem ucetnictvi. Uzavrene obdobi ani chyba u jednoho
REM  zamestnance beh neshodi - skonci v reportu (System -> Planovane ulohy).
REM
REM  Volitelne argumenty:
REM    --dry-run           jen vypise, co by se zauctovalo
REM    --supplier=ID       jen jeden dodavatel
REM    --period=RRRR-MM    dohnat konkretni mesic
REM
REM  Task Scheduler (1. den v mesici 04:00):
REM    schtasks /create /tn "MyUcto Payroll Post" ^
REM      /tr "%~f0" /sc monthly /d 1 /st 04:00 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-payroll-post.php" %* >> "%LOG_DIR%\payroll-post-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
