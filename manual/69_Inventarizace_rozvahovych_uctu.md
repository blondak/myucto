# 69. Inventarizace účtů

> Návod, jak před uzavřením knih odsouhlasit zůstatky rozvahových účtů se skutečností a doložit případné
> rozdíly. Pro účetní firmy v podvojném účetnictví. Doporučený celkový postup popisuje také kapitola
> [Účetní kontroly a inventarizace](46_Ucetni_kontroly_a_inventarizace.md).

## 69.1 Kdy to potřebujete

Kapitolu otevřete, když:

- se blíží uzavření účetního období a kontrola uzávěrky hlásí nedokončenou inventarizaci,
- potřebujete soupis zůstatků rozvahových účtů k rozvahovému dni pro fyzickou nebo dokladovou inventuru,
- potřebujete doložit, jak byl vypořádán rozdíl mezi účetním a skutečným stavem,
- potřebujete vytisknout inventurní soupis s místem pro podpis (§ 29 a 30 zákona o účetnictví).

Inventarizace účtů převádí konečné zůstatky rozvahových účtů na pracovní protokol, do kterého účetní doplní
skutečný stav a vypořádání rozdílu.

## 69.2 Než začnete

1. **Podvojné účetnictví.** Stránka je dostupná jen firmě v podvojném účetnictví.
2. **Zaúčtované doklady.** Sestava bere jen zaúčtované řádky deníku. Nezaúčtované koncepty se nezapočítají, jejich počet aplikace ukáže ve varování.
3. **Otevřené nebo uzavírané období.** Skutečný stav lze uložit jen v otevřeném nebo uzavíraném období.
4. **Podklady k inventuře.** Připravte si nezávislé podklady ke skutečnému stavu (bankovní výpisy, soupis zásob, odsouhlasení s protistranou). Doporučené podklady viz [§ 69.5.2](#6952-doporucene-podklady).

## 69.3 Krok za krokem: provést inventarizaci

1. Otevřete `Nástroje → Inventarizace účtů`.
2. V poli **Období** zvolte účetní období. Sestava se vždy sestavuje k poslednímu dni celého vybraného období; libovolné Od/Do se nepoužívá.
3. Vyplňte hlavičku **Inventarizačního protokolu**: **Odpovědná osoba**, **Datum inventury**, **Odkaz na protokol** (číslo jednací nebo odkaz na podepsaný protokol) a poznámku.
4. U každého účtu doplňte do sloupce **Skutečný stav** hodnotu z nezávislého podkladu. Sloupec **Rozdíl** se počítá živě. Sloupec **Způsob doložení** nabízí doporučený podklad.
5. U účtu s rozdílem napište do pole **Poznámka k rozdílu**, čím je rozdíl doložen nebo vypořádán. Pak rozdíl potvrďte zaškrtnutím **Vyřešeno**. Samotné zaškrtnutí nic nezaúčtuje; opravu proveďte průkazným zdrojovým nebo ručním zápisem.
6. Klikněte na **Uložit** (protokol zůstane rozpracovaný), nebo na **Uložit a dokončit**, jakmile nezůstal žádný nevyřešený účet.
7. Chcete-li soupis vytisknout, klikněte na **Export PDF** nebo **Export XLSX**.

**Jak poznáte, že je hotovo:** Protokol má stav **Dokončeno**, aplikace ohlásila **Inventarizace dokončena** a kontrola uzávěrky už neblokuje uzavření knih.

> [!WARNING]
> **Uložit a dokončit** uspěje, jen když nezůstal žádný nevyřešený účet. Jinak aplikace napíše, kolik
> nevyřešených rozdílů zbývá. Pokud se účetnictví po uložení změní, kontrola znovu porovná uložený skutečný
> stav s aktuálním účetním stavem. Dříve nulový rozdíl tak může být znovu nevyřešený.

## 69.4 Když něco nejde

<!-- cols: 34 30 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Nelze dokončit, zbývají nevyřešené rozdíly | U některých účtů se skutečný stav liší od účetního a nejsou označené jako vyřešené | Rozdíl opravte zápisem, nebo ho doložte poznámkou a zaškrtněte **Vyřešeno**. |
| Skutečný stav nejde uložit | Období je uzavřené nebo schválené | Skutečný stav lze uložit jen v otevřeném nebo uzavíraném období. |
| V období jsou nezaúčtované koncepty | Koncepty se do zůstatků nepočítají | Zaúčtujte je, jinak soupis neodpovídá úplnému stavu knih. |
| Uzavřené období má skutečný stav doplněný sám | Jde o starší uzavřené období bez uloženého protokolu | Viz [§ 69.5.1](#6951-pravidla-sestavy-a-protokolu). Nejde o důkaz fyzické inventury. |
| Kontrola uzávěrky stále blokuje | Inventarizace není dokončená nebo je nevyřešený rozdíl | Dokončete protokol ([§ 69.3](#693-krok-za-krokem-provest-inventarizaci)). |

## 69.5 Podrobnosti a pravidla

### 69.5.1 Pravidla sestavy a protokolu

**Jaké účty a datum sestava používá.** Sestava se vždy sestavuje k poslednímu dni celého vybraného účetního
období. Z deníku bere jen zaúčtované řádky a vylučuje vlastní závěrkový převod knih.

Zahrnuty jsou účty typu **aktiva**, **pasiva** a **vlastní kapitál**, tedy rozvahové účty tříd 0 až 4.
Nákladové, výnosové, podrozvahové a uzávěrkové účty se vynechají. Analytiky se seskupují pod syntetiku.

Pro každý účet platí:

`účetní stav = PS MD − PS Dal + obrat MD − obrat Dal`

Kladná hodnota je konečný zůstatek MD, záporná konečný zůstatek Dal. Na obrazovce se **Účetní stav** a
**Skutečný stav** uvádějí na normální straně účtu, u pasivních účtů (závazky, oprávky, vlastní kapitál) tedy
jako kladné číslo. Účty bez určené strany (±, například 343 nebo 431) se uvádějí znaménkově: kladně na MD,
záporně na Dal. Koncepty se nezapočítají a jejich počet se zobrazí ve varování.

**Protokol a skutečný stav.** Hlavička protokolu obsahuje odpovědnou osobu, datum inventarizace, označení
protokolu a poznámku. U každého účtu lze zadat:

- **Skutečný stav** z nezávislého inventurního podkladu,
- **Rozdíl**, který se živě počítá jako `skutečný stav − účetní stav`,
- potvrzení **Vyřešeno**,
- poznámku k doložení nebo vypořádání rozdílu.

Účet je pro uzávěrku vyřešený, pokud skutečný stav přesně sedí na haléře, nebo účetní rozdíl výslovně označí
jako vyřešený. Samotné zaškrtnutí nic nezaúčtuje; opravu je třeba provést průkazným zdrojovým nebo ručním
zápisem a důvod rozdílu doložit.

Při uložení aplikace znovu načte živé účetní zůstatky, vypočte rozdíly a přepíše položky protokolu, takže
účetní saldo zaslané z obrazovky není zdrojem pravdy.

**Vazba na uzávěrku.** Nedokončená inventarizace nebo nevyřešený rozdíl blokují kontrolu uzávěrky a tím
uzavření knih.

**Starší uzavřená období.** U staršího již uzavřeného či schváleného období bez uloženého protokolu se pro
čtení doplní skutečný stav z účetního zůstatku a položky se označí jako vyřešené. Jde o technický backfill
uzavřeného roku, nikoli důkaz, že byla provedena fyzická nebo dokladová inventura.

### 69.5.2 Doporučené podklady

Aplikace podle účtu nabídne typ podkladu, například:

- 0xx: inventární karta a odpisový plán,
- 1xx: skladová evidence a inventurní soupis,
- 211/213: fyzická inventura hotovosti či cenin,
- 221: bankovní výpis,
- 311/314/321/324: saldokonto a odsouhlasení s protistranou,
- 33x: mzdová rekapitulace,
- 34x: přiznání, rozhodnutí správce daně nebo rekapitulace,
- 38x: smlouva, výpočet časového rozlišení či dohadu,
- 4xx: smlouva, rozhodnutí orgánu společnosti nebo výpočet.

Jde o vodítko. Aplikace neověřuje, zda podklad existuje, je podepsaný nebo odpovídá skutečnosti. Kód účtu
vede do opisu za celé období.

### 69.5.3 Export

**Export PDF** a **Export XLSX** vyexportují účetní soupis konečných zůstatků MD a Dal, doporučený podklad
a prázdná pole pro ruční doplnění skutečného stavu a rozdílu. Nejde o tisk uložených editovatelných hodnot
protokolu z obrazovky; ty zůstávají uložené v uzávěrkové evidenci aplikace.

> [!WARNING]
> Aplikace nenahrazuje inventuru. Připraví a hlídá účetní část. Fyzickou inventuru, existenci majetku,
> vymahatelnost pohledávek, úplnost závazků a průkaznost podkladů musí posoudit odpovědná osoba.

## 69.6 Související kapitoly

- [Účetní kontroly a inventarizace](46_Ucetni_kontroly_a_inventarizace.md)
- [Uzávěrka](72_Uzaverka.md)
