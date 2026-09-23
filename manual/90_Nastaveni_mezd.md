# 90. Nastavení mezd

## 90.1 Účel

Nastavení mezd obsahuje údaje zaměstnavatele, výchozí účty, termíny, institucionální identifikátory a předkontace používané napříč mzdovým tokem.

## 90.2 Předpoklady a oprávnění

Je nutné oprávnění `payroll.settings`. Připravte ověřené identifikační údaje, symboly ČSSZ a zdravotních pojišťoven, bankovní účty a schválený účtový rozvrh. ISDS se nastavuje samostatně v obecném nastavení firmy.

## 90.3 Krokový postup

1. Otevřete **Mzdy → Nastavení mezd**.
2. Vyplňte identifikaci zaměstnavatele a údaje pro dokumenty a podání.
3. Nastavte účty a splatnosti pro mzdy, daň, sociální a zdravotní pojištění.
4. Doplňte přidělené identifikátory institucí.
5. Nastavte výchozí předkontace a zkontrolujte jejich existenci v účtovém rozvrhu.
6. Pro podání vytvořte oddělené TEST a produkční profily; certifikát uložte jen do určeného bezpečného úložiště.

## 90.4 Stavy

Rozpracované nastavení lze uložit, ale navazující krok může být blokován. Validační chyba označuje neúplný nebo neplatný údaj. Úspěšné uložení nepotvrzuje, že identifikátor či účet uznala externí instituce.

