@echo off
REM Stáhne XSD schémata do api/xsd/ (Windows verze): EPO MFČR výkazy (DPH/KH/SH/
REM DPFO/DPPO) + ISDOC 6.0.2 (formát faktur) + ČSSZ OSVC (přehled OSVČ, e-podání).
REM Default jsou commitnutá v repo — skript použij jen pro upgrade na nové ročníky.
REM
REM Pouziti:
REM   cmd\download-xsd.cmd           — stáhne všechna schémata (EPO + ISDOC + ČSSZ)
REM   cmd\download-xsd.cmd dphkh1    — stáhne jen jedno EPO schema
REM   cmd\download-xsd.cmd isdoc     — stáhne jen ISDOC schema
REM   cmd\download-xsd.cmd osvc25    — stáhne jen ČSSZ přehled OSVČ (annual)
REM
REM Pozn.: ČSSZ mění název souboru per ročník (OSVC24/OSVC25/…) i dokument-ID v URL,
REM proto je URL napevno níže — při novém ročníku aktualizuj CSSZ_OSVC_URL i cílový
REM název (form_code osvcYY). Zdrojová stránka: viz api/xsd/README.md.

setlocal EnableDelayedExpansion

set "DIR=%~dp0..\api\xsd"
set "BASE=https://adisspr.mfcr.cz/adis/jepo/schema"
set "ISDOC_URL=https://isdoc.cz/6.0.2/xsd/isdoc-invoice-6.0.2.xsd"
set "CSSZ_OSVC_URL=https://www.cssz.gov.cz/documents/20143/3201321/OSVC25.xsd/5d467add-4c11-0e56-4d54-d455b56c15c9"

if not exist "%DIR%" mkdir "%DIR%"

if "%~1"=="" (
    set "FORMS=dphdp3 dphkh1 dphshv dpfdp5 dppdp9 isdoc osvc25"
) else (
    set "FORMS=%*"
)

for %%F in (%FORMS%) do (
    if /I "%%F"=="isdoc" (
        echo -^> isdoc: %ISDOC_URL%
        powershell -NoProfile -Command "try { Invoke-WebRequest -Uri '%ISDOC_URL%' -OutFile '%DIR%\isdoc-invoice-6.0.2.xsd' -UseBasicParsing; Write-Host '  OK' } catch { Write-Host '  FAIL:' $_.Exception.Message }"
    ) else (
        if /I "%%F"=="osvc25" (
            echo -^> osvc25 ^(ČSSZ přehled OSVČ^): %CSSZ_OSVC_URL%
            powershell -NoProfile -Command "try { Invoke-WebRequest -Uri '%CSSZ_OSVC_URL%' -OutFile '%DIR%\osvc25.xsd' -UseBasicParsing; Write-Host '  OK' } catch { Write-Host '  FAIL:' $_.Exception.Message }"
        ) else (
            echo -^> %%F: %BASE%/%%F_epo2.xsd
            powershell -NoProfile -Command "try { Invoke-WebRequest -Uri '%BASE%/%%F_epo2.xsd' -OutFile '%DIR%\%%F.xsd' -UseBasicParsing; Write-Host '  OK' } catch { Write-Host '  FAIL:' $_.Exception.Message }"
        )
    )
)

echo.
echo Hotovo. Schemata v: %DIR%
endlocal
