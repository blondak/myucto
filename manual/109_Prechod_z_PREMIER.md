# 109. Přechod z PREMIER

**Cesta: `Systém → Přechod z PREMIER`**

Průvodce převede vybrané účetní roky z programu PREMIER do firmy v MyÚčtu. Vstupem je
záloha dat, kterou vytvoříte přímo v PREMIERu. Na rozdíl od POHODY tu není
samostatný exportní nástroj ke stažení.

Průvodce převádí **účetnictví** a k němu **zaměstnance a zpracované mzdy**
(§ 109.2.1). Mzdové zápisy jsou v převedeném deníku, mzdy se proto
převezmou jako evidence předchozího systému a žádný účetní zápis nezaloží.

Položka je v menu Systém, které vidí administrátor. Jiný uživatel s potřebnými
oprávněními otevře průvodce přímým odkazem `/imports/premier`.

Průvodce vidí a zkoušku nanečisto spouští uživatel s oprávněním
`utilities.import` pro zápis. Ostrý převod zapisuje účetní deník a mění
nastavení firmy, proto navíc vyžaduje zápis do účetního deníku
(`accounting.journal.write`) a do nastavení firmy (`settings.company.write`).
Chybějící oprávnění průvodce ukáže a převod nespustí.

Průvodce je dostupný i firmě, která zatím vede daňovou evidenci: převod ji sám
přepne do podvojného účetnictví od začátku převáděného roku.

## 109.1 Záloha z PREMIER

### 109.1.1 Vytvoření zálohy

1. V PREMIERu otevřete **Správce → Záloha dat** (klávesa **F11**).
2. Zálohu uložte na disk. PREMIER nabízí formát **iZIP** nebo **iCAB**,
   průvodci vyhovuje kterýkoli z nich.
3. Vzniklý soubor nahrajte do průvodce beze změny.

### 109.1.2 Co je v záloze

- Záloha obsahuje **všechny účetní roky** vedené v PREMIERu, ne jen jeden.
  Průvodce v ní najde všechny roky a k převodu nabídne ty, které podle IČO
  patří firmě v MyÚčtu.
- Firma musí v MyÚčtu existovat a mít vyplněné stejné IČO jako v PREMIERu.
  Převádí se do firmy, ve které právě pracujete; novou firmu nejdřív založte
  (kapitola [Multi supplier](95_Multi_supplier.md)).
- Nahraná záloha zůstává na serveru pro převod dalších let. Aplikace ji smaže
  po 7 dnech, kdy se s ní nepracovalo.
- Soubor může mít až 2 GB. Průvodce ho posílá po částech a ukazuje průběh
  v procentech; při výpadku spojení část zopakuje a naváže tam, kde server
  data má. Stránku nechte během nahrávání otevřenou.

### 109.1.3 Pořadí let

Záloha nese celé účetnictví najednou a převádějí se roky, které zaškrtnete
v náhledu (jeden i víc najednou). Vybrané roky převod projde **vzestupně od
nejstaršího**, každý s vlastním protokolem. Nevynechávejte nepřevedený starší
rok. PREMIER
počáteční ani uzávěrkové zápisy do deníku neukládá, převod proto počáteční
stavy roku spočte z deníku všech předchozích let v záloze: zůstatky
rozvahových účtů a výsledek hospodaření minulých let na účet 431. Doklady
předchozích let ale převede jen převod těch let, a proto se vyplatí začít
nejstarším. Průvodce v přehledu předvybere všechny roky zálohy s IČO firmy.
Další rok převedete i později zopakováním postupu (§ 109.4).

## 109.2 Co převod přenese