Po dokončení základního nastavení se mzdový modul automaticky označí jako
aktivní. Zákazník nedokládá paralelní měsíce, opravný běh, obnovu ze zálohy ani
kvalifikační protokol. Od aktivace jsou dostupná ostrá podání i mzdové platební
příkazy. Podrobný popis je v
[úvodní kapitole mezd](75_Uplne_mzdy.md#7527-7-dokoncete-nastaveni-firmy).

## 90.5 Kontroly a bezpečnost

Ověřte každou hodnotu proti oficiálnímu zdroji a správné firmě. Privátní klíče, hesla a SMS kódy nepatří do poznámek ani příloh. Testovací certifikát ČSSZ používejte jen v TEST profilu a produkční konfiguraci ověřte samostatně.

## 90.6 Časté chyby

- Identifikátor nebo účet zkopírovaný z jiné firmy.
- Záměna TEST a produkčního prostředí.
- Chybějící předkontace blokující účetní krok.
- Domněnka, že nastavení ISDS automaticky odesílá podání nebo načítá inbox.

## 90.7 Návaznosti

Osoby a vztahy založíte v [kapitole 58k](86_Zamestnanci.md), složky v [58p](91_Mzdove_slozky_a_vstupy.md), účetní kontrolu v [58f](81_Shoda_uctovani_mezd.md) a elektronické odeslání v [58j](85_Podani_a_hlaseni.md).



## 90.8 Podrobný pracovní postup a kontroly

V **Mzdy → Nastavení mezd** se evidují registrační a kontaktní údaje
pro mzdovou agendu. Stránka používá čtyři samostatné záložky:
**Zaměstnavatel a účtárny**, **Účty institucí**, **Automatické účtování** a
**Politiky a připravenost**. Firma může mít více mzdových účtáren, ale právě jedna
aktivní účtárna musí být označena jako výchozí. Každá účtárna má vlastní název,
kód a vlastní variabilní symbol pro platby sociálního pojištění. Vyplňuje se
**název**; kód se z něj předvyplní sám (bez diakritiky, velkými písmeny) a
při shodě s existující účtárnou se odliší číselnou příponou. Přepsat ho můžete
kdykoli — jakmile do něj sáhnete, přestane se z názvu odvozovat. Stejně se
chová kód mzdové složky, dimenze (středisko/zakázka/činnost) i ručně zadávané
instituce. Pole
**Registrační číslo zaměstnavatele** slouží pro evidenci a podání; není
variabilním symbolem platby.

U každé účtárny lze vyplnit **Testovací VS ČSSZ**. Testovací prostředí ČSSZ má
vlastní přidělený variabilní symbol, jiný než ostrý, a podání poslané pod cizím
symbolem zamítne. Odmítnutí přitom hlásí chybějící pověření k e-službě nebo
nezaznamenaný certifikát, takže se snadno splete s problémem podpisu. Jakmile je
testovací symbol vyplněný, přehled odeslání ho v testovacím prostředí nabídne
sám a při odesílání pod ostrým symbolem upozorní.

V záložce **Podání** se potvrzuje samostatný profil REGZEL. Obsahuje
čtyřmístný `kodFU`, povinný `kodPracovisteFU` (kromě Specializovaného
finančního úřadu s kódem 4000), případné devítimístné VČP
začínající `6` a evidenční příznaky
zaměstnavatele. Kód pracoviště může aplikace nabídnout z daňového nastavení
firmy, ale použije jej až po výslovném potvrzení; `kodFU` nikdy neodvozuje.
VČP vyplň pouze tehdy, pokud je firmě skutečně přidělil správce daně; nejde
o registrační číslo zaměstnavatele ani o variabilní symbol ČSSZ.

V části **Platební účty institucí** se evidují účty ČSSZ, finančního úřadu,
zdravotních pojišťoven, zákonného pojištění a dalších příjemců. Pro každý účet
vyber typ instituce a ulož zaměstnavatelský variabilní symbol, měnu, období
platnosti, druh ověřovacího zdroje a datum ověření; reference zdroje (číslo
sdělení nebo dopisu) je nepovinná a lze ji nechat prázdnou. Stejně tak jsou
volitelné reference podkladů v politikách zaměstnavatele a u počátečních stavů
převzatých z předchozího zpracování. Povinná pole jsou ve formuláři označená
hvězdičkou. V seznamu jsou celé číslo účtu a variabilní symbol vidět hned
v prvních sloupcích, bez rozklikávání; v úložišti zůstává účet šifrovaný.
Změnu samotného účtu, typu nebo kódu
instituce či začátku platnosti založ jako nový historický záznam; u existujícího
záznamu lze bezpečně upravit název, platební symboly, konec platnosti a údaje
o ověření. Období stejné instituce a měny se nesmějí překrývat.

Pod účty institucí se nastavuje **sazba zákonného pojištění odpovědnosti
zaměstnavatele** (vyhláška č. 125/1993 Sb.). Sazba se ukládá s datem, od kdy
platí, a s kódem pojistitele — ten musí odpovídat kódu instituce zadanému
u účtu typu Zákonné pojištění. Sazbu určuje a pojistné počítá i platí sám
zaměstnavatel; pojišťovna neposílá výměr ani předpis pojistného.

K výběru slouží rozbalovací **sazebník přílohy č. 2 vyhlášky** — všech osm
sazbových skupin a 98 vyjmenovaných činností tak, jak je uvádí předpis, včetně
dvou skupin bez kódu: 10,5 ‰ pro činnosti, při kterých se pracuje s výbušninami,
radioaktivními látkami, radonem, infekčním materiálem nebo jedy a pro práci ve
velkých výškách či hloubkách, a 5,6 ‰ pro ostatní ekonomické činnosti. Kliknutím
na řádek se sazba předvyplní do formuláře; uloží se až tlačítkem Přidat sazbu
a lze ji předtím přepsat.

Sazebník je **podklad, ne odpověď**. Příloha č. 2 člení činnosti podle
klasifikace OKEČ, kterou Český statistický úřad zrušil k 31. 12. 2007 a nahradil
ji CZ-NACE; vyhláška se od té doby nezměnila, takže závazná je stále OKEČ.
Stejné číslo přitom v obou klasifikacích znamená jinou činnost (OKEČ 62 je
letecká doprava, CZ-NACE 62 jsou činnosti v oblasti informačních technologií),
a závazný převodník mezi nimi neobsahuje žádný právní předpis. MyÚčto proto
čísla kódů nepáruje: má-li firma vyplněný kód CZ-NACE, nabídne řádky sazebníku
podobné **názvem** činnosti a označí je jako nezávazný návrh. Rozhoduje
skutečná převažující základní činnost tvořící předmět podnikání.

Zadá-li se sazba, která v příloze č. 2 není, formulář na to upozorní, ale
uložení nezablokuje — doložená odlišná sazba má přednost před číselníkem.
Z uložené sazby se čtvrtletně počítá pojistné z vyměřovacího základu sociálního
pojištění; minimum je 100 Kč za kalendářní čtvrtletí a výsledek se zaokrouhluje
nahoru na celé koruny.

Osobní variabilní symbol ČSSZ a číslo pojištěnce OSVČ v obecném nastavení firmy
zůstávají určena pro vlastní odvody fyzické osoby. Platby zaměstnavatele je
nepřebírají. U právnické osoby se tato osobní pole v obecném nastavení
nezobrazují; identifikátory zaměstnavatele se ukládají jen v mzdovém nastavení.
Automatické návrhy a rozpoznání bankovních plateb používají aktivní mzdovou
účtárnu a účet příslušné instituce platný k datu platby; nejednoznačný nebo
historický údaj zůstane k ručnímu posouzení.

Na stejné stránce se nastavují výchozí účty automatického zaúčtování. Samostatně
se rozlišuje mzda zaměstnance mimo výkon funkce, příjem společníka a odměna za
výkon funkce člena orgánu. Dále se vybírají účty pojistného, daně a ostatních
srážek. Nabídka obsahuje jen aktivní účty vhodného typu z účtového rozvrhu firmy.
Příznak automatického zaúčtování se při uzamčení vstupů uloží do neměnné revize
mzdového běhu. Je-li pro dané období vypnutý, schválení automatický účetní deník
nevytvoří. Pozdější změna politiky už uzamčený běh nezmění; chybějící nebo
neplatná politika automatické účtování bezpečně zastaví.

### 90.8.1 Předkontace pro zvláštní mzdové situace

Vedle běžných účtů mzdy, pojistného, daně a srážek se nastavují také
předkontace, které se použijí jen v konkrétní situaci. Není-li předkontace
vyplněná, aplikace použije bezpečnou výchozí hodnotu; vyplňte ji podle vlastní
osnovy tam, kde se od výchozí liší:

| Předkontace | Kdy se použije | Výchozí účty |
|---|---|---|
| **Povinné spoření u rizikové práce** | zákonný příspěvek zaměstnavatele a závazek vůči penzijní společnosti | 527 / 379 |
| **Pohledávka za zaměstnancem** | záporná čistá mzda | 335 proti 331 nebo 366 |
| **Nedaňová část benefitu** | osvobozená část nepeněžního benefitu | 528 |
| **Cestovní náhrady** | vyúčtování pracovní cesty promítnuté do mzdy | 512 proti 331 nebo 366 |

Zákonný příspěvek na spoření u rizikové práce se zaměstnanci nevyplácí a
penzijní společnost není institucí sociálního ani zdravotního pojištění, proto
nejde ani na 331, ani na 336.

Nedaňová část benefitu je ta, která je **u zaměstnance osvobozená od daně** —
§ 25 odst. 1 písm. h) zákona o daních z příjmů ve znění od 1. 1. 2024 ji
vylučuje z daňově uznatelných nákladů. Nadlimitní část se naopak zaměstnanci
zdaní a zaměstnavateli uznatelná zůstává (§ 24 odst. 2 písm. j) bod 4). Dělení
se týká **jen** košů **zdravotní plnění** a **rekreace, sport a kultura** podle
§ 6 odst. 9 písm. d); stravování, spoření na stáří a přechodné ubytování jsou
uznatelné celé a nedělí se.

