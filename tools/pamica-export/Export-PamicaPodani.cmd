@echo off
rem Podani (JMHZ, registrace, NEMPRI, HZUPN, ELDP, ONZ) z datoveho souboru PAMICA do JSON korpusu a XML.
rem Pouziti:
rem   Export-PamicaPodani.cmd -Mdb "C:\kopie\Mzdy.mdb" -Vystup "C:\soukrome\korpus" -Xml
rem Pracujte nad kopii souboru; vystup obsahuje osobni udaje, patri jen do soukromeho ulozeni.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Export-PamicaPodani.ps1" %*
echo.
pause
