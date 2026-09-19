# 80. Mzdové běhy

## 80.1 Účel

Mzdový běh shromáždí data jednoho období, provede výpočet a uchová kontrolovatelný výsledek. Uzavření odděluje návrh od podkladu pro platby, účetnictví, dokumenty a podání.

## 80.2 Předpoklady a oprávnění

Musí být dokončeno nastavení zaměstnavatele, zaměstnanců, vztahů, kalendáře, absencí a vstupů. Uživatel potřebuje mzdové oprávnění; před uzavřením musí rozumět validačním hlášením.

## 80.3 Krokový postup

1. Otevřete **Mzdy → Mzdové běhy**, vyplňte **Mzdové období** a **Datum
   výplaty** a klikněte **Nový mzdový běh**. Předtím schvalte měsíc docházky —
   zákonné příplatky vznikají jeho schválením a po uzamčení vstupů už se do běhu
   nedostanou.
2. Klikněte **Spočítat mzdy**. Jedno tlačítko uzamkne vstupy i spustí výpočet;
   před zmrazením ještě uvidíte **Kontrolu před zahájením** a potvrdíte ji
   volbou **Přesto zahájit**. Zůstal-li některý vstup v konceptu, běh to ohlásí
   jako blokaci a přímo u ní nabídne **Schválit vše**; nemusíte kvůli tomu
   odcházet na jinou obrazovku. Samostatné **Uzamknout vstupy** se nabízí jen
   tam, kde sloučený krok nelze použít.