Cestovní náhrady se účtují proti závazkovému účtu pracovního vztahu, ne na
samostatný účet jiných závazků. Zaměstnanci se vyplácí přesně totéž co dřív;
mění se jen zápis v deníku.

**Analytika pojistného.** Pole účtu přijme i analytiku, například `336.100` pro
sociální a `336.200` pro zdravotní pojištění. Založíte-li tyto účty ve své
osnově, můstek je použije a saldo 336 se rozdělí. Firmám, které je nemají,
aplikace tyto účty **sama nedoplní** a předvyplněná hodnota zůstává na
syntetickém `336` — doplnění uprostřed roku by rozdělilo saldo, které do té
doby bylo jedno. Rozhodnutí je na účetní; udělejte je k začátku účetního
období.

Nové předkontace se do už zaúčtovaných revizí nepromítají. Zmrazený snapshot
nese vlastní sadu účtů, takže opakované zaúčtování staršího období vypadá
přesně jako poprvé.

V záložce **Politiky a připravenost** se vede časová historie výplatního dne,
pravidla posunu na pracovní den, zaokrouhlení doplatku, oprávnění účetní,
automatických kroků a bezpečného doručení. Jedna oprávněná účetní může celý
mzdový tok dokončit a odeslat bez povinného zásahu druhé osoby. Období dvou politik se nesmějí
překrývat. Nové budoucí pravidlo proto založ až po ukončení platnosti
předchozího záznamu. Původ systémového nebo migrovaného záznamu nelze při
ruční úpravě změnit.

