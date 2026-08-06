# 33. Sklad

Modul **Sklad** je skladová evidence pro firmy, které nakupují a prodávají materiál,
zboží nebo vyrábí výrobky. Vede skladové karty, příjemky/výdejky/převodky mezi
sklady, umožňuje víc skladů, inventury a základní skladové sestavy. Napojuje se na
[Vydané faktury](14_Faktury.md) a [Přijaté faktury](23_Prijate_faktury.md) — umí
automaticky vydat zboží ze skladu při vystavení faktury a naskladnit zboží z přijaté
faktury.

V menu ho najdeš pod sekcí **Sklad** (zobrazí se jen po zapnutí modulu):
**Skladové karty**, **Skladové doklady**, **Inventury**, **Sestavy**. Číselník
**Sklady** (jednotlivé sklady firmy) je záložka na stránce **E-shop** — modul Sklad
totiž sdílí skladové karty s [e-shopovým modulem](34_Eshop.md) (ceny, kategorie,
parametry, dodavatelé, přílohy k produktu).

> [!NOTE]
> Sklad je **doplňkový, nepovinný modul (opt-in)** — dokud ho v Nastavení firmy
> nezapneš, sekce Sklad se v menu vůbec nezobrazí a všechny skladové API endpointy
> vrací chybu „Skladový modul není pro tuto firmu zapnutý" (HTTP 403) — a to i na
> čtecí požadavky, aby se do frontendu vůbec nedostala žádná data nezapnutého
> modulu. Funguje **nezávisle na účetním režimu** — stejně dobře pro podvojné
> účetnictví i pro daňovou evidenci.

## 33.1 Zapnutí modulu

