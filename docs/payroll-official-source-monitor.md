# Monitoring oficiálních podkladů JMHZ

> PDF export lze připravit sdíleným projektem [MD2PDF](C:/work/MD2PDF) přes
> `docs/export-pdf.ps1`; konfigurace je v `docs/md2pdf.config.php`.

`cron-jmhz-source-monitor` jednou denně čte veřejné indexy dokumentace JMHZ
MPSV a ČSSZ. Do lokálního runtime stavu ukládá jen normalizovaný seznam
dokumentů (název, rozpoznanou verzi, oficiální URL, SHA-256 a velikost). Výsledek
posledního běhu je v **Systém → Plánované úlohy** v `last_report`; stav je v
`storage/monitoring/jmhz-official-sources.json` a respektuje
`MYINVOICE_DATA_DIR`.

Upozornění vždy obsahuje konkrétní dokument, předchozí a novou verzi a odkaz.
Hlásí nově přidané nebo odebrané položky veřejného seznamu, změnu URL/verze a
změnu obsahu na nezměněné URL. Změna rozložení indexu bez změny odkázaného
dokumentu se nehlásí.

Monitor je výhradně detekční: nestahuje kandidáta pro importer, nepřepisuje
`api/resources/payroll/jmhz`, nic neukládá do databáze mezd a nikdy neschvaluje
aktualizaci. Po upozornění je nutné projít dokument a navázat na postup
`cmd/download-jmhz-codebooks.* --candidate=...` a diff z MZ-28-W07.

ČSSZ nemá veřejný, úplný a strojově čitelný katalog všech JMHZ dokumentů:
ePortál vystavuje jen aktuálně přímo odkazované soubory a část materiálů je za
přihlášením. Monitor proto může automaticky objevit jen nově přímo odkazovaný
veřejný soubor na dané stránce a ověřovat jeho obsah. Neumí poctivě slibovat
detekci neveřejného, neodkázaného nebo autentizovaného dokumentu; takový zdroj
musí člověk po ověření veřejné adresy přidat do
`tools/jmhz-official-source-monitor-sources.php`.
