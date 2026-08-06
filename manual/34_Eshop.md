# 34. E-shop

**Cesta: `Zboží → E-shop`** *(poslední položka sekce Zboží, viditelná jen když je
v [Nastavení](72_Nastaveni.md) zapnutý modul **Sklad**)*

Modul **E-shop** rozšiřuje skladovou kartu zboží (`Zboží → Skladové karty`) o vše,
co potřebuješ pro **prodej přes e-shop**: vícejazyčný popis a SEO, zařazení do
kategorií a označení štítky, typované parametry/atributy, poplatky (autorský,
recyklační…), cenotvorbu odvozenou z nákupní ceny ve více měnách, dodavatele
zboží a hromadný import. Stránka `/eshop` sama o sobě **needituje jednotlivé
zboží** — je to sada **číselníků a nastavení**, které pak využiješ na kartě
konkrétní položky v editoru skladové karty (záložky „Jazyky", „Kategorie &
štítky", „Parametry", „Ceny", „Dodavatelé", „Přílohy").

Kapitola má dvě části: **§ 34.1–33.7** popisují číselníky a import na stránce
`/eshop`, **§ 34.8–33.10** pak cenotvorbu a dodavatele, které se zadávají přímo
na kartě zboží. Pokud tě zajímá jen nacenění katalogu, začni
[§ 34.8](#348-cenotvorba).

> [!NOTE]
> **Karta zboží nemusí mít skladový stav.** Pokud u položky vypneš příznak
> „Skladová položka" (`is_stocked = 0`), karta funguje bez jediné příjemky —
> jen se nacení a popíše, prodává se přes dodavatele (dropshipping) a skladové
> množství se nesleduje. To je hlavní důvod, proč e-shopová prezentace žije
> jako nadstavba nad skladem, ne jako podmínka „napřed naskladni".

## 34.1 Přehled záložek

Stránka `/eshop` má nahoře vodorovné taby, mezi kterými se přepínáš (stav
tabu se ukládá do URL, takže jde odkázat i naback/refresh):

| Záložka | Obsah |
|---|---|
| **Výrobci** | Číselník výrobců/značek zboží |
| **Kategorie** | Strom kategorií e-shopového katalogu |
| **Atributy** | Typované parametry zboží (barva, rozměr, výkon…) vč. voleb pro výběrové atributy |
| **Tagy** | Barevné štítky zboží |
| **Poplatky** | Typy poplatků (autorský, recyklační/PHE…) s vlastní sazbou DPH |
| **Sklady** | Stejná záložka jako `Zboží → Skladové karty → Sklady` — sklady patří oběma pohledům |
| **Import zboží** | Hromadný import/aktualizace karet z XLSX/CSV |

Každý číselník (Výrobci, Kategorie, Atributy, Tagy, Poplatky) má stejný tvar:
tabulka existujících záznamů, tlačítko **„Nový…"** vpravo nahoře a u každého
řádku ikony **tužky** (upravit) a **koše** (smazat). Editace i mazání jsou
dostupné jen uživatelům s právem zápisu — u readonly uživatele akční sloupec
zmizí úplně.

## 34.2 Výrobci

Jednoduchý číselník značek: **Kód**, **Název**, **Web** (odkaz, otevře se v
novém okně), **Pořadí** (řadí výpis v e-shopu), příznak **Exportovat** (zda se
výrobce má zobrazit na e-shopu) a **Aktivní/Neaktivní**. Kód musí být v rámci
firmy jedinečný.

Výrobce následně přiřadíš konkrétní kartě zboží v poli „Výrobce" na záložce
„Obecné" editoru skladové karty. Import zboží umí výrobce **přiřadit podle
kódu**, ale **nezakládá je** — pokud kód v souboru neexistuje mezi výrobci,
řádek importu skončí chybou s instrukcí založit výrobce nejdřív ručně zde.

## 34.3 Kategorie

Kategorie tvoří **strom** (kategorie může mít nadřazenou kategorii, ta svou
vlastní atd.) — v tabulce se odsazují podle hloubky a mají ikonu šipky u
podkategorií. Formulář obsahuje:

- **Kód**, **Název**,
- **Nadřazená kategorie** — vyhledávací select se seznamem existujících
  kategorií (odsazeno podle úrovně); prázdné = kategorie první úrovně,
- **Pořadí** zobrazení,
- příznak **Exportovat** (viditelnost na e-shopu) a **Aktivní**.

Sloupec **Cesta** v tabulce ukazuje interní materializovanou cestu stromem
(`/12/45/`) — slouží k rychlému dohledání podstromu, uživatelsky důležitý je
hlavně vizuální odsazený název.

### 34.3.1 Přesun kategorie

Tlačítko se šipkami u řádku otevře dialog **„Přesunout kategorii"**, kde
vybereš novou nadřazenou kategorii (nebo necháš prázdné pro přesun na
nejvyšší úroveň). Nabídka **vylučuje samotnou kategorii a celý její
podstrom** — nelze tedy kategorii zacyklit (udělat z ní vlastního potomka).
Přesun rovnou přepočítá cestu/hloubku pro celý přesouvaný podstrom.

Kategorie s podřízeným zbožím nebo podkategoriemi nelze smazat — systém
nabídne archivaci místo mazání (viz [§ 34.11](#3411-mazani-vs-archivace)).

Konkrétní kartě zboží přiřadíš jednu i více kategorií na záložce „Kategorie &
štítky" editoru skladové karty, kde navíc označíš jednu jako **hlavní**
(pro drobečkovou navigaci a kanonickou URL na e-shopu).

## 34.4 Atributy (parametry)

Atributy jsou **typované parametry zboží** — na rozdíl od volného textu mají
přesně daný datový typ, takže je lze na e-shopu i filtrovat. Formulář nového
atributu obsahuje:

- **Kód** a **Název** (např. „barva", „Barva"),
- **Datový typ**: `Text`, `Number`, `Boolean`, nebo `Enum (Volby)` — u posledního
  se atribut vybírá z předem definovaného seznamu hodnot,
- **Měrná jednotka** (nepovinná, např. „kg", „cm", „ks" — smysl dává hlavně u
  `Number`),
- **Pořadí** zobrazení,
- **Filtrovatelný** — příznak pro budoucí facetové filtrování na e-shopu,
- **Vícehodnotový** — povolí u karty zboží přiřadit atributu víc hodnot najednou
  (typicky u `Enum`, např. „dostupné velikosti"),
- **Aktivní/Neaktivní**.

### 34.4.1 Volby atributu (jen typ Enum)

V modálu úpravy atributu je pod základním formulářem sekce **„Možnosti/Volby
atributu"** — tabulka voleb (Kód, Popisek, Pořadí) a formulář pro přidání
nové volby. Chování se liší podle toho, zda atribut zakládáš, nebo upravuješ:

- **Při zakládání** nového atributu se volby jen **bufferují lokálně** (ještě
  nemají server ID) a **odešlou se na server až po uložení atributu** —
  postupně, ve frontě; pokud část requestů selže, zbytek fronty přežije i po
  opravě a opětovném uložení.
- **Při editaci** existujícího atributu se volby ukládají/mažou **rovnou** přes
  API (atribut už ID má).

Každá volba má svůj **Kód** (technický, používá se i při párování importu/
API) a **Popisek** (zobrazovaný text, typicky se z něj kód automaticky
odvozuje jako slug — lze ho ale ručně přepsat).

Konkrétní hodnoty atributů (typované vstupy dle datového typu) se zadávají na
záložce „Parametry" editoru skladové karty pro každou kartu zvlášť.

## 34.5 Tagy

Tagy jsou volné barevné štítky zboží (např. „Novinka", „Výprodej", „TOP
prodej") — na rozdíl od kategorií a atributů nejsou hierarchické ani typované,
slouží jen k vizuálnímu odlišení a filtrování na e-shopu. Formulář: **Kód**,
**Název**, **Barva** (textové pole ve formátu `#RRGGBB` + barevný picker vedle
něj pro pohodlný výběr) a **Aktivní**. V tabulce vidíš barevný čtvereček u
každého tagu, aby bylo na první pohled jasné, jak bude vypadat na e-shopu.

Konkrétní kartě zboží přiřadíš libovolný počet tagů na záložce „Kategorie &
štítky" editoru skladové karty.

## 34.6 Poplatky

Poplatky reprezentují dodatečné zákonné příplatky ke zboží — typicky
**autorský poplatek** nebo **recyklační poplatek (PHE)** za elektrozařízení.
Formulář: **Kód** (interní, např. `copyright`, `recycling`), **Název**,
**Sazba DPH** (výběr z číselníku sazeb DPH, nebo „Bez DPH" — poplatek tak může
mít jinou sazbu než samotné zboží) a **Aktivní**.

Konkrétnímu zboží pak přiřadíš konkrétní **částku** poplatku (v dané měně, s
příznakem, zda je částka „s DPH") přímo na kartě zboží. Poplatek s
navázaným zbožím nelze smazat, jen archivovat.

> [!TIP]
> Recyklační poplatek (PHE) je u elektrozařízení a baterií ze zákona povinný
> a musí být na e-shopu **viditelně uveden odděleně od ceny zboží** — proto je
> namodelovaný jako samostatný typ poplatku s vlastním režimem DPH, ne jako
> součást prodejní ceny.

## 34.7 Import zboží

Záložka **„Import zboží"** umožní hromadně **založit nové i aktualizovat
existující** skladové karty ze souboru **XLSX nebo CSV** (max 2 MB).

### 34.7.1 Postup

1. **Přetáhni soubor** do vyznačené plochy, nebo klikni a vyber ho ručně.
2. Zaškrtávátko **„Jen náhled (dry-run) — nic se neuloží"** je při prvním
   nahrání zapnuté — doporučený postup je vždy nejdřív spustit **náhled**.
3. Tlačítko se podle stavu přepínače jmenuje **„Zobrazit náhled"** nebo rovnou
   **„Importovat"**.
4. Po náhledu se zobrazí **report** — souhrn (počet nových / změn / beze
   změny / chyb) a řádková tabulka s detailem každého řádku souboru.
5. Pokud náhled **neobsahuje žádnou chybu**, objeví se tlačítko **„Potvrdit
   import"**, které provede tentýž soubor **naostro** bez nutnosti ho nahrávat
   znovu.
6. Filtr **„Jen problémy"** nad tabulkou zobrazí jen řádky se stavem chyba
   nebo s doprovodnou zprávou.

### 34.7.2 Sloupce souboru

Povinný je jen **`sku`** — slouží jako **identita řádku** (podle něj se pozná,
jde-li o nové zboží, nebo aktualizaci existujícího). Přijímají se české i
anglické varianty názvů sloupců (např. `nazev`/`name`, `vyrobce`/`manufacturer`/
`znacka`/`brand`), pořadí sloupců není podstatné — párují se podle hlavičky.

| Sloupec | Význam |
|---|---|
| `sku` | Katalogové číslo — povinné, max 50 znaků, musí být v souboru jedinečné (i bez ohledu na velikost písmen) |
| `nazev` | Název zboží — povinný jen při **zakládání** nové karty |
| `jednotka` | Měrná jednotka (default `ks`, pokud sloupec chybí) |
| `ean` | Čárový kód |
| `cena` | Prodejní cena bez DPH — přijímá český i anglický formát čísla (`1 234,50` i `1234.50`) |
| `vyrobce` | **Kód existujícího** výrobce — pokud neexistuje, řádek skončí chybou (výrobce se importem nezakládá, založ ho nejdřív v číselníku Výrobci) |
| `skladem` | `1`/`0` (nebo `ano`/`ne`) — je karta skladová položka, nebo se prodává jen přes dodavatele |
| `export_eshop` | `1`/`0` — má se karta zobrazit na e-shopu |
| `hmotnost_g` | Hmotnost v gramech (celé číslo, pro výpočet dopravy) |
| `zaruka_mesice` | Záruka v měsících |
| `dodaci_lhuta_dny` | Dodací lhůta ve dnech |

### 34.7.3 Chování importu

- **Nikdy nemaže** — import zboží ani nesmaže existující kartu, ani z ní
  neodstraní hodnotu, která v souboru chybí (aktualizuje se **jen sloupec,
  který je v souboru skutečně vyplněný**).
- Řádky se stavem **„Beze změny"** se přeskočí (import je idempotentní —
  opakované nahrání téhož souboru nic nezmění).
- Řádek se stavem **„Nový"** založí kartu jako `zboží` (item_type `goods`).
- Řádek se stavem **„Chyba"** (např. chybějící povinné pole, duplicitní SKU v
  souboru, neplatná cena, neexistující kód výrobce, hodnota mimo povolený
  rozsah) se **nezapíše** a v detailu vidíš konkrétní důvod.
- Ostrý import je **all-or-nothing v rámci dávky** — pokud náhled hlásí
  chyby, „Potvrdit import" se nenabídne a musíš soubor nejdřív opravit.

> [!WARNING]
> Sloupec `vyrobce` **nezakládá nové výrobce** — očekává kód už existujícího
> záznamu z číselníku Výrobci ([§ 34.2](#342-vyrobci)). Připrav si tedy
> číselník výrobců dřív, než spustíš import velkého katalogu.

## 34.8 Cenotvorba

**Cesta: `Zboží → Skladové karty → (karta) → záložka „Ceny"`**

Zatímco stránka `/eshop` drží číselníky, samotná **cena zboží** se rodí na kartě
konkrétní položky. Prodejní cena přitom není hodnota, kterou prostě zadáš — je
to **výsledek výpočtu**, který systém přepočítává z nákupní ceny.

> [!IMPORTANT]
> **MyÚčto má dva oddělené cenové subsystémy a nemíchají se.** Jednoduchý
> **Ceník** ([§ 72.1.5](72_Nastaveni.md)) je určený pro fakturaci služeb a umí
> ceny per zákazník. **Sklad + E-shop** má vlastní cenotvorbu odvozenou z
> nákupní ceny, popsanou zde. Po zapnutí modulu Sklad **Ceník z menu zmizí** —
> ceny se mezi nimi nepřenášejí.

### 34.8.1 Jak cena vzniká

Řetěz má pět kroků:

| # | Krok | Kde se nastavuje |
|---|---|---|
| 1 | **Nákupní cena v CZK** (nákladová báze) | pole „Cenová báze" na záložce Obecné + skladové pohyby / dodavatelé |
| 2 | **Přirážka %** nebo **fixní cena** | záložka Ceny, sloupce „Režim" a „Přirážka % / Fixní cena" |
| 3 | **Přepočet do cílové měny** kurzem | automaticky z kurzovního lístku |
| 4 | **Zaokrouhlení** | záložka Ceny, sloupec „Zaokrouhlení" |
| 5 | **Výsledná cena** (bez DPH) | sloupec „Výsledná cena" — jen ke čtení |

```
nákupní cena (CZK)
        │
        ├── režim Přirážka % ──→ × (1 + přirážka/100) ──→ ÷ kurz (cizí měna)
        │                                                        │
        └── režim Fixní cena ────────────────────────────────────┤
                                                                 ▼
                                                         zaokrouhlení
                                                                 │
                                                                 ▼
                                                    výsledná cena bez DPH
```

Celý výpočet běží v **celočíselné haléřové aritmetice** (žádná desetinná čísla
s plovoucí čárkou), takže se v cenách nekumulují zaokrouhlovací chyby.

### 34.8.2 Nákupní cena — cenová báze

Pole **„Cenová báze"** na záložce „Obecné" určuje, **odkud systém vezme nákupní
cenu**, ze které se počítá přirážka:

| Cenová báze | Odkud bere nákupní cenu | Kdy ji použít |
|---|---|---|
| **Vážený průměr** | Průměrná pořizovací cena skladových zásob (`Σ hodnota ÷ Σ množství` **napříč všemi sklady**) | Výchozí volba pro běžné skladové zboží — cena se plynule přizpůsobuje nákupům |
| **Poslední nákup** | Jednotková cena z **poslední zaúčtované příjemky** | Když se nákupní ceny rychle mění a chceš marži počítat z aktuální hladiny, ne z historického průměru |
| **Ruční** | **Nákupní cena preferovaného dodavatele** ze záložky Dodavatelé | Zboží bez skladu (dropshipping) nebo když se řídíš ceníkem dodavatele, ne skutečnými nákupy |

Podrobnosti o tom, jak se vážený průměr počítá při příjmu a výdeji, jsou
v [§ 33.3 Oceňování zásob](33_Sklad.md).

Pokud zvolený zdroj nákupní cenu **nevrátí** (např. „Vážený průměr" u karty bez
jediné příjemky), systém zkusí náhradní zdroje v pořadí:

**zvolená báze → poslední nákup → preferovaný dodavatel**

Teprve když selžou všechny tři, zůstane prodejní cena prázdná (`—`). Nákupní
cena **nula nebo záporná se nepočítá jako platná** — přirážka z nulového nákladu
nedává smysl, takže se pokračuje dalším zdrojem v řetězu.

> [!NOTE]
> Díky záložnímu řetězu funguje karta se „skladovou" bází i **před první
> příjemkou** — nacení se podle dodavatele a jakmile naskladníš, přepne se
> automaticky na skutečná skladová data.

### 34.8.3 Přirážka vs. marže — nepleť si je

Systém pracuje s **přirážkou** (*markup*), ne s marží. Rozdíl je zásadní a je
nejčastějším zdrojem zklamání z výsledné ziskovosti:

- **Přirážka %** = kolik procent **nákupní ceny** přidáváš.
  `přirážka = (prodej − nákup) ÷ nákup × 100`
- **Marže %** = jaký podíl **prodejní ceny** ti zůstane.
  `marže = (prodej − nákup) ÷ prodej × 100`

Přirážka 30 % tedy **neznamená** marži 30 %. Nákup 1 000 Kč + 30 % přirážky =
prodej 1 300 Kč, hrubý zisk 300 Kč, ale marže je `300 ÷ 1 300 = 23,1 %`.

| Přirážka % (zadáváš) | Marže % (dostaneš) | | Chceš marži % | Zadej přirážku % |
|---:|---:|---|---:|---:|
| 10 | 9,1 | | 10 | 11,11 |
| 20 | 16,7 | | 15 | 17,65 |
| 25 | 20,0 | | 20 | 25,00 |
| 30 | 23,1 | | 25 | 33,33 |
| 40 | 28,6 | | 30 | 42,86 |
| 50 | 33,3 | | 40 | 66,67 |
| 100 | 50,0 | | 50 | 100,00 |

Vzorce pro přepočet:

- `marže = přirážka ÷ (100 + přirážka) × 100`
- `přirážka = marže ÷ (100 − marže) × 100`

> [!TIP]
> Když ti dodavatel nebo konkurence mluví o „rabatu", myslí zpravidla **slevu z
> doporučené ceny**, tedy ještě třetí veličinu. Než si nastavíš přirážky napříč
> katalogem, ujasni si, které z těch tří čísel vlastně máš — u velkých katalogů
> je omyl v tomhle bodě dražší než cokoli jiného.

> [!WARNING]
> **Marži systém nikde nepočítá ani nereportuje** — ukládá se jen zadaná
> přirážka. Pokud chceš hlídat skutečnou ziskovost, musíš si ji spočítat sám z
> nákupní a prodejní ceny (viz [§ 34.10.4](#34104-kontrola-marze)).

### 34.8.4 Záložka „Ceny"

Karta může mít **libovolný počet cenových řádků — jeden na měnu**. Tlačítkem
**„Přidat měnu"** přidáš řádek, ikonou koše ho odebereš.

| Sloupec | Význam |
|---|---|
| **Měna** | Kód měny podle ISO 4217 (3 písmena, např. `CZK`, `EUR`) — jedinečný v rámci karty |
| **Režim** | **Přirážka %** (dopočet z nákladové báze) nebo **Fixní cena** (pevná částka) |
| **Přirážka % / Fixní cena** | Hodnota podle zvoleného režimu — pole se přepíná automaticky |
| **Zaokrouhlení** | Bez / na haléře / desetihaléře / půlkoruny / koruny / na 9 na konci ([§ 34.8.5](#3485-zaokrouhleni)) |
| **Ruční** | Zafixuje cenu — přepočet ji nepřepíše |
| **Výsledná cena** | Dopočtená cena **bez DPH** — jen ke čtení |
| **Kurz** | Kurz použitý při posledním přepočtu (u CZK prázdný) |

Tlačítko **„Přepočítat"** nejdřív uloží aktuální nastavení řádků a **teprve pak
spustí výpočet** — nemusíš tedy ukládat zvlášť.

> [!IMPORTANT]
> **Řádek v CZK má zvláštní postavení: zrcadlí se do prodejní ceny skladové
> karty**, a tím i do výchozí ceny na řádku faktury. Ostatní měny slouží jen
> e-shopové prezentaci a do fakturace nevstupují. Když kartě CZK řádek
> **nezaložíš**, prodejní cena karty zůstane na té hodnotě, kterou jsi zadal
> ručně (nebo naimportoval) — cenotvorba ji nebude aktualizovat.

**Ruční přepis** vyřadí řádek z automatického dopočtu a chová se dvěma způsoby
podle toho, co je v hodnotovém poli:

- **„Ruční" + režim Fixní cena + zadaná částka** → cena je přesně tato částka
  (jen se zaokrouhlí dle nastavení). Tohle je způsob, jak zboží nacenit napevno.
- **„Ruční" + režim Přirážka %** → cena **zamrzne na poslední dopočtené
  hodnotě** a přestane reagovat na změny nákupní ceny i kurzu.

Odškrtnutím „Ruční" se řádek vrátí do automatického režimu a nejbližší přepočet
cenu přepíše.

Sloupec „Výsledná cena" zůstane prázdný (`—`) ve dvou situacích:

| Příčina | Co s tím |
|---|---|
| **Chybí nákupní cena** — žádný zdroj v záložním řetězu nic nevrátil | Naskladni příjemkou, nebo doplň preferovaného dodavatele s nákupní cenou |
| **Chybí kurz** — v kurzovním lístku není kurz dané měny | Doplň kurz ([§ 34.8.6](#3486-cizi-meny-a-kurzy)) a spusť přepočet |

### 34.8.5 Zaokrouhlení

Zaokrouhluje se **až úplně nakonec**, na výslednou cenu bez DPH, matematicky
(půlka nahoru). Pro dopočtenou cenu **1 035,7335 Kč** dopadnou režimy takto:

| Režim | Výsledek | Poznámka |
|---|---:|---|
| **Bez** | 1 035,73 | Jen normalizace na haléře |
| **Na haléře (0,01)** | 1 035,73 | Totožné s „Bez" |
| **Na desetihaléře (0,10)** | 1 035,70 | |
| **Na půlkoruny (0,50)** | 1 035,50 | |
| **Na koruny (1)** | 1 036,00 | Nejčastější volba pro běžný retail |
| **Na 9 na konci** | 1 039,00 | Psychologická cena — nejbližší celá koruna končící devítkou |

Režim **„Na 9 na konci"** zaokrouhlí nejdřív na celé koruny a pak vybere
nejbližší číslo končící devítkou; při stejné vzdálenosti volí **nahoru**.
Nejnižší cena, kterou tenhle režim vytvoří, je 9 Kč.

> [!TIP]
> Zaokrouhlení nastavuj **per měnu**. „Na 9 na konci" dává skvělý smysl u
> korunových cen, ale u eurových řádků často vyrobí zbytečně hrubý skok —
> tam bývá vhodnější „Na haléře" nebo „Na desetihaléře".

### 34.8.6 Cizí měny a kurzy

U řádku v cizí měně systém převede **nákupní cenu v CZK** kurzem do cílové měny
a **až pak** aplikuje přirážku a zaokrouhlení.

**Příklad:** nákupní cena 812,34 Kč, přirážka 27,5 %, kurz EUR 25,30 Kč:

```
812,34 ÷ 25,30 = 32,108300 EUR   (nákup v EUR)
32,108300 × 1,275 = 40,938082    (+ přirážka 27,5 %)
zaokrouhlení „Na haléře"    →    40,94 EUR
```

Marže tedy zůstává stejná jako v CZK, ale **eurová cena plave s kurzem** — při
posílení koruny sama klesá.

> [!IMPORTANT]
> **Kurz se bere výhradně z kurzovního lístku v databázi**, nikdy se nestahuje
> živě z ČNB — přepočet ceny nesmí záviset na dostupnosti cizí služby. Použije
> se **nejbližší kurz s datem ≤ dnešek**. Když pro danou měnu není v lístku
> žádný kurz, cena se **nedopočte vůbec** (zůstane prázdná) — cena se nikdy
> nespočítá z odhadnutého nebo nulového kurzu.

### 34.8.7 Kdy se cena přepočítá

Přepočet **není v databázi ani na časovači** — spouští ho aplikace v těchto
okamžicích:

| Událost | Přepočet |
|---|:---:|
| Uložení cenových řádků (záložka Ceny) | ✅ automaticky |
| Kliknutí na **„Přepočítat"** | ✅ vynuceně |
| Uložení dodavatelů (záložka Dodavatelé) | ✅ automaticky |
| Uložení karty zboží | ✅ automaticky |
| **Zaúčtování příjemky** (změní vážený průměr) | ❌ **ne** |
| **Import nových kurzů** | ❌ **ne** |
| Hromadné přecenění katalogu | ❌ nedostupné |

> [!WARNING]
> **Tohle je nejdůležitější provozní úskalí celé cenotvorby.** Zaúčtování
> příjemky změní váženou průměrnou nákupní cenu, ale **prodejní ceny odvozené
> přirážkou se samy nepřepočtou** — zůstanou na hodnotě z posledního přepočtu.
> Totéž platí po aktualizaci kurzů u cen v cizí měně. Po naskladnění za jinou
> nákupní cenu (a po výraznějším pohybu kurzu) je potřeba **projít dotčené
> karty a kliknout na „Přepočítat"**. Hromadné přecenění zatím v aplikaci není,
> takže se to dělá kartu po kartě.

### 34.8.8 Co cena obsahuje — DPH a poplatky

- Všechny ceny v cenotvorbě jsou **bez DPH**. Sazbu DPH má karta zvlášť
  (pole na záložce Obecné) a připočítává se až na dokladu.
- **Poplatky** (recyklační/PHE, autorský — [§ 34.6](#346-poplatky))
  **nejsou součástí prodejní ceny**. Vedou se jako samostatné částky s vlastní
  sazbou DPH, protože zákon vyžaduje jejich oddělené uvedení. Do přirážky ani
  do zaokrouhlení nevstupují.
- **Sleva** existuje jen **na úrovni dokladu** (procentní sleva na faktuře),
  ne na kartě zboží.

## 34.9 Dodavatelé zboží

**Cesta: `Zboží → Skladové karty → (karta) → záložka „Dodavatelé"`**

Ke kartě zboží můžeš přiřadit **libovolný počet dodavatelů** — každého s
vlastními podmínkami. Slouží ke dvěma věcem: jako **podklad pro nákup** a jako
**zdroj nákupní ceny** pro cenovou bázi „Ruční".

| Pole | Význam |
|---|---|
| **Dodavatel** | Výběr z klientů, kteří mají v adresáři zapnutou **roli dodavatele** ([§ 18](18_Klienti.md)) |
| **Kód u dodavatele** | Katalogové číslo, pod kterým položku vede dodavatel (max 64 znaků) — pro objednávky a párování ceníků |
| **Nákupní cena** | Cena, za kterou od něj nakupuješ |
| **Měna** | Měna nákupní ceny (default `CZK`) |
| **Dodání (dny)** | Dodací lhůta — u zboží bez skladu tvoří dostupnost na e-shopu |
| **Skladem (ks)** | Množství, které dodavatel drží — orientační, needituje tvůj sklad |
| **Preferovaný** | Přepínač; **nejvýš jeden dodavatel na kartu** |
| **Poznámka** | Volný text (minimální odběr, sezónnost, kontakt…) |

### 34.9.1 Preferovaný dodavatel

Preferovaný dodavatel je ten, jehož **nákupní cena se použije při cenové bázi
„Ruční"** a jako poslední článek záložního řetězu ([§ 34.8.2](#3482-nakupni-cena-cenova-baze)).
Je-li jeho cena v cizí měně, převede se do CZK kurzem k danému dni — a
**chybí-li kurz, nákupní cena z něj nepůjde získat**.

> [!NOTE]
> Aby se klient v nabídce dodavatelů vůbec objevil, musí mít v adresáři zapnutý
> **příznak dodavatele**. Pokud je seznam prázdný, nejdřív roli zapni u
> příslušných klientů — na kartě zboží se dodavatel založit nedá.

### 34.9.2 Dropshipping — zboží bez skladu

Vypneš-li na kartě příznak **„Skladová položka"**, karta žije **jen z
dodavatelů**: skladové množství se nesleduje, dostupnost a dodací lhůta se berou
od dodavatele a nákupní cena pro cenotvorbu přijde z preferovaného dodavatele.
Kombinace **„Skladová položka" vypnutá + cenová báze „Ruční" + preferovaný
dodavatel s cenou** je doporučené nastavení pro dropshipping.

## 34.10 Praktické postupy cenotvorby

### 34.10.1 Nacenění nového skladového zboží

1. Založ kartu, nech **„Skladová položka"** zapnutou.
2. Cenová báze → **Vážený průměr**.
3. Naskladni příjemkou (tím vznikne nákupní cena).
4. Záložka **Ceny** → „Přidat měnu" → `CZK`, režim **Přirážka %**, hodnota podle
   cílové marže (viz převodní tabulka v [§ 34.8.3](#3483-prirazka-vs-marze-neplet-si-je)),
   zaokrouhlení **Na koruny** nebo **Na 9 na konci**.
5. **„Přepočítat"** a zkontroluj sloupec „Výsledná cena".

### 34.10.2 Nacenění dropshippingového zboží

1. Vypni **„Skladová položka"**, cenová báze → **Ruční**.
2. Záložka **Dodavatelé** → přidej dodavatele s **nákupní cenou, měnou, kódem a
   dodací lhůtou**, označ ho jako **Preferovaného**.
3. Záložka **Ceny** → `CZK` s přirážkou → **„Přepočítat"**.

### 34.10.3 Změna dodavatele nebo jeho ceníku

Nákupní ceny dodavatelů se zadávají **ručně** — automatický import ceníku
dodavatele v aplikaci není. Po přecenění od dodavatele tedy:

1. Uprav nákupní cenu na záložce **Dodavatelé** (u karet s bází „Ruční" se cena
   přepočte hned po uložení).
2. Přesouváš-li nákup k jinému dodavateli, přepni u něj přepínač
   **Preferovaný** — starého nech v seznamu jako záložní zdroj.

### 34.10.4 Kontrola marže

Systém marži nepočítá, ale máš k dispozici obě čísla:

- **nákupní cenu** — ve skladových sestavách (ocenění zásob, [§ 32](33_Sklad.md))
  nebo na záložce Dodavatelé,
- **prodejní cenu** — ve sloupci „Výsledná cena".

Marži pak spočítáš jako `(prodej − nákup) ÷ prodej × 100`. Pro pravidelnou
kontrolu se vyplatí vyexportovat skladové karty a dopočítat ji v tabulkovém
procesoru.

### 34.10.5 Akce a výprodej

Časově omezené akční ceny aplikace neumí. Prakticky se řeší takto:

1. Na akčním řádku přepni režim na **Fixní cena**, zaškrtni **„Ruční"** a zadej
   akční částku.
2. Po skončení akce **odškrtni „Ruční"**, vrať režim na **Přirážka %** a klikni
   na **„Přepočítat"** — cena se vrátí na standardní hladinu.
3. Zboží v akci si označ **tagem** (např. „Výprodej", [§ 34.5](#345-tagy)),
   ať víš, které karty po skončení akce vrátit zpět.

> [!WARNING]
> Krok 2 je **čistě na tobě** — nic ho nepřipomene a akční cena by jinak platila
> donekonečna. Než akci spustíš, poznač si datum jejího konce.

## 34.11 Mazání vs. archivace

U všech číselníků (výrobci, kategorie, atributy, tagy, poplatky) platí stejné
pravidlo: pokud je záznam **použitý u nějakého zboží**, smazání ho odmítne a
zobrazí hlášku, že je „v použití" — řešením je záznam **archivovat**
(zaškrtávátko „Aktivní" ve formuláři vypnout) místo mazání. Archivované
záznamy zůstávají v tabulce (zešednou), ale nenabízí se při zakládání nového
zboží ani v exportu na e-shop.

## 34.12 Omezení a tipy

### 34.12.1 Číselníky a import

- Modul E-shop je **dostupný jen se zapnutým Skladem** — bez něj se položka v
  menu vůbec nezobrazí (nastavuje se v [§ 53. Nastavení](72_Nastaveni.md)).
- Kódy (výrobce, kategorie, atribut, tag, poplatek) jsou jedinečné **v rámci
  firmy** — při kolizi vrátí formulář chybu „…s tímto kódem už existuje".
- Kategorie nelze přesunout do vlastního podstromu (ochrana proti zacyklení
  stromu).
- Atribut typu `Enum` bez alespoň jedné volby nemá u karty zboží co nabídnout
  k výběru — volby zakládej rovnou při vytváření atributu.
- Import zboží zvládne jen **XLSX/CSV do 2 MB** a nikdy nezakládá výrobce —
  connect-the-dots pořadí je: nejdřív číselníky (výrobci), pak import.
- Readonly uživatelé vidí všechny záložky i importní report, ale nemají
  tlačítka pro zápis (nový/upravit/smazat/import naostro).

> [!TIP]
> Před prvním importem velkého katalogu spusť náhled (dry-run), projdi
> sloupec „Jen problémy" a chyby oprav přímo ve zdrojovém souboru — teprve
> pak spouštěj ostrý import. Ušetříš si tak opakované ruční opravy karet po
> částečně nepovedeném importu.

### 34.12.2 Cenotvorba

Ať si nastavíš očekávání správně — tohle cenotvorba v MyÚčto **neumí**:

| Chybějící funkce | Náhradní řešení |
|---|---|
| **Cenové hladiny / skupiny zákazníků** | Ceny per zákazník existují jen v jednoduchém **Ceníku** pro fakturaci ([§ 72.1.5](72_Nastaveni.md)), který se se skladem nekombinuje |
| **Časově omezené akční ceny** | Ruční přepis + tag, ručně vrátit ([§ 34.10.5](#34105-akce-a-vyprodej)) |
| **Množstevní slevy** (od X ks levněji) | Samostatná karta pro balení, nebo sleva na dokladu |
| **Sleva na produkt** | Jen procentní sleva na úrovni faktury |
| **Historie cen** | Není — uchovává se jen aktuální hodnota a datum posledního přepočtu |
| **Hromadné přecenění** | Kartu po kartě přes „Přepočítat" |
| **Import ceníku dodavatele** | Nákupní ceny se zadávají ručně na záložce Dodavatelé |
| **Automatický přepočet po příjemce / po importu kurzů** | Ruční „Přepočítat" ([§ 34.8.7](#3487-kdy-se-cena-prepocita)) |
| **Výpočet a reporting marže** | Ručně z nákupní a prodejní ceny |
| **XML feed pro Heureku / Zboží.cz** | Příznak „Exportovat do e-shopu" je zatím jen označení pro externí systém |
