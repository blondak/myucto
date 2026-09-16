# 107. Přechod z POHODY a PAMICA

**Cesta: `Systém → Přechod z POHODA a PAMICA`**

Průvodce převede účetní rok z programu POHODA do firmy v MyÚčtu. Vstupem je XML
export agendy, který vytvoří exportní nástroj stažený přímo z průvodce. Nástroj
data z POHODY jen čte, v POHODĚ nic nemění.

Položka je v menu Systém, které vidí administrátor. Jiný uživatel s potřebnými
oprávněními otevře průvodce přímým odkazem `/imports/pohoda`.

Průvodce vidí a zkoušku nanečisto spouští uživatel s oprávněním
`utilities.import` pro zápis. Ostrý převod zapisuje účetní deník a mění
nastavení firmy, proto navíc vyžaduje zápis do účetního deníku
(`accounting.journal.write`) a do nastavení firmy (`settings.company.write`).
Chybějící oprávnění průvodce ukáže a převod nespustí.

Průvodce je dostupný i firmě, která zatím vede daňovou evidenci: převod ji sám
přepne do podvojného účetnictví od začátku převáděného roku.

## 107.1 Export z POHODY

### 107.1.1 Exportní nástroj

V prvním kroku průvodce ukáže tlačítkem *Zobrazit exportní nástroj* soubory
nástroje:

| Soubor | K čemu slouží |
|---|---|
| `Export-Pohoda.cmd` | spouštěč, který se otevírá dvojklikem |
| `Export-Pohoda.ps1` | vlastní exportní skript |
| `Export-PohodaMdb.cmd`, `Export-PohodaMdb.ps1` | majetek a mzdy z datového souboru POHODY (XML export je neobsahuje) |

Stáhněte každý soubor zvlášť, nebo všechny najednou tlačítkem *Stáhnout vše (ZIP)*
jako `pohoda-export.zip`. Všechny soubory musí ležet ve stejné složce.

Nástroj zkopírujte na počítač s nainstalovanou POHODOU. Běží na Windows
v PowerShellu 5.1, který je součástí Windows 10 a 11. POHODU spouští v režimu
XML komunikace z příkazového řádku.

### 107.1.2 Vytvoření exportu

1. Spusťte `Export-Pohoda.cmd` dvojklikem, nebo z příkazového řádku s parametry:

   ```
   Export-Pohoda.cmd -Uzivatel Admin -Rok 2026 -Ico 12345678
   ```

2. Zadejte heslo uživatele POHODY. Uživatel musí mít právo na XML
   import/export, nejjednodušší je Admin.
3. Počkejte na dokončení. Okno ukazuje průběh po agendách, u velké agendy
   export trvá desítky minut.
4. Vedle skriptu vznikne soubor `pohoda_export_<datum>.zip`. Ten nahrajte do
   průvodce beze změny, nerozbalený.

| Parametr | Význam |
|---|---|
| `-Uzivatel` | uživatel POHODY s právem na XML import/export |
| `-Rok` | účetní rok, případně víc roků; bez něj se vyexportují všechny roky |
| `-Ico` | IČO firmy; nutné, když POHODA vede víc firem |
| `-Heslo` | heslo uživatele; bez něj se na něj nástroj zeptá |

Export jen čte. Do POHODY nic nezapisuje a nic v ní nemění.

### 107.1.3 Co je v exportu

- POHODA vede každý účetní rok samostatně a export obsahuje jednu agendu za
  každou kombinaci IČO a roku. Převádí se vždy jeden rok, doporučujeme
  nejnovější. Starší roky zůstávají v POHODĚ.
- Majetek a mzdy XML export POHODY neobsahuje. Nástroj je čte přímo
  z datového souboru a přidá je do exportu jako `90_majetek.xml`
  a `91_mzdy.xml`, viz 107.1.4.
- ZIP může obsahovat podsložky. Průvodce v nich najde XML soubory všech agend
  a k převodu nabídne jen ty, které podle IČO patří firmě v MyÚčtu. Agendy
  jiných firem ukáže jen pro informaci.
