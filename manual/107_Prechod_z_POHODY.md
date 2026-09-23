# 107. Přechod z POHODY

**Cesta: `Systém → Přechod z POHODA`**

Průvodce převede vybrané účetní roky z programu POHODA do firmy v MyÚčtu. Vstupem je ZIP
s XML, který připravíte jednou ze dvou cest: exportem přes XML rozhraní POHODY,
nebo místním převodem kopie datového souboru MDB na Windows. Nástroje stáhnete
přímo z průvodce. Zdrojová data jen čtou, v POHODĚ nic nemění.

Průvodce obě varianty ZIP automaticky rozpozná. Následuje stejný náhled,
zkouška nanečisto a převod. Samotný MDB ani původní zálohu POHODY na server
nenahrávejte.

Průvodce převádí jen **účetnictví**. Mzdy z datového souboru POHODA Mzdy nebo
z programu PAMICA převádí samostatný průvodce, viz kapitola
[Přechod z PAMICA](108_Prechod_z_PAMICA.md).

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
| `Export-PohodaMdbAccounting.cmd`, `Export-PohodaMdbAccounting.ps1` | převod účetnictví z kopie MDB do ZIP s XML bez spouštění XML exportu POHODY |
| `Export-PohodaMdb.cmd`, `Export-PohodaMdb.ps1` | majetek a mzdy z datového souboru POHODY (XML export je neobsahuje) |

Stáhněte každý soubor zvlášť, nebo všechny najednou tlačítkem *Stáhnout vše (ZIP)*
jako `pohoda-export.zip`. Všechny soubory musí ležet ve stejné složce.

Balíček rozbalte na počítači s Windows. Nástroje běží v PowerShellu 5.1,
který je součástí Windows 10 a 11. Pro první cestu je potřeba nainstalovaná
POHODA, kterou exportér spouští v režimu XML komunikace z příkazového řádku.
Pro druhou cestu potřebujete kopii MDB a ovladač Microsoft Access Database
Engine (ACE).

### 107.1.2 Cesta 1: export XML přes POHODU

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
  každou kombinaci IČO a roku. Převádějí se roky, které zaškrtnete v náhledu
  (jeden i víc najednou). Nevybrané roky zůstávají v POHODĚ.
- Majetek a mzdy XML export POHODY neobsahuje. Nástroj je čte přímo
  z datového souboru a přidá do exportu jako `90_majetek.xml`
  a `91_mzdy.xml`, viz 107.1.4. Tento průvodce ale zpracuje jen
  `90_majetek.xml` — mzdy z téhož souboru převede
  [Přechod z PAMICA](108_Prechod_z_PAMICA.md).
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

### 107.1.4 Majetek z datového souboru

XML rozhraní POHODY nevrací dlouhodobý majetek, drobný majetek ani mzdy. Čte
je skript `Export-PohodaMdb.ps1` přímo z datového souboru POHODY (`.mdb`),
u POHODA SQL z databáze agendy. Data jen čte, nic v nich nemění.

| Soubor | Obsah |
|---|---|
| `90_majetek.xml` | karty dlouhodobého majetku, daňové odpisy po letech, účetní odpisy po měsících, drobný majetek a jeho zdrojové doklady |
| `91_mzdy.xml` | zaměstnanci, pracovní poměry, zpracované mzdy a číselníky mezd — tento průvodce ho nevyužije, viz [Přechod z PAMICA](108_Prechod_z_PAMICA.md) |

**Při běžném exportu nemusíte dělat nic.** `Export-Pohoda.cmd` skript spustí
sám po každé agendě a soubory přidá do její složky v ZIP. Datový soubor najde
ve složce dat POHODY; když leží jinde, zadejte ji parametrem `-DataDir`.
U POHODA SQL se připojí k instanci `.\POHODA`, jinou určí `-SqlServer`.
Když datový soubor nenajde, export doběhne a souhrn to uvede. Parametrem
`-BezMajetkuAMezd` se tento krok vynechá.

**Samostatně** skript spusťte, když máte jen datový soubor:

```
Export-PohodaMdb.cmd -Mdb "C:\...\StwPh_12345678_2026.mdb" -Vystup .\pohoda_export\12345678_2026
```

