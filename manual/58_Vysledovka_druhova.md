# 58. Výkaz zisku a ztráty — druhové členění

**Cesta: `Účetnictví → Výkaz zisku a ztráty`**

Druhová výsledovka člení náklady podle jejich druhu — například spotřebu,
služby, mzdy, odpisy a finanční náklady — a výnosy podle zákonných řádků
přílohy č. 2 části I vyhlášky č. 500/2002 Sb.

## 58.1 Zdroj a období

Výkaz čte syntetické zůstatky zaúčtovaných nákladových a výnosových účtů od
začátku zvoleného fiskálního období do data **Sestaveno k** včetně. Prázdné
datum znamená dřívější z posledního dne období a dneška. Vlastní závěrkový
převod účtů se vylučuje.

Sloupec **Minulé období** vznikne stejným výpočtem za předchozí fiskální
období k jeho konci a používá stejnou verzi mapy jako běžné období. Přepínač
**Kč / tis. Kč** mění jen obrazovku; PDF/XLSX zůstává v korunách.

## 58.2 Jak se účty mapují

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

## 58.3 Přesné vzorce výsledku

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

Čistý obrat (období od 1. 1. 2024)       = I. + II. + řádky zvolené firmou
Čistý obrat (období započatá před 2024) = I. + II. + III. + IV. + V. + VI. + VII.
```

Čistý obrat jsou od roku 2024 výnosy z prodeje výrobků, zboží a služeb (§ 1a
odst. 2 zákona o účetnictví, § 35 vyhlášky č. 500/2002 Sb.). Které další výnosy
patří k obchodnímu modelu firmy, je její úsudek: pronajímatel k nim může
počítat tržby z prodeje majetku (III.1.), holding výnosy z podílů (IV.). Řádky se
volí v **Nastavení uzávěrky** na stránce účetních období a rozhodnutí se uvádí
v příloze v účetní závěrce.

Výběr řádků má dvě pomůcky. Podle zapsané činnosti **CZ-NACE** aplikace navrhne
řádky, které k takové činnosti typicky patří, i s odůvodněním; tlačítkem se
návrh zaškrtne najednou. Řádky, na kterých firma za období nemá žádný obrat, se
skryjí - přepínačem *Zobrazit i řádky bez obratu* se vrátí. Zvolený řádek se
neskryje nikdy, ani když je na něm nula. Návrh je podklad, ne rozhodnutí:
aplikace nic nezaškrtne sama a výběr uložíte vy. Ve výkazu za první rok nového pojetí se čistý obrat
minulého období spočtený po staru neuvádí, sloupec zůstane nulový.

Interně mají obě položky označené `I.` jedinečné kódy, na výstupu se však
zobrazují podle vyhlášky. Výsledek za období se zároveň nezávisle spočítá ze
všech výsledkových účtů jako `Σ(Dal − MD)`. Kontrola **profit_matches**
porovnává oba výsledky na haléře.

## 58.4 Rozsah a kategorie

Filtr **Rozsah** nabízí automatický, plný, malý a mikro rozsah. U druhové
výsledovky se malý i mikro rozsah omezí na řádky nejvyšší úrovně. Automatická
volba používá kategorii účetní jednotky, ruční přepis rozsahu a povinný audit
stejně jako [Rozvaha](57_Rozvaha.md).

## 58.5 Kontroly a export

Pod výkazem se zobrazí **Výsledek hospodaření** a **Čistý obrat**. Backend
navíc kontroluje shodu výsledku s výsledkovými účty a úplnost mapování; tyto
vazby používají také automatické testy a navazující procesy. PDF a XLSX
používají stejné období, datum, rozsah, mapu i minulé období jako obrazovka.

Účetní jednotka používající členění nákladů podle funkce sestaví samostatnou
[účelovou výsledovku](59_Vysledovka_ucelova.md); obě varianty mají shodný
celkový výsledek, ale jinou strukturu provozních nákladů.

## 58.6 Výsledovka po účtech

Záložka **Účet 710 (po účtech)** ukáže nákladové a výnosové účty se zůstatkem od začátku
období do data sestavení, rozdělené do skupin **Provozní činnost**,
**Finanční činnost**, **Daň z příjmů** a **Převod podílu na výsledku
hospodaření společníkům**. Skupinu určuje stejná mapa jako výkaz (včetně
výjimek firmy), takže účet je vždy ve skupině, do které ho výkaz započte.
Náklady jsou ve sloupci **Náklady (MD)**, výnosy ve sloupci **Výnosy (D)**,
každá skupina končí svým výsledkem.

Pod skupinami následují mezisoučty **Provozní výsledek hospodaření**,
**Finanční výsledek hospodaření**, **Výsledek hospodaření před zdaněním** a
**po zdanění** a zvýrazněný řádek **Výsledek hospodaření** za účetní období.
Hodnoty se shodují s řádky výkazu a s výsledkem v rozvaze po účtech
([§ 57.8](57_Rozvaha.md)). Uzávěrkový zápis se nezapočítává, po uzavření roku
tak pohled ukazuje obsah konečného účtu 710.

Výsledkový účet, který mapa výkazu nezná, je ve skupině **Účty nezařazené ve
výkazu**. Výsledek hospodaření ho zahrnuje, výkaz ne, proto stránka nad
tabulkou upozorní a účet je třeba zařadit výjimkou mapování
([§ 57.7](57_Rozvaha.md)).

Kliknutím na účet se otevře jeho opis. Export PDF a XLSX z této záložky
vytvoří výsledovku po účtech v jednotce zvolené přepínačem **Kč / tis. Kč**.

### Odhad do konce roku (nezaúčtováno)

U právnické osoby v roce, který ještě není uzavřený a nemá zaúčtovanou daň
z příjmů (účet 591), je pod tabulkou blok **Odhad do konce roku
(nezaúčtováno)**. Nic z něj není v účetnictví a po zaúčtování daně z příjmů
zmizí. Načítá se samostatně až po tabulce, protože přepočítává náhled
přiznání k DPPO. Aplikace v něm nic nového nepočítá, jen skládá čísla, která
už ukazují jiné stránky:

| Řádek | Odkud je |
|---|---|
| Výsledek hospodaření průběžně (zaúčtováno) | ř. 10 náhledu DPPO; shoduje se s řádkem Výsledek hospodaření před zdaněním výše (k poslednímu dni období) |
| Nezaúčtované operace uzávěrky (časové rozlišení drobného majetku a nákladů příštích období, kurzové rozdíly, rozpuštění rozlišení z minulého roku) | projekce uzávěrky v náhledu DPPO, odkaz vede na uzávěrku období ([kap. 72](72_Uzaverka.md)) |
| Opravné položky a dohadné položky | tatáž projekce; jde o návrhy, které účetní teprve potvrdí, proto jsou šedě a do odhadu se nesčítají |
| Odpisy roku podle odpisového plánu (nezaúčtované) | karty majetku ([kap. 28](28_Majetek.md)); šedě a mimo součty, protože náhled DPPO odpisy zahrne až po jejich zaúčtování |
| Odhad výsledku hospodaření před zdaněním, připočitatelné a odčitatelné položky (ř. 70 a ř. 170), základ daně, odhad daně | náhled DPPO ([§ 43.3](43_Dan_z_prijmu.md)) včetně ručních úprav základu, ztráty, darů a slev |
| Zaplacené zálohy na daň a odhad doplatku nebo přeplatku | zálohy zadané v přiznání, jinak jistě spárované zálohy z evidence ([§ 43.4](43_Dan_z_prijmu.md)) |
| Odhad výsledku hospodaření po zdanění | odhad výsledku před zdaněním minus odhad daně |

U každého řádku je odkaz na stránku, ze které číslo pochází. Blok se
nezobrazí u fyzické osoby, v uzavřeném roce, při filtru dimenze ani
uživateli bez práva na přiznání k dani z příjmů.
