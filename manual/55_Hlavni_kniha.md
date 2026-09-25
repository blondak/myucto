# 55. Hlavní kniha

**Cesta: `Účetnictví → Hlavní kniha`**

Hlavní kniha je úplný přehled zaúčtovaných pohybů seskupený podle účtů.
Zahrnuje rozvahové, výsledkové, uzávěrkové i podrozvahové účty včetně účtové
třídy 7. Je dostupná jen firmám s podvojným účetnictvím a navazuje na
[Účtový rozvrh](66_Ucetni_osnova.md).

## 55.1 Co je zdrojem sestavy

Sestava čte řádky účetního deníku, které mají vyplněný okamžik zaúčtování.
Koncepty do částek nevstupují; jejich počet v nastaveném rozsahu se zobrazí
ve varování nad tabulkou. Storna se neodstraňují přepsáním historie, ale
vyruší původní částku opačným zaúčtovaným zápisem.

Účetní zápisy mohou vzniknout z vydaných a přijatých dokladů, banky, pokladny,
majetku, mezd, uzávěrky nebo ručního zápisu. Hlavní kniha mezi těmito zdroji
částek nerozlišuje: rozhodující je účet, strana MD/Dal, částka a datum řádku.

## 55.2 Období, rozsah a filtry

- **Období** určuje fiskální rok. Předvyplní se nejnovější otevřené období,
  jinak první dostupné. Při změně období se **Od / Do** nastaví na jeho hranice;
  ostatní filtry i rozbalený účet zůstanou zachované. Volba **Vše** spojí všechna
  účetní období firmy do jednoho víceletého rozsahu. Ve výchozím pohledu přitom
  vynechá technické uzavírací a navazující otevírací zápisy, aby nepřifukovaly
  obraty mezi roky.
- **Od / Do** omezí sestavu uvnitř zvoleného období. Prázdné hodnoty znamenají
  první a poslední den období. Datum mimo období nebo `Od > Do` server odmítne.
- **Rozpad po analytikách** vypíše jednotlivé analytické účty. Bez něj se
  analytiky sečtou pod syntetický účet.
- **Po uzavření knih** započte i vlastní závěrkový převod období. Výchozí
  pohled jej vynechává, aby zůstatky k poslednímu dni uzavřeného roku nebyly
  závěrkovým zápisem vynulované. Otevírací zápis a skladové uzávěrkové pohyby
  zůstávají součástí účetních hodnot.
- **Dodavatel**, **Odběratel** a **Text položky** filtrují zápisy podle
  protistrany nebo položky zdrojové přijaté či vydané faktury. Filtr se
  uplatní na počáteční stav, obraty i měsíční rozpad vybraného výřezu.

Aktuální kombinaci období, rozsahu, rozpadu analytik a vyhledávacích filtrů
lze uložit jako vlastní sestavu a případně ji nastavit jako výchozí. Volba
**Po uzavření knih** se do uložené kombinace nepřenáší. Ovládání **Sloupce**
skrývá či zobrazuje sloupce tabulky a **Hustota** mění řádkování; obě volby
mění jen zobrazení.

Kliknutím na záhlaví sloupce lze řadit účty podle kódu, názvu i částek.
Při rozpadu po analytikách zůstávají analytické účty pod svou syntetikou
a řadí se uvnitř její skupiny.

## 55.3 Výpočet PS, obratů a KS

Každý řádek účtu obsahuje:

| Hodnota | Výpočet |
|---|---|
| **PS MD / PS Dal** | Netto zůstatek před datem `Od`, zobrazený jen na straně výsledného salda |
| **Obrat MD / Obrat Dal** | Hrubé součty stran za dny `Od` až `Do` včetně |
| **KS MD / KS Dal** | `PS MD − PS Dal + obrat MD − obrat Dal`, opět jen na straně výsledného salda |

Otevírací zápis datovaný prvním dnem období patří do PS, nikoli do obratu.
U rozvahových účtů se počáteční stav nese z historie; existuje-li otevírací
kotva po uzávěrce, počítá se od ní, aby se historie a otevření nezapočetly
dvakrát. U nákladových a výnosových účtů začíná okno PS nejdříve prvním dnem
daného fiskálního období.

Peníze se pro součty převádějí na haléře. Účet bez PS i bez obratu se
nezobrazuje. Řádek **Součty** sčítá všechny zobrazené účty.

## 55.4 Měsíční rozpad a řádky měsíce

Šipka na začátku řádku rozbalí obrat MD a Dal po kalendářních měsících
zasahujících do nastaveného rozsahu. Jde o rozpad obratu, nikoli zůstatku:
měsíc bez pohybu proto ukazuje nuly. Měsíční částky se seskupují stejně jako
hlavní řádek — podle syntetiky, nebo po analytikách. Součet měsíců se rovná
obratu za celý rozsah; otevírací zápis prvního dne období je i tady součástí
počátečního stavu, ne lednového obratu.