- Firma musí v MyÚčtu existovat a mít vyplněné stejné IČO jako v POHODĚ.
  Převádí se do firmy, ve které právě pracujete; novou firmu nejdřív založte
  (kapitola [Multi supplier](95_Multi_supplier.md)).
- Export obsahuje celé účetnictví firmy. Nahraný soubor aplikace po 7 dnech
  bez práce s převodem sama smaže, po úspěšném ostrém převodu hned.
- Soubor může mít až 2 GB. Průvodce ho posílá po částech a ukazuje průběh
  v procentech; při výpadku spojení část zopakuje a naváže tam, kde server
  data má. Stránku nechte během nahrávání otevřenou.

### 107.1.4 Majetek a mzdy z datového souboru

XML rozhraní POHODY nevrací dlouhodobý majetek, drobný majetek ani mzdy. Čte je
skript `Export-PohodaMdb.ps1` přímo z datového souboru POHODY (`.mdb`), u POHODA
SQL z databáze agendy. Data jen čte, nic v nich nemění.

| Soubor | Obsah |
|---|---|
| `90_majetek.xml` | karty dlouhodobého majetku, daňové odpisy po letech, účetní odpisy po měsících, drobný majetek a jeho zdrojové doklady |
| `91_mzdy.xml` | zaměstnanci, pracovní poměry, zpracované mzdy a číselníky mezd |

**Při běžném exportu nemusíte dělat nic.** `Export-Pohoda.cmd` skript spustí
sám po každé agendě a soubory přidá do její složky v ZIP. Datový soubor najde
ve složce dat POHODY; když leží jinde, zadejte ji parametrem `-DataDir`.
U POHODA SQL se připojí k instanci `.\POHODA`, jinou určí `-SqlServer`.
Když datový soubor nenajde, export doběhne a souhrn to uvede. Parametrem
`-BezMajetkuAMezd` se tento krok vynechá.

**Samostatně** skript spusťte, když máte jen datový soubor, nebo když mzdy
vede jiný program (PAMICA):

```
Export-PohodaMdb.cmd -Mdb "C:\...\StwPh_12345678_2026.mdb" -Vystup .\pohoda_export\12345678_2026
Export-PohodaMdb.cmd -Mdb "D:\PAMICA\Data\Mzdy.mdb" -Skupiny mzdy -Vystup .\12345678_2026
```

| Parametr | Význam |
|---|---|
| `-Mdb` | datový soubor POHODY nebo PAMICA |
| `-SqlServer`, `-Databaze` | místo `-Mdb` databáze POHODA SQL (`StwPh_<IČO>_<rok>`) |
| `-Vystup` | složka agendy pojmenovaná `<IČO>_<rok>`; podle ní průvodce pozná firmu a rok |
| `-Skupiny` | `majetek`, `mzdy` nebo obojí (výchozí) |

Složku `<IČO>_<rok>` pak zabalte do ZIP (samotnou, nebo spolu s XML exportem
agendy) a nahrajte do průvodce. Soubor vznikne, jen když v datovém souboru
něco je: majetek, jen když jsou karty, mzdy, jen když jsou zaměstnanci nebo
mzdy. Systémové údaje (kdo a kdy záznam změnil) skript nevytahuje.

Pro `.mdb` je potřeba ovladač Microsoft Access Database Engine, který se
instaluje s POHODOU. Když ho 64bitový PowerShell nenajde, skript se sám spustí
v 32bitovém.

## 107.2 Co převod přenese