| Z PREMIER | Do MyÚčta |
|---|---|
| účtová osnova (jen účty, na které se účtovalo) | analytiky pod syntetiky osnovy (`518100` → `518.100`) včetně daňové uznatelnosti účtu |
| účetní rok | účetní období 1. 1. až 31. 12. |
| počáteční stavy spočtené z předchozích let | otevírací zápis k 1. dni období (účty proti 701, výsledek na 431) |
| účetní deník | účetní zápisy, přesná kopie |
| adresář partnerů | klienti, párování podle IČO |
| přijaté a vydané faktury a zálohové listy včetně položek | doklady se stavem zaúčtováno nebo uhrazeno; doklad nejisté daňové povahy jako koncept k ruční kontrole |
| pokladní doklady z deníku | pokladní doklady, u tuzemského kódu DPH i s řádky DPH |
| ostatní doklady s DPH mimo faktury (bankovní poplatky, interní doklady) | přijaté nebo vydané doklady s položkami po kódech DPH |
| bankovní řady deníku | výpisy a bankovní pohyby v měně účtu |
| vazby úhrad na faktury | spárování faktury s bankovním pohybem nebo pokladním dokladem |
| dlouhodobý majetek (řady hmotného a nehmotného majetku) | karty s daňovými a účetními odpisy let převodu |
| drobný majetek (řady drobného majetku, operativní evidence) | karty evidence drobného majetku |
| zaměstnanci a pracovní vztahy (pracovní poměr, DPP, DPČ, jednatel) | osoby a pracovní vztahy v modulu Mzdy |
| zpracované mzdy po měsících | převzaté mzdy předchozího systému, bez účetních zápisů |
| ruční úpravy základu daně z přiznání k DPPO | položky rozpracovaného přiznání k DPPO |
| uzavřený rok | uzávěrka roku v MyÚčtu (702/710) a navazující počáteční stavy |

**Částky v Kč.** Faktura v cizí měně se převede v Kč podle zaúčtování
v deníku, stejně jako z deníku počítá přiznání PREMIER. Položky faktury se
přepočtou kurzem dokladu a haléřový rozdíl dorovná největší položka.

**Klasifikace DPH.** PREMIER vede u každé položky dokladu kód DPH a jeho
definici v číselníku kódů: řádky přiznání a oddíl kontrolního hlášení. Převod
položku klasifikuje **podle definice kódu**, ne podle jeho čísla, účtu ani
textu dokladu. Platí to i pro samovyměření u přijatých plnění (pořízení
zboží a služby z EU, služby ze třetích zemí, dovoz, tuzemský přenos
daňové povinnosti): položka dostane nulovou daň a kód zařazení a daň na
výstupu i odpočet dopočte evidence DPH MyÚčta. Nezáleží na tom, jestli
účetní samovyměření zaúčtovala na účet 343. Vydaná položka s kódem mimo
přiznání, která nese daň, je plnění v režimu OSS a posoudí ji stejné
pravidlo jako ostatní importy.

**Období odpočtu.** Datum pro DPH a datum pro kontrolní hlášení
z přijaté faktury převod přebírá. Pokud PREMIER uplatnil odpočet dřív, než
je datum plnění nebo vystavení dokladu, MyÚčto ho tak brzy nepřipustí a
doklad zařadí do období podle data dokladu. Protokol takový doklad vypíše.

**Majetek.** PREMIER vede karty majetku v řadách podle druhu evidence. Karty
řad hmotného a nehmotného majetku se převedou jako dlouhodobý majetek
s odpisy. Karty řad drobného neodpisovaného majetku a operativní evidence
ostatního majetku se převedou do evidence drobného majetku (název,
inventární číslo, datum pořízení, cena, umístění, odpovědná osoba,
vyřazení). Evidence finančního majetku, leasingu, rezerv a ostatní
evidence se nepřevádí, účetně je v převedeném deníku a protokol ji vypíše.