V záložce **Dimenze** se vedou mzdová střediska, zakázky a činnosti — vlastní
číselník nezávislý na účetním rozvrhu, takže funguje i ve firmě v daňové
evidenci. Každá dimenze má typ, kód, název, období účinnosti a volitelný
výchozí analytický účet k předkontacím automatického můstku. Kód je unikátní
v rámci typu jen s ohledem na účinnou historii — stejný kód a typ lze znovu
použít v neprekrývajícím se pozdějším období. Dimenzi použitou ve schválené
mzdové revizi nejde smazat, jen ukončit její účinnost; nepoužitou dimenzi lze
smazat běžně. Konkrétní přiřazení střediska, zakázky nebo činnosti pracovnímu
vztahu se vede přímo na kartě daného vztahu v seznamu zaměstnanců, opět
s vlastním obdobím účinnosti a bez souběhu dvou dimenzí stejného typu.

Výchozí účet dimenze mění pouze nákladovou stranu hrubé mzdy. Použije se jen
tehdy, když mzdová složka nemá vlastní výslovnou předkontaci; konkrétní účet
složky má vždy přednost. Pokud má vztah účet na více dimenzích, rozhoduje v
pevném pořadí středisko, zakázka a činnost. Účinné přiřazení i účet se při
uzamčení vstupů uloží do snapshotu revize. Pozdější změna číselníku proto
nezmění schválený měsíc a projeví se až v nově sestaveném snapshotu.
Jako nákladový účet nepoužívejte účty vyhrazené pro pojistné, daň, srážky nebo
čistou mzdu (například 524, 336, 342, 379, 331 a 366); aplikace takovou kolizní
předkontaci odmítne, aby se jedna částka nevykázala ve dvou kategoriích.

Kontrola připravenosti se spouští k vybranému dni. Ukazuje každý ověřený
předpoklad i přesný blokující nedostatek. Kontrolují se jen funkce, které firma
skutečně zapnula; zapnutá automatizace, JMHZ nebo bezpečné doručení však bez
pozitivního důkazu zůstávají zablokované. Přepínač **Vést mzdy** je nadále jen
v obecném Nastavení firmy a na této stránce se neduplikuje.

Změny se ukládají s kontrolou souběžné editace. Pokud mezitím nastavení změnil
jiný uživatel, aplikace zobrazí přesný důvod konfliktu. Tlačítko pro načtení
aktuální verze obnoví také její nové číslo verze a teprve potom dovolí úpravu
uložit znovu.

## 90.9 Importy zaměstnanců a docházky

Stránka **Mzdy → Importy** leží v menu hned za Nastavením mezd a slouží
k převzetí dat při zavádění mezd i v běžném měsíci. Má čtyři záložky:
**JMHZ** (registrace i měsíční hlášení), **Docházka** (měsíční import), **Mapování sloupců**
(jednorázové nastavení) a **OIČ z POHODY** (doplnění identifikátorů ČSSZ). Importy pracují stejně: nahrajete soubory,
prohlédnete si náhled a teprve tlačítkem **Použít** se něco zapíše. Soubory se
na serveru neukládají, při použití se náhled spočítá znovu ze stejných souborů.

### 90.9.1 JMHZ: registrace a měsíční hlášení

Záložka načte XML registrací zaměstnanců pro ČSSZ, tedy přihlášky a oznámení
REGZEC a přihlášky před nástupem PREZEC, export zaměstnanců z ePortálu ČSSZ
i měsíční hlášení JMHZ. Soubory může
vytvořit i jiný mzdový program. Najednou lze nahrát víc souborů. Vyžaduje
oprávnění `payroll.person.write`.

Náhled ukáže každou větu zvlášť: druh akce, osobu, maskované rodné číslo,
nástup nebo skončení, stav spárování a navrženou operaci. Osoba se hledá podle
identifikátoru od ČSSZ (OIČ), rodného čísla a u pracovního vztahu podle ID
zaměstnání. Podle výsledku aplikace navrhne:

- **založení osoby** i s pracovním vztahem, identitou, adresou a údaji pro
  ČSSZ, u přihlášení k nástupu, který už nastal, vztah rovnou aktivuje,
- **nový pracovní vztah** u osoby, kterou už evidujete,
- **aktualizaci** údajů, které se liší (zdravotní pojišťovna, adresa, titul,
  místo narození, občanství, pracoviště, CZ-ISCO, druh činnosti); změnu jména
  aplikace jen oznámí, provedete ji na kartě osoby,