| Z POHODY | Do MyÚčta |
|---|---|
| účtová osnova (jen účty, na které se účtovalo) | analytiky pod syntetiky osnovy |
| účetní rok | účetní období 1. 1. až 31. 12. |
| účetní deník včetně počátečních stavů | účetní zápisy, počáteční stavy jako otevírací zápis k 1. dni období |
| adresář | klienti, párování podle IČO |
| předkontace | pravidla zaúčtování se zkratkou z POHODY |
| přijaté a vydané faktury | doklady se stavem zaúčtováno nebo uhrazeno; doklad nejisté daňové povahy jako koncept k ruční kontrole |
| interní daňové doklady | daňové doklady k platbě a samovyměření DPH u přijatých faktur |
| pokladny a pokladní doklady | pokladny a zaúčtované pokladní doklady |
| bankovní účty a bankovní doklady | výpisy podle čísla výpisu v POHODĚ, bankovní pohyby |
| likvidace faktur | spárování faktury s bankovním pohybem nebo pokladním dokladem |
| karty dlouhodobého majetku (`90_majetek.xml`) | karty zařazené do užívání s počátečními stavy daňových a účetních odpisů |
| drobný majetek (`90_majetek.xml`) | karty evidence drobného majetku navázané na zdrojový doklad |

**Majetek.** Karta vznikne jako zařazená, bez zápisu v deníku: zařazení
i dosavadní odpisy už v převedeném deníku jsou. Daňové odpisy za roky před
převáděným rokem se převezmou jako počáteční stav. Účetní odpisy navážou na
poslední měsíc, který POHODA zaúčtovala; plán skončí ve stejném měsíci jako
v POHODĚ a odpis roku v MyÚčtu je jen za zbytek roku. Majetkový účet se
určí z počátečního stavu účtu 01x až 03x ve výši vstupní ceny, účet oprávek
z odpisových zápisů karty. Kartu, u které něco z toho nejde spolehlivě určit
(neznámý typ majetku nebo odpisu, jiná daňová vstupní cena, chybějící plán,
účet), převod založí jako koncept a protokol uvede důvod. Majetek vyřazený
před převáděným rokem se nepřevádí.

**Drobný majetek.** Karty operativní evidence POHODY se převedou do evidence
drobného majetku včetně množství, ceny, umístění a vyřazení. Nic se
neúčtuje, náklad je v převedeném deníku. Zdrojový doklad karty dohledá už
exportní nástroj; převod pak kartu naváže na převedenou přijatou fakturu
a její položku (podle textu, jinak podle částky), na pokladní nebo interní
doklad. Číslo zdrojového dokladu zůstane na kartě i tehdy, když doklad
v převáděném roce není. Kartu, u které POHODA odkaz na doklad nevede (to je
častý případ), převod naváže na položku přijaté faktury se stejným datem
vystavení nebo DUZP a stejnou částkou bez DPH, jen když je taková položka
jediná a žádná jiná karta ji ještě nemá. Jinak karta zůstane bez dokladu
a doklad jde doplnit ručně.

**Zaúčtování se nepřepočítává.** Deník je přesná kopie toho, co bylo v POHODĚ,
a doklady se k němu jen připojí podle čísla dokladu. Z dokladu je proto vidět
jeho zápis a naopak a automatika už doklad znovu nezaúčtuje. Uzávěrkové zápisy
se nepřebírají. Doklad s datem mimo převáděný rok se zaúčtuje k hranici období.

**Doklady k ruční kontrole.** Fakturu, jejíž daňovou povahu export spolehlivě
neurčuje, převod převezme jako koncept, například doklad s daní bez členění
DPH, doklad s neznámým členěním nebo samovyměření bez interního dokladu.
Koncept nevstoupí do přiznání k DPH, kontrolního hlášení ani do účtování.
Protokol ho vypíše i s důvodem. Po opravě klasifikace DPH ho potvrďte.

Pohledávky, závazky a interní doklady mimo přiznání k DPH zůstanou jen jako
zápisy v deníku, samostatný doklad z nich nevzniká.

Číslo dokladu, které už ve firmě je, dostane příponu roku, například
`FV-0001/2026`.

## 107.3 Co převod nepřenese

- **Sklad.** Zápisy jsou v převedeném deníku, zásoby se zakládají v MyÚčtu.
- **Mzdy s převodem účetnictví.** Převod účetnictví mzdy nepřevádí; mzdy
  z datového souboru (`91_mzdy.xml`) jsou samostatná akce průvodce (107.9).
