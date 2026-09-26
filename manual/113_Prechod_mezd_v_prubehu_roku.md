# 113. Přechod mezd v průběhu roku

**Cesta: `Mzdy → Přehled` (průvodce prvním nastavením, skupina Přechod
v průběhu roku)**

Firma, která začne vést mzdy v MyÚčtu jindy než v lednu, má část roku
zpracovanou v předchozím programu. Roční agendy ale potřebují celý rok:
roční zúčtování záloh, potvrzení o zdanitelných příjmech ze závislé činnosti
(§ 38j zákona o daních z příjmů), vyúčtování zálohové a srážkové daně, mzdový
list, evidenční list důchodového pojištění, průměrný výdělek pro první
čtvrtletí i roční limit dohod o provedení práce. Měsíce před začátkem vedení
mezd se proto do MyÚčta **převezmou**. Nepřepočítávají se a nic se z nich
znovu neúčtuje ani nepodává.

Kapitola platí pro každý zdroj. Převod z PAMICA, PREMIER nebo import hlášení
JMHZ převzetí vyplní sám (kapitoly 108, 109 a 90); zbytek kapitoly popisuje,
jak převzetí doplnit ručně nebo ze souboru a jak ho zkontrolovat.

## 113.1 Začátek vedení mezd

Za převzaté se považují měsíce roku, ve kterém mzdy v MyÚčtu začínají, a to
před prvním mzdovým obdobím. První mzdové období se nastavuje při zapnutí mezd
na přehledu mezd. Když mzdy začínají lednem, převzatá část roku neexistuje
a nic z této kapitoly se neukáže.

Mzdový běh za převzatý měsíc založit nejde. Průvodce prvním nastavením na
přehledu mezd přidá u přechodu v průběhu roku skupinu kroků, které vedou
na všechna místa z této kapitoly.

## 113.2 Dvě vrstvy převzaté části roku

| Vrstva | Co obsahuje | Kdo z ní čte |
|---|---|---|
| **Počáteční stavy ročních součtů** | po měsících a za osobu: základ sociálního pojištění, základ a záloha daně, srážková daň, uplatněné slevy, sleva na děti, daňový bonus | roční zúčtování, potvrzení o zdanitelných příjmech, mzdový list, vyúčtování daně, roční maximum vyměřovacího základu |
| **Převzaté mzdy** | po vztazích a měsících: hrubá a čistá mzda, pojistné, vyměřovací základy, zálohy, dny pojištění, odpracované hodiny, datum výplaty | evidenční list důchodového pojištění, převzatý běh, kontrolní sestava, návrh průměrného výdělku, limit DPP |

Obě vrstvy musí tvrdit totéž. Kde si odporují, ukáže kontrola převzaté
části roku (113.9).

## 113.3 Ruční zadání převzatých mezd

**Cesta: `Mzdy → Importy → Převzaté mzdy`, blok Ruční zadání převzatých mezd**

Ruční zadání je pro měsíce, ze kterých nemáte export ani hlášení.

1. Vyberte zaměstnance. Formulář nabídne jeho pracovní vztahy, které
   v převzatých měsících trvaly, a měsíce, za které se převzetí čeká.
2. Za každý vztah a měsíc vyplňte úhrny z předchozího programu **v korunách**.
   Pole jsou seskupená na mzdu, pojištění, daň a doby.
3. Měsíc, ve kterém zaměstnanec opravdu neměl žádný příjem (například
   neplacené volno), zaškrtněte jako **Opravdu nula**. Prázdný měsíc se jako
   nula neuloží.
4. Základ daně, slevy, zvýhodnění na děti a dopočet do minima jsou veličiny
   osoby. U souběžných vztahů je stačí vyplnit v jednom řádku měsíce.
5. Uložte.

Uložení zapíše převzaté mzdy i počáteční stavy najednou. Odmítne měsíc mimo
převzatou část roku, vztah, který v měsíci netrval, dvojí zadání téhož
měsíce, nepotvrzenou nulu i vynechaný měsíc, ve kterém vztah trval. Když už
jsou počáteční stavy zamčené (z úhrnů vyšlo podané hlášení, je vydaný roční
doklad nebo je mzdový rok uzavřený), formulář je jen ke čtení a řekne proč.

Formulář načte i převzaté mzdy nahrané souborem. Po importu ze souboru ho
proto stačí otevřít, zkontrolovat a uložit, a počáteční stavy se doplní.

## 113.4 Import převzatých mezd ze souboru

**Cesta: `Mzdy → Importy → Převzaté mzdy`, nahrání CSV nebo XLSX**

Vzorový soubor je ke stažení na stejném místě. Zaměstnance určuje sloupec se
jménem spolu s označením pracovního vztahu, období je měsíc a částky jsou
v korunách s nejvýše dvěma desetinnými místy (například `12 345,50`).
Starší soubory s částkami v haléřích import přijme dál.

Import ze souboru plní **jen převzaté mzdy**. Počáteční stavy doplňte
ručním zadáním (113.3), nebo na kartě pracovního vztahu (113.5).

## 113.5 Počáteční stavy na kartě pracovního vztahu

**Cesta: `Mzdy → Zaměstnanci → karta pracovního vztahu`, sekce Počáteční
stavy za rok**

Sekce se ukáže u hlavního vztahu zaměstnance, který nastoupil před prvním
mzdovým obdobím. Každý převzatý měsíc, ve kterém vztah trval, musí mít
vyplněné úhrny, nebo zaškrtnuté **Opravdu nula**. Stejně se chová import
počátečních stavů ze souboru: řádek měsíce bez jediné vyplněné částky je
chyba, výslovně zapsané nuly znamenají potvrzenou nulu.

