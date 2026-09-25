# 57. Rozvaha

**Cesta: `Účetnictví → Rozvaha`**

Rozvaha sestavuje aktiva a pasiva k rozvahovému dni ve struktuře přílohy
č. 1 vyhlášky č. 500/2002 Sb. Je dostupná jen pro podvojné účetnictví.

## 57.1 Období, den a verze

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
aby byly sloupce srovnatelné. Výjimky mapování firmy a souhrnné vykázání daní
se ve sloupci minulého období řídí pravidly běžného roku; změnu zařazení pak
vysvětlete v příloze. Kdo chce sloupec minulého období převzít tak, jak byl
v uzavřeném výkazu minulého roku, zapne v **Nastavení uzávěrky** na stránce Účetní období
volbu **Minulé období výkazů převzít z uzavřeného výkazu minulého roku**: sloupec
se pak sestaví s výjimkami a volbami platnými v minulém roce.

## 57.2 Zůstatky, znaménka a mapování

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

### Souhrnné vykázání daní vůči finančnímu úřadu

Daňové pohledávky a závazky se ve výchozím stavu vykazují zvlášť: přeplatek
jedné daně v aktivech (**Stát — daňové pohledávky**), nedoplatek jiné
v pasivech (**Stát — daňové závazky a dotace**). Vyhláška v § 58 odst. 2 za
vzájemné zúčtování nepovažuje souhrnné vykázání pohledávek a závazků vůči téže
osobě se splatností do jednoho roku. Volba **Daně vůči finančnímu úřadu
vykazovat v rozvaze souhrnně** v **Nastavení uzávěrky** na stránce Účetní období proto
započte přeplatky a nedoplatky na účtech 341 až 345 (daň z příjmů, ostatní
přímé daně, DPH, ostatní daně); dotace (346) a pojistné (336) do započtení
nevstupují. Aktiva i pasiva klesnou o stejnou, menší z obou částek, v detailu
řádku je vidět jako samostatná položka. Pole **Od účetního období** omezí
volbu na roky, kdy ji firma používá. Souhrnné vykázání je třeba uvést
v příloze; příloha u účetních zásad nabídne větu se započtenými částkami.

## 57.3 Strom a výpočtové řádky

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

## 57.4 Kontroly

Kontrola **Aktiva = Pasiva** porovnává Aktiva netto a Pasiva celkem na haléře.
Další vazba porovnává výsledek hospodaření v rozvaze s výsledkem vypočteným
přímo ze všech nákladových a výnosových účtů. Backendová odpověď navíc vrací
nenamapované nenulové účty; aktuální stránka z kontrolního bloku zobrazuje
rovnost stran a obě bilanční částky.

Řádek aktiv se **záporným netto** (korekce vyšší než brutto) stránka ukáže nad
tabulkou jako varování, v běžném i minulém období. Nejčastější příčinou je
opravná položka zařazená jinam než pohledávka, ke které patří, třeba celá 391
v obchodních pohledávkách, zatímco pohledávka je výjimkou v dlouhodobých.
Opravte ji výjimkou mapování s vazbou na pohledávku (kapitola 57.7). Stejné
varování zapíše do protokolu převod dat z jiného programu.

Nesoulad není zaokrouhlovací rozdíl obrazovky; před použitím výkazu je nutné
prověřit obratovou předvahu, mapu účtů a závěrkové zápisy.

## 57.5 Kategorie účetní jednotky a rozsah

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

## 57.6 Export

PDF a XLSX používají stejný rozvahový den, rozsah, verzi mapy, srovnávací
období a kontroly jako obrazovka. Přepínač tisíců export neovlivňuje.

## 57.7 Mapování účtů pro konkrétní firmu

**Cesta: `Účetnictví → Mapování účtů do výkazů`**

Globální mapa zařazuje účet podle čísla syntetiky. Předpis ale nechává firmě
volby, které z čísla účtu vyčíst nejde. Pro ně slouží výjimky mapování, které
platí jen pro vybranou firmu:

- **splatnost** — půjčka od společníka na analytice 365.100 splatná za víc
  než rok patří do dlouhodobých závazků (C.I.), ne do krátkodobých (C.II.),
- **spřízněné osoby** — pohledávky, závazky, výnosy a náklady vůči ovládané
  nebo ovládající osobě patří do vlastních řádků a podřádků výkazů,
- **zařazení analytiky** — konkrétní analytika může patřit do jiného řádku
  než její syntetika.

Stránka má záložky **Rozvaha** a **Výsledovka**. Tabulka ukazuje účty firmy,
jejich zůstatek ke konci vybraného období a řádek, kam je výkaz dnes zařadí,
spolu se zdrojem zařazení (globální mapa, mapa funkcí u účelové výsledovky,
výjimka firmy). Ve sloupci **Řádek výkazu** se vybírá cílový řádek ze stromu
řádků výkazu, volba **(podle globální mapy)** výjimku ruší. U saldového účtu
lze výjimku omezit na debetní nebo kreditní zůstatek; opačná strana se pak
řídí mapou bez výjimky. U řádku aktiv lze účet zařadit jako korekci. Poznámka
slouží k zapsání důvodu, například splatnosti.

Výjimka se zadává prefixem účtu stejně jako globální mapa. Při více shodách
vyhrává nejdelší prefix a při stejné délce vyhrává výjimka firmy. Výjimka na
analytiku (365.100) proto přesune jen tu analytiku, ostatní analytiky
syntetiky zůstanou podle globální mapy.