- **ukončení vztahu** u odhlášení, případně zápis „nenastoupil",
- **doplnění OIČ a ID zaměstnání**, pokud je věta obsahuje.

Věty, které nejde jednoznačně přiřadit, jsou označené a vybrat je nelze.
Před použitím potvrďte, že jste údaje porovnali s podáním, které ČSSZ přijala;
identifikátory se ukládají jako ověřený ruční opis. Opakovaný import téhož
souboru nic nezaloží podruhé.

**Export zaměstnanců z ePortálu ČSSZ.** Záložka přijme i soubor, který
stáhnete na ePortálu ČSSZ jako přehled zaměstnanců (kořen `ExportZamestnancu`).
V náhledu se zobrazí jako **Export zaměstnanců ČSSZ**. Každá věta nese jméno,
rodné číslo, OIČ, ID zaměstnání, druh činnosti, příznak zaměstnání malého
rozsahu a variabilní symbol zaměstnavatele. Datum nástupu v exportu není.

- U osoby, kterou už evidujete, import doplní chybějící OIČ a ID zaměstnání.
  Druh činnosti a druh vztahu jen porovná; nesoulad ohlásí varováním
  a podmínky vztahu nemění. Vztah, který je zatím jen naplánovaný, import
  aktivuje, protože ID zaměstnání v exportu dokládá přihlášení u ČSSZ.
  Nástupem je plánovaný nástup vztahu, a když chybí, datum z měsíčního
  hlášení v dávce. Vztah s nástupem v budoucnu zůstane naplánovaný.
- Osobu, kterou v evidenci nemáte, založí i s pracovním vztahem jen tehdy,
  když v téže dávce nahrajete měsíční hlášení JMHZ s formulářem stejného ID
  zaměstnání. Nástupem je nejdřívější datum nástupu z formulářů, a když ho
  formuláře nenesou, nejdřívější začátek pojištění v hlášeném měsíci. Vyjde-li
  nástup na první den nejstaršího nahraného měsíce, náhled upozorní, že
  pojištění mohlo začít dřív. Skutečný nástup pak ověřte podle smlouvy
  a případně ho opravte na kartě vztahu.
- Bez měsíčního hlášení je věta nové osoby zablokovaná. Nahrajte k exportu
  hlášení nebo přihlášku REGZEC.
- Variabilní symbol ve větě se porovná s variabilními symboly vašich mzdových
  účtáren. Když nesouhlasí, věta je zablokovaná jako export jiného
  zaměstnavatele. Pokud žádná účtárna variabilní symbol vyplněný nemá,
  kontrola se přeskočí.

Při použití se věty exportu zapíšou dřív než formuláře hlášení, takže se
formuláře k nově založeným vztahům spárují podle ID zaměstnání samy. Vyberte
proto v náhledu obojí najednou.

**Měsíční hlášení z předchozího mzdového programu.** Hlášení samo osobu
nezakládá, proto nejdřív naimportujte registrace nebo export zaměstnanců ČSSZ,
případně zaměstnance založte ručně. Náhled u každé věty ukáže období a spárovaný pracovní vztah.
Větu bez jednoznačné shody přiřadíte ručně výběrem vztahu. Náhled se pak
přepočítá. Z hlášení se převezme zdravotní pojišťovna, prohlášení
poplatníka, uplatňované slevy a vyživované děti podle období, ve kterém
platily. Opravné a stornovací podání se skládá s řádným podle pořadí.
Údaje pro ELDP a zdravotní pojištění náhled jen ukáže, import je nepřebírá.

Z historie hlášení aplikace navrhne:

- **počáteční stavy ročních součtů** (základy, zálohy, slevy a bonus po
  měsících), které potřebujete při přechodu z jiného programu během roku pro
  roční zúčtování a limity,
- **průměrné výdělky** po čtvrtletích, které se zakládají ke schválení
  v Nepřítomnostech.

Návrh, kterému chybí údaje nebo už je v evidenci, je označený a nepoužije se.
Obojí zapnete zaškrtnutím před tlačítkem **Použít**.

Aby firma se stovkami zaměstnanců nemusela nic potvrzovat po jednom, import
nabízí dvě volby automatického schválení. Obě jsou předem zapnuté a jde je
vypnout:

- **Rovnou odškrtnout povinnosti ke změnám.** Nová verze podmínek jinak na
  kartě vztahu založí úkoly (dodatek smlouvy, oznámení změny pojišťovně
  a ČSSZ). Změnu už vykázalo importované podání, proto je import odškrtne
  s poznámkou. Povinnosti při nástupu a skončení i starší rozpracované úkoly
  zůstávají.
- **Průměry rovnou schválit.** Bez této volby čekají založené průměry na
  schválení v Nepřítomnostech.

### 90.9.2 Docházka

Záložka převezme měsíční podklady z docházkového systému (například GIRITON)
nebo z tabulek, které z něj firma skládá. Obvykle jde o hlavní sešit se
seznamem zaměstnanců, provozní sešity s hodinami a CSV s osobními čísly.
Podporované formáty jsou XLSX (všechny listy) a CSV. Vyžaduje oprávnění
`payroll.inputs.write`.

Měsíční import má tři kroky:

1. **Období a soubory.** Zvolte měsíc a nahrajte všechny soubory najednou.
   Profil mapování se vybere sám podle toho, kolik sloupců nahraných souborů
   rozpozná; jiný profil můžete zvolit ručně. Pod výběrem se ukáže, který
   profil se použil, kolik sloupců rozpoznal a které sloupce s daty nezná.
   Odkaz **Upravit mapování** otevře záložku Mapování sloupců i s nahranými
   soubory.
2. **Osoby.** Osoby ze všech souborů se sloučí podle jména, osobní číslo a
   rodné číslo se k nim připojí. Tituly (i s překlepem jako „MqA.“) a poznámka
   v závorce jako „(DPP)“ nebo osobní číslo do jména nepatří, „(ml.)“ a „(st.)“
   ano. Jméno, které se od jiné osoby liší jen dalším jménem navíc (třeba druhým
   křestním), se s ní spojí a u osoby se to ohlásí. Každá osoba se spáruje
   s pracovním vztahem podle uložené vazby, rodného čísla, kódu vztahu nebo
   jména. Nejasné případy přiřaďte ručně, nejasnou osobu import nezakládá.
   Osoby, které v evidenci chybí, můžete samostatným tlačítkem založit; měsíční
   mzda z mzdového výměru v podkladech se přitom předvyplní jako pravidelná
   hrubá mzda vztahu a nástup z poznámky („nový nástup 15. 6. 2026“) jako den
   nástupu. Mají-li podklady sloupce s významem **Datum narození** a
   **Zdravotní pojišťovna (kód)**, založená osoba je dostane rovnou; kód
   pojišťovny je třímístný (například 111). Automatické založení při použití se týká jen osob s osobním nebo
   rodným číslem, pokud je podklady (typicky CSV mezd) obsahují. Osoba bez čísel
   bývá jinak zapsané jméno někoho z evidence, třeba po změně příjmení, proto
   zůstane k ruční volbě. Uvádějí-li podklady ukončení, spárovaná osoba dostane
   upozornění; import vztah neukončuje.
3. **Souhrn a použití.** Tabulka ukáže hodiny a částky každé osoby i s buňkou,
   ze které pocházejí, a pro kontrolu i hrubou a čistou mzdu z mzdového exportu.
   Chybí-li ve firmě mzdová složka, kterou profil používá, import ji na
   potvrzení založí jako jednorázovou složku.

**Kontrola období a dvojích vstupů.** Výchozí období je pracovní měsíc mezd,
ne měsíc podkladů. Import proto pozná měsíc z názvů souborů a listů
(„podklady 11-2025", list „Mzdy 11-25", soubor „1125.xlsx", „dochazka-2025-11")
a když se liší od vybraného období, upozorní na to hned nad náhledem. Souhrn
navíc ukáže, že období už má platné mzdové vstupy z jiného importu, třeba
z převodu mezd z POHODY / PAMICA nebo z jiné sady souborů; použitím by vznikly
dvojí vstupy. V obou případech se dávka použije, až nález výslovně potvrdíte.
Opakované použití týchž souborů se za jiný import nepovažuje.

