# 79. Rychlý měsíční vstup

## 79.1 Účel

Rychlý měsíční vstup umožňuje zadat jednorázové částky a jednotky pro více zaměstnanců v jednom období bez otevírání každého vztahu zvlášť.

## 79.2 Předpoklady a oprávnění

Musí být vybraný správný měsíc, existovat aktivní vztahy a nastavené mzdové složky. Uživatel potřebuje mzdové oprávnění a podklad pro každý údaj.

## 79.3 Krokový postup

1. Otevřete **Mzdy → Rychlý měsíční vstup** a ověřte firmu a období.
2. Projděte zaměstnance a doplňte základ, přesčas a odměnu tam, kde se něco mění.
3. Zadejte částku nebo počet jednotek v očekávaném formátu.
4. Jedním tlačítkem uložte celou sadu; nemusíte ukládat po stránkách.
5. Porovnejte souhrn se zdrojovým podkladem a přepočítejte otevřený běh.

## 79.4 Stavy

Uložený vstup čeká na zpracování během; podle oprávnění uživatele vznikne rovnou
jako schválený, nebo jako koncept ke schválení. V otevřeném běhu se projeví po výpočtu. Po uzavření nelze změnou vstupu přepsat archivovaný výsledek; je nutný podporovaný opravný postup.

## 79.5 Kontroly a bezpečnost

Kontrolujte období, souběžný vztah, znaménko, jednotku a duplicity. Hromadné zadání zvyšuje riziko záměny osoby; před uložením používejte kontrolní součet. Zdrojovou přílohu nesdílejte mimo oprávněný okruh.

## 79.6 Časté chyby

- Vstup v jiném měsíci nebo u jiného vztahu.
- Duplicitní ruční zadání již importované částky.
- Jednorázová částka vložená do pravidelné složky.
- Oprava vstupu bez nového výpočtu.

## 79.7 Návaznosti

Význam složek popisuje [kapitola 58p](91_Mzdove_slozky_a_vstupy.md). Výsledek ověřte v [mzdovém běhu](80_Mzdove_behy.md) a následně v [platbách](82_Platby_a_uhrady.md).



## 79.8 Podrobný pracovní postup a kontroly

V **Mzdy → Rychlý měsíční vstup** vybereš měsíc a upravíš všechny účinné
pracovní vztahy na jedné stránce. U každého zaměstnance se zobrazí jméno,
maskované rodné číslo, typ vztahu, základní mzda nebo odměna ze vztahu,
přesčas a bonus či další odměna. Pracovní poměr, DPP, DPČ, závislý příjem
společníka a odměna za výkon funkce zůstávají v samostatných řádcích a systém
je neslučuje.

Nad tabulkou je pole pro **hledání podle jména nebo osobního čísla**. Hledá
se v celém měsíci, ne jen na zobrazené stránce, a nezáleží na velikosti
písmen ani na diakritice; stránkování i počet řádků se pak týkají jen
výsledku. Hledaný text zůstává v adrese stránky, takže ho lze sdílet odkazem.
Rozepsané a neuložené změny hledání nezruší: **Uložit měsíční podklady** je
pošle i tehdy, když řádek aktuální hledání nevrací.
Náhled hrubé mzdy se přepočítává okamžitě; další již existující mzdové vstupy
jsou v něm zobrazeny samostatně. Do hrubého náhledu vstupují všechny složky
zařazené jako zdanitelný příjem včetně nepeněžních. Osvobozené náhrady a jiné
složky mimo hrubý příjem se zobrazí zvlášť a do součtu se nepřičtou. Složka
s neuzavřeným daňovým zařazením vytvoří ruční kontrolu. Jde pouze o náhled
hrubých složek, nikoli o výpočet čisté mzdy; ten vznikne až ve mzdovém běhu.

**Nabídnutá měsíční mzda je už zkrácená o evidované absence.** Mzda přísluší za
vykonanou práci (§ 109 odst. 1 zákoníku práce), takže neodpracovaná doba do ní
nepatří. Poměr se počítá z **naplánovaných hodin individuálního rozvrhu**, ne
z počtu pracovních dnů: při nerovnoměrném rozvržení by deset zameškaných dnů
vyšlo stejně jako deset jiných dnů téhož měsíce, i když je za nimi jiný počet
hodin. Pod polem je vidět, kolik hodin bylo odečteno a z jakého měsíčního fondu.
Zkrácený úvazek se počítá ze svého vlastního fondu, ne z obecného
čtyřicetihodinového týdne.

