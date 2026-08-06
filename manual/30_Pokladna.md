# 30. Pokladna

Pokladna slouží k evidenci hotovostních příjmů a výdajů — příjmové (PPD) a výdajové (VPD)
pokladní doklady, pokladní knihu a zaúčtování hotovostních pohybů na účet 211. Modul je dostupný
firmám v **obou** účetních režimech — podvojném účetnictví i daňové evidenci; v daňové evidenci
běží pokladna bez účetního deníku (kasová báze), zatímco u podvojného účetnictví se každý doklad
promítá do [Účetního deníku](45_Ucetni_denik.md).

Najdete ji v menu **Peníze → Pokladna** (hned za položkou Bankovní účty).

> [!NOTE]
> V podvojném účetnictví může být pokladna vedena v **CZK, EUR, USD nebo GBP**.
> Valutová pokladna eviduje cizí částku i její korunový ekvivalent a nese
> cizoměnovou stopu pro závěrkové přecenění. V režimu daňové evidence zůstává
> pokladna korunová, protože tento režim nemá účetní deník ani analytiky 211.

## 30.1 Číselník pokladen

Firma může mít **libovolný počet pokladen** — typicky jednu hlavní, případně další (např. pro
pobočku nebo konkrétní provozovnu). Správa pokladen se otevírá tlačítkem **Spravovat pokladny**
na hlavní stránce pokladny.

### 30.1.1 Založení pokladny

V modálním okně správy pokladen vyplníte:

- **Název** pokladny (např. „Hlavní pokladna").
- **Měna** — CZK, EUR, USD nebo GBP. Měnu zvol při založení; po vzniku pohybů ji
  nelze zaměnit za jinou.
- **Účet** — analytika účtu 211 z účtové osnovy firmy (např. 211100). Nabízí se jen aktivní účty
  začínající prefixem 211; pokud vhodná analytika v osnově chybí, je u pole odkaz na založení
  účtu přímo v osnově. V daňové evidenci se pole s účtem nezobrazuje vůbec (evidence nemá účtovou
  osnovu ani deník). U valutové pokladny je analytika volitelná; ponecháš-li ji
  prázdnou, systém vybere volný kód 211 a účet v osnově založí. Už používanou
  analytiku nepřevezme.
- **Výchozí pokladna** — zaškrtnutím se nastaví jako výchozí pro nové doklady; při uložení se
  příznak automaticky odebere ostatním pokladnám firmy.

Každá pokladna musí mít **jinou analytiku 211** — nelze založit dvě pokladny na stejný účet (ani
neaktivní pokladna účet needsuvolní). Po založení systém pokladnu rovnou zobrazí v seznamu s
aktuálním zůstatkem.

### 30.1.2 Správa existujících pokladen

Tabulka v modálním okně zobrazuje u každé pokladny název, účet (s názvem z osnovy), aktuální
zůstatek, přepínač výchozí pokladny a stav aktivní/neaktivní ano/ne. Dostupné akce:

- **Nastavit jako výchozí** — kliknutím na přepínač (radio button) u řádku pokladny.
- **Aktivovat/deaktivovat** — kliknutím na badge stavu; neaktivní pokladna se v nabídkách pro
  nové doklady dále nenabízí, existující doklady zůstávají zachované.
- **Smazat** — smazání je možné jen u pokladny **bez jediného dokladu**. Pokud pokladna už
  doklady má, systém nabídne místo smazání deaktivaci.

> [!TIP]
> Pokud plánujete pokladnu jen dočasně vyřadit z provozu (např. na konci roku), použijte
> deaktivaci — smazání je vyhrazené jen pro omylem založené prázdné pokladny.

## 30.2 Hlavní stránka pokladny — přehled dokladů

Stránka **Peníze → Pokladna** zobrazuje:

- **Hero panel se zůstatkem** vybrané pokladny k aktuálnímu dni, název pokladny a účet analytiky.
  U valutové pokladny je vedle korunové účetní hodnoty vidět také zůstatek v její měně.
  Pokud je zůstatek záporný, zobrazí se červené upozornění „záporný zůstatek pokladny" — pokladna
  do minusu jít nemá (krátkodobý finanční majetek), ale systém to eviduje jako varování, nikoli
  jako tvrdý blok.
- **Výběr pokladny** (pokud jich firma má víc) v rozbalovací nabídce v hlavičce.
- **Tlačítka Příjem (PPD)** a **Výdej (VPD)** pro vystavení nového dokladu — zelené pro příjem,
  oranžové pro výdej.
- **Odkaz na Pokladní knihu** (§ 30.5).

### 30.2.1 Filtry a sloupce seznamu

Nad tabulkou dokladů jsou filtry: rozsah data (od–do, výchozí je aktuální kalendářní rok), typ
dokladu (příjem/výdej), stav (zaúčtováno/stornováno) a fulltextové hledání v popisu. Filtry lze
uložit jako výchozí přes nabídku uložených filtrů; zobrazené sloupce a hustotu řádků lze upravit
tlačítky Sloupce a Hustota (stejný mechanismus jako v ostatních tabulkových přehledech).

Dostupné sloupce: číslo dokladu, datum, typ (P/V), partner, popis, částka, vazba (odkaz na
navázanou fakturu nebo účel dokladu), stav, DUZP, vytvořeno a kdo doklad vytvořil (poslední tři
jsou ve výchozím zobrazení skryté).

Kliknutím na řádek se rozbalí detail dokladu — účel platby, IČ/DIČ partnera (u daňových dokladů),
DUZP, název pokladny a u dokladu s DPH tabulka rozpadu základ/sazba/daň po jednotlivých sazbách.
V detailu je také prokliknutí **Zobrazit v deníku** na konkrétní zápis (jen v podvojném
účetnictví — daňová evidence deník nemá).

U každého řádku jsou k dispozici:

- **Tisk** — otevře PDF dokladu (§ 30.4).
- **Storno** — jen u zaúčtovaného dokladu (§ 30.3.5).

Stránka podporuje serverové stránkování (50 dokladů na stránku).

## 30.3 Vystavení pokladního dokladu (PPD/VPD)

Formulář nového dokladu se otevírá tlačítkem **Příjem** nebo **Výdej** z hlavní stránky pokladny,
případně z rychlé akce v menu (ikona plus u položky Pokladna). Předvyplní se typ dokladu podle
zvoleného tlačítka a aktuální/výchozí pokladna.

### 30.3.1 Hlavička dokladu

- **Typ dokladu** — přepínač Příjem/Výdej (P = příjmový pokladní doklad, V = výdajový).
- **Pokladna** — výběr z aktivních pokladen firmy.
- **Datum** — datum vystavení dokladu = datum pokladního pohybu. Číslo dokladu (řada
  `PPD-RRRR-####` / `VPD-RRRR-####`) se přiděluje **až při zaúčtování**, ne při rozepsání
  formuláře.

### 30.3.2 Účel dokladu

Nabídka účelů se liší podle typu dokladu:

| Typ | Dostupné účely |
|---|---|
| Příjem (PPD) | Prodej (tržba), Úhrada faktury, Převod, Ostatní |
| Výdej (VPD) | Nákup, Úhrada přijaté faktury, Převod, Ostatní |

U **valutové pokladny** jsou záměrně dostupné jen účely, které lze bezpečně
zaúčtovat bez saldokontního nebo převodového protějšku: PPD **Prodej/Ostatní** a
VPD **Nákup/Ostatní**. Úhrady faktur a převody přes 261 formulář nenabídne.

Podle zvoleného účelu se formulář dynamicky mění:

**Prodej / Nákup** — zobrazí se pole partner (s našeptávačem z evidence klientů), IČ a DIČ
partnera. Volitelně lze doklad vystavit s DPH (viz § 30.3.3).

**Úhrada faktury / Úhrada přijaté faktury** — zobrazí se našeptávač nezaplacených dokladů
(vyhledávání podle čísla nebo partnera); po výběru dokladu se automaticky předvyplní partner,
popis a částka. U úhrady vydané faktury lze uhradit i částečnou částku (do výše zbývající k
úhradě). U úhrady přijaté faktury (VPD) je nutné uhradit **celou zbývající částku najednou** —
částka je po výběru dokladu needitovatelná (pole je uzamčené). Po zaúčtování se faktura označí
jako uhrazená. U přijaté hotovostní úhrady zálohové faktury se stejně jako u bankovní platby
automaticky připraví koncept daňového dokladu k částečné platbě, nebo koncept finální faktury
při úplném uhrazení zálohy.

**Převod** — pokladní strana převodu hotovosti mezi bankou a pokladnou (druhá strana — bankovní
pohyb — se řeší samostatně mimo pokladní doklad).

**Ostatní** — volný protiúčet z účtové osnovy (musí být jiný než účet vybrané pokladny) pro
případy, které nespadají pod žádný z předchozích účelů (např. manko/přebytek pokladny). DPH u
tohoto účelu není podporována.

### 30.3.3 DPH na pokladním dokladu

DPH lze zapnout pouze u účelů **Prodej** a **Nákup** (u úhrad faktur, převodů a ostatního je
DPH vynuceně vypnuté — u úhrady faktury DPH nese už samotná faktura). Po přepnutí přepínače na
„S DPH" se zpřístupní:

- **DUZP** (datum uskutečnění zdanitelného plnění), výchozí shodné s datem vystavení.
- **Rozpad DPH podle sazeb** — komponenta umožňuje přidat řádky se sazbou (nabízí se aktuální
  sazby > 0 z číselníku sazeb pro daný rok), základem a daní; celková částka rozpadu se musí
  **přesně** rovnat celkové částce dokladu.

> [!WARNING]
> U korunového nákupu (VPD) s DPH formulář z bezpečnostních důvodů blokuje
> částku **10 000 Kč včetně DPH a vyšší** (hranice zjednodušeného daňového
> dokladu). Od této částky zobrazí upozornění a doklad nelze
> zaúčtovat; vyšší částky je nutné vést jako přijatou fakturu a uhradit ji účelem „Úhrada přijaté
> faktury". U valutového nákupu se tento korunový limit ve formuláři automaticky
> neposuzuje; použitelnost zjednodušeného dokladu je proto nutné zkontrolovat
> ručně podle CZK protihodnoty. U prodeje nad 10 000 Kč bez vyplněného DIČ partnera se zobrazí jen informativní
> upozornění (nejde o blokaci) kvůli evidenci pro kontrolní hlášení.

### 30.3.4 Částka, popis a náhled zaúčtování

Povinná pole jsou **celková částka včetně DPH** (u úhrady přijaté faktury needitovatelná, viz
výše) a **popis** (obsah účetního případu). V podvojném účetnictví se vpravo zobrazuje **živý
náhled zaúčtování** — tabulka MD/D podle zvoleného účelu a částky, s účty a jejich názvy z osnovy;
v daňové evidenci se náhled nezobrazuje (evidence žádný deníkový zápis nevytváří).

Ve valutové pokladně zadáváš částku v měně pokladny. Systém použije kurz ČNB k
datu dokladu; není-li pro daný den dostupný, použije dostupný kurz v povoleném
náhradním okně, jinak uložení odmítne. Do deníku se uloží korunový ekvivalent,
zatímco na řádku 211 zůstane
částka v cizí měně a použitý kurz. U rozpisu DPH zadáváš základ a daň v měně
pokladny; jednotlivé řádky se převedou do CZK a jejich korunový součet musí
přesně odpovídat zaúčtované částce.

Po vyplnění formuláře se doklad ukládá tlačítkem **Uložit** — doklad se rovnou zaúčtuje (nevzniká
mezikrok rozpracovaného dokladu, který by bylo nutné dodatečně zaúčtovat). Po uložení se zobrazí
číslo přiděleného dokladu a případná varování (např. záporný zůstatek pokladny po zaúčtování).

### 30.3.5 Storno dokladu

Zaúčtovaný doklad nelze opravit ani smazat — jedinou cestou k opravě je **storno** (tlačítko s
ikonou zpětné šipky u řádku v seznamu). Storno vyžaduje:

- **Důvod storna** — povinné textové pole (minimálně 3 znaky), vloží se do popisu protizápisu.
- **Datum storna** — volitelné, výchozí je aktuální datum.

Po potvrzení systém vytvoří zrcadlový protizápis, doklad se označí jako stornovaný (v seznamu
přeškrtnutý a ztlumený) a číslo dokladu zůstává v řadě obsazené (nedorovnává se). Pokud šlo o
úhradu faktury, storno zruší i příslušný záznam úhrady a stav faktury/přijaté faktury se vrátí do
předchozího stavu.

> [!WARNING]
> Storno dokladu, který už byl zahrnut do podaného přiznání k DPH nebo kontrolního hlášení, se
> v podaném přiznání zpětně neopraví — případný rozdíl je nutné řešit dodatečným přiznáním nebo
> následným kontrolním hlášením mimo tento systém.

## 30.4 Tisk pokladního dokladu

Tlačítko tisku u řádku v seznamu otevře PDF verzi dokladu (příjmový/výdajový pokladní doklad s
označením PPD/VPD, číslem, údaji firmy, partnerem, datem vystavení a DUZP, částkou, účelem
platby, rozpadem DPH u daňových dokladů a podpisovými bloky Vystavil/Schválil/Pokladník/Příjemce).
Tisk je dostupný jen u dokladů, které už byly zaúčtovány (případně stornovány) — u rozpracovaného
dokladu nedává tisk smysl. Tisk je dostupný v obou účetních režimech.

U valutového dokladu PDF uvádí původní částku a měnu, korunový ekvivalent i
použitý kurz. Tyto údaje před archivací zkontroluj.

## 30.5 Pokladní kniha

Stránka **Pokladní kniha** (odkaz z hlavní stránky pokladny) zobrazuje chronologický přehled
všech pohybů na vybrané pokladně za zvolené období — obdoba výpisu z bankovního účtu, jen pro
hotovost.

### 30.5.1 Filtry a souhrn

Nahoře lze zvolit pokladnu (pokud jich firma má víc) a rozsah data od–do (výchozí je od začátku
kalendářního roku do dneška). Čtyři souhrnné karty ukazují:

- **Počáteční zůstatek** k začátku období,
- **Příjmy celkem** za období,
- **Výdaje celkem** za období,
- **Konečný zůstatek** ke konci období (červeně, pokud je záporný).

U valutové pokladny jsou souhrnné karty a deníkové pohyby v korunové účetní
hodnotě. Množstevní zůstatek v měně pokladny je vidět v přehledu pokladen;
PPD/VPD a jejich PDF nesou obě hodnoty.

Pokud je zůstatek kdekoli v zobrazeném období záporný, nad tabulkou se zobrazí výstražný pruh.

### 30.5.2 Tabulka pohybů

Řádky obsahují datum, číslo dokladu (proklik zpět na doklad v seznamu), typ (P/V), partnera,
popis, účel (skrytý ve výchozím zobrazení), DUZP (skrytý), odkaz na zápis v deníku (skrytý — jen
podvojné účetnictví), příjem, výdej a průběžný zůstatek po každém řádku. První řádek tabulky vždy
zobrazuje počáteční zůstatek období. Sloupce a hustotu řádků lze upravit stejně jako u ostatních
tabulek v aplikaci.

Zdrojem pravdy pro zůstatek je vždy **účetní kniha** (zaúčtované obraty na analytice pokladny),
nikoli součet pokladních dokladů — pokud by na účet pokladny vznikl i ruční zápis mimo pokladní
doklady, projeví se v knize s prázdným popisem vazby, ale zůstatek zůstává konzistentní.

### 30.5.3 Export do PDF

Tlačítko **PDF pokladní knihy** vygeneruje tiskovou sestavu za celý zvolený rozsah data (bez
stránkování) s hlavičkou pokladny, počátečním a konečným zůstatkem a přehledem příjmů/výdajů —
vhodné jako podklad k roční uzávěrce nebo pro kontrolu.

## 30.6 Zaúčtování a vazba na deník

V podvojném účetnictví se každý zaúčtovaný pokladní doklad promítá standardním způsobem do
[Účetního deníku](45_Ucetni_denik.md) — konkrétní účtovací předpis (MD/D) se liší podle účelu
dokladu:

| Účel | Zaúčtování (zjednodušeně) |
|---|---|
| Prodej (PPD) | MD Pokladna (211) / D Tržby (602) + DPH na 343 |
| Nákup (VPD) | MD Náklad (501) + DPH na 343 / D Pokladna (211) |
| Úhrada vydané faktury | MD Pokladna (211) / D Pohledávky (311) |
| Úhrada přijaté faktury | MD Závazky (321) / D Pokladna (211) |
| Převod — příjem z banky | MD Pokladna (211) / D Převody mezi účty (261) |
| Převod — odvod do banky | MD Převody mezi účty (261) / D Pokladna (211) |
| Ostatní | volný protiúčet podle zvoleného účtu |

Strana účtu 211 v zápisu vždy odpovídá konkrétní analytice zvolené pokladny. Z detailu dokladu
i z řádku pokladní knihy lze prokliknout přímo na odpovídající zápis v deníku. V daňové evidenci
žádný deníkový zápis nevzniká — pokladní pohyb se eviduje jen v rámci pokladny samotné (kasová
báze).

U valutové pokladny jsou všechny deníkové řádky vedené v CZK a řádek analytiky
211 navíc obsahuje měnu, kurz a cizí částku. Díky tomu ji závěrková kontrola
kurzových pozic zahrne mezi účty k přecenění. Samotné PPD/VPD typu Prodej,
Nákup a Ostatní se zaúčtují automaticky stejně jako korunové doklady, včetně
rozpadu DPH; nejde o pouhý evidenční záznam čekající na ruční deník.

> [!NOTE]
> **Zálohová přijatá faktura.** Pokud u účelu **Úhrada přijaté faktury** vybereš zálohovou
> (proforma) přijatou fakturu, zaúčtuje se místo saldokonta 321 jako **poskytnutá záloha —
> MD 314 Poskytnuté zálohy / D 211 Pokladna**. Když později zaúčtuješ vyúčtovací fakturu
> vázanou na tuto zálohu, zápis automaticky doplní i zúčtovací řádek (321/314) ve výši
> skutečně zaplacené zálohy — viz [Přijaté faktury § 23.3.1](23_Prijate_faktury.md#2331-propojeni-zalohy-s-vyuctovaci-fakturou-proti-dvojimu-zapocteni).

## Omezení a tipy

- Valutová pokladna je dostupná jen v podvojném účetnictví. Podporuje samostatný
  hotovostní Prodej, Nákup a Ostatní, nikoli úhradu cizoměnové vydané/přijaté
  faktury. Ta vyžaduje saldokontní vypořádání 311/321 v cizí měně a systém ji
  záměrně odmítne místo vytvoření neúplného zápisu.
- Převod mezi valutovou pokladnou a bankou nebo jinou pokladnou přes účet 261
  zatím není podporovaný. Různé měny vyžadují doložený kurz a kurzový rozdíl;
  zaúčtuj je ručně v deníku.
- Úhradu přijaté faktury z pokladny lze provést jen v plné výši; částečné úhrady přijatých
  faktur hotově systém nepodporuje.
- Z korunové pokladny lze uhradit jen korunovou fakturu; z valutové pokladny
  není účel úhrady faktury dostupný ani pro fakturu ve stejné měně.
- Korunový daňový doklad při nákupu (VPD) formulář blokuje od 10 000 Kč včetně
  DPH. U valutového VPD kontroluj
  limit ručně podle korunové protihodnoty.
- Doklad, jednou zaúčtovaný, se needituje ani nemaže — jedinou opravou je storno s uvedením
  důvodu a případné vystavení nového dokladu.
- Smazat lze jen pokladnu bez jediného dokladu; jinak nabídne systém deaktivaci.

> [!TIP]
> Pokud potřebujete přehled hotovostních pohybů za delší období (např. pro účetní uzávěrku),
> použijte export PDF z pokladní knihy — obsahuje kompletní chronologický přehled včetně
> počátečního a konečného zůstatku bez stránkování.