- **Objednávky, nabídky a přílohy dokladů.** Skeny dokladů připojíte zvlášť
  v `Dokumenty → Skeny k dokladům`.
- **Číselné řady a podaná přiznání.** Přiznání k DPH a kontrolní hlášení za
  převáděný rok zůstávají v POHODĚ.
- **Cizí měny.** Doklady v cizí měně se převezmou v Kč.

## 107.4 Postup

1. **Export z POHODY.** Vytvořte export nástrojem (107.1) a nahrajte soubor
   `.zip`. Rozbalení a načtení běží na serveru na pozadí, u velkého exportu
   i několik minut; obnovení stránky mezitím průvodce nepřeruší.
2. **Náhled a volby.** Tabulka ukáže všechny agendy v exportu: IČO, firmu,
   rok, počty řádků deníku, počátečních stavů, dokladů a partnerů a rozsah
   zápisů. Převést jde jen agendu s IČO firmy v MyÚčtu. Vyberte převáděný rok,
   výchozí je nejnovější. Soubory exportu, které POHODA vrátila prázdné nebo
   které v exportu chybí, průvodce vypíše jako informaci.

   Kontrola před převodem zvoleného roku zastaví převod, když:
   - export patří firmě s jiným IČO,
   - v exportu chybí nebo nejde přečíst účetní deník, osnova nebo členění DPH,
   - účetní období v MyÚčtu už obsahuje zápisy, které nevznikly převodem,
   - období je v MyÚčtu uzavřené.
3. **Zkouška nanečisto.** Proběhne celý převod zvoleného roku včetně
   rekonciliace, na konci se ale všechno vrátí. Výsledkem je protokol;
   v MyÚčtu nic nezůstane a nastavení automatiky se nezmění. Zkouška běží
   v jedné databázové transakci, spouštějte ji proto mimo běžnou práci ve firmě.
4. **Ostrý převod.** Po potvrzení běží na pozadí, stránku můžete zavřít. Po
   dokončení průvodce nabídne účetní deník a obratovou předvahu. Převod jedné
   firmy běží vždy jen jeden, druhý se do jeho konce nespustí.

## 107.5 Rekonciliace a protokol

Každý běh (zkouška i převod) končí protokolem. Najdete v něm kroky převodu
s počty, upozornění a chyby a rekonciliaci převáděného roku:

- obratová předvaha MyÚčta proti předvaze spočtené přímo z deníku POHODY
  v exportu, po syntetických účtech, počáteční stav, obrat a konečný stav na haléř,
- obraty MD = D, předvaha = deník, vyrovnané počáteční stavy, žádné rozpracované zápisy,
- rozvaha vyrovnaná a všechny účty zařazené ve výkazu; účty, které výkazy
  neznají, protokol vypíše k přiřazení v mapování výkazů,
- doklady proti deníku: přijaté faktury proti 321, vydané proti 311, pokladna
  proti 211 a banka proti 221. Doklady účtované jinak (zápočet, úhrada v témže
  zápisu) protokol uvede zvlášť.

Doklad, ke kterému v deníku POHODY není zápis se stejným číslem, protokol
vypíše jako doklad bez zápisu. Takový doklad není zaúčtovaný. Zaúčtujte ho
ručně nebo hromadně v Účetnictví → Doúčtovat doklady.

Úhradu faktury páruje převod podle likvidace, kterou POHODA u faktury drží:
číslo bankovního nebo pokladního dokladu a datum úhrady. Mezi pohyby se
stejným číslem rozhoduje datum. Nejednoznačnou úhradu převod nespáruje
a protokol ji vypíše k ručnímu spárování. Úhrada zápočtem nebo zálohou se
jako úhrada bankou ani pokladnou nepáruje. Spárovaná faktura dostane stav
uhrazeno.

Protokoly všech běhů zůstávají v přehledu pod průvodcem.

## 107.6 Režim účetnictví a automatika

