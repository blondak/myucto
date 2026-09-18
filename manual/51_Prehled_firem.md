# 51. Přehled firem

**Cesta: `Účetnictví → Přehled firem`**

Přehled firem je pracovní rozcestník pro účetní kancelář nebo jiného uživatele,
který má přístup k více firmám. Položka se v menu zobrazí jen tehdy, když má
uživatel k dispozici více firem a právě pracuje ve firmě s podvojným
účetnictvím. Samotný přehled ale může obsahovat i přiřazené firmy v režimu
daňové evidence.

Stránka nic neúčtuje a nepřepočítává doklady. Skládá aktuální provozní ukazatele
ze všech povolených firem a umožňuje jedním kliknutím přepnout aktivní firmu a
otevřít příslušnou agendu.

## 51.1 Které firmy uživatel vidí

Běžný uživatel vidí výhradně firmy, ke kterým má řádek přiřazení a účinné
oprávnění. Uživatel bez přiřazené firmy neuvidí žádnou. Systémový superadmin
vidí všechny firmy.

Omezení se provádí na serveru, nikoli pouze skrytím řádků v prohlížeči.
Přehled proto není závislý na právě zvolené firmě v přepínači. Po kliknutí na
řádek se aktivní firma nastaví a stránka se načte znovu s jejím kontextem.

Firmy jsou řazené podle naléhavosti nejbližšího daňového termínu. Termín po
splatnosti je před budoucím termínem, firmy bez vypočteného termínu jsou na
konci; při shodě rozhoduje název firmy.

## 51.2 Co znamenají sloupce

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
> otevřete přes [K doúčtování](54_Rucni_fronta_doctovani.md); čekající návrhy
> bankovní kontace jsou v [Automatu](53_Automat.md).

## 51.3 Pruh akutních kontrol

Firma s podvojným účetnictvím má nad ukazateli barevný pruh **Akutní kontroly**.
Ukazuje vybrané nálezy z měsíční kontroly za aktuální účetní období do dnešního
dne, jen jako názvy a počty. Kliknutím na nález se otevře měsíční kontrola té
firmy, kde je celý seznam dokladů i opravy.

Pruh záměrně ukazuje **jen to, s čím lze hnout dnes**: chybějící účetní zápis
dokladu, saldo na 311 nebo 321, které nesedí na zaplacený doklad, nevyrovnaný
deník, koncept v období, nezaúčtovaný kurzový rozdíl nebo rozdíl mezi účtem 343
a podaným přiznáním k DPH.

Práce vázaná na rozvahový den se v pruhu **nezobrazuje**, protože u otevřeného
roku chybí naprosto legitimně a svítila by měsíce:

- účetní odpisy roku (účtují se v uzávěrce),
- inventarizace rozvahových účtů podle § 29–30 zákona o účetnictví,
- nerozdělený výsledek hospodaření na účtu 431,
- neuzavřený minulý rok.

Tyto kontroly najdete v [Měsíční kontrole](62_Mesicni_kontrola.md) a hlídá je
uzávěrková brána, která bez jejich vyřešení nedovolí rok uzavřít. Zelený pruh
proto znamená „nic akutního", nikoli „účetnictví je hotové".

## 51.4 Jak přehled používat

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

## 51.5 Co přehled nekontroluje

Přehled neověřuje věcnou správnost kontace, úplnost všech účetních podkladů,
shodu saldokonta, DPH ani stav inventarizace. Například:

- nula u banky bez párování neznamená, že jsou všechny platby správně
  zaúčtované,
- nula u nezaúčtovaných dokladů nezahrnuje doklad, který v systému vůbec
  chybí,
- termín DPH neznamená, že je přiznání připravené nebo podané,
- stav **Uzavřené** neprokazuje, že proběhly všechny odborné kontroly.

Pro denní práci pokračujte kapitolami [Automat](53_Automat.md) a
[K doúčtování](54_Rucni_fronta_doctovani.md), pro periodické kontroly
[Úplnost dokladů](61_Uplnost_dokladu.md) a
[Měsíční kontrola](62_Mesicni_kontrola.md).
