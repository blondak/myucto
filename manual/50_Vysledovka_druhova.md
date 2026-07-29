# 50. Výkaz zisku a ztráty — druhové členění

**Cesta: `Účetnictví → Výkaz zisku a ztráty`**

Druhová výsledovka člení náklady podle jejich druhu — například spotřebu,
služby, mzdy, odpisy a finanční náklady — a výnosy podle zákonných řádků
přílohy č. 2 části I vyhlášky č. 500/2002 Sb.

## 50.1 Zdroj a období

Výkaz čte syntetické zůstatky zaúčtovaných nákladových a výnosových účtů od
začátku zvoleného fiskálního období do data **Sestaveno k** včetně. Prázdné
datum znamená dřívější z posledního dne období a dneška. Vlastní závěrkový
převod účtů se vylučuje.

Sloupec **Minulé období** vznikne stejným výpočtem za předchozí fiskální
období k jeho konci a používá stejnou verzi mapy jako běžné období. Přepínač
**Kč / tis. Kč** mění jen obrazovku; PDF/XLSX zůstává v korunách.

## 50.2 Jak se účty mapují

Každý účet se přiřadí podle nejdelšího shodného prefixu ve verzované mapě.
Výnosový příspěvek se počítá jako `Dal − MD`, nákladový jako `MD − Dal`.
Proto běžné výnosy i náklady vstupují do svých řádků kladně; opravný pohyb na
opačné straně je snižuje. Analytické mapy, například 559M/559Z/559P a
561P/561C, mají přednost před obecnou syntetikou.

Základní skupiny mapování jsou:

| Řádky výkazu | Typické účty |
|---|---|
| I., II. tržby | 601, 602, 604 |
| A. výkonová spotřeba | 501–504, 511–513, 518 |
| B., C. změna zásob a aktivace | 581–588 |
| D. osobní náklady | 521–528 |
| E. úpravy hodnot | 551, 557–559 a jejich analytiky |
| III. ostatní provozní výnosy | 641–644, 646–648 |
| F. ostatní provozní náklady | 531, 532, 538, 541–549, 552, 554, 555 |
| finanční výnosy | 661–666, 668 |
| finanční náklady | 561–569, 574, 579 |
| daň a převod výsledku | 591, 592, 595, 596, 599 |

Úplný aktuální rozpad je dán verzí mapování zobrazenou v záhlaví. Kliknutí na
řádek zobrazí přímo namapované účty a jejich příspěvky; kód vede do opisu od
začátku období do data sestavení. Backendová kontrola vrací i nenamapované
nenulové výsledkové účty, přestože aktuální souhrnný blok stránky zobrazuje
jen výsledek hospodaření a čistý obrat.

## 50.3 Přesné vzorce výsledku

Mezisoučty A., D., E., III., F. a L. jsou součtem svých podřádků a případných
přímých příspěvků. Z nich se na haléře počítá:

```text
Provozní VH =
    I. + II.
  − A. − B. − C. − D. − E.
  + III.
  − F.

Finanční VH =
    IV. − G.
  + V.  − H.
  + VI. − I. (úpravy hodnot a rezervy ve finanční oblasti)
  − J.
  + VII.
  − K.

VH před zdaněním = Provozní VH + Finanční VH
VH po zdanění    = VH před zdaněním − L.
VH za období     = VH po zdanění − M.

Čistý obrat = I. + II. + III. + IV. + V. + VI. + VII.
```

Interně mají obě položky označené `I.` jedinečné kódy, na výstupu se však
zobrazují podle vyhlášky. Výsledek za období se zároveň nezávisle spočítá ze
všech výsledkových účtů jako `Σ(Dal − MD)`. Kontrola **profit_matches**
porovnává oba výsledky na haléře.

## 50.4 Rozsah a kategorie

Filtr **Rozsah** nabízí automatický, plný, malý a mikro rozsah. U druhové
výsledovky se malý i mikro rozsah omezí na řádky nejvyšší úrovně. Automatická
volba používá kategorii účetní jednotky, ruční přepis rozsahu a povinný audit
stejně jako [Rozvaha](49_Rozvaha.md).

## 50.5 Kontroly a export

Pod výkazem se zobrazí **Výsledek hospodaření** a **Čistý obrat**. Backend
navíc kontroluje shodu výsledku s výsledkovými účty a úplnost mapování; tyto
vazby používají také automatické testy a navazující procesy. PDF a XLSX
používají stejné období, datum, rozsah, mapu i minulé období jako obrazovka.

Účetní jednotka používající členění nákladů podle funkce sestaví samostatnou
[účelovou výsledovku](51_Vysledovka_ucelova.md); obě varianty mají shodný
celkový výsledek, ale jinou strukturu provozních nákladů.