Prázdný měsíc není nula. Nulový stav by tiše podhodnotil roční zúčtování,
potvrzení o příjmech i roční maximum sociálního pojištění.

## 113.6 Prohlášení, děti a invalidita od ledna

**Cesta: `Mzdy → Zaměstnanci → karta osoby`, Zákonná evidence**

Prohlášení poplatníka, vyživované děti a invaliditu zaevidujte s platností
od začátku roku, ne od přechodu. Potvrzení o zdanitelných příjmech za převzaté
měsíce uvádí, zda bylo podepsané prohlášení, na které děti a v jakém stupni
invalidity se slevy uplatnily. Bez této evidence se potvrzení nevystaví
a řekne, co chybí.

Roční zúčtování se zastaví, když počáteční stavy u převzatého měsíce nesou
slevu na děti nebo bonus, ale v evidenci osoby za ten měsíc žádné dítě není.
Odkaz v zúčtování vede přímo na kartu zaměstnance.

## 113.7 Identifikátory pro ČSSZ a hlášení JMHZ

**Cesta: `Mzdy → Zaměstnanci → karta pracovního vztahu`, identifikátory
JMHZ; hromadně `Mzdy → Importy → JMHZ` nebo `OIČ z POHODY`**

OIČ a ID pojistného vztahu převezměte z předchozího programu. První měsíční
hlášení z MyÚčta na ně navazuje.

Hlášení JMHZ za převzaté měsíce podal předchozí program. MyÚčto k nim
opravné hlášení nesestaví, protože převzatý měsíc nemá mzdový běh, ze kterého
by hlášení vzniklo. Opravu podejte tam, odkud šlo řádné hlášení (předchozí
program nebo portál ČSSZ). Opravené úhrny potom promítněte do převzatých mezd
i počátečních stavů, ať roční doklady odpovídají podanému.

## 113.8 Průměrný výdělek, dovolená a limit DPP

**Průměrný výdělek.** Pro první čtvrtletí po přechodu se průměr pro náhrady
počítá z převzatých mezd předchozího čtvrtletí. Návrh v `Mzdy → Nepřítomnosti
→ Průměrný výdělek` měsíce z převzatých mezd označí. Převzatý měsíc bez odpracovaných
hodin návrh zastaví. Návrh z převzatých mezd se hromadně nezakládá:
zkontrolujte ho a schvalte u každého vztahu zvlášť.

**Dovolená.** Nárok a čerpání za část roku v předchozím programu zapište
ručním záznamem v knize dovolené (`Mzdy → Nepřítomnosti → Dovolená`), ať
zůstatek sedí od prvního měsíce.

**Limit 300 hodin u DPP.** Kontrola mzdového běhu sečte za osobu odpracované
hodiny všech jejích dohod o provedení práce v roce včetně převzatých hodin.
Po překročení 300 hodin přidá varování s počtem hodin a podílem převzatých
a odkazem na podmínky vztahu. Výpočet nezastaví, odpracovanou práci je
potřeba zaplatit; smluvní vztah je ale nutné upravit.

## 113.9 Kontrola převzaté části roku

**Cesta: `Mzdy → Importy → Převzaté mzdy` a `Kontrola převzetí`**

Kontrola za rok vypíše:

- **Chybějící počáteční stavy:** komu v převzatých měsících trval pracovní
  vztah, ale počáteční stav za ty měsíce nic neříká. U každého je odkaz na
  kartu zaměstnance.
- **Rozpory mezi vrstvami** po zaměstnancích, měsících a veličinách (základ
  daně proti hrubé mzdě, záloha na daň, srážková daň, daňový bonus, základ
  sociálního a zdravotního pojištění). U základu daně může být rozdíl
  oprávněný, protože osvobozený příjem je v hrubé mzdě, ne v základu daně.
- Měsíce, které mají **jen počáteční stav** (odkaz vede na ruční zadání
  převzatých mezd daného zaměstnance) nebo **jen převzatou mzdu** (odkaz vede
  na kartu zaměstnance, kde se doplní počáteční stav).
- **Nástup odhadnutý z hlášení:** hlášení JMHZ bez data nástupu dá jako nástup
  začátek pojištění v nejstarším hlášeném měsíci. Začíná-li řada hlášení třeba
  v březnu, vztah mohl trvat už v lednu a únoru a převzaté mzdy za ně chybí.
  Kontrola vypíše koho a za které měsíce, s odkazem na kartu vztahu. Tam jde
  nástup opravit, nebo potvrdit tlačítkem **Nástup je správně**. Import hlášení
  za chybějící měsíce posune nástup dřív sám. Mzdový běh ani měsíční hlášení
  to neblokuje; vyúčtování daně a uzávěrka roku na to upozorní.

## 113.10 Vyúčtování daně a uzávěrka roku

Vyúčtování zálohové a srážkové daně naplní převzaté měsíce z počátečních
stavů a označí je jako převzaté. Odvedenou daň za ně odvodí jako sražené
zálohy snížené o vyplacený bonus. Ověřte ji proti osobnímu daňovému účtu.
Přeplatky z ročního zúčtování za předchozí rok, které vyplatil předchozí
program, v převzatých datech nejsou; pokud nějaké byly, doplňte je po
stažení XML v EPO. Když komukoli chybí počáteční stav převzatého měsíce,
vyúčtování se nesestaví a panel vypíše komu a za které měsíce.

Uzávěrka mzdového roku se zastaví na stejné mezeře a vypíše dotčené
zaměstnance. Rozpory mezi vrstvami uzávěrku nezastaví, ale ukáže je jako
varování s odkazem na kontrolu převzetí.