| Parametr | Význam |
|---|---|
| `-Mdb` | datový soubor POHODY |
| `-SqlServer`, `-Databaze` | místo `-Mdb` databáze POHODA SQL (`StwPh_<IČO>_<rok>`) |
| `-Vystup` | složka agendy pojmenovaná `<IČO>_<rok>`; podle ní průvodce pozná firmu a rok |

Složku `<IČO>_<rok>` pak zabalte do ZIP (samotnou, nebo spolu s XML exportem
agendy) a nahrajte do průvodce. Soubor vznikne, jen když v datovém souboru
něco je: majetek, jen když jsou karty, mzdy, jen když jsou zaměstnanci nebo
mzdy — tento průvodce ale z nahraného `91_mzdy.xml` převede jen majetek,
mzdy zpracuje [Přechod z PAMICA](108_Prechod_z_PAMICA.md). Systémové údaje
(kdo a kdy záznam změnil) skript nevytahuje.

Pro `.mdb` je potřeba ovladač Microsoft Access Database Engine, který se
instaluje s POHODOU. Když ho 64bitový PowerShell nenajde, skript se sám spustí
v 32bitovém.

### 107.1.5 Cesta 2: účetnictví z MDB přes místní převodník

Tato cesta čte datový soubor přímo a vynechává postupné exportní požadavky
na XML rozhraní POHODY. Hodí se, když máte datový soubor MDB a export přes
POHODU trvá dlouho. Pro POHODA SQL použijte první cestu.

1. V POHODĚ ověřte firmu a účetní rok, které chcete převést. Připravte
   odpovídající datový soubor `.mdb`. Zavřete POHODU u všech uživatelů
   a pracujte s kopií souboru. Pokud máte zálohu v ZIP, nejprve z ní obnovte
   datový soubor. ZIP zálohy není MDB a do převodníku nepatří.
2. V průvodci stáhněte *exportér a převodník (ZIP)* a rozbalte celý
   `pohoda-export.zip` do jedné složky na Windows.