Převod zapíše podvojné účetnictví od začátku převáděného roku do nastavení
firmy. Automatika účtování je během převodu vypnutá: deník přichází hotový
a každý automatický zápis nad týmiž doklady by byl duplicita.

Po úspěšném převodu se automatika vrátí do stavu před převodem. Firma, která
podvojné účetnictví zapíná právě převodem, dostane výchozí nastavení účetní
jednotky jako po aktivaci. Skončí-li převod chybou, automatika zůstane
vypnutá, dokud převod nedoběhne bez chyb.

## 107.7 Opakovaný převod

Převod si pamatuje, co z které agendy už vzniklo. Opakovaný převod téhož nebo
novějšího exportu založí jen to, co ještě chybí, a nic nezdvojí. Převod
přerušený chybou tak stačí po opravě spustit znovu. Takhle se převádí i další
rok: nahrajte export a zvolte jiný rok.

Už převedené doklady ani zápisy deníku opakovaný převod nepřepisuje, protože
mohly být mezitím zaúčtované, spárované nebo upravené v MyÚčtu. Změnila-li se
v POHODĚ celková částka faktury, protokol ji vypíše jako změněnou v POHODĚ
a ponechanou v MyÚčtu; upravte ji ručně.

## 107.8 Omezení

- Převádí se kalendářní účetní rok, období se vždy založí od 1. 1. do 31. 12.
- Převod čte jen export vytvořený nástrojem, nikdy živou databázi POHODY.
- Jeden běh převede jeden rok jedné firmy. Agenda roku musí mít IČO firmy
  v MyÚčtu.
- Nahraný export zůstává na serveru pro další běh. Po úspěšném ostrém
  převodu se smaže, jinak ho aplikace smaže po týdnu bez práce s ním.
  Obsahuje-li export účetnictví i mzdy, po převodu jedné části zůstane pro
  druhou.

## 107.9 Převod mezd

Mzdy z POHODA Mzdy nebo z programu PAMICA převede průvodce samostatně, i do
firmy, která účetnictví z POHODY nepřevádí. Vstupem je `91_mzdy.xml`, který
vytvoří `Export-PohodaMdb.cmd` (107.1.3). Pro samotné mzdy ho spusťte se
složkou `<IČO>_<rok>` a výsledek zabalte do ZIP:

```
Export-PohodaMdb.cmd -Mdb D:\PAMICA\Data\Mzdy.mdb -Skupiny mzdy -Vystup .\mzdy\12345678_2026
```

IČO ve jménu složky určí firmu, do které průvodce mzdy nabídne. V náhledu
exportu je u agendy sloupec **Mzdy** s počtem zaměstnanců a měsíců; agenda
jen se mzdami má štítek *jen mzdy*. U agendy s účetnictvím i mzdami zvolte
v kroku *Náhled a volby*, co převést.

**Předpoklady.** Kontrola před převodem mezd zastaví převod, když firma nemá
zapnutý modul Mzdy, když chybí výchozí mzdová účtárna zaměstnavatele
(kapitola [Nastavení mezd](90_Nastaveni_mezd.md)) nebo když export nemá
zpracované mzdy za zvolený rok. Převod mezd vyžaduje navíc oprávnění
k zápisu mzdových vstupů (`payroll.inputs.write`), osob
(`payroll.person.write`) a nastavení mezd (`payroll.settings`).

**Co převod udělá.** Jde stejnou cestou jako ruční import v `Mzdy → Importy`:

1. uloží profil importu *POHODA mzdy (převod)*,
2. pro každý měsíc sestaví sešit (řádek = pracovní poměr v měsíci): osobní
   číslo, rodné číslo, datum narození, pojišťovna, druh vztahu, středisko,
   pracovní místo, úvazek, měsíční mzda, fond a odpracované hodiny,
   nepřítomnosti, mzdové složky a srážky; hrubá a čistá mzda slouží ke
   kontrole,
