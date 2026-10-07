# 108. Přechod z PAMICA

**Cesta: `Systém → Přechod z jiných účetních systémů → PAMICA (i SQL)`**

Průvodce převede personalistiku a mzdy z datového souboru mzdového programu
PAMICA, nebo z databáze PAMICA SQL, do firmy v MyÚčtu, i do firmy, která
účetnictví z POHODY nepřevádí.
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
nástroje ve dvou skupinách:

| Soubor | K čemu slouží |
|---|---|
| `Export-Pamica.cmd`, `Export-Pamica.ps1` | export z datového souboru PAMICA (`Mzdy*.mdb`) |
| `Export-PamicaSQL.cmd`, `Export-PamicaSQL.ps1` | export z databáze PAMICA SQL (§ 108.1.4) |
| `pamica-sql.example.json` | vzor konfigurace připojení k SQL Serveru |
| `PohodaSql-Common.ps1` | společné připojení k SQL Serveru; spouští ho `Export-PamicaSQL` |

Stáhněte soubory zvlášť, nebo tlačítkem *Stáhnout vše (ZIP)* jako
`pamica-export.zip`; soubory musí ležet ve stejné složce. `Export-PamicaSQL`
potřebuje vedle sebe i `Export-Pamica.ps1`, zápis exportu je společný.

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
pracovní poměry, zpracované mzdy, srážky, podání pro ČSSZ a pojišťovny, platby
a číselníky mezd. IČO ve jménu složky určí firmu, do které průvodce mzdy
nabídne; podle roku ve jménu složky pak měsíce.

Tabulky jdou do exportu celé, vynechají se jen čistě systémové sloupce (kdo
záznam označil a zamkl, výběr, ruční pořadí). Obsah odeslaných hlášení
a registrací drží PAMICA v binárních sloupcích; nástroj ho rozepíše na
jednotlivé údaje datového slovníku JMHZ, takže převod převezme i to, co
PAMICA za firmu podala (§ 108.12). Binární sloupec, který nejde přečíst
(obrázek, doručenka datové schránky), nástroj vynechá. Souhrn exportu vedle
ZIPu vypíše počty řádků po tabulkách, kolik podání se přečetlo a které tabulky
s daty export vědomě nebere (protokoly změn, odeslané e-maily, nastavení oken).

V náhledu exportu je u agendy sloupec **Mzdy** s počtem zaměstnanců a měsíců.
Firma musí v MyÚčtu existovat a mít vyplněné stejné IČO.

Po nahrání server export na pozadí rozbalí a přečte; průvodce mezitím ukazuje,
na kterém kroku je. Když se náhled nepodaří načíst (výpadek spojení, chyba
serveru), průvodce to oznámí a nabídne **Zkusit znovu**. Nahraný export na
serveru zůstává a znovu se nenahrává. Tlačítkem **Nahrát jiný export** začnete
od prvního kroku. Nahraný soubor
aplikace po 7 dnech bez práce s převodem sama smaže, po úspěšném ostrém
převodu hned.

