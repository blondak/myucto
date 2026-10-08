# 56. Obratová předvaha

> Kontrolní soupis počátečních stavů, obratů a konečných zůstatků všech účtů s kontrolami, které ověřují úplnost a podvojnost deníku. Kapitola je pro účetní, která před sestavením výkazů potřebuje zjistit, že je deník v pořádku.

## 56.1 Kdy to potřebujete

Kapitolu otevřete, když:

- chcete před sestavením [Rozvahy](57_Rozvaha.md) nebo [Výsledovky](58_Vysledovka_druhova.md) ověřit, že je deník vyvážený,
- se po importu historie nebo otevření knih potřebujete ujistit, že počáteční stavy sedí,
- potřebujete soupis všech účtů s obraty a zůstatky pro audit nebo klienta,
- se výkazy neshodují a hledáte, kde je rozdíl.

Na rozdíl od [Hlavní knihy](55_Hlavni_kniha.md) nemá předvaha měsíční rozpad ani filtry podle protistrany a položky. Přidává tři kontroly (viz [§ 56.4](#564-kontroly)).

## 56.2 Než začnete

1. **Podvojné účetnictví.** Sestava je dostupná jen firmám v podvojném účetnictví.
2. **Zaúčtované doklady.** Sestava čte jen zaúčtované řádky deníku. Koncepty se nezapočítají a jejich počet se zobrazí ve varování. Doúčtujte je předem v [K doúčtování](54_Rucni_fronta_doctovani.md).

## 56.3 Krok za krokem: kontrola předvahy před výkazy

1. Otevřete `Účetnictví → Obratová předvaha`.
2. V poli **Období** zvolte fiskální rok. Chcete-li, zúžte rozsah poli **Od** a **Do**. Datum musí ležet uvnitř období.
3. Podle potřeby zapněte **Rozpad po analytikách** (jinak se analytiky sečtou pod syntetiku).
4. Podívejte se do bloku **Kontroly** pod tabulkou. Všechny tři kontroly mají být zelené: **Σ obrat MD = Σ obrat Dal**, **Obrat předvahy = obrat deníku** a **Bilanční kontinuita PS (Σ PS MD = Σ PS Dal)**.
5. Je-li některá kontrola červená, postupujte podle [§ 56.5](#565-kdyz-neco-nejde). Teprve potom sestavujte výkazy.
6. Zajímá-li vás, z čeho se zůstatek účtu skládá, klikněte na **kód účtu**. Otevře se opis účtu za stejné období.
7. Chcete-li soupis předat, klikněte na **Export PDF** nebo **Export XLSX**.

**Jak poznáte, že je hotovo:** Všechny tři kontroly jsou zelené a nad tabulkou není varování o konceptech.

> [!TIP]
> Přes **Sloupce** a **Hustotu** lze upravit tabulku bez změny dat. Kliknutím na záhlaví sloupce seřadíte řádky, další kliknutí obrátí směr.

## 56.4 Kontroly

Kontrolní blok vyhodnocuje částky na haléře:

<!-- cols: 38 62 -->
| Kontrola | Co ověřuje |
|---|---|
| **Σ obrat MD = Σ obrat Dal** | Všechny pohyby ve výběru dodržují podvojnost. |
| **Obrat předvahy = obrat deníku** | MD i Dal předvahy odpovídají nezávislému součtu všech zaúčtovaných řádků deníku za stejný rozsah. |
| **Bilanční kontinuita PS (Σ PS MD = Σ PS Dal)** | Netto počáteční stavy všech účtů jsou v rovnováze. |

## 56.5 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Červená první kontrola | Zápisy nejsou vyvážené | Dohledejte nevyvážený zápis v [Účetním deníku](52_Ucetni_denik.md) a opravte ho stornem nebo opravou dokladu. |
| Červená druhá kontrola (neshoda s deníkem) | Typicky chybí účet v osnově nebo je chyba seskupení | Zkontrolujte [Účtový rozvrh](66_Ucetni_osnova.md). |
| Červená třetí kontrola (nevyvážený PS) | Problém přenosu zůstatků nebo otevření knih | Zkontrolujte otevírací zápisy a počáteční stavy. |
| Varování o nezaúčtovaných konceptech | Koncepty se do částek nezapočítají | Koncepty zaúčtujte nebo smažte. |
| Zůstatky k poslednímu dni uzavřeného roku jsou nulové | Zapnuté **Stav po uzavření knih** zahrnuje závěrkový převod | Vypněte volbu. Výchozí pohled závěrkový převod vynechává. |
| **Žádné účty s pohybem nebo počátečním stavem ve zvoleném rozsahu.** | V rozsahu není žádný pohyb | Zkontrolujte **Období**, **Od** a **Do**. |

> [!WARNING]
> Zelené kontroly potvrzují vnitřní vazby deníku, samy však nepotvrzují věcnou správnost účtování ani správné mapování účtů do výkazů.

## 56.6 Podrobnosti a pravidla

### 56.6.1 Rozsah a zdroj dat

Sestava je dostupná jen v podvojném účetnictví a čte pouze zaúčtované řádky
deníku. Koncepty se nezapočítají a jejich počet se zobrazí ve varování.

Filtry **Období**, **Od**, **Do**, **Rozpad po analytikách** a **Stav po uzavření
knih** mají stejný význam jako v Hlavní knize. Datum musí ležet uvnitř
období. Výchozí pohled závěrkový převod knih vynechá; volba **Stav po uzavření
knih** jej zahrne.

### 56.6.2 Výpočet řádků

Pro každý účet se nejprve netto počáteční stav umístí na MD nebo Dal, zvlášť
se sečtou obraty obou stran a konečný zůstatek se určí:

`KS saldo = PS MD - PS Dal + obrat MD - obrat Dal`

Kladné saldo se zobrazí v **KS MD**, záporné v **KS Dal**. Otevírací zápis
z prvního dne období se zahrnuje do PS. Rozvahové účty navazují na historický
nebo uzávěrkou vytvořený otevírací stav, zatímco výsledkové účty nezačínají
před prvním dnem fiskálního období. Účty bez PS a pohybu se vynechají.

Bez rozpadu analytik se pohyby analytických účtů seskupí pod syntetiku.
Sestava zahrnuje i podrozvahové a uzávěrkové účty; kontroluje celý deník,
nejen účty vykazované v rozvaze.

### 56.6.3 Detail a export

Kód účtu vede do opisu za stejné období `Od / Do`. Přes **Sloupce** a
**Hustotu** lze upravit tabulku bez změny dat. Kliknutím na záhlaví sloupce
lze seřadit zobrazené řádky; další kliknutí obrátí směr.

PDF i XLSX se vytvářejí ze stejných filtrů jako obrazovka a obsahují řádky,
součty i kontrolní vazby. Před sestavením [Rozvahy](57_Rozvaha.md) nebo
[Výsledovky](58_Vysledovka_druhova.md) je vhodné nejprve odstranit všechny
červené kontroly a doúčtovat koncepty.

## 56.7 Související kapitoly

- [Hlavní kniha](55_Hlavni_kniha.md) - měsíční rozpad, opis účtu a párování otevřených položek
- [Rozvaha](57_Rozvaha.md) a [Výsledovka](58_Vysledovka_druhova.md) - výkazy, které se sestavují až po zelených kontrolách
- [Účtový rozvrh](66_Ucetni_osnova.md)
