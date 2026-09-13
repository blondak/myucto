# Nastavení mezd

## 76.1 Účel

Nastavení mezd obsahuje údaje zaměstnavatele, výchozí účty, termíny, institucionální identifikátory a předkontace používané napříč mzdovým tokem.

## 76.2 Předpoklady a oprávnění

Je nutné oprávnění `payroll.settings`. Připravte ověřené identifikační údaje, symboly ČSSZ a zdravotních pojišťoven, bankovní účty a schválený účtový rozvrh. ISDS se nastavuje samostatně v obecném nastavení firmy.

## 76.3 Krokový postup

1. Otevřete **Mzdy → Nastavení mezd**.
2. Vyplňte identifikaci zaměstnavatele a údaje pro dokumenty a podání.
3. Nastavte účty a splatnosti pro mzdy, daň, sociální a zdravotní pojištění.
4. Doplňte přidělené identifikátory institucí.
5. Nastavte výchozí předkontace a zkontrolujte jejich existenci v účtovém rozvrhu.
6. Pro podání vytvořte oddělené TEST a produkční profily; certifikát uložte jen do určeného bezpečného úložiště.

## 76.4 Stavy

Rozpracované nastavení lze uložit, ale navazující krok může být blokován. Validační chyba označuje neúplný nebo neplatný údaj. Úspěšné uložení nepotvrzuje, že identifikátor či účet uznala externí instituce.

Po dokončení základního nastavení se mzdový modul automaticky označí jako
aktivní. Zákazník nedokládá paralelní měsíce, opravný běh, obnovu ze zálohy ani
kvalifikační protokol.

Dokud MyÚčto nedokončí interní ověření produktu, zůstávají ostrá podání a
mzdové platební příkazy globálně blokované. Výpočty a testovací podání fungují
a na přehledu mezd se zobrazuje informační upozornění. Na straně firmy není
potřeba tuto interní bránu odemykat. Podrobný popis je v
[úvodní kapitole mezd](61_Uplne_mzdy.md#6127-7-dokoncete-nastaveni-firmy).

## 76.5 Kontroly a bezpečnost

Ověřte každou hodnotu proti oficiálnímu zdroji a správné firmě. Privátní klíče, hesla a SMS kódy nepatří do poznámek ani příloh. Testovací certifikát ČSSZ používejte jen v TEST profilu a produkční konfiguraci ověřte samostatně.

## 76.6 Časté chyby

- Identifikátor nebo účet zkopírovaný z jiné firmy.
- Záměna TEST a produkčního prostředí.
- Chybějící předkontace blokující účetní krok.
- Domněnka, že nastavení ISDS automaticky odesílá podání nebo načítá inbox.

## 76.7 Návaznosti

Osoby a vztahy založíte v [kapitole 58k](72_Zamestnanci.md), složky v [58p](77_Mzdove_slozky_a_vstupy.md), účetní kontrolu v [58f](67_Shoda_uctovani_mezd.md) a elektronické odeslání v [58j](71_Podani_a_hlaseni.md).



## 76.8 Podrobný pracovní postup a kontroly

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

### 76.8.1 Předkontace pro zvláštní mzdové situace

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

## 76.9 Importy zaměstnanců a docházky

Stránka **Mzdy → Importy** leží v menu hned za Nastavením mezd a slouží
k převzetí dat při zavádění mezd i v běžném měsíci. Má tři záložky:
**JMHZ** (registrace i měsíční hlášení), **Docházka** (měsíční import) a **Mapování sloupců**
(jednorázové nastavení). Importy pracují stejně: nahrajete soubory,
prohlédnete si náhled a teprve tlačítkem **Použít** se něco zapíše. Soubory se
na serveru neukládají, při použití se náhled spočítá znovu ze stejných souborů.

### 76.9.1 JMHZ: registrace a měsíční hlášení

Záložka načte XML registrací zaměstnanců pro ČSSZ, tedy přihlášky a oznámení
REGZEC a přihlášky před nástupem PREZEC, i měsíční hlášení JMHZ. Soubory může
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

**Měsíční hlášení z předchozího mzdového programu.** Hlášení samo osobu
nezakládá, proto nejdřív naimportujte registrace, případně zaměstnance
založte ručně. Náhled u každé věty ukáže období a spárovaný pracovní vztah.
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

### 76.9.2 Docházka

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
   rodné číslo se k nim připojí. Každá se spáruje s pracovním vztahem podle
   uložené vazby, rodného čísla, kódu vztahu nebo jména. Nejasné případy
   přiřaďte ručně. Osoby, které v evidenci chybí, můžete samostatným tlačítkem
   založit; měsíční mzda z mzdového výměru v podkladech se přitom předvyplní
   jako pravidelná hrubá mzda vztahu.
3. **Souhrn a použití.** Tabulka ukáže hodiny a částky každé osoby i s buňkou,
   ze které pocházejí, a pro kontrolu i hrubou a čistou mzdu z mzdového exportu.
   Chybí-li ve firmě mzdová složka, kterou profil používá, import ji na
   potvrzení založí jako jednorázovou složku.

Hodnoty se nikdy nesčítají napříč listy. Když stejný údaj přichází ze dvou
listů, použije se ten s vyšší prioritou a rozdílná hodnota se ukáže jako
konflikt. Opakuje-li se v jednom listu stejná hlavička, platí první sloupec.
Vzorce se nepřepočítávají, bere se hodnota uložená v sešitu. Prázdná buňka ani
chyba vzorce se nepovažují za nulu. Trvání delší než 24 hodin se převádí
správně. Náhled nad podklady pro stovky zaměstnanců trvá jednotky sekund.

Použitím vznikne dávka importu s měsíčním souhrnem hodin po pracovních
vztazích a s původem každé hodnoty. Peněžní částky se volitelně založí jako
**návrhy mzdových vstupů** stejnou cestou jako import vstupů v kapitole
[Mzdové složky a vstupy](77_Mzdove_slozky_a_vstupy.md); schvalují se běžně před
výpočtem běhu. Opakovaný import téhož souboru vrátí existující dávku. Opravený
soubor nevytvoří druhou odměnu, protože vstup nese stálý identifikátor osoby,
měsíce a složky; původní návrh je nutné nejdřív smazat.

Import hodin nevytváří záznamy docházky ani absence s konkrétními dny. Dovolenou,
nemoc a další nepřítomnost s daty zadejte v kapitole
[Absence a dovolená](62_Absence_a_dovolena.md), jinak je výpočet běhu nezahrne.