Sklad zapneš na stránce úpravy firmy (**Systém → Nastavení**, viz
[§ 71.5 Editace dodavatele](71_Multi_supplier.md#715-editace-dodavatele)) v samostatné
sekci **Vést skladovou evidenci**:

- **Vést skladovou evidenci** — hlavní přepínač (interně sloupec `stock_enabled` na
  firmě). Zpřístupní skladové karty, doklady, inventury i skladové sestavy a přidá
  do menu sekci Sklad.
- **Automatická výdejka při vystavení faktury** *(zobrazí se jen po zapnutí evidence,
  interně sloupec `stock_auto_issue`, výchozí zapnuto)* — když má faktura řádky
  napojené na skladovou kartu, při vystavení faktury se automaticky založí a
  zaúčtuje výdejka. Vypnutím přepínače přejdeš na ruční vydávání zboží ze skladu
  (výdejky si zakládáš sám/sama) — použij to, pokud chceš mít nad výdejem plnou
  kontrolu nebo výdejky slučuješ jinak, než jak fakturuješ.

Sklad interně účtuje **způsobem B** dle ČÚS 015 (bod 4.3) — v průběhu roku se
skladové pohyby neúčtují na účty, jen evidují; do účetnictví se promítne až
**uzávěrkovým krokem** (konečný/počáteční stav zásob, reklasifikace inventurních
mank a přebytků — viz uzávěrka v modulu Účetnictví). To je aktuálně jediný podporovaný
způsob účtování zásob; datový model má připravený i sloupec `stock_method` s hodnotami
`B`/`A`, ale zapisuje se do něj natvrdo jen `B` — přepnutí na způsob A není v tomto
vydání funkčně implementované.

## 33.2 Skladové karty

**Sklad → Skladové karty** je stránkovaný seznam karet materiálu, zboží a výrobků
(výchozí stránkování 50 karet na stránku).

**Filtry**: typ karty (Materiál/Zboží/Výrobek), sklad (omezí zobrazený stav a hodnotu
na vybraný sklad), **jen pod minimem**, jen aktivní a fulltextové hledání (SKU, název,
EAN). Filtry si můžeš uložit jako výchozí přes uložené filtry, sloupce si zapneš/vypneš
přes výběr sloupců a hustotu řádků přes přepínač hustoty (viz
[§ 72.9 Uložené filtry a předvolby zobrazení](72_Nastaveni.md#729-ulozene-filtry-a-predvolby-zobrazeni)).

Sloupce: **SKU**, **Název**, **Typ**, **MJ** (měrná jednotka), **Stav** (aktuální
množství — červeně a tučně, pokud je pod nastaveným minimem), **Hodnota** (ocenění
stavu v Kč), **Prům. cena** (dopočtená průměrná pořizovací cena za jednotku),
volitelně i **Prodejní cena**, **Min. zásoba** a **Aktivní**. Stav a hodnota se
počítají ze skutečných skladových pohybů v momentě zobrazení stránky, ne z uložených
souhrnných čísel karty.

Kliknutím na **Novou skladovou kartu** se otevře editor se základními poli:

- **Název** *(povinné, max 255 znaků)* a **SKU** *(max 50 znaků)* — pokud SKU necháš
  prázdné, dopočítá se automaticky ze zadaného názvu (bez diakritiky, VELKÝMI
  písmeny, zkráceno na 50 znaků; když by ze jména nezbylo nic použitelného, dosadí se
  namísto toho náhodný kód tvaru `SKU-XXXXXXXX`); po prvním ručním zásahu do pole SKU
  se automatický přepočet vypne. SKU musí být v rámci firmy unikátní — při shodě
  systém odmítne uložení chybou „Skladová karta s tímto SKU už existuje" (HTTP 409);
  totéž hlídá i databázový unikátní klíč na dvojici firma + SKU, takže ke kolizi
  nemůže dojít ani při souběžném zakládání.
- **Typ karty**: **Materiál** (spotřebovává se do nákladu 501), **Zboží** (do nákladu
  504) nebo **Výrobek** (uzávěrková kontace 123/583).
  Typ určuje, na jaké účty se v uzávěrce zaúčtuje konečný/počáteční stav a inventurní
  rozdíly — viz posuzovací pravidla `stock.closing.material` (112/501),
  `stock.closing.goods` (132/504), `stock.opening.material` (501/112),
  `stock.opening.goods` (504/132), `stock.shortage.reclass.*` (549/501 nebo 549/504
  pro inventurní manko) a `stock.surplus.*` (501/648 nebo 504/648 pro inventurní
  přebytek); tato posuzovací pravidla jsou uzávěrce k dispozici jako globální šablona,
  kterou lze firemně přenastavit stejně jako ostatní kontace.
- **Měrná jednotka** (výchozí „ks"), **EAN**, **sazba DPH** (výchozí do řádku faktury),
  **prodejní cena bez DPH** (výchozí do řádku faktury) a **minimální zásoba** — pokud
  stav klesne pod tuto hodnotu, karta se v seznamu i sestavách zvýrazní.
- **Poznámka** a příznak **Aktivní** — needativní kartu jde jen deaktivovat, ne smazat
  (viz níže), aby zůstala historie pohybů.

Po založení karty (jen v režimu úpravy) se zpřístupní i **e-shopové taby** — jazykové
mutace popisu, kategorie a štítky, parametry, ceny v jednotlivých měnách, dodavatelé
a přílohy/obrázky. Skladová karta je totiž zároveň produktovou kartou pro e-shop;
tyto taby popisuje kapitola o e-shopu.

### 33.2.1 Detail karty a skladová kniha

Kliknutím na řádek v seznamu se otevře **detail karty**: dlaždice s počátečním
stavem, prodejní cenou, minimální zásobou a EAN, pod nimi záložka **Pohyby** —
kompletní **skladová kniha** karty (datum, doklad s prokliknutím, sklad, množství se
znaménkem, jednotková cena, hodnota a **běžná bilance** po každém řádku). Stránka
pohybů se natahuje po 100 řádcích (dá se vyžádat až 500 najednou), počáteční bilance
před zobrazenou stránkou se dopočítává ze všech předchozích řádků se stejnými filtry.
Stornované doklady se v knize zobrazí ztlumeně, ale zůstávají viditelné.

Akce v hlavičce detailu: **Nová výdejka** (rovnou předvyplní kartu do nového dokladu),
**Upravit**, **export do PDF** a **export do XLSX** (kompletní skladová kniha karty,
natažená dávkově po 500 řádcích bez ohledu na to, kolik pohybů karta má) a
**Deaktivovat** (jen pokud je karta aktivní).

> [!NOTE]
> Skladovou kartu, která má jakýkoli skladový pohyb, **nejde smazat** — pokus o smazání
> vrátí chybu „Skladovou kartu nelze smazat — má skladové pohyby. Deaktivujte ji místo
> mazání." (HTTP 409) a nabídne deaktivaci. Smazat lze pouze čerstvě založenou kartu
> bez pohybů.

### 33.2.2 Vazba na e-shopovou kartu

Skladová a e-shopová karta je v datech **jeden a týž záznam** (tabulka skladových
karet nese i e-shopové sloupce), ale zobrazení v e-shopu se neřídí typem karty
(Materiál/Zboží/Výrobek) — o tom, jestli se karta má exportovat na e-shop, rozhoduje
samostatný, na typu nezávislý příznak **Exportovat do e-shopu** (v editoru na
e-shopové záložce). I materiál nebo výrobek tedy technicky jde nastavit jako
zobrazovaný v e-shopu, a naopak běžnou kartu typu Zboží lze mít vedenou jen skladově,
bez e-shopové prezentace. Druhý související příznak, **Skladem** (interně
`is_stocked`), určuje, jestli e-shop pro danou kartu vůbec hlídá dostupnost skladem —
podrobnosti k oběma příznakům a dalším e-shopovým polím (kategorie, parametry, ceny,
dodavatelé) najdeš v kapitole [E-shop](34_Eshop.md).

## 33.3 Oceňování zásob

Sklad oceňuje zásoby **váženým aritmetickým klouzavým průměrem** (§ 49 odst. 3
vyhlášky 500/2002 Sb., ČÚS 015 bod 3.6) — při každém příjmu se dopočítá nová průměrná
cena z dosavadní hodnoty skladu a hodnoty přijaté dávky; výdej se pak vždy oceňuje
aktuální průměrnou cenou v okamžiku zaúčtování dokladu, ne cenou z minulého příjmu.
Je to jediná podporovaná oceňovací metoda — FIFO/LIFO modul nenabízí.

Uvnitř se nepočítá s desetinnými čísly (float), ale s celočíselnou aritmetikou:
množství se drží v **tisícinách kusu** a hodnota v **haléřích** — haléřová hodnota
skladu je vždy zdrojem pravdy, průměrná jednotková cena (na 6 desetinných míst) je
z ní jen dopočítaná pro zobrazení. Zaokrouhluje se (matematicky, „na obě strany", ne
jen dolů) vždy jen na hranici haléře při každém jednotlivém pohybu, takže se
zaokrouhlovací chyba v čase nekumuluje. Speciální případ je výdej **celého** zbylého
množství karty — ten se vždy oceňuje přesně zbývající hodnotou skladu, takže po něm
nezůstane žádný haléřový „sediment" a stav klesne přesně na 0 Kč / 0 ks.

Systém **tvrdě zakazuje záporný stav zásob** — pokus o výdej/převod/storno, který by
u jakékoli karty způsobil zápor, skončí chybou „Nedostatek zásob pro výdej" (HTTP 409)
a doklad se nezaúčtuje. Kontrola proběhne souhrnně za všechny řádky dokladu najednou
(ne na první nedostatkové položce) a vrátí seznam všech karet, kterým množství
nesedí, s požadovaným i dostupným množstvím — proběhne dřív, než se dokladu přidělí
číslo řady, takže se při chybě žádné číslo „nespálí". Žádnou výjimku ani „povolit
záporný stav" volbu modul nemá — pokud sklad neodpovídá skutečnosti, je potřeba to
nejdřív srovnat inventurou (§ 33.7) nebo opravnou příjemkou.

> [!NOTE]
> Čistě technicky (kvůli celočíselné aritmetice v haléřích) má oceňování horní mez:
> jeden výdej unese hodnotu skladu zhruba do 10 milionů Kč a zhruba 5 milionů kusů
> současně, hodnota jedné karty smí dosáhnout zhruba 4,6 miliardy Kč. Pro běžný
> provoz malé a střední firmy jsou to meze bez praktického významu.

### 33.3.1 Příklad výpočtu klouzavého průměru

Karta nemá zatím žádný pohyb (stav 0 ks / 0 Kč). Následují dva příjmy a jeden výdej:

| Datum | Doklad | Pohyb | Množství | Jedn. cena | Hodnota pohybu | Stav (ks) | Hodnota skladu | Prům. cena |
|---|---|---|--:|--:|--:|--:|--:|--:|
| 1.3. | PRI-2026-0001 | Příjem | +100 | 50,00 Kč | +5 000,00 Kč | 100 | 5 000,00 Kč | 50,0000 Kč |
| 5.3. | PRI-2026-0002 | Příjem (vč. dopravy 200 Kč) | +50 | 60,00 Kč | +3 200,00 Kč | 150 | 8 200,00 Kč | 54,6667 Kč |
| 12.3. | VYD-2026-0001 | Výdej | −80 | 54,6667 Kč (dopočteno) | −4 373,33 Kč | 70 | 3 826,67 Kč | 54,6667 Kč |

- U prvního příjmu je průměrná cena rovnou zadaná jednotková cena (50,00 Kč), protože
  na skladě předtím nic nebylo.
- U druhého příjmu je na řádku zadaná jednotková cena 60,00 Kč (50 ks × 60,00 Kč =
  3 000,00 Kč), k tomu se ale rozpustí vedlejší pořizovací náklad 200,00 Kč (doprava —
  viz § 33.4.4), takže do skladu vstoupí celkem 3 200,00 Kč. Nová průměrná cena se
  počítá z **celkové** hodnoty skladu po příjmu (5 000 + 3 200 = 8 200 Kč) děleno
  celkovým množstvím (100 + 50 = 150 ks) = 54,6667 Kč/ks — ne jen z ceny posledního
  příjmu.
- Výdej 80 ks se ocení aktuální průměrnou cenou 54,6667 Kč/ks, tj. hodnotou
  80 × 54,6667 = 4 373,33 Kč (zaokrouhleno na haléře). Po odečtení zbývá na skladě
  70 ks za 3 826,67 Kč — pořád ve stejné průměrné ceně 54,6667 Kč/ks (výdej nemění
  jednotkovou cenu zbytku, jen úměrně sníží celkovou hodnotu).
- Kdyby se místo 80 ks vydalo **všech** zbývajících 70 ks (po předchozím výdeji),
  poslední výdej by se ocenil přesně zbývající hodnotou skladu bez zaokrouhlovacího
  zbytku a stav by klesl přesně na 0 ks / 0,00 Kč.

## 33.4 Skladové doklady

**Sklad → Skladové doklady** je seznam všech skladových dokladů se záložkami
**Vše / Příjemky / Výdejky / Převodky** (stránkovaný, max 500 řádků na stránku).
Filtry: sklad, stav a fulltext; sloupce zahrnují číslo dokladu, datum, typ, sklad (u
převodky „odkud → kam"), partnera, popis, **původ** a **stav**.

Doklad má tři podoby:

- **Příjemka** — navýší stav na skladu (nákup, počáteční naskladnění, přebytek
  z inventury…).
- **Výdejka** — sníží stav na skladu (prodej, spotřeba, manko z inventury…).
- **Převodka** — přesune zásobu mezi dvěma sklady jedné firmy (zdrojový a cílový
  sklad musí být různé).

### 33.4.1 Životní cyklus dokladu

Doklad prochází stavy **Rozpracován (`draft`) → Zaúčtováno (`posted`) → Stornováno
(`reversed`)** a přechody jsou striktně jednosměrné — z `posted`/`reversed` už se
doklad nikdy nevrátí zpátky do `draft`:

- Nově založený doklad je **Rozpracován (draft)** — dá se libovolně upravovat i
  smazat, ještě nehýbe skladem ani nemá přidělené číslo. Pokus upravit nebo smazat
  doklad, který už není v draftu, vrátí chybu „Upravovat lze jen rozpracovaný (draft)
  doklad." resp. „Smazat lze jen rozpracovaný (draft) doklad." (obojí HTTP 422).
- **Zaúčtování** doklad zamkne, přidělí mu **číslo řady** a teprve teď se promítne do
  stavu zásob a skladové knihy karty (podrobně k číslování § 33.4.2). Opakované
  kliknutí na Zaúčtovat u už zaúčtovaného dokladu není chyba — systém ho jen vrátí
  beze změny (bezpečné proti dvojkliku). Zaúčtovat stornovaný doklad ale nejde
  („Stornovaný doklad nelze zaúčtovat.", HTTP 422). Po zaúčtování už doklad ani jeho
  řádky nejde editovat.
- **Storno** je možné jen u zaúčtovaného dokladu — storno draftu nebo už jednou
  stornovaného dokladu odmítne chybou „Stornovat lze jen zaúčtovaný doklad." resp.
  „Doklad už byl stornován." (obojí HTTP 422). Storno založí **protidoklad**, který
  pohyb otočí zpět **beze změny hodnot** — množství, jednotková cena i hodnota řádků
  se do protidokladu zkopírují přesně tak, jak byly na originálu (hodnotová
  neutralita), otáčí se jen typ dokladu (příjemka ↔ výdejka; u převodky se prohodí
  zdrojový a cílový sklad) a datum protidokladu zůstává shodné s originálem. Popisu
  protidokladu se automaticky předsadí „Storno {číslo originálu}"; volitelný **důvod
  storna**, pokud ho vyplníš, se připojí za pomlčku (typicky pro obsah účetního
  případu ho vyplníš, ale technicky vyplnění vynucené není). Doklad samotný zůstává
  ve stavu Stornováno, historie se nemaže. Storno odmítne i situace, kdy by
  protidoklad sám o sobě způsobil zápor na skladě (typicky storno starší příjemky
  poté, co mezitím proběhl další výdej) — stejnou chybou „Nedostatek zásob", nebo
  pokud na cílovém skladu právě probíhá inventura (§ 33.7) — chybou „Na skladu
  probíhá inventura — dokončete ji před zaúčtováním pohybu." (HTTP 409).

Doklad má i **Původ (origin)** — informaci, odkud vznikl: **Ručně**, **Vydaná
faktura**, **Dobropis**, **Přijatá faktura** nebo **Inventura**. Doklady s jiným
původem než „Ručně" typicky vznikají automaticky (viz § 33.5 a § 33.7) a v editoru
se u nich zobrazí odkaz na zdrojový doklad.

### 33.4.2 Číslování dokladů

Číslo dokladu má formát **`PRI-RRRR-NNNN`** (příjemka), **`VYD-RRRR-NNNN`** (výdejka)
nebo **`PRE-RRRR-NNNN`** (převodka) — prefix, rok dokladu a čtyřmístné pořadové
číslo. Číslování běží **odděleně pro každou firmu, každý typ dokladu a každý rok**
(rok se bere z data dokladu, ne z data zaúčtování), takže si každá řada nezávisle
začíná od 0001. Číslo se přidělí teprve při zaúčtování, v rámci téže databázové
transakce jako zápis pohybu — přidělení je chráněné zamykacím dotazem, aby ani
souběžné zaúčtování dvou dokladů ve stejné vteřině nemohlo vygenerovat duplicitní
číslo. Rozdílové doklady z uzavřené inventury (§ 33.7) čerpají čísla ze **stejné**
běžné řady jako ruční doklady — nemají žádný zvláštní prefix.

### 33.4.3 Editor dokladu

Nový doklad založíš tlačítkem **Nová příjemka** nebo **Nová výdejka** v seznamu (u
nového dokladu bez vazby si typ dokladu i přepneš přímo v editoru — Příjemka /
Výdejka / Převodka). Hlavička: **sklad** (u převodky zvlášť „odkud" a „kam"),
**datum dokladu**, **popis** (povinný, jde o obsah účetního případu dle § 11/1/c
zákona o účetnictví) a nepovinný **partner** (volný text — dodavatel/odběratel).

**Řádky dokladu**: skladová karta (našeptávač podle SKU/názvu), **množství**,
u příjemky i **jednotková cena** (u výdejky/převodky se cena nezadává — dopočítá se
automaticky z klouzavého průměru při zaúčtování) a poznámka. U výdejky/převodky se
u každého řádku zobrazuje náhled **dostupného množství** na zvoleném skladu — je jen
informativní, závaznou kontrolu dělá až zaúčtování; pokud množství nesedí, zobrazí se
u řádku hláška ve tvaru „{SKU} — {název}: požadováno {X}, skladem {Y}".

Akce v hlavičce editoru: **Zaúčtovat** (uloží a rovnou zaúčtuje), **Uložit koncept**,
**Tisk (PDF)** a u zaúčtovaného dokladu **Stornovat** (přes modální dialog s polem
„Důvod storna").

### 33.4.4 Vedlejší pořizovací náklady

U příjemky ve stavu rozpracováno (jen tehdy — po zaúčtování už se položky nedají
přidávat) můžeš přidat libovolný počet položek **vedlejších pořizovacích nákladů**
(doprava, clo, provize apod. podle § 49 odst. 1 vyhlášky 500/2002 Sb.): popis, částka
a způsob rozpuštění — **podle hodnoty** (výchozí) nebo **podle množství** přijímaných
řádků.

Algoritmus rozpouštění je čistě celočíselný (v haléřích), aby součet rozpuštěných
částí vždy přesně souhlasil se zadanou částkou nákladu:

- Podíl každého řádku se počítá jako `částka × (podíl řádku na základně) `, kde
  základnou je buď součet hodnot řádků (u „podle hodnoty"), nebo součet množství
  (u „podle množství") — výsledek se **zaokrouhlí vždy dolů** (na celé haléře).
- Rozdíl mezi zadanou částkou a součtem takto zaokrouhlených podílů (vždy nezáporný,
  typicky pár haléřů) se celý přičte k **jednomu jedinému** řádku — tomu s nejvyšší
  hodnotou; při shodě hodnot víc řádků vyhrává řádek s nižším pořadím.
- Ve výjimečném případě, kdy je základna nulová (např. všechny řádky mají nulovou
  hodnotu i množství), se náklad rozdělí rovnoměrně a případný zbytek po haléřích jde
  postupně na první řádky (ne na řádek s nejvyšší hodnotou).
- Nákladová položka s nulovou nebo zápornou částkou se přeskočí a nerozpouští se
  vůbec.

Rozpuštěná částka se u řádku uloží zvlášť jako informativní **vedlejší náklad**, ale
zvyšuje i celkovou hodnotu, ze které se počítá klouzavý průměr (viz worked example
v § 33.3.1, kde druhý příjem obsahuje dopravu 200 Kč).

## 33.5 Vazba na faktury

### 33.5.1 Automatický výdej při vystavení faktury

Na řádku [vydané faktury](15_Faktura_editor.md) můžeš vybrat **skladovou kartu** a
**sklad**, ze kterého se má zboží vydat — zobrazí se náhled dostupného množství
stejně jako v editoru skladového dokladu.

Je-li zapnutá **automatická výdejka** (§ 33.1), při vystavení běžné/finální faktury
s takto napojenými řádky systém automaticky založí a rovnou zaúčtuje výdejku
(původ „Vydaná faktura"), v jedné transakci se samotným vystavením faktury. Pokud má
faktura řádky z víc skladů, založí se samostatná výdejka pro každý sklad. Proforma
a daňový doklad k platbě skladem nehýbou — čerpá se až finální faktura. Storno
faktury (interní zrušení) automaticky stornuje i navázanou výdejku a **dobropis**
zboží vrátí zpátky na sklad **v původní pořizovací ceně** výdeje k faktuře, ke které
se dobropis vztahuje.

Datum automatické výdejky nebo vratky odpovídá **DUZP faktury**; datum vystavení se
použije jen tehdy, když doklad DUZP nemá. Záporný skladový řádek na běžné faktuře
se zpracuje jako vratka na sklad, nikoli jako další výdej. Administrátorské smazání
vystavené faktury před smazáním atomicky stornuje i všechny její automatické
skladové doklady.

Pokud na skladě není dost zboží, vystavení faktury **skončí chybou „Nedostatek
zásob pro výdej"** (HTTP 409) ještě předtím, než se spotřebuje číslo řady faktury —
faktura zůstane ve stavu koncept a dá se opravit (jiné množství, jiný sklad, nebo
napřed naskladnit). Na detailu faktury najdeš i přehled výdejek/vratek navázaných na
daný doklad.

### 33.5.2 Naskladnění z přijaté faktury

Na detailu [přijaté faktury](23_Prijate_faktury.md) je tlačítko **Naskladnit**, pokud
má alespoň jeden dosud nenaskladněný řádek navázaný na skladovou kartu. Faktura bez
jediné takové vazby tlačítko nenabízí. Akce otevře průvodce naskladněním. Průvodce
nabídne řádky faktury s vazbou na skladovou
kartu a **zbývající množství k naskladnění** — spočítá se jako fakturované množství
mínus součet množství, které už bylo naskladněno dřívějšími (zaúčtovanými) příjemkami
napojenými na tentýž řádek faktury; pokud by ses pokusil/a naskladnit víc, systém to
odmítne chybou „Množství přesahuje zbývající k příjmu z faktury" (HTTP 409, s malou
tolerancí na zaokrouhlení cca 0,0005 jednotky) — kontroluje se hned při zakládání
konceptu příjemky, ne až při zaúčtování. Pole se dá i ručně přepsat na menší množství
(částečné naskladnění), k jednotlivým řádkům lze zvolit existující kartu, napojit
jinou nebo rovnou z popisu řádku faktury **založit novou skladovou kartu**.

Volitelně lze zahrnout i **vedlejší pořizovací náklady** — kandidáti se nabídnou
automaticky: jsou to řádky **téže** přijaté faktury, které nemají napojenou žádnou
skladovou kartu (typicky položka za dopravu vedle položek zboží na jedné faktuře);
modul nehledá náklady na jiných fakturách stejného dodavatele ani podle textu popisu.
Náklady se rozpustí do ceny přijímaného zboží stejným algoritmem jako v editoru
skladového dokladu (§ 33.4.4). Po potvrzení vznikne **draft příjemka** (původ
„Přijatá faktura") ve zvoleném skladu a datu, kterou pak podle potřeby doplníš a
zaúčtuješ v modulu Skladové doklady.

Pokud se obsah přijaté faktury po dřívějším naskladnění změní (typicky přepsáním
řádků faktury, které vazbu na starou příjemku „osiří"), průvodce na to upozorní, aby
sis ověřil/a, jestli naskladněné množství pořád odpovídá aktuálnímu obsahu faktury.

## 33.6 Sklady (více skladů)

Firma může mít libovolný počet **skladů** (v kódu není žádný horní limit) — najdeš je
na stránce **E-shop**, záložka **Sklady**. Ke každému skladu zadáváš **kód**
*(povinný, max 20 znaků, musí být v rámci firmy unikátní — jinak „Sklad s tímto kódem
už existuje", HTTP 409)*, **název** *(povinný, max 100 znaků)*, poznámku a příznaky
**Výchozí sklad** a **Aktivní**. Tabulka u každého skladu ukazuje aktuální
**celkovou hodnotu** zásob na něm.

Výchozí sklad se automaticky předvyplní při zakládání nového skladového dokladu;
výchozí smí být vždy jen **jeden** sklad — nastavením nového výchozího se příznak
u předchozího výchozího skladu sám shodí. Sklad, který má nenulový stav zásob nebo
jakékoli skladové pohyby (na kterémkoli z obou směrů převodky), **nejde smazat** —
pokus o smazání vrátí chybu „Sklad nelze smazat — má nenulový stav nebo skladové
pohyby. Deaktivujte jej místo mazání." (HTTP 409) a nabídne deaktivaci místo mazání.

## 33.7 Inventury

**Sklad → Inventury** slouží k fyzické kontrole skutečného stavu zásob dle
§ 29–30 zákona o účetnictví. Založíš ji tlačítkem **Nová inventura** — zvolíš
**sklad**, **datum**, způsob zjištění skutečného stavu a osoby odpovědné za zjištění
stavu a za provedení inventury; poznámka je nepovinná.

Na daném skladu smí běžet vždy jen **jedna otevřená** inventura (ve stavu Založena
nebo Probíhá sčítání) — dokud ji neuzavřeš, další na tentýž sklad založit nejde, a to
bez ohledu na zvolené datum („Na skladu už je rozpracovaná inventura.", HTTP 409).
Nezávisle na tom je i v databázi vynucené, že na jeden sklad a den existuje nejvýš
jeden záznam inventury vůbec.

Průvodce inventurou má tři kroky (stavy `draft` → `counting` → `closed`, striktně
jednosměrné — inventuru nejde smazat ani vrátit do předchozího kroku):

1. **Založení** — inventura je ve stavu **Založena**; tlačítkem **Zahájit sčítání**
   se pořídí snapshot očekávaných stavů (množství i hodnota) **k rozhodnému datu
   inventury** replayem skladové knihy. Zahrne všechny aktivní karty firmy, i ty bez
   pohybu, a také neaktivní karty s nenulovou zásobou k danému dni. Inventura přejde do
   stavu **Probíhá sčítání**. Po dobu sčítání nelze na daném skladu zaúčtovat žádný
   jiný skladový pohyb (příjemku, výdejku ani převodku z/do něj) — pokus o to skončí
   chybou „Na skladu probíhá inventura — dokončete ji před zaúčtováním pohybu."
   (HTTP 409); totéž platí i pro storno staršího dokladu na tomto skladu.
2. **Sčítání** — u každé položky zadáš **skutečně napočítané množství**; systém
   průběžně dopočítává **rozdíl** oproti očekávanému stavu (zvýrazněný, pokud není
   nulový). Tlačítko **Napočítat vše dle očekávání** rychle předvyplní všechny
   řádky očekávanou hodnotou (pro položky, které sedí). Rozepsané počty jde průběžně
   ukládat tlačítkem **Uložit průběh**, aniž by se inventura uzavřela. U kladného
   rozdílu se zadává reprodukční pořizovací cena za jednotku; systém nabídne cenu
   očekávaného stavu nebo poslední známou cenu. Přebytek bez kladné ceny nelze uzavřít.
3. **Rekapitulace** — po uzavření (tlačítko **Uzavřít**, s potvrzovacím dialogem —
   akci nejde vzít zpět) se zobrazí jen řádky s nenulovým rozdílem. Uzavření
   v jedné databázové transakci vytvoří (podle znaménka rozdílu) jednu souhrnnou
   **rozdílovou příjemku** pro všechny přebytky a/nebo jednu souhrnnou **rozdílovou
   výdejku** pro všechna manka, obě rovnou zaúčtované, s původem „Inventura" a
   popisem „Inventurní přebytek — inventura #…" resp. „Inventurní manko — inventura
   #…". Přebytek se ocení uloženou reprodukční pořizovací cenou, manko se
   ocení standardně jako běžný výdej (klouzavým průměrem v okamžiku zaúčtování).
   Číslo dokladu čerpají ze **stejné** řady jako ruční doklady (§ 33.4.2), žádný
   zvláštní prefix pro inventuru neexistuje. Inventura přejde do stavu **Uzavřena**
   a už se nedá znovu otevřít; z rekapitulace se na vzniklé rozdílové doklady dá
   proklikat. Uzavřenou inventuru lze vytisknout jako **inventurní soupis PDF**;
   obsahuje rozhodný den, okamžik zahájení a ukončení, způsob zjištění, odpovědné
   osoby, všechny řádky a podpisové záznamy.

> [!TIP]
> Pokud v kroku sčítání jen uložíš rozpracovaný stav a odejdeš, inventura zůstává
> „Probíhá sčítání" — nezapomeň se k ní vrátit a **uzavřít** ji, jinak zůstane blokovat
> zaúčtování ostatních dokladů na daném skladu.

## 33.8 Skladové sestavy

**Sklad → Sestavy** nabízí dvě záložky:

- **Stav zásob** — aktuální množství, průměrná cena a hodnota po jednotlivých
  kartách a skladech k okamžiku zobrazení; řádky pod nastaveným minimem se zvýrazní.
  Filtr jen na sklad (bez data — je to okamžitý stav).
- **Ocenění** — stejný přehled, ale **k libovolnému historickému datu** (přepočet ze
  skladové knihy zpětně — systém přehraje všechny zaúčtované pohyby firmy do
  zadaného data znovu od nuly). Pokud má firma přes **50 000** zaúčtovaných/
  stornovaných skladových řádků, přepočet k historickému datu odmítne s hláškou
  „Příliš mnoho skladových pohybů — sestava k historickému datu se pro tak velký
  objem generuje asynchronně." (HTTP 422) — v tom případě zvol kratší období nebo
  aktuální den, kde se historický přepočet nepoužívá.

Obě sestavy mají **součtový řádek** (počet položek a celková hodnota) a jdou
exportovat do **PDF** i **XLSX**; každý export se zaznamenává do žurnálu aktivit
firmy (typ, formát, čas, uživatel).

## 33.9 Omezení a tipy

- Modul podporuje jen **způsob B** účtování zásob (průběžná evidence bez účtování,
  promítnutí do účetnictví až uzávěrkou) — způsob A není v tomto vydání funkční,
  ačkoliv je pro něj v datech (`stock_method`) i posuzovacích pravidlech místo
  připravené.
- Záporný stav zásob **nejde nijak povolit** — nedostatek se musí vždy vyřešit
  příjmem/inventurou dřív, než doklad, který ho způsobuje, půjde zaúčtovat.
- Počet skladů ani počet skladových karet není v aplikaci nijak omezen; jediný
  reálný limit je **50 000 zaúčtovaných pohybů firmy** pro okamžitý přepočet
  historického ocenění (§ 33.8) — nad tuto hranici je potřeba zvolit kratší
  období.
- Skladová karta typu **Výrobek** se při uzávěrce zaúčtuje na **MD 123 / D 583**;
  při otevření roku se počáteční stav zrcadlově rozpustí.
- Zaúčtovaný doklad se needituje — jedinou cestou zpět je **storno** (protidoklad),
  ne oprava původního dokladu; opakované kliknutí na Zaúčtovat u už zaúčtovaného
  dokladu ale chybu nehlásí (je to bezpečné proti dvojkliku).
- Zobrazení karty v e-shopu je nezávislé na jejím skladovém typu — řídí ho
  samostatný příznak **Exportovat do e-shopu** (§ 33.2.2).

> [!TIP]
> Skladovou kartu nemusíš mít vždy založenou dopředu — v průvodci **naskladněním
> z přijaté faktury** (§ 33.5.2) ji jde založit rovnou z popisu řádku faktury, bez
> nutnosti přecházet napřed na Skladové karty.
