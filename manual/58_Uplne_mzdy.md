# 58. Úplné mzdy — aktivace, zaměstnavatel a zaměstnanci

**Cesta: `Mzdy`**

Samostatná sekce Mzdy rozšiřuje zaměstnance z existující Mzdové rekapitulace
o personální profil a více pracovních vztahů. Nevytváří druhý seznam lidí a
nemění dosavadní Mzdovou rekapitulaci.

> [!IMPORTANT]
> **Modul je funkčně hotový, ale zatím ve zkušebním provozu — ostré spuštění je
> plánované na září 2026.** Agenda je kompletní: aktivace, nastavení
> zaměstnavatele a mzdových účtáren, osobní karty, pracovní vztahy, docházka,
> absence a dovolená, mzdové složky a vstupy, řízený mzdový běh, srážky
> a exekuce, platby mezd i odvodů, účetní můstek, dokumenty a archiv výstupů.
> Zákonné výpočty sociálního a zdravotního pojištění, daně a čisté mzdy jsou
> napojené do neměnné revize.
>
> **Do ostrého spuštění ale výsledek, odvody, dokumenty i podání vždy ověř proti
> jinému důvěryhodnému zdroji** a nepoužívej modul jako jediný podklad pro
> výplatu nebo zákonné podání. Neúplný či nepodporovaný scénář systém zastaví
> v ruční kontrole a **zákonná podání se zatím jen připravují a stahují, aplikace
> je neodesílá** ([§ 58.15](#5815-podani-a-hlaseni)). Pro zaúčtování zůstává
> k dispozici [Mzdová rekapitulace](57_Mzdy.md).

## 58.1 Zapnutí pro firmu

V **Firma → Nastavení** je přepínač **Vést mzdy**. Je ve výchozím stavu vypnutý
a není navázaný na samostatnou licenci. Do ostrého spuštění ho v aplikaci
doprovází výrazné upozornění, že modul je ve zkušebním provozu a jeho výstupy
je nutné ověřovat. Je-li přepínač vypnutý, sekce Mzdy se skryje z menu a její
přímé adresy nejsou dostupné. Zapnutí neznamená potvrzení správnosti výpočtů.

Na přehledu mezd zvolíš první měsíc, od kterého má firma používat úplný mzdový
modul. Rok musí být uvedený v matici podporovaného rozsahu; nemusí jít o budoucí
měsíc.
Po aktivaci slouží horní část přehledu jako pracovní rozcestník **Tento měsíc**:
vede přímo na měsíční zadání mezd a odměn, mzdový běh, zaměstnance, platby
a dokumenty. Technický rozsah podporovaných scénářů zůstává dostupný ve sbalené
diagnostické části. Starší měsíce mohou zůstat v Mzdové rekapitulaci. Jeden
měsíc však nelze zpracovat současně oběma cestami.

Rozpracovanou aktivaci lze zrušit, dokud je pouze ve stavu nastavení. Aktivní
začátek se nezruší obyčejným přepínačem, aby nezmizely vazby na uzavřené mzdy,
platby, dokumenty nebo podání.

## 58.2 Podporovaný rozsah

Přehled mezd ukazuje podporované roky a schopnosti modulu. Stav má tento význam:

- **Podporováno** — funkce má implementované kontroly pro uvedený rozsah;
- **Ruční kontrola** — systém připraví podklady, ale výsledek vyžaduje odborné
  posouzení;
- **Nepodporováno** — funkci nelze použít pro ostrý mzdový běh.

Označení na přehledu je bezpečnostní hranice. Modul nesmí chybějící pravidlo
nahradit nejbližším rokem nebo odhadem. Stav **Podporováno** popisuje technicky
pokrytý scénář, ale neruší povinnost ověřovat výstupy do ostrého spuštění.

## 58.3 Nastavení zaměstnavatele

V **Mzdy → Nastavení zaměstnavatele** se evidují registrační a kontaktní údaje
pro mzdovou agendu. Stránka používá čtyři samostatné záložky:
**Zaměstnavatel a účtárny**, **Účty institucí**, **Automatické účtování** a
**Politiky a připravenost**. Firma může mít více mzdových účtáren, ale právě jedna
aktivní účtárna musí být označena jako výchozí. Každá účtárna má vlastní kód,
název a vlastní variabilní symbol pro platby sociálního pojištění. Pole
**Registrační číslo zaměstnavatele** slouží pro evidenci a podání; není
variabilním symbolem platby.

V části **Platební účty institucí** se evidují účty ČSSZ, finančního úřadu,
zdravotních pojišťoven, zákonného pojištění a dalších příjemců. Pro každý účet
vyber typ instituce a ulož zaměstnavatelský variabilní symbol, měnu, období
platnosti, druh ověřovacího zdroje, jeho referenci a datum ověření. V seznamu se
bankovní účet zobrazuje jen maskovaně. Změnu samotného účtu, typu nebo kódu
instituce či začátku platnosti založ jako nový historický záznam; u existujícího
záznamu lze bezpečně upravit název, platební symboly, konec platnosti a údaje
o ověření. Období stejné instituce a měny se nesmějí překrývat.

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

V záložce **Politiky a připravenost** se vede časová historie výplatního dne,
pravidla posunu na pracovní den, zaokrouhlení doplatku, kontroly čtyř očí,
automatických kroků a bezpečného doručení. Období dvou politik se nesmějí
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

Kontrola připravenosti se spouští k vybranému dni. Ukazuje každý ověřený
předpoklad i přesný blokující nedostatek. Kontrolují se jen funkce, které firma
skutečně zapnula; zapnutá automatizace, JMHZ nebo bezpečné doručení však bez
pozitivního důkazu zůstávají zablokované. Přepínač **Vést mzdy** je nadále jen
v obecném Nastavení firmy a na této stránce se neduplikuje.

Změny se ukládají s kontrolou souběžné editace. Pokud mezitím nastavení změnil
jiný uživatel, aplikace zobrazí přesný důvod konfliktu. Tlačítko pro načtení
aktuální verze obnoví také její nové číslo verze a teprve potom dovolí úpravu
uložit znovu.

## 58.4 Společný seznam zaměstnanců

V **Mzdy → Zaměstnanci** se zobrazují stejné karty jako ve spodní části Mzdové
rekapitulace. Změna jména nebo aktivního stavu v původní agendě se proto týká
téže osoby; žádné slučování duplicitních karet není potřeba.

Primárním tlačítkem **Přidat zaměstnance** založíš právě tuto společnou kartu,
nikoli druhou osobu jen pro úplné mzdy. Krátký formulář obsahuje nejčastější
vstupní údaje: jméno, volitelné rodné číslo, datum narození, druh vztahu,
plánovaný nástup a základní mzdu. Jedno uložení založí kartu i první pracovní
vztah; nový zaměstnanec se pak otevře k doplnění osobního profilu a podrobností
vztahu. Zaměstnance bez českého rodného čísla lze založit bez náhradní hodnoty.
EČP, VČP a zahraniční identifikátor se vedou samostatně a lze je doplnit
přímo v běžné editaci; úplná osobní evidence dál uchovává jejich 1:N historii.
Rodné číslo se v seznamu nezobrazuje. Tlačítko zůstává viditelné
i uživateli bez práva zápisu, ale je neaktivní a vysvětlí chybějící oprávnění.

Toolbar nad seznamem umožňuje hledání podle jména, přepnutí mezi aktivními,
všemi a kartami vyžadujícími doplnění a rychlý přechod na měsíční zadání mezd.

Seznam ukazuje:

- aktivní nebo neaktivní stav osoby;
- zda její rozšířený mzdový profil vyžaduje doplnění;
- počet a druh pracovních vztahů;
- původní vztah převzatý z Mzdové rekapitulace.

Tlačítkem **Upravit zaměstnance** otevřeš vztahy a zároveň jeden formulář
**Běžné údaje zaměstnance**. Bez přepínání záložek v něm upravíš jméno
a příjmení, rodné číslo, bydliště, e-mail, telefon, týdenní pracovní dobu
a pravidelnou hrubou mzdu. Stát bydliště se vybírá ze společného číselníku
zemí. Pokud je číselník dočasně nedostupný, formulář dovolí ručně zadat
dvoupísmenný ISO kód, aby úpravu adresy nezablokoval výpadek sítě. Změna
jména, bydliště, kontaktu, pracovní doby nebo mzdy nevynuluje historii:
starší záznam uzavře a založí novou účinnou verzi; záznam založený tentýž den
lze ještě opravit na místě. Uzavřená historická adresa se při založení nové
adresy nemění. Pokud je u osoby uloženo rodné příjmení, nová verze jména je
bez jeho odkrytí bezpečně převezme na serveru. Jméno a příjmení se zadávají
samostatně a systém je nikdy neodhaduje z celého zobrazovaného jména. Osobní
profil a primární pracovní vztah se ukládají jednou transakcí, takže při chybě
nezůstane změněná jen jedna část.

Na telefonu se seznam automaticky mění z tabulky na karty. Historii identit,
adres a kontaktů, výplatní účty a další méně časté údaje otevřeš pod formulářem
ve sbalené části **Úplná osobní evidence a historie**. Nejde o nahrazenou nebo
ztracenou evidenci: zůstávají zde vazby 1:N pro historické identity, adresy,
kontakty, výplatní účty a jejich období účinnosti. Také u všech historických
adres se stát vybírá ze stejného číselníku. U citlivých údajů se zobrazuje
pouze maska; novou hodnotu zadej jen tehdy, když ji chceš změnit. Po uložení
aplikace otevřenou hodnotu z formuláře odstraní.

Výplatní účet musí mít název, období účinnosti a rozdělení výplaty. Před
zařazením do platební dávky jej samostatně ověř tlačítkem **Ověřit účet** a
uveď druh podkladu i datum ověření. Máš-li ve formuláři neuloženou změnu účtu,
ověření je zablokované: nejdříve kartu ulož, aby se nikdy neověřila předchozí
uložená hodnota pod nově zobrazenými údaji. Každá pozdější změna čísla účtu,
účinnosti nebo aktivního stavu ověření automaticky zneplatní.

### 58.4.1 Vyživované osoby a daňové zvýhodnění na dítě

Ve sbalené části **Úplná osobní evidence a historie** je pod osobním profilem
sekce **Vyživované osoby a daňové zvýhodnění**. Eviduje děti, na které se
uplatňuje měsíční daňové zvýhodnění podle § 35c zákona o daních z příjmů,
a manžela nebo partnera, u kterých lze slevu uplatnit až v ročním zúčtování.

U osoby zadáš vztah k poplatníkovi, jméno, datum narození, volitelné rodné
číslo, průkaz ZTP/P, soustavné studium a období, po které je osoba vyživovaná.
Rodné číslo dítěte se ukládá šifrovaně, v seznamu i v detailu se zobrazuje jen
maskované a odkrýt je lze pouze auditovaným odhalením citlivých údajů.

Samotná evidence osoby ještě nezakládá nárok. Ten vzniká až **uplatněním**
s vlastním obdobím účinnosti, kde uvedeš:

- **pořadí dítěte** — určuje výši zvýhodnění a patří k uplatnění u konkrétního
  poplatníka, ne k dítěti; dvě děti nesmí mít v jednom měsíci stejné pořadí;
- **ZTP/P** — zvýhodnění za dítě s průkazem ZTP/P je dvojnásobné a zaškrtnout
  je lze jen tehdy, je-li ZTP/P vedeno i u samotné osoby;
- **důvod a doklad** — doložený nárok vyžaduje odkaz do mzdové dokumentace
  a podepsané prohlášení poplatníka platné k počátku nároku; bez obojího se
  nárok uloží jen jako nedoložený a do výpočtu mzdy nevstoupí;
- **potvrzení společně hospodařící domácnosti a druhého poplatníka** — chybí-li,
  výpočet skončí v ruční kontrole.

Aplikace nedovolí dvě překrývající se uplatnění na totéž dítě u jednoho
poplatníka ani uplatnění mimo období, kdy je osoba vedena jako vyživovaná.
Uplatňuje-li totéž dítě (rozpoznané podle rodného čísla) ve stejném měsíci jiný
zaměstnanec téže firmy, uložení se odmítne.

Sazby zvýhodnění se berou z legislativního rulesetu. Pokud pro dané období
žádná účinná sazba neexistuje, aplikace částku neodhaduje — označí nárok
k ruční kontrole.

Nárok zasahující do měsíce uzavřeného schválenou mzdovou revizí se věcnou
změnou nepřepisuje. Původní záznam se ukončí posledním zmrazeným měsícem
a vznikne nová účinná verze od měsíce následujícího, takže historický výsledek
zůstane nedotčený. Ukončení nároku mimo zmrazené období se provede běžnou
úpravou data „Nárok do".

## 58.5 Pracovní vztah a předkontace

Jedna osoba může mít více samostatných právních vztahů. Rozlišení je důležité
pro výpočet, podání i účetnictví:

| Druh vztahu | Hrubý náklad | Závazek |
|---|---:|---:|
| pracovní poměr mimo výkon funkce, zaměstnání malého rozsahu, DPP, DPČ | 521 | 331 |
| příjem společníka ze závislé činnosti | 522 | 366 |
| odměna za výkon funkce člena orgánu | 523 | 366 |
| pojistné hrazené zaměstnavatelem | 524 | 336 |

Odměna jednatele za výkon funkce tedy není totéž co pracovní poměr jednatele
mimo výkon funkce ani jiný příjem společníka. Souběh se vede jako více vztahů
jedné osoby.

Převzatý legacy vztah zachovává dosavadní kontaci Mzdové rekapitulace. Před
ostrým použitím úplných mezd zkontroluj, zda právní titul odpovídá skutečnosti;
zejména starší karta „jednatel-společník“ sama nerozliší smlouvu o výkonu funkce
od ostatní závislé činnosti.

## 58.6 Životní cyklus vztahu

Nový vztah začíná jako **Plánovaný**. Stav se nemění volným přepsáním pole, ale
jen nabízenými akcemi:

`Plánovaný → Předregistrovaný → Aktivní → Přerušený → Skončený → Archivovaný`

Z přerušeného vztahu se lze vrátit do aktivního stavu nebo jej ukončit.
Plánovaný či předregistrovaný vztah lze samostatně označit jako **Nenastoupil**
a potom archivovat. U každé akce zvolíš datum účinnosti. Přeskočení povinného
kroku nebo návrat ze skončeného vztahu aplikace odmítne.

Skončení vztah nemaže. Zůstává dostupný pro pozdější doplatek, opravu, podání a
dohledání tehdy platných údajů. Archivace jej pouze odklidí z aktivního workflow.

## 58.7 Historie smluvních podmínek a souběhy

Tlačítko **Nová verze podmínek** založí další účinný interval. Předchozí verzi
uzavře dnem před novou účinností; starší mzdové období proto pozdější změna
nepřepíše. Historie drží zejména:

- uzavření smlouvy, plánovaný a skutečný nástup a dobu určitou;
- úvazek, týdenní hodiny, místo práce, pravidelné pracoviště, CZ-ISCO a druh
  činnosti;
- mzdovou účtárnu, pojistnou účast, A1 a cizí předpisy, rizikovou práci,
  daňový režim a prohlášení k dani;
- příznak primárního pracovního vztahu a důvod změny.

Ve stejné verzi podmínek je skupina **JMHZ – vykonávaná pozice**. Eviduje
strukturovanou obec pracoviště, kód obce a stát, příspěvek a nástroj APZ,
funkční požitky a dočasné přidělení. Příznaky používají tři stavy **Neověřeno**,
**Ne** a **Ano**; chybějící historický údaj se nikdy automaticky nepovažuje za
„ne“. Obec, její kód a stát se ukládají jen jako úplná trojice. Dokud nejsou
údaje vybrané z autoritativních číselníků CISOB a CZEM, aplikace kontroluje
shodu názvu obce s kódem i platnost státu. Obec se vybírá našeptávačem; stát z
připnuté nabídky. Podmínky před začátkem účinnosti připnutých číselníků nelze
takto označit jako ověřené. Budoucí personální změnu lze naplánovat podle
posledního připnutého snapshotu, ale mzdový snapshot ji pro JMHZ označí jako
neověřenou, pokud vykazované období přesahuje jeho ověřené pokrytí. Taková data
nesmějí projít budoucí readiness bránou ani se odeslat bez novějšího snapshotu.
Při dočasném přidělení
**Ano** je navíc nutné doplnit identitu alespoň jednoho uživatele; samotný
příznak nestačí k přípravě podání.

Jedna osoba může mít souběžně například HPP a DPP nebo samostatný pracovní poměr
a odměnu za výkon funkce. V aktivním workflow může být právě jeden vztah označen
jako primární. Každý souběh má vlastní kód, stav, historii a budoucí registrační
identitu.

## 58.8 Checklist a časová osa

Detail ukazuje povinnosti nástupu, změny a skončení. Patří sem smlouva nebo
dohoda, registrace a změny pro zdravotní pojišťovnu a ČSSZ/JMHZ, daňové
prohlášení, výstupní doklady, kontrola exekucí či insolvence a kontrola
pozdějšího doplatku. U každé položky je termín a stav **Nesplněno**,
**Splněno** nebo **Netýká se**.

Časová osa zachovává stavové přechody, změny checklistu i rozdíl každé smluvní
verze. Pokud jiný uživatel mezitím vztah změnil, starší formulář se neuloží a je
nutné načíst aktuální verzi.

## 58.9 Mzdové složky a vstupy

V **Mzdy → Mzdové složky a vstupy** jsou běžnými záložkami oddělené:

- katalog mzdových složek;
- pravidelné předpisy;
- jednorázové měsíční vstupy;
- CSV/XLSX import s povinným náhledem před uložením.

Výchozí složky používají české kódy bez diakritiky, aby byly bezpečné i pro
CSV a jiné strojové zpracování. Patří mezi ně například `MZDA_MESICNI`,
`MZDA_HODINOVA`, `ODMENA`, `NAHRADA_MZDY`, `NEPENEZNI_PRIJEM`,
`PRISPEVEK_STRAVOVANI` a `CESTOVNI_NAHRADA`. Stejné kódy používej také ve
sloupci `component_code` importovaného souboru. U nové vlastní složky zadej
nejprve název; kód se z něj automaticky vytvoří bez diakritiky. Dokud jej ručně
neupravíš, sleduje změny názvu. Po uložení už kód ani začátek platnosti změnit
nelze; další účinnost se zakládá jako nová verze.

Každá složka samostatně určuje dopad do daně, sociálního a zdravotního
pojištění, průměrného výdělku, exekučního základu, JMHZ, statistiky a
účetnictví. Schválený vstup si uloží neměnný snapshot této klasifikace; pozdější
změna katalogu proto nepřepíše již zpracované období.

U složky zahrnuté do JMHZ nastav také konkrétní cílový atribut měsíčního
hlášení. Stav **Chybí mapování** nebrání výpočtu mzdy, ale znamená, že složku
zatím nelze bezpečně převést do úplného JMHZ. Celkové cíle používej jen pro
částky, které nelze přesně zařadit do detailního rozpadu; aplikace je proto
viditelně odlišuje. Mapování lze auditovatelně deaktivovat a teprve potom lze
složku z JMHZ vyloučit nebo převést do ručního posouzení. Samotné mapování
nevytváří XML ani nic neodesílá na ČSSZ.

Pravidelný předpis má vlastní interval platnosti a lze jej zadat pevnou částkou
nebo procentem. Účty MD/D se vybírají našeptáváním z aktivního účtového rozvrhu;
formulář nezadává interní identifikátory. Procentní sazbu zadávej jako běžné
procento a množství v přirozené jednotce — převod na interní bazické body
a tisíciny provede aplikace. Jednorázový vstup se nejprve zkontroluje a potom samostatně
schválí. Import odmítá nebezpečné sešity, vzorce a duplicitní řádky a před
zápisem vždy ukáže výsledek náhledu. Soubor můžeš vybrat fialovým tlačítkem
**Vybrat soubor** nebo jej přetáhnout do zvýrazněné plochy; stejný ovládací
prvek používá také import docházky. Přijímá CSV a XLSX do 5 MB a chybu zobrazí
přímo u souboru.

### 58.9.1 Rychlý měsíční vstup bez docházky

V **Mzdy → Rychlý měsíční vstup** vybereš měsíc a upravíš všechny účinné
pracovní vztahy na jedné stránce. U každého zaměstnance se zobrazí jméno,
maskované rodné číslo, typ vztahu, základní mzda nebo odměna ze vztahu,
přesčas a bonus či další odměna. Pracovní poměr, DPP, DPČ, závislý příjem
společníka a odměna za výkon funkce zůstávají v samostatných řádcích a systém
je neslučuje.
Náhled hrubé mzdy se přepočítává okamžitě; další již existující mzdové vstupy
jsou v něm zobrazeny samostatně. Do hrubého náhledu vstupují všechny složky
zařazené jako zdanitelný příjem včetně nepeněžních. Osvobozené náhrady a jiné
složky mimo hrubý příjem se zobrazí zvlášť a do součtu se nepřičtou. Složka
s neuzavřeným daňovým zařazením vytvoří ruční kontrolu. Jde pouze o náhled
hrubých složek, nikoli o výpočet čisté mzdy; ten vznikne až ve mzdovém běhu.

Přesčas lze zadat celkovou částkou. Zadání v hodinách je dostupné pouze tehdy,
když má vztah pro dané čtvrtletí schválený průměrný hodinový výdělek. Systém
pak použije tento doložený průměr a 25% příplatek; bez schváleného podkladu
hodinovou sazbu neodhaduje a vyžádá celkovou částku. U závislého příjmu
společníka, odměny za výkon funkce, DPP a DPČ se hodinový přesčas s 25%
příplatkem nenabízí; použije se doložená celková částka nebo odměna.

Hromadné uložení vytváří běžné vstupy složek `MZDA_MESICNI`,
`PREMIE_PRIPLATKY` a `ODMENA`, takže nevzniká paralelní evidence mezd.
Opakované uložení stejného měsíce nevytvoří duplicity. Rozpracované vstupy se
mění s kontrolou jejich verze; schválený nebo uzamčený vstup formulář nikdy
nepřepíše. Pokud základní mzdu už spravuje pravidelný či jiný měsíční vstup,
rychlý formulář ji zobrazí pouze pro čtení. Kontroluje také verzi pracovního
vztahu, takže po souběžné změně smlouvy vyžádá obnovení formuláře. Historický
měsíc zachová vztah, který byl tehdy účinný a později archivován. Při nástupu,
ukončení nebo pozastavení v průběhu měsíce nepředvyplní plnou měsíční mzdu a
vyžádá skutečnou částku za zpracovávané období. Plný měsíční pravidelný předpis
v takovém měsíci také nepřevezme automaticky; zůstane v ruční kontrole, dokud
není doložené správné časové rozpočítání.

Částky zadávej v Kč s nejvýše dvěma desetinnými místy a hodiny s nejvýše
třemi. Prázdná hodnota neznamená nulu; pokud složka v měsíci není, zadej
**0**. Formulář označí konkrétní chybné pole a rozliší chybějící hodnotu,
neplatný formát, záporné číslo a překročení podporovaného rozsahu. Pokud se
uložení nepodaří například proto, že stejný vstup mezitím změnil jiný
uživatel, přesný důvod zůstane viditelný nad formulářem. Před dalším pokusem
načti aktuální měsíc tlačítkem **Obnovit** a změny zkontroluj.

### 58.9.2 Cestovní náhrady

V **Mzdy → Cestovní náhrady** vedeš tuzemské pracovní cesty a jejich vyúčtování.
U cesty zadej pracovní vztah, odjezd a návrat s časem, místo, účel a dopravní
prostředek. K vyúčtování přidáš doložené výdaje (jízdné, ubytování, nutné
vedlejší výdaje), jízdy soukromým vozidlem v kilometrech a spotřebě na 100 km,
bezplatná jídla po dnech a poskytnutou zálohu.

Nárok se počítá z účinné vyhlášky k rozhodnému dni:

- stravné podle časových pásem pracovní cesty (5 až 12 h, nad 12 do 18 h,
  nad 18 h) za každý kalendářní den; u cesty spadající do dvou kalendářních dnů
  se použije výhodnější varianta;
- krácení stravného za každé poskytnuté bezplatné jídlo;
- základní náhrada za ujetý kilometr a náhrada za spotřebované pohonné hmoty
  z průměrné ceny podle vyhlášky, nebo z doložené ceny;
- doložené ubytování a nutné vedlejší výdaje.

Náhled ukazuje rozpad po dnech i po položkách a rozdělí výsledek na část **do
zákonného limitu**, která není předmětem daně, pojistného ani exekučních srážek,
a na **nadlimitní část**, která do mzdy vstupuje jako zdanitelný příjem a do
vyměřovacích základů. Sazba stravného nižší než zákonné minimum, chybějící
účinná sazba i zahraniční pracovní cesta skončí v ruční kontrole a vyúčtování
nelze schválit.

Schválené vyúčtování promítneš tlačítkem **Promítnout do mzdy**; založí mzdové
vstupy na složkách `CESTOVNI_NAHRADA_LIMIT` a `CESTOVNI_NAHRADA_NADLIMIT`
v období vyúčtování. Opakované promítnutí nevytvoří duplicitu. Zakládat a
upravovat cesty smí role s oprávněním pro mzdové vstupy, schválení a promítnutí
vyžaduje oprávnění pro schvalování.

## 58.10 Absence, dovolená a DPN

V **Mzdy → Absence a dovolená** jsou tři navazující agendy:

- absence se schvalovacím stavem a kontrolou překryvu;
- čtvrtletní snapshot průměrného nebo pravděpodobného hodinového výdělku;
- hodinový ledger dovolené, ve kterém oprava vytváří novou položku a nemaže historii.

Nejprve vyber pracovní vztah a založ snapshot průměrného výdělku. Skutečný
průměr používá započitatelnou mzdu a odpracovaný čas v rozhodném období.
Formuláře zadávají částky v Kč a čas v hodinách či dnech; interní haléře
a minuty převádí aplikace až při uložení. Stejně se v hodinách zadává částečný
první nebo poslední den absence i ruční změna dovolené. Přesný důvod chyby
zůstane viditelný přímo u příslušného formuláře.
Při méně než 21 odpracovaných dnech je povinný pravděpodobný hodinový výdělek
a jeho odůvodnění. Snapshot musí projít ruční kontrolou a schválením; teprve
potom jej lze připojit k absenci s náhradou.

Nárok dovolené se vede v minutách. U DPP a DPČ výpočet používá zákonnou
fiktivní týdenní pracovní dobu 20 hodin. Započitatelné a náhradní doby,
změny úvazku, krácení a další právní okolnosti před uložením vždy ověř.
Schválení čerpání zapíše zápornou položku podle publikovaných směn. Zrušení
schváleného čerpání ji nemaže, ale vytvoří kladnou reverzi a označí absenci
pro kontrolu případné opravy mzdy.

Náhrada při DPN se počítá pouze z publikovaných směn v prvních 14 kalendářních
dnech. Před schválením potvrď účast na nemocenském pojištění a vyloučení
souběžné dávky. Pokud zaměstnanec první plánovanou směnu celou odpracoval,
označ tuto skutečnost; čtrnáctidenní okno pak začíná následujícím dnem.
Výsledek uchovává použitý průměr, redukční hranice, pravidla, zaokrouhlení
a rozpad po směnách. Diagnóza se v agendě absence neeviduje.

> [!WARNING]
> Agenda je označena **Vyžaduje ruční kontrolu**. Bez schváleného průměru,
> publikovaného rozvrhu nebo potvrzených zákonných podmínek výpočet bezpečně
> selže; systém chybějící údaj neodhaduje. Výpočty náhrad a dovolené jsou
> aktuálně dostupné pouze pro legislativní ruleset roku 2026.

## 58.11 Mzdové běhy

V **Mzdy → Mzdové běhy** založíš zpracování konkrétního měsíce. K období se
zadává také skutečné datum výplaty; podle něj se vybírají účinná pravidla
srážek. Jeden běh prochází řízenými kroky **Uzamknout vstupy → Vypočítat →
Zkontrolovat → Schválit**. Výpočet a kontrolu musí provést různí uživatelé a
schválení vyžaduje samostatné oprávnění.

Skutečně prázdný technický běh lze tlačítkem **Smazat prázdný běh** odstranit
i po jeho zrušení. Tlačítko se zobrazí pouze tehdy, když běh nemá žádnou revizi,
uzamčené vstupy, výpočet, dokument, podání, platbu ani účetní stopu. Jakmile
běh obsahoval věcnou evidenci, zůstává kvůli auditu dohledatelný a lze jej jen
zrušit, nikoli smazat.

Uzamknutí vytvoří neměnný snapshot zaměstnanců, vztahů, složek, data výplaty
a měsíčních podkladů srážek. Pozdější změna živé karty už rozpracovanou revizi
nepřepíše. Oprava schváleného měsíce vytváří novou revizi; původní zůstává
dohledatelná.

Po výpočtu je u běhu dostupný **Rozpad daně ze závislé činnosti**. Pro každého
zaměstnance ukazuje zdanitelný a zákonně zaokrouhlený základ, základ a sazbu
jednotlivých pásem, daň před slevami, uplatněné a skutečně použité slevy,
zvýhodnění na děti, daňový bonus a případnou srážkovou daň. U souběhu vztahů
je vidět, který příjem spadl do zálohového nebo srážkového režimu.

Stav **Vyžaduje ruční kontrolu** není vypočtená nula. Rozpad vypíše konkrétní
důvody, například neověřené prohlášení k dani, rezidenci nebo nárok na dítě.
Nejdřív oprav podklad na kartě osoby a potom vytvoř novou revizi výpočtu;
historický snapshot se zpětně nemění.

Výpočet odděluje hotovost zahrnutou do exekučního základu od částek, které se
nesrážejí, například správně klasifikovaných cestovních náhrad. Vypočtená
srážka sníží částku k výplatě, ale neměnný výsledek a ledger
**sraženo / deponováno** vzniknou až společně se schválením. Neúplné důkazy,
více plátců bez ověřeného rozdělení nebo jiný stav vyžadující posouzení
schválení zablokují.

Schválení také promítne vypočtené standardní srážky do append-only ledgeru.
Oprava nepřepíše původní pohyb: zvýšení přidá pouze rozdíl a snížení vytvoří
reverzi navázanou na původní sražení. Opakované schválení částku nezapíše
podruhé.

Schválení zároveň automaticky vytvoří výplatní pásku každé zpracované osoby
a v podvojném účetnictví rozdílový mzdový deník. Použijí se předkontace
zmrazené při uzamknutí vstupů, takže pozdější změna nastavení nezmění již
zkontrolovanou revizi. Je-li účetní období uzamčené, datum deníku se posune na
první otevřený den. Schválení, zákonné kumulace, účetní zápis i pásky tvoří
jeden celek: selže-li některý krok, běh zůstane ve stavu **Zkontrolováno**
a nevznikne částečně schválená mzda.

> [!WARNING]
> Produkční schválení vyžaduje odborně schválený a aktivní legislativní
> ruleset pro příslušné období. Neaktivní nebo neúplný ruleset výpočet označí
> pro ruční kontrolu a schválení zablokuje; aplikace chybějící zákonné údaje
> neodhaduje.

U schválené revize nabídne karta běhu **Rozklad čisté mzdy**. Pro vybranou osobu
ukáže hrubý příjem, odvody zaměstnance, daň a bonus, čistou mzdu před srážkami,
jednotlivé srážky s titulem a pořadím, exekuční srážku a výslednou částku
k výplatě včetně rozdělení mezi platební cíle. Údaje se čtou ze zmrazené revize,
takže pozdější změna dohody ani výplatního pravidla je nezmění. Zobrazí se vždy
jen vybraná osoba a bankovní cíl pouze maskou účtu.

V podvojném účetnictví je pro schválenou revizi dostupná stránka
**Mzdy → Shoda účtování mezd**. Pro zvolené období porovná mzdovou revizi,
skutečně zaúčtovaný deník a platební závazky po kategoriích (hrubé mzdy,
pojistné hrazené zaměstnavatelem, sociální a zdravotní pojištění, daň,
ostatní srážky, exekuční srážky a čistá mzda) a u každé ukáže, na které
straně případný rozdíl vznikl. Oprava schváleného měsíce se do porovnání
promítne správně — deník se sčítá napříč všemi revizemi běhu, protože
rozdílová revize účtuje jen rozdíl proti poslední zaúčtované revizi. Měsíc,
který ještě nebyl zaúčtován (vypnuté automatické zaúčtování, čekající krok,
nebo firma vedoucí daňovou evidenci), stránka označí jako nezaúčtovaný —
nejde o rozdíl. Stránka je čistě informační a nic nezapisuje ani do deníku,
ani do mzdové revize.

## 58.12 Platby mezd a odvodů

V **Mzdy → Platby mezd a odvodů** vybereš mzdové období a připravíš platební
závazky z aktuálních schválených revizí. Čistá mzda se vždy odvozuje z částky
po exekučních srážkách. Rozdělení mezi ověřené bankovní účty a hotovost se
znovu vypočte nad pravidly a účty zmrazenými při uzamčení vstupů; pozdější
změna živé karty už schválenou revizi nepřesměruje.

Bankovní cíl musí mít ve snapshotu úplné ověření, období platnosti a verzi
účtu. Starší schválená revize, která vznikla před zavedením zmrazených
výplatních účtů, se k přípravě plateb nenabídne. Pro její zaplacení nejprve
vytvoř opravnou revizi z aktuálních a ověřených podkladů. Selhání jedné
účtárny nezastaví přípravu ostatních běhů téhož měsíce; aplikace vypíše počet
nezpracovaných běhů a konkrétní důvod.

Stejná akce připraví také zdravotní pojistné samostatně pro každou pojišťovnu
z neměnného výsledku schválené revize. V nastavení musí mít konkrétní
pojišťovna právě jeden účet účinný ke splatnosti, úplný ověřovací podklad a
platební symboly. Seznam ukáže název a kód pojišťovny, maskovaný účet a stav
ověření; celé číslo účtu ani interní reference neposílá. Splatnost se vždy
odvodí z mzdového období, ne z dne, kdy byl historický běh vypočten nebo
opraven.

Ze stejných schválených podkladů se připraví jeden závazek sociálního
pojištění za mzdovou účtárnu a oddělené závazky zálohové a srážkové daně.
Sociální pojistné používá zaměstnavatelský VS z účtárny a účet ČSSZ účinný
ke splatnosti. Zálohová a srážková daň se neslučují: každá má vlastní účet
finančního úřadu a platební symboly. Záloha je splatná 20. den následujícího
měsíce, srážková daň jeho poslední den.

Opakované stisknutí **Připravit závazky** je bezpečné a nevytvoří duplicity.
Opravná revize nezapisuje znovu celou mzdu, ale jen rozdíl proti předchozím
závazkům. Seznam ukazuje příjemce, druh závazku, způsob úhrady, splatnost,
částku a odvozený stav. Záporný rozdíl institucionálního odvodu se zobrazí jako
příchozí opravný závazek, ale nelze jej vložit do odchozí bankovní dávky;
vratku je nutné doložit příchozím bankovním nebo pokladním dokladem.

V záložce **Platební dávky** vybereš připravené závazky. Aplikace podle
výplatních cílů nabídne účet plátce a formát ABO nebo SEPA, znovu ověří
nezměněné účty příjemců a vytvoří dávku. U zdravotní pojišťovny, ČSSZ i
finančního úřadu použije přesné zmrazené VS, SS a KS. Export se ukládá
šifrovaně přesně
v těch bajtech, které se stáhnou do banky. Opakování se stejným klíčem vrátí
tentýž export a nevytvoří další ekonomický závazek. Stažení vyžaduje právo
zápisu a používá krátkodobé jednorázové oprávnění.

V záložce **Úhrady a párování** vybereš konkrétní alokaci závazku a kompatibilní
bankovní pohyb nebo zaúčtovaný pokladní doklad. Lze zapsat i částečnou úhradu.
Historie je neměnná: vratka nebo storno nevynuluje původní záznam, ale přidá
samostatnou reverzní událost s vlastním důkazem. Jeden bankovní nebo pokladní
důkaz nesmí současně převzít fakturace ani jiné párování.

Filtr období patří mzdové revizi, ne datu vytvoření dávky. V nabídce důkazů
proto zůstane i předčasná nebo opožděná platba vztahující se k otevřenému
závazku.

Skutečné datum úhrady vzniká výhradně z data zvoleného důkazu, nikdy z
plánovaného data výplaty mzdového běhu ani ze samotné existence exportního
souboru. Dokud nejsou všechny požadované částky průkazně spárovány a případné
vratky vyřešeny, aplikace závazek ani daňové potvrzení neoznačí za skutečně
uhrazené.

## 58.13 Srážky, exekuce a oddlužení

V **Mzdy → Srážky a exekuce** založíš zaměstnanci zákonnou srážku, exekuci
nebo dohodu o srážkách. Případ začíná ve stavu **Přijato — čeká na ověření**.
Nejdříve doplň pohledávky, jejich kategorii, den pořadí a potvrď ověření
právního titulu, doručení a přednosti. Aktivace srážení vyžaduje počáteční
rozhodnutí z agendy **Dokumenty**. Do ověření příjemce aplikace částky jen
deponuje. Odesílání musí uživatel povolit samostatnou akcí a doložit
odpovídajícím rozhodnutím. Aplikace ověří firmu i oprávnění uživatele k
dokumentu, uloží jeho otisk a dokument od té chvíle chrání jako právní důkaz.
Také odklad, obnovení deponování či odesílání a zastavení vyžadují vybraný
dokument; u odkladu a zastavení je navíc povinný důvod.

Výpočet používá celé haléře a uchovává neměnný měsíční vstup, použitou verzi
pravidel, mezikroky zaokrouhlení, přidělení částek pohledávkám a pohyby
**sraženo / deponováno**. Odeslané peníze eviduje samostatně platební vrstva,
aby výpočet nemohl předstírat skutečnou úhradu. Kontroluje zejména nezabavitelnou částku,
třetiny, plně zabavitelný zbytek, pořadí přednostních pohledávek, běžné a dlužné
výživné, více exekučních příkazů, více plátců, oddlužení a paušální náhradu
nákladů zaměstnavatele. Chybějící měsíční podklady nezastoupí odhadem — výsledek
označí pro ruční kontrolu.

Číslo řízení, bankovní účet příjemce ani právní dokument se do polí případu
nepřepisují. Patří do zabezpečených dokumentů; agenda srážek pracuje pouze
s interním identifikátorem a ověřenými skutečnostmi. Odklad a zastavení vyžadují
ověřené rozhodnutí a důvod. Ukončený případ nelze zkratkou znovu otevřít.
Označení případu za uhrazený projde teprve tehdy, když potvrzené úhrady pokryjí
celý zůstatek pohledávek — samotné sražení ze mzdy k tomu nestačí.

### 58.13.1 Odeslání sražených částek příjemci

Aby aplikace sražené peníze skutečně odeslala, vyber v případu **příjemce srážky**
z katalogu **Mzdy → Nastavení → Účty institucí** (typ *ostatní příjemce*). Účet
musí být ověřený a účinný k datu výplaty; číslo účtu ani symboly se do případu
neopisují. Po schválení mzdové revize vytvoří akce **Připravit závazky**
v **Mzdy → Platby** závazek vůči tomuto příjemci — ale jen z částek, které jsou
ve stavu **odesílání**. Cokoli je deponované (nový případ, odklad, zastavení)
se do odchozí platební dávky nedostane. Opakovaná příprava nevytvoří druhý
závazek; oprava mzdy promítne jen rozdíl a pokles vznikne jako samostatný
příchozí opravný závazek.

Blok **Sraženo, depozitum a odeslané platby** v detailu případu ukazuje, kolik
bylo sraženo, kolik drží depozitum, kolik je připraveno k úhradě, kolik už
příjemce dostal a kolik na pohledávce zbývá. Poslední dva údaje se mění až
po spárování skutečné bankovní nebo pokladní platby v **Mzdy → Platby →
Úhrady a párování**.

### 58.13.2 Dohody o srážkách

Dobrovolné a standardní srážky — zálohy, stravování, spoření, náhrada škody
a příspěvky — spravuješ v **Mzdy → Dohody o srážkách**. Dohoda má titul,
příjemce, druh, pořadí, částku a účinnost od–do; volitelně i celkový limit,
po jehož vyčerpání se srážka přestane uplatňovat. Částku lze zadat pevně,
nebo procentem ze zadaného základu — z procenta a základu se uloží pevná
částka, protože mzdový běh zmrazuje podklady dřív, než zná výsledný příjem.

Pořadí zadáváš v rozsahu 10–9999. Nižší pásmo je vyhrazené zákonným
a exekučním srážkám, takže dobrovolná dohoda nikdy nepředběhne přednostní
pohledávku ani neobejde nezabavitelnou částku — výpočet dobrovolné srážky vždy
omezí volnou kapacitou po zákonných srážkách.

Dohoda prochází stavy **Návrh → Aktivní → Pozastavená → Ukončená**; návrh, který
ještě nemá jediný pohyb, lze zrušit. Do mzdového běhu vstupuje jen aktivní
dohoda účinná v daném období. Změna dohody nikdy nepřepíše podklady už schválené
mzdy: uloží se jako nová účinná verze a historie verzí i pohybů zůstává v detailu
dohody. Ukončení dohodu zastaví, ale historii sražených částek nemaže. Pokud
dohodu mezitím změnil někdo jiný nebo do ní přibyl pohyb, uložení skončí
konfliktem a formulář se načte znovu — poslední zápis nikdy tiše nevyhrává.

## 58.14 Dokumenty a měsíční balíček

V **Mzdy → Dokumenty a výstupy** vyber období. Seznam zobrazuje dokumenty
uložené ke schválené revizi mzdového běhu, zaměstnance, mzdovou účtárnu,
číslo revize, čas vytvoření a velikost. Na telefonu se tabulka mění na karty.

Záložka **Roční dokumenty** umožňuje zvolit rok a zaměstnance a vytvořit
**mzdový list**, **potvrzení k zálohové dani** nebo **potvrzení ke srážkové
dani**. Mzdový list vzniká pouze z posledních schválených revizí všech mzdových
účtáren v daném roce. Zahrne také více souběžných revizí v jednom měsíci,
například doplatek po skončení vztahu. Pokud chybí schválený výsledek,
historická identifikace nebo jiný povinný podklad, aplikace dokument nevytvoří
a zobrazí konkrétní důvod.

Daňová potvrzení jsou dva samostatné formuláře: `25 5460, MFin 5460 – vzor
č. 33` pro zálohovou daň a `25 5460/A, MFin 5460/A – vzor č. 12` pro příjmy
zdaněné srážkou. Automaticky se vytvářejí pro rok 2026 a českého
daňového rezidenta v podporovaném běžném režimu. Zálohové potvrzení zmrazí
stav Prohlášení poplatníka i měsíce, ve kterých bylo podepsané; srážkové
potvrzení uvádí přesné měsíce příjmů. Pro přesná pole tiskopisu musí mít
historická identita zaměstnance vedle celého zobrazovaného jména vyplněné
také samostatné jméno a příjmení; systém je z celého jména neodhaduje.

Řádek skutečně vyplacených příjmů nevychází z plánovaného výplatního dne.
Aplikace jej povolí jen tehdy, když neměnná platební evidence dokládá úplnou
výplatu všech zahrnutých čistých mezd nejpozději do 31. ledna následujícího
roku. Chybějící, částečná, pozdní nebo zvrácená úhrada vytvoření zablokuje.
Stejně bezpečně se odmítnou situace, pro které snapshot nemá všechna
povinná pole formuláře, například dítě, invalidita, nerezident, podporovaný
produkt spoření, nepeněžní příjem, doplatek za minulý rok nebo provedené roční
zúčtování. Údaj se nikdy tiše nedopočítá z dnešní karty zaměstnance.

Roční dokument má vlastní neměnnou revizi a není uměle připojený k prosincové
mzdě. Osobní údaje jeho zdrojového snapshotu jsou kontextově šifrované;
manifest obsahuje pouze interní identifikátory a kryptografické otisky.
Pozdější oprava mzdy vytvoří další revizi mzdového listu a původní soubor
zůstane dohledatelný. Roční zúčtování se v mzdovém listu nedopočítává a bez
samostatně schváleného ročního výsledku se označí jako neprovedené.
Opravné daňové potvrzení musí navazovat na poslední vydaný dokument stejného
druhu a vyžaduje konkrétní důvod. Nová revize uvádí datum nahrazovaného
potvrzení a důvod v příloze; původní PDF zůstává beze změny. Opakování stejné
opravné žádosti bezpečně vrátí již archivovanou revizi.

U ukončeného pracovního vztahu otevři v **Mzdy → Zaměstnanci** jeho detail
a část **Dokumenty při skončení vztahu**. Potvrzení o zaměstnání lze vytvořit
jen po kontrole přesné identity, adresy a smluvních podmínek účinných ke dni
skončení. Ve formuláři potvrď druh práce, kvalifikaci, pracovní expozici,
pokračující srážky a případné důchodové kategorie před rokem 1993. Částky
srážek se nezadávají — aplikace je přebírá z uzavřené evidence. Každá oprava
vyžaduje konkrétní důvod a vytvoří novou neměnnou revizi.

Samostatné potvrzení pro Úřad práce (§ 313 odst. 2) je zablokované.
Aplikace v modulu Absence a průměry rozlišuje, zda pro rozhodné čtvrtletí
chybí schválený snapshot průměrného výdělku, nebo zda snapshot existuje a
chybí jen ověřený přepočet na čistý měsíční výdělek podle zákona o
zaměstnanosti — v obou případech ale potvrzení nelze vydat a aplikace
nenabízí ruční zadání hotové čisté částky.

Stažení nejprve získá krátkodobé jednorázové oprávnění a potom soubor předá
prohlížeči. Původní dokument se při opravě nikdy nepřepisuje. Nový výstup má
vlastní revizi a původní zůstává dohledatelný.

Pro každou poslední schválenou revizi období lze vytvořit měsíční ZIP. Obsahuje
právě ty dokumenty, které už byly k revizi archivovány, a strojově čitelný
manifest s jejich otisky. Doplníš-li později další dokument, vznikne nová
revize balíčku; opakované vytvoření nad stejnou sadou vrátí stejný výsledek.

> [!WARNING]
> Výplatní pásky vznikají automaticky při schválení; mzdový list a obě daňová
> potvrzení vytvoříš v záložce Roční dokumenty. Potvrzení při skončení vytváříš
> v detailu konkrétního vztahu a podací protokoly se evidují samostatně. Neúplný
> měsíční balíček proto neznamená, že jsou všechny povinné výstupy hotové.

## 58.15 Podání a hlášení

V **Mzdy → Nastavení mezd → Podání** nejprve potvrď evidenční profil pro
REGZEL. Samostatně se eviduje, zda je zaměstnavatel sociálním podnikem,
agenturou práce nebo zaměstnavatelem na chráněném trhu práce. Potvrzení se
vztahuje i na nezaškrtnuté hodnoty; při každém uložení je proto nutné znovu
výslovně potvrdit, že byly všechny tři údaje ověřeny.

V **Mzdy → Podání a hlášení** lze připravit doplňující údaje zaměstnavatele
`REGZELDOPL25` podle lokálně připnutého oficiálního XSD. Vyber produkční nebo testovací prostředí a
konkrétní aktivní mzdovou účtárnu. Prostředí jsou striktně oddělená:
test vyžaduje fiktivní desetimístný variabilní symbol začínající `999`,
zatímco produkce jej odmítne. Před každou přípravou XML znovu potvrď aktuálnost
prostředí, účtárny, identifikátorů i evidenčních příznaků.

Příprava vytvoří neměnný šifrovaný snapshot a XML ověří proti lokálně
připnutému oficiálnímu XSD. Historie se filtruje podle právě vybraného
prostředí a XML lze znovu stáhnout. Při stažení aplikace ověří šifrovaný zdroj,
tenant, prostředí, XSD i kryptografický otisk výsledného XML.

Tato funkce XML pouze připraví a stáhne. Neodesílá je a neoznačuje registraci
za přijatou. Prvotní registrace zaměstnavatele, přidání nebo ukončení účtárny
a opravné scénáře nejsou bez odpovídajícího oficiálního XSD dostupné.

Záložky **JMHZ** a **Zdravotní pojišťovny** zobrazují za vybraný měsíc skutečný
přehled evidovaných povinností, termínů, kanálů a posledních stavů podání.
Produkční a testovací prostředí zůstávají oddělená. Přehled je pouze
kontrolní — bez implementovaného důvěryhodného transportu a parseru protokolu
nenabízí falešné tlačítko odeslání ani nepovyšuje lokální stav na přijaté
podání.

U každého termínu se samostatně zobrazuje jeho aktuální fáze: okno ještě není
otevřené, otevřeno, blíží se termín, termín je dnes, po termínu, čeká se na
výsledek, splněno nebo je nutný zásah. Samotný stav **Odesláno** není důkazem
splnění; po termínu zůstane povinnost zvýrazněná, dokud nepřijde důvěryhodné
přijetí. Odmítnutí, částečné přijetí nebo čekání na ztotožnění se vždy ukáže
jako stav vyžadující zásah. Pravidelný termín JMHZ je 20. den následujícího
měsíce; připadne-li na sobotu, neděli nebo český svátek, aplikace jej posune
na nejbližší následující pracovní den.

Má-li povinnost připravené podání, tlačítko **Detail** zobrazí jeho bezpečný
provozní rozpad: stav a kanál, jednotlivé části, metadata archivovaných
artefaktů, kontroly a problémy a přijaté dodejky. Obsah šifrovaných XML ani
citlivé podrobnosti validačních chyb se do tohoto přehledu neposílají.
Rozlišuj zejména stav **Odesláno** od **Přijato** — přijetí se smí zobrazit
jen na základě důvěryhodně ověřeného protokolu. Tlačítkem **Stáhnout**
u artefaktu získáš přesně archivovaný XML, ZIP, PDF, JSON nebo jiný podklad;
každé stažení používá krátkodobé jednorázové oprávnění.

U schválených běhů může záložka JMHZ nabídnout také **Kontrolní náhled
PVPOJ**. Zobrazuje vyměřovací základ, pojistné k úhradě, počet zahrnutých osob
a identifikaci připnutého XSD; stejný deterministický kontrolní JSON lze stáhnout.
Náhled vznikne pouze tehdy, když souhlasí neměnný vstup revize, vypočtené
sociální pojištění, vztahové i osobní součty a odpovídající závazek ČSSZ.
Viditelné označení **Pouze kontrolní náhled** znamená, že nejde o úplné XML
JMHZ, připravené podání ani důkaz odeslání nebo přijetí.

U schválených mzdových běhů připraví záložka zdravotních pojišťoven také
interní měsíční přehled samostatně pro každý kód pojišťovny a každou aktuální
revizi. Zobrazuje číslo běhu a revize, počet osob, úhrn vyměřovacích základů
a pojistného a lze jej stáhnout jako deterministický kontrolní JSON. Nejde
o oficiální PPZ/HOZ ani o elektronické podání; výstup vzniká jen ze všech
aktuálních schválených neměnných revizí měsíce a před stažením se znovu
ověřují jejich hashe a kontrolní součty.

Záložka **Inbox** shrnuje napříč agendami a prostředím vše, co aktuálně
vyžaduje pozornost: blížící se nebo prošlou lhůtu, odmítnuté podání, čekání
na ztotožnění nebo jiný vzdálený problém. Odznak u záložky ukazuje počet
otevřených položek. Jde o čistě odvozený přehled — potvrzení ani odložení
nikdy nemění stav povinnosti ani podání, jen připomínku samotnou. Jednou
dosažená naléhavost (blíží se → dnes → po lhůtě) se u položky už nikdy
nesníží, ani když se zdánlivě zmírní. Položku lze **potvrdit** (beze změny
zmizí z pozornosti, zůstane ale vidět jako vyřízená) nebo **odložit** na
zvolený termín s povinně vyplněným důvodem; po uplynutí termínu se znovu
vrátí mezi otevřené. Jakmile podání skutečně dojde k výsledku (přijato,
zrušeno v termínu), položka automaticky zmizí jako vyřešená.

## 58.16 Oprávnění a citlivé údaje

Sekci mohou číst pouze interní role s oprávněním `payroll`. Nastavení aktivace
a zaměstnavatele vyžaduje `payroll.settings`. API nového modulu je dostupné
jen z přihlášené webové relace, ne přes běžný bearer token.
Zakládání vztahů, nové verze podmínek, stavové přechody a checklist vyžadují
`payroll.employment.write`; bez něj je detail pouze pro čtení.
Změna osobní karty a ověření výplatního účtu vyžadují `payroll.person.write`.
Archiv dokumentů vyžaduje `payroll.documents`; bez práva zápisu nelze vytvořit
měsíční balíček.
Platební závazky, dávky a párování vyžadují samostatné oprávnění
`payroll.payments`.
REGZEL profil, příprava, historie a stažení používají samostatné oprávnění
`payroll.submissions`; zápis a čtení se rozlišují. Všechny route jsou dostupné
jen z přihlášené webové relace a bearer token odmítnou.
Agenda srážek má samostatné oprávnění `payroll.enforcement`; právo pro běžné
mzdy ani původní Mzdovou rekapitulaci samo o sobě přístup k těmto údajům
nedává. Změna měsíčního insolvenčního režimu vyžaduje také
`payroll.insolvency` a právní přechod s dokumentem oprávnění `documents`.

Běžný seznam ani detail neposílá rodné číslo, adresu nebo bankovní účet.
Citlivé mzdové identifikátory se ukládají kontextově šifrované pro konkrétní
firmu a osobu; vyhledávací otisk nelze použít ke spojování stejné hodnoty mezi
firmami. Citlivé hodnoty a mzdové částky se redigují z provozních logů.

## 58.17 Legislativní pravidla mezd

Sazby, hranice, lhůty a číselníky, ze kterých mzdový výpočet čerpá, jsou
rozdělené do samostatných oblastí (daň z příjmů, sociální a zdravotní pojištění,
hranice a minimální mzda, průměry a náhrady, cestovní náhrady, exekuční srážky,
termíny, číselníky a verze podání). Každá oblast má vlastní verze s obdobím
účinnosti. Otevřete je na **Mzdy → Legislativní pravidla**.

Ověřená sada je součástí aplikace a používá se, dokud ji nikdo nezmění. Na téže
obrazovce ji lze přepsat, aniž by se čekalo na novou verzi programu — uloží se
jen změněné hodnoty, ostatní se dál berou z ověřené sady. Tlačítko **Vrátit
ověřenou hodnotu** ruční úpravu zahodí. Hodnoty jsou národní a společné pro
všechny firmy, takže je smí měnit jen superadmin; ostatní je vidí ke čtení.

Peníze se zadávají v korunách a sazby v procentech; převod na vnitřní jednotky
řeší aplikace.

Verze prochází stavy **Rozpracováno → Technicky zkontrolováno → Odborně
schváleno → Účinné → Nahrazeno**. Výpočet čerpá jen z účinné verze — dokud
verzi někdo neuvede do provozu, modul ji odmítne použít. Před schválením a
uvedením do provozu se kontroluje, že v období účinnosti dané oblasti nevzniká
mezera ani překryv a že uložené hodnoty odpovídají svému kontrolnímu součtu;
dokud kontrola neprojde, obrazovka u akce ukáže konkrétní důvod.

Ke každé verzi je vidět rozdíl proti ověřené sadě (co přibylo, co zmizelo a jak
se změnila hodnota) a historie změn: kdo, kdy, co a proč změnil. Historii nelze
mazat ani přepisovat. Pokud verzi schválí tentýž člověk, který ji upravil,
změna projde, ale obrazovka na to upozorní.

## 58.18 Vztah k Mzdové rekapitulaci

Mzdová rekapitulace zůstává součástí základní agendy na adrese
**Účetnictví → Mzdová rekapitulace**. Její formulář, automatické měsíční
zaúčtování a mzdový list fungují dál beze změny a nejsou podmíněné budoucí
licencí úplných mezd.

Nový modul nad společnou kartou pouze doplňuje další údaje. Ochrana období
zabrání tomu, aby stejnou firmu a měsíc uzavřela legacy rekapitulace i nový
mzdový běh.