3. založí zaměstnance a pracovní vztahy, které ve firmě chybí,
4. použije dávku: vazby, mzdové vstupy, chybějící mzdové složky, měsíční
   mzdu vztahu, souhrn docházky a srážky.

5. doplní údaje osob a vztahů, které sešit měsíce nenese (krok *Údaje osob
   a vztahů*, viz níže).

**Údaje osob a vztahů pro JMHZ.** Po mzdách převod doplní z `91_mzdy.xml`
jen údaje, které v MyÚčtu chybí; vyplněný údaj nepřepíše:

| Z PAMICA | Do MyÚčta |
|---|---|
| občanství, místo narození, tituly, rodné příjmení | identita osoby |
| adresa trvalého pobytu a kontaktní adresa, e-mail, telefon | osobní karta |
| příznak daňového nerezidenta | daňová rezidence (česká rezidence) |
| příslušnost k cizím právním předpisům | příslušnost k sociálnímu pojištění (český režim bez A1) |
| žádost o slevu pracujícího důchodce u zpracovaných mezd | sleva pracujícího důchodce po měsících (uplatněná, nebo neuplatňuje se) |
| děti s daňovým zvýhodněním (1., 2. a 3. dítě) | vyživované osoby s nárokem daného pořadí, od měsíce podepsaného prohlášení |
| prohlášení poplatníka u zpracovaných mezd | prohlášení poplatníka po měsících |
| pracoviště s kódem obce, CZ-ISCO | podmínky vztahu (pracoviště JMHZ) |
| OIČ a ID PPV | identifikátory ČSSZ, jen s potvrzením v průvodci |
| datum skončení pracovního poměru | skončení vztahu k tomuto dni |
| odeslané registrace a odhlášky ČSSZ, oznámení pojišťovnám, ELDP | splněné položky Zákonných termínů |
| úhrny mezd za měsíce před začátkem vedení mezd v MyÚčtu | počáteční stavy kumulací |

OIČ a ID PPV se převezmou jen tehdy, když v kroku *Náhled a volby* potvrdíte,
že čísla v PAMICA pocházejí z protokolů ČSSZ. OIČ s chybnou kontrolní číslicí
převod vynechá a vypíše osobní čísla.

Položku Zákonných termínů převod odškrtne jen tam, kde PAMICA nese doklad:
odeslanou registraci nebo odhlášku ČSSZ, oznámení zdravotní pojišťovně k datu
nástupu nebo skončení, podepsané prohlášení poplatníka, odeslaný ELDP.
Pracovní smlouvu a doklad o skončení odškrtne u vztahu, který PAMICA vedla.
Poznámka položky uvede *Převzato z PAMICA* a datum z PAMICA. Položky bez
dokladu zůstanou otevřené a protokol je spočítá.

U vztahu, který vznikl před prvním převáděným měsícem, PAMICA oznámení
a přihlášky za dávné roky nedrží. Převod proto odškrtne registraci zdravotní
pojišťovny, když má osoba v PAMICA platný kód pojišťovny (ne 999), a registraci
ČSSZ / JMHZ, když PAMICA vede účast na nemocenském pojištění; poznámka uvede
den nástupu. Vztah bez účasti (typicky DPP pod limitem) zůstane otevřený.

Změnové položky (dodatek smlouvy, změna pro pojišťovnu a pro ČSSZ) převod
odškrtne, jen když je založil sám import mezd tím, že zapsal změnu měsíční mzdy
z PAMICA jako historickou verzi podmínek vztahu. Po jiné změně podmínek
zůstanou otevřené.

**Výplatní účty.** Účet, na který PAMICA opakovaně vyplácela mzdu, převod
označí za ověřený. Doklad je věcný: peníze na ten účet skutečně chodily.
Jako datum ověření nese den poslední výplaty z PAMICA (ne den převodu) a původ
je v popisku účtu, takže je při kontrole vidět. Bez ověřeného účtu by u každé
osoby zůstala značka, která brání podání i bankovnímu příkazu. Neověřený
zůstane účet, který PAMICA vede jako neaktivní, tedy mzda na něj nechodila,
a účet osoby, u které export žádnou vyplacenou mzdu nemá; protokol je vypíše
s počtem a ověříte je v kartě osoby.

