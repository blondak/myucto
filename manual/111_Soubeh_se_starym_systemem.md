# 111. Souběh se starým systémem

**Cesta: `Účetnictví → Souběh se starým systémem`**

Firma, která přechází do MyÚčta z jiného účetního programu, obvykle ještě několik
měsíců účtuje v obou. Kontrola souběhu každý měsíc porovná MyÚčto s výstupy starého
programu a vypíše rozdíly po kritériích. Rozdíly účetní zařadí a měsíc uzavře.

Kontrola do účetnictví nic nezapisuje. Ukládá jen protokol, který zůstává
v historii cyklů. Stránka je dostupná firmám s podvojným účetnictvím. Kontrolu
spouští, zařazuje rozdíly a uzavírá uživatel s právem zápisu do účetnictví,
prohlížet ji může každý, kdo vidí účetnictví.

## 111.1 Co se porovnává

Na straně MyÚčta se berou stejné sestavy, jaké vidí účetní v aplikaci, vždy
k poslednímu dni vybraného měsíce.

| Kritérium | Co se porovná | Výstup starého programu | Tolerance |
|---|---|---|---|
| K1 | Obratová předvaha po syntetických účtech: počáteční stav, obrat od začátku roku a konečný stav | Obratová předvaha (CSV nebo text) | 0,00 Kč |
| K5 | Počty dokladů měsíce po knihách: vydané a přijaté faktury, pokladna, banka, interní doklady | Počty dokladů po knihách | 0 ks |
| K6 | Otevřené položky saldokonta po účtech a partnerech | Saldokonto | 0,00 Kč |
| K7 | Úhrady po dokladech: doklad otevřený jen v jednom z programů | Saldokonto | seznam |
| K8 | Korunové bankovní účty: zůstatek účtu 221 a posledního výpisu | Zůstatky bankovních účtů | 0,00 Kč |
| K9 | Přiznání k DPH po řádcích a kontrolní hlášení po dokladech | Přiznání a kontrolní hlášení (XML z EPO) | 0 Kč na řádek |
| K10 | Účty v cizí měně: zůstatek v měně a v Kč | Zůstatky bankovních účtů | 0,01 v měně |
| K11 | Majetek po kartách: vstupní cena, oprávky, zůstatková cena | Inventurní soupis majetku | 0,00 Kč |
| K12 | Výnosy a náklady měsíce po střediscích | Obraty po střediscích | 0,00 Kč |
| K13 | Řádky rozvahy a výsledovky | Rozvaha, výsledovka | 0 Kč, u výkazu v tisících ±1 tis. |

Kritérium, ke kterému nenahrajete výstup, se nekontroluje a v protokolu není.
Kritéria K2 až K4 (vyrovnanost, úplnost mapování, doklady proti deníku) kontroluje
převod sám při každém běhu, viz kapitola 103.5.

Přiznání k DPH a kontrolní hlášení MyÚčto sestaví stejně jako při podání
a porovná s XML starého programu po atributech. Pořadí řádků kontrolního hlášení
ani mezery v evidenčním čísle dokladu nehrají roli. Rozdíl u dokladu v kontrolním
hlášení a v saldokontu odkazuje na doklad v MyÚčtu.

## 111.2 Postup

1. Vyberte **měsíc** a **starý program** (Money S3, POHODA, PREMIER nebo jiný).
2. Nahrajte výstupy, které máte k dispozici. U každého pole je vidět, ke kterému
   kritériu patří. Výkazy vyexportované v tisících Kč přepněte volbou
   **Výkazy ve** na *tisících Kč*.
3. U Money S3 můžete místo souborů vybrat **zálohu agendy** nahranou v průvodci
   přechodu z Money S3 (kapitola 103). Záloha se čte bez zápisu do MyÚčta a doplní
   obratovou předvahu a počty dokladů, pokud je nenahrajete jako soubor. Sestava
   vyexportovaná z Money má přednost před zálohou.
4. Klikněte na **Spustit kontrolu**. Výsledek se zobrazí po kritériích a kontrola
   přibude do historie cyklů.

Kontrolu lze pustit i z příkazové řádky, například pro dávku firem:

```
php api/bin/parallel-run-check.php --ico=<IČO> --month=RRRR-MM --source=money_s3 \
    --trial-balance=predvaha.csv --saldo=saldo.csv --vat-return=dphdp3.xml \
    [--money-backup=agenda.lz] [--save]
```

Bez `--save` se výsledek jen vypíše, s ním se uloží do historie cyklů.

## 111.3 Zařazení rozdílů a uzavření cyklu

Každý rozdíl zařaďte do jedné skupiny:

- **Už ve zdroji**: rozdíl je už ve starém programu a převod ho věrně převzal.
- **Rozdíl převodu**: převod data přenesl jinak. Opravte příčinu a kontrolu
  pusťte znovu.
- **Rozdíl výkladu**: MyÚčto vykazuje položku jinak a předpis připouští obojí.

K zařazení lze připsat poznámku. Opakovaná kontrola téhož měsíce převezme zařazení
rozdílů, které trvají, kromě rozdílů převodu.

Tlačítko **Uzavřít cyklus** je aktivní, když žádné kritérium neskončilo chybou
a všechny rozdíly jsou zařazené jako *Už ve zdroji* nebo *Rozdíl výkladu*.
Uzavřený cyklus nejde měnit ani smazat, dokud ho znovu neotevřete (menu „…").
**Protokol CSV** stáhne výsledek kontroly se zařazením všech rozdílů.

## 111.4 Formát výstupů

Tabulkové výstupy jsou CSV nebo text se středníkem, tabulátorem nebo čárkou,
v UTF-8 nebo Windows-1250. Hlavička nemusí být na prvním řádku, řádky nad ní
(název firmy, období) se přeskočí. Součtové řádky (Celkem, Součet) se nepočítají.
Sloupce se hledají podle názvu bez ohledu na diakritiku a velikost písmen:

| Výstup | Povinné sloupce | Volitelné sloupce |
|---|---|---|
| Obratová předvaha | účet a 3 čísla (PS, obrat, KS netto) nebo 6 čísel (PS MD, PS D, obrat MD, obrat D, KS MD, KS D) | hlavička se nehledá |
| Počty dokladů | Kniha, Počet | |
| Saldokonto | Doklad (Číslo dokladu), Zbývá (Zůstatek, Saldo) | Účet, Firma, IČ |
| Zůstatky bankovních účtů | Účet (číslo účtu, IBAN nebo analytika 221), Zůstatek | Měna, Zůstatek Kč |
| Inventurní soupis majetku | Inventární číslo | Název, Vstupní cena, Oprávky, Zůstatková cena |
| Obraty po střediscích | Středisko a buď Výnosy a Náklady, nebo Účet a Obrat | |
| Rozvaha, výsledovka | Označení (Řádek), Běžné období (Částka) | Strana (A/P) |

- **Předvaha** se čte stejně jako sestava z Money v průvodci převodu: obsahuje-li
  syntetický účet i jeho analytiky, platí syntetický řádek.
- **Počty dokladů**: knihu kontrola pozná podle názvu (*Faktury vydané*, *Přijaté
  faktury*, *Pokladna*, *Bankovní výpisy*, *Interní doklady*). Neznámou knihu
  vynechá s upozorněním.
- **Saldokonto**: částka je zbývající částka kladně (pohledávka i závazek).
  Přijatou fakturu kontrola spáruje podle čísla dokladu dodavatele i podle vlastního
  čísla. Bez sloupce účtu se porovnají všechny saldokontní účty najednou.
- **Obraty po střediscích** jsou obraty měsíce, výnosy i náklady kladně. Řádek
  *Bez střediska* porovná i obraty bez střediska. Na straně MyÚčta se bere první
  aktivní typ dimenze *Středisko* (kapitola 110).
- **Rozvaha**: pasiva poznají sloupec *Strana* s hodnotou P nebo označení začínající
  `P.` (například `P.A.I.`), ostatní řádky jsou aktiva. Aktiva se porovnávají
  v netto hodnotě.

## 111.5 Na co si dát pozor

- Počty dokladů ze zálohy Money zahrnují i doklady, které převod záměrně nepřebírá
  (nezaúčtovaný koncept v uzavřeném roce, nulový pokladní doklad). Kontrola je
  ukáže jako rozdíl knihy, zařaďte je jako *Rozdíl výkladu*.
- Inventurní soupis majetku v MyÚčtu počítá oprávky ze zaúčtovaných odpisů.
  V průběhu roku, kdy se odpisy účtují až k 31. 12., proto porovnávejte hlavně
  stav k rozvahovému dni.
- Přiznání k DPH sestavuje MyÚčto podle periody firmy. U čtvrtletního plátce
  spouštějte kontrolu DPH za poslední měsíc čtvrtletí.

Související kapitoly: [Přechod z Money S3](103_Prechod_z_Money_S3.md),
[Přechod z POHODY](107_Prechod_z_POHODY.md), [Přechod z PREMIER](109_Prechod_z_PREMIER.md),
[Obratová předvaha](56_Obratova_predvaha.md), [Saldokonto](60_Saldokonto.md).