3. Spusťte `Export-PohodaMdbAccounting.cmd` dvojklikem a vyberte připravenou
   kopii MDB. Převodník běží ve Windows PowerShellu 5.1, PHP na svém počítači
   instalovat nemusíte. Potřebuje ovladač Access. Pokud chybí, nainstalujte
   [Microsoft 365 Access Runtime z webu Microsoftu](https://support.microsoft.com/en-us/access/download-and-install-microsoft-365-access-runtime).
   Zvolte 32bitovou (x86) nebo 64bitovou (x64) variantu podle nainstalovaného
   Office.
4. Počkejte na vytvoření výsledného ZIP s XML účetnictví, majetku a mezd.
   Doplněk pro majetek a mzdy zvlášť spouštět nemusíte, převodník jej používá
   automaticky. Převodník vypíše cestu k výsledku. Zdrojový MDB zůstává
   na vašem počítači.
5. Do průvodce nahrajte až tento výsledný ZIP. Nerozbalujte jej
   a nenahrávejte místo něj MDB, původní zálohu ani stažený balíček nástrojů.
6. Průvodce formát automaticky rozpozná. Zkontrolujte firmu, rok a počty
   dokladů, spusťte zkoušku nanečisto a projděte její protokol před ostrým
   převodem.

Převod MDB běží na Windows, následný import ZIP funguje stejně i na serveru
s Linuxem. Server pro tuto cestu nepotřebuje ovladač Access ani přístup
k původnímu MDB. Mzdy se nadále převádějí samostatným průvodcem
[Přechod z PAMICA](108_Prechod_z_PAMICA.md).

## 107.2 Co převod přenese

| Z POHODY | Do MyÚčta |
|---|---|
| účtová osnova (jen účty, na které se účtovalo) | analytiky pod syntetiky osnovy |
| účetní rok | účetní období 1. 1. až 31. 12. |
| účetní deník včetně počátečních stavů | účetní zápisy, počáteční stavy jako otevírací zápis k 1. dni období |
| adresář | klienti, párování podle IČO |
| předkontace | pravidla zaúčtování se zkratkou z POHODY |
| přijaté a vydané faktury | doklady se stavem zaúčtováno nebo uhrazeno; doklad nejisté daňové povahy jako koncept k ruční kontrole |
| vydané doklady v režimu OSS (členění mimo přiznání, daň, stát MOSS) | plnění v režimu OSS včetně země spotřeby, typu sazby a typu plnění |
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
se nepřebírají. Agenda POHODY často vede i doklady po konci roku (výpisy
a faktury dalších měsíců). Jejich zápisy převod dá do účetního období podle
skutečného data; chybějící období následujícího roku založí otevřené. Převáděný
rok zůstane neuzavřený, uzávěrku a převod zůstatků do dalšího roku provede
účetní v MyÚčtu. Doklad s datem před převáděným rokem se zaúčtuje k prvnímu dni
období.

**Popisy zápisů se dogenerují.** POHODA veze v řádku deníku jen volný text, který
je u celé řady dokladů shodný („Fakturujeme Vám za …"). Po navázání dokladů proto
převod popisy přeskládá do tvaru **doklad — protistrana — obsah**, aby se zápisy
v deníku daly rozlišit; v protokolu to uvidíš jako *„U N převedených zápisů se popis
doplnil o číslo dokladu a protistranu."* Částek, účtů ani dat se to nedotýká a jde
to kdykoli zopakovat — viz [§ 52.12.1](52_Ucetni_denik.md#52121-dogenerovani-popisu-u-prevzatych-zapisu).

**Doklady v režimu OSS.** Vydaná faktura, jejíž členění DPH stojí mimo přiznání
a přesto nese daň, je typicky prodej koncovému zákazníkovi do jiného členského
státu — v POHODĚ se vede vlastní zkratkou členění bez řádku přiznání, sazbou
státu spotřeby, odběratelem bez DIČ a vyplněným **státem MOSS**. Převod takový
doklad převezme rovnou jako [OSS plnění](45_OSS.md): nastaví na řádcích příznak
OSS, zemi spotřeby a typ sazby a doklad vstoupí do OSS přiznání, ne do českého.
Zemí spotřeby je stát MOSS z dokladu, adresa odběratele jen tehdy, když stát
MOSS chybí. Rozhoduje o tom stejné pravidlo jako u všech ostatních cest
([§ 45.4](45_OSS.md#454-jak-vznika-oss-radek)), tedy číselník sazeb členských států.

Doklad s členěním mimo přiznání a s daní, který stát MOSS nemá, POHODA do svého
OSS přiznání nezahrnula. Převod ho proto převezme jako koncept k ruční kontrole
s důvodem *„… není v režimu OSS (chybí stát MOSS)"*; řádky s daní jsou navržené
podle země odběratele a označené k ručnímu posouzení. Rozhodněte, zda plnění
patří do OSS, nebo do tuzemského přiznání, a koncept potvrďte.

U dokladu v eurech převezme převod do OSS přiznání **částky v eurech přímo
z dokladu** (ruční částky pro OSS na řádku). Plnění v eurech se pro OSS
nepřepočítává, takže podání sedí na eura z POHODY. Tuzemská evidence dokladu
zůstává v korunách.

Aby to fungovalo, musí být před převodem splněné dvě věci:

- firma má **zapnutý režim OSS** ([§ 45.3.1](45_OSS.md#4531-zapnuti-rezimu-a-platnost-registrace))
  s platností pokrývající převáděný rok,
- v číselníku DPH sazeb jsou **sazby států spotřeby** se správným státem
  ([§ 45.3.2](45_OSS.md#4532-sazby-dph-pro-cizi-zeme-hlidej-pole-stat) — formulář
  předvyplňuje `CZ`, což je nejčastější příčina, proč se doklad nepřevede).

Když některá chybí, řekne to protokol jednou větou hned u prvního takového
dokladu. Doklady, u kterých sazbu není na co navázat, převod nepřevezme a vypíše
je jmenovitě; po doplnění nastavení stačí převod zopakovat, doplní se jen ony.
Typ plnění (zboží/služba) převod bere z typu plnění MOSS na položce dokladu:
dodání zboží je zboží, ostatní druhy (elektronické, telekomunikační a ostatní
služby) jsou služba. Jen když ho položka nemá, odvodí se z měrné jednotky, karty
odběratele a CZ-NACE — pak u e-shopu se zbožím před převodem vyplňte
[výchozí typ plnění na kartě odběratele](45_OSS.md#4534-vychozi-nastaveni-na-karte-odberatele)
nebo převažující činnost firmy, jinak řádky spadnou na výchozí „služba" (protokol
na to upozorní).

Export z datového souboru (MDB) nese údaje OSS jen z aktuální verze exportního
nástroje. Se starším exportem skončí doklady v režimu OSS jako koncepty kvůli
chybějícímu státu MOSS; vytvořte export znovu.

**Doklady k ruční kontrole.** Fakturu, jejíž daňovou povahu export spolehlivě
neurčuje, převod převezme jako koncept, například doklad s daní bez členění
DPH, doklad s neznámým členěním nebo samovyměření bez interního dokladu.
Koncept nevstoupí do přiznání k DPH, kontrolního hlášení ani do účtování.
Protokol ho vypíše i s důvodem. Po opravě klasifikace DPH ho potvrďte.
U dokladů, které vypadaly na OSS a nerozhodlo se o nich, důvod rovnou říká, co
doplnit; hromadně je pak dorovná akce
[Nastavit OSS](14_Faktury.md#1432-hromadne-nastaveni-oss) v seznamu faktur.

Pohledávky, závazky a interní doklady mimo přiznání k DPH zůstanou jen jako
zápisy v deníku, samostatný doklad z nich nevzniká.

Číslo dokladu, které už ve firmě je, dostane příponu roku, například
`FV-0001/2026`.

## 107.3 Co převod nepřenese

- **Sklad.** Zápisy jsou v převedeném deníku, zásoby se zakládají v MyÚčtu.
- **Mzdy.** Tento průvodce mzdy nepřevádí, ani z datového souboru
  (`91_mzdy.xml`); ty převede samostatný průvodce
  [Přechod z PAMICA](108_Prechod_z_PAMICA.md).
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
   zápisů. Převést jde jen agendu s IČO firmy v MyÚčtu. Roky k převodu
   zaškrtněte v prvním sloupci tabulky; předvybrané jsou všechny roky agend
   s IČO firmy. Soubory exportu, které POHODA vrátila prázdné nebo které
   v exportu chybí, průvodce vypíše jako informaci.

   Agenda POHODY často vede i doklady po konci roku. Každý takový pozdější
   rok průvodce nabídne jako samostatný řádek pod agendou („Doklady roku 2026
   vedené v agendě 2025"), starší doklady přenesené jako neuhrazené z minulých
   let samostatný rok netvoří. Pozdější rok jde převést jen spolu s rokem
   agendy: jeho zaškrtnutí vybere i rok agendy, odškrtnutí roku agendy ho
   zruší. Nevybraný pozdější rok převod přeskočí: zápisy deníku, pohyby
   v bance a pokladně, faktury a úhrady s datem v něm nepřevede a protokol
   uvede jejich počty. Faktura uhrazená až v nevybraném roce zůstane
   v MyÚčtu neuhrazená. Chcete-li třeba jen rok 2025, zaškrtněte jen jeho
   agendu; rok 2026 doplníte později opakovaným převodem (107.7).

   Kontrola před převodem se ukáže pro každý vybraný rok zvlášť. Převod
   zastaví, když u kteréhokoli vybraného roku:
   - export patří firmě s jiným IČO,
   - v exportu chybí nebo nejde přečíst účetní deník, osnova nebo členění DPH,
   - účetní období v MyÚčtu už obsahuje zápisy, které nevznikly převodem,
   - období je v MyÚčtu uzavřené.

   Obojí platí i pro vybrané období následujícího roku, do kterého padají
   doklady agendy s pozdějším datem.
3. **Zkouška nanečisto.** Proběhne celý převod vybraných roků včetně
   rekonciliace, na konci se ale všechno vrátí. Výsledkem je protokol za každý
   rok; v MyÚčtu nic nezůstane a nastavení automatiky se nezmění. Každý rok se
   zkouší samostatně a hned po své zkoušce se vrátí, pozdější rok proto ve
   zkoušce nevidí data předchozího roku (počáteční stavy, převzaté doklady
   a úhrady) a jeho výsledek se od ostrého převodu může lišit. Zkouška běží
   v databázové transakci, spouštějte ji proto mimo běžnou práci ve firmě.
4. **Ostrý převod.** Potvrzení vyjmenuje převáděné roky. Převod běží na
   pozadí, stránku můžete zavřít. Roky se převádějí vzestupně jeden po druhém
   a průběh ukazuje, kolikátý rok z kolika právě běží. Skončí-li rok chybou
   nebo převod zrušíte, další roky se nespustí a průvodce je vypíše. Po
   dokončení průvodce ukáže protokoly všech převedených roků a nabídne účetní
   deník a obratovou předvahu. Převod jedné firmy běží vždy jen jeden, druhý
   se do jeho konce nespustí.

### 107.4.1 Navázání na existující číselnou řadu

Převod přenáší doklady s čísly, která měly v POHODĚ, ale počítadlo nové řady
tím sám nenastaví. Číslo, kterým má řada v MyÚčtu pokračovat, zadejte
v Nastavení → Doklady → **Číslování faktur** do pole **Příští číslo** u příslušné
šablony a potvrďte tlačítkem *Nastavit počítadlo*. Ukládá se samostatně, mimo
tlačítko *Uložit*, a po potvrzení ukáže náhled výsledného čísla.

Vlastní řadu může mít i jednotlivý zákazník nebo kategorie tržby; pole *Příští
číslo* je pak u jejich šablony. U zděděné šablony se pole nenabízí, protože se
čísluje řadou dodavatele a počítadlo je společné.

> ⚠️ Zkontrolujte, že **perioda resetu sedí se šablonou**: u masky bez `{MM}`
> a měsíčního resetu by počítadlo prvního dne dalšího měsíce spadlo zpátky na
> začátek a čísla by kolidovala. Podrobně viz
> [§ 95.5.3](95_Multi_supplier.md#9553-cislovani-faktur).

> 🛈 Sestava *Úplnost číselné řady* začne řadu počítat až od nastaveného čísla,
> takže začátek řady na vyšším čísle nehlásí jako chybějící doklady.

## 107.5 Rekonciliace a protokol

Každý převáděný rok (ve zkoušce i v převodu) má vlastní běh a protokol. Najdete v něm kroky převodu
s počty, upozornění a chyby a rekonciliaci převáděného roku:

- obratová předvaha MyÚčta proti předvaze spočtené přímo z deníku POHODY
  v exportu, po syntetických účtech, počáteční stav, obrat a konečný stav na haléř,
- obraty MD = D, předvaha = deník, vyrovnané počáteční stavy, žádné rozpracované zápisy,
- rozvaha vyrovnaná a všechny účty zařazené ve výkazu; účty, které výkazy
  neznají, protokol vypíše k přiřazení v mapování výkazů,
- doklady proti deníku: přijaté faktury proti 321, vydané proti 311, pokladna
  proti 211 a banka proti 221. Doklady účtované jinak (zápočet, úhrada v témže
  zápisu) protokol uvede zvlášť. Rozdíl, který je už v deníku POHODY, převod
  nehlásí jako chybu, ale vypíše ho po dokladech: doklad zní na jinou částku,
  než kolik jeho zápis v deníku POHODY dá na účet dokladu, a MyÚčto převzalo
  obojí beze změny. Typicky jde o odpočet nedaňové zálohy, který POHODA
  zaúčtovala kladně na stranu MD účtu 311 (například 311/602). Faktura je
  uhrazená, účet 311 ale v deníku drží navíc částku zálohy. Saldo takových
  dokladů zkontrolujte a případně opravte interním dokladem.

Doklad, ke kterému v deníku POHODY není zápis se stejným číslem, protokol
vypíše jako doklad bez zápisu. Takový doklad není zaúčtovaný. Zaúčtujte ho
ručně nebo hromadně v Účetnictví → Doúčtovat doklady.

Úhradu faktury páruje převod podle likvidace, kterou POHODA u faktury drží:
číslo bankovního nebo pokladního dokladu a datum úhrady. Mezi pohyby se
stejným číslem rozhoduje datum. Nejednoznačnou úhradu převod nespáruje
a protokol ji vypíše k ručnímu spárování. Úhrada zápočtem nebo zálohou se
jako úhrada bankou ani pokladnou nepáruje. Spárovaná faktura dostane stav
uhrazeno.

### 107.5.1 Pohyby, které POHODA nezaúčtovala

Bankovní pohyb s předkontací „Nevím" v deníku POHODY zápis nemá. Bývá to
úhrada faktury, kterou účetní ještě nezlikvidovala. Převod ji spáruje
s fakturou a rovnou zaúčtuje úhradu (321/221, u příjmu 221/311, na analytiku
předpisu faktury a banky) jako bankovní zápis pohybu, ale jen když je shoda
jednoznačná. Rozhoduje v tomto pořadí:

1. úhrada, kterou u faktury eviduje POHODA,
2. párovací symbol pohybu nebo jeho položky (číslo nebo VS otevřené faktury)
   a částka,
3. variabilní symbol pohybu a částka,
4. účet protistrany uvedený na přijaté faktuře, částka a datum platby
   v rozumném okně kolem splatnosti.

Výdaj se páruje jen s přijatou fakturou, příjem jen s vydanou. Faktura musí
být jediná pro pohyb a pohyb jediný pro fakturu. Nejistou shodu (víc faktur
se stejnou částkou, jiná částka při shodném symbolu) převod jen navrhne,
návrh najdete u výpisu. Platby kartou a ostatní pohyby zůstávají
k zaúčtování v Účetnictví → Doúčtovat doklady. Úhradu dokladu, jehož saldo
je v počátečních stavech nebo který v deníku POHODY zápis nemá, převod spáruje,
ale nezaúčtuje a protokol ji vypíše.

Zaúčtuje-li POHODA takový pohyb později sama, opakovaný převod novějšího
exportu odvozený zápis stornuje a pohyb nese zápis z deníku POHODY; úhrada
v deníku není dvakrát. Rekonciliace se zápisy odvozených úhrad počítá
a protokol uvede jejich počet.

Protokoly všech běhů zůstávají v přehledu pod průvodcem. Protokol zkoušky
nanečisto z přehledu smažete, protokol ostrého převodu zůstává.

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
rok: nahrajte export a zaškrtněte jiný rok. Stejně se doplní pozdější rok
agendy, který jste napoprvé nevybrali: převod založí jen jeho zápisy, pohyby,
doklady a úhrady a doklady uhrazené v tomto roce označí jako uhrazené. Zápisy, které do období dalšího roku
přinesla už agenda minulého roku, převod dalšího roku podruhé nezaloží.
Zápis, který dřívější převod posunul k 31. 12., opakovaný převod přesune do
období podle jeho data (jen v otevřených obdobích a mimo uzamčené datum).

Už převedené doklady ani zápisy deníku opakovaný převod nepřepisuje, protože
mohly být mezitím zaúčtované, spárované nebo upravené v MyÚčtu. Změnila-li se
v POHODĚ celková částka faktury, protokol ji vypíše jako změněnou v POHODĚ
a ponechanou v MyÚčtu; upravte ji ručně.

Před převodem firmy znovu od začátku stáhněte v průvodci **profil firmy** a po
ostrém převodu ho nahrajte zpět, viz [§ 96.18](96_Nastaveni.md#9618-profil-firmy).

## 107.8 Omezení

- Převádí se kalendářní účetní rok, období se vždy založí od 1. 1. do 31. 12.
  (i období následujícího roku pro doklady agendy s pozdějším datem).
- Převod čte jen export vytvořený nástrojem, nikdy živou databázi POHODY.
- Jeden běh převede vybrané roky jedné firmy, každý rok s vlastním
  protokolem. Agenda roku musí mít IČO firmy v MyÚčtu.
- Nahraný export zůstává na serveru pro další běh. Smaže se po úspěšném
  ostrém převodu, který prošel všechny agendy firmy v exportu, jinak ho
  aplikace smaže po týdnu bez práce s ním.
  Obsahuje-li export i `91_mzdy.xml`, nahrajte ho beze změny i do průvodce
  [Přechod z PAMICA](108_Prechod_z_PAMICA.md) — mzdy tento průvodce
  nepřevede.