Odečtené hodiny nezůstanou nezaplacené — nahradí je vlastní složka podle titulu:
[náhrada za dovolenou](76_Absence_a_dovolena.md) podle § 222, náhrada při
dočasné pracovní neschopnosti podle § 192, nebo náhrada za jinou placenou
překážku. Dobu krytou dávkou nemocenského pojištění a neplacené volno
zaměstnavatel neplatí. Každá naplánovaná hodina je tak vyplacena právě jednou.

Svátek, který připadl na obvyklý pracovní den, měsíční mzdu **nekrátí**
(§ 115 odst. 3 zákoníku práce) a do fondu se proto nezapočítává. Výjimkou je
svátek v době nemoci: za ten náleží náhrada podle § 192 odst. 1, takže se z
základní mzdy odečte, aby nebyl zaplacen dvakrát.

Když si aplikace jistá není, **žádnou částku nenabídne** a vyžádá ruční zadání:
chybí pracovní kalendář, o absenci v měsíci se ještě nerozhodlo, nemoc nemá
zmrazený výpočet náhrady, nebo si evidence odporuje. Nabídnout v takové chvíli
celou sjednanou mzdu by vypadalo hotově a nikdo by to už nezkontroloval.

Přesčas lze zadat celkovou částkou. Zadání v hodinách je dostupné pouze tehdy,
když má vztah pro dané čtvrtletí schválený průměrný hodinový výdělek. Bez
schváleného podkladu aplikace hodinovou sazbu neodhaduje a vyžádá celkovou
částku. U závislého příjmu společníka, odměny za výkon funkce, DPP a DPČ se
hodinový přesčas nenabízí; použije se doložená celková částka nebo odměna.

Přesčas zadaný hodinami se **rozdělí na dvě části**, protože jsou to dva různé
nároky:

- **dosažená mzda** za odpracované přesčasové hodiny — složka `MZDA_HODINOVA`.
  Počítá se z měsíčního základu děleného **fondem hodin daného měsíce**, ne
  paušální sazbou;
- **příplatek za práci přesčas** podle § 114 — složka `PRIPLATEK_PRESCAS`.
  Počítá se z doloženého průměrného výdělku sazbou z legislativní sady, takže
  se propíše i sazba sjednaná výš než zákonné minimum.

Rozdělení není kosmetika: z mzdového listu musí být vidět, čím byl nárok na
příplatek uspokojen (§ 142 odst. 5 zákoníku práce). Dřív obojí splývalo do
jedné sběrné složky a základ se počítal jinak.

Hodinový režim vyžaduje, aby měl vztah pro daný měsíc **přiřazený pracovní
kalendář** — bez něj není z čeho určit fond hodin a aplikace vyžádá celkovou
částku. Je-li mzda sjednána s přihlédnutím k práci přesčas (§ 114 odst. 3),
přesčas hodinami zadat nelze, protože příplatek ani náhradní volno nepřísluší.
Je-li u vztahu sjednáno **náhradní volno** místo příplatku, uloží se jen
dosažená mzda a příplatková část je nulová.

**Přesčas zadejte buď tady, nebo v docházce, nikdy obojím.** Máte-li přesčas
v rychlém vstupu, schválení měsíce docházky s přesčasovými hodinami se zastaví
a naopak; jeden přesčas nelze vykázat dvakrát.

