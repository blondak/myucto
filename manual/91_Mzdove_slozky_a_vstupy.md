# 91. Mzdové složky a vstupy

## 91.1 Účel

Mzdové složky definují význam pravidelných a jednorázových plnění, náhrad a korekcí. Vstupy přiřazují konkrétní hodnotu zaměstnanci, vztahu a období.

## 91.2 Předpoklady a oprávnění

Musí existovat aktivní vztah a správné období. Uživatel potřebuje mzdové oprávnění a podklad pro částku, jednotky, účinnost a případné daňové či pojistné zacházení.

## 91.3 Krokový postup

1. Otevřete **Mzdy → Mzdové složky a vstupy** a ověřte význam dostupných složek.
2. Pravidelnou složku nastavte s datem účinnosti u vztahu; jednorázovou vložte do konkrétního měsíce.
3. Vyplňte částku nebo jednotky v očekávaném formátu a uložte.
4. Zkontrolujte návaznost benefitů, cestovních náhrad, absencí a srážek v jejich vlastních agendách.
5. Před výpočtem porovnejte soupis vstupů s podklady a odstraňte duplicity.

## 91.4 Stavy

Budoucí pravidelná složka čeká na účinnost, aktivní se použije pro rozhodné období a ukončená zůstává v historii. Jednorázový vstup čeká na běh. Po uzavření období jej nepřepisujte bez řízené opravy.

## 91.5 Kontroly a bezpečnost

Kontrolujte znaménko, jednotku, období, vztah a klasifikaci plnění. Obecnou složku nepoužívejte k obcházení nepodporovaného právního režimu. Odkaz na podklad je volitelný důkaz; hodnota, období a druh plnění jsou skutečné vstupy.

## 91.6 Časté chyby

- Jednorázová složka zadaná jako pravidelná.
- Vstup přiřazený jinému souběžnému vztahu.
- Duplicitní import a ruční zadání stejné částky.
- Změna vstupu bez nového výpočtu otevřeného běhu.

## 91.7 Návaznosti

Hromadné zadání nabízí [rychlý měsíční vstup](79_Rychly_mesicni_vstup.md), speciální plnění [koše benefitů](89_Kose_benefitu.md) a vše zpracuje [mzdový běh](80_Mzdove_behy.md).



## 91.8 Podrobný pracovní postup a kontroly

V **Mzdy → Mzdové složky a vstupy** jsou běžnými záložkami oddělené:

- katalog mzdových složek;
- pravidelné předpisy;
- jednorázové měsíční vstupy;
- CSV/XLSX import s povinným náhledem před uložením.

Výchozí složky používají české kódy bez diakritiky, aby byly bezpečné i pro
CSV a jiné strojové zpracování. Patří mezi ně například `MZDA_MESICNI`,
`MZDA_HODINOVA`, `ODMENA`, `NAHRADA_MZDY`, `NEPENEZNI_PRIJEM`,
`PRISPEVEK_STRAVOVANI` a `CESTOVNI_NAHRADA`. Stejné kódy používej také ve
sloupci `component_code` importovaného souboru.

Náhrady vázané na schválenou absenci — `NAHRADA_MZDY_DOVOLENA` podle § 222
a `NAHRADA_MZDY_DPN` podle § 192 — se **ručně ani importem zadat nedají**.
Vznikají při schválení [absence](76_Absence_a_dovolena.md) z jejích hodin
a ze zmrazeného průměrného výdělku; ruční částka by se rozešla s evidencí
nároku. U nemoci je to navíc daňová otázka: osvobozena je podle § 6 odst. 9
písm. p) zákona o daních z příjmů jen náhrada do výše minimálního zákonného
nároku, takže sjednanou vyšší náhradu podle § 192 odst. 3 zadej jako běžnou
zdanitelnou složku.

