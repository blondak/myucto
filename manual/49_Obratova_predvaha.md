# 49. Obratová předvaha

**Cesta: `Účetnictví → Obratová předvaha`**

Obratová předvaha je kontrolní soupis PS, obratů a KS všech účtů. Na rozdíl
od [Hlavní knihy](48_Hlavni_kniha.md) nemá měsíční rozpad ani filtry podle
protistrany a položky; přidává tři vazby, které ověřují úplnost a podvojnost
deníku.

## 49.1 Rozsah a zdroj dat

Sestava je dostupná jen v podvojném účetnictví a čte pouze zaúčtované řádky
deníku. Koncepty se nezapočítají a jejich počet se zobrazí ve varování.

Filtry **Období**, **Od**, **Do**, **Rozpad po analytikách** a **Po uzavření
knih** mají stejný význam jako v Hlavní knize. Datum musí ležet uvnitř
období. Výchozí pohled závěrkový převod knih vynechá; volba **Po uzavření
knih** jej zahrne.

## 49.2 Výpočet řádků

Pro každý účet se nejprve netto počáteční stav umístí na MD nebo Dal, zvlášť
se sečtou obraty obou stran a konečný zůstatek se určí:

`KS saldo = PS MD − PS Dal + obrat MD − obrat Dal`

Kladné saldo se zobrazí v **KS MD**, záporné v **KS Dal**. Otevírací zápis
z prvního dne období se zahrnuje do PS. Rozvahové účty navazují na historický
nebo uzávěrkou vytvořený otevírací stav, zatímco výsledkové účty nezačínají
před prvním dnem fiskálního období. Účty bez PS a pohybu se vynechají.

Bez rozpadu analytik se pohyby analytických účtů seskupí pod syntetiku.
Sestava zahrnuje i podrozvahové a uzávěrkové účty; kontroluje celý deník,
nejen účty vykazované v rozvaze.

## 49.3 Kontroly

Kontrolní blok vyhodnocuje částky na haléře:

1. **Σ obrat MD = Σ obrat Dal** — všechny pohyby ve výběru dodržují podvojnost.
2. **Obrat předvahy = obrat deníku** — MD i Dal předvahy odpovídají nezávislému
   součtu všech zaúčtovaných řádků deníku za stejný rozsah.
3. **Σ PS MD = Σ PS Dal** — netto počáteční stavy všech účtů jsou v rovnováze.

Červený stav první kontroly ukazuje nevyváženost zápisů. Neshoda s deníkem
typicky znamená chybějící účet v osnově nebo chybu seskupení. Nevyvážený PS
ukazuje na problém přenosu zůstatků či otevření knih. Zelené kontroly potvrzují
vnitřní vazby deníku, samy však nepotvrzují věcnou správnost účtování nebo
správné mapování účtů do výkazů.

## 49.4 Detail a export

Kód účtu vede do opisu za stejné období `Od / Do`. Přes **Sloupce** a
**Hustotu** lze upravit tabulku bez změny dat.

PDF i XLSX se vytvářejí ze stejných filtrů jako obrazovka a obsahují řádky,
součty i kontrolní vazby. Před sestavením [Rozvahy](50_Rozvaha.md) nebo
[Výsledovky](51_Vysledovka_druhova.md) je vhodné nejprve odstranit všechny
červené kontroly a doúčtovat koncepty.