**Pracoviště a CZ-ISCO.** Obec pracoviště, stát a CZ-ISCO zapisuje převod hned
po každém převedeném měsíci, dokud je verze podmínek toho měsíce ta poslední.
Opravit jde totiž vždy jen poslední verzi; zapsáno až nakonec by pracoviště
dostal jen poslední měsíc a za starší měsíce by nešlo zmrazit hlášení JMHZ.
Každá další verze podmínek si pracoviště opíše z předchozí. Číselníky CZ-ICSE
a CZ-NUTS MyÚčto nevede a kontrola pracoviště pro JMHZ je nevyžaduje: ptá se
na kód obce, název obce a stát.

**Nepřítomnosti.** Hodiny dovolené, lékaře, překážek, neplaceného volna,
neomluvené absence, nemoci a ošetřovného nese už souhrn z importu docházky.
Za měsíc, ve kterém takový souhrn je, převod tutéž nepřítomnost nezakládá
podruhé: jeden údaj má mít jediný zdroj, jinak by se doba vedla dvakrát
a poměrná část měsíční mzdy by se nezkrátila vůbec. Druhy, které souhrn
nenese (peněžitá pomoc v mateřství, rodičovská, dlouhodobé ošetřovné), převod
zapíše a schválí. Protokol vypíše počty podle druhu.

**Začátek vedení mezd.** Počáteční stavy kumulací převod zapíše jen tehdy,
když má firma v nastavení mezd začátek vedení mezd v MyÚčtu. Nastavte první
měsíc, který PAMICA nezpracovala; kontrola před převodem jinak upozorní.

Počáteční stavy ročních kumulací (sociální vyměřovací základ, základ
a záloha daně, uplatněné slevy, bonus, srážková daň) převod zapíše za měsíce
roku před začátkem vedení mezd v MyÚčtu, jen za souvislou řadu měsíců
a jen osobě, která stavy ještě nemá.

Exportní nástroj bere z podání pro ČSSZ a pojišťovny jen vazbu na pracovní
poměr, druh, data a stav odeslání; jména, rodná čísla a adresy z nich
nevytahuje.

Klasifikace složek odpovídá katalogu POHODA Mzdy / PAMICA: časová a úkolová
mzda, příplatky, odměny, proplacená dovolená a obědy (srážka ze mzdy).
Základní mzdu počítá MyÚčto ze sjednané mzdy vztahu, náhrady z hodin
a průměru; odstupné, exekuce a zákonné položky převod nepřebírá.

**Zařazení složek do JMHZ.** Plnění, které svou složku v číselníku má, jde na
ni: zdanitelná část stravování na *Zdanitelná část stravování*, odměna za
kontejnery na *Odměna za kontejnery*, příplatek za noční práci na *Příplatek
za noční práci*. Druhá složka pro totéž plnění by rozdělila úhrn v hlášení.
Ostatním složkám doplní aplikace zařazení podle druhu už při jejich založení
(časová a úkolová mzda, příplatky, odměny, náhrady). Mzda za odpracovaný
přesčas, doplatek, dorovnání i placená doba školení jsou mzda za práci, ne
příplatek ani odměna, takže jdou mezi tarifní mzdy. Kde obsah plnění z názvu
složky neplyne, například u příspěvku, převod nic nehádá:
složku založí bez zařazení a protokol ji vypíše s kódem a počtem vstupů.
Zařaďte je v `Mzdy → Mzdové složky`, jinak nepůjde zmrazit měsíční hlášení.

**Opakovaný převod** měsíc, který už prošel, přeskočí. Osobu, kterou nejde
založit (například rodné číslo bez platného data narození nebo neznámý kód
pojišťovny), protokol vypíše a její mzdy v daném měsíci zůstanou nespárované;
po doplnění osoby v evidenci převod spusťte znovu.
