# Syntetický full-flow mezd – ruční checklist

Tento scénář ověřuje jeden celý mzdový měsíc na třech výhradně syntetických osobách s pracovními vztahy HPP, DPČ a DPP. Automatizovaná část vytváří izolovanou firmu v databázi `myucto_test`, vše drží v jedné transakci a na konci provede rollback. Výchozí režim nikdy neodesílá data mimo lokální počítač.

## Bezpečnostní brána před spuštěním

- [ ] Pracujete v lokálním vývojovém prostředí, nikoli na produkčním serveru.
- [ ] Je dostupná testovací databáze odvozená testovacím bootstrapem jako `myucto_test`.
- [ ] V repozitáři nejsou vložené skutečné rodné číslo, číslo pojištěnce, osobní účet, certifikát, privátní klíč ani přihlašovací údaj.
- [ ] Všechny použité osoby, instituce, účty a reference jsou zjevně syntetické. Bankovní účet instituce používá ověřený testovací placeholder `1000000005 / 0100`.
- [ ] Nejsou nastavené žádné produkční transportní proměnné ani produkční certifikáty pro tento průchod.
- [ ] Výchozí politika firmy má `delivery_channel = disabled`, automatické účtování i automatické platby vypnuté.

Runner odmítá neznámé argumenty a nemá volbu pro produkční prostředí nebo skutečné odeslání. PHPUnit se spouští s `--fail-on-skipped`, takže chybějící DB či migrace nemohou vydávat přeskočený scénář za úspěch.

## Automatizované spuštění

Windows PowerShell:

```powershell
pwsh -File private\MZDY\test\run-payroll-full-flow.ps1
```

Linux, Docker nebo přímé PHP na Windows:

```text
php private/MZDY/test/run-payroll-full-flow.php
```

Volitelné transportní kontrakty pro prostředí TEST:

```powershell
pwsh -File private\MZDY\test\run-payroll-full-flow.ps1 -WithTestTransport
```

```text
php private/MZDY/test/run-payroll-full-flow.php --with-test-transport
```

Tento přepínač stále nic neodesílá. Pouze přidá unit testy, které používají `Guzzle MockHandler`, mockovaný ledger a čisté sestavení ISDS zprávy. Skutečné cvičné odeslání do externího TEST prostředí runner záměrně nepodporuje; vyžadovalo by samostatné oprávnění, testovací identitu a fail-closed kontrolu prostředí.

## Očekávaný automatizovaný scénář

### Firma a obsluha

- [ ] Vznikne izolovaný syntetický tenant s povoleným modulem mezd.
- [ ] Jedna syntetická účetní provede výpočet, kontrolu i schválení.
- [ ] Aplikace nevyžaduje další účet ani povinné pravidlo čtyř očí.
- [ ] Firma má jednu syntetickou mzdovou účtárnu s testovacím variabilním symbolem.
- [ ] Účet ČSSZ je syntetický, ověřený jen pro účely testu a po rollbacku nezůstane uložený.

### Lidé a pracovní vztahy

- [ ] Alice Syntetická má `employment_type = hpp` a `relation_type = employment`.
- [ ] Boris Syntetický má `employment_type = dpc` a `relation_type = dpc`.
- [ ] Cyril Syntetický má `employment_type = dpp` a `relation_type = dpp`.
- [ ] Každá osoba má právě jeden aktivní vztah, profil ve stavu `ready` a účinnost od 1. 1. 2026.
- [ ] HPP má 40 hodin týdně, DPČ 20 hodin a DPP 10 hodin; workload odpovídá 100 %, 50 % a 25 %.
- [ ] Zákonná evidence obsahuje syntetické české daňové, sociální a zdravotní podklady a pojišťovnu 111.
- [ ] Roční sociální a daňové počáteční stavy jsou explicitně potvrzené syntetické nuly.

### Složky a měsíční vstupy

- [ ] Existuje pravidelná složka `MZDA_MESICNI_FLOW` a jednorázová složka `ODMENA_FLOW`.
- [ ] Obě složky vstupují do daně, sociálního a zdravotního pojištění, průměrného výdělku, exekucí, JMHZ a statistiky.
- [ ] Pro červen 2026 existují tři schválené základní vstupy a jedna schválená odměna.
- [ ] Součet zdrojových částek ve zmrazeném běhu je 9 325 000 haléřů.
- [ ] Snapshot složky i jeho SHA-256 odpovídají uložené definici v okamžiku schválení vstupu.

### Absence

- [ ] DPČ má na 15. 6. 2026 publikovanou osmihodinovou směnu po odečtení přestávky.
- [ ] Existuje vypočtený a schválený průměrný hodinový výdělek s rozhodným obdobím 1. 1.–31. 3. 2026.
- [ ] Dovolená je vytvořená jako `vacation`, odkazuje na schválený průměr a je schválená před uzamčením vstupů.
- [ ] Ve vstupním snapshotu běhu je absence zachycena právě jednou; nedochází k dvojímu přičtení náhrady.

### Mzdový běh

- [ ] Běh pro období 1. 6. 2026 vznikne ve správném tenantu.
- [ ] Vstupy se uzamknou a další změny živých dat nemohou změnit již zmrazený snapshot.
- [ ] Výpočet vrátí právě tři osoby.
- [ ] Žádná validace se závažností `blocker` nezůstane otevřená.
- [ ] Jedna účetní provede stavy vytvořený → uzamčený → vypočtený → zkontrolovaný → schválený.
- [ ] Výsledný snapshot a jednotlivé zákonné výsledky mají konzistentní hashe.
- [ ] Účtování je nahrazeno testovacím stubem; nevzniká účetní zápis mimo scénář.

