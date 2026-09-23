# 103. Přechod z Money S3

**Cesta: `Systém → Přechod z Money S3`**

Průvodce převede účetní agendu z Money S3 do firmy v MyÚčtu. Vstupem je záloha
agendy, kterou si firma nebo účetní vytvoří v Money funkcí *Zálohovat agendu*
(soubor `.lz`). Záloha se jen čte, instalace Money se převodem nijak nemění.

Položka je v menu Systém, které vidí administrátor. Jiný uživatel s potřebnými
oprávněními otevře průvodce přímým odkazem `/imports/money-s3`.

### 103.1.1 Co je soubor zálohy agendy

- Záloha agendy je jeden soubor s příponou `.lz`, který Money S3 vytvoří
  funkcí *Zálohovat agendu*. Jméno obsahuje IČO firmy a číslo agendy, například
  `12345678ag001.lz`.
- Uvnitř je obyčejný ZIP s datovými soubory agendy (`*.DAT`), rozdělenými po
  účetních rocích, a se základními údaji o agendě (název, IČO, verze Money).
  Jedna záloha obsahuje všechny roky agendy.
- Soubor nahrajte tak, jak ho Money vytvořilo, nerozbalený. Přijímá se i stejný
  soubor s příponou `.zip`.
- Záloha může mít až 4 GB. Průvodce ji posílá po částech a ukazuje průběh
  v procentech; při výpadku spojení část zopakuje a naváže tam, kde server
  data má. Stránku nechte během nahrávání otevřenou.
- Elektronický archiv dokumentů z Money záloha nepřenese (Money ho ukládá
  šifrovaně). Skeny dokladů připojíte zvlášť v `Dokumenty → Skeny k dokladům`.
- Záloha obsahuje celé účetnictví firmy. Nahraný soubor aplikace po 7 dnech bez
  práce s převodem sama smaže.

Průvodce vidí a zkoušku nanečisto spouští uživatel s oprávněním
`utilities.import` pro zápis. Ostrý převod zapisuje účetní deník, mění nastavení
firmy a uzavírá roky, proto navíc vyžaduje zápis do účetního deníku
(`accounting.journal.write`) a do nastavení firmy (`settings.company.write`),
s uzávěrkou historických let i uzavírání období (`accounting.periods.close`).
Chybějící oprávnění průvodce ukáže a převod nespustí.

Průvodce je dostupný i firmě, která zatím vede daňovou evidenci: převod ji sám
přepne do podvojného účetnictví.

## 103.2 Co převod přenese

| Z Money | Do MyÚčta |
|---|---|
| účtový rozvrh (jen účty, na které se účtovalo) | analytiky pod syntetiky osnovy, `042000` → `042.000` |
| účetní roky | účetní období |
| účetní deník včetně počátečních stavů | účetní zápisy, počáteční stavy jako otevírací zápis k 1. dni období |
| středisko na řádku deníku | dimenze Středisko a středisko na řádku zápisu, viz níže |
| zakázka na řádku deníku | dimenze Projekt nebo Vozidlo na řádku zápisu a v hlavičce dokladu, viz níže |
| adresář a bankovní spojení partnerů | klienti a jejich bankovní účty |
| předkontace | pravidla zaúčtování se zkratkou z Money |
| přijaté a vydané faktury | doklady se stavem zaúčtováno nebo uhrazeno, položka na každou sazbu DPH; doklad nejisté daňové povahy jako koncept k ruční kontrole |
| pokladny a pokladní doklady | pokladny a zaúčtované pokladní doklady |
| bankovní účty a bankovní doklady | výpis na účet a rok se zdrojem „import", bankovní pohyby |
| úhrady faktur | spárování faktury s bankovním pohybem nebo pokladním dokladem |
| karty dlouhodobého majetku a jejich pohyby | karty majetku s počátečními stavy, technickými zhodnoceními a odpisy převedených let, viz níže |
| karty drobného majetku | evidence drobného majetku včetně vyřazených karet |

### 103.2.1 Majetek

Převod přebírá evidenci majetku Money (karty a jejich pohyby). Zařazení, odpisy
i vyřazení jsou už v převedeném deníku, karta proto vzniká bez zápisu v deníku.

