# 85. Podání a hlášení

## 85.1 Účel

Agenda připravuje vybraná mzdová hlášení, provádí formální kontroly a vede uživatele přes ručně spuštěné odeslání. Pokrývá zejména podporované toky ČSSZ a zdravotních pojišťoven; vytvořený soubor ani datová zpráva nejsou samy o sobě potvrzením věcného přijetí.

## 85.2 Předpoklady a oprávnění

Je nutné oprávnění `payroll.submissions`, způsobilý uzavřený běh nebo schválená revize, úplné identifikátory a správně oddělené TEST/produkční prostředí. Pro ČSSZ TEST použijte pouze testovací profil a certifikát v určeném bezpečném úložišti. ISDS musí být nastaveno pro správnou firmu a prostředí.

**Prostředí podání.** Podání ČSSZ, zdravotním pojišťovnám i datovou schránkou jdou vždy do ostrého provozu úřadu; výchozí volba je všude **Ostrý provoz**. Výběr testovacího prostředí se nabízí jen ve vývojové instalaci (v `cfg.php` `app.env = development`), jinde se místo přepínače zobrazí jen štítek ostrého provozu a server požadavek na test odmítne. Zvolený test je na obrazovce zvýrazněný varovnou barvou. Výjimkou je daňové podání na EPO: akce **Zkontrolovat na EPO** pošle výkaz jen ke kontrole chyb a je dostupná vždy.

### 85.2.1 Podání přes VREP: zmocnění a registrace certifikátu

Odeslání JMHZ a registrací zaměstnanců přes VREP podepisuje osobní kvalifikovaný certifikát toho, kdo podává. ČSSZ podání přijme, jen když je předem vyřízeno následující. Aplikace si to ověřit nemůže, chybu ukáže až protokol ČSSZ.

