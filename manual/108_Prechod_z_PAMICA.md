# 108. Přechod z PAMICA

**Cesta: `Systém → Přechod z PAMICA`**

Průvodce převede personalistiku a mzdy z datového souboru mzdového programu
PAMICA do firmy v MyÚčtu, i do firmy, která účetnictví z POHODY nepřevádí.
Stejnou cestou se převádí i mzdy z **POHODA Mzdy** — je to tentýž mzdový
modul STORMWARE se stejným formátem dat. Účetnictví tento průvodce nepřevádí,
to je samostatná kapitola [Přechod z POHODY](107_Prechod_z_POHODY.md).

Položka je v menu Systém, které vidí administrátor. Jiný uživatel s potřebnými
oprávněními otevře průvodce přímým odkazem `/imports/pamica`.

Průvodce vidí a zkoušku nanečisto spouští uživatel s oprávněním
`utilities.import` pro zápis. Ostrý převod zakládá zaměstnance a zapisuje
mzdové vstupy, proto navíc vyžaduje zápis mzdových vstupů
(`payroll.inputs.write`), osob (`payroll.person.write`) a nastavení mezd
(`payroll.settings`). Chybějící oprávnění průvodce ukáže a převod nespustí.

## 108.1 Export z PAMICA

### 108.1.1 Exportní nástroj

V prvním kroku průvodce ukáže tlačítkem *Zobrazit exportní nástroj* soubory
nástroje `Export-Pamica.cmd` (spouštěč) a `Export-Pamica.ps1` (vlastní
exportní skript). Stáhněte oba, nebo tlačítkem *Stáhnout vše (ZIP)* jako
`pamica-export.zip`; oba soubory musí ležet ve stejné složce.

Na rozdíl od POHODY nemá PAMICA XML rozhraní pro komunikaci s běžícím
programem. Nástroj proto čte přímo datový soubor `Mzdy*.mdb` — stejně jako
doplňkový skript `Export-PohodaMdb.ps1` u přechodu z POHODY (§ 107.1.4), pro
`.mdb` je tak potřeba ovladač Microsoft Access Database Engine. Nástroj data
jen čte, PAMICA během exportu musí být zavřená.

### 108.1.2 Vytvoření exportu

1. Spusťte `Export-Pamica.cmd` dvojklikem, nebo z příkazového řádku:

   ```
   Export-Pamica.cmd -Rok 2026 -Ico 12345678
   ```

2. Bez parametrů nástroj datový soubor najde sám v obvyklých umístěních
   STORMWARE. Když ho nenajde, nebo je jinde, zadejte ho parametrem `-Mdb`.
3. Vedle skriptu vznikne rovnou `pamica_export_<datum>.zip`. Ten nahrajte do
   průvodce beze změny, nerozbalený.

| Parametr | Význam |
|---|---|
| `-Mdb` | cesta k datovému souboru PAMICA (`Mzdy*.mdb`), když ho nástroj sám nenajde |
| `-Rok` | omezí export na zadané roky; bez něj se vyexportují všechny roky v datech |
| `-Ico` | IČO firmy, když se ho nepodaří určit z dat |

Export jen čte. Do datového souboru nic nezapisuje.

### 108.1.3 Co je v exportu

ZIP obsahuje podsložky `<IČO>_<rok>` se souborem `91_mzdy.xml` — zaměstnanci,
pracovní poměry, zpracované mzdy a číselníky mezd. IČO ve jménu složky určí
firmu, do které průvodce mzdy nabídne; podle roku ve jménu složky pak měsíce.

V náhledu exportu je u agendy sloupec **Mzdy** s počtem zaměstnanců a měsíců.
Firma musí v MyÚčtu existovat a mít vyplněné stejné IČO. Nahraný soubor
aplikace po 7 dnech bez práce s převodem sama smaže, po úspěšném ostrém
převodu hned.

