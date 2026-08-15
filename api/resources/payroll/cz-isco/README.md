# Klasifikace zaměstnání CZ-ISCO

Připnutá kopie oficiální **systematické části klasifikace zaměstnání CZ-ISCO**
Českého statistického úřadu. Slouží ke dvěma věcem: našeptávači kódu CZ-ISCO
v kartě pracovního vztahu a k validaci uloženého kódu dřív, než ho ČSSZ odmítne
v podání JMHZ.

## Připnutý stav

| Údaj | Hodnota |
|---|---|
| Verze klasifikace | **2026-02-01** (účinnost od 1. 2. 2026) |
| Právní základ | Sdělení ČSÚ č. 5/2026 Sb. ze dne 16. ledna 2026 |
| Vydavatel | Český statistický úřad |
| Licence | **CC BY 4.0** — [podmínky užívání dat ČSÚ](https://csu.gov.cz/podminky_pro_vyuzivani_a_dalsi_zverejnovani_statistickych_udaju_csu) |
| Staženo | 2026-08-15 |
| Balíček | `cz-isco-2026-02-01-v1` |
| Položek platné verze | **1 992** (10 hlavních tříd, 43 tříd, 130 skupin, 434 podskupin, 1 375 kategorií) |
| Vyřazených kódů | 7 (platily v některém starším vydání) |

| Zdroj | Soubor | Původ | SHA-256 |
|---|---|---|---|
| Systematická část CZ-ISCO 2026-02-01 | `classification-2026-02-01/klasifikace_zamestnani_systematicka_cast_2026_02_01.xlsx` | [csu.gov.cz](https://csu.gov.cz/docs/107516/ae2997c3-bf4b-b7c4-0626-82fb1078e81e/klasifikace_zamestnani_systematicka_cast_2026_02_01.xlsx) | `2f9327f942fc54f3b302003380429501bda94b6d9728502c6a4352bd9d126ad5` |

Kompletní otisky stromu jsou v `SHA256SUMS`.

**Odvozený obsah:** `classification-2026-02-01/manifest.json` je strojově
vygenerovaný výtah z XLSX, ne originální publikace ČSÚ. Ve smyslu licence
CC BY 4.0 jde o **upravená / odvozená data** a takto je také označeno
(`usage_policy`, `parser_version` v manifestu).

## Proč soubor, a ne databáze

- Klasifikace se mění **sdělením ČSÚ zhruba jednou za rok až dva** (2011, 2018,
  2020, 2022, 2025, 2026) — je to vydání, ne provozní data. Změna má být vidět
  v diffu jako změna otisku, ne jako migrace, kterou je nutné dohnat v každé
  instalaci zvlášť.
- **Žádný SQL dotaz číselník nepotřebuje.** Kód CZ-ISCO se ukládá jako řetězec
  do `payroll_employment_terms.cz_isco_code`; nikde se na číselník nejoinuje ani
  se podle něj neagreguje. Obě čtecí cesty (validace zápisu, našeptávač) běží
  v PHP nad jedním polem v paměti.
- Data jsou **stejná pro všechny nájemce**. V databázi by to byla 1 992 řádků
  duplikovaných do každé instalace a jeden další zdroj pravdy, který se může
  rozejít s repozitářem.
- Stejný postup už projekt používá u externích číselníků JMHZ
  (`api/resources/payroll/jmhz/`) i u číselníku ČINNOSTI pro EPO
  (`api/resources/ciselniky/okec.txt`).

Kdyby přibyl reporting „kolik zaměstnanců v které profesní kategorii" napříč
firmami, kritérium se otočí a číselník se do databáze přesune migrací.

## Obnova po vydání nové verze ČSÚ

1. Stáhni novou systematickou část z
   [stránky klasifikace na csu.gov.cz](https://csu.gov.cz/klasifikace_zamestnani_-cz_isco-)
   do nového adresáře `classification-<účinnost>/`.
2. V `tools/CzIscoClassificationPackageBuilder.php` přepiš připnuté konstanty
   (`PACKAGE_KEY`, `SOURCE_*`, `LEGAL_BASIS`, nový řádek ve `VERSIONS`).
3. `php tools/CzIscoClassificationPackageBuilder.php` — builder odmítne zdroj
   s jiným otiskem, list s jiným názvem, kód mimo tvar, kategorii bez
   nadřazeného kódu i list, který vrátil podezřele málo řádků.
4. Přepiš `SHA256SUMS`, `CzIscoCodebook::PACKAGE_KEY` /
   `::DEFAULT_MANIFEST_SHA256` a očekávané počty v
   `api/tests/Unit/Payroll/CzIscoCodebookIntegrityTest.php`.

Builder i runtime jsou **fail-closed**: prázdný nebo osekaný soubor neprojde
kontrolou počtu položek, obsahového hashe ani otisku manifestu.
