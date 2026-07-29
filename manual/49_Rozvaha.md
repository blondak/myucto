# 49. Rozvaha

**Cesta: `Účetnictví → Rozvaha`**

Rozvaha sestavuje aktiva a pasiva k rozvahovému dni ve struktuře přílohy
č. 1 vyhlášky č. 500/2002 Sb. Je dostupná jen pro podvojné účetnictví.

## 49.1 Období, den a verze

- **Období** určuje fiskální rok.
- **Rozvahový den** musí ležet uvnitř období. Prázdná hodnota znamená dřívější
  z posledního dne období a dneška.
- **Rozsah** volí automatický, plný, zkrácený pro malou nebo zkrácený pro
  mikro účetní jednotku.
- **Kč / tis. Kč** mění pouze obrazovkové zobrazení. Výpočet i export zůstávají
  v korunách.

Systém vybere verzi definice výkazu platnou k rozvahovému dni. Její kód se
zobrazuje v záhlaví. Minulé období se sestaví z předchozího fiskálního období
k jeho poslednímu dni, ale se stejnou verzí řádků a mapy jako běžný výkaz,
aby byly sloupce srovnatelné.

## 49.2 Zůstatky, znaménka a mapování

Zdrojem jsou syntetické zůstatky všech zaúčtovaných řádků do rozvahového dne.
Analytiky se standardně sčítají pod syntetiku; obsahuje-li mapa delší analytický
prefix, má přednost nejdelší shodný prefix. Podrozvahové účty a vlastní
závěrkový převod knih se vylučují. Výsledkové účty se pro výpočet výsledku
hospodaření omezí na začátek fiskálního období.

Pro každý účet se počítá saldo `MD − Dal`:

- aktivní účet přispívá saldem do **Brutto**,
- korekční účet se obrátí do **Korekce** a odečte se ve vztahu
  `Netto = Brutto − Korekce`,
- pasivní účet se znaménkově obrátí, aby kreditní zůstatek vyšel kladně,
- případný koeficient mapy `sign` umí hodnotu řádku obrátit.

Mapa je verzovaná a při více shodách používá nejdelší prefix. Podmínka
`debit` nebo `credit` mapuje tentýž saldový účet do aktiv či pasiv podle
skutečné strany zůstatku. U takových účtů se saldo nejprve počítá po
analytikách a teprve potom se kladné a záporné zůstatky sečtou odděleně.
Proto se například kladný běžný účet a záporný kontokorent na analytikách 221
vzájemně nezkompenzují. Stejný princip chrání saldové účty 336, 341–346, 395
a 481.

Analytiky **311D** a **461K** mají delší mapu pro dlouhodobé obchodní
pohledávky a krátkodobou část dlouhodobého úvěru. Nezařazený nenulový
rozvahový účet je vrácen v kontrole jako nenamapovaný; účetní jej musí
správně zařadit, jinak může výkaz zůstat neúplný.

## 49.3 Strom a výpočtové řádky

Řádek typu **detail** obsahuje přímo namapované účty. **Mezisoučet** sčítá
dceřiné řádky a případné vlastní přímé mapování. **Vypočtený řádek** používá
definovaný vzorec a může k němu přičíst vlastní mapované účty.

Aktiva zobrazují Brutto, Korekci, Netto a Netto minulého období. Pasiva
zobrazují běžné a minulé období. Kliknutí na řádek s přímými příspěvky
rozbalí účty, částku a u aktiv cíl Brutto/Korekce; kód účtu vede do opisu od
začátku období do rozvahového dne.

Řádek **Výsledek hospodaření běžného období** se počítá přímo ze všech
výsledkových účtů jako `Σ výnosy (Dal − MD) − Σ náklady (MD − Dal)`. Při
sestavení před koncem období se saldo účtu 431 mapuje do výsledku minulých let;
k poslednímu dni období patří do řádku běžného výsledku spolu s vypočteným
výsledkem.

## 49.4 Kontroly

Kontrola **Aktiva = Pasiva** porovnává Aktiva netto a Pasiva celkem na haléře.
Další vazba porovnává výsledek hospodaření v rozvaze s výsledkem vypočteným
přímo ze všech nákladových a výnosových účtů. Backendová odpověď navíc vrací
nenamapované nenulové účty; aktuální stránka z kontrolního bloku zobrazuje
rovnost stran a obě bilanční částky.

Nesoulad není zaokrouhlovací rozdíl obrazovky; před použitím výkazu je nutné
prověřit obratovou předvahu, mapu účtů a závěrkové zápisy.

## 49.5 Kategorie účetní jednotky a rozsah

Automatický rozsah vychází z nejnižší kategorie, u které účetní jednotka
nepřekračuje alespoň dvě ze tří mezí:

| Kategorie | Aktiva netto | Čistý obrat | Průměrný počet zaměstnanců |
|---|---:|---:|---:|
| Mikro | 11 000 000 Kč | 22 000 000 Kč | 10 |
| Malá | 120 000 000 Kč | 240 000 000 Kč | 50 |
| Střední | 600 000 000 Kč | 1 200 000 000 Kč | 250 |
| Velká | nad limity střední kategorie | nad limity střední kategorie | nad limity střední kategorie |

Aktiva netto používají stejnou mapu jako rozvaha. Čistý obrat pro kategorizaci
je obrat účtů 601, 602 a 604; zaměstnance přebírá nastavení účetnictví.
Limity jsou načteny podle roku konce období.

Po uzavření se kritéria a hrubá kategorie období zmrazí. Změna kategorie se
uplatní podle dvou po sobě jdoucích uzavřených období se stejnou hrubou
kategorií. Ruční přepis rozsahu má přednost, povinný audit však vždy vynutí
plný rozsah.

Rozsah řádků:

- **plný** — bez omezení úrovně,
- **malá** — rozvaha do druhé úrovně a navíc povinné řádky C.II.1. a C.II.2.,
- **mikro** — jen nejvyšší úroveň,
- **automaticky** — mikro → mikro, malá → malá, střední/velká → plný.

## 49.6 Export

PDF a XLSX používají stejný rozvahový den, rozsah, verzi mapy, srovnávací
období a kontroly jako obrazovka. Přepínač tisíců export neovlivňuje.

> ⚠️ **Zelená rovnost stran není kontrola věcného zařazení.** Rozvaha je
> odvozena z účtového rozvrhu a mapy výkazu; podezřelý řádek rozbalte a jeho
> účty ověřte v opisu.