Zákonné příplatky podle § 114 až § 118 mají vlastní složky
`PRIPLATEK_PRESCAS`, `PRIPLATEK_SVATEK`, `PRIPLATEK_NOCNI`,
`PRIPLATEK_VIKEND` a `PRIPLATEK_ZTIZENE_PROSTREDI`. **Nezadávají se ručně ani
importem** — vznikají samy při schválení měsíce
[docházky](77_Dochazka_a_smeny.md#7793-zakonne-priplatky-ke-mzde-114-az-118)
z evidovaných hodin a jejich příznaků, aby šel nárok doložit z mzdového listu
(§ 142 odst. 5 zákoníku práce). Výjimkou je přesčas zadaný hodinami v
[rychlém měsíčním vstupu](79_Rychly_mesicni_vstup.md), který příplatkovou
složku založí také. Sazbu berou z legislativní sady, případně ze sjednané
zásady pracovního vztahu; ručně se u nich sazba nepřepisuje. U nové vlastní složky zadej
nejprve název; kód se z něj automaticky vytvoří bez diakritiky. Dokud jej ručně
neupravíš, sleduje změny názvu. Po uložení už kód ani začátek platnosti změnit
nelze; další účinnost se zakládá jako nová verze.

Jednorázový měsíční vstup vzniká jako **koncept** — teprve tak jde ještě
upravit i zrušit. Schválení je to, co vstup zmrazí. Upravit jde i koncept
z importu, například z docházky: zaměstnanec, vztah, složka i externí
identifikátor zůstávají podle zdroje, měnit lze částku a množství. Vstupy
z pravidelného předpisu, docházky, absence nebo pracovní cesty se opravují
u svého zdroje.

Po importu docházky má měsíc často stovky vstupů. Lišta nad seznamem je
filtruje podle jména nebo osobního čísla, zaměstnance, složky, stavu, zdroje
a importní dávky. Filtr se drží v adrese stránky, takže přežije obnovení a jde
poslat odkazem. Volba **Zobrazení** seskupí vstupy podle zaměstnance nebo podle
složky i se součty; skupina se rozbalí kliknutím a **Otevřít v seznamu** z ní
udělá filtr.

Souhrnný pruh ukazuje počet vstupů, součet částek a počet konceptů za **celý
filtr**, ne za zobrazenou stránku. Tlačítko **Schválit N odpovídajících
filtru** schválí všechny koncepty ve filtru bez ohledu na jejich počet a už
schválený vstup jen přeskočí. Každý vstup přitom prochází stejnými kontrolami
jako při schválení po jednom (roční limit benefitu, podklad docházky
u stravného, složka k ručnímu posouzení). Co schválit nešlo, zůstane pod
pruhem seskupené podle důvodu. Stejně funguje **Zrušit N konceptů**, které se
vždy zeptá na potvrzení. Zaškrtnutím řádků schválíte nebo zrušíte jen vybrané
vstupy.

Tlačítka **Excel** a **PDF** v souhrnném pruhu stáhnou všechny vstupy
odpovídající filtru, ne jen zobrazenou stránku. Sešit Excel má na listu
**Vstupy** jeden řádek na vstup (osobní číslo, jméno, vztah, složka, množství,
sazba, částka, stav, zdroj a importní dávka) a pod tabulkou součet; na listu
**Info** je firma, období, použitý filtr a čas exportu. Sazba je dopočtená jako
částka lomená množstvím. Poslední dva sloupce jsou skryté a identifikují vstup,
proto je list zamčený bez hesla: filtrovat v něm jde, pro řazení ho odemkněte
(Revize, Odemknout list). PDF je tisková sestava na šířku seskupená podle
zaměstnanců s mezisoučty a na konci s rekapitulací podle složek. Tisková
sestava pojme nejvýše 5 000 vstupů a Excel 20 000; při větším výběru aplikace
požádá o zúžení filtru.

Blokace neschválených vstupů v mzdovém běhu odkazuje rovnou na koncepty
daného měsíce a u ní je i tlačítko pro jejich hromadné schválení. Hromadné
zadání přes [rychlý měsíční vstup](79_Rychly_mesicni_vstup.md) uloží řádky
rovnou jako schválené, má-li k tomu uživatel oprávnění.

Každá složka samostatně určuje dopad do daně, sociálního a zdravotního
pojištění, průměrného výdělku, exekučního základu, JMHZ, statistiky a
účetnictví. Schválený vstup si uloží neměnný snapshot této klasifikace; pozdější
změna katalogu proto nepřepíše již zpracované období.

Osvobození od daně samo o sobě nenahrazuje zařazení do JMHZ. Náhrada mzdy
při nemoci a příspěvky na penzijní produkty nebo pojištění dlouhodobé péče
potřebují zařazení do odpovídajícího údaje hlášení i tehdy, když jsou
osvobozené. Chybějící nebo deaktivované zařazení použitých složek zastaví
přípravu hlášení. Osvobozené příspěvky na stravování, ubytování, vzdělávání,
rekreaci a zdravotní benefity se mohou vykázat pouze v úhrnu příjmů bez
zařazení do rozpadu mzdy.

Omylem založenou vlastní složku nebo pravidelný předpis lze tlačítkem
**Smazat** odstranit, dokud ještě nevstoupily do žádného mzdového vstupu,
výpočtu ani jiné navazující evidence. Před odstraněním se vždy zobrazí
potvrzení. Jakmile byl záznam použit, aplikace smazání odmítne a vysvětlí, zda
je potřeba ukončit jeho platnost nebo jej deaktivovat; již zpracovaná historie
se nemaže.

## 91.9 Povinné spoření u rizikové práce

Panel **Povinné spoření u rizikové práce** slouží pouze pro práce 3. kategorie,
u nichž je rozhodným faktorem vibrace, chlad, teplo nebo dynamická fyzická
zátěž velkými svalovými skupinami. Nestačí obecné označení vztahu jako
rizikového. U každého dotčeného vztahu proto vyberte konkrétní zákonný faktor
a za měsíc zadejte počet rozhodných osmin směny. Celá osmihodinová směna má
osm osmin; u směny jiné délky se každá započatá hodina počítá jako jedna
osmina. Rozhodný minimální rozsah směn, sazba příspěvku, datum účinnosti i
splatnost určuje pro dané období účinný legislativní ruleset. Tyto hodnoty
uživatel ručně nepřepisuje; zadává pouze skutečný rozsah směn a podklady
konkrétního pracovního vztahu.

Nejdříve v **Mzdy → Nastavení mezd → Účty institucí** založte penzijní
společnost jako jiného příjemce a ověřte její účet. Číslo účtu je v katalogu
šifrované. Do schváleného měsíčního podkladu se připne identifikátor, verze,
hash a maskovaná podoba účtu; pozdější změna katalogu tedy nezmění již
zmrazený běh. V panelu dále uveďte identifikaci smlouvy nebo produktu a podle
pokynů penzijní společnosti variabilní či specifický symbol a zprávu pro
příjemce. Odkaz na podklad zůstává nepovinný.

Datum **Právo uplatněno dne** určuje první měsíc nároku. Oznámí-li zaměstnanec
údaje během aktuálního měsíce, nejde o chybu: aplikace uloží kontrolovatelný
stav bez příspěvku a nárok začne až následující měsíc. Datum, kdy byl
zaměstnanec informován, eviduje samostatnou informační povinnost. Chybějící
datum výpočet 4 % nezmění, ale mzdový běh zobrazí srozumitelné varování.

Podklady nejprve uložte jako koncept a po kontrole je schvalte. Schválený
záznam se už nepřepisuje; oprava založí novou revizi a původní zůstane v
auditní historii. Mzdový běh zmrazí použitý ruleset spolu s výsledkem, takže
pozdější legislativní změna nepřepíše sazbu, minimum směn, účinnost ani
splatnost již vypočteného období. Výpočet použije sazbu z nezastropovaného
vyměřovacího základu a výsledek zaokrouhlí nahoru na celé koruny. Chybí-li
u historického běhu tento podklad nebo je poškozený, aplikace přepočet zablokuje
pro ruční posouzení; nikdy místo něj nedosadí dnešní parametry. Uhrazení potvrďte
až podle skutečného bankovního pohybu.
V podvojném účetnictví se příspěvek účtuje jako zákonný sociální náklad
zaměstnavatele proti závazku vůči penzijní společnosti (výchozí kontace
527 / 379). Zaměstnanci se nevyplácí, takže nejde na účet čistých mezd, a
penzijní společnost není institucí sociálního ani zdravotního pojištění, takže
nejde ani na 336. Předkontaci lze změnit v
[Nastavení mezd](90_Nastaveni_mezd.md#9081-predkontace-pro-zvlastni-mzdove-situace).

Po schválení mzdové revize aplikace vytvoří samostatný závazek **Povinné
spoření u rizikové práce** v Mzdových příkazech. Odtud jej zařaďte do ABO
nebo SEPA dávky stejně jako ostatní mzdové odvody. Za uhrazený se považuje až
po spárování bankovní transakce nebo pokladního dokladu; v panelu podkladů se
stav ručně nepřepíná.