- **Karta dlouhodobého majetku** přebírá název, inventární číslo, datum zařazení,
  majetkový a oprávkový účet a daňovou skupinu a způsob odpisu (Z zrychlený,
  N rovnoměrný). Vstupní cena je cena na začátku prvního převáděného roku,
  pozdější zvýšení ceny a technická zhodnocení jsou technická zhodnocení karty.
- **Účetní odpisy** před prvním převáděným rokem jsou počáteční stav karty,
  odpisy převedených let se zapíší přesně podle Money jako zaúčtované převzatým
  deníkem. Hromadné účtování odpisů je znovu neúčtuje a plán naváže dalším měsícem.
- **Daňové odpisy** hmotného majetku Money na kartách neukládá, počítá je
  z parametrů karty. Převod je stejně spočte podle zákona o daních z příjmů
  a uzavřené roky zapíše jako potvrzené. Otevřený rok dostane daňový odpis až
  uzávěrkou. Nehmotný majetek se daňově odpisuje podle účetních odpisů.
- **Neodpisovaný majetek** (pozemky, skupina N) se převede bez odpisů.
- **Vyřazená karta** z převedených let se převede jako vyřazená. Karta vyřazená
  před prvním převáděným rokem se nepřevádí.
- **Pomocná karta** bez majetkového účtu (Money na ní počítá například daňové
  odpisy k majetku vedenému jinde) se nepřevádí, protokol ji vypíše. Karta
  „jen ÚČETNÍ odpis" se převede jen s účetními odpisy.
- **Snížení ceny** (dotace, dobropis) z převedených let se zapíše jako záporné
  technické zhodnocení a karta zůstane konceptem ke kontrole daňové vstupní ceny.
- **Drobný majetek** přebírá název, inventární číslo, datum pořízení, cenu,
  dodavatele a umístění, vyřazené karty s datem vyřazení.

Na konci kroku převod porovná karty se zůstatky majetkových a oprávkových účtů
po syntetikách. Rozdíl, který je už v evidenci Money (majetek účtovaný bez karty),
protokol označí zvlášť.

### 103.2.2 Mzdy

Zápisy mezd jsou v převedeném deníku a znovu nevznikají. Z mzdových dokladů
(závazky a interní doklady mzdového modulu Money) převod v kroku **Mzdy** udělá
tři věci:

- **Návrh kontací mezd.** Z mzdových zápisů posledního převáděného roku odvodí,
  které účty firma používá pro hrubé mzdy, pojistné, daň a srážky, a uloží je jako
  návrh v Mzdy → Importy → **Kontace mezd** (viz
  [§ 108.11](108_Prechod_z_PAMICA.md#10811-kontace-mezd-z-puvodniho-programu)).
  Nastavení mezd se nemění, dokud návrh nepotvrdíte. Význam zápisu se bere
  z druhu mzdového dokladu, který Money u novějších dokladů vede (sociální,
  zdravotní pojištění, daň, srážky…); starší doklady bez druhu se zařadí podle
  páru účtů. Analytiku účtu 336 pro sociální a zdravotní pojištění pozná převod
  podle dokladů s druhem, jinak podle názvu účtu. Co zařadit nejde, je v návrhu
  jen v přehledu.
- **Kontrolní úhrny po měsících.** Protokol ukáže za každý měsíc mezd součty
  celé firmy: hrubé mzdy, pojistné zaměstnanců a zaměstnavatele, zálohovou
  a srážkovou daň, srážky a čistou mzdu k výplatě. Zálohovou daň porovná
  s měsíčním vyúčtováním daně z příjmů ze závislé činnosti, které Money vede
  zvlášť (sražené zálohy po přeplatcích z ročního zúčtování); měsíc, kde nesedí,
  vyznačí. Úhrny jsou kontrola, ne převzaté mzdy jednotlivých zaměstnanců.
- **Zapnutí modulu Mzdy.** Firmě, která mzdy vede, převod zapne modul Mzdy
  a nastaví začátek vedení mezd v MyÚčtu na měsíc po posledním mzdovém dokladu.
  Chybí-li nastavení zaměstnavatele, založí ho s mzdovou účtárnou `MZDY`
  a výchozími předkontacemi. Variabilní symbol ČSSZ, kód OSSZ a účty institucí
  převod nevymýšlí, protokol je vypíše k doplnění v Mzdy → Nastavení. Zapnutý
  modul, jeho začátek ani existující nastavení převod nemění. Firmě, jejíž mzdy
  skončily víc než rok před koncem převáděných dat, se modul nezapíná.

**Zaměstnanci se nepřevádějí.** Novější verze Money vedou osoby a mzdy
jednotlivých zaměstnanců v šifrované databázi agendy, kterou převod přečíst
nemůže. Starší čitelné tabulky mzdového modulu v záloze (u agend vedených dlouho
končí typicky rokem 2020) převod také nepřebírá: historie osob se nepřevádí.
Aktuální zaměstnance převezměte importem přijatých podání JMHZ a registrací
v Mzdy → Importy, viz [§ 90.9.1](90_Nastaveni_mezd.md#9091-jmhz-registrace-a-mesicni-hlaseni).

**Zaúčtování se nepřepočítává.** Deník je přesná kopie toho, co bylo v Money,
a doklady se k němu jen připojí. Z dokladu je proto vidět jeho zápis a naopak,
detail faktury ukazuje úhradu jako zaúčtovanou a automatika už doklad znovu
nezaúčtuje.

**Střediska a zakázky jako dimenze.** Převod u firmy zapne
[Dimenze](110_Dimenze.md) a středisko i zakázku z deníku Money převede na
hodnoty dimenzí. Doklad dostane hodnotu do hlavičky, když všechny jeho řádky
s daným typem nesou tutéž; hlavičku, kterou už někdo vyplnil, převod nemění.

- Středisko z Money je hodnota firemní dimenze **Středisko**. Založí se i
  v `Nástroje → Střediska` a kód střediska zůstane na řádcích zápisů.
- Zakázka ve tvaru registrační značky (`1AB 2345`, `3CD4567`, `EL123AB`) je
  hodnota firemní dimenze **Vozidlo**. Je-li vůz v knize jízd, hodnota se na
  něj naváže a nese jeho název. Různé zápisy téže značky (`5E6 7890`, `5E67890`)
  se sloučí do jedné hodnoty.
- Ostatní zakázky jsou hodnoty dimenze **Projekt**. Patří-li firma do skupiny
  firem, je Projekt globální: stejný kód zakázky v mateřské firmě i v SPV je
  jeden projekt a výsledovka po dimenzi ho sečte přes všechny firmy skupiny.
  Skupinu je proto dobré založit před převodem.
- Hodnota, kterou poslední převáděný rok nepoužil, se založí jako uzavřená.
  Hodnotu, kterou poslední rok použil, převod znovu otevře.
- Fakturační zakázky (`Zakázky`) ani klienty převod ze zakázek Money nezakládá.
- Činnost z Money se nepřevádí.

Dimenze dostanou i zápisy z let, která převod uzavřel už dříve; obraty ani výkazy
se tím nemění. U řádků převzatých z Money platí Money: opakovaný převod přepíše
středisko, projekt nebo vozidlo, které jsi u nich změnil ručně.

**Popisy zápisů se dogenerují.** Money veze v řádku deníku jen pole `Popis`, které
je u celé řady dokladů shodné. Po navázání dokladů proto převod popisy přeskládá do
tvaru **doklad — protistrana — obsah**, aby se zápisy v deníku daly rozlišit; v protokolu
to uvidíš jako *„U N převedených zápisů se popis doplnil o číslo dokladu a protistranu."*
Částek, účtů ani dat se to nedotýká a jde to kdykoli zopakovat — viz
[§ 52.12.1](52_Ucetni_denik.md#52121-dogenerovani-popisu-u-prevzatych-zapisu).

**Doklady k ruční kontrole.** Fakturu, jejíž daňovou povahu záloha Money
spolehlivě neurčuje, převod převezme jako koncept:

- zálohovou fakturu, proformu a daňový doklad k platbě (jiný druh než běžná faktura),
- dobropis, stornovaný doklad a doklad, který je v Money označený „neúčtovat",
- doklad s členěním DPH mimo tuzemské řádky přiznání: přenesená daňová
  povinnost, plnění z EU a do EU, zvláštní režimy, nebo doklad s daní bez členění.

Koncept nevstoupí do přiznání k DPH, kontrolního hlášení ani do účtování.
Protokol ho vypíše i s důvodem. Po opravě druhu dokladu nebo klasifikace
DPH ho potvrďte.

**Doklad v cizí měně** koncept není. Převezme se v měně a kurzu dokladu,
když základ a daň po sazbách v měně přepočtené kurzem dávají na haléř koruny,
které Money vykázalo v přiznání; DPH, kontrolní hlášení i deník tak zůstávají
v Kč přesně stejné. Jinak (a u samovyměření nebo měny, kterou firma nemá
v číselníku měn) se převezme v Kč a poznámka dokladu i protokol uvedou důvod. Přijatá faktura s členěním „do přiznání nezahrnovat" se
převezme bez nároku na odpočet.

Číslo dokladu, které už ve firmě je, dostane příponu roku, například
`FP001/2025`. Money čísluje řady každý rok od začátku, takže stejné číslo
v dalším roce je běžné. Stejně se rozliší doklady se stejným číslem na dvou
bankovních účtech nebo ve dvou pokladnách.

## 103.3 Co převod nepřenese

- **Přílohy a elektronický archiv.** Money je drží v šifrovaných souborech,
  které ze zálohy číst nejde. Skeny dokladů se připojují zvlášť.
- **Zaměstnanci, mzdy osob a sklad.** Zápisy mezd a zásob jsou v převedeném
  deníku; zaměstnance převezmete z podání JMHZ (viz 103.2.2), zásoby se zakládají
  v MyÚčtu.
- **Interní doklady, kniha pohledávek a závazků.** V deníku jsou jako ruční
  zápisy s původním číslem dokladu, samostatný doklad z nich nevzniká.
- **Číselné řady a řádky DPH pokladních dokladů.** Podaná přiznání k DPH za
  převáděné roky zůstávají v Money.

## 103.4 Postup

1. **Záloha agendy.** Nahrajte soubor `.lz`. Průvodce ho rozbalí (jen datové
   soubory agendy) a načte. Rozbalení a načtení běží na serveru na pozadí,
   u velké zálohy i několik minut; obnovení stránky mezitím průvodce nepřeruší.
2. **Náhled a volby.** Zkontrolujte firmu, IČO, verzi Money a účetní roky.
   Kontrola před převodem zastaví převod, když:
   - záloha patří firmě s jiným IČO,
   - účetní období v MyÚčtu už obsahuje zápisy, které nevznikly převodem,
   - období je v MyÚčtu uzavřené,
   - agenda vede hospodářský rok odlišný od kalendářního.

   Chybí-li IČO v záloze nebo ve firmě, nejde ověřit, že agenda patří této
   firmě. Zkouška nanečisto jen upozorní, ostrý převod se spustí až po
   výslovném potvrzení.

   Volby:
   - *Uzavřít historické roky* — viz 83a.5, ve výchozím stavu zapnuto.
   - *Začátek prvního účetního období* — jen u firmy založené během prvního
     převáděného roku. Bez něj začíná první období 1. 1., u roku bez počátečních
     stavů prvním zápisem deníku.
   - *Sestavy z Money* — ke každému roku můžete nahrát obratovou předvahu
     vyexportovanou z Money do CSV (účet v prvním sloupci, dál PS MD, PS D,
     obrat MD, obrat D, KS MD, KS D). Rekonciliace ji porovná s předvahou MyÚčta.
3. **Zkouška nanečisto.** Proběhne celý převod včetně uzávěrky a rekonciliace,
   na konci se ale všechno vrátí. Výsledkem je protokol; v MyÚčtu nic nezůstane
   a nastavení automatiky se nezmění. Zkouška běží v jedné databázové transakci
   a po celou dobu drží zámky převáděných účetních období: spouštějte ji mimo
   běžnou práci ve firmě, zápisy do těchto období do jejího konce počkají.
4. **Ostrý převod.** Po potvrzení běží na pozadí, stránku můžete zavřít. Po
   dokončení průvodce nabídne účetní deník a obratovou předvahu. Převod jedné
   firmy běží vždy jen jeden, druhý se do jeho konce nespustí.

### 103.4.1 Navázání na existující číselnou řadu

Převod přenáší doklady s čísly, která měly v Money S3, ale počítadlo nové řady
tím sám nenastaví. Číslo, kterým má řada v MyÚčtu pokračovat, zadejte
v Nastavení → Doklady → **Číslování faktur** do pole **Příští číslo** u příslušné
šablony a potvrďte tlačítkem *Nastavit počítadlo*. Ukládá se samostatně, mimo
tlačítko *Uložit*, a po potvrzení ukáže náhled výsledného čísla.

Vlastní řadu může mít i jednotlivý zákazník nebo kategorie tržby; pole *Příští
číslo* je pak u jejich šablony. U zděděné šablony se pole nenabízí, protože se
čísluje řadou dodavatele a počítadlo je společné.

> ⚠️ Zkontrolujte, že **perioda resetu sedí se šablonou**: u masky bez `{MM}`
> a měsíčního resetu by počítadlo prvního dne dalšího měsíce spadlo zpátky na
> začátek a čísla by kolidovala. Podrobně viz
> [§ 95.5.3](95_Multi_supplier.md#9553-cislovani-faktur).

> 🛈 Sestava *Úplnost číselné řady* začne řadu počítat až od nastaveného čísla,
> takže začátek řady na vyšším čísle nehlásí jako chybějící doklady.

## 103.5 Rekonciliace a protokol

Každý běh (zkouška i převod) končí protokolem. Najdete v něm kroky převodu
s počty, upozornění a chyby a pro každý rok rekonciliaci:

- obratová předvaha MyÚčta proti předvaze spočtené přímo z deníku Money
  v záloze, po syntetických účtech, počáteční stav, obrat a konečný stav na haléř,
- obratová předvaha proti sestavě z Money, pokud jste ji nahráli,
- obraty MD = D, předvaha = deník, vyrovnané počáteční stavy, žádné rozpracované zápisy,
- doklady proti deníku: přijaté faktury proti 321, vydané proti 311, pokladna
  proti 211 a banka proti 221.

Doklad, ke kterému v deníku Money není zápis se stejným číslem, protokol vypíše
jako doklad bez zápisu. Takový doklad není zaúčtovaný. Zaúčtujte ho ručně nebo
hromadně v Účetnictví → Doúčtovat doklady. Koncepty k ruční kontrole se mezi
doklady bez zápisu nepočítají.

Úhradu faktury páruje převod podle čísla dokladu úhrady, které Money
u faktury drží. Číslo se v Money každý rok opakuje, takže mezi pohyby se
stejným číslem rozhoduje shoda částky, pak datum úhrady a nakonec rok. Dva
stejně dobré kandidáty převod nespáruje a protokol je vypíše k ručnímu
spárování. Spárovaná faktura dostane stav uhrazeno.

Protokoly všech běhů zůstávají v přehledu pod průvodcem. Protokol zkoušky
nanečisto z přehledu smažete, protokol ostrého převodu zůstává.

## 103.6 Uzávěrka historických let

Money převáděné roky uzavřelo, převod je ale naveze otevřené. Průvodce je pak
uzavře průvodcem uzávěrkou MyÚčta od nejstaršího roku, a jen tehdy, když konečné
stavy roku sedí účet po účtu na počáteční stavy dalšího roku z Money včetně
výsledku hospodaření na 431. Při rozdílu rok zůstane otevřený a pozdější roky
také.

Kroky, které proběhly v Money (odpisy, dohadné položky, časové rozlišení,
rezervy, daň z příjmů), se přeskočí s poznámkou, protože jejich zápisy jsou
v deníku. Kurzové přecenění a zásoby se nepřeskakují: má-li rok co přeceňovat,
uzavřete ho ručně v Uzávěrce (kapitola [Uzávěrka](72_Uzaverka.md)).

Otevření dalšího roku převzaté počáteční stavy z Money ponechá a nic nového
nezaúčtuje, takže se počáteční stavy nezdvojí. Poslední převedený rok zůstává
otevřený. Rok, jehož knihy se uzavřely, ale další rok se otevřít nepodařilo,
opakovaný převod dotáhne.

## 103.7 Režim účetnictví a automatika

Převod zapíše podvojné účetnictví od začátku prvního převáděného období do
nastavení firmy i do historie režimů. Automatika účtování je během převodu
vypnutá: deník přichází hotový a každý automatický zápis nad týmiž doklady by
byl duplicita.

Po úspěšném převodu se automatika vrátí do stavu před převodem. Firma, která
podvojné účetnictví zapíná právě převodem, dostane výchozí nastavení účetní
jednotky jako po aktivaci. Skončí-li převod chybou, automatika zůstane vypnutá,
dokud převod nedoběhne bez chyb. Stav před převodem se ukládá ještě před
vypnutím automatiky, takže ani převod přerušený pádem ji nenechá vypnutou
natrvalo: další úspěšný běh ji vrátí na stav před prvním převodem.

Záznam daňové evidence, který v historii režimů firmy leží uvnitř převáděných
let, převod odstraní, protože v Money byly tyto roky podvojné.

## 103.8 Opakovaný převod

Převod si pamatuje, co z které agendy už vzniklo. Opakovaný převod téže nebo
novější zálohy založí jen to, co ještě chybí, a nic nezdvojí. Převod přerušený
chybou tak stačí po opravě spustit znovu. Do uzavřeného roku už převod nic
nepřidá.

Už převedené doklady ani zápisy deníku opakovaný převod nepřepisuje, protože
mohly být mezitím zaúčtované, spárované nebo upravené v MyÚčtu. Změnila-li se
v Money celková částka faktury, protokol ji vypíše jako změněnou v Money
a ponechanou v MyÚčtu; upravte ji ručně.

Převádíte-li firmu znovu od začátku (firmu smažete a převedete znovu), ztratí
se s ní i nastavení, které převod nezakládá: výjimky mapování výkazů, volby
výkazů a uzávěrky, daňový profil, dimenze, předkontace a pravidla banky. Před
smazáním proto v průvodci (nebo v Nastavení) stáhněte **profil firmy** a po
ostrém převodu ho nahrajte zpět. Výkazy pak vyjdou stejně jako před smazáním.
Popis profilu je v [§ 96.18](96_Nastaveni.md#9618-profil-firmy).

## 103.9 Dávkový převod více firem

Účetní kancelář nebo skupina firem převede víc agend najednou v záložce
**Dávka více firem** nahoře v průvodci. Dávka dělá u každé firmy totéž co
průvodce jedné firmy (stejný převod, stejný protokol u firmy) a navíc firmu
najde nebo založí.

- **Zálohy.** Vyberte zálohy `.lz` všech firem najednou. Nahrávají se po jedné,
  server každou po nahrání přečte a ukáže IČO, název, roky, doporučený rok „od"
  a firmu v MyÚčtu, do které se převede. Z více záloh téže firmy platí nejnovější
  podle data zálohy. Nahrané zálohy zůstávají týden; zálohu úspěšně převedené
  firmy dávka smaže.
- **Firma podle IČO.** Firma se stejným IČO, ke které máte přístup, se převede
  (nebo přeskočí, podle volby *Firma už v MyÚčtu je*). Chybějící firmu dávka
  založí stejně jako zakládání další firmy v aplikaci: název, sídlo a DIČ ze
  zálohy, co v ní chybí, z posledního podaného přiznání k DPPO a z ARES.
  Plátcovství DPH ověří registr plátců; bez něj rozhodnou obraty na účtu 343
  a protokol vyzve k ověření. Zakládat firmy smí jen uživatel s oprávněním
  zakládat firmy. Firmu, ke které přístup nemáte, dávka nepřevede a vypíše ji
  jako chybu. E-mail založené firmy doplňte v nastavení firmy.
- **Rok „od" automaticky.** U každé firmy začne převod prvním rokem, od kterého
  v Money navazují konečné a počáteční stavy (viz 103.6). Starší roky zůstanou
  v archivu Money. Volbou *Všechny roky* převedete celou zálohu.
- **Podaná přiznání k DPPO (volitelné).** Přiložte EPO XML podaných přiznání
  (DPPDP9). Přiřadí se podle IČO, za každý rok platí poslední podání (dodatečné
  před opravným před řádným). Zakládaná firma z nich dostane NACE, kategorii
  účetní jednotky, audit a začátek prvního účetního období u firmy vzniklé
  během roku. Po ostrém převodu dávka přiznání převezme do Daní a do evidence
  daňových ztrát stejným převzetím jako `api/bin/tax-return-import.php`; existující
  rozpracované přiznání nepřepíše a finální nikdy nemění.
- **Skupina firem.** Firmy lze zařadit do skupiny aktuální firmy nebo do nové
  skupiny. Zařazení proběhne před převodem, takže zakázky Money se převedou jako
  globální projekty skupiny (viz 103.2). Volba *Firmy dávky jsou spřízněné osoby*
  označí partnery s IČO jiné firmy dávky nebo skupiny jako spřízněné osoby; pro
  nezávislé klienty kanceláře ji nezapínejte.

Zkouška nanečisto založí firmy i celý převod v transakci, která se na konci
vrátí: nezůstane ani firma, ani protokol u firmy, výsledek je jen v protokolu
dávky i s podrobným protokolem každé firmy.

Dávka běží jako jeden úkol na pozadí se společným průběhem. Firmy se převádějí
po jedné a pád jedné firmy ostatní nezastaví. Zrušení platí od další firmy,
u ostrého převodu i uvnitř právě převáděné firmy.

**Protokol dávky** ukazuje u každé firmy, zda se založila, převedla do
existující, nebo přeskočila, rok „od", stav, převzatá přiznání, upozornění
a kontroly:

| Kontrola | Co ověřuje |
|---|---|
| K1 | obratová předvaha proti deníku Money (a proti sestavě z Money) na haléř |
| K2 | obraty MD = D, předvaha = deník, vyrovnané počáteční stavy, žádné koncepty |
| K3 | rozvaha vychází a žádný účet ve výkazech nechybí |
| K4 | doklady po knihách proti zápisům na 321, 311, 211 a 221 |

Podrobný protokol ostrého převodu je u firmy v záložce *Jedna firma* (po
přepnutí do firmy). Předchozí dávky zůstávají v přehledu pod průvodcem.

**Opakování.** Dávku jde spustit znovu se stejnými nebo novějšími zálohami.
S volbou *Převést znovu* se do existujících firem doplní jen to, co chybí
(viz 103.8), nic se nezdvojí; firma, která minule selhala, se převede znovu.
Profil nastavení existující firmy si dávka před převodem odloží a po úspěšném
převodu ho obnoví, takže ho při opakování není potřeba stahovat ručně.

Správce instalace může dávku spustit i z příkazové řádky nad adresářem
záloh:

```
php api/bin/money-s3-batch.php --dir=<adresář se zálohami> --list
php api/bin/money-s3-batch.php --dir=<adresář se zálohami> --all --dry-run
php api/bin/money-s3-batch.php --dir=<adresář se zálohami> --all --dppo-dir=<adresář s XML> --report=souhrn.json
```

Volby odpovídají průvodci (`--from-year=auto|RRRR|all`, `--existing=skip|update`,
`--group="Název"`, `--related-parties`, `--no-close`, `--no-registry`), na konci
je souhrn po firmách s K1 až K4.

Účtuje-li firma po převodu ještě nějaký čas i v Money, porovnávejte každý měsíc
MyÚčto s výstupy Money nebo s novou zálohou agendy na stránce
`Účetnictví → Souběh se starým systémem` (kapitola
[Souběh se starým systémem](111_Soubeh_se_starym_systemem.md)). Záloha se tam
čte bez zápisu do MyÚčta.

## 103.10 Omezení

- Formát dat Money není veřejně dokumentovaný. Čtení je ověřené na verzi
  Money S3 26.600; u jiné verze průvodce upozorní a výsledek je o to důležitější
  porovnat se sestavami z Money.
- Hospodářský rok odlišný od kalendářního převod nepodporuje, kontrola před
  převodem ho zastaví.
- Převod čte jen zálohu agendy, nikdy živou instalaci Money. Z archivu rozbalí
  jen datové soubory agendy s omezeným počtem i velikostí.
- Nahraná záloha zůstává na serveru pro další běh. Po úspěšném ostrém převodu
  se smaže, jinak ji denní úklid smaže po týdnu bez práce s ní.