Kliknutí na měsíc s pohybem rozbalí **jednotlivé řádky deníku** za ten měsíc:
datum, doklad, analytiku, popis a částku na správné straně. Doklad vede na
prvotní doklad (viz [48.5](#555-opis-uctu)). Zobrazí se prvních 200 řádků;
u delších měsíců odkaz v patičce pokračuje v opisu účtu za stejný měsíc.

Kliknutí na **kód účtu** otevře [kartu účtu](66_Ucetni_osnova.md#667-karta-uctu)
se zachovaným rozsahem `Od / Do`. Z karty vede odkaz zpět do hlavní knihy;
kniha pak dotčený účet sama rozbalí a odroluje k němu.

## 55.5 Opis účtu

Opis účtu otevřeš z karty účtu nebo z rozbaleného měsíce; rozsah `Od / Do`
se přenáší. Opis syntetického účtu zahrnuje i jeho analytiky a nahoře ukazuje
PS, obrat MD, obrat Dal a KS. Je-li opis složený z víc analytik, přibude
sloupec **Analytika** s odkazem na kartu příslušného účtu.

Pohyby jsou řazené podle data, ID zápisu a čísla řádku. Sloupec **Zůstatek**
je běžící saldo `PS + MD − Dal` počítané nad celým rozsahem, takže navazuje
správně i na dalších stránkách. Výchozí stránka má 50 řádků. Kladný zůstatek
znamená MD, záporný Dal.

Doklad v opisu vede podle zdroje na vydanou fakturu, přijatou fakturu, bankovní
výpis, pokladní doklad, kartu majetku nebo na vyrovnaný doklad zápočtu; bez
rozpoznaného zdroje na konkrétní zápis účetního deníku. PDF/XLSX opisu obsahuje
celý zvolený rozsah, nejen aktuální stránku.

Každý řádek dál ukazuje:

- **Partner** a **VS** ze zdrojového dokladu (faktura, bankovní pohyb, pokladní
  doklad, u zápočtu hrazený doklad),
- **Protiúčet**, tedy účty opačné strany téhož zápisu,
- **Okruh**, pokud je řádek spárovaný (viz níže),
- **Měnu**; u cizoměnového řádku i částku v cizí měně.

XLSX opisu nese partnera, VS, protiúčet a okruh také.

### Otevřené položky a párování

Záložka **Otevřené položky - párování** ukazuje, z čeho se skládá zůstatek
účtu k datu. Funguje na kterémkoli účtu, typicky na 261 Peníze na cestě
(převody mezi účty), 395 Vnitřní zúčtování, zálohách nebo půjčkách.

Řádky, které se navzájem vyrovnávají, spojíš do **okruhu**: zaškrtni je
a klikni na **Spárovat**. Lišta nad tabulkou průběžně ukazuje rozdíl MD a Dal
vybraných řádků. Vyrovnaný okruh je uzavřený a jeho řádky z pohledu
**Jen otevřené** zmizí. Nevyrovnaný okruh je povolený: rozdíl zůstane otevřený
na straně, která převažuje, a to na nejnovějším řádku.

Sloupce **Otevřeno MD**, **Otevřeno Dal** a **Otevřený zůstatek** počítají jen
otevřené části řádků. Součet otevřených položek se vždy rovná zůstatku účtu;
karta **Zůstatek účtu** nahoře to kontroluje a případný rozdíl zvýrazní.

- Okruh drží řádky jednoho účtu, u syntetiky vždy jedné analytiky. Řádek
  smí být jen v jednom okruhu.
- Počáteční stav z otevření knih je v seznamu jako běžný řádek, takže se
  dá spárovat s pozdějším vyrovnáním.
- Datum **K datu** počítá jen řádky do toho dne. Okruh, jehož protějšek přišel
  později, je k tomu dni otevřený.
- Klik na číslo okruhu otevře dole jeho položky. Odtud jde řádek z okruhu
  odebrat, přidat do okruhu další zaškrtnuté řádky nebo celý okruh zrušit.
  Odebráním předposledního řádku okruh zanikne.
- **Návrhy párování** najdou storno s původním zápisem a dvojice stejné
  částky na opačných stranách téhož účtu v zadaném okně dní. **Spárovat
  návrhy** z nich založí okruhy jedním klikem.

Párování nemění účetní deník, obraty ani zůstatky. Proto jde měnit i v
uzavřeném nebo zamčeném období. Když se doklad přeúčtuje a jeho řádek zůstane
na stejném účtu, okruh zůstane beze změny. Když řádek přejde na jiný účet nebo
se zápis stornuje nebo smaže, řádek z okruhu vypadne a zbytek okruhu je znovu
otevřený. Okruh, ve kterém zůstane jediný řádek, zanikne a řádek jde spárovat
znovu. Tyto změny se zapisují do historie aktivit.

## 55.6 Export a návaznosti

**Export PDF** a **Export XLSX** používají stejné období, rozsah, rozpad
analytik, pohled před/po uzavření a filtry jako obrazovka. Název souboru
obsahuje fiskální rok. Stejná logika PS/obrat/KS se používá také v
[Obratové předvaze](56_Obratova_predvaha.md); výkazové mapování a zákonné
řádky popisuje [Rozvaha](57_Rozvaha.md).

> ⚠️ **Koncepty nejsou součástí částek.** Žluté upozornění znamená, že se po
> jejich zaúčtování hlavní kniha ještě změní.