Přechází-li firma z POHODY zároveň s účetnictvím i se mzdami z **POHODA
Mzdy**, použije se místo tohoto nástroje `Export-PohodaMdb.cmd` popsaný
v [§ 107.1.4](107_Prechod_z_POHODY.md#10714-majetek-z-datoveho-souboru) — vzniklý
`91_mzdy.xml` nahrajte beze změny sem, do tohoto průvodce; sám o sobě je
formátem shodný s exportem z PAMICA.

### 108.1.4 Export z PAMICA SQL

PAMICA SQL má tytéž tabulky jako datový soubor, jen v databázi na Microsoft SQL
Serveru. `Export-PamicaSQL.cmd` z ní vytvoří **stejný ZIP** jako
`Export-Pamica.cmd` (`pamica_export_<datum>.zip` se složkami `<IČO>_<rok>`
a souhrnem vedle). Posílá jen dotazy `SELECT` přes šifrované spojení jen pro
čtení; PAMICA může během exportu běžet.

1. Rozbalte `pamica-export.zip` do jedné složky na počítači s Windows, ze
   kterého je vidět SQL Server. Stačí Windows PowerShell 5.1.
2. Zkopírujte vzor `pamica-sql.example.json` jako `pamica-sql.json` vedle
   skriptu a vyplňte server, port a přihlášení. Klíče jsou stejné jako
   u POHODA SQL ([§ 107.1.6](107_Prechod_z_POHODY.md#10716-cesta-3-export-z-pohoda-sql)).
   Bez souboru se nástroj na všechno zeptá, heslo skrytě.
3. Spusťte `Export-PamicaSQL.cmd` dvojklikem. Když v konfiguraci chybí
   databáze, nástroj nabídne databáze na serveru, které mají zaměstnance,
   pracovní poměry a zpracované mzdy (tabulky `ZAM`, `ZAMpomer` a `MZ`),
   a mzdovou databázi vyberete číslem. Rozhoduje obsah databáze, ne její název.
4. Vzniklý `pamica_export_<datum>.zip` nahrajte do průvodce beze změny.

| Parametr | Význam |
|---|---|
| `-Config` | jiný konfigurační soubor než `pamica-sql.json` vedle skriptu |
| `-Databaze` | mzdová databáze; přebije `database` z konfigurace |
| `-Rok` | omezí export na zadané roky; bez něj se vyexportují všechny roky v datech |
| `-Ico` | IČO firmy, když se ho nepodaří určit z dat |

Uživateli SQL stačí role `db_datareader` v mzdové databázi. Výchozí ovladač
je součástí Windows; jen při volbě `"driver": "odbc"` nainstalujte
[Microsoft ODBC Driver for SQL Server](https://learn.microsoft.com/sql/connect/odbc/download-odbc-driver-for-sql-server).
Soubor `pamica-sql.json` s heslem po exportu smažte.

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

**Začátek vedení mezd před zpracovanými měsíci.** Firma, která už v MyÚčtu
je (například po dřívějším převodu nebo resetu dat s ponecháním nastavení
firmy), může mít začátek vedení mezd dřív, než končí měsíce zpracované
v PAMICA. Převod by pak tyto měsíce bral jako měsíce, které počítá MyÚčto:
vznikly by v nich vstupy náhrad za nepřítomnost z docházky a dohody
o srážkách, přestože je PAMICA už zpracovala a podala. Kontrola před převodem
to proto ohlásí a nabídne dvě volby:

- **Posunout začátek na MM/RRRR a převést** (výchozí): začátek se před
  převodem posune na měsíc po posledním uzavřeném měsíci exportu a měsíce
  do něj se převezmou jako zpracované předchozím programem. Protokol posun
  zapíše.
- **Převést bez posunu začátku**: jen pokud má MyÚčto tyto měsíce vědomě
  spočítat znovu. Volbu je třeba potvrdit zaškrtnutím.

Ostrý převod bez jedné z voleb se nespustí a protokol řekne proč. Zkouška
nanečisto proběhne vždy a ukáže, co zvolená možnost udělá. Má-li MyÚčto
v dotčených měsících už vlastní mzdový běh, posun se nenabízí: kontrola
jen upozorní, ať se měsíce nepočítají dvakrát. Běh pak zrušte, nebo převod
nechte bez posunu.

Počáteční stavy ročních kumulací (sociální vyměřovací základ, základ
a záloha daně, uplatněné slevy, bonus, srážková daň) převod zapíše za měsíce
roku před začátkem vedení mezd v MyÚčtu, jen za souvislou řadu měsíců
a jen osobě, která stavy ještě nemá.

Co převod doplní z odeslaných hlášení JMHZ a registrací, popisuje § 108.12.

Klasifikace složek odpovídá katalogu PAMICA / POHODA Mzdy: časová a úkolová
mzda, příplatky, odměny, proplacená dovolená, odstupné a obědy (srážka ze mzdy).
Příplatek za přesčas, za práci v sobotu a neděli a za práci ve svátek jde na
standardní složky, které mají v měsíčním hlášení vlastní kolonku.
Základní mzdu počítá MyÚčto ze sjednané mzdy vztahu, náhrady z hodin
a průměru; srážky a exekuce mají vlastní krok (§ 108.3).

**Sjednaná měsíční mzda** je měsíční sazba složky základní mzdy v PAMICA, ne
základní mzda vyplacená za měsíc (ta je krácená o dovolenou a překážky).
Zvýšení mzdy v průběhu roku založí od měsíce změny novou verzi. Opakovaný
převod opraví předpis měsíční mzdy, který zapsal dřívější převod; předpis
upravený účetní nemění.

**Měsíce, které počítá MyÚčto.** Za měsíce od začátku vedení mezd převod
z hodin docházky rovnou spočítá náhrady mzdy za dovolenou, lékaře, placené
volno (ve výši průměru), překážky na straně zaměstnavatele (sazbou, kterou
platila PAMICA) a za svátek u mzdy za hodiny nebo úkol. Svátek v jinak pracovní
den u měsíční mzdy převod vede zvlášť, ne mezi odpracovanými hodinami.

**Náhrada mzdy při nemoci.** U převzaté dočasné pracovní neschopnosti, která
zasahuje do měsíců počítaných MyÚčtem, převod spočítá náhradu mzdy stejně
jako schválení v `Mzdy → Nepřítomnosti`: okno prvních 14 dnů, redukovaný
průměr ze schváleného průměru čtvrtletí, ve kterém nemoc začala. Dobu měří
rozvrhem pracovního kalendáře (měsíc ze souhrnu docházky směny nemá). Náhrada
vznikne jen za dny od začátku vedení mezd, dřívější dny zaplatila PAMICA.
První den nemoci bere převod jako neodpracovaný; když ho zaměstnanec celý
odpracoval, opravte nepřítomnost v `Mzdy → Nepřítomnosti`. Nepřítomnost
bez schváleného průměru protokol vypíše; po doplnění průměru převod
zopakujte. Vrácenou dovolenou zadejte ručně v `Mzdy → Vstupy`.

**Opakovaný převod** téhož exportu nic nezmění. Když se sešit měsíce, který
počítá MyÚčto, od dřívějšího převodu změnil (nový export nebo novější verze
převodu), převod ho převede znovu: pracovní měsíce se souhrnem z dřívější
dávky sám znovu otevře, zapíše nový souhrn a mzdové vstupy dřívější dávky,
které nová dávka už nevede (třeba složka, kterou nová verze převodu vede
jinak), zruší. Protokol vypíše počty i kódy zrušených složek. Měsíc, jehož
mzdový běh už má zamčené vstupy, převod znovu nepřevede a vypíše ho:
neschválený běh nejdřív zrušte v `Mzdy → Mzdové běhy`, schválený měsíc převod
nepřepisuje.

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

Protokol případy rozdělí podle toho, jestli je PAMICA opravdu srážela. Exekuce
s kladnou srážkou v některém ze tří posledních zpracovaných měsíců, která
neskončila a není doplacená, vypíše zvlášť s měsícem poslední srážky: srážet
se má dál, takže ji doložte a aktivujte ještě před prvním mzdovým během, jinak
ji běh vynechá. Aktivace potřebuje exekuční příkaz, soud nebo exekutora,
oprávněného a příjemce; oprávněného export z PAMICA nenese. Ostatní případy
(nový příkaz, odklad, doplacená pohledávka) vypíše protokol jako informaci
k ověření proti spisu.

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

- **Vyplacené dávky nemocenského (`MZdavky`).** Od roku 2009 je vyplácí ČSSZ,
  takže se jako částky nepřenášejí. Rozpracovaná neschopnost se ale převede
  jako případ dávky (§ 108.7).
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

K rozběhnuté neschopnosti, ošetřování nebo mateřské převod založí i **případ
dávky** v `Mzdy → Podání → Dávky nemocenského` se skutečným dnem vzniku
z PAMICA. Oznámení NEMPRI, jehož lhůta začala běžet před prvním měsícem
vedeným v MyÚčtu, je v případu vedené jako podané předchozím programem a
MyÚčto ho znovu nepřipraví. Připadá-li patnáctý den neschopnosti až na první
měsíc v MyÚčtu, hlídá NEMPRI MyÚčto; podala-li ho přesto PAMICA, zapište to
u případu tlačítkem **NEMPRI podal předchozí program**. Hlášení HZUPN
k návratu do práce podává už MyÚčto a hlídač termínů ho hlídá. Opakovaný
převod případ nezdvojí.

## 108.8 Přechod uprostřed roku

Měsíce před zahájením vedení mezd v MyÚčtu se nepřepočítávají — jejich
výsledky se uloží jako počáteční stavy ročních kumulací (§ 108.2, Začátek
vedení mezd) a jako **převzaté mzdy** po měsících.

Obě vrstvy mají jiný účel. Z **počátečních stavů** vychází roční zúčtování,
potvrzení o zdanitelných příjmech ze závislé činnosti (§ 38j odst. 3 zákona
o daních z příjmů), roční mzdový list i vyúčtování záloh a srážkové daně.
**Převzaté mzdy** čte evidenční list důchodového pojištění, převzatý běh,
kontrolní sestava, návrh průměrného výdělku pro první čtvrtletí po přechodu
a hlídání ročního limitu dohod o provedení práce. Převzatá část je v dokladu vždy označená —
není to výpočet MyÚčta. Chybí-li převzatému měsíci údaj, který doklad
potřebuje, doklad se raději nevystaví a řekne, co doplnit.

Obě vrstvy plní převod z PAMICA sám. Zákazník, který přichází odjinud,
postupuje podle kapitoly
[Přechod mezd v průběhu roku](113_Prechod_mezd_v_prubehu_roku.md): převzaté
mzdy zadá ručně po měsících, nebo je nahraje z CSV či XLSX v
`Mzdy → Importy → Převzaté mzdy`. Ruční zadání plní obě vrstvy najednou.
Import souboru plní jen převzaté mzdy; počáteční stavy se pak doplní na kartě
pracovního vztahu a **Kontrola převzetí** ukáže, kde se vrstvy rozcházejí.

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

Měsíc, který se nepodařilo převést, je v protokolu rozdíl k přijetí: ostatní
měsíce se převedou a ostrý převod jde po zkoušce nanečisto spustit i s ním,
viz [§ 103.5.1](103_Prechod_z_Money_S3.md#10351-chyby-upozorneni-a-rozdily-k-prijeti).

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

## 108.12 Odeslaná hlášení JMHZ a registrace

Po údajích osob a vztahů projde převod podání, která PAMICA za firmu podala:
měsíční hlášení JMHZ převáděného roku a registrace zaměstnanců (přihlášky,
odhlášky, registrace trvajících vztahů). V protokolu je to krok **Podaná
hlášení a registrace**.

**Zpracované mzdy zůstávají zdrojem převzatých mezd.** Z hlášení se nic
nepřepisuje. Doplní se jen údaje, které po převodu karet a mezd v MyÚčtu
chybí, a to stejnými zápisy jako údaje z karet:

| Z odeslaného hlášení nebo registrace | Do MyÚčta |
|---|---|
| příspěvek APZ, funkční požitky, dočasné přidělení | podmínky vztahu, jen dosud neověřený příznak |
| fond pracovní doby a stanovená týdenní doba | týdenní pracovní doba a úvazek, jen vztahu bez týdenní doby |
| druh činnosti, upřesnění vztahu, CZ-ISCO | podmínky vztahu, jen prázdná pole |
| obec, kód obce a stát pracoviště, sjednané místo výkonu práce | pracoviště JMHZ, jen vztahu bez kódu obce a se shodným místem výkonu práce |
| OIČ a ID PPV | identifikátory ČSSZ, jen s potvrzením v průvodci a jen chybějící |
| průměrný hodinový výdělek | schválený průměr čtvrtletí, které průměr ze zpracovaných mezd nemá |
| vyživované děti z prohlášení poplatníka | vyživované osoby, jen osobě, která žádnou nemá |

Podmínky vztahu doplní převod po každém převedeném měsíci z hlášení za ten
měsíc, stejně jako pracoviště z karet. Dny důchodového pojištění a vyloučené
doby z hlášení převod porovná se dny, které převzaté mzdy odvodily ze
zpracované mzdy; rozdíl vypíše po osobních číslech a měsících, nic nemění.

**Platí poslední odeslané podání za měsíc.** Opravné hlášení nahrazuje řádné
téhož měsíce. Hlášení, které PAMICA připravila, ale neodeslala, se nepoužije;
protokol na něj upozorní větou *Hlášení za MM/RRRR nebylo odesláno*.

**Historie podání.** Každé hlášení a registrace (i neodeslané) se uloží do
historie podání předchozím programem: období, druh, GUID podání a formulářů,
stav odeslání, časy odeslání a přijetí, vazba formulářů na vztahy a úplný
obsah po údajích. Opakovaný převod záznamy aktualizuje, nezdvojí. Přehled je
v `Mzdy → Podání → JMHZ`, oddíl *Podání předchozím programem*
([§ 85.7.4](85_Podani_a_hlaseni.md#8574-podani-predchozim-programem)); za měsíc,
za který řádné hlášení odešlo, MyÚčto řádné hlášení znovu nepřipraví.

Prohlášení poplatníka, slevy na dani a sleva pracujícího důchodce se z hlášení
nepřebírají: nesou je už zpracované mzdy. Údaje registrace, které evidence
bere z karty zaměstnance (adresy, doklad totožnosti, vzdělání), a údaje, pro
které nemá místo, zůstávají v obsahu uloženém v historii podání.