**Měsíční mzda z podkladů.** Obsahují-li podklady měsíční mzdu (mzdový výměr),
souhrn ukáže tabulku **Měsíční mzda z podkladů**: původní mzdu ze sjednaných
podmínek vztahu, mzdu z podkladů a způsob zápisu. Chybějící mzda se doplní do
platné verze podmínek, jiná mzda založí novou verzi podmínek od prvního dne
importovaného období. Volba **Převzít měsíční mzdu z podkladů** je zapnutá,
jen když je co převzít. Mzda se nepřevezme u osoby, která v podkladech dostává
mzdu podle docházky (hodinovou nebo úkolovou složku), u ukončeného nebo
nenastoupeného vztahu, u vztahu, kde po začátku období platí novější verze
podmínek, a tam, kde by zápis zasáhl schválený, zaúčtovaný nebo vyplacený
mzdový běh. Důvod je vidět přímo u osoby v tabulce; mzdu takové osobě upravte
na kartě vztahu. Výsledek importu vypíše převzaté mzdy, mzdy, které se
nepřevzaly, a mzdové běhy k přepočtu s odkazem na ně. Běh počítá ze
zmrazeného snímku vstupů, novou mzdu proto vezme až nový snímek (viz
[Mzdové běhy](80_Mzdove_behy.md)).

**Srážky ze mzdy.** Sloupce s významem **Obědy – srážka ze mzdy** a **Srážka ze
mzdy** (ve vzoru GIRITON dotovaná cena obědů a srážky z hlavního seznamu) se
k hrubé mzdě nepřičítají. Volba **Založit srážky ze mzdy** z nich založí dohody
o srážce platné jen pro importovaný měsíc, které se strhnou z čisté mzdy.
Dohoda patří zaměstnanci, srážky z více jeho vztahů se sečtou. Opakovaný import
dohodu opraví, dokud se srážka nepoužila ve schválené mzdě; potom ji import
nemění a jen to ohlásí. Předpokládá se uzavřená dohoda o srážkách ze mzdy se
zaměstnancem.

**Verze vzoru.** Vzorový profil GIRITON dostane každá firma a s novou verzí
aplikace se sám aktualizuje, pokud jste ho neupravili. Upravený vzor se
nepřepíše: v Mapování sloupců nese štítek **Nová verze vzoru** a náhled
měsíčního importu na novou verzi upozorní s odkazem **Otevřít mapování**.
Tlačítko **Aktualizovat vzor** nahradí pravidla a složky profilu novou verzí a
uloží profil; vlastní úpravy se tím ztratí, proto si profil před aktualizací
případně duplikujte. Pravidla nové verze posílá server spolu s náhledem, takže
tlačítko je aktivní po načtení náhledu docházky nebo po zkoušce profilu na
souborech. Smazaný vzor vrátí tlačítko **Obnovit vzor GIRITON** nad seznamem
profilů; vzor vznikne v aktuální verzi a dál se sám aktualizuje.

Hodnoty se nikdy nesčítají napříč listy. Když stejný údaj přichází ze dvou
listů, použije se ten s vyšší prioritou a rozdílná hodnota se ukáže jako
konflikt. Opakuje-li se v jednom listu stejná hlavička, platí první sloupec.
Vzorce se nepřepočítávají, bere se hodnota uložená v sešitu. Prázdná buňka ani
chyba vzorce se nepovažují za nulu. Trvání delší než 24 hodin se převádí
správně. Náhled nad podklady pro stovky zaměstnanců trvá jednotky sekund.

**Pravidlo s podmínkou.** Pravidlo mapování může platit jen pro řádky, kde má
jiný sloupec téhož listu danou hodnotu, například *Odměny → úkolová mzda, když
oddělení = výroba*. Ostatní řádky dostanou další pravidlo pro tentýž sloupec.
Vzor GIRITON takto čte sloupec odměn: ve výrobě jako úkolovou mzdu, jinde jako
odměnu. Sloupec „Suma hodinovky NOC" výpočetního listu čte jako příplatek za
noční práci, ne jako druhou hodinovou mzdu.

