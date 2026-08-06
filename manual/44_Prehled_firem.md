# 44. Přehled firem

**Cesta: `Účetnictví → Přehled firem`**

Přehled firem je pracovní rozcestník pro účetní kancelář nebo jiného uživatele,
který má přístup k více firmám. Položka se v menu zobrazí jen tehdy, když má
uživatel k dispozici více firem a právě pracuje ve firmě s podvojným
účetnictvím. Samotný přehled ale může obsahovat i přiřazené firmy v režimu
daňové evidence.

Stránka nic neúčtuje a nepřepočítává doklady. Skládá aktuální provozní ukazatele
ze všech povolených firem a umožňuje jedním kliknutím přepnout aktivní firmu a
otevřít příslušnou agendu.

## 44.1 Které firmy uživatel vidí

Běžný uživatel vidí výhradně firmy, ke kterým má řádek přiřazení a účinné
oprávnění. Uživatel bez přiřazené firmy neuvidí žádnou. Systémový superadmin
vidí všechny firmy.

Omezení se provádí na serveru, nikoli pouze skrytím řádků v prohlížeči.
Přehled proto není závislý na právě zvolené firmě v přepínači. Po kliknutí na
řádek se aktivní firma nastaví a stránka se načte znovu s jejím kontextem.

Firmy jsou řazené podle naléhavosti nejbližšího daňového termínu. Termín po
splatnosti je před budoucím termínem, firmy bez vypočteného termínu jsou na
konci; při shodě rozhoduje název firmy.

## 44.2 Co znamenají sloupce

| Sloupec | Zdroj a význam | Kam vede |
|---|---|---|
| **Firma** | Zobrazovaný název firmy a IČ. | Přepne firmu a otevře její Přehled. |
| **Nejbližší termín** | Nejbližší termín DPH, KH nebo SH vypočtený CRM agregací; zobrazuje datum a počet dní, záporná hodnota znamená po termínu. | Přepne firmu a otevře přiznání k DPH. |
| **Nezaúčtováno** | Součet nezaúčtovaných vydaných dokladů podporovaných pro zaúčtování, nezaúčtovaných přijatých dokladů kromě zálohových výzev a skutečných bankovních pohybů bez aktivního zápisu. U firmy v daňové evidenci je vždy nula. | Přepne firmu a otevře vydané faktury s filtrem nezaúčtovaných. |
| **Banka bez párování** | Nespárované příchozí pohyby za posledních 90 dní. Vlastnictví se ověřuje podle bankovního účtu firmy, nikoli jen podle hlavičky výpisu. | Přepne firmu a otevře Banku. |
| **Koncepty přijatých faktur** | Přijaté faktury ve stavu koncept. | Přepne firmu a otevře filtrovaný seznam přijatých faktur. |
| **Účetní období** | Nejnovější založené účetní období a jeho stav **Otevřené**, **Uzavírá se** nebo **Uzavřené**. Firma bez období má pomlčku. | Je pouze informační. |
| **Poslední import banky** | Nejnovější čas importu výpisu, který podle bankovních účtů náleží dané firmě. | Je pouze informační. |

> ⚠️ **Nezaúčtováno je pracovní součet.** Číslo skládá tři různé agendy. Kliknutí vede na
> vydané faktury, ne na úplný rozpad. Celý seznam známých nedokončených případů
> otevřete přes [K doúčtování](47_Rucni_fronta_doctovani.md); čekající návrhy
> bankovní kontace jsou v [Automatu](46_Automat.md).

## 44.3 Jak přehled používat

Doporučený začátek práce nad více firmami:

1. Nejprve projděte firmy s prošlým nebo blízkým daňovým termínem.
2. U každé firmy zkontrolujte počet nezaúčtovaných položek, banku bez párování
   a koncepty přijatých faktur.
3. Klikněte na konkrétní ukazatel. Aplikace přepne firmu a otevře zdrojovou
   agendu.
4. Po dokončení práce se vraťte do Přehledu firem a použijte
   **Aktualizovat**. Čas **Vygenerováno** ukazuje okamžik posledního načtení,
   nejde o trvale uložený snímek.

Mobilní zobrazení používá zkrácené karty firmy. Ukazuje nejbližší termín,
stav období a tři provozní počty; poslední import banky a přímé akční tlačítko
jsou dostupné v plné tabulce na širší obrazovce.

## 44.4 Co přehled nekontroluje

Přehled neověřuje věcnou správnost kontace, úplnost všech účetních podkladů,
shodu saldokonta, DPH ani stav inventarizace. Například:

- nula u banky bez párování neznamená, že jsou všechny platby správně
  zaúčtované,
- nula u nezaúčtovaných dokladů nezahrnuje doklad, který v systému vůbec
  chybí,
- termín DPH neznamená, že je přiznání připravené nebo podané,
- stav **Uzavřené** neprokazuje, že proběhly všechny odborné kontroly.

Pro denní práci pokračujte kapitolami [Automat](46_Automat.md) a
[K doúčtování](47_Rucni_fronta_doctovani.md), pro periodické kontroly
[Úplnost dokladů](54_Uplnost_dokladu.md) a
[Měsíční kontrola](55_Mesicni_kontrola.md).