### Zdravotní pojištění

- [ ] Přehled ZP pro schválenou revizi vrátí HTTP 200.
- [ ] Přehled obsahuje pojišťovnu 111 a právě tři osoby.
- [ ] Elektronický transport je označen jako nepodporovaný s důvodem `health_insurance_transport_unavailable`.
- [ ] Stažený artefakt má hlavičku `Content-SHA256` rovnou SHA-256 skutečného obsahu.
- [ ] Automatizovaný scénář nevytvoří ani neodešle PPZ/HOZ transakci.

### JMHZ

- [ ] Z produkční kalkulační pipeline vznikne sociální zákonný výsledek pro všechny tři vztahy.
- [ ] Syntetický závazek ČSSZ je materializovaný právě jednou a je svázaný se schválenou revizí a účtárnou.
- [ ] PVPOJ preview má období `2026-06` a druh dokumentu `internal_jmhz_pvpoj_preview`.
- [ ] Preview má deterministický 64znakový SHA-256.
- [ ] Preview fail-closed kontroluje shodu snapshotů, osob, vztahů, zákonných součtů a závazku ČSSZ.
- [ ] Preview není oficiální podání a runner nevolá dispatch službu.

## Ruční UI průchod na samostatných syntetických datech

Automatizovaný scénář rollbackuje data, proto pro vizuální kontrolu nezakládejte výjimku v runneru. V UI použijte samostatnou lokální testovací firmu a opět jen syntetické údaje.

- [ ] V Zaměstnancích založte tři osoby a ověřte zobrazení HPP, DPČ a DPP v seznamu i detailu.
- [ ] Ověřte, že hledací pole osoby nevyžaduje render dlouhého seznamu a správně zachová deep-link osoby.
- [ ] U každého vztahu zkontrolujte datum vzniku, úvazek, účtárnu, daňový režim a účast na pojištění.
- [ ] V Mzdových složkách ověřte právní zacházení pravidelné mzdy a odměny.
- [ ] V Rychlém měsíčním vstupu zadejte tři základní částky a jednu odměnu, poté je schvalte.
- [ ] V Absencích založte směnu, průměr a jednodenní dovolenou; ověřte pořadí schválení.
- [ ] V Mzdových bězích vytvořte červen 2026, uzamkněte vstupy a proveďte výpočet.
- [ ] Rozklikněte validace a ověřte, že neobsahují technické anglické kódy bez českého vysvětlení.
- [ ] Kontrolu a schválení dokončete týmž testovacím účtem účetní.
- [ ] V Dokumentech ověřte výplatní sestavu a konzistenci částek se snapshotem běhu.
- [ ] V Podáních otevřete přehled ZP a JMHZ preview; nic neodesílejte.
- [ ] Ověřte, že bez explicitně nakonfigurovaného bezpečného kanálu UI nenabídne implicitní produkční odeslání.

## Negativní a fail-closed kontroly

- [ ] Chybějící schválený průměr zablokuje dovolenou nebo výpočet; systém nesmí částku odhadnout.
- [ ] Neschválená absence nevstoupí do immutable snapshotu jako schválená náhrada.
- [ ] Druhé použití stejného `external_id` nevytvoří duplicitní mzdový vstup.
- [ ] Změna složky po uzamčení nezmění hash ani výsledek existující revize.
- [ ] Jedna účetní může běh vypočítat, zkontrolovat a schválit; každá akce zůstane samostatně v auditu.
- [ ] Chybějící zdravotní evidence nebo neověřený kód pojišťovny vytvoří srozumitelný blocker.
- [ ] Chybějící ověřený účet ČSSZ zabrání materializaci závazku i JMHZ preview.
- [ ] Nesoulad hashe zákonného výsledku, závazku nebo snapshotu zablokuje preview.
- [ ] Cizí tenant nemůže načíst běh, osoby, závazek ani výstup.
- [ ] Neznámý argument runneru skončí chybou; neexistuje `production`, `send` ani podobný bypass.
- [ ] Volitelný transportní běh používá pouze mocky a neotevře síťové spojení.

## Úklid a důkaz výsledku

- [ ] Výstup končí `OK` bez skipů a runner vypíše `FULL-FLOW OK`.
- [ ] Po skončení nezůstane v `myucto_test` izolovaný tenant ani jeho osoby, vstupy, absence, běh, závazky či výstupy.
- [ ] Nevznikl žádný soubor s osobními údaji, certifikátem nebo heslem.
- [ ] Do repozitáře se ukládá jen syntetický test, runner a tento checklist; PDF a jiné generované artefakty se necommitují.
- [ ] Případný neúspěch se archivuje jen jako technický log bez databázových hesel a bez osobních údajů.

## Kritérium dokončení

Průchod je úspěšný pouze tehdy, když automatizovaný test doběhne bez skipu, všechny čtyři vstupy a jedna schválená absence se objeví ve zmrazeném běhu právě jednou, revizi vypočítá, zkontroluje a schválí jedna účetní, ZP download i JMHZ preview mají ověřený hash a nebyla provedena žádná síťová nebo externí operace.