Přechází-li firma z POHODY zároveň s účetnictvím i se mzdami z **POHODA
Mzdy**, použije se místo tohoto nástroje `Export-PohodaMdb.cmd` popsaný
v [§ 107.1.4](107_Prechod_z_POHODY.md#10714-majetek-z-datoveho-souboru) — vzniklý
`91_mzdy.xml` nahrajte beze změny sem, do tohoto průvodce; sám o sobě je
formátem shodný s exportem z PAMICA.

## 108.2 Co převod přenese

**Co převod udělá.** Jde stejnou cestou jako ruční import v `Mzdy → Importy`:

1. uloží profil importu *POHODA mzdy (převod)*,
2. pro každý měsíc sestaví sešit (řádek = pracovní poměr v měsíci): osobní
   číslo, rodné číslo, datum narození, pojišťovna, druh vztahu, středisko,
   pracovní místo, úvazek, měsíční mzda, fond a odpracované hodiny,
   nepřítomnosti, mzdové složky a srážky; hrubá a čistá mzda slouží ke
   kontrole,
3. založí zaměstnance a pracovní vztahy, které ve firmě chybí,
4. použije dávku: vazby, mzdové vstupy, chybějící mzdové složky, měsíční
   mzdu vztahu, souhrn docházky a srážky,
5. doplní údaje osob a vztahů, které sešit měsíce nenese (viz níže).

**Převzatou docházku a mzdové vstupy rovnou schválit.** V kroku *Náhled
a volby* je zaškrtávátko, ve výchozím stavu zapnuté. Měsíce z PAMICA už
proběhly a jsou podané, takže jako neschválené koncepty by nad nimi mzdový
běh nešel spustit ani uzavřít — kontroly *docházka není schválena*
a *neschválené mzdové vstupy* jsou blokující a výjimkou se nedají přebít.
Se zapnutou volbou převod docházku a mzdové vstupy rovnou schválí. Bez ní
zůstanou podklady ke kontrole a účetní je schválí ručně v `Mzdy → Vstupy`
a `Mzdy → Docházka`.

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

**Zapnutí mezd a začátek vedení mezd.** Firmě, která mzdy v MyÚčtu ještě
nemá, převod zapne modul Mzdy a začátek vedení mezd v MyÚčtu nastaví na měsíc
po posledním měsíci v exportu. Chybí-li nastavení zaměstnavatele, založí ho
s mzdovou účtárnou `MZDY` a výchozími předkontacemi. Variabilní symbol ČSSZ,
kód OSSZ a číslo plátce zdravotního pojištění, které firma vede v Nastavení
firmy, převezme do Mezd a k variabilnímu symbolu založí registraci účtárny
s účinností od začátku vedení mezd
(viz [§ 90.8](90_Nastaveni_mezd.md#908-podrobny-pracovni-postup-a-kontroly));
co chybí, včetně účtů institucí, vypíše protokol k doplnění v Mzdy → Nastavení.
Zapnutý modul, jeho začátek ani existující nastavení převod nemění. Počáteční
stavy kumulací převod zapíše jen tehdy, když má firma začátek vedení mezd
nastavený; jiný než navržený začátek nastavte ještě před převodem.

Počáteční stavy ročních kumulací (sociální vyměřovací základ, základ
a záloha daně, uplatněné slevy, bonus, srážková daň) převod zapíše za měsíce
roku před začátkem vedení mezd v MyÚčtu, jen za souvislou řadu měsíců
a jen osobě, která stavy ještě nemá.

Exportní nástroj bere z podání pro ČSSZ a pojišťovny jen vazbu na pracovní
poměr, druh, data a stav odeslání; jména, rodná čísla a adresy z nich
nevytahuje.

Klasifikace složek odpovídá katalogu PAMICA / POHODA Mzdy: časová a úkolová
mzda, příplatky, odměny, proplacená dovolená a obědy (srážka ze mzdy).
Základní mzdu počítá MyÚčto ze sjednané mzdy vztahu, náhrady z hodin
a průměru; odstupné převod nepřebírá, srážky a exekuce mají vlastní krok (§ 108.3).

**Zařazení složek do JMHZ.** Plnění, které svou složku v číselníku má, jde na
ni: zdanitelná část stravování na *Zdanitelná část stravování*, odměna za
kontejnery na *Odměna za kontejnery*, příplatek za noční práci na *Příplatek
za noční práci*. Druhá složka pro totéž plnění by rozdělila úhrn v hlášení.
Ostatním složkám doplní aplikace zařazení podle druhu už při jejich založení
(časová a úkolová mzda, příplatky, odměny, náhrady). Mzda za odpracovaný
přesčas, doplatek, dorovnání i placená doba školení jsou mzda za práci, ne
příplatek ani odměna, takže jdou mezi tarifní mzdy. Kde obsah plnění z názvu
složky neplyne, například u příspěvku, převod nic nehádá: složku založí bez
zařazení a protokol ji vypíše s kódem a počtem vstupů. Zařaďte je
v `Mzdy → Mzdové složky`, jinak nepůjde zmrazit měsíční hlášení.

## 108.3 Srážky, exekuce a insolvence

Trvalé srážky z karty zaměstnance i srážky ve zpracovaných mzdách převod
zařadí podle číselníku srážek PAMICA, ne podle čísla složky:

| V PAMICA | Do MyÚčta |
|---|---|
| zákonná srážka | případ v `Mzdy → Srážky a exekuce` s jednou pohledávkou |
| deponovaná částka | tentýž případ se stavem odloženého srážení |
| insolvence a oddlužení | případ s režimem, který jen upozorňuje, nesráží |
| ostatní srážky | dohoda o srážkách v `Mzdy → Dohody o srážkách` |

Den doručení plátci (`DatPoradi`) určuje pořadí pohledávky, počet vyživovaných
osob zakládá vyživované osoby pro nezabavitelnou částku a příjemce srážky
(firma, účet, variabilní symbol) vznikne jako příjemce odvodu. Srážka bez data
doručení se nepřevede, protože bez něj nejde určit pořadí; protokol jmenuje
osobu. Srážku, kterou už nese měsíční sešit (typicky obědy), převod nezaloží
podruhé.

**Případy zůstanou nedoložené.** Doklady, kterými se exekuce dokládá (exekuční
příkaz, rozhodnutí o oddlužení), export nenese, a MyÚčto je bez nich vyžaduje.
Převzatý případ proto zůstane ve stavu *přijato*, do mzdového běhu nevstoupí
a protokol spočítá, kolik případů čeká na doložení. Doložíte je v kartě případu.

Nepřevezme se rozpad nezabavitelné částky z PAMICA (MyÚčto ho počítá vlastní
sadou pravidel, dvojí zdroj by se rozešel), vazba dvou srážek na jeden příkaz,
společné oddlužení manželů a vazby na spořicí produkty.

## 108.4 Účty institucí

Účty zdravotních pojišťoven převod vezme z číselníku PAMICA. Číselník
finančního úřadu a OSSZ ale PAMICA drží v nastavení programu, které se
neexportuje, takže jejich účty převod **odvozuje z vystavených závazků** podle
předčíslí účtu u ČNB: `21012` je OSSZ, `713` záloha daně ze závislé činnosti,
`7720` srážková daň. Variabilní symbol finančního úřadu je kmenová část DIČ
firmy v MyÚčtu.

Odvozený účet se zakládá jako **nepotvrzený**: platební dávka ho odmítne,
dokud ho nepotvrdíte v Nastavení mezd. Protokol vypíše, kolik účtů čeká na
potvrzení. Když závazek pod daným předčíslím v exportu není, nebo jsou pod ním
dva různé účty, převod mezi nimi nevybírá a účet nezaloží; protokol řekne,
co doplnit.

Kód územního pracoviště finančního úřadu export nenese vůbec, pro podání
REGZEL ho zadejte ručně. Penzijní a životní pojištění, DIP a dlouhodobá péče
mají v exportu jen názvy, ne účty.

## 108.5 Dovolená

Převádí se **zůstatek** dovolené ke dni přechodu jako převod z minulého
období, počítaný z hodinových sloupců karty dovolené; dny se použijí, jen když
hodiny chybí, a přepočtou se denním úvazkem vztahu. Záporný zůstatek se
nepřevádí. Jednotlivá čerpání se nepřevádějí, v zůstatku jsou už odečtená.
Karta dovolené je v PAMICA na osobě, takže u zaměstnance se souběžnými vztahy
se zůstatek nepřevede a protokol na to upozorní.

## 108.6 Co převod nepřenese

- **Dávky nemocenského (`MZdavky`).** Od roku 2009 je vyplácí ČSSZ, MyÚčto pro
  ně evidenci nevede. Rozpracovaná podání zadejte v `Mzdy → Podání`.
- **Vlastní výpočet náhrady mzdy.** Částku z předchozího programu převod uvede
  u nepřítomnosti jako poznámku, ale nepoužije ji: náhradu si MyÚčto počítá
  z průměrného výdělku a rozvržených směn. Vnucená cizí částka by ten výpočet
  obešla.
- **Přílohy k žádosti o dávku (`NEMPRIpol`).** Rozhodné období a vyloučené dny
  z nich MyÚčto v případu dávky nevede.
- **Zaúčtování mezd (`MZzauct`).** Účetní zápisy se nepřenášejí: mzdy se do
  účetnictví zaúčtují až v MyÚčtu, podle jeho vlastního nastavení. Převzaté
  zápisy by proti převedeným dokladům vyrobily duplicitu. Z převzatého
  zaúčtování se bere jen podklad pro nastavení kontací (§ 108.11).
- **Zákonné pojištění odpovědnosti zaměstnavatele.** Export ho nevede.

## 108.7 Nemocenská přes přelom

Neschopnost, která začala u předchozího programu a pokračuje v prvním měsíci
vedeném v MyÚčtu, převod zapíše jako nepřítomnost od prvního měsíce, který
MyÚčto vede, a **doplní dny čtrnáctidenního okna náhrady mzdy, které vyčerpal
předchozí plátce** (§ 192 zákoníku práce). Bez nich by MyÚčto okno počítalo
znovu od začátku a náhradu vyplatilo podruhé. Hodnotu je vidět a jde opravit
v detailu nepřítomnosti.

Nepřítomnost, kterou převod založil, rovnou schválí: u převzatého případu
rozhodl předchozí program. Druh, který potřebuje průměrný výdělek, se schválí
až když má čtvrtletí schválený průměr; ostatní zůstanou k rozhodnutí a protokol
je vypíše.

## 108.8 Přechod uprostřed roku

Měsíce před zahájením vedení mezd v MyÚčtu se nepřepočítávají — jejich
výsledky se uloží jako počáteční stavy ročních kumulací (§ 108.2, Začátek
vedení mezd) a jako **převzaté mzdy** po měsících.

Z převzatých mezd MyÚčto sestaví i to, co dřív za rok přechodu sestavit nešlo:
potvrzení o zdanitelných příjmech ze závislé činnosti (§ 38j odst. 3 zákona
o daních z příjmů), roční mzdový list a evidenční list důchodového pojištění.
Převzatá část je v dokladu vždy označená — není to výpočet MyÚčta. Chybí-li
převzatému měsíci údaj, který doklad potřebuje, doklad se raději nevystaví
a řekne, co doplnit.

Převzaté mzdy plní převod z PAMICA sám. Zákazník, který přichází odjinud, je
nahraje z CSV nebo XLSX v `Mzdy → Importy → Převzaté mzdy`; vzorový soubor je
ke stažení tamtéž.

Celá agenda přechodu žije v `Mzdy → Importy` jako záložky **Převzaté mzdy**,
**Kontrola převzetí** a **Kontace z převzetí**. Poslední dvě se nabízí, až
když je co převzatého — firmě, která mzdy od začátku počítá v MyÚčtu, se
neukážou vůbec.

## 108.9 Opakovaný převod

Převod si pamatuje, co z které agendy už vzniklo. Opakovaný převod téhož nebo
novějšího exportu založí jen to, co ještě chybí, a nic nezdvojí. Měsíc, který
už prošel, přeskočí. Osobu, kterou nejde založit (například rodné číslo bez
platného data narození nebo neznámý kód pojišťovny), protokol vypíše a její
mzdy v daném měsíci zůstanou nespárované; po doplnění osoby v evidenci
převod spusťte znovu.

## 108.10 Kontrola převzatých mezd

**Cesta: `Mzdy → Importy → Kontrola převzetí`**

Převzatý měsíc jde v MyÚčtu přepočítat vlastní legislativní sadou. Jenže
PAMICA ta čísla už podala — do jednotného měsíčního hlášení zaměstnavatele,
zdravotním pojišťovnám a finančnímu úřadu. Kontrolní sestava postaví obě
strany vedle sebe, aby bylo vidět, jestli se přepočet s podaným rozešel.

Sestava porovnává za rok, po osobách a měsících, vždy trojici **PAMICA /
MyÚčto / rozdíl**:

- hrubá a čistá mzda,
- vyměřovací základ sociálního a zdravotního pojištění,
- pojistné zaměstnance na sociální a na zdravotní,
- pojistné zaměstnavatele na zdravotní,
- záloha na daň, srážková daň a daňový bonus.

Rozhodovací vrstvou je **přehled měsíců**: za každý měsíc roku počet osob,
počet odchylek, největší rozdíl a stav. Rozkliknutý měsíc ukáže své odchylky
po osobách a veličinách; tlačítkem *Zobrazit celý rozpad* se pod ním dokreslí
úplná tabulka trojic za všechny osoby a všechny veličiny. Přepínačem *Jen
měsíce s odchylkou* se schovají měsíce, ve kterých všechno sedí.

Pojistné zaměstnavatele na **sociální** zabezpečení se porovnává jen
v součtu za měsíc a za rok. Osobní veličina to není — počítá se z úhrnu
vyměřovacích základů celé firmy —, takže rozpad na jednotlivé osoby by byl
jen odhad.

Řádek sestavy je **osoba a měsíc**, ne pracovní vztah a měsíc. PAMICA má
zpracovanou mzdu za každý vztah zvlášť a sestava je za osobu sečte: pojistné
i daň jsou ze zákona veličiny osoby, ne vztahu, takže jinou společnou
granularitu obě strany nemají. Kolik vztahů do řádku přispělo, je u osoby
vidět.

**Chybějící protějšek se nikdy nevydává za nulu.** Měsíc, který MyÚčto
nepočítalo, i vztah, který PAMICA nemá, se v rozdílovém sloupci ukáže jako
*MyÚčto nepočítalo* / *chybí v původním systému*, a součet, do kterého
nepřispěly všechny řádky, nese značku *neúplné*. Nula v rozdílu tak vždycky
znamená „sedí to", ne „nemám s čím porovnat".

Sestava čte **aktuální** revizi mzdového běhu, ne jen schválenou — smysl je
podívat se na přepočet dřív, než se schválí. Měsíc s neschválenou revizí je
označený stavem revize.

Sestavu vidí uživatel s oprávněním ke mzdovým sestavám (`payroll.reports`).

## 108.11 Kontace mezd z původního programu

**Cesta: `Mzdy → Importy → Kontace z převzetí`**

Kontace mezd (které mzdové plnění jde na který účet) má původní program
nastavené a export je nese. MyÚčto z nich odvodí **návrh nastavení**, takže
je nemusíte naklikat znovu.

Není to import účetních zápisů. Mzdy se zaúčtují až v MyÚčtu podle tohoto
nastavení; převzaté zápisy by proti převedeným dokladům vznikly dvakrát.

U každého mzdového plnění obrazovka ukáže, **z čeho odvozený účet vyšel**:
kolik řádků převzatého zaúčtování za ním stojí, kolik přes něj prošlo peněz
a na jakých střediscích. Stavy jsou čtyři:

- **jednoznačné** - vyšel právě jeden účet a firma ho má v osnově. Jen tenhle
  stav nese doporučení.
- **rozpor** - na jedno plnění vyšly dva a víc účtů. Návrh **nevybírá
  většinový**: firma se dvěma zdravotními pojišťovnami na dvou analytikách má
  obě správně. Oba účty se ukážou s počty a rozhodnete vy.
- **účet mimo osnovu** - účet je jednoznačný, ale ve vaší účtové osnově není.
  Nabídnout ho jako hotovou volbu by nešlo uložit, takže se jen označí; pokud
  má osnova aspoň jeho syntetiku, obrazovka to připomene.
- **bez podkladu** - v převzatých datech k tomu plnění nic není a nastavení
  zůstává na výchozí hodnotě.

Plnění, pro které MyÚčto kontaci nemá (zálohy, úhrada mzdy, zaokrouhlení,
dávky nemocenské), se nezahazuje - je ve zvláštní tabulce pod návrhem.

**Nic se neuloží samo.** Uloží se právě ty účty, které jste v nabídce
vybrali; ostatní plnění zůstanou na dosavadní hodnotě. Zápis jde stejnou
cestou jako obrazovka `Mzdy → Nastavení`, takže platí stejné kontroly osnovy
i typu účtu. Obrazovku vidí uživatel s oprávněním k nastavení mezd
(`payroll.settings`).