Ostatní zákonné příplatky, tedy noční práci (§ 116), sobotu a neděli (§ 118),
práci ve svátek (§ 115) a ztížené prostředí (§ 117), zadáte po stisku
**Zadat i příplatky**. Přepínač odkryje příplatkové sloupce pro všechny řádky
najednou a aplikace si jeho stav pamatuje; drží-li některý řádek v měsíci
příplatek, sloupce se ukážou samy. Zadáváte počet hodin za měsíc, u ztíženého
prostředí i počet ztěžujících vlivů. Částku dopočte aplikace ze schváleného
průměrného výdělku (u § 117 ze základní sazby minimální mzdy) a ze sjednané
sazby a ukáže ji přímo u pole. Příplatek, který za měsíc už vznikl ze
schválené [docházky](77_Dochazka_a_smeny.md#7793-zakonne-priplatky-ke-mzde-114-az-118),
tady ručně zadat nejde, protože by se vyplatil dvakrát. Pole, do kterého
příplatek zadat nejde, je neaktivní a místo hodnoty ukazuje **Nedostupné**;
důvod zobrazí popisek po najetí myší. Platí-li stejný důvod pro více řádků,
vypíše se jednou v pruhu nad tabulkou.

**Další mzdové složky ve vlastních sloupcích.** Tlačítko **Zadat i
příplatky** zároveň otevře nabídku sloupců; kdykoli později ji otevře tlačítko
**Složky**. Ve výchozím stavu jsou zaškrtnuté jen zákonné příplatky.
Zaškrtnutím přidáte sloupec pro kteroukoli další mzdovou složku firmy, třeba
`PRIPLATEK_BOZP` z importu docházky. Složky jsou v nabídce seskupené podle
druhu (příplatky a prémie, odměny, hodinová mzda…) a u každé je vidět, u kolika
lidí má v měsíci hodnotu. Předvolba **Kompaktní** vrátí výchozí stav,
**Kompletní přehled** přidá každou složku, která má v měsíci hodnotu. Volba se
pamatuje pro přihlášeného uživatele.

Nově zadat lze jednorázové peněžní složky s uzavřeným zdaněním: příplatky,
prémie, odměny, provize, hodinovou a úkolovou mzdu a ostatní. Pravidelné
složky, benefity, náhrady a cestovné nabídka označí **jen přehled** — sloupec
je ukáže, ale mění se v Mzdových vstupech. Základ, přesčas, bonus a zákonné
příplatky mají svá pevná pole, vlastní sloupec nemají.

Buňka ukazuje částku a ikonu zdroje:

- **šipka dolů** — hodnota z importu, například z docházky;
- **tužka** — ruční přepis importované hodnoty; popisek říká „Z importu X,
  přepsáno na Y“;
- **řetěz** — hodnotu spravuje jiný vstup (pravidelná složka, docházka nebo
  více vstupů) a buňka je jen ke čtení.

**Úprava importované hodnoty je ruční přepis.** Importní vstup se nemaže ani
nemění. Zůstane jako doklad dávky ve stavu zrušeno a vedle něj vznikne ruční
vstup téže složky a téhož období. Do mzdového běhu i do náhledu jde vždy jen
jeden z nich, takže se částka nikdy nezapočte dvakrát. Tlačítko **Vrátit na
hodnotu z importu** přepis při uložení zruší a platnou se znovu stane hodnota
z importu. Přepsat na nulu jde (zadejte 0); prázdné pole importovanou hodnotu
nesmaže. Uzamčenou hodnotu, kterou už zpracoval mzdový běh, lze změnit jen
opravnou revizí. Schválenou hodnotu může přepsat nebo vrátit jen uživatel
s právem schvalovat mzdové vstupy.

**Opakovaný import** hodnoty nepřepisuje naslepo. Stejná hodnota je
duplicita. Změněná hodnota rozpracovaného vstupu se aktualizuje na místě a
druhý vstup nevznikne. Schválený vstup import nezmění a rozdíl ohlásí. Ručně
přepsanou hodnotu import ponechá a ve výsledku napíše, kolik hodnot je ručně
přepsaných. Novou hodnotu z importu si přitom zapamatuje, takže **Vrátit na
hodnotu z importu** vrátí tu z poslední dávky.

Pod tabulkou je **součtový řádek za celé období**: sčítá všechny vztahy
měsíce (při hledání všechny nalezené), ne jen zobrazenou stránku, a započítá
i rozepsané změny. Hlavička, sloupec se jménem a součtový řádek zůstávají
při posouvání tabulky na očích.

Hromadné uložení vytváří běžné vstupy složek `MZDA_MESICNI`,
`PREMIE_PRIPLATKY`, `ODMENA` a zvolených dalších složek, takže nevzniká
paralelní evidence mezd. Opakované uložení stejného měsíce nevytvoří
duplicity.

**Uložení a schválení je jeden krok.** Má-li přihlášený uživatel právo mzdové
vstupy schvalovat, uloží se rozepsané řádky rovnou jako schválené a mzdový běh
je bez dalšího zásahu přebere. Uživatel bez tohoto práva ukládá koncepty, které
někdo se schvalovacím oprávněním potvrdí později — buď po jednom, nebo hromadně
v mzdových vstupech tlačítkem **Schválit N odpovídajících filtru**. Ani u pěti
set zaměstnanců tedy nemusíte schvalovat řádek po řádku.

Ukládá se **celá rozepsaná sada, ne jen zobrazená stránka.** Rozepsané změny
přežijí přechod na další stránku a při uložení se pošlou i řádky z ostatních
stránek. Uložení se posílá po dávkách, takže funguje i pro stovky zaměstnanců.

Selhání jednoho řádku už neshodí uložení celé stránky. Chybný řádek se vrátí
zpět s červeným označením **konkrétního pole** a s důvodem přímo u něj;
ostatní řádky se uloží. Opravte označená pole a uložení zopakujte.

Rozpracované vstupy se mění s kontrolou jejich verze; už zpracovaný nebo
uzamčený vstup formulář nikdy nepřepíše. Hodnotu, kterou už spravuje
pravidelný nebo jiný měsíční vstup (typicky pravidelná složka nebo hodinová
mzda z docházky), formulář zobrazí jen pro čtení s ikonou odkazu; ikona vede do mzdových složek daného vztahu a
po najetí myší ukáže plné vysvětlení. Kontroluje také verzi pracovního
vztahu, takže po souběžné změně smlouvy vyžádá obnovení formuláře. Historický
měsíc zachová vztah, který byl tehdy účinný a později archivován. Při nástupu,
ukončení nebo pozastavení v průběhu měsíce nepředvyplní plnou měsíční mzdu a
vyžádá skutečnou částku za zpracovávané období. Plný měsíční pravidelný předpis
v takovém měsíci také nepřevezme automaticky; zůstane v ruční kontrole, dokud
není doložené správné časové rozpočítání.

Upozornění, která by se opakovala u mnoha řádků, stojí jednou v pruhu nad
tabulkou:

- kolik řádků na stránce má hodnotu spravovanou jiným vstupem (importem
  docházky nebo pravidelnou složkou), s odkazem **Otevřít mzdové vstupy** na
  rozpracované vstupy téhož měsíce;
- u kterých lidí na stránce chybí rodné číslo; jméno otevře kartu osoby, kde
  údaj doplníte;
- u kolika vztahů nejde přesčas zadat v hodinách, protože chybí schválený
  průměrný výdělek. Na počítači stojí tahle věta v hlavičce sloupce
  **Přesčas**, v buňce je tlačítko hodin jen neaktivní.

V řádku pak zůstává jen ikona stavu u pole (koncept, schváleno, uzamčeno,
spravuje jiný vstup) s plným vysvětlením v popisku po najetí myší. Štítek
druhu vztahu a vlastní popisek pole základu mají jen vztahy, které nejsou
běžným pracovním poměrem (DPP, DPČ, zaměstnání malého rozsahu, odměna za
výkon funkce, příjem společníka). Způsob zadání přesčasu přepínáte u každého
řádku malým přepínačem **h / Kč**; volba platí jen pro daný řádek.

Částky zadávej v Kč s nejvýše dvěma desetinnými místy a hodiny s nejvýše
třemi. Prázdná hodnota neznamená nulu; pokud složka v měsíci není, zadej
**0**. Formulář označí konkrétní chybné pole a rozliší chybějící hodnotu,
neplatný formát, záporné číslo a překročení podporovaného rozsahu. Pokud se
uložení nepodaří například proto, že stejný vstup mezitím změnil jiný
uživatel, přesný důvod zůstane viditelný nad formulářem. Před dalším pokusem
načti aktuální měsíc tlačítkem **Obnovit** a změny zkontroluj.