1. **Zmocnění od každého zaměstnavatele.** Firma (typicky statutár) zmocní účetní v aplikaci [Správa plných mocí](https://eportal.cssz.cz/web/portal/-/sluzby/sprava-plnych-moci) na ePortálu ČSSZ, případně tiskopisem **Plná moc ke službám a tiskopisům ČSSZ**. V rozsahu zmocnění musí být položka **JMHZ – Jednotné měsíční hlášení zaměstnavatele** (zahrnuje měsíční hlášení, registrace zaměstnanců i změny registrace zaměstnavatele), nebo zmocnění ke všem úkonům.
2. **Registrace zmocněnce a certifikátu.** Účetní podá tiskopis **Oznámení o zmocnění k úkonům a službám ČSSZ a Úřadu práce ČR (pro zmocněnou osobu)** s kopií plné moci. Uvede v něm vystavitele a sériové číslo certifikátu přesně podle certifikátu (decimálně nebo hexadecimálně) a variabilní symboly zastupovaných zaměstnavatelů. Při zastupování více firem stačí registrace na jedné OSSZ, místně příslušné zastupující osobě. Jeden certifikát pak pokryje všechny zastupované variabilní symboly.
3. **Variabilní symbol.** Každá mzdová účtárna má od OSSZ přidělený desetimístný variabilní symbol. Vyplňte ho v **Mzdy → Nastavení mezd → Zaměstnavatel** u účtárny.
4. **Obnova certifikátu.** Nový certifikát je potřeba ČSSZ oznámit ještě před vypršením starého (e-Podání USRCERT), po vypršení už jen přes podatelnu OSSZ. Pak ho v MyÚčtu nahrajte, povolte a vyberte znovu ve všech firmách, včetně nového sériového čísla.

Nastavení v MyÚčtu:

1. V **Daně → EPO podání a archív → Certifikáty EPO** nahrajte certifikát P12/PFX (jednou, patří přihlášenému uživateli) a v každé zastupované firmě ho tlačítkem **Povolit pro tuto firmu** povolte. Mzdy i EPO používají stejný trezor, nic se nenahrává dvakrát.
2. V každé firmě otevřete **Mzdy → Podání a hlášení → Certifikát**, vyberte certifikát a do pole **Sériové číslo registrované u ČSSZ** opište číslo z Oznámení o zmocnění. Aplikace ho porovná se sériovým číslem certifikátu v obou zápisech a při neshodě volbu neuloží. Uložte tlačítkem **Uložit volbu**.

Podání přes datovou schránku registraci certifikátu nevyžaduje, zmocnění ano. Datovou schránku zmocněnce je vhodné uvést na Oznámení o zmocnění, jinak ČSSZ podání dohledává ručně a odpověď přijde se zpožděním.

## 85.3 Měsíc — co podat a odeslat

Stránka **Mzdy → Podání a hlášení** ukazuje jako hlavní záložky to, co se
dělá každý měsíc: **Měsíc** (co za měsíc podat a odeslat), **JMHZ**,
**Zdravotní pojišťovny**, **K odeslání** a **Odesláno** (stav odeslaných
podání). Podání mimo měsíční cyklus jsou v nabídce **Mimořádná podání ▾**:
přehled povinností, registrace zaměstnavatele, dohlášení údajů (A3), záměr
slevy, dávky nemocenského, evidenční list DP a ostatní. V nabídce **Další ▾**
je jen správa: Inbox a Certifikát. Na úzkém displeji se záložky zalamují do
dalšího řádku. Každá záložka má vlastní adresu, odkazy na ni platí beze změny.

Celá stránka má **jedno období** nad záložkami; platí pro
všechny záložky a je vidět i v adrese stránky. Bez období v adrese se otevře
nejstarší měsíc s nesplněným měsíčním hlášením, jinak předchozí měsíc.
Oznámení zdravotní pojišťovně (HOZ) z jiného měsíce, které má ještě otevřenou
lhůtu, ukáže akční karta jako samostatný řádek s označením **mimo zvolené
období** — druhý výběr měsíce k tomu není potřeba.

Záložka **Měsíc** (a tentýž panel pod uzavřeným mzdovým během) skládá
za zvolené období jeden seznam: co se generuje a odesílá, komu, jakou cestou a
do kdy, a u toho, co MyÚčto neodesílá samo, také proč. Bez zvoleného měsíce se
otevře nejstarší měsíc s nesplněným měsíčním hlášením (JMHZ, přehled o platbě,
hromadné oznámení) za poslední rok, jinak předchozí měsíc — mzdy se podávají
zpětně.

### 85.3.1 Připravit a odeslat

U povinnosti bez podání je tlačítko **Připravit a odeslat** a vedle něj
**Jen připravit**. Příprava zmrazí podání ze schválené revize mzdového běhu
a přehled zůstane na místě; připravený řádek pak nabídne **Odeslat**.

Tlačítko **Připravit a odeslat vše** nad seznamem pokryje všechny řádky měsíce,
které jde připravit nebo odeslat. Nepřipravené se nejdřív připraví, pak se
v ostrém prostředí ukáže jedno potvrzení se seznamem *co → komu → jakou cestou*.
Po potvrzení:

- **JMHZ** odejde přes VREP ČSSZ (podepsané certifikátem) hned.
- **Přehledy o platbě** všem pojišťovnám a **hromadná oznámení** se zařadí do
  odchozí fronty datové schránky. S Mobilním klíčem je odešle **jedno potvrzení
  v mobilu** — každá zpráva jde do schránky své pojišťovny. Když jedna zpráva
  selže, ostatní odejdou a chyba se ukáže u jejího řádku.
- U **odesílací brány** se každý koncept schvaluje v datové schránce zvlášť
  (tlačítko **Schválit koncept v datovce 1/3**); jednou autorizací pro všechny
  zprávy odesílá jen Mobilní klíč.
- Bez brány i Mobilního klíče zůstanou zprávy ve frontě datové schránky
  k ručnímu odeslání.

Kontrola slevy po lhůtě (kontrola 290) se při přípravě ptá u konkrétního řádku
a čeká na vědomé potvrzení. Druhé řádné JMHZ za týž měsíc aplikace nepřipraví.

Po odeslání Mobilním klíčem zůstane potvrzená relace pět minut otevřená
a aplikace v ní sama dotáhne doručenky odeslaných zpráv — bez dalšího
potvrzení v mobilu a bez čtení schránky doručených zpráv. U odeslaného řádku
bez doručenky je stav **Čeká na doručenku**; nad seznamem je vidět, kolik
odeslaných zpráv z kolika má doručenku, a tlačítko **Načíst doručenky**, které
je stáhne pro všechny zprávy jedním přihlášením Mobilním klíčem. Po načtení se
řádek sám přepne na **Doručeno**. Dokud nějaká ostrá zpráva odeslaná před víc
než hodinou doručenku nemá, ukazuje totéž upozornění i přehled mezd.

Záložky **JMHZ**, **Zdravotní pojišťovny**, **Dávky nemocenského** a
**Evidenční list DP** začínají stejnou akční kartou za své agendy (co, komu, stav, lhůta, jedno tlačítko). Ostatní — podání
předchozím programem, náhledy, právní skutečnosti, oznámení HOZ a ruční
sestavení — je pod **Podrobnosti**; prohlížeč si pamatuje, co máte rozbalené.

Přehled nese **povinnost, ne dokument**. Měsíční hlášení zaměstnavatele (JMHZ) a
přehled o platbě pojistného za každou zdravotní pojišťovnu, u které je v období
pojištěný aspoň jeden zaměstnanec, se v něm objeví hned po schválení mzdové
revize — tedy dřív, než k nim vůbec existuje podání. U každé takové položky je
zákonná lhůta konkrétním datem, stav **nepřipraveno** a tlačítka **Připravit
a odeslat** a **Jen připravit** pro tu agendu, to období a tu pojišťovnu.
Uzávěrka sama žádná podání nezakládá: koncept, který se musí rušit při každé
opravě běhu, by práci spíš přidal.

Povinnost vzniká ze schváleného běhu, ne z prostředí, takže se ukazuje v ostrém
i testovacím režimu. Přepínač prostředí rozhoduje jen o tom, kde se hledá už
založené podání — zkušební podání v testu proto ostrou povinnost neodškrtne.

Za splněnou se položka považuje teprve tehdy, když je podání **odeslané a
přijaté**. Přehled o platbě pojistného a hromadné oznámení zdravotní
pojišťovně jsou splněné **doručením do schránky pojišťovny** (viz 85.7.1);
řádek pak ukáže **Doručeno**. Odeslaný řádek, u kterého se čeká, nese stav
**Čeká na doručení** nebo **Čeká na výsledek** a kanál, kterým zpráva skutečně
odešla. Zrušené podání povinnost nesplnilo: zůstává v seznamu jako nesplněné,
s poznámkou, že se má připravit nové.

## 85.4 Zákonné termíny na přehledu mezd

Panel **Zákonné termíny** na přehledu mezd ukazuje za celou firmu, co je po
termínu a co se blíží: podání, odvody, lhůty u lidí (položky nástupního a
výstupního checklistu), změny k ohlášení, roční vyúčtování daně, dávky
nemocenského pojištění, roční zúčtování záloh a konec platnosti povolení
cizinců. Pro zobrazení stačí oprávnění číst podání (`payroll.submissions`).

**Roční zúčtování záloh** (§ 38ch zákona o daních z příjmů) má v panelu tři
lhůty za každý rok:

- **Zjistit žádosti o roční zúčtování (do 15. 2.)** — počet lidí s příjmem
  v roce, u kterých ještě není rozhodnuto, zda o zúčtování žádají. Po 15. 2.
  položka zmizí, protože požádat už nelze.
- **Provést roční zúčtování (do 31. 3.)** — každý, kdo požádal a zúčtování
  ještě nemá. Kdo musí podat daňové přiznání, se nepřipomíná.
- **Vrátit přeplatek ze zúčtování se mzdou za březen** — provedené zúčtování
  s přeplatkem, který ještě nevyplatil žádný mzdový běh; termínem je konec
  dubna, kdy je nejpozději splatná mzda za březen.

Odkaz vede na **Mzdy → Roční zúčtování** rovnou na daný rok a osobu.

**Konec platnosti povolení cizince** (povolení k pobytu nebo k zaměstnání
zapsané na kartě osoby) se připomíná u lidí s trvajícím vztahem, dokud k němu
není zapsané navazující povolení téhož druhu. Bez platného povolení nesmí
cizinec pracovat. Odkaz vede na kartu osoby, kde se nové povolení zapíše.

Povinnosti jsou rozdělené podle naléhavosti (**Po termínu**, **Dnes**, **Do
pěti dnů**, **Otevřené**) a uvnitř každé fáze **seskupené podle druhu**. Jeden
řádek tak znamená například „Pracovní smlouva / dohoda, 225 osob, po termínu
o 104 dnů (nejstarší)". Počty v souhrnu i u skupin zahrnují všechny položky,
nic se neschovává. Skutečně nový nástup s termínem za pár dní má vlastní
skupinu ve fázi **Do pěti dnů**, takže se mezi staršími položkami neztratí.

Podání a odvody jsou za firmu. Jednotlivá povinnost je rovnou odkaz na
obrazovku, kde se řeší, a víc povinností stejného druhu (třeba odvody více
pojišťovnám) se rozbalí do řádků pod skupinou.

Skupina lhůt u lidí se po rozbalení zobrazí jako seznam osob po stránkách
s hledáním podle jména nebo osobního čísla (na velikosti písmen ani diakritice
nezáleží). U každé osoby je termín, počet dnů po termínu a odkaz na její kartu.

Nevyřízené položky checklistu, u kterých zákon lhůtu neukládá nebo ji aplikace
neodvozuje, jsou ve vlastní sekci **Bez termínu** na konci panelu. Patří sem
například **Registrace ČSSZ / JMHZ** u nástupu před 1. 7. 2026 (registrační
povinnost zaměstnance platí až od tohoto dne, takže termín se nedopočítává;
přihlášku REGZEC ale podat jde, viz [Registrace zaměstnance](#85111-registrace-zamestnance-prezec-a-regzec)),
potvrzení o zdanitelných příjmech bez zapsaného dne žádosti nebo kontrola
exekucí a insolvence. Tyto
položky se nezapočítávají do fáze **Po termínu** ani do ostatních počtů
termínů; skupiny jsou seřazené od největší a rozbalují se stejně jako ostatní.

Povinnosti z doby **před začátkem vedení mezd v MyÚčtu** (nástup, změna nebo
skončení vztahu před prvním mzdovým obdobím v nastavení mezd) vyřídil předchozí
program. Import ani převod mezd je nezakládá, a pokud už na vztahu jsou, karta
vztahu je ukazuje jako **Vyřízeno předchozím programem**. V přehledu termínů,
ve varováních mzdového běhu ani mezi nesplněnými povinnostmi se neobjeví.
Změní-li se začátek vedení mezd, pravidlo se přepočítá samo.

### 85.4.1 Hromadné označení jako splněné

U skupin z checklistu jde položky odškrtnout najednou:

- **Označit vybrané jako splněné** označí osoby zaškrtnuté v seznamu (výběr
  platí napříč stránkami),
- **Označit celou skupinu jako splněnou** označí celou skupinu; když je
  zadané hledání, jen nalezené osoby.

Před provedením je nutné vyplnit **poznámku**, předvyplněnou textem „Vyřízeno
v předchozím zpracování mezd". Je to jediná stopa, proč je položka splněná,
a zapíše se ke každé položce. Každá položka se mění stejně jako při odškrtnutí
na kartě vztahu, tedy se záznamem na časové ose vztahu a v auditu. Velká
skupina se zpracuje po dávkách a průběh je vidět. Položky, které označit nejde
(například **Doplnit datum nástupu** u vztahu bez data nástupu nebo položka,
kterou mezitím změnil někdo jiný), se vypíšou i s důvodem a ostatní se
označí. Už vyřízená položka se přeskočí, opakované spuštění tedy nic nepokazí.

Hromadné označení vyžaduje stejné oprávnění jako odškrtnutí jedné položky
(`payroll.employment.write`). Změny k ohlášení a dávky nemocenského se
hromadně neoznačují, mají vlastní postup splnění.

## 85.5 Fronta „K odeslání"

Záložka **K odeslání** ukazuje na jednom místě všechna připravená podání, která
ještě neodešla — napříč agendami i zaměstnanci a bez ohledu na období. Podání se
do fronty dostane samo tím, že vznikne; nikam se kvůli tomu nepřepíná.

Řádky jsou seřazené podle lhůty a to, co je po lhůtě, je zvýrazněné. Seznam lze
filtrovat podle agendy a přeřadit podle agendy — při větším počtu zaměstnanců
tak jdou všechny registrace vyřídit najednou. U každého řádku je vidět, čeho se
týká, koho se týká (u registrací jméno zaměstnance), do kdy se má podat a v jakém
je stavu.

### 85.5.1 Odeslané podání, na které nepřijde odpověď

Ve frontě zůstávají i podání, která už jednou odešla a čekají na vyjádření
úřadu. Odeslat se z fronty nedají a je u nich uvedeno proč — jsou tam kvůli
případu, kdy se odpovědi nedočkáte: ČSSZ zprávu převezme, ale zpracovat ji
odmítne, například když certifikát, kterým je podání podepsané, není u OSSZ
zapsaný v registru podávajících. Podané pak nic není, jenže povinnost by bez
zásahu zůstala nesplněná a z aplikace by zmizela.

Rozeznat takový případ od běžného čekání aplikace neumí, proto o něm
nerozhoduje sama. Zobrazí odpověď úřadu a nabídne tlačítko **Zahodit a podat
znovu**. To si vyžádá důvod (předvyplní se tím, co úřad odpověděl), pokus
uzavře a podání vrátí do stavu k odeslání, takže je lze podat znovu. Původní
pokus z historie nezmizí — jen přestane bránit dalšímu odeslání. Totéž
tlačítko je i v záložce **JMHZ** u připraveného hlášení.

Řádné měsíční hlášení, které ČSSZ **zpracovala a zamítla**, se po zahození
samo znovu zmrazí s **novým GUID podání** i novými GUID součástí. Pravidla
podání to vyžadují: shodné řádné podání se stejným GUID, variabilním symbolem,
obdobím a balíkem ČSSZ odmítne kontrolou 22. Variabilní symbol, období i obsah
zůstávají stejné a aplikace nový GUID po zahození ukáže. Původní dokument
zůstává v archivu podání jako doklad prvního odeslání. Opravné a stornovací
podání nesou GUID řádného podání, ten se nemění; nový GUID dostanou jen nové
součásti uvnitř opravného podání. Podání, které čeká na odpověď a výsledek
zpracování nemá, si po zahození GUID ponechá.

Zahodit nejde podání, které úřad přijal nebo přijal částečně. Tam už u úřadu
něco je a opakované odeslání by vyrobilo duplicitu; opravuje se opravným
podáním (viz § 85.9).

Stejně tak nejde zahodit zprávu, kterou **datová schránka prokazatelně
doručila** — tedy takovou, u níž je stažená doručenka nebo potvrzené dodání.
Adresát ji má, takže druhé podání téhož by u něj založilo duplicitu. Takové
podání se ve frontě „K odeslání" ani neukazuje: není z čeho vést cestu ven.
Řešíte-li chybný obsah, použijte opravné nebo stornovací podání.

Z fronty zmizí i podání pod povinností, která je už uzavřená. Připravená
podání tam ale zůstávají vždy, i k uzavřené povinnosti — opravné hlášení se
odesílá pořád odtud.

#### Podání „možná doručeno"

Když požadavek na ČSSZ odejde, ale odpověď nedorazí (vyprší čas, spadne
spojení, brána vrátí chybu serveru nebo nečitelnou odpověď), nedá se poznat,
jestli ČSSZ zprávu převzala. Pokus proto dostane stav **Možná doručeno**
a podání se samo znovu neodešle: druhé odeslání by u ČSSZ mohlo založit
duplicitu. Tlačítko **Odeslat** je u něj nedostupné a fronta i **Stav
odeslání** ukážou u podání postup:

1. **Dohledejte protokol.** Hledejte zprávu od ČSSZ v datové schránce firmy
   (tlačítko **Otevřít datovou schránku**) nebo přehled podání na ePortálu
   ČSSZ. Protokol načtěte tlačítkem **Načíst protokol**; aplikace ho s podáním
   spáruje podle GUID podání. Nese-li pokus CorrelationID (potvrzení převzetí
   dorazilo a selhal až zápis), aplikace se na výsledek doptává sama.
2. **Potvrďte opakování**, jen když protokol nikde není. Vyplňte, jak jste
   ho hledali, a zaškrtněte potvrzení. Potvrzení samo nic neodesílá; podání
   se vrátí mezi připravená a odejde **tentýž zmrazený dokument se stejným
   GUID**. Je-li k podání načtený protokol se stejným GUID, aplikace opakování
   odmítne, protože originál je u ČSSZ.

Kdyby originál u ČSSZ přece jen byl, odpoví ČSSZ na opakované odeslání chybou
**20022** („shodné podání už existuje"). Aplikace ji nebere jako zamítnutí:
podání zůstane odeslané, u pokusu se ukáže **Originál podání je u ČSSZ**
a úkolem je doložit nebo načíst protokol originálu. Znovu se neodesílá.

### 85.5.2 Hromadné odeslání

Zaškrtávacím políčkem vyberte položky, nebo použijte políčko v hlavičce tabulky
a vyberte všechno, co lze odeslat. Tlačítkem **Odeslat vybrané** odejde celý
výběr jedním úkonem. Vybrat lze jen položky, které odeslat jde; u ostatních je
políčko nedostupné a důvod stojí u řádku.

Během odesílání je vidět průběh („Odesílám… 50 ze 120“). Dávka se odesílá po
částech, takže dlouhé odesílání nezablokuje prohlížeč ani nespadne na časovém
limitu. **Jedna chyba dávku nezastaví**: co selže, zůstane ve frontě i s důvodem
a dá se poslat znovu. Po dokončení se ukáže souhrn („Odesláno 37, selhalo 3.“)
a jmenovitý seznam toho, co neprošlo, i s důvodem.

Jednotlivé podání jde poslat i samostatně tlačítkem **Odeslat** na řádku.

Potvrzení o převzetí není potvrzení o přijetí — výsledek zpracování se dotahuje
samostatně a najdete ho ve **Stavu odeslání**.

### 85.5.3 Kontrola změn u všech zaměstnanců

Tlačítko **Zkontrolovat změny u všech zaměstnanců** projde pracovní vztahy celé
firmy a založí povinnost u těch, kde se od minulé kontroly změnil hlásitelný
údaj. Bez něj se změna zjistí jen tehdy, když někdo otevře kartu konkrétního
zaměstnance — a osmidenní lhůta by mezitím mohla uplynout, aniž by o ní kdokoli
věděl.

Kontrola se dá pouštět opakovaně: porovnávají se jen vztahy, u kterých se zdroj
opravdu pohnul, takže se povinnosti nezakládají dvakrát. Když je vztahů hodně,
hlásí kontrola, že další čekají — spusťte ji v tom případě ještě jednou.

Na tlačítko ale nikdo spoléhat nemusí: totéž projde **každou noc sama** plánovaná
úloha `cron-payroll-registration-changes` (denně v 05:00), a to u všech firem se
zapnutými mzdami. Denní běh stačí, protože lhůta je osm dnů — změna zachycená až
ráno nechává sedm dnů na vyřízení. Úloha **nikdy nic neodesílá**: založí jen
návrh povinnosti s termínem, který uvidíte tady ve frontě a v přehledu termínů.
Odeslat ho musí člověk. Naplánování úlohy popisuje
[§ 5.5 Cron skripty](05_Po_instalaci.md#55-cron-skripty), její stav najdete
v **Systém → Plánované úlohy**.

Fronta ukazuje i podání, která odeslat nejde, a u každého uvádí důvod: typicky
že podání ještě není zmrazené, že už bylo odesláno, že už čeká v odchozí frontě
datové schránky, že má neopravené chyby, nebo že pro danou agendu aplikace
odesílací kanál nemá. Které agendy aplikace odesílá sama, popisuje § 85.8 a dál.

Fronta je druhá cesta k témuž: odesílací tlačítka na kartě pracovního vztahu,
ve **Stavu odeslání**, na kartě nemocenského případu i ve zdravotním panelu
fungují dál a odesílají stejnou cestou.

Přehledy zdravotním pojišťovnám mají datové schránky doložené jen pro ostré
prostředí, takže se v testovacím prostředí ve frontě zobrazí jako neodeslatelné.

## 85.6 Krokový postup

1. Otevřete **Mzdy → Podání a hlášení**, vyberte typ, období a schválenou revizi.
2. Spusťte náhled nebo kontrolní přípravu a odstraňte blokující chyby. Řádné JMHZ vzniká ze způsobilé běžné revize. Opravu nebo storno již připraveného JMHZ založte z jeho historie řízenou akcí; nejde o ruční nepodporovaný scénář.
3. Vytvořte výstup. U zdravotního PPZ i HOZ aplikace podle pojišťovny připraví podporovaný XML nebo vytěžitelný PDF; XDP není odesílaný formulář. Kde je doložený úřední tiskopis, vyplní se rovnou on, jinak vznikne vlastní čitelná sestava s uvedeným důvodem. Životní události, které datová věta nepokrývá, dokončete ručně na oficiálním kanálu.
4. Pro ISDS stiskněte **Odeslat přes ISDS**. Aplikace vytvoří záznam v outboxu a provede předběžnou kontrolu, ale zprávu sama neodešle.
5. Otevřete koncept na oficiálním rozhraní ISDS, přihlaste se metodou, kterou ISDS v daném prostředí skutečně nabídne, zkontrolujte adresáta a přílohy a odeslání výslovně potvrďte.
6. Alternativně použijte podporovaný profil VREP pro ČSSZ. Přihlašovací a certifikační údaje zadávejte jen do určených polí, nikdy do poznámek.
7. Inbox načítejte pouze ručně. Před načtením vždy potvrďte, že rozumíte tomu, že přístup ke zprávě může způsobit její doručení. Potom přiřaďte odpověď k podání a ověřte věcný výsledek.

## 85.7 Stavy

Návrh čeká na doplnění, připravené podání prošlo lokální kontrolou, outbox čeká na uživatelskou akci a koncept čeká na potvrzení v ISDS. Odesláno popisuje transport. Doručeno dokládá doručení datové zprávy, nikoli přijetí obsahu institucí. Přijato, odmítnuto nebo vyžaduje opravu určete až z doručenky, odpovědi či stavu cílového systému.

### 85.7.1 Když úřad výsledek neposílá

U přehledu o platbě pojistného a hromadného oznámení zdravotní pojišťovně
žádná strojově čitelná odpověď nedorazí. Podání je učiněné **dodáním do datové
schránky pojišťovny**; vadu pojišťovna oznámí samostatnou výzvou, kterou
evidujete jako výzvu k podání.

Jakmile je dodání doložené (datová schránka potvrdí doručení nebo je
k podání připojená doručenka), aplikace povinnost **uzavře sama**: termín se
překlopí na **Splněno** a Měsíc ukáže **Doručeno**. Stav podání zůstane
„odesláno" a odchozí zpráva si nese jen doručení — výrok o přijetí obsahu
aplikace nevymýšlí. U měsíčního hlášení ČSSZ to neplatí, tam měsíc uzavírá
protokol ČSSZ.

Když doručení doložené není (zpráva odešla mimo aplikaci a doručenka chybí),
je v přehledu podání u řádku věta *„Úřad výsledek zpracování neposílá,
potvrďte vyřízení sami"* a tlačítko **Označit za vyřízené**. Vyžádá si poznámku, čím je vyřízení doložené (číslo zprávy, datum
doručenky); ta zůstane v historii, aby bylo poznat, že měsíc uzavřel člověk
a o co se opřel.

Uzavírá se tím **povinnost**, ne stav podání: podání zůstane „odesláno",
protože úřad se k němu nevyjádřil a tvrdit opak by byla nepravda. Termín se
překlopí na **Splněno** a řádek zmizí z fronty „K odeslání".

Tlačítko se objeví jen tam, kde je doložené, že odpověď nepřijde. U měsíčního
hlášení ČSSZ ani u registrací zaměstnanců ho nenajdete — tam protokol dorazí
sám a aplikace podle něj podání uzavře.

### 85.7.2 Podáno mimo aplikaci

Jiný případ je hlášení, které jste vyplnili a odeslali **na portálu úřadu**,
ne z aplikace — typicky JMHZ přímo na ePortálu ČSSZ. Protokol na ně nikdy
nedorazí, protože aplikace nic neodeslala, a povinnost by visela navždy.

U takového řádku je tlačítko **Podáno mimo aplikaci**. Vyžádá si den podání
a poznámku, čím je doložené (například číslo protokolu z ePortálu). Povinnost
se uzavře jako splněná, ale v historii zůstane výslovně zapsané, že podal
člověk jinudy a kdy — z přehledu tedy nejde usoudit, že hlášení odeslala
aplikace.

Tlačítko se neobjeví u podání, o kterém už úřad rozhodl (není co potvrzovat),
ani když zpráva ještě leží neodeslaná v odchozí frontě datové schránky —
tu je potřeba nejdřív z fronty zrušit, jinak by totéž hlášení odešlo podruhé.

### 85.7.3 Měsíc uzavírá protokol, ne jedno podání

Obsahová oprava měsíčního hlášení řádné podání záměrně nenahrazuje: přijaté
formuláře zůstávají zaevidované, takže řádné podání navždy zůstane „částečně
přijaté". Jakmile ČSSZ v protokolu potvrdí, že je hlášení úplné, uzavře se
celá povinnost za měsíc a termín ukáže **Splněno** — i když u řádného podání
dál svítí „částečně přijato". Obojí je pravda: první o měsíci, druhé o jednom
podání v jeho řetězci.

### 85.7.4 Podání předchozím programem

**Cesta: `Mzdy → Podání → JMHZ`, oddíl *Podání předchozím programem***

Firma, která do MyÚčta přešla z jiného mzdového programu, má v záložce JMHZ
oddíl s podáními, která za ni podal předchozí program: měsíční hlášení JMHZ
a registrace zaměstnanců převzaté převodem z PAMICA
([§ 108.12](108_Prechod_z_PAMICA.md#10812-odeslana-hlaseni-jmhz-a-registrace))
a měsíční hlášení nahraná jako XML v `Mzdy → Importy → JMHZ`.
Oddíl je rozdělený na **měsíční hlášení JMHZ** (seskupená po období)
a **registrace zaměstnanců** (seskupené po měsíci odeslání). U každého podání
je druh, stav, akce s počtem formulářů (u registrací *A1 přihláška*,
*A2 odhláška*, *A3 změna / dohlášení údajů*), jména osob, u registrací den
účinnosti, výsledek (přijato ČSSZ, odesláno bez zaznamenaného přijetí,
neodesláno) a kolik osob se spárovalo se vztahy v evidenci. Tlačítko
**Detail** ukáže všechny formuláře podání: osobu s odkazem na její kartu,
akci a den účinnosti; formulář, který se se vztahem nespároval, nese číslo
vztahu v předchozím programu. Historie je ve výchozím stavu sbalená, rozbalí ji
tlačítko **Zobrazit historii** a aplikace si volbu pamatuje pro každého
uživatele v jeho prohlížeči. Upozornění, která vyžadují akci, jsou vidět vždy.
Firma bez převodu tenhle oddíl nevidí.

**Měsíc, za který řádné hlášení odešlo, MyÚčto znovu nepodá.** Druhé řádné
hlášení za stejný měsíc ČSSZ zamítne jako duplicitní (kontrola č. 22 katalogu
kontrol), proto se ani nepřipraví: příprava skončí hláškou, který program
a kdy hlášení podal, a odkazem na tento oddíl. Opravu takového měsíce pošlete
jako opravné podání z programu, který řádné hlášení podal.

**Zrušené a zamítnuté hlášení měsíc nepodalo.** Řádné nebo opravné hlášení, které
zrušilo nahrané stornující podání téhož programu (storno nese GUID rušeného
hlášení), a hlášení, které podle načteného protokolu ČSSZ zamítla nebo nepřijala,
se za podané nepočítá. MyÚčto pak za měsíc nové řádné hlášení s novým GUID
připraví a měsíc se v oddílu i v hlídači termínů znovu hlásí jako nepodaný, dokud
ho nikdo nepodá.

**Neodeslaný měsíc je upozornění.** Když předchozí program hlášení za měsíc
připravil, ale neodeslal, oddíl na to upozorní: ČSSZ ho nemá a je potřeba ho
podat.

**Převzaté měsíce bez hlášení v historii.** Měsíce, které zpracoval předchozí
program a za které v historii není žádné hlášení JMHZ, oddíl vypíše v jednom
upozornění (například *duben–červenec 2026*). Hlášení JMHZ se podává za období
od ledna 2026; leden až březen 2026 se hlásil zpětně do 30. 6. 2026. Měsíce
roku 2025 a starší proto upozornění nikdy neobsahuje.

Podal-li hlášení předchozí program nebo portál ČSSZ, ale doklad v MyÚčtu není,
použijte **Potvrdit podání mimo MyÚčto**. V dialogu vyberete měsíce, datum
podání a nepovinnou poznámku (například *podáno portálem ČSSZ*). Potvrzený měsíc
se zapíše do historie jako odeslané řádné hlášení se zdrojem *Potvrzeno: podáno
mimo MyÚčto*: přestane se hlásit jako nepodaný v oddílu, v Měsíčním přehledu
i v hlídači termínů a MyÚčto za něj nepřipraví druhé řádné hlášení. Potvrzení
jde vzít zpět akcí **Vzít potvrzení zpět** u záznamu v historii; měsíc se pak
znovu objeví mezi nepodanými. Potvrzení i jeho zpětvzetí se zapisuje do
auditního logu. Potvrdit jde jen v ostrém prostředí a jen měsíc, který oddíl
právě hlásí jako nepodaný.

**Záznam, který převod převzal chybně, jde odebrat.** Volba *Odebrat
z historie* je v nabídce **…** u podání. Dialog vysvětlí, kdy odebrání použít
(podání ve skutečnosti neodešlo, patří jiné firmě, nahrálo se omylem) a co se
stane: za měsíc bez odeslaného řádného hlášení MyÚčto hlášení připraví
a vztahy z odebrané registrace přestanou v Dohlášení údajů (A3) platit za
vyřízené předchozím programem. Podání, které ČSSZ opravdu dostala, v historii
nechte. Po zopakování převodu se záznam vrátí. Odebírá uživatel s oprávněním
zápisu podání (`payroll.submissions`).

## 85.8 Kontroly a bezpečnost

Odesílací brána přesměruje uživatele na oficiální rozhraní ISDS. Podle nabídky
konkrétního účtu tam lze použít například jméno a heslo, heslo aplikace
s bezpečnostním klíčem eGovernmentu, Mobilní klíč eGovernmentu, SMS,
uživatelský certifikát nebo Identitu občana. MyÚčto údaje z této přihlašovací
stránky nevidí a zpráva odejde až po výslovném schválení konceptu uživatelem.

**Brána je jednosměrná.** Umí vložit koncept k odeslání, ale schránku číst
neumí — ke stažení zpráv by potřebovala přihlášení, které vzniká jen tím, že se
uživatel sám přihlásí v perimetru ISDS. Doručenku odeslaného podání proto
stáhněte v datové schránce a nahrajte ji k podání ručně; do té doby zůstane
u podání jako neověřená.

Datovou schránkou z mezd chodí přehledy a hlášení zdravotním pojišťovnám,
měsíční hlášení zaměstnavatele ČSSZ a součinnost exekutorům. **Daňová podání
jdou přes EPO**, ne datovkou; podání odeslané datovkou nedostane potvrzení
s podacím číslem, jen dodejku.

Vedle brány umí MyÚčto odeslat datovou zprávu i přímo, ale výhradně v **živé
relaci, kterou uživatel právě sám schválil** Mobilním klíčem eGovernmentu nebo
SMS kódem. Systémový certifikát firmy ani uložené heslo odesílání neotevírají a
vypršelá relace se sama neobnovuje. Podrobnosti a chování při chybě popisuje
kapitola
[Datová schránka](97_Datova_schranka.md#9742-odeslani-primo-z-aplikace-v-relaci-mobilniho-klice).

Ruční načtení inboxu v **Firma → Datová schránka** má v aplikaci čtyři volby:

- **Mobilní klíč eGovernmentu** — jméno, komunikační kód (heslo aplikace)
  a potvrzení konkrétní relace v klíči;
- **jméno a heslo** — pouze pro jeden synchronní požadavek;
- **SMS** — zahájení jménem a heslem a následné dokončení jednorázovým SMS
  kódem;
- **firemní certifikát** — šifrovaně uložený pouze u právě zvolené firmy.

Technická dostupnost metody není tvrzením o její právní vhodnosti pro konkrétní
organizaci. Heslo a SMS kód se trvale neukládají; uložený profil Mobilního
klíče je oddělen podle firmy, uživatele a prostředí a lze jej odstranit.
Každé přihlášení, vytvoření konceptu, odeslání a načtení inboxu musí vědomě
spustit uživatel. Před načtením inboxu navíc vždy výslovně potvrdí, že rozumí
možnému účinku doručení a spuštění lhůt.

## 85.9 Časté chyby

U právních skutečností JMHZ je u každé výjimky vysvětlení a cesta k nápravě.
Známou kompatibilní změnu specifikace aplikace posoudí automaticky bez
přepisu uložených podkladů. Přijaté hlášení kvůli změně specifikace znovu
neodesílej. V historii odeslání rozlišuj certifikátový pokus VREP/APEP a
podání datovou schránkou; chyba jednoho pokusu sama neruší splněnou povinnost.
ELDP při neúplném podkladu odkazuje na konkrétní měsíc, vztah nebo absenci.
Další postupy jsou v [kontrolách mzdové agendy](999_Reseni_problemu.md#99911-kontroly-mzdove-agendy).

- Považování XML, PDF nebo záznamu outboxu za odeslané podání.
- Záměna testovacího certifikátu či adresáta za produkční.
- Odeslání řádného JMHZ bez schválené běžné revize nebo založení opravy či storna bez vazby na způsobilé předchozí podání.
- Považování doručenky za věcné přijetí bez kontroly odpovědi.
- Automatické nebo neuvážené otevření inboxu bez potvrzení možného účinku doručení.
- Uložení hesla, SMS kódu či privátního klíče do poznámky nebo evidence podání.

Vyjde-li příjmový údaj JMHZ záporně — třeba čistý příjem po přeplatku dovolené
nebo doplatku zdravotního pojištění za celý měsíc nemoci — vykáže se v hlášení
**nula**. ČSSZ zápornou hodnotu u těchto údajů nepřijímá, takže je to jediný
průchodný tvar; ve mzdové evidenci zůstává skutečná částka beze změny. Rozdíl
proti evidenci proto vzniká záměrně a hlášení kvůli němu neblokujeme.

**Sleva na pojistném pracujícího důchodce** (§ 7d a § 7e zákona č. 589/1992 Sb.)
se v měsíčním hlášení vykazuje sama. U zaměstnance, kterému mzdový běh slevu
spočítal, nese formulář příznak **Sleva na pojistném zaměstnance = ANO** a výši
slevy, tedy 6,5 % z vyměřovacího základu zaokrouhlených na celé koruny nahoru.
Pojistná část hlášení uvádí počet zaměstnanců se slevou, úhrn jejich
vyměřovacích základů a úhrn slev, a o úhrn slev snižuje pojistné k úhradě.
Pojistné zaměstnance na formuláři zůstává **před slevou** (7,1 % ze základu),
stejně jako pojistné za zaměstnance v pojistné části. Nárok se zadává v zákonné
evidenci osoby (viz [Zaměstnanci](86_Zamestnanci.md#8682-zakonna-evidence-osoby)).

Má-li důchodce u firmy víc souběžných vztahů, slevu nese jen formulář vztahu,
který nese i pojistné osoby; ostatní vztahy uvádějí příznak NE. Když je v měsíci
účastných na pojištění víc vztahů najednou, hlášení se zablokuje stejně jako
u pojistného a podává se ručně přes ePortál ČSSZ. Slevu pracujícího důchodce
a sezónní slevu na pojistném nelze na jednom vztahu uplatnit současně.

### Jak se plní vybrané údaje měsíčního hlášení

- **Čistý příjem** je zdanitelný příjem po odečtení pojistného zaměstnance
  a zálohy na daň po slevách, bez daňového bonusu. Započítává se do něj i náhrada
  mzdy za prvních 14 dní nemoci, i když je od daně osvobozená.
- **Fond pracovní doby** zahrnuje i svátky připadající na pracovní dny. Svátek,
  ve kterém zaměstnanec nepracoval ani nečerpal jinou nepřítomnost, se při
  potvrzení docházky přičte k neodpracovaným placeným hodinám. Dialog schválení
  docházky počet těchto hodin ukazuje.
- **Náhrady mzdy** mají v hlášení tři údaje: za svátek, za překážky na straně
  zaměstnavatele a za překážky na straně zaměstnance. Používejte proto složky
  **Náhrada mzdy za svátek**, **Náhrada mzdy při překážkách na straně
  zaměstnavatele** a **Náhrada mzdy při překážkách na straně zaměstnance**. Schválená
  placená překážka v absencích a import docházky (návštěva lékaře a překážky na straně
  zaměstnavatele) je zakládají samy.
- **Přesčas** bez příplatku za přesčas se vykáže s příplatkem za přesčas 0,
  jak vyžaduje kontrola ČSSZ.
- **Pravděpodobný průměrný hodinový výdělek** navrhne aplikace u nového vztahu
  bez odpracované mzdy ze sjednané mzdy a nemá-li ani tu, z minimální mzdy.
  Návrh vždy potvrzujete v **Mzdy → Absence a průměry**.
- **Statutární orgán a společník** smějí mít druh činnosti K i N až S;
  hlášení je v tom případě vykazuje ve větvi pro statutární orgány.

**Vyloučené dny podle § 18 zákona č. 582/1991 Sb.** se rozdělují podle důvodu:
nemoc v období, kdy zaměstnavatel platí náhradu mzdy, jde do údaje pracovní
neschopnost; dny, za které vyplácí nemocenské ČSSZ, do údaje vyplacení dávek
(jen když je u nemoci potvrzený nárok na dávku). Stejně se jako vyplacení dávek
vykazuje peněžitá pomoc v mateřství, otcovská a ošetřovné do konce podpůrčí
doby: 9 kalendářních dnů, u zaměstnance, který o dítě pečuje sám, 16 dnů
(zaškrtnutí **Osamělý zaměstnanec pečující o dítě do 16 let** u ošetřování v **Mzdy → Absence a průměry**),
u dlouhodobého ošetřovného 90 dnů. Ošetřování nad podpůrčí dobu je omluvená
nepřítomnost. Nemoc zaznamenaná v již schválené revizi bez období náhrady mzdy
hlášení zablokuje; revizi přepočtěte opravným během po schválení nemoci.

**Pracující důchodce** nemá v hlášení údaje pro evidenční list (třída, dny
a vyměřovací základ ELDP), vyloučené dny podle § 18 ale nese dál. Za
pracujícího důchodce aplikace považuje zaměstnance s ověřenou slevou
pracujícího důchodce v zákonné evidenci osoby.

Měsíc porodu, ve kterém zaměstnankyně pobírá peněžitou pomoc v mateřství a nemá
žádný započitatelný příjem, se vykazuje jako měsíc účasti na pojištění, nikoli
jako vyloučená doba.

## 85.10 Návaznosti

Identifikátory nastavte v [Nastavení mezd](90_Nastaveni_mezd.md). Firemní přístupy, ruční inbox a odchozí zprávy popisuje kapitola [Datová schránka](97_Datova_schranka.md), globální registraci pro odesílání správcem systému pak [Odesílací brána ISDS](98_Odesilaci_brana_ISDS.md). Zdrojová data pocházejí z [mzdového běhu](80_Mzdove_behy.md); kontrolní soubory a doručenky uchovávejte podle [retenčních lhůt](93_Retencni_lhuty.md).



## 85.11 Podrobný tok podání

V **Mzdy → Nastavení mezd → Podání** nejprve potvrď evidenční profil pro
REGZEL. Zadej čtyřmístný kód finančního úřadu `kodFU` z číselníku finanční
správy a čtyřmístný kód jeho územního pracoviště `kodPracovisteFU`. Kód
pracoviště smí zůstat prázdný jen u Specializovaného finančního úřadu
(`kodFU` 4000). Nejde o
tříčíselný kód EPO, například 451; aplikace tyto dva číselníky záměrně
neslučuje. Pokud správce daně firmě přidělil vlastní číslo plátce (VČP),
zadej jeho devět číslic začínajících `6`. VČP není registrační číslo
zaměstnavatele ani desetimístný variabilní symbol ČSSZ; bez skutečného
přidělení zůstává prázdné. Samostatně se eviduje, zda je zaměstnavatel sociálním podnikem,
agenturou práce nebo zaměstnavatelem na chráněném trhu práce. Potvrzení se
vztahuje i na nezaškrtnuté hodnoty; při každém uložení je proto nutné znovu
výslovně potvrdit, že byly ověřeny kódy, případné VČP i všechny tři příznaky.

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
kontrolní; samotný řádek povinnosti ani stažení náhledu nikdy neznamená, že bylo
podání odesláno nebo přijato. Běžné měsíční JMHZ má navíc řízené odeslání přes
ISDS nebo VREP a stav **Přijato** získá teprve z ověřeného protokolu ČSSZ.

Záložka **JMHZ** ukazuje všechny povinnosti vůči ČSSZ, tedy vedle měsíčního
hlášení i registrace zaměstnance a zaměstnavatele, evidenční list důchodového
pojištění a oznámení o zaměstnání osoby pobírající starobní důchod.

### 85.11.1 Registrace zaměstnance PREZEC a REGZEC

Test registrace zaměstnance čte identitu z osobní karty účinnou přesně k datu
nástupu pracovního vztahu. V **Mzdy → Zaměstnanci → Úplná osobní evidence a
historie → Identita a adresy** rozbal u příslušné verze jména část **Údaje pro
registraci zaměstnance** a doplň datum a místo narození, stát narození, státní
občanství, pohlaví a případné tituly. Občanství rozhoduje také o tom, zda lze
použít omezenou předregistraci PREZEC, nebo je potřeba úplná registrace REGZEC.
Náhled i následné zmrazení používají stejný historický zdroj a stejné kontroly;
pozdější změna osobní karty už nemění dříve zmrazené podání.

Úplnou registraci REGZEC s akcí A1 aplikace nepřipraví ani neodešle, dokud
nemá zmrazený povinný druh činnosti a úplnou datovou sadu odpovídající varianty
OST, 10 nebo SPEC. Navazující akce A5 až A8 jsou dostupné pouze pro variantu
OST; u variant 10 a SPEC je aplikace odmítne ještě před schválením události.

**Nástup před 1. 7. 2026.** Událost, která nastala do 31. 3. 2026 a do té doby
nebyla ČSSZ ohlášená, se od 1. 4. 2026 hlásí už jen přes REGZEC (Pravidla pro
REGZEC). Přihlášku A1 proto aplikace připraví i u staršího nástupu, třeba
s datem 15. 2. 2026. Lhůtu podle tehdejších pravidel ale neodvozuje: termínem
je den nástupu, podání je vedené jako po lhůtě a nad podáním je vysvětlení.
Podejte ho bez zbytečného odkladu. Částečné přihlášení PREZEC P1 jde podat až
od 23. 6. 2026 — u staršího nástupu aplikace rovnou nabídne plnou registraci.
Druh činnosti 10 až 16 a výkon trestu jde přihlásit jen s nástupem od
1. 1. 2026; dřívější datum ohlásí kontrola profilu.

**Náhled, příprava a stav přihlášky.** V části **Registrace vztahu na ČSSZ**
jsou dvě samostatná tlačítka: **Zjistit, co se podá** ukáže náhled a lhůtu,
**Připravit podání** je aktivní až po náhledu a teprve ono zakládá úřední
podání. Uplynulou lhůtu náhled označí červeným upozorněním. Když už k vztahu
existuje přihláška, karta to ukáže hned po otevření: připravenou nabídne
tlačítkem **Otevřít ve frontě** místo nové přípravy, u odeslané nebo přijaté
napíše její číslo a stav. Druhou přihlášku téhož vztahu aplikace nezaloží ani
po změně údajů; změny se hlásí změnovým hlášením A3, chyby opravou A4.

**Přihlášení před nástupem.** Zaměstnance je nutné přihlásit před nástupem,
nejdřív osm dnů předem (§ 19 odst. 1 písm. a) zákona č. 323/2025 Sb.).
U zaměstnance s českým občanstvím nabídne náhled volbu **Přihlášení před
nástupem**: výchozí je **Částečné přihlášení (PREZEC P1)** se základními
údaji, zbytek se doplní plnou registrací do osmi dnů po nástupu. Máte-li
profil A1 hotový, zvolte **Plná registrace (REGZEC A1)** a podejte ji rovnou;
v podání je předpokládaný den nástupu. Nastoupí-li zaměstnanec jindy, podejte
opravu A4, nenastoupí-li vůbec, storno A8. Cizinec se přihlašuje vždy plnou
registrací před zahájením práce, volba se u něj neukazuje. Dřív než osm dnů
před nástupem aplikace přihlášku nepřipraví a napíše, od kterého dne to jde.

**Storno přihlášení (A8).** Ve formuláři události A2–A8 zvolte **A8** a důvod
storna. **Zaměstnanec nenastoupil** se oznamuje do osmi dnů od předpokládaného
dne nástupu (§ 19 odst. 4 zákona č. 323/2025 Sb.) a vztah musí být v evidenci
označený jako nenastoupený. **Jiný důvod** (přihlášení pod chybným variabilním
symbolem, druh činnosti, který nejde opravit, soudní zneplatnění vztahu)
zákonnou lhůtu nemá, ČSSZ ho ale zpracuje jen s písemným zdůvodněním —
přiložte ho tlačítkem **Přiložit zdůvodnění**, bez přílohy storno uložit nejde.
U takového storna aplikace ukáže jen milník 20. dne následujícího měsíce: do
něj jde stornovat i měsíční hlášení, později se podává opravné hlášení.

**Ukončení předregistrace (PREZEC P2).** Nenastoupil-li zaměstnanec, kterého
jste přihlásili částečně (PREZEC P1), označte vztah jako nenastoupený a
připravte podání: aplikace nabídne **Ukončení předregistrace (PREZEC P2)**.
P2 musí odkazovat na GUID formuláře původní přijaté P1, jinak ji ČSSZ
nezpracuje a předregistrace zůstane otevřená. Aplikace proto GUID bere z
protokolu ČSSZ, kterým byla P1 přijata, a do P2 ho opíše; ukončení se
připraví až po načtení tohoto protokolu. V P2 jsou jen variabilní symbol,
rodné číslo, GUID P1 a datum vyhotovení, žádné další osobní údaje. Podává se
do osmi dnů od předpokládaného dne nástupu. Byl-li zaměstnanec přihlášen
plnou registrací (REGZEC A1), použijte storno A8.

**Postavení v zaměstnání** se vybírá ze seznamu čtyřmístných kódů Klasifikace
postavení v zaměstnání (NKPZ), kratší kód ČSSZ nepřijme. Aplikace kód navrhne
podle druhu vztahu a doby určité (1111 a 1112 pracovní poměr na dobu neurčitou
a určitou, 1211 a 1212 DPČ, 1221 a 1222 DPP); návrh zkontrolujte proti smlouvě.
U druhu činnosti 15 a 16 nabídka obsahuje jen 1341 a 1342.

**Bližší určení pracovněprávního vztahu** má tři hodnoty: 1 = žádné,
2 = výkon trestu odnětí svobody nebo zabezpečovací detence, 3 = pracovní vztah
specifické skupiny (soudce, poslanec, člen vlády a podobně). Vybírá se jen
u druhu činnosti 1 až 9. U dohod (A až J, T až ZC) a u 15 a 16 evidence
bližší určení nevede a do přihlášky, změny i odhlášky jde automaticky
hodnota 1; u druhu činnosti 10 se neuvádí vůbec.

**Práce probíhá převážně** se vyplňuje jen u zaměstnavatele uznaného na
chráněném trhu práce (zaškrtnutého v profilu registrace zaměstnavatele) a jen
u zaměstnance s vyplněným zdravotním omezením. Jinde ji ČSSZ nepřijímá, proto
se pole ve formuláři vůbec neukáže a do podání nejde.

Tlačítko **Kontrola** v profilu hlídá i osobní údaje, které přihláška nese —
jméno, příjmení, rodné příjmení, datum, místo a stát narození a pohlaví. U každé
vady je tlačítko, které otevře kartu osoby přímo u chybějícího údaje. Odhláška
a dohlášení údajů rodné příjmení, místo a stát narození do věty nepřenášejí,
takže jejich chybění podání dalších akcí nebrání. Má-li
zaměstnanec u firmy další vztah se stejným druhem činnosti a stejným příznakem
zaměstnání malého rozsahu, který se s tímto časově překrývá, ukáže profil žluté
**Upozornění před podáním**: ČSSZ by přihlášku odmítla (chyba 603 nebo 604).
U navazujícího vztahu nejdřív odhlaste ten předchozí, jinak změňte druh
činnosti (například 2 místo 1); obě karty vztahů jsou v upozornění prokliknuté.
Navazující vztahy se od 1. 4. 2026 hlásí každý zvlášť — odhláškou prvního
a přihláškou druhého.

**Údaje, které se mění podle občanství.** U zaměstnance bez českého státního
občanství je povinný orgán, který vydal doklad totožnosti v zahraničí (název
a obec úřadu, například „Municipal office, Preston"; není-li obec známa, stát),
předpokládaná místa výkonu práce (sídlo zaměstnavatele, provozovna, obec) a u
varianty OST také vzdělání požadované pro výkon profese. Krajská pobočka ÚP ČR
je povinná, jen když je druhem pracovního oprávnění povolení k zaměstnání; u
zaměstnanecké karty, modré karty a karty vnitropodnikově převedeného
zaměstnance se neuvádí. U daňového rezidenta jiného státu než ČR je povinný typ
i hodnota zahraničního daňového identifikátoru. U občana ČR se předpokládaná
místa, požadované vzdělání ani daňový identifikátor do přihlášky neposílají,
i kdyby je profil obsahoval: ČSSZ je u českého občana odmítá.

**Cizozemský nositel pojištění.** U druhu činnosti N (smluvní zaměstnanec) musí
přihláška nést oddíl **Cizozemský nositel pojištění** se specifikací P (poslední
nositel) nebo S (současný nositel) a státem; bez něj profil zůstane
rozpracovaný a podání se nepřipraví. U ostatních druhů činnosti varianty OST je
oddíl nepovinný a vyplňuje se, jen když byl zaměstnanec pojištěn v cizině a
firma je jeho prvním zaměstnavatelem po skončení tohoto pojištění. Vyplníte-li
kteroukoli část adresy nositele, jsou povinné i číslo popisné, PSČ a obec.

**PSČ a zdravotní omezení.** PSČ se při uložení profilu zapíše bez mezer
(„602 00" se uloží jako 60200), protože ČSSZ mezery v PSČ nepřijímá. U adresy
pobytu v ČR musí mít PSČ přesně pět číslic. Přihláška unese nejvýš jedno
zdravotní omezení; zadáte-li víc, kontrola řekne, že má zůstat omezení platné
ke dni nástupu.

**Jméno v podání.** Tituly před i za jménem se v přihlášce posílají v jednom
poli (nejvýš 30 znaků, například „Ing. Ph.D."). Dřívější příjmení aplikace
skládá z předchozích verzí jména na kartě osoby, bez aktuálního a rodného
příjmení, od nejnovějšího, oddělená čárkou (nejvýš 100 znaků; příjmení, která se
nevejdou, se vynechají od nejstaršího). **Částečné přihlášení PREZEC** nemá pole
pro stát narození, proto se u osoby narozené mimo ČR píše stát za název obce
(„Bratislava, Slovensko"); k tomu je potřeba mít na kartě osoby vyplněný stát
narození. Místo narození smí mít i se státem nejvýš 50 znaků.

Úplný podklad zadáte na kartě pracovního vztahu v části **Registrace vztahu na
ČSSZ → Autoritativní profil REGZEC A1**, tlačítkem **Doplnit profil**. Profil
obsahuje rozhodné datum a druh činnosti, trvalou adresu, variantní údaje
pracovního místa a podle situace také daňovou rezidenci, zdravotní pojišťovnu,
vzdělání, důchodové skutečnosti a údaje cizince. Vyplňujte pouze údaje doložené
personálními podklady. Server před uložením zkontroluje variantu OST, 10 nebo
SPEC a všechny její povinné vazby; neúplný profil neuloží. Každé úspěšné uložení
vytvoří novou šifrovanou verzi, starší verzi nepřepisuje. Náhled a podání pak
zmrazí přesné ID verze i její otisk, takže pozdější oprava profilu už hotové
podání nezmění.

Profil se vyplňuje **formulářem rozděleným do sekcí** (trvalý pobyt, adresa
pobytu v ČR, kontaktní adresa, daňová rezidence, pracovní vztah, zdravotní
pojištění, důchod, zahraniční legislativa, cizozemský nositel pojištění, doklad
totožnosti, přístup na trh práce a přílohy). Které sekce se zobrazí, určuje
varianta podání a občanství: u varianty 10 odpadá daňová rezidence, zdravotní
pojišťovna i doplňující skutečnosti, u varianty OST naopak přibývá kontaktní
adresa, důchod, zahraniční legislativa, cizozemský nositel pojištění a
vzdělání, a u cizince navíc doklad totožnosti a přístup na trh práce. Občanu EU, EHP nebo Švýcarska aplikace přístup na trh práce předvyplní
jako volný (důvod 1 — § 87 zákona o zaměstnanosti) a povolení k zaměstnání po
něm nechce. Variantu aplikace odvodí z druhu činnosti a bližšího určení vztahu a
napíše ji nad formulář; ručně se nevolí. Úplný JSON zůstal dostupný jako
read-only náhled **Zobrazit, co odesíláme**.

Server **předvyplní, co o osobě a vztahu ví k datu nástupu**, a u každé takové
hodnoty napíše drobným písmem, odkud pochází: z adres osoby, ze zákonné evidence,
z identifikátorů, z identity, z pracovního oprávnění, nebo ze sjednaných
podmínek vztahu. Účetní tedy hodnoty **potvrzuje, nepřepisuje**. Dvě odvození
stojí za zapamatování: adresa bydliště ve státě rezidence se převezme z trvalé
adresy jen tehdy, když se země shodují, a zdravotní pojišťovna se předvyplní jen
tehdy, je-li v evidenci označená jako ověřená. Jinak se obojí hlásí jako
chybějící.

Chybějící údaje se hlásí konkrétně, nikoli domýšlejí. Nahoře je souhrn **Co
aplikace o osobě nevede** a u každého dotčeného pole je místo zdroje žlutá
poznámka, **která rovnou říká, kde se údaj doplňuje** - třeba na kartě osoby
v Adresách nebo v Zákonné evidenci, případně na kartě vztahu. Údaje, které
aplikace nevede vůbec (číslo popisné zvlášť, typ a číslo dokladu totožnosti,
typ zahraničního daňového identifikátoru, režim práce,
vzdělání, průkaz osoby se zdravotním postižením, důchodové údaje a povolení
k práci), o sobě řeknou právě to a vyžádají si ruční opis z personálního
podkladu. Průkaz OZP pro registr přitom není totéž co sleva ZTP/P z daňových
nároků; aplikace je vědomě nezaměňuje.

> ⚠️ Pozor: **uložení profilu nikdy nezapíše nic do karty osoby ani do karty
> pracovního vztahu.** Profil je snímek k datu registrace, ne editor kmenových
> dat. Změníte-li tedy v profilu například adresu, opravili jste podklad
> k registraci, nikoli evidenci osoby - tu je potřeba opravit zvlášť. Z téhož
> důvodu se snímek sám neaktualizuje, když se kmenová data později změní;
> rozdíl se jen ukáže v bloku **Snímek se rozešel s kmenovými daty** s výpisem
> „ve snímku X, v kmenových datech Y". Ukládá se jedním tlačítkem **Uložit
> ověřenou verzi** za celý profil; opakované uložení beze změny novou verzi
> nezaloží. Tlačítkem **Vrátit návrh z kmenových dat** se formulář vrátí
> k předvyplněnému stavu.

Při ukončovací akci REGZEC A2 aplikace prověří také všechna dotčená období od
ledna 2026 do měsíce skončení. Pokud byla mzda za některý měsíc opravena,
vyžaduje aktuální schválenou opravnou revizi, skutečně dokončený přenos jejího
JMHZ, shodnou korelaci důvěryhodné doručenky a přijatý výsledek daného vztahu.
Chybějící, čekající nebo odmítnutý měsíc přípravu A2 zablokuje a uvede konkrétní
období. Při přípravě se celý plán pod zámkem znovu ověří a uloží se jeho
neměnný otisk; pozdější historie se nepřepisuje.

Odhláška A2 nese OIČ a ID PPV, které musí mít doložený původ: protokol o přijetí
registrace, přijaté dohlášení údajů A3, nebo import exportu zaměstnanců
z ePortálu ČSSZ (Seznam zaměstnanců). U zaměstnance převzatého z ONZ, jehož
čísla jste opsali ručně a dohlášení A3 ještě neodešlo, porovnejte čísla se
Seznamem zaměstnanců na ePortálu ČSSZ a ve formuláři odhlášky zaškrtněte
**OIČ a ID PPV jsem ověřil(a) v Seznamu zaměstnanců na ePortálu ČSSZ**.
Odhláška pak projde bez A3; dohlášení A3 zůstává samostatnou povinností
(u skončeného vztahu s datem skončení). Stejné ID PPV nemůže nést jiný vztah
firmy — druhý zápis téhož čísla aplikace odmítne.

Samostatná záložka **ZP — oznámení** řeší oznamovací povinnost vůči zdravotní
pojišťovně, tedy hlášení nástupů, skončení a dalších skutečností v osmidenní
lhůtě. Je to jiná povinnost než měsíční přehled o platbě pojistného, a proto
má vlastní záložku; podrobnosti jsou v oddílu
[Podání zdravotním pojišťovnám](#8514-podani-zdravotnim-pojistovnam).

#### Odeslání registrace přes VREP

Po přípravě registrace zůstává na kartě pracovního vztahu přesně zmrazené XML.
Vyberte **Test** nebo **Produkci** ještě před přípravou a pak stiskněte
**Odeslat do testu** nebo **Odeslat do produkce**. Každé stisknutí založí jeden
doložitelný pokus; aplikace jej sama neopakuje ani se sama neptá na stav.

Po převzetí bránou klikněte ručně na **Zjistit výsledek**. Potvrzení o převzetí
není přijetí registrace — rozhoduje až protokol ČSSZ. Až je protokol načtený,
stiskněte **Uzavřít**, aby se dokončila transakce u brány. Neuzavírejte přenos
během čekání na protokol, jinak by nebylo možné výsledek bezpečně načíst.
Testovací a produkční pokusy jsou oddělené; pracovní vztah není přihlášený,
dokud přijetí nepotvrdí ČSSZ.

Záložka **Ostatní** je záchytná. Zobrazí evidované povinnosti, jejichž agendu
aplikace nezná — typicky zadané ručně nebo importované. Nic se
tak neztratí z dohledu; přípravu ani odeslání pro ně aplikace nenabízí.

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
sociální pojištění a vztahové i osobní součty. Bankovní účet ČSSZ ani připravený
platební závazek nejsou podmínkou hlášení: platba je navazující samostatný tok
a její chybějící účet nesmí blokovat zákonné podání.
Viditelné označení **Pouze kontrolní náhled** znamená, že nejde o úplné XML
JMHZ, připravené podání ani důkaz odeslání nebo přijetí.

Panel **Test měsíčního hlášení JMHZ** postaví z ověřené přípravy úplné XML
běžného měsíčního hlášení a projde s ním trojí kontrolu: sestavitelnost
dokumentu, shodu s připnutým schématem a katalog kontrol ČSSZ. Nic se
neodesílá ani neukládá jako podání.

Při měsíčním daňovém zvýhodnění na děti musí evidence obsahovat jméno,
příjmení a datum narození každého uplatňovaného dítěte. Datum narození se
přenáší do hlášení a slouží také ke kontrole věkové hranice. Příprava ověřuje
pořadí dětí a při společném vyživování další osobou i její identifikační
údaje. U příplatků kontroluje, že jejich celková částka není menší než součet
vykázaných příplatků za noc, víkend a svátek.

Nálezy z katalogu se dělí podle dopadu, ne podle závažnosti textu.
**Nepropustná vada** by způsobila neúčinnost podání a vyvolala výzvu
k opravnému hlášení. **Propustná vada** podání nezneplatní, ale úřady dostanou
chybná data. **Nevykonaná nepropustná kontrola** znamená mezeru na naší straně,
ne chybu v datech. U každého nálezu je kód chyby v podobě, v jaké ho vrátí ČSSZ,
a u nálezu vázaného na konkrétního zaměstnance i pořadí jeho součásti.

Výsledek proto rozlišuje tři stavy: dokument nejde postavit, XML vzniklo
a prošlo schématem, ale katalog kontrol není celý vykonaný, a konečně podání
připravené k odeslání. Prostřední stav je varovný, ne zelený. Část kontrol
rozhoduje až ČSSZ proti svému registru — ty se nikdy nevykazují jako splněné,
jen se počítají zvlášť. Patří sem i kontrola 22 (duplicitní GUID podání):
jestli ČSSZ už má podání se stejným GUID, ví jen její evidence.

Hlášení, které se podává **po splatnosti pojistného** a uplatňuje **slevu na
pojistném zaměstnavatele**, dostane varování kontroly 290. ČSSZ slevu porovná
se slevou v posledním hlášení s akceptovanou pojistnou částí a vyšší slevu po
lhůtě neuzná. Varování zmrazení nezakazuje, ale vyžaduje výslovné potvrzení:
panel **Zmrazení a odeslání JMHZ** i záložka **Měsíc** ukážou větu
s datem splatnosti a výší slevy, odkaz na protokoly ve **Stavu odeslání** (kde
najdete poslední akceptovanou slevu) a na **Mzdové běhy** (kde se sleva
opravuje). Tlačítkem **Sleva nepřevyšuje poslední akceptovanou, zmrazit**
hlášení zmrazíte. Panel zároveň ukazuje lhůtu pro podání za vykazované
období, včetně posunu na nejbližší pracovní den.

Panel **Zmrazení a odeslání JMHZ** navazuje až na schválenou revizi, úplné
právní evidence a úspěšné kontroly. Pro každou registraci u OSSZ pracuje se
samostatnou povinností a variabilním symbolem. Povinnost i zákonnou lhůtu při
prvním zmrazení založí automaticky z ověřené revize; účetní ji nemusí předem
vytvářet v jiné agendě. Před odesláním neměnně uloží
přesné XML a jeho otisk; další kliknutí proto nevytvoří jiné podání pod stejnou
identitou. Ostré podání je zablokované do začátku zákonné lhůty, testovací
prostředí lze použít k bezpečnému testu celého toku.

- **Odeslat přes ISDS** připraví datovou zprávu pro doloženou schránku ČSSZ.
  Je-li aktivní odesílací brána, MyÚčto před přesměrováním vysvětlí přihlášení
  a pošle uživatele přímo do ISDS. Přihlašovací údaje aplikace nevidí ani
  neukládá a zpráva odejde až po schválení konceptu uživatelem v ISDS.
  Konkrétní nabídku metod určuje ISDS a nastavení účtu; může zahrnovat jméno
  a heslo, heslo aplikace s bezpečnostním klíčem eGovernmentu nebo Mobilní klíč
  eGovernmentu. Není-li brána aktivní, připravená zpráva zůstane v odchozí
  frontě pro ruční odeslání a doplnění ID zprávy a doručenky.
- **Odeslat přes VREP** předá stejné zmrazené podání bráně ČSSZ. Výsledek,
  protokol a případné chyby se sledují na záložce **Odesláno**. Převzetí
  transportem ještě není přijetí podání.

Záložka **Odesláno** řadí podání podle období od nejnovějšího. V každém
období stojí nahoře platný stav, tedy poslední přijaté podání se štítkem
výsledku (**Přijato**, **Částečně přijato**, **Odmítnuto**, **Čeká na
výsledek**). Podání odeslané datovou schránkou nese štítek **Odesláno datovou
schránkou** s datem. Podání nahrazená opravným nebo stornovacím podáním jsou
pod tlačítkem **Historie období** se štítkem, kterým podáním byla nahrazena.
Načtený protokol z datové schránky je připojený ke kartě podání, ke kterému
patří; samostatně se ukáže jen protokol, který k žádnému podání jednoznačně
přiřadit nejde. **Opravit hodnoty hlášení** a **Stornovat podání** nabízí jen
platná karta období.

Stav podání převezme aplikace jen z protokolu, jehož podpis ČSSZ ověřila.
Protokol, který ověřením neprošel, zůstane u podání uložený jako neověřený
a podání se nepohne. U takového pokusu ukáže **Odesláno** tlačítko
**Znovu ověřit protokol**. Aplikace uložený protokol ověří úplně stejně jako
čerstvě dotažený (podpis, certifikát ČSSZ, druh podání i CorrelationID) a teprve
když projde, převezme z něj stav podání. Když neprojde, nezmění nic a ukáže
důvod. Opakované kliknutí nic nezdvojí.

Protokol k hlášení podanému jiným softwarem načtete na záložce **Stav
odeslání** tlačítkem **Načíst protokol z datové schránky**. Vedle protokolu
o zpracování jde načíst i dílčí protokol k JMHZ, tedy přílohu
`JMH-DILCI-PROTOKOL-…`. Ten variabilní symbol v podepsané části nenese, proto ho
nahrajte pod původním názvem z datové schránky. Aplikace u něj ověří pečeť ČSSZ
a to, že všechny pracovní vztahy v protokolu (ID PPV) má firma v evidenci. Když
některý vztah chybí, protokol neuloží; načtěte nejdřív měsíční hlášení, ze
kterých se ID PPV doplní.

Před každým odesláním aplikace znovu ověří, že odesílat vůbec lze: podání musí
být ve stavu **připraveno**, musí souhlasit prostředí i kanál a **druh podání
musí odpovídat agendě**, do které míří. Neodpovídající kombinaci odmítne ještě
před tím, než cokoli opustí aplikaci. Zopakované odeslání téhož podání se
stejným klíčem projde i tehdy, když už je odeslané — nevznikne z něj druhá
datová věta. Odesílá se vždy přesně to XML, které bylo zmrazeno; jinou podobu
podání do transportu vložit nelze.

Podaří-li se odeslání, ale nepovede se zapsat jeho evidence, aplikace to
**nehlásí jako nepodáno**. Odeslání proběhlo, a tvrdit opak by účetní svedlo
k druhému podání; místo toho vznikne provozní nález, který je vidět
v provozním přehledu mezd.

Odpovědi z datové schránky se nikdy nestahují automaticky. Jedinou výjimkou
jsou doručenky právě odeslaných zpráv v relaci Mobilního klíče, kterou uživatel
při odeslání potvrdil (viz 85.3.1); novou relaci aplikace nikdy sama
nezakládá. Načtení příchozích zpráv vyvolá uživatel samostatným tlačítkem v
**Firma → Datová schránka** a před síťovým voláním potvrdí upozornění, že
vyzvednutí může založit doručení a spustit zákonné lhůty. Pro toto jediné
načtení si zvolí firemní certifikát, jednorázové jméno a heslo, SMS, nebo
Mobilní klíč: jméno a komunikační kód (heslo aplikace) a potvrzení konkrétní
relace v klíči. Heslo a SMS kód se trvale neukládají; profil Mobilního klíče
lze volitelně uložit šifrovaně a později odstranit.

Pro běžný profil JMHZ se u každé schválené revize samostatně potvrzuje pět
právních skutečností: evidované srážky ze mzdy, slevu zaměstnance pro sezónní
práci, specifickou právní skutečnost, podporu zaměstnávání osob se zdravotním
postižením a hlubinné hornictví. Potvrzuje se **za každý pracovní vztah zvlášť**,
takže revize s víc lidmi (a každá revize přes dvě mzdové účtárny) má tolik
potvrzení, kolik má vztahů; panel ukazuje, kolik vztahů ještě na potvrzení čeká
a kterých se to týká. Každou odpověď **Ne** je nutné zaškrtnout výslovně; nic se
nepředvyplňuje ani neodvozuje z chybějících dat. Aplikace současně ověří, že
schválená revize neobsahuje známý rozpor, například aktivní exekuci, insolvenci,
dohodu o srážkách nebo skutečně sraženou částku. Potvrzení se uloží jako neměnný
šifrovaný důkaz svázaný s přesnou revizí a přesným pracovním vztahem. Vztah bez
potvrzení zůstává adresným nálezem přípravy — je z něj vidět, komu evidence
chybí. Pokud některá skutečnost nastala, tento první běžný profil ji nepodporuje
a přípravu uzavře bez falešného výchozího **Ne**.

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

**Záměr uplatňovat slevu na pojistném (OZUSPOJ) a přihláška.** Záměr lze
oznámit nejdříve měsíc přede dnem, od kterého se sleva uplatní, a **ne dříve
než dnem podání přihlášky zaměstnance** (§ 7a odst. 5 věta druhá zákona
č. 589/1992 Sb.). Aplikace bere den podání přihlášky PREZEC nebo REGZEC
z vlastních podání i z přihlášky předchozího programu a posouvá podle něj
začátek lhůty oznámení. Přijetí záměru s dnem doručení před podáním přihlášky
nezapíše, protože takovou slevu by ČSSZ mohla doměřit. Nezná-li den podání
přihlášky, záměr na to v záložce záměrů slevy upozorní; byla-li přihláška
podána až po lhůtě oznámení, záměr od zvoleného dne oznámit nelze a hláška
vyzve ke zvolení pozdějšího dne.

### 85.11.2 Hlášení změn do registru pojištěnců (A3)

Změní-li se u přihlášené osoby nebo u jejího pracovního vztahu údaj, který
zaměstnavatel do registru pojištěnců hlásí, má na jeho ohlášení **osm
kalendářních dnů** (§ 19 odst. 5 zákona č. 323/2025 Sb.). Dřív na to nic
neupozorňovalo. Nově aplikace změnu sama najde a nabídne hotový návrh
ke schválení.

**Kde se návrhy objeví.** Na kartě pracovního vztahu v části **Registrace vztahu
na ČSSZ** v žlutém bloku **Změny k ohlášení**. Blok se zobrazí jen tehdy, když
je co hlásit. U každého návrhu je, o kterou povinnost jde, termín, věta
**Změnilo se: …** se seznamem dotčených skupin údajů a odkaz na právní pramen.
Konkrétní staré a nové hodnoty se u citlivých údajů (rodné číslo, evidenční
a variabilní číslo pojištěnce, daňový identifikátor, číslo dokladu totožnosti)
nikdy nezobrazují ani neukládají; u nich se hlásí pouze to, že se změnily.

**Kdy detekce běží.** Vždy, když otevřete registrační kartu člověka nebo
přepnete prostředí, a hromadně za celou firmu při otevření přehledu termínů.
Lhůta tedy vzniká, i když kartu vůbec neotevřete. Nic se nespouští při samotném
uložení údaje, takže po opravě karty se návrh objeví až při nejbližším
přepočtu.

**Co detekce porovnává.** Výchozím stavem je poslední podání REGZEC, které
odešlo na ČSSZ (přihláška i každá další změna). Porovnává se s ním profil
registrace A1 a také **kmenová data na kartě osoby a vztahu**: adresa trvalého
pobytu, zdravotní pojišťovna, CZ-ISCO, místo výkonu práce a platnost
pracovního oprávnění cizince. Změníte-li tedy adresu nebo pojišťovnu na kartě
osoby, návrh vznikne, i když profil A1 zůstal beze změny. Kmenová data vedou
adresu jedním řádkem a číslo popisné zvlášť neznají; u nové adresy proto
návrh napíše, že číslo popisné chybí, a tlačítkem **Doplnit v profilu A1**
otevře profil přímo u adresy. Po uložení profilu jde změnu ohlásit jedním
kliknutím. Stejně se chová prodloužené povolení k zaměstnání: nové datum
platnosti aplikace z karty zná, číslo nového rozhodnutí doplníte v profilu.

> ⚠️ Pozor: **změna úvazku ani mzdy se takto nehlásí.** Stanovená i sjednaná
> týdenní doba, měsíční mzda, hodinová sazba, mzdové složky, odpracované
> a neodpracované hodiny, přesčasy i daňové údaje jsou měsíční atributy hlášení.
> Projeví se samy v nejbližším měsíčním hlášení a **žádnou osmidenní lhůtu
> nespouštějí**. Aplikace na ně proto vědomě neupozorňuje: planý poplach
> u položky, která termín nemá, je horší než ticho, protože si na něj účetní
> zvykne a přestane číst i to upozornění pravé.

**Změna zdravotní pojišťovny vyrábí dvě povinnosti.** Vedle registrační akce
vůči ČSSZ vzniká samostatné oznámení zdravotním pojišťovnám podle § 10 odst. 1
písm. b) zákona č. 48/1997 Sb. Měsíční hlášení tu druhou povinnost
**nenahrazuje**. Obě mají vlastní řádek i vlastní termín. Přestup se navíc
hlásí oběma pojišťovnám: dosavadní odhláškou s kódem `O`, nové přihláškou
s kódem `P`. Návrh u změny pojišťovny proto vede odkazem **Připravit HOZ**
na záložku **Zdravotní pojišťovny** za měsíc změny; tam vzniknou obě věty
hromadného oznámení (viz 85.14) a návrh potom uzavřete. Lhůta přestupu vůči
pojišťovně je osm dnů i u dohod o provedení práce a o pracovní činnosti
(výjimka 20. dne následujícího měsíce platí u dohod jen pro nástup a skončení)
a neposouvá se na pracovní den.

**Schválení je jedno kliknutí.** Tlačítko **Ohlásit změnu** se nabídne jen
u návrhu, který datová věta skutečně unese. Neptá se na důvod ani na potvrzení:
obsah je celý odvozený z porovnání, není co doplňovat. Před založením události
se stav ještě jednou přepočítá, aby se neohlásilo něco, co už mezitím někdo
vrátil zpátky.

**Platnost změny a lhůta jsou dvě různá data.** Lhůta osmi dnů běží ode dne
detekce, tedy ode dne, kdy se zaměstnavatel o změně dozvěděl. Platnost změny
(do podání jde jako „platnost od") je den, od kterého nový údaj platí. U
návrhu proto najdete pole **Změna platí od**. Mění-li se jen jméno nebo
občanství, aplikace předvyplní začátek nové verze identity osoby, u ostatních
údajů (adresy, pojišťovna a další) předvyplní den zjištění. Datum před
ohlášením přepište, platí-li změna od jiného dne; lhůta se tím nemění. Změna,
která teprve nastane, se předem schválit nedá, schválí se až v den její
platnosti. Při ruční změně (A3) zadáte stejná dvě data: **platnost od** a
nepovinné **datum, kdy jste se o změně dozvěděli**; bez druhého platí lhůta
od platnosti.

Schválením ale **nic neodchází**. Vznikne registrační událost; podání se z ní
připravuje samostatným krokem a odeslání na ČSSZ je krok další. Postup
odesílání je stejný jako u prvotní registrace.

Jedním kliknutím se ohlásí **jméno a příjmení (s dřívějším příjmením z
historie osoby), státní občanství, titul před jménem, adresa trvalého pobytu,
adresa pobytu v ČR, doručovací adresa, daňová rezidence, doklad totožnosti,
důchod, průkaz ZTP a zdravotní omezení, kód zdravotní pojišťovny, nejvyšší
dosažené vzdělání, přístup cizince na trh práce, stát při trvající příslušnosti
k cizím předpisům a pracovní údaje** — postavení v zaměstnání, režim práce,
nepřetržitý provoz, místo výkonu práce, profese, požadované vzdělání a pozice.
Změní-li se kterýkoli pracovní údaj, odejde celý pracovní blok v aktuální
podobě; změní-li se jméno nebo příjmení, odejde celé jméno. Datum narození,
pohlaví a rodné příjmení se přes A3 neopravují (jde o opravu A4). Ostatní údaje
se ohlásí větou „Tenhle údaj datová věta A3 v aplikaci nenese - podejte ho
jinou cestou a návrh pak uzavřete ručně." Nález se nezahazuje: povinnost i
lhůta existují dál a zůstávají vidět. Jedním kliknutím nelze podat ani
vymazání hodnoty (zánik důchodu, zdravotního omezení), ani víc zdravotních
omezení najednou, ani neúplnou adresu, ani vznik či zánik příslušnosti k cizím
předpisům, který má vlastní akci.

**Daňová rezidence v jiném státě než ČR** se hlásí vždy s adresou bydliště
v tom státě a s druhem a číslem daňového identifikátoru, je-li vyplněn
(jednoznakový kód druhu). Bez adresy ČSSZ změnu odmítne, proto aplikace
návrh neslíbí a pošle vás doplnit adresu do profilu A1. Stejné pravidlo platí
pro přihlášku A1 i pro ruční změnu. PSČ se v podání zapisuje bez mezer
(„110 00" odejde jako 11000); české PSČ musí mít pět číslic.

Ruční změnu založíte v části **Registrace vztahu na ČSSZ** tlačítkem **Nová
událost A2–A8**, druh **A3 · změna údajů** a rozsah **Změna jednoho údaje**.
Nejvyšší vzdělání se tam vybírá ze seznamu.

#### Dohlášení údajů zaměstnanců přihlášených přes ONZ

Zaměstnance přihlášené do 31. 3. 2026 přes ONZ zná ČSSZ bez údajů, které ONZ
nevedla: postavení v zaměstnání, režim práce, nepřetržitý provoz, místo výkonu
práce, profese, pozice, nejvyšší vzdělání a stát daňové rezidence. Zákon je
ukládá doplnit akcí A3. Přijaté dohlášení potvrdí ručně zapsané OIČ a ID PPV
stejně jako protokol k přihlášce. Odhlášku A2 jde ale podat i dřív: s čísly
z importu exportu zaměstnanců z ePortálu ČSSZ, nebo po výslovném potvrzení,
že jste ručně zapsaná čísla ověřili v Seznamu zaměstnanců (viz odhláška A2
výše).

Postup u jednoho zaměstnance:

1. Na kartě pracovního vztahu otevřete **Registrace vztahu na ČSSZ → profil
   A1**, doplňte ho a uložte jako ověřený (tlačítko **Kontrola** ukáže, co
   chybí).
2. Stiskněte **Dohlásit údaje (A3)**. Formulář předvyplní dnešní den — do
   údaje „platnost od" patří u dohlášení den, kdy podání odchází, a hlásí se
   poslední stav údajů.
3. Vyberte rozsah: **Dohlášení údajů – celý profil** (vedle pracovních údajů
   i identita, adresy, pojišťovna, důchod a doklady cizince; tak podávají
   i jiné mzdové programy), nebo **jen údaje, které ONZ nevedla**.
4. **Schválit zdroj a zobrazit náhled**, pak **Připravit podání** a odeslat
   jako každé jiné registrační podání.

Hromadně to jde v **Mzdy → Mzdová podání → Dohlášení údajů (A3)**. Seznam
ukazuje vztahy s přiděleným ID PPV, za které aplikace nepodávala přihlášku A1
a které trvaly v roce 2026, spolu se stavem profilu A1 a stavem dohlášení.
Vybrat jde jen vztahy s ověřeným profilem; u ostatních je tlačítko **Otevřít
profil registrace**, které vede rovnou na kartu vztahu. Zvolte rozsah a den
odeslání a stiskněte **Dohlásit vybrané**. Vada u jednoho vztahu ostatní
nezastaví — důvod se ukáže v jeho řádku. Připravená podání pak odešlete ze
záložky **K odeslání**.

U firmy převedené z jiného mzdového programu seznam ve výchozím stavu ukazuje
jen vztahy, které dohlášení opravdu potřebují. Vztah, za který předchozí
program registraci podal (v historii **Podání předchozím programem** je
odeslaná přihláška A1 nebo dohlášení A3), má štítek **Dohlášeno předchozím
programem** a odkaz na to podání. Stejně se čte vztah, u kterého lhůta
dohlášení (30. 4. 2026) uplynula před prvním mzdovým obdobím v MyÚčtu, a vztah,
který nastoupil po 31. 3. 2026, ale před tímto obdobím: přihlášku i dohlášení
tehdy vyřizoval předchozí program. Volba **Zobrazit všechny vztahy** je ukáže
a ručně dohlásit je jde i tak, třeba když ČSSZ dohlášení nemá.

Dohlášení jde i za vztah, který už skončil. Údaje se pak čtou ke dni skončení
a podání nese i datum skončení, jak to ČSSZ u ukončených vztahů vyžaduje.

Ruční uzavření návrhu tlačítkem vedle **vyžaduje důvod** (1 až 500 znaků). Je to
jediná stopa, proč se touto cestou nehlásilo, takže ji napište věcně.

Návrh po marném uplynutí lhůty **nezmizí**. Zůstává otevřený a v přehledu
termínů se ukáže jako po termínu s počtem dnů; prokliknete se z něj rovnou na
kartu člověka. Pohne-li se stav dál, starý otevřený návrh se uzavře jako
nahrazený, nikdy se nemaže, aby lhůta, která existovala, zůstala dohledatelná.

Detekce má dvě hranice, které je dobré znát:

- **Bez odeslané prvotní registrace se nedetekuje nic.** Porovnává se proti
  poslednímu skutečně odeslanému podání, protože nemá smysl hlásit změnu údaje,
  který úřad ještě nemá. Samotný uložený profil A1 jako základ nestačí.
- **Porovnávají se jen údaje, které nese i to poslední podání.** Údaj, který
  v něm nebyl, se jako změna neohlásí. Aplikace zvlášť vypisuje i hlásitelné
  údaje, u kterých srovnávací základ nemá (variabilní symbol zaměstnavatele,
  název zaměstnavatele, ID PPV přidělované ČSSZ a nositel pojištění v cizině),
  aby byla mezera vidět.

Testovací a produkční prostředí mají návrhy oddělené a nemíchají se.

### 85.11.3 Uklizení neúspěšného pokusu

Pokus, který ČSSZ převzala, ale odmítla zpracovat (třeba proto, že certifikát
není u OSSZ v registru podávajících), zůstane v historii ve stavu **Převzato,
čeká na protokol** a aplikace se dál doptává na výsledek, který nikdy nepřijde.

Jsou na to dvě cesty a liší se tím, co po nich zůstane:

| Akce | Kde | Co udělá |
|---|---|---|
| **Zahodit** (Fronta „K odeslání") | u podání | Pokus dostane konečný stav a přestane blokovat další odeslání. V historii zůstane i s tím, co úřad odpověděl. |
| **Smazat pokus** | u pokusu v historii | Řádek z historie zmizí úplně. Zůstane po něm jen záznam v auditním logu. |
| **Potvrdit opakování** | u pokusu „Možná doručeno" (fronta i Odesláno) | Po dohledání protokolu uvolní odeslání téhož dokumentu se stejným GUID. Pokus zůstane v historii i s důvodem. |

Zahození je běžná cesta — historie pokusů je záměrně úplná, aby šlo dohledat,
co se kdy komu odeslalo. Smazání je pro záznam, který **nic nedokládá** a jen
mate: typicky první nepovedený pokus u podání, které nakonec odešlo jinou
cestou. Tlačítko **Smazat pokus** se proto nabízí jen u pokusu, který úřad
nikdy nepřevzal: pokus připravený a neodeslaný nebo pokus, který selhal dřív,
než ho úřad převzal. Pokus odeslaný na úřad (s časem odeslání nebo
identifikátorem CorrelationID, čekající na protokol, s dotaženým protokolem
nebo „Možná doručeno") je doklad o ostrém podání a smazat ho nejde. **Zjistit
stav** se u uzavřeného pokusu (protokol dotažen nebo propadlo) nenabízí,
výsledek už je známý.

### 85.11.4 Test měsíčního hlášení a nálezy

Tlačítko **Otestovat** v přehledu JMHZ zmrazí přípravu nad schválenou revizí
běhu, sestaví z ní XML a prožene ho schématem ČSSZ i katalogem kontrol. Nic
neodesílá. Když hlášení sestavit nejde, vypíše nálezy seskupené podle příčiny.
U každého nálezu je:

- **co je špatně** (popisek v jazyce aplikace),
- **u koho** (jména dotčených zaměstnanců nebo název účtárny),
- **kde se to opravuje** (krok nápravy a tlačítko, které otevře přesné místo:
  kartu pracovního vztahu s konkrétním polem, absence, průměrný výdělek,
  pracovní dobu, mzdový běh, mzdové složky, potvrzení právních skutečností,
  roční údaje zaměstnavatele v Nastavení mezd na záložce Podání nebo mzdové
  účtárny).

Nálezy, které vzniknou až při sestavení formuláře (například přesčas nad
odpracovanými hodinami, bonus bez podepsaného prohlášení nebo rozpad mzdy bez
mzdy), se hlásí u konkrétního pracovního vztahu stejně jako ostatní. Technická
vada přípravy (změněné podklady, nesouhlasící otisk) nabídne tlačítko **Spustit
test znovu**. Případ, který aplikace záměrně nezpracovává automaticky, řekne,
že se podává ručně přes ePortál ČSSZ.

### 85.11.5 Odložení vztahu z řádného hlášení

Jeden zaměstnanec s neúplnými daty nesmí zablokovat hlášení za ostatní. U
nálezu na pracovním vztahu nebo osobě nabídne test tlačítko **Odložit
z hlášení**. Po zadání důvodu (zapíše se do auditní stopy) se test spustí
znovu a řádné hlášení se sestaví bez formuláře odloženého vztahu.

Co odložení znamená:

- **Je to nesplněná povinnost.** Formulář musí ČSSZ dostat stejně jako ostatní;
  ČSSZ hlášení přijme částečně a k doplnění vyzve. Lhůta řádného hlášení
  (20. den následujícího měsíce) platí i pro odložený vztah.
- **Pojistná část a sleva zůstávají za všechny.** Přehled o výši pojistného
  i sleva na pojistném se uplatní za všechny zaměstnance včetně odloženého,
  protože po splatnosti už slevu uplatnit nelze. Kontroly, které porovnávají
  pojistnou část se součtem podaných formulářů, proto hlásí varování; test je
  označí jako **očekávané** a hlášení projde.
- **Souhrn daní** zahrne i odloženou osobu, pokud má zálohu na daň spočtenou.
- **Souběh:** má-li zaměstnanec v téže registraci víc vztahů, odloží se všechny,
  protože pojistné osoby a souhrnná data nese jediný formulář.
- **Firemní nález odložit nejde.** Chybějící variabilní symbol účtárny, pojistná
  část, souhrn nebo mzdová složka se odložením jednoho vztahu nevyřeší.

Odložené vztahy běhu jsou vidět v seznamu **Odložené vztahy** pod testem se
stavem a lhůtou. Dokud řádné hlášení nezmrazíte, můžete odložení zrušit (opět
s důvodem). Po přijetí řádného hlášení doplňte data vztahu, případně opravte
a znovu schvalte mzdový běh, a zvolte **Doplnit opravným hlášením**. Aplikace
připraví hlášení nad aktuální revizí a zmrazí opravné hlášení, které formulář
odloženého vztahu doplní. Odešlete ho ve **Stavu odeslání** jako každé jiné.
Když data vztahu ještě nejsou úplná, řekne přesně, co chybí.

## 85.12 Storno a obsahová oprava JMHZ

Za jedno rozhodné období existuje právě jedno **řádné** hlášení. Druhý pokus
o řádné hlášení za totéž období — typicky z nové přípravy nad přepočtenou revizí
běhu — aplikace odmítne a odkáže na opravné hlášení; ČSSZ by takové podání
stejně zamítla jako duplicitu. Výjimkou je zamítnuté nebo stornované řádné
hlášení: to se za dané období nahrazuje novým řádným.

Storno JMHZ nevzniká přepsáním původního XML. V **Stavu odeslání** otevřete
způsobilé předchozí podání a zvolte řízenou akci. **Připravit storno** nabídne
dva rozsahy: **Celé podání za období** zruší celé hlášení, **Jen vybrané
pracovní vztahy** připraví opravné hlášení se stornujícími formuláři jen
u zaměstnanců, které vyberete podle jména (ostatní formuláře zůstanou u ČSSZ
platné; storno vybraných vztahů jde jen u úplně přijatého hlášení). Obojí se
nejdřív potvrzuje. **Opravit hodnoty hlášení** pracuje s aktuální přípravou
JMHZ po opravě mzdových údajů a vytvoří skutečné obsahové opravné hlášení.
Aplikace vytvoří nový neměnný artefakt s vazbou na původní podání.

Příprava storna sama nic neodešle. Nový artefakt se ve **Stavu odeslání** ukáže
v oddílu **Připravená podání čekají na odeslání** se svým přesným číslem,
druhem a vazbou na původní hlášení. Odtud jej odešlete tlačítkem **Odeslat přes
ISDS** nebo **Odeslat přes VREP**; aplikace nehledá jiné podání za stejné
období. ISDS nejprve vytvoří odchozí zprávu a teprve další výslovná akce otevře
přihlášení a potvrzení odeslání. Samostatně potom sledujte protokol až do
přijetí. Opakování stejné přípravy vrací již vytvořený výsledek, i když
tlačítko použijete později znovu.

Přijetím storna celého hlášení se jako nahrazené označí řádné hlášení i všechny
jeho dříve přijaté dílčí opravy. Historie tak dál ukazuje celý řetězec, ale za
platné už nepovažuje žádnou jeho zrušenou část.

Jakmile už pro podání existuje odchozí zpráva ISDS, přehled ukáže její číslo a
aktuální stav. Další odeslání přes ISDS i VREP zablokuje, aby účetní omylem
nepodala tutéž datovou větu dvakrát. Pokračujte odkazem **Otevřít odchozí
zprávy**, kde se dokončí přihlášení, odeslání a evidence doručenky.

Ve **Stavu odeslání** nemusíte opisovat GUID ani interní číslo přípravy.
Aplikace nabídne aktuální přípravy pro stejnou firmu, prostředí, období
a mzdový běh (i takové, kde má nález jiný zaměstnanec); jedinou možnost vybere automaticky, z více možností vyberete
ve vyhledávatelné nabídce. Potom spojí neměnné odeslané XML s výsledky
jednotlivých formulářů z přijatých podepsaných protokolů ČSSZ. Neověřený,
neúplný nebo rozporný protokol opravu zablokuje. Zaměstnance vybíráte primárně
podle jména, identifikátory ČSSZ zůstávají zobrazené jako technická kontrolní
stopa. U přijatého formuláře nabídne **Opravit přijaté hodnoty** a odešle jeho
úplné opravené tělo se zachovanou identitou. U odmítnutého, stornovaného nebo
dosud chybějícího formuláře nabídne **Doplnit odmítnutý/chybějící formulář** a
vytvoří novou identitu formuláře.

Vyberete jen vztahy, jejichž obsah chcete změnit, ale souhrn a PVPOJ se při
dopadu kontrolují proti úplnému aktuálnímu setu všech osob firmy. Tím se
pojistný přehled nikdy nepřepočítá jen z vybrané podmnožiny. Oprava posuzuje
jen vybrané vztahy, pojistnou část a souhrn: nález u jiného zaměstnance ji
nezastaví, takový vztah jen nejde vybrat a aplikace ho vypíše zvlášť. Opravu
zastaví jen nález na vybraném vztahu nebo v pojistné části a souhrnu. Hlavička
opravy nese variabilní symbol ze zmrazeného řádného hlášení, takže se oprava
k řádnému hlášení spáruje i poté, co se variabilní symbol účtárny změnil.
Příprava za jiné období se odmítne s uvedením obou období. Příprava opravy
zůstává oddělená od odeslání: nejprve potvrdíte zmrazení přesných bajtů XML a
teprve potom podání odešlete v oddílu připravených podání. Opakovaná stejná
akce vrátí tentýž zmrazený artefakt. Testovací a produkční prostředí mají
oddělené řetězce i idempotenci.

## 85.13 Evidenční list důchodového pojištění

**Evidenční list už není roční povinnost.** Od roku 2026 jej zaměstnavatel
nevyhotovuje ani nepředkládá: údaje pro důchodové pojištění sděluje jednotným
měsíčním hlášením a evidenční list z nich sestaví ČSSZ (§ 38 odst. 1 a 2 zákona
č. 582/1991 Sb. ve znění zákona č. 360/2025 Sb.). Zaměstnanci je dostupný na
ePortálu ČSSZ (§ 39 odst. 1). Žádný úkon „vygeneruj a odešli ELDP za rok" tedy
na konci roku nečekejte — aplikace jej nenabízí a přípravu za takový rok
odmítne.

Tiskopis ale zrušen nebyl a v aplikaci jej připravíte ve třech výjimkách:

- za období **před 1. lednem 2026**, na které se použije dřívější znění zákona,
- u zaměstnání **skončených před 1. dubnem 2026**, na která dopadá přechodné
  ustanovení,
- **na výzvu ČSSZ/ÚSSZ** podle § 38a odst. 2 a 3 — uplynula-li lhůta pro měsíční
  nebo opravné hlášení, anebo nelze-li z nahlášených údajů evidenční list
  sestavit. U výzvy zaškrtněte příslušné potvrzení a zadejte skutečné datum
  jejího doručení. U listu za roky do 2026 od tohoto dne běží lhůta osmi dnů
  (za rok 2025 podle § 39 odst. 3 dřívějšího znění, za rok 2026 podle
  přechodného ustanovení). Za roky od 2027 lhůtu neurčuje zákon, ale výzva:
  formulář si proto vyžádá i **lhůtu uvedenou ve výzvě** a bez ní list
  nesestaví.

Nad formulářem vždy stojí věta, jestli evidenční list pro zvolený rok a pracovní
vztah vůbec vzniká, a proč. Není-li přípustný, věta jmenuje konkrétní důvod —
například že zaměstnání v roce 2026 trvá, nebo že skončilo až po 31. 3. 2026 —
a tlačítko přípravy zůstane nedostupné. Chybějící údaje v evidenčním listu,
který sestavuje ČSSZ, opravte opravným měsíčním hlášením.

Evidenční list se sestaví pro **pracovní poměr** (včetně zaměstnání malého
rozsahu), **dohodu o pracovní činnosti** i **dohodu o provedení práce**. Kód
řádku se skládá z druhu činnosti ČSSZ (např. `1++`, `A++`, `T++`). U dohod se
do doby pojištění počítají jen měsíce, ve kterých se dohoda účastnila
pojištění; ostatní měsíce řádku se vyznačí „X". Dohoda, která se v roce
neúčastnila ani jednou, evidenční list nemá.

### Důchodové údaje zaměstnance

Mzdová revize nenese údaje o důchodu, a přitom na nich stojí kód řádku i to,
zda se list vůbec vede. Formulář je proto chce výslovně potvrdit, i když
nic z toho nenastalo (prázdné pole znamená „nenastalo"):

- **Den dosažení důchodového věku** a **den, od kterého zaměstnanec pobírá
  předčasný starobní důchod.** Od dřívějšího z nich má činnost druhý znak
  kódu `D` (například `1D+`, `AD+`); řádek se k tomuto dni rozdělí na dvě
  sekce. Připadne-li den doprostřed měsíce, aplikace list nesestaví, protože
  vyměřovací základ za část měsíce nevede; takový list podejte mimo aplikaci.
- **První měsíc výplaty starobního důchodu v plné výši.** Od roku 2025 se za
  poživatele plného starobního důchodu evidenční list nevede (§ 38 odst. 1
  věta druhá zákona č. 582/1991 Sb.). Měsíce od tohoto měsíce se z listu
  vypustí; nezbude-li žádný, list se nesestaví a hláška to řekne. Za roky do
  2024 se list za pracujícího důchodce vede dál.
- **Účast na důchodovém pojištění v cizině.** Je-li zaměstnanec účasten
  pojištění v cizině, vede se list i za poživatele plného starobního důchodu.

Má-li zaměstnanec v zákonné evidenci ověřenou slevu pracujícího důchodce,
ale potvrzení žádný starobní důchod neuvádí, aplikace list nesestaví
a vyzve k doplnění údajů.

**Příjem zúčtovaný po skončení zaměstnání** (doplatek, odměna vyplacená
v dalším měsíci) se zapíše samostatným řádkem s kódem `1P+` (u dohody
o pracovní činnosti `AP+`) — jen vyměřovacím základem, bez údajů „Od", „Do"
a bez dnů. Měsíc po skončení bez vyměřovacího základu se do listu nezapisuje.
Skončilo-li zaměstnání už v předchozím roce, vznikne list jen s tímto řádkem.

Pod souhrnem je přehled **Údaje tiskopisu k opisu**: typ evidenčního listu
(`01` za rok nebo na výzvu, `02` při skončení zaměstnání, `51` a `52` opravný),
„zaměstnán od", datum vyhotovení a u každého řádku měsíce „X". Kontrolní XML
tyto údaje nenese, proto je opište spolu s řádky listu. **Datum vyhotovení**
můžete zadat; nesmí předcházet údaji „Do" žádného řádku, jinak by ČSSZ list
odmítla chybou 251 a aplikace ho proto nesestaví. Když datum nezadáte, použije
se konec posledního zúčtovaného měsíce.

Přijde-li výzva ještě v průběhu vykazovaného roku a pracovní vztah trvá,
aplikace sestaví list jen do posledního měsíce, za který existuje aktuální
schválená mzdová revize. To odpovídá metodice ČSSZ: do údaje **Do** patří
poslední den měsíce, za který byl zaměstnanci naposledy zúčtován příjem.
Budoucí měsíce se nevyžadují. Chybí-li ale některá revize uvnitř takto
vymezeného období, příprava zůstane zablokovaná, protože by nebylo možné
doložit souvislou dobu pojištění ani vyměřovací základ.

### Opravný evidenční list

Zmrazený evidenční list se nepřepisuje. Změní-li se podklad po zmrazení
(doplatek, opravná mzdová revize), řádná příprava skončí hláškou, že list je
zmrazený s jiným obsahem. Zaškrtněte **Opravný evidenční list** a list připravte
znovu: vznikne nový list typu `51` (oprava ročního listu) nebo `52` (oprava
listu při skončení) s odkazem na list, který opravuje, a s vlastní povinností
i kontrolním XML. Nezměnil-li se podklad, opravný list se nesestaví. Od té
chvíle panel ukazuje nejnovější list rozsahu.

### Rok přechodu z jiného mzdového programu

Přejdete-li na MyÚčto uprostřed roku, chybí za měsíce vedené původním programem
mzdová revize. Evidenční list se v takovém roce sestaví ze **dvou zdrojů**:
z měsíců spočítaných v MyÚčtu a z převzatých mzdových měsíců, které jste
naplnili převodem z původního systému nebo importem tabulky v **Kontrole převodu
mezd**. Převzatá část je vidět přímo v panelu — vypsaná po měsících, se zdrojem
a s otiskem převzatého řádku — a tentýž otisk je součástí zmrazeného podkladu
listu. Údaje z původního programu se tedy nikdy nevydávají za vlastní výpočet.

Platí přitom tři pravidla:

- **Měsíc se schválenou mzdovou revizí se bere vždy z revize.** Leží-li k němu
  navíc převzatá data, nesčítají se; panel takový měsíc pojmenuje jako rozpor
  a evidenční list stojí na revizi.
- **Měsíc, který MyÚčto počítá, ale nemá schválenou revizi, převzatá data
  nenahradí.** Nejdřív revizi schvalte, nebo rozpracovaný běh zrušte.
- **Nic se nedopočítává.** Chybí-li převzatému měsíci druh činnosti ČSSZ, dny
  účasti, vyměřovací základ nebo souhlasné trvání vztahu, příprava zůstane
  zablokovaná a hláška řekne, který měsíc a který údaj doplnit. Má-li převzatý
  měsíc vyloučené doby jen jako součet, bez rozpadu podle § 16 odst. 4 zákona
  č. 155/1995 Sb., nelze jej do listu zapsat — zaevidujte odpovídající
  nepřítomnosti, nebo evidenční list za dotčený měsíc podejte mimo aplikaci.

Trvání pracovního vztahu drží zmrazená revize: zná-li vztah aspoň jedna
schválená revize roku, převzatá data se proti ní jen kontrolují. Revize za
měsíce před nástupem nebo po skončení vztahu, ve kterých vztah už není,
přípravu neblokují, takže list za vztah ukončený v lednu sestavíte i poté, co
schválíte mzdy za další měsíce.

Skončil-li vztah ještě v době, kterou vedl původní program, žádná revize roku
ho nezná. Trvání listu se pak vezme z převzatých měsíců, ale jen doložené:
datum nástupu i skončení musí být vyplněné aspoň u jednoho převzatého měsíce
a všechny vyplněné údaje se musí shodovat. Prázdné datum skončení může
znamenat, že vztah trvá, i že ho původní program nevydal, proto takový list
zůstane zablokovaný, dokud datum v **Kontrole převodu mezd** nedoplníte.
Stejně zablokovaný zůstane převzatý měsíc, který nese vyměřovací základ až po
skončení vztahu: dodatečně zúčtovaný příjem (řádek „P+") z převzatých dat
aplikace nedoloží a list s ním podejte mimo aplikaci.

Vygenerované XML slouží pouze ke kontrole údajů. Není to transportní datová
věta a MyÚčto je neodesílá ani nevkládá do datové schránky. ELDP dokončete
v aktuálním oficiálním rozhraní ČSSZ a výsledek potom doložte aktivním firemním
dokumentem z DMS, referencí potvrzení a skutečným datem.

Rozlišujte dva výsledky. **Podáno (`submitted`)** znamená, že máte doklad o
podání, ale ještě ne konečné přijetí; povinnost proto zůstává ve stavu čekání
na výsledek. **Přijato (`accepted`)** použijte jen tehdy, když připojený dokument
výslovně dokládá konečné přijetí. Teprve tento důkaz označí zákonnou povinnost
za splněnou. Kontrolní XML přitom zůstává stále jen ve stavu připraveno a nikdy
se nevykazuje jako odeslané.

## 85.14 Podání zdravotním pojišťovnám

Záložka **Zdravotní pojišťovny** začíná kartami
pojišťoven za období: co se podává, kolik a jak je na tom úhrada. Karta už
podané pojišťovny nenabízí **Podat datovkou** znovu (druhé podání by
u pojišťovny založilo duplicitu), ukáže **Podáno** s datem a nechá jen
stažení. Stav úhrady pojistného rozlišuje:

- **Čeká na úhradu … do …** — pojistné ještě není zaplacené a splatnost
  neuplynula; nic nesouhlasí, jen se čeká na platbu,
- **Po splatnosti** — nezaplaceno po splatnosti,
- **Nesouhlasí: doloženo X z Y** — zaplaceno jen zčásti, nebo závazek
  v platbách mezd nesouhlasí s přehledem; nesouhlas závazku brání uzávěrce
  plateb mezd, dokud se nesrovná,
- **Úhrada doložena**.

Když závazek k úhradě ještě nevznikl, stav se neukazuje. Pod kartami jsou
oznámení HOZ za období; možnosti elektronického podání a ruční sestavení HOZ
i PPZ jsou sbalené pod **Podrobnosti a ruční sestavení** (revize je předvybraná
nejnovější schválená).

Soubor podání se jmenuje stejně v příloze datové zprávy i při stažení:
`{AGENDA}_{RRRR-MM}_{příjemce}_{IČO}`, u opravného podání s `_opravne`, např.
`PPPZ_2026-09_VZP-111_12345678.pdf` nebo `JMHZ_2026-09_CSSZ_12345678.xml`.
Název je bez diakritiky a mezer.

Záložky zdravotních pojišťoven oddělují dvě povinnosti:

- **HOZ** je hromadné oznámení zaměstnavatele. Aplikace povinnosti odvodí,
  sestaví z nich datovou větu XML i PDF a obojí zmrazí. Připravený soubor není
  odeslaný — odeslání datovou schránkou musíte potvrdit sami.

Komu se oznámení týká, rozhodují pravidla **zdravotního** pojištění, ne pravidla
ČSSZ (§ 5 písm. a) zákona č. 48/1997 Sb.):

- **Pracovní poměr a zaměstnání malého rozsahu** se hlásí vždy, bez ohledu na
  výši příjmu.
- **Jednatel a společník v závislé činnosti** se hlásí, má-li sjednanou odměnu,
  i pod rozhodným příjmem. Bez sjednané odměny aplikace oznámení nepředpokládá.
- **DPČ** se hlásí, když sjednaná měsíční odměna dosahuje prahu účasti, nebo
  když účast doložil schválený mzdový běh.
- **DPP** se hlásí jen podle schváleného mzdového běhu: o účasti rozhoduje úhrn
  všech DPP za měsíc. Přihláška se váže k prvnímu měsíci s účastí (k jeho
  prvnímu dni, nebo ke dni nástupu, je-li pozdější), odhláška ke dni skončení
  dohody. Měsíce bez účasti mezi nimi aplikace samostatnými odhláškami
  a přihláškami neřeší; když je pojišťovna vyžaduje, podejte je ručně.

Přehled za měsíc vyhodnocuje jen skutečnosti, které v tom měsíci nastaly.
Zaměstnanec s dávným nástupem (i před rokem 1997) proto přehled nezablokuje;
skutečnosti z doby před vznikem veřejného zdravotního pojištění (1. 1. 1993)
povinnost nezakládají vůbec.

U firmy převedené z jiného mzdového programu ukazuje záložka **ZP — oznámení**
i události z měsíců před prvním mzdovým obdobím v MyÚčtu, ale jako
**Oznámil předchozí program**: nepočítají se do dlaždice *Po lhůtě*
a synchronizace do inboxu z nich povinnost nezaloží. Když pojišťovna takové
oznámení nemá, hromadné oznámení za ten měsíc jde připravit ručně.

Kód změny v HOZ se určuje podle skutečnosti a podle zaměstnance:

- **Nástup** má kód `P`. U cizince rozhoduje státní příslušnost na kartě osoby
  a to, zda má v evidenci rodné číslo nebo číslo pojištěnce ZP: občan EU, EHP
  nebo Švýcarska s číslem se hlásí kódem `A`, bez něj jako první přihlášení
  kódem `E`; cizinec ze třetí země bez čísla kódem `C`. U prvního přihlášení se
  místo čísla pojištěnce uvede pohlaví a datum narození (`M05071980`,
  `Z12101982`), takže je musí mít karta osoby vyplněné.
- **Číslo pojištěnce** ve větě je číslo pojištěnce ZP z karty osoby, a když
  není vyplněné, rodné číslo. EČP se nepoužívá: je to evidenční číslo ČSSZ.
  Cizinec, kterého pojišťovna už přihlásila, potřebuje na kartě číslo
  pojištěnce ZP opsané z průkazu pojištěnce nebo z oznámení pojišťovny; bez
  něj aplikace větu nesestaví a řekne, u koho číslo chybí.
- **Skončení** má kód `O`.
- **Přestup k jiné pojišťovně** — změnu zapíšete na kartě osoby (zdravotní
  pojištění od nového dne). Z jedné změny vzniknou dvě věty: u dosavadní
  pojišťovny odhláška s kódem `O` k poslednímu dni pojištění u ní, u nové
  pojišťovny přihláška s kódem `P` ke dni změny. V přehledu povinností je
  u každé z nich napsané, zda jde o odhlášku, nebo přihlášku; hromadné
  oznámení se sestaví zvlášť za každou pojišťovnu. Lhůta je u obou osm dnů
  od změny, i u dohod. Zaměstnanec s několika souběžnými vztahy přestupuje
  jednou, takže vznikne jedna odhláška a jedna přihláška, ne po jedné za
  každý vztah.
- **Jednodenní zaměstnání** — vznikne a skončí týž den — se hlásí jedinou větou
  s kódem `Q`, ne přihláškou a odhláškou.
- **Lhůta dohod.** U DPP a DPČ se nástup, skončení i jednodenní zaměstnání
  hlásí do 20. dne následujícího měsíce; ostatní skutečnosti mají i u dohod
  osm dnů.
- **Mateřská a rodičovská** mají kódy `M` a `U`. Rodičovská, která navazuje na
  mateřskou bez mezery, je jedna nepřítomnost: `M` se hlásí jen na začátku
  mateřské a `U` až na konci rodičovské, přechod mezi nimi se nehlásí. Je-li
  mezi absencemi aspoň jeden den, jde o dvě nepřítomnosti s vlastním `U`
  i `M`. Skončí-li pracovní vztah během mateřské nebo rodičovské, hlásí se ke
  dni skončení vedle odhlášky `O` i `U`.
- **PPZ** je měsíční přehled o platbě pojistného. Ze schválené revize se
  sestaví a zmrazí pouze formát doložený pro vybranou pojišťovnu. Připravený
  soubor není odeslaný. Řádný přehled se podává do 20. dne následujícího
  měsíce. Opravný přehled (z opravné revize ke dříve podanému přehledu) má
  lhůtu 8 dnů ode dne zjištění chyby (§ 25 odst. 4 zákona č. 592/1992 Sb.);
  za den zjištění se bere žádost o opravu mzdového běhu. Bývalý zaměstnanec,
  kterému po skončení vztahu přišel příjem (doplatek mzdy, odměna), je
  v přehledu se základem i pojistným, ale do počtu zaměstnanců se
  nezapočítává, protože v měsíci zaměstnancem nebyl. Plyne-li pojistné za
  měsíc jen od bývalých zaměstnanců, datová věta přehled neumí (počet nula
  nepřijme) a aplikace vyzve k podání na tiskopisu pojišťovny. Nesoulad
  u jedné pojišťovny nebrání sestavit přehled pro ostatní.

### 85.14.1 Kdy vyjde úřední tiskopis a kdy vlastní sestava

Vydání tiskopisů z roku 2026 je jednotné: hromadné oznámení má číslo
`UNI 73.51/2026`, přehled o platbě `UNI 76.51/2026`, ani jeden nemá logo nebo
kód konkrétní pojišťovny. Zveřejňuje je zatím jen VZP; VoZP používá stejná
čísla tiskopisů a stejnou XDP šablonu, takže MyÚčto vyplňuje úřední tiskopis
**pro VZP (111) a VoZP (201)**. Ostatní pojišťovny dál zveřejňují vlastní starší
formuláře, proto pro ně vzniká vlastní čitelná sestava se stejnými údaji.

Vlastní sestava vznikne také tehdy, když se oznámení na tiskopis nevejde:
úřední tiskopis má čtyři bloky vět a natištěné „1/1“ v poli počtu listů, takže
od páté věty se použít nedá. Ve všech případech aplikace důvod pojmenuje —
uvidíte ho u výsledku sestavení i v patce vytištěného dokumentu, nikdy se
nezamlčí.

Formát připravené přílohy se řídí pojišťovnou a obdobím:

| Kód | Pojišťovna | Formát připravený pro ISDS |
|---|---|---|
| 111 | VZP ČR | strojově čitelné PDF |
| 201 | VoZP ČR | strojově čitelné PDF |
| 205 | ČPZP | XML podle zveřejněného schématu |
| 207 | OZP | XML podle zveřejněného schématu |
| 209 | ZPŠ | strojově čitelné PDF |
| 211 | ZP MV ČR | strojově čitelné PDF; nový XML/B2B kanál je oddělený |
| 213 | RBP | XML podle zveřejněného schématu |

ZP MV ČR plánuje nový XML/B2B kanál od 1. 10. 2026, ale pro ISDS výslovně
zůstává podporované strojově čitelné PDF i od roku 2027; MyÚčto proto ISDS
automaticky na XML nepřepíná. RBP připouští XML i vytěžitelné PDF a MyÚčto
volí XML. U VZP a VoZP je XDP šablona pomůcka pro hromadné vyplnění PDF,
nikoli soubor, který by se přikládal k datové zprávě. XSD se rovněž
neodesílá: slouží jen jako schéma, proti kterému aplikace kontroluje XML.
Tato matice popisuje formát zvolený aplikací pro ISDS, nikoli neveřejná
portálová nebo B2B rozhraní pojišťoven.

Pokud panel u PPZ nabídne **Odeslat přes ISDS**, adresát musí pocházet ze
stejného centrálního katalogu pojišťoven jako sestavení souboru. Akci vždy
spustí uživatel; vytvoření záznamu ve frontě ani konceptu není odeslání.
Zkontrolujte adresáta, období a přílohu, v ISDS koncept výslovně schvalte
a následně ověřte doručenku i věcnou odpověď pojišťovny.

## 85.15 Nemocenské a další zákonné povinnosti

Záložka **Mimořádná podání ▾ → Přehled povinností** ukazuje pro vybraný měsíc přesnou matici toho,
co MyÚčto umí a co musí zůstat ruční. NEMPRI je po zavedení JMHZ nahrazené
jen částečně a HZUPN zůstává samostatným hlášením.

**Případ vzniká sám ze schválené nepřítomnosti.** Schválením dočasné pracovní
neschopnosti, karantény, ošetřování člena rodiny, dlouhodobé péče, mateřské
nebo otcovské v **Nepřítomnostech** se založí případ dávky a hlídač termínů
začne hlídat lhůtu NEMPRI ode dne události. Nad seznamem nepřítomností se
ukáže, co se stalo (případ založen s termínem NEMPRI, prodloužen, navázán na
existující), a odkaz **Otevřít případy dávek**. Neschopnost zapsaná po
měsících tvoří jeden případ: navazující nepřítomnost prodlouží jeho konec.
Neschopnost nebo karanténa do 14 kalendářních dnů případ nezaloží: celou ji
kryje náhrada mzdy (§ 192 zákoníku práce) a nemocenské náleží až od 15. dne
(§ 26 odst. 1 zákona č. 187/2006 Sb.), takže se ČSSZ nic nepředává. Případ
vznikne, jakmile neschopnost 14. den přesáhne, i když ji tam dotáhne teprve
navazující nepřítomnost; začíná pak prvním dnem neschopnosti. Odpracoval-li
zaměstnanec v den vzniku neschopnosti celou směnu (potvrzení při schválení),
je prvním dnem neschopnosti až následující den (§ 26 odst. 3 zákona
č. 187/2006 Sb.) a o den se posune i povinnost a lhůta NEMPRI; případ pak
nese i pracovní dobu a odpracované hodiny toho dne ze zveřejněné směny nebo
z rozvrhu. Nejsou-li zapsané, hláška vyzve k jejich doplnění u případu.
Navazuje-li neschopnost na dny, které padly u předchozího plátce nebo
programu (dny okna náhrady mzdy u nepřítomnosti), začíná případ skutečným
dnem vzniku a lhůta NEMPRI se počítá od něj. K ručně
založenému případu do 14 dnů hlídač termínů lhůtu neukáže a NEMPRI se
nepřipraví. Nevznikne-li případ (například firma nemá kód OSSZ), hláška řekne proč
a nepřítomnost se schválí i tak. Zrušením nepřítomnosti se zruší i případ,
ze kterého ještě nebylo připravené podání; případ s podáním zůstává
a vyřešíte ho opravným podáním. Zrušená navazující nepřítomnost vrátí konec
případu na den před sebou. Případ můžete založit i ručně na záložce
**Dávky nemocenského**.

Případ vzniká i z **převodu mezd** z předchozího programu, a to k události,
která trvá aspoň do prvního měsíce vedeného v MyÚčtu. Podání, jehož lhůta
začala běžet ještě v době předchozího programu, je v případu vedené jako
podané předchozím programem a MyÚčto ho nepřipravuje; zbývající podání
(typicky HZUPN k návratu do práce) hlídá MyÚčto. Nepodal-li předchozí
program takové podání ve skutečnosti, vraťte ho u případu tlačítkem
**Předchozí program NEMPRI nepodal** (HZUPN); MyÚčto ho pak připraví a jeho
lhůtu hlídá.

Schválíte-li v Nepřítomnostech zpětně neschopnost z doby, kdy mzdy vedl
předchozí program, MyÚčto za ni nic nepředpokládá: podání zůstane nedoručené
a hlídač termínů ho vede, dokud o něm nerozhodnete. Hláška po schválení na to
upozorní. Podal-li ho předchozí program, zapište to u případu tlačítkem
**NEMPRI podal předchozí program** (HZUPN), jinak ho připravte a podejte.

Případ evidujte na záložce **Dávky nemocenského**. Z případu si můžete
zobrazit náhled datové věty a tlačítkem **Připravit NEMPRI** nebo **Připravit
HZUPN** ji zmrazit; MyÚčto ji ověří proti připnutému XSD. Odesílá se rovnou
odsud tlačítkem **Odeslat NEMPRI/HZUPN datovou schránkou** — kanál VREP/APEP
pro tyhle dvě agendy otevřený není, takže na záložce **Odesláno**, která
patří jemu, tahle podání nenajdete. U připraveného podání je vždy napsané, co
se s ním stane: buď ho MyÚčto vloží do datové schránky jako koncept a odeslání
schválíte v ISDS, nebo ho odešle po potvrzení Mobilním klíčem, nebo si přílohu
stáhnete z fronty podání a odešlete ji ze své schránky. Doručenku nahrajete
ručně v každém případě — žádný z kanálů datovou schránku číst neumí.

Odeslání není splnění povinnosti: tu splní až doručení územní správě
sociálního zabezpečení. Skutečnou doručenku nebo protokol proto uložte jako
firemní dokument do DMS a výsledek zapište u případu.

**NEMPRI a HZUPN mají každé vlastní stav.** U případu je pod hlavičkou vidět,
v jakém stavu je které podání (nedoručeno, připraveno, přijato ČSSZ s dnem
doručení, odmítnuto s důvodem, podal předchozí program). Výsledek z protokolu
zapisujete ke konkrétnímu podání: vyplňte **Den doručení ČSSZ** a zvolte
**Zapsat přijetí NEMPRI** (HZUPN), nebo vyplňte důvod a **Zapsat odmítnutí**.
Přijaté NEMPRI případ neuzavře: dokud čeká HZUPN, případ jde upravovat,
navazující nepřítomnost ho prodlouží a hlídač termínů lhůtu HZUPN hlídá dál.
Vyřízené podání zamkne jen svoje údaje — údaje přijatého NEMPRI se už
nemění, údaje pro HZUPN ano. Opravit vyřízené podání jde jen opravným
podáním: zaškrtněte **Opravné podání**, upravte údaje, podání znovu
připravte a jeho přijetí zapište. Odmítnuté podání se vrací do hlídače
a připravíte ho znovu. U případu k události z doby předchozího programu jde
zapsat, že podání podal předchozí program (den doručení je nepovinný), a
takový zápis zase vrátit tlačítkem **Předchozí program NEMPRI nepodal**
(HZUPN).
Společný stav případu se z obou podání jen odvozuje: **Částečně vyřízeno**
znamená, že jedno podání je vyřízené a druhé čeká, **Vše vyřízeno**, že
čekat není na co.

### 85.15.1 Co vyplnit u případu

Tlačítkem **Upravit** otevřete editor případu. Má jedno společné **Uložit**
ve spodní liště a tyto sekce:

- **Případ** — kód OSSZ, **číslo rozhodnutí**, den skončení, **Opravné
  podání**, **Zahraniční případ**, **Slovenský případ** a další sdělení pro
  OSSZ. Číslo rozhodnutí
  (u eNeschopenky a eOČR číslo z rozhodnutí lékaře) je u nemocenského,
  ošetřovného a dlouhodobého ošetřovného povinné a HZUPN ho vyžaduje vždy;
  bez něj ČSSZ podání nespáruje. Nemusí ho mít jen zahraniční a slovenský
  případ. Zahraniční případ je rozhodnutí mimo ČR a SR; slovenskou
  neschopenku označte jako **Slovenský případ**, protože NEMPRI ji hlásí jako
  zahraniční, kdežto HZUPN jako českou (zahraničí v HZUPN je jen mimo ČR a SR).
  Číslo rozhodnutí se kontroluje už při přípravě podání podle druhu dávky
  (povinnost, zákaz i tvar), spolu s ostatními chybějícími údaji případu.
  Číslo rozhodnutí a Opravné podání jdou měnit i u vyřízeného podání.
  Otcovská, peněžitá pomoc v mateřství a vyrovnávací příspěvek číslo
  rozhodnutí nemají. Opravné podání nahradí dřívější podání se stejným číslem
  rozhodnutí.
- **Potvrzení zaměstnavatele** — mimo jiné **příjem ze zaměstnání malého
  rozsahu** v celých korunách. Pobírá-li zaměstnanec důchod, vyberte **druh
  důchodu** ze seznamu podle číselníku ČSSZ pro dávky CIS_DRUHDUCH_NEM
  (S starobní, I1 invalidní prvního nebo druhého stupně, I3 invalidní
  třetího stupně, A, B, C cizí důchod). Číselník přihlášky zaměstnance
  (1, 2, 8) je jiný a ČSSZ by ho v NEMPRI odmítla; starší takto zapsaný kód
  je v nabídce označený a je potřeba ho vybrat znovu. U studenta zaškrtněte, zda zaměstnání spadá výlučně do
  školních prázdnin. Pracovní volno bez náhrady příjmu má den od i do.
  U nemocenského, vyrovnávacího příspěvku a mateřské se vyplňuje nástup na
  peněžitou pomoc v mateřství a den narození dítěte. Převedení na jinou práci
  nese den převedení a důvod (těhotenství, mateřství, kojení): při převedení
  z těchto důvodů se rozhodné období může určit ke dni převedení, je-li to
  výhodnější (§ 19 odst. 6 zákona č. 187/2006 Sb.).
- **Žádost o dávku** (ošetřovné, dlouhodobé ošetřovné, otcovská, peněžitá
  pomoc v mateřství). Zaměstnavatel žádost přijímá a předává ČSSZ, údaje proto
  opisujete ze žádosti, kterou vám zaměstnanec předal. U ošetřovného
  zaškrtněte **akce** Vznik, Trvání nebo Ukončení — alespoň jednu. Potvrzení
  zaměstnavatele, rozhodné období, platební spojení, ošetřovaná osoba, důvod
  péče, prohlášení zaměstnance a den, od kterého se o dávku žádá, se
  posílají jen s akcí Vznik; u samotného trvání nebo ukončení je ČSSZ
  odmítá. Naopak den, do kterého se žádá, údaj o osobní péči, dny péče
  a podklady pro výplatu (plánované směny, dny práce) se posílají jen
  s akcí Trvání nebo Ukončení, a poslední den péče (zda zaměstnanec
  pracoval, jeho hodiny) jen s akcí Ukončení. U dlouhodobého ošetřovného
  podklady nesou i rozvrh směn a pracovní volno. Při střídání ošetřujících
  osob první osoba péči ukončí a druhá podá vlastní žádost se vznikem. Dítě nebo ošetřovanou osobu vyberte z
  vyživovaných osob na kartě zaměstnance — rodné číslo se doplní samo; osobu
  mimo evidenci zadejte jménem, příjmením a datem narození. Dále vyplňte
  důvod péče, vztah k ošetřované osobě (u otcovské důvod otcovské, u mateřské
  případně důvod převzetí dítěte do péče), dny, kdy zaměstnanec pečoval,
  a podklady pro výplatu (směny v posledním dni a v období dávky; u
  dlouhodobého ošetřovného i to, zda měl zaměstnanec pracovní volno a kdy,
  a při rozvržených směnách jejich rozvrh). Vztah
  i důvody se vybírají ze seznamu podle číselníků ČSSZ: u ošetřovného
  CIS_RODVZTAH (PL, MA, RP, SDO, SO, TCH, JIN), u dlouhodobého ošetřovného
  CIS_VZTAH (1 až 29), důvod otcovské OTC, ZEM nebo PEC a důvod převzetí do
  péče CIS_DUVPREVZETI (DOH, ONE, ROZ, UMR). S důvodem převzetí se číslo
  rozhodnutí nevyplňuje. Číslo rozhodnutí musí mít tvar podle druhu dávky:
  u nemocenského písmeno a 6 až 7 číslic nebo 10 číslic, u mateřské
  s příponou M, u ošetřovného N nebo Z, u otcovské T a u dlouhodobého
  ošetřovného L (vždy sedmimístné číslo, případně s předponou ICPE). Mateřská
  bez důvodu převzetí číslo nese, vyrovnávací příspěvek ne. U uzavřené školy
  uveďte i její IČ.
- **Potvrzení zaměstnavatele** — hodiny a pracovní doba se vyplňují jen
  tehdy, když zaměstnanec v den události pracoval (a pak oba údaje, hodiny
  nejvýš do výše pracovní doby). Prázdniny patří jen ke studentovi, druh
  důchodu jen k pobíranému důchodu, datum narození dítěte jen k nástupu na
  mateřskou a pracovní volno bez náhrady příjmu musí mít začátek i konec.
  Podání s rozporem se nepřipraví a hlásí, který údaj chybí nebo přebývá.
- **Otcovská** — hodiny posledního dne a datum návratu do práce patří k sobě
  (jedno bez druhého nejde) a odpracované hodiny nesmí převýšit pracovní dobu. Starší ručně zapsaný kód mimo číselník je v nabídce
  označený a podání s ním neprojde, dokud ho nevyberete znovu.
- **Rozhodnutí zaměstnavatele o dlouhodobé péči** (jen dlouhodobé
  ošetřovné) — podle § 191a zákoníku práce musí zaměstnavatel nepřítomnosti
  vyhovět, ledaže mu brání vážné provozní důvody, a odmítnutí písemně
  zdůvodní. Vyberte **Souhlasil** nebo **Odmítl**, den, kdy jste rozhodnutí
  zaměstnanci sdělili, a u odmítnutí důvod. Odmítnutý případ se ČSSZ
  nepředává: zaměstnanec v práci nechybí a dávka mu z tohoto zaměstnání
  nenáleží.
  Prohlášení, které zaměstnanec v žádosti nevyplnil, nechte nezaškrtnuté:
  podle zásad NEMPRI se uvede „NE“ a žádost se kvůli tomu nezdrží. Hranice
  žádosti jsou předvyplněné dny případu.
- **Rozhodné období** — viz níže.
- **Kontaktní pracovník** — jméno, telefon a e-mail osoby, na kterou se OSSZ
  obrátí.
- **Ukončení neschopnosti** (jen nemocenské, pro HZUPN) — zda se zaměstnanec
  vrátil do práce. Když se nevrátil (nástup na peněžitou pomoc v mateřství,
  skončení zaměstnání), zvolte **Ne** a uveďte důvod; do hlášení jde jen
  důvod, datum návratu a hodiny posledního dne se u odpovědi „Ne“ neposílají.
  Odpovíte-li **Ano**, uveďte datum návratu a hodiny odpracované v poslední
  den neschopnosti i pracovní dobu (0 a 0, když zaměstnanec nepracoval;
  je-li pracovní doba větší než 0, nesmí být odpracováno 0). HZUPN hlásí nástup do zaměstnání, a proto se jeho lhůta („neprodleně",
  § 97 odst. 3 zákona č. 187/2006 Sb.) počítá ode dne nástupu: od zapsaného
  dne návratu, jinak od dne po skončení neschopnosti.

**Ochranná lhůta.** Vznikne-li neschopnost nebo karanténa až po skončení
zaměstnání, nemocenské náleží jen v ochranné lhůtě 7 kalendářních dnů
(nejvýš tolik dnů, kolik pojištění trvalo), peněžitá pomoc v mateřství
nejvýš do 180 dnů u ženy, jejíž pojištění skončilo v těhotenství (§ 15
zákona č. 187/2006 Sb.). Z dohody o provedení práce, zaměstnání malého
rozsahu a zaměstnání studenta jen o prázdninách ochranná lhůta neplyne,
neplyne ani poživateli starobního důchodu a invalidního důchodu třetího
stupně (u případu proto musí být vyplněný druh důchodu, pobírá-li ho)
a ostatní dávky ji nemají vůbec. Případ mimo ochrannou lhůtu nejde založit
ani připravit; u případu v ochranné lhůtě seznam ukáže, do kdy lhůta běží,
a NEMPRI se podá se dnem skončení zaměstnání. Rozhodné období se pak určí,
jako by událost vznikla den po skončení zaměstnání (§ 19 odst. 11 zákona
č. 187/2006 Sb.).

**Způsob výplaty mzdy** se do NEMPRI doplní sám z výplatního profilu
zaměstnance: účet, na který chodí mzda, zahraniční IBAN, nebo adresa bydliště,
když se mzda vyplácí v hotovosti. Platební spojení se posílá jen s akcí Vznik
(u ošetřovného bez vzniku je zakázané) a u ostatních dávek vždy; u nemocenského
jen s elektronickým číslem rozhodnutí (10 číslic). Chybí-li účet nebo
nejde-li adresu rozložit na ulici, číslo popisné a PSČ, příprava se zastaví
a pod chybou je odkaz na kartu osoby. Stejně se zastaví výplata přes partnera:
MyÚčto nezná účet ani adresu, kam má ČSSZ dávku poslat. Opravte způsob výplaty
ve výplatním profilu osoby, nebo oznámení podejte mimo aplikaci.
Ve větě jsou vždy všechny čtyři volby způsobu výplaty (účet v ČR, účet
v zahraničí, adresa, hotovost), vybraná jako „ano“ a ostatní jako „ne“.

**Kontakt pojištěnce** (telefon a e-mail) se do NEMPRI doplní z karty osoby.
Bere se primární aktivní kontakt, a když primární není, jediný aktivní;
při více kandidátech se kontakt nevysílá.

**Částky v rozhodném období** se do NEMPRI posílají v celých korunách a
vyloučené dny nesmí převýšit počet dnů měsíce. Rozhodné období je u všech
dávek (u ošetřovného s akcí Vznik) povinné a buď nese úplný seznam měsíců se
součty, nebo jen pravděpodobnou výši příjmu, nikdy obojí.

### 85.15.2 Rozhodné období a pravděpodobný příjem

NEMPRI nese rozhodné období vždy celé: každý měsíc se započitatelným příjmem
a vyloučenými dny podle § 18 odst. 7 zákona č. 187/2006 Sb. a k tomu oba
součty. Platí to i pro měsíce, za které MyÚčto podalo jednotné měsíční
hlášení. Měsíc se bere z tohoto zdroje, v tomto pořadí:

1. ruční zadání v sekci **Rozhodné období** případu,
2. schválený mzdový běh v MyÚčtu: vyměřovací základ vztahu (i část nad
   ročním maximem, kterou hlášení nenese) a vyloučené dny odvozené
   z nepřítomností běhu,
3. převzaté mzdy předchozího programu.

Chybí-li k měsíci podklad, příprava se zastaví s výčtem měsíců. U měsíců, které
počítá MyÚčto, mzdu spočítejte a schvalte; převzatou mzdu doplňte
v **Kontrole převodu mezd**. Měsíc můžete vždy zadat i ručně. Částky jdou do
věty v celých korunách, haléře se zaokrouhlí nahoru.

U převzatých měsíců se vyloučené dny berou z údaje hlášení o vyloučených dnech
podle § 18 odst. 7 (neplacené volno, nemoc s náhradou mzdy, dny s dávkou),
ne z vyloučených dob pro důchodové pojištění. Měsíc bez příjmu, ke kterému
předchozí program tento údaj nevydal, se zastaví a vyžádá ruční zadání. Měsíc
dohody bez účasti na pojištění se započte s příjmem z nepojištěné činnosti.
Převzatý měsíc, ve kterém byl vyměřovací základ krácený ročním maximem, se
také zastaví: hlášení nenese část nad maximem, kterou rozhodné období
potřebuje, a tak ji zadejte ručně.

Nemá-li rozhodné období vyměřovací základ nebo aspoň 30 nevyloučených dnů
(například celé na rodičovské), použije se první předchozí kalendářní rok
se započitatelným příjmem a aspoň 30 dny (§ 18 odst. 6). Věta pak nese tento
rok. Teprve když takový rok není, nebo když zaměstnání trvalo méně než
12 měsíců, vychází ČSSZ z **pravděpodobné výše příjmu**. Stejně je tomu, když
zaměstnanec onemocní v měsíci nástupu. Zadejte ji v sekci **Rozhodné
období**; tlačítko **Navrhnout z mzdy** předvyplní sjednanou měsíční hrubou
mzdu. Bez ní se NEMPRI v takovém případě nepřipraví. S pravděpodobnou výší
věta jednotlivé měsíce ani součty nenese.

U zaměstnankyně převedené na jinou práci kvůli těhotenství, mateřství nebo
kojení se rozhodné období spočítá i ke dni převedení a do věty jde to
výhodnější (§ 19 odst. 6).

Do NEMPRI se zapisuje skutečný den nástupu do zaměstnání, ne sjednaný den
ze smlouvy. HZUPN se nabízí jen u nemocenského.

Po ručním splnění lze u NEMPRI nebo HZUPN zapsat zaměstnance, referenci
případu, referenci doručenky, datum a ID firemního DMS dokumentu. Server
ověří vlastnictví dokumentu firmou a sám zmrazí jeho SHA-256. Záznam je
neměnný; oprava se přidává jako nový důkaz. Produkční a testovací důkazy se
nemíchají. Samotné vyplnění formuláře v MyÚčtu nikdy nenahrazuje podání
v oficiálním kanálu.

**Zákonné úrazové pojištění** je v matici výslovně uvedené jako samostatná
ruční povinnost. MyÚčto nyní nepočítá základ ani sazbu, nevytváří předpis,
výstup nebo platební závazek a nenabízí transport. Částku proto určete podle
odborně ověřených externích podkladů a skutečnou úhradu proveďte mimo MyÚčto.
Potvrzení úhrady nebo konkrétní oficiální doklad uložte jako firemní dokument
do DMS. Teprve potom lze zapsat externě ověřenou částku v CZK, referenci
povinnosti, referenci platby a DMS dokument jako neměnný důkaz. Zápis je
výslovné potvrzení uživatele; MyÚčto správnost výpočtu ani provedení platby
automaticky neověřuje. Absence automatizace neznamená, že povinnost zanikla
nebo ji nahradilo JMHZ.

## 85.16 Vyúčtování zálohové a srážkové daně

Za uplynulý rok podává plátce správci daně dvě samostatná vyúčtování, ne jedno
se dvěma přílohami:

- **Vyúčtování daně z příjmů ze závislé činnosti** (§ 38j odst. 4 ZDP, tiskopis
  25 5459). Lhůta jsou dva měsíce po skončení roku, elektronicky do 20. března.
- **Vyúčtování daně vybírané srážkou podle zvláštní sazby** (§ 38d ZDP, tiskopis
  25 5466). Lhůta jsou tři měsíce po skončení roku.

Ani jednu lhůtu nelze prodloužit. Aplikace je vypisuje jako text; do daňového
kalendáře ani do přehledu mzdových termínů se nepromítají.

> 🛈 Pozn: Za rok 2026 se obojí podává běžným způsobem. Teprve od období 2027
> nahradí vyúčtování zálohové daně hlášení k záloze v měsíčním hlášení, takže
> tuhle cestu je potřeba ještě jednu sezónu.

**Kde to je.** Na přehledu mezd, panel **Vyúčtování daně**, pod ročním
zúčtováním a roční uzávěrkou. Vybíráš rok (výchozí je loňský) a typ vyúčtování:
řádné, řádné opravné, dodatečné nebo dodatečné opravné. U obou dodatečných
variant se navíc zadává datum zjištění důvodů (§ 141 odst. 5 daňového řádu).
Víc se ručně vyplnit nedá: **žádnou částku ani řádek nelze přepsat**, podklad
je průmět schválených mzdových běhů a v roce přechodu i počátečních stavů za
převzaté měsíce.

Panel ukazuje tři dlaždice (zálohy, které měly být sraženy, skutečně odvedeno
finančnímu úřadu, srážková daň celkem), tabulku po měsících, přílohu č. 1 se
seznamem obcí místa výkonu práce a blok varování. Nejsou v něm žádná jména ani
osobní identifikátory. Měsíc bez schváleného mzdového běhu **není měsíc s
nulami** - řádek se prostě nevytvoří a dostaneš na to varování. Pokud v takovém
měsíci mzdy byly, schval je nejdřív.

**Rok přechodu.** Měsíce před začátkem vedení mezd v MyÚčtu zpracoval
předchozí program. Jejich řádky se naplní z počátečních stavů ročních součtů
(základ a záloha daně, slevy, bonus, srážková daň) a v tabulce nesou značku
*převzato*; odvedená záloha je u nich odvozená jako záloha po odečtení bonusu.
Když některému zaměstnanci v převzatém měsíci trval pracovní vztah a počáteční
stav za ten měsíc chybí, vyúčtování se nesestaví: panel vypíše, komu a za
které měsíce chybí, s odkazem na kartu zaměstnance. Jak převzaté měsíce
doplnit, popisuje kapitola
[Přechod mezd v průběhu roku](113_Prechod_mezd_v_prubehu_roku.md).

Podklad je vždy zmrazený výsledek schválených revizí nebo uložené počáteční
stavy, nikdy nový výpočet.
Do přílohy č. 1 se počítají zaměstnanci podle obce místa výkonu práce k 1. 12.;
komu obec u vztahu chybí, ten se do přílohy nedostane a aplikace to spočítá do
varování. Okres se dopočítá z číselníku obcí, a co číselník nepokrývá, zůstane
prázdné.

**Co se záměrně negeneruje, a proč.** Věz to dopředu, ať to nehledáš:

- **Příloha č. 2 pro nerezidenty.** Vyžaduje číslo dokladu totožnosti, jeho typ
  a typ zahraničního daňového identifikátoru. Tyto údaje aplikace o osobě
  nevede, takže by příloha byla poloprázdná a nepravdivá. Místo ní vzniká
  varování s počtem evidovaných nerezidentů a výzvou doplnit přílohu ručně
  v EPO.
- **Přílohy č. 3 a 4 podle § 38i.** Modul opravy neeviduje jako samostatný
  záznam „měsíc chybný, měsíc opravy, částka" - opravuje se přepočtem revize.
  Prázdná příloha je pravdivá, vymyšlená by nebyla. Totéž platí pro obdobnou
  přílohu u srážkové daně (§ 38d odst. 8).
- **Částky předepsané k přímé úhradě.** To je rozhodnutí správce daně, které
  aplikace nezná; příslušný sloupec proto zůstává nulový.
- **Řádky „finanční úřad na žádost vrátil, převedl nebo použil"** podle § 35d
  odst. 5 a 9 zůstávají nulové ze stejného důvodu.
- **Rozdíl u dodatečného vyúčtování** se nepočítá: musel by být znám obsah
  původního podání jako celku, ne jen dnešní stav mezd. U dodatečné varianty se
  navíc vynechává část II. a dva sloupce části I.

**Výstup.** Dvě samostatná tlačítka stáhnou dvě XML pro EPO. Každé stažení se
archivuje s otiskem a najdeš ho v přehledu podání ve složce **Vyúčtování daně
ze závislé činnosti**; stažení nikdy neposune daňový zámek. Odtud pokračuješ
asistovaným nebo přímým podáním na EPO stejně jako u ostatních daňových
písemností, viz
[EPO podání, archív a daňová rekonciliace](49_Archiv_podani_a_rekonciliace.md).

Podání se nesestaví vůbec, když za rok není ani jeden schválený mzdový běh,
nebo když úhrn skutečně odvedené daně vyjde záporně - to znamená špatně
spárované platby finančnímu úřadu, oprav je dřív. Varování se zobrazí i tehdy,
když je příloha č. 1 prázdná, když firma nemá vyplněný finanční úřad (dosadí se
FÚ pro Prahu 1 a je potřeba ho ověřit), a když zaokrouhlení na celé koruny
zbylo přes.

## 85.17 Žádost o poukázání chybějící částky na daňovém bonusu

Vyplatí-li zaměstnavatel na daňových bonusech víc, než kolik ten měsíc srazil
na zálohách, rozdíl doplácí ze svého. Aby se mu vrátil, musí o něj finanční
úřad požádat; samo se to nestane a peníze do té doby leží u státu. Jde
o dobrovolné podání: podává je plátce tehdy, když chce své peníze zpátky.

Formuláře jsou dva a mají vlastní tiskopis:

| Písemnost | Právní základ | Čeho se týká |
|---|---|---|
| Žádost podle § 35d odst. 5 | měsíční daňové bonusy | bonusy vyplacené v daném měsíci |
| Žádost podle § 35d odst. 9 | doplatek z ročního zúčtování | doplatek na bonusu vyplacený z ročního zúčtování |

**Obě žádosti se vážou na měsíc, ne na rok.** I doplatek z ročního zúčtování,
protože rozhodné je datum jeho skutečné výplaty a záloha, proti které se
započítává, je měsíční. Doplatek vyplacený v březnu a doplatek z opravné revize
v červnu jsou proto dvě samostatné žádosti, i když jde o tentýž zdaňovací rok.

Podklad je zmrazený výsledek schválených mzdových revizí za daný měsíc, sečtený
přes všechny mzdové účtárny firmy; žádost jde na jeden finanční úřad za celou
firmu. Druhý výpočet nevzniká. Rozdělení mezi obě žádosti potřebovalo pravidlo,
které zákon nedává, a je zvolené takto: **sražené zálohy kryjí nejdřív měsíční
bonusy, zbytek doplatky**. Obě žádosti musí dát dohromady přesně tu částku,
kterou aplikace zaúčtovala jako pohledávku za finančním úřadem; nesouhlas
by podání zastavil. V jedenácti měsících v roce, kdy se roční zúčtování
nevyplácí, na pořadí stejně nezáleží.

Vyžaduje se zapnuté vedení mezd, oprávnění ke mzdovým sestavám a k exportu.
Žádost se nesestaví, když za měsíc není schválený mzdový běh s vypočtenou daní,
ani když bonusy zálohy nepřevýšily, tedy když není o co žádat. Chybí-li u běhu
datum výplaty, aplikace **nedosadí konec měsíce** a vrátí varování: rozhodné
datum musí být skutečný den výplaty bonusu. Je-li v měsíci víc běhů, použije se
poslední datum výplaty. Vnitřně se počítá v haléřích, tiskopis chce celé
koruny, a zbytek po zaokrouhlení se hlásí varováním, ne tiše zahazuje.

Aplikace **záměrně neurčuje, kam peníze poslat, ani zda je započíst proti
vlastním nebo cizím nedoplatkům**. To jsou rozhodnutí plátce, ne výpočet, a
vymyslet je by znamenalo tvrdit volbu, kterou nikdo neudělal. Vynechání těchto
částí znamená běžnou výplatu na účet plátce. Aplikace také nehlídá lhůtu pro
podání a žádost sama od sebe nenavrhuje; k prošlému měsíci se musíš vrátit sám.

Výstupem je XML pro EPO. Archivuje se se stejným otiskem jako ostatní daňová
podání a v přehledu podání je najdeš ve složce **Daňové bonusy**. Se
[Vyúčtováním daně](#8516-vyuctovani-zalohove-a-srazkove-dane) nemá žádost
společný formulář ani přílohu; jsou to samostatná podání, byť se stejnými
částkami vyplacených bonusů v pozadí.

> 🛈 Pozn: Samostatná obrazovka pro žádost zatím není. Připravená písemnost se
> zakládá přes rozhraní a hotové XML uvidíš v přehledu daňových podání.