3. Projděte blokace, varování i výsledky jednotlivých zaměstnanců.
4. Porovnejte souhrny s docházkou, vstupy, srážkami a očekávanými odvody.
5. Po opravě zdroje klikněte **Přepočítat**; neupravujte vypočtený výsledek bez
   podkladu. Přepočet počítá ze snímku zmrazeného při zamknutí. Vstup,
   nepřítomnost nebo zákonnou evidenci schválenou až potom převezme
   **Obnovit podklady** (viz [80.8.3](#8083-obnoveni-podkladu-rozpracovaneho-behu)).
6. Klikněte **Schválit**. Samostatný krok **Zkontrolovat** aplikace jako
   tlačítko nenabízí — schválení ho provede za vás. Následné činnosti provádějte
   z této schválené revize: **Zaúčtovat**, potom **Připravit platby** a nakonec
   **Uzavřít**. Tlačítko pro potvrzení úhrady neexistuje; do stavu **Uhrazeno**
   běh překlopí server sám podle spárovaných plateb.

## 80.4 Stavy

Návrh je měnitelný, vypočtený návrh čeká na kontrolu a uzavřený běh je stabilním podkladem. Chyba blokuje pokračování; varování vyžaduje rozhodnutí uživatele. Storno či oprava musí zachovat auditní návaznost a nesmí přepsat historii.

## 80.5 Kontroly a bezpečnost

Kontrolujte hrubou a čistou mzdu, daň, pojistné, náhrady, srážky a náklad zaměstnavatele. Ověřte počet osob a souběhy. Celý postup může dokončit jedna účetní s příslušným oprávněním; případná interní kontrola další osobou je dobrovolným pravidlem firmy. Výpočet v aplikaci nenahrazuje odborné posouzení nepodporovaného případu.

## 80.6 Časté chyby

Kontrola před zahájením i blokace výpočtu uvádějí jednotlivé důvody a odkazy
k nápravě. U skupiny osob může každý odkaz vést jinam; použij odkaz u
konkrétního člověka. Karta vztahu se otevře přímo na příslušné sekci nebo
poli, měsíční agenda ve správném období. Pokud podklady již patří schválené
revizi, jejich oprava vyžaduje navazující opravnou revizi. Podrobný přehled
řešení je v [kontrolách mzdové agendy](999_Reseni_problemu.md#99911-kontroly-mzdove-agendy).

- Uzavření před dodáním absence nebo srážky.
- Oprava vstupu bez přepočtu.
- Záměna výpočtu za automatické zaúčtování či odeslání plateb.
- Přehlédnutí varování u souběhu nebo chybějícího identifikátoru.

## 80.7 Návaznosti

Po schválení pokračujte v tomto pořadí: **Zaúčtovat** a ověřit
[shodu účtování](81_Shoda_uctovani_mezd.md) → **Připravit platby** a vypořádat
[mzdové příkazy a úhrady](82_Platby_a_uhrady.md) → vydat
[dokumenty](83_Dokumenty_a_vystupy.md) → odeslat [podání](85_Podani_a_hlaseni.md).
Teprve potom měsíc **Uzavřít**. Celý klikací postup je v
[§ 63.3.1](75_Uplne_mzdy.md#7531-krok-za-krokem-co-presne-klikat).



## 80.8 Podrobný pracovní postup a kontroly

V **Mzdy → Mzdové běhy** založíš zpracování konkrétního měsíce. K období se
zadává také skutečné datum výplaty; podle něj se vybírají účinná pravidla
srážek. Datum výplaty nesmí být později než poslední den měsíce následujícího
po měsíci, za který mzda přísluší (§ 141 odst. 1 zákoníku práce); pozdější
datum aplikace odmítne jako chybu, protože je to kotva, ze které se odvozují
všechny navazující termíny odvodů.

Běžný běh má dva kroky: **Spočítat mzdy → Schválit**. Uzamčení vstupů a výpočet
jsou sloučené do jednoho tlačítka, protože je to tatáž práce; samostatné
**Uzamknout vstupy** zůstává v API pro opravné revize. Krok
**Zkontrolovat** zůstává jako samostatný příkaz pro firmu, která chce mít
kontrolu vidět jako vlastní událost, ale povinný není a jako tlačítko se
nenabízí — **schválení ji provede
implicitně** a do historie běhu se zapíše jako kontrola provedená spolu se
schválením. Všechny kroky může provést jedna účetní, pokud má mzdové oprávnění;
pravidlo čtyř očí modul nezavádí. Jednotlivé změny a potvrzení zůstávají
v auditní stopě, takže firma může dobrovolně zapojit další kontrolu bez toho,
aby byl běžný tok blokován.

Vstupy do běhu se nemusí schvalovat po jednom. **Rychlý měsíční vstup** uloží
řádky rovnou jako schválené, má-li přihlášený uživatel právo mzdové vstupy
schvalovat; bez toho práva vznikají koncepty a schválí je někdo jiný.
Jednotlivé mzdové vstupy zadané v **Mzdy → Mzdové složky a vstupy** vznikají
vždy jako koncept, protože je nutné je umět ještě upravit i zrušit.

Koncepty, které v běhu zbyly, schválíte hromadně — přímo u blokace v kartě
běhu, nebo v mzdových vstupech tlačítkem **Schválit N odpovídajících filtru**.
Odkaz u blokace otevře seznam konceptů daného měsíce. Schvalují se všechny
koncepty bez ohledu na jejich počet; už schválený se jen přeskočí, takže je
bezpečné tlačítko použít znovu. Dvoustupňový režim tedy zůstává možný, jen není
povinný.

Skutečně prázdný technický běh lze tlačítkem **Smazat prázdný běh** odstranit
i po jeho zrušení. Tlačítko se zobrazí pouze tehdy, když běh nemá žádnou revizi,
uzamčené vstupy, výpočet, dokument, podání, platbu ani účetní stopu. Jakmile
běh obsahoval věcnou evidenci, zůstává kvůli auditu dohledatelný a lze jej jen
zrušit, nikoli smazat.

Uzamknutí vytvoří neměnný snapshot zaměstnanců, vztahů, složek, data výplaty
a měsíčních podkladů srážek. Pozdější změna živé karty už rozpracovanou revizi
nepřepíše. Oprava schváleného měsíce vytváří novou revizi; původní zůstává
dohledatelná.

Schválením opravné revize se předchozí schválená revize označí jako
**nahrazená**. Za jeden běh je tedy vždy právě jedna platná schválená revize
a nemůže se stát, že by dvě revize současně tvrdily výsledek téhož měsíce.
Dokumenty vydané z původní revize zůstávají platné a čitelné — každý se váže
na svou vlastní revizi. Archivní export nese celý řetěz, tedy platnou revizi
i všechny nahrazené.

Čistá mzda jedné osoby může vyjít **záporně** — typicky když se v měsíci bez
peněžního příjmu doplácí zdravotní pojištění. Výpočet to nezaokrouhlí na nulu
ani nezastaví: jde o legitimní stav, ze kterého vzniká **pohledávka za
zaměstnancem**. Ta se vykazuje samostatně, nesčítá se do čistých mezd a
nevytvoří platební závazek, takže se nikdy nemůže dostat do odchozí bankovní
dávky s obráceným znaménkem. Zápočet takové pohledávky v dalším měsíci je
ruční úkon účetní — § 147 zákoníku práce omezuje, co lze srazit bez souhlasu
zaměstnance.

Po výpočtu je u běhu dostupný **Rozpad daně ze závislé činnosti**. Pro každého
zaměstnance ukazuje zdanitelný a zákonně zaokrouhlený základ, základ a sazbu
jednotlivých pásem, daň před slevami, uplatněné a skutečně použité slevy,
zvýhodnění na děti, daňový bonus a případnou srážkovou daň. U souběhu vztahů
je vidět, který příjem spadl do zálohového nebo srážkového režimu.

Stav **Vyžaduje ruční kontrolu** není vypočtená nula. Rozpad vypíše konkrétní
důvody, například neověřené prohlášení k dani, rezidenci nebo nárok na dítě.
Nejdřív oprav podklad na kartě osoby a potom vytvoř novou revizi výpočtu;
historický snapshot se zpětně nemění.

### 80.8.1 Hromadné doplnění výchozí zákonné evidence

Po převzetí zaměstnanců z importu nemají osoby daňovou rezidenci, příslušnost
k pojištění ani údaj o slevě pracujícího důchodce a běh proto hlásí nedokončený
zákonný výpočet u většiny lidí. U této blokace nabízí karta běhu tlačítko
**Doplnit výchozí údaje (N osob)**. Stejná akce je na seznamu **Mzdy →
Zaměstnanci** jako **Doplnit výchozí zákonné údaje**; tam zvolíte měsíc, od
kterého údaje platí. Náhled bere všechny osoby, které by vzal mzdový běh za
daný měsíc, a nic nezapisuje. Ukáže:

- kolika osobám se doplní česká daňová rezidence, česká příslušnost
  k pojištění bez formuláře A1 a neuplatňování slevy pracujícího důchodce
  (sleva jen u osob mladších 60 let se známým datem narození),
- vyřazené osoby s důvodem: cizí prvek (adresa v cizině, cizí občanství,
  povolení k pobytu nebo práci, cizí legislativa, formulář A1, zahraniční
  pojištění nebo daňový režim, zahraniční údaj v evidenci), chybějící pracovní
  vztah v měsíci nebo vztah ukončený ve schváleném období,
- osoby bez zdravotní pojišťovny s odkazem na **Importy → JMHZ registrace**;
  pojišťovnu hromadná akce nikdy nedoplní,
- osoby bez prohlášení poplatníka.

Existující záznam se nikdy nepřepíše ani neukončí. Údaje platí od prvního dne
měsíce, u pozdějšího nástupu od měsíce nástupu; do období se schválenou mzdou
se nezapisují a platí až od dalšího měsíce. Chybějící prohlášení poplatníka se
zapíše jako **nepodepsané** jen po zaškrtnutí volby **Zapsat chybějící
prohlášení jako nepodepsané**, která je výchozím stavem vypnutá. Náhled u ní
jmenovitě vypíše osoby, kterým by nepodepsané prohlášení mohlo přepnout
zdanění na srážkovou daň. Zápis probíhá osobu po osobě stejnou cestou jako
karta osoby; výsledek ukáže doplněné, přeskočené a neúspěšné osoby s důvodem.

Spočítaný běh počítá ze zmrazeného snímku vstupů, samotný přepočet proto
doplněné údaje nevezme. Po uložení nabídne dialog další krok podle stavu
běhu: u konceptu **Spočítat mzdy**, u rozpracovaného běhu **Obnovit
podklady** (potom spočítejte mzdy), u zrušeného nebo opravného běhu otevření
nové revize a u schváleného běhu **Vyžádat opravu**.

### 80.8.2 Hromadné doplnění místa výkonu práce pro JMHZ

Měsíční hlášení ČSSZ vyžaduje u každého vztahu místo výkonu práce jako kód
obce a státu z číselníku ČSSZ. Chybí-li u vztahů (typicky po importu), hlásí
test JMHZ nález **Chybí ověřené číselníkové údaje pracoviště**. Místo výkonu
práce je sjednané v pracovní smlouvě, aplikace ho proto neodhaduje — zvolíte ho
vy. Akce **Doplnit pracoviště** je na seznamu **Mzdy → Zaměstnanci** a přímo
u tohoto nálezu v testu JMHZ. Náhled za zvolený měsíc ukáže, kolik vztahů
pracoviště nemá, kolik ho má ověřené a kolik vyplněné, ale neověřitelné (to se
nepřepisuje, opravte ho na kartě vztahu), a nabídne pracoviště, která už ve
firmě ověřeně jsou, nejčastější první. Jinou obec vyhledáte v číselníku.

Obec a stát se ověří proti číselníku ČSSZ platnému po celý vykazovaný měsíc
a zapíší se jako oprava platné verze podmínek, stejně jako na kartě vztahu —
včetně záznamu v historii změn. Vyplněné pracoviště se nikdy nepřepíše.
Do běhu se doplněné údaje dostanou až novou revizí: schválený běh vraťte
k opravě, otevřete novou revizi, spočítejte, schvalte a hlášení připravte znovu.

### 80.8.3 Obnovení podkladů rozpracovaného běhu

Zamknutí vstupů i otevření opravy zmrazí snímek podkladů a **Přepočítat**
počítá vždy z něj. Co se schválí až potom, typicky doplatek, oprava nebo
zapomenutý příplatek, se do rozpracované revize samo nedostane.

Karta rozpracovaného běhu (vstupy uzamčeny, spočítáno nebo otevřená oprava)
proto hlídá, jestli od zmrazení snímku přibyly nebo se změnily schválené
mzdové vstupy, nepřítomnosti, pracovní vztahy v období nebo zákonná evidence
osob. Když ano, ukáže varování s datem snímku a počtem změn podle druhu
a jako hlavní akci nabídne **Obnovit podklady**.

**Obnovit podklady** založí novou revizi téhož druhu (opravná zůstává
opravnou) ze stejných podkladů, jaké by vzalo zamknutí vstupů, nově schválené
vstupy zamkne a běh vrátí k přepočtu. Rozpracovaná revize zůstane v historii
jako zahozená, v historii běhu přibude událost **Podklady obnoveny**.
Schválenou revizi obnova nikdy nemění, u schváleného běhu vede cesta přes
**Vyžádat opravu**. Výjimky schválené u varování předchozí revize se
nepřenášejí, nová revize je vyžaduje znovu.

Schválení revize, ke které existují novější podklady, aplikace odmítne
s výzvou k obnovení. Nejde o varování s výjimkou: schválený vstup, který by
revize vynechala, by zůstal navždy nezaúčtovaný a nevyplacený. Nechcete-li
vstup do měsíce zahrnout, zrušte ho nebo ho přesuňte do jiného období.

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
straně případný rozdíl vznikl. Účetně neutrální nepeněžní plnění se z hrubých
mezd vyčleňuje, takže z něj rozdíl nevzniká. Oprava schváleného měsíce se do porovnání
promítne správně — deník se sčítá napříč všemi revizemi běhu, protože
rozdílová revize účtuje jen rozdíl proti poslední zaúčtované revizi. Měsíc,
který ještě nebyl zaúčtován (vypnuté automatické zaúčtování, čekající krok,
nebo firma vedoucí daňovou evidenci), stránka označí jako nezaúčtovaný —
nejde o rozdíl. Stránka je čistě informační a nic nezapisuje ani do deníku,
ani do mzdové revize.

## 80.9 Převzaté měsíce roku přechodu

Firma, která přešla z jiného mzdového programu uprostřed roku, má v roce
přechodu dvě poloviny: měsíce vedené původním programem a měsíce, které už
počítá MyÚčto. Hranicí je první mzdový měsíc nastavený u mzdového modulu.
Za měsíc pod touto hranicí mzdový běh založit nejde a ani nemá — MyÚčto ten
měsíc nepočítalo.

Na stránce **Mzdy → Mzdové běhy** proto svítí panel **Převzaté měsíce roku
přechodu**. Vypíše historické měsíce, ke kterým jsou v aplikaci převzaté
mzdy (z převodu z PAMICA, POHODY, Money S3 nebo z importu CSV/XLSX), a u
každého nabídne **Převzít měsíc**. Panel se u firmy bez převzatých
historických měsíců nezobrazí.

**Převzatý běh** je zrcadlo cizího výpočtu, ne pracovní dokument:

- Nic se nepočítá. Výsledek vzniká pouze součtem převzatých řádků za měsíc;
  chybějící veličina zůstane nulová a nedopočítává se.
- Neprochází workflow. Nemá revizi, nejde ho uzamknout, přepočítat,
  zkontrolovat ani schválit; v seznamu běhů je označený štítkem **Převzato**.
- Nezaúčtovává se. Doklady za historická období jsou v účetnictví už
  z převodu, takže druhý zápis vzniknout nesmí a ani nemůže.
- Neopravuje se. Cestou zpět je **Zrušit převzetí** s uvedením důvodu; potom
  jde měsíc převzít znovu z aktuálních podkladů.
- Nevstupuje do zákonných výstupů. Evidenční list, roční zúčtování,
  potvrzení o zdanitelných příjmech, vyúčtování daně ani hlášení pro ČSSZ
  a zdravotní pojišťovny z převzatého běhu nečerpají. Převzatá čísla pro ně
  mají vlastní, přiznanou cestu — nikdy se nesmí dostat do tiskopisu jako
  údaj spočítaný aplikací.

### 80.9.1 Doložení plateb za historický měsíc

U převzatého měsíce se pod tlačítkem **Zobrazit doložení plateb** vypíše, co
se za měsíc platilo. Rozlišují se dvě jistoty:

- **Čistá mzda** je uvedená po osobách. Nese-li převzatý záznam datum
  výplaty, zobrazí se u částky; jinak zůstane sloupec **Zaplaceno** prázdný
  s poznámkou *datum neznámé*.
- **Odvody a srážky** (sociální a zdravotní pojištění, záloha na daň,
  srážková daň, daňový bonus, srážky ze mzdy) jsou součtem složek převzaté
  mzdy za celou firmu. Datum u nich není a nedoplňuje se: převzatá data
  nenesou doklad o odeslané platbě. Záloha na daň a daňový bonus zůstávají
  rozepsané zvlášť, protože jejich rozdíl by byl dopočet aplikace, ne
  doložení.

Doložení plateb **není platba**. Nevzniká z něj platební závazek, platební
dávka ani úhrada, takže se neobjeví v saldu mzdových příkazů, v hlídači
termínů ani v účetním deníku. Je to záměr: za historický měsíc MyÚčto nikdy
nic nedlužilo — závazek vznikl i zanikl v původním programu a jeho účetní
obraz je v knihách už z převodu. Otevřený závazek by naopak trvale znečistil
saldo částkami, které jsou dávno zaplacené.

Pro měsíce, které počítá MyÚčto, se nic nemění: mzdové příkazy, úhrady
i saldo fungují dál přes [mzdové příkazy a úhrady](82_Platby_a_uhrady.md).