**Zdanitelná část stravování.** Sloupec hlavního seznamu s hodnotou jídla nad
osvobozený limit (v sešitech GIRITON „Součet z Výpočet pro socku") se přenáší na
složku **Zdanitelná část stravování**. Je to nepeněžní příjem: zvyšuje hrubou
mzdu i vyměřovací základy na sociální a zdravotní pojištění, ale nevyplácí se.
Druhá strana téhož plnění, srážka za obědy, jde z čisté mzdy a zůstává
samostatně, takže se nic nepočítá dvakrát. V měsíčním hlášení má složka výchozí
zařazení **10328 úhrn zúčtované mzdy**: plnění je součástí hrubé mzdy a obou
vyměřovacích základů, a detailní uzel, který by nepeněžnímu stravování odpovídal
přesněji, číselník cílů nenabízí. Bez zařazení by přitom nešlo zmrazit měsíční
hlášení.

Použitím vznikne dávka importu s měsíčním souhrnem hodin po pracovních
vztazích a s původem každé hodnoty. Peněžní částky se volitelně založí jako
**návrhy mzdových vstupů** stejnou cestou jako import vstupů v kapitole
[Mzdové složky a vstupy](91_Mzdove_slozky_a_vstupy.md); schvalují se běžně před
výpočtem běhu. Opakovaný import téhož souboru vrátí existující dávku. Opravený
soubor nevytvoří druhou odměnu, protože vstup nese stálý identifikátor osoby,
měsíce a složky; původní návrh je nutné nejdřív smazat.

**Porovnání s výpočtem mezd.** Nese-li dávka hrubou a čistou mzdu z mzdového
exportu (například CSV z předchozího mzdového programu), tlačítko **Porovnat
s výpočtem mezd** v historii importů ji po výpočtu mzdového běhu téhož období
porovná s výsledkem výpočtu po osobách. Rozdíl do 1 Kč se bere jako
zaokrouhlení; osoby s rozdílem a bez výpočtu jsou nahoře. U osoby s více
pracovními vztahy se porovná jen hrubá mzda, čistou mzdu výpočet vede za osobu.

Import hodin nevytváří záznamy docházky ani absence s konkrétními dny. Se
zápisem souhrnu docházky lze zapnout výpočet náhrad mzdy z hodin: z hodin
dovolené, lékaře a překážek na straně zaměstnavatele vzniknou návrhy vstupů
náhrady mzdy podle schváleného průměrného výdělku (dovolená a lékař 100 %,
překážka na straně zaměstnavatele podle sazby v pravidle profilu mapování,
bez zadání 80 %; nižší sazba, nejméně 60 %, jen při nepříznivém počasí podle
§ 207 písm. b) nebo částečné nezaměstnanosti podle § 209) a měsíční mzda v rychlém vstupu se
o hodiny nepřítomnosti zkrátí. Bez schváleného průměru se náhrada nezaloží.
Náhradu mzdy při nemoci z měsíčního součtu spočítat nejde (rozhoduje prvních
14 kalendářních dní a konkrétní dny), nemoc a další nepřítomnost s daty proto
zadejte v kapitole [Absence a dovolená](76_Absence_a_dovolena.md).

### 90.9.3 OIČ z POHODY

Záložka doplní osobní identifikační číslo od ČSSZ (OIČ, také IK MPSV) osobám,
které už v mzdové evidenci jsou. Zdrojem je export **Tabulka agendy
Personalistika** z programu POHODA ve formátu XLSX, případně CSV se stejnými
sloupci: Příjmení, Jméno, Rodné číslo, Osobní číslo a OIC. Řádek hlavičky
aplikace najde sama, blok s názvem agendy a firmy nad ním nevadí. Liší-li se IČ
v exportu od IČ firmy, náhled na to upozorní. Vyžaduje oprávnění
`payroll.person.write` a `payroll.employment.write`.

Náhled hledá osobu podle rodného čísla, které ukáže jen maskované, a zkontroluje,
že OIČ má 10 číslic se správnou kontrolní číslicí. U každého řádku uvede stav:

- **Připraveno**: OIČ se zapíše k pracovnímu vztahu, který platí dnes, jinak
  k poslednímu vztahu, s platností ode dne nástupu.
- **Už uloženo**: osoba má stejné OIČ, není co zapisovat.
- **Jiné OIČ v evidenci**: import uložené číslo nikdy nepřepíše. Správné číslo
  ověřte a případnou opravu udělejte na kartě pracovního vztahu.
- **OIČ má jiná osoba**, osoba nenalezena nebo nejednoznačná, osoba bez
  pracovního vztahu, duplicitní řádek a neplatné rodné číslo či OIČ: řádek nejde
  vybrat a důvod je uvedený přímo u něj.

Řádky bez OIČ se přeskočí, náhled ukáže jen jejich počet. POHODA není protokol
ČSSZ, proto před zápisem potvrďte, že jste čísla ověřili proti ePortálu ČSSZ
nebo registracím. OIČ se uloží jako ověřený ruční opis ke zvolenému prostředí
(ostré nebo testovací), stejně jako při zadání na kartě vztahu. Opakovaný
import téhož souboru nic nezapíše podruhé.