**Drobný majetek bez evidence v PREMIERu.** Když účetní drobný majetek
v PREMIERu jako evidenci nevedla a účtovala ho jen do nákladů, převod karty
odvodí z přijatých faktur: položka zaúčtovaná na účet, který osnova
PREMIERu pojmenovává jako drobný majetek (například „Spotřeba materiálu -
dr. majetek"), s cenou za kus od 1 000 Kč bez DPH dostane kartu drobného
majetku, levnější zůstane materiálem. Dobropis, který věc vrací, kartu
vyřadí, pokud jde jednoznačně určit (stejný dodavatel a název, případně
cena). Jinak ho protokol vypíše k ručnímu vyřazení.

**Zaúčtování se nepřepočítává.** Deník je přesná kopie toho, co bylo
v PREMIERu, a doklady se k němu jen připojí. Zápisy na 702 a 710 se
nepřebírají, rok uzavře průvodce uzávěrkou MyÚčta (§ 109.6).

**Doklady k ruční kontrole.** Doklad, jehož daňovou povahu záloha spolehlivě
neurčuje (například kód opravy podle § 44 nebo § 74), převod převezme jako
koncept. Koncept nevstoupí do přiznání k DPH, kontrolního hlášení ani do
účtování. Protokol ho vypíše i s důvodem. Po opravě klasifikace DPH ho
potvrďte.

Číslo dokladu, které už ve firmě je, dostane příponu roku.

### 109.2.1 Zaměstnanci a mzdy

Mzdy převod přenese jen firmě, která má zapnutý modul Mzdy a v Mzdy →
Nastavení nastavenou výchozí mzdovou účtárnu. Bez toho převede účetnictví,
mzdy přeskočí a protokol to řekne; po nastavení mezd převod roku zopakujte
a mzdy se doplní.

- **Zaměstnanci.** Každý pracovní vztah z PREMIERu se založí jako osoba
  a pracovní vztah s osobním číslem z PREMIERu: jméno, rodné číslo, datum
  narození, adresa, zdravotní pojišťovna, výplatní účet, druh vztahu
  (pracovní poměr, DPP, DPČ, jednatel), nástup, skončení a sjednaná mzda
  včetně jejích změn. V zákonné evidenci osoby doplní daňovou rezidenci,
  prohlášení poplatníka po měsících a příslušnost k sociálnímu pojištění.
  Doplňuje se jen to, co v MyÚčtu chybí. Vztah se stejným osobním číslem
  a jménem, který ve firmě už je, převod převezme místo založení nového.
- **Zpracované mzdy.** Každý měsíc do konce převáděného roku se uloží jako
  převzatá mzda předchozího systému: hrubý příjem, vyměřovací základy,
  pojistné zaměstnance i zaměstnavatele, záloha a srážková daň, daňový bonus,
  čistá mzda, částka k výplatě a doby pojištění. Z nich vznikne převzatý
  mzdový běh, evidenční list důchodového pojištění za rok přechodu
  a srovnávací sestava převzatých mezd. Měsíce od začátku vedení mezd
  v MyÚčtu se nepřebírají, ty počítá MyÚčto.
- **Počáteční stavy ročních kumulací.** Za měsíce roku, ve kterém začíná
  vedení mezd v MyÚčtu, před jeho prvním měsícem převod zapíše počáteční
  stavy kumulací (roční zúčtování daně a potvrzení o zdanitelných příjmech
  na ně navážou). Začátek vedení mezd nastavte v Mzdy → Nastavení ještě před
  převodem posledního roku; když chybí, protokol navrhne měsíc po poslední
  mzdě z PREMIERu.

Zkontrolujte po převodu:

- druh vztahu u zaměstnanců, u kterých ho protokol označil jako odvozený,
- výplatní účty: převod je založí jako neověřené, ověřte je na kartě osoby,
- mzdové složky, pravidelné předpisy a průměrný výdělek pro první měsíc
  vedený v MyÚčtu,
- upozornění rekonciliace mezd proti deníku (§ 109.5).

## 109.3 Co převod nepřenese

- **Docházka a podrobnosti mezd.** Mzdové složky jednotlivých měsíců,
  nepřítomnosti, dovolená, průměrné výdělky, srážky ze mzdy, exekuce a děti
  pro daňové zvýhodnění se nepřevádějí, zadejte je v modulu Mzdy.
- **Sklad, zakázky a CRM.** Zápisy jsou v převedeném deníku, evidence se
  zakládá v MyÚčtu.
- **Objednávky, nabídky a přílohy dokladů.** Skeny dokladů připojíte zvlášť
  v `Dokumenty → Skeny k dokladům`.
- **Podaná přiznání a hlášení.** Zůstávají v PREMIERu, proti nim ale
  probíhá kontrola, viz § 109.5.

## 109.4 Postup

1. **Záloha z PREMIER.** Vytvořte zálohu (109.1) a nahrajte soubor `.izip`
   nebo `.icab`. Rozbalení a načtení běží na serveru na pozadí, u velké
   zálohy i několik minut; obnovení stránky mezitím průvodce nepřeruší.
2. **Náhled a volby.** Tabulka ukáže roky nalezené v záloze s IČO firmy
   a počtem zápisů deníku. Roky k převodu zaškrtněte v prvním sloupci
   tabulky; předvybrané jsou všechny (§ 109.1.3).

   Kontrola před převodem se ukáže pro každý vybraný rok zvlášť. Převod
   zastaví, když u kteréhokoli vybraného roku:
   - záloha patří firmě s jiným IČO,
   - v záloze chybí deník, osnova nebo číselník kódů DPH,
   - účetní období v MyÚčtu už obsahuje zápisy, které nevznikly převodem,
   - období je v MyÚčtu uzavřené.
3. **Zkouška nanečisto.** Proběhne celý převod vybraných roků včetně
   rekonciliace a kontroly proti podáním, na konci se ale všechno vrátí.
   Výsledkem je protokol za každý rok; v MyÚčtu nic nezůstane a nastavení
   automatiky se nezmění. Každý rok se zkouší samostatně a hned po své
   zkoušce se vrátí, pozdější rok proto ve zkoušce nevidí data předchozího
   roku (převzaté doklady, uzávěrku) a jeho výsledek se od ostrého převodu
   může lišit. Zkouška běží v databázové transakci, spouštějte ji proto mimo
   běžnou práci ve firmě.
4. **Ostrý převod.** Potvrzení vyjmenuje převáděné roky. Převod běží na
   pozadí, stránku můžete zavřít. Roky se převádějí vzestupně jeden po druhém
   a průběh ukazuje, kolikátý rok z kolika právě běží. Skončí-li rok chybou
   nebo převod zrušíte, další roky se nespustí a průvodce je vypíše. Po
   dokončení průvodce ukáže protokoly všech převedených roků a nabídne účetní
   deník a obratovou předvahu. Převod jedné firmy běží vždy jen jeden, druhý
   se do jeho konce nespustí.

## 109.5 Rekonciliace, kontrola a protokol

Každý převáděný rok (ve zkoušce i v převodu) má vlastní běh a protokol s kroky převodu, počty,
upozorněními a chybami a rekonciliací převáděného roku, obdobně jako
u přechodu z POHODY, viz [§ 107.5](107_Prechod_z_POHODY.md#1075-rekonciliace-a-protokol).
Obratová předvaha MyÚčta se porovná s předvahou spočtenou přímo z deníku
PREMIER na haléř, včetně počátečních stavů.

**Úpravy základu daně.** Výsledek hospodaření, odpisy a nedaňové účty spočte
MyÚčto z převedených dat samo. Ruční úpravy, které účetní zadala do přiznání
k DPPO v PREMIERu (například paušální výdaj na dopravu, příjmy osvobozené,
ztráta minulých let, zaplacené zálohy), převod zapíše jako položky
rozpracovaného přiznání k DPPO s odkazem na řádek PREMIERu. Přiznání, které
už ve firmě je, nemění.

**Kontrola proti podáním z PREMIER.** Kontrolní hlášení DPH za každý měsíc
a přiznání k DPPO spočtené v MyÚčtu z převedených dat se porovnají s podáními,
která má PREMIER uložená v záloze (u KH vždy s posledním podáním měsíce).
Rozdíl protokol vypíše jako upozornění, převod kvůli němu neselže. Typické
příčiny:

- doklad upravený v PREMIERu až po podání (podání neodpovídá aktuálním datům),
- kód DPH, který PREMIER podle vlastního nastavení do kontrolního hlášení
  nezahrnul, přestože tam podle zákona patří (například služba od
  dodavatele ze třetí země v oddílu A.2),
- odpočet, který PREMIER uplatnil dřív, než MyÚčto připustí (§ 109.2),
- zaokrouhlení částek přiznání k DPPO: PREMIER zaokrouhluje na koruny
  nahoru, MyÚčto matematicky. Rozdíl do 1 Kč protokol neoznačí jako
  neshodu, daň vychází stejně.

Přiznání k DPH PREMIER v záloze neukládá, proto se s ním nekontroluje.

**Mzdy proti deníku.** Zpracované mzdy převáděného roku se po měsících
porovnají se zaúčtováním v deníku: hrubé příjmy (náklad 52x proti účtům 331,
333 a 366), pojistné zaměstnance (proti 336), pojistné zaměstnavatele
(náklad proti 336) a daň (proti 342, snížená o daňový bonus). Měsíc, který
se liší, protokol vypíše i s částkami jako upozornění. Typicky jde o mzdu
zpracovanou, ale ještě nezaúčtovanou, nebo o mzdu přepočtenou v PREMIERu po
zaúčtování. Rekonciliace mezd běží i u firmy bez modulu Mzdy.

## 109.6 Režim účetnictví a automatika

Chování je stejné jako u přechodu z POHODY: převod zapíše podvojné
účetnictví od začátku převáděného roku, automatika účtování je během
převodu vypnutá a po úspěšném převodu se vrátí do stavu před ním, viz
[§ 107.6](107_Prechod_z_POHODY.md#1076-rezim-ucetnictvi-a-automatika).

Odpisy majetku, které PREMIER v převedeném roce zaúčtoval, jsou v převedeném
deníku. Hromadné zaúčtování odpisů v uzávěrce je proto pro převedené roky
znovu neúčtuje a plán odpisů naváže dalším měsícem.

### 109.6.1 Uzávěrka uzavřených roků

PREMIER uzávěrkové zápisy do deníku neukládá. Rok, který je v PREMIERu
uzavřený, převod uzavře i v MyÚčtu průvodcem uzávěrky. Za uzavřený se
považuje rok, ke kterému záloha obsahuje podané přiznání k dani z příjmů
právnických osob, nebo rok celý zamčený v PREMIERu v „Zamykání period".

- Kurzové rozdíly a odpisy se spustí, ale nesmějí nic zaúčtovat: převzatý
  deník je už obsahuje.
- Dohadné položky, časové rozlišení, opravné položky a daň z příjmů se
  potvrdí jako zaúčtované v PREMIERu.
- Uzavření knih zaúčtuje zápis na 702 a 710 a otevření dalšího roku převezme
  počáteční stavy z převodu (musí sedět účet po účtu).

Uzávěrka proběhne jen tehdy, když převod roku skončil bez chyb a předchozí
rok je uzavřený. Když by musela zaúčtovat cokoli navíc nebo něco nesouhlasí,
celá se vrátí, rok zůstane otevřený a protokol řekne proč. Uzavřete ho pak
ručně v Účetnictví → Uzávěrka. Rok, který v PREMIERu uzavřený není (typicky
běžný rok), zůstává otevřený.

## 109.7 Opakovaný převod

Převod si pamatuje, co z které zálohy už vzniklo. Opakovaný převod téže nebo
novější zálohy založí jen to, co ještě chybí, a nic nezdvojí. Převod
přerušený chybou tak stačí po opravě spustit znovu. Takhle se převádí i další
rok: ve stejné záloze zaškrtněte další rok v pořadí.

## 109.8 Omezení

- Převádí se kalendářní účetní rok, období se vždy založí od 1. 1. do 31. 12.
- Převod čte jen nahranou zálohu, nikdy živou databázi PREMIER.
- Jeden běh převede vybrané roky jedné firmy, každý rok s vlastním
  protokolem. Záloha musí mít IČO firmy
  v MyÚčtu.
- Záloha ve formátu iCAB musí být jeden soubor s kompresí MSZIP, jak ji
  PREMIER ukládá. Jiný archiv CAB uložte v PREMIERu jako iZIP.
