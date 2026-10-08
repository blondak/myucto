# 71. Spojené osoby

> Návod, jak vést transakce se spojenými osobami, ohlídat cenové odchylky a evidovat úpravy základu daně
> podle § 23 odst. 7 zákona o daních z příjmů. Pro účetní a daňové poradce.

## 71.1 Kdy to potřebujete

Kapitolu otevřete, když:

- obchodujete se společníkem, jednatelem, příbuzným nebo jinou spojenou firmou a potřebujete jejich transakce přehledně za rok,
- chcete zjistit, zda se ceny pro spojené osoby výrazně liší od cen pro ostatní zákazníky,
- potřebujete před podáním daňového přiznání k dani z příjmů právnických osob doložit úpravu základu daně,
- měsíční kontrola vás upozornila na transakce se spojenými osobami nebo na cenovou odchylku.

Stránka `Nástroje → Spojené osoby` (nadpis **Spojené osoby a ceny obvyklé**) soustřeďuje zdanitelná plnění
se spojenými osobami, měřitelné odchylky prodejních cen a ručně evidované úpravy základu daně podle
§ 23 odst. 7 zákona o daních z příjmů.

## 71.2 Než začnete

1. **Označte spojené osoby.** Aplikace právní nebo faktický vztah z dokladů neodvozuje. Partner musí být v kartě kontaktu označen jako **Spojená osoba**. Můžete u něj uvést typ vztahu (kapitálově spojená, jinak spojená, blízká osoba nebo pracovněprávní vztah) a poznámku s doložením vazby, například podíl nebo jméno jednatele.
2. **Oprávnění.** Stránka je dostupná uživatelům s právem číst sestavy. Založení nebo smazání úpravy základu daně vyžaduje právo dokončovat daňové sestavy.
3. **Doklady.** Soupis vychází z vydaných a přijatých dokladů za zvolený rok (viz [§ 71.7.1](#7171-soupis-transakci)).

## 71.3 Krok za krokem: zobrazit transakce a odchylky za rok

1. Otevřete `Nástroje → Spojené osoby`.
2. Zvolte rok a případně klikněte na **Načíst**.
3. V části **Transakce se spojenými osobami** projděte směr, partnera, typ vztahu, doklad, datum a částku bez DPH. Číslo dokladu vede na jeho detail.
4. V části **Měřitelné cenové odchylky** zkontrolujte, zda cena účtovaná spojené osobě nevybočuje o 20 % a více od mediánu cen téže položky pro nespojené osoby (sloupce včetně **Ceny obvyklé** a počtu srovnatelných prodejů).
5. Má-li odchylka, nebo transakce bez srovnání, ospravedlnění, doložte cenu obvyklou mimo aplikaci (benchmark, posudek, obchodní důvod).

**Jak poznáte, že je hotovo:** Pro rok máte prověřené všechny transakce a odchylky a víte, kterých se týká
úprava základu daně.

> [!TIP]
> Když srovnatelný vzorek chybí, aplikace odchylku netvrdí. Transakce zůstane v soupisu a cenu obvyklou
> musí doložit účetní.

## 71.4 Krok za krokem: zaevidovat úpravu základu daně

1. Na stránce `Nástroje → Spojené osoby` v části **Úpravy základu daně (§ 23 odst. 7)** klikněte na **Zaevidovat úpravu**.
2. Zvolte směr **zvýšení** nebo **snížení**.
3. Zadejte kladnou **Částku**.
4. Vyplňte povinný **Důvod**. Uveďte, čím je rozdíl doložen, nebo proč doložen není. Bez toho se úprava při kontrole neobhájí.
5. Klikněte na **Uložit**.
6. Chybnou úpravu odstraňte křížkem **×** na jejím řádku v seznamu a potvrzením dotazu.

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Úprava zaevidována** a v souhrnu vidíte **Čistý dopad na základ
daně** (součet zvýšení minus součet snížení).

> [!WARNING]
> Úprava se sama neodvozuje z procentní odchylky. Podle § 23 odst. 7 záleží i na tom, zda je rozdíl
> uspokojivě doložen, což účetní data sama nerozhodnou. Záznam nic nezaúčtuje; je podkladem návrhu přiznání
> k dani z příjmů právnických osob, kde se předává seznam, zvýšení, snížení, čistá změna, objem transakcí
> a odchylky.

## 71.5 Když něco nejde

<!-- cols: 34 32 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Za zvolený rok nejsou žádné transakce se spojenými osobami | Žádný partner není označen jako spojená osoba, nebo v roce nejsou doklady | Označte partnera v kartě kontaktu a zkontrolujte rok. |
| Transakce chybí v soupisu | Je to koncept, storno, proforma nebo přijatý zálohový doklad | Tyto doklady nejsou zdanitelným plněním a do soupisu nevstupují. |
| Částka musí být kladná | Zadali jste nulu nebo zápornou hodnotu | Zadejte kladnou částku a zvolte směr zvýšení nebo snížení. |
| Důvod úpravy je povinný | Prázdný důvod | Doplňte, čím je rozdíl doložen. |
| Cenová odchylka se nezobrazuje | Chybí aspoň dva srovnatelné prodeje nespojeným osobám, nebo je množství 1 či jednotka nepodporovaná | Cenu obvyklou doložte jinak ([§ 71.7.2](#7172-meritelne-cenove-odchylky)). |
| Nelze založit nebo smazat úpravu | Chybí právo dokončovat daňové sestavy | Požádejte administrátora o oprávnění. |

## 71.6 Návazné kontroly

Měsíční kontrola vždy informativně vypíše počet transakcí se spojenými osobami. Zároveň varuje, pokud
existuje alespoň jedna měřitelná cenová odchylka. U účetní jednotky s auditorským rozsahem je samostatné
zveřejnění transakcí se spřízněnými stranami také jednou z ručně doplňovaných částí přílohy účetní závěrky.

## 71.7 Podrobnosti a pravidla

### 71.7.1 Soupis transakcí

Po výběru roku se načtou vydané i přijaté doklady partnerů označených jako spojené:

- vydané doklady typu faktura, dobropis nebo daňový doklad, které nejsou konceptem ani stornem, podle data
  zdanitelného plnění,
- přijaté faktury, účtenky, dobropisy a daňové doklady, které nejsou konceptem ani stornem, podle efektivního
  data nákladu.

Proformy a přijaté zálohové doklady nejsou zdanitelným plněním a do tohoto soupisu nevstupují. Tabulka
ukazuje směr, partnera, typ vztahu, doklad, datum a částku bez DPH. Celkem je prostý součet vydaných
i přijatých částek; nejde o jejich vzájemné netto.

### 71.7.2 Měřitelné cenové odchylky

Obecnou cenu obvyklou aplikace nezná. Umí porovnat jen vydanou položku spojené osobě s vlastními prodeji
stejné položky nespojeným osobám ve zvoleném roce.

Srovnání používá tato pravidla:

1. popis položky se normalizuje bez ohledu na velikost písmen a vícenásobné mezery,
2. jednotková cena bez DPH i množství musí být kladné a množství musí být větší než 1,
3. jednotka musí být z podporované množiny kusových, časových, hmotnostních, délkových, plošných,
   objemových nebo litrových jednotek,
4. musí existovat alespoň dva srovnatelné prodeje nespojeným osobám,
5. referenční cena je **medián**, nikoli průměr,
6. odchylka se zobrazí až od absolutní hodnoty 20 %.

Vzorec je:

`odchylka % = (cena spojené osobě − medián nespojených) / medián × 100`

Medián omezuje vliv jednorázového výprodeje. Položka s množstvím 1 nebo bez jednotky se nepovažuje za
spolehlivě srovnatelnou, protože často představuje měsíční paušál či celý souhrn práce.

Když srovnatelný vzorek chybí, aplikace odchylku netvrdí; transakce zůstane v soupisu a cenu obvyklou musí
doložit účetní například benchmarkem, posudkem nebo obchodním důvodem. Výsledek je upozornění, nikoli
automatická změna DPH nebo účetnictví.

### 71.7.3 Úpravy základu daně

Úprava eviduje pro fiskální rok směr zvýšení nebo snížení, kladnou částku, povinný důvod a volitelně
konkrétního partnera (rozhraní API partnera podporuje, formulář stránky jej běžně nepředává). Souhrn počítá:

`Čistá úprava = Σ zvýšení − Σ snížení`

Evidence je vedena podle protistrany a důvodu, aby šla při kontrole obhájit.

> [!WARNING]
> Výstup je podklad, nikoli automatické daňové posouzení. Příznak partnera, srovnání cen i ruční úpravu musí
> před podáním posoudit účetní nebo daňový poradce. Aplikace nezná nárok protistrany na odpočet DPH, tržní
> okolnosti ani úplnou právní definici vztahu.

## 71.8 Související kapitoly

- [Měsíční kontrola](62_Mesicni_kontrola.md)
- [Daň z příjmů](43_Dan_z_prijmu.md)