**Opravná položka k pohledávce.** U výjimky zařazené jako korekce lze do pole
**k pohledávce** zapsat účet pohledávky (například 351.100). Korekce pak jde
vždy do řádku, kam výkaz zařadí tuto pohledávku, i když ji později přeřadíte;
vybraný řádek platí jen tehdy, když účet pohledávky v mapě není. Netto řádku
pohledávky tak odpovídá pohledávce snížené o její opravnou položku a opravná
položka nesnižuje obchodní pohledávky.

**Platnost po letech.** Sloupec **Platí v letech** omezí výjimku na účetní
období od roku / do roku (prázdné = bez omezení). Tabulka ukazuje výjimky
platné ve vybraném období; výjimky jiných let jsou v přehledu pod tabulkou.
Pro jeden účet a stranu zůstatku se roky platnosti výjimek nesmí překrývat.
Když účet už výjimku jiných let má, nová výjimka vybraná v tabulce platí od
roku vybraného období.

Změny se nejdřív jen připravují a ukládají se najednou tlačítkem **Uložit**
v liště dole; **Zahodit změny** vrátí uložený stav. **Náhled dopadu** ukáže
řádky výkazu, jejichž hodnota se po uložení změní, a upozorní, kdyby rozvaha
přestala být vyrovnaná. Náhled nic neukládá.

Výjimky používá rozvaha, obě výsledovky, jejich PDF a XLSX exporty, měsíční
report, uzávěrkový balík, kategorie účetní jednotky i příloha účetní závěrky
v přiznání k dani z příjmů právnických osob.

### 57.7.1 Návrh z podaného přiznání

Když je za rok v evidenci podané přiznání k dani z příjmů právnických osob,
tlačítko **Navrhnout z podaného přiznání** porovná přílohu účetní závěrky
(aktiva, pasiva a výsledovku v celých tisících Kč), jak ji vyrobí aplikace,
s přílohou podaného přiznání. Kde se dva řádky liší opačně o částku, která
odpovídá zůstatku jednoho účtu nebo analytiky (s tolerancí 1 tis. Kč na
zaokrouhlení), navrhne tento účet přeřadit do řádku, kde ho má podané
přiznání. Přiznání, které v evidenci není, lze nahrát jako XML přes
**Navrhnout z XML přiznání** v nabídce dalších akcí.

U aktiv se brutto a korekce porovnávají zvlášť. Návrh tak najde přesun
pohledávky i její opravné položky, i když jsou v aplikaci každá v jiném řádku,
a korekci přesunutou do stejného řádku jako pohledávku k ní rovnou naváže.

Podané přiznání nese i sloupec minulého období, jak byl v uzavřeném výkazu
minulého roku. Blok **Minulé období** navrhne výjimky platné do minulého roku,
se kterými bude sloupec minulého období shodný s podaným (při zapnutém převzetí
z uzavřeného výkazu). Má-li účet výjimku bez omezení, převzetí ji omezí na
pozdější roky a návrh přidá pro minulý rok.

Každý návrh uvádí účet, výchozí a cílový řádek a odůvodnění. Návrh, který by
stejně dobře vysvětlil víc účtů, je označený jako nejistý a není předvybraný.
Vybrané návrhy se tlačítkem **Převzít vybrané** přenesou do připravovaných
změn; uloží se až tlačítkem **Uložit**. Pod návrhy je rozbalovací seznam všech
rozdílných řádků přílohy.

> ⚠️ **Zelená rovnost stran není kontrola věcného zařazení.** Rozvaha je
> odvozena z účtového rozvrhu a mapy výkazu; podezřelý řádek rozbalte a jeho
> účty ověřte v opisu.

## 57.8 Rozvaha po účtech

Záložka **Po účtech** nad rozvahou ukáže místo zákonné struktury seznam
rozvahových účtů tříd 0 až 4 se zůstatkem k rozvahovému dni. Účty jsou
seřazené po třídách, pod syntetikou jsou její analytiky (přepínač
**Zobrazit analytiky** je skryje). Syntetika má ve sloupcích **Zůstatek MD**
a **Zůstatek D** součet debetních a kreditních zůstatků svých analytik, bez
vzájemného započtení, takže kladný běžný účet a kontokorent zůstanou každý na
své straně. Každá třída má svůj součet.

Pod součtem rozvahových účtů je zvýrazněný řádek **Výsledek hospodaření**
(zisk zeleně, ztráta červeně) jako rozdíl `Σ MD − Σ D`. Je k dispozici
kdykoli během roku bez uzávěrky. Zůstatky jsou vždy před uzavřením účetních
knih: uzávěrkový zápis se nezapočítává, takže po uzavření roku pohled k poslednímu
dni období ukazuje přesně to, co uzávěrka převedla na konečný účet rozvažný 702.

Kliknutím na účet se otevře jeho opis od začátku období do rozvahového dne.
Rozvahový den a filtr dimenze platí stejně jako u výkazu, rozsah výkazu se
tu nepoužívá. Když se výsledek z rozvahových účtů neliší od výsledku
z výsledkových účtů ([§ 58.6](58_Vysledovka_druhova.md)), je vše v pořádku;
rozdíl stránka ohlásí nad tabulkou a obvykle ukazuje na chybu v počátečních
stavech nebo v uzávěrce minulého roku. Při filtru dimenze obsahují rozvahové
účty jen řádky s touto hodnotou, takže se jejich výsledek od výsledovky
lišit může; stránka to pak ukáže jen jako upozornění a za výsledek dimenze
platí výsledovka.

Export PDF a XLSX z této záložky vytvoří rozvahu po účtech v jednotce zvolené
přepínačem **Kč / tis. Kč**.
