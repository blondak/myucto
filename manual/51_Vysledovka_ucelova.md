# 51. Výsledovka — účelová

**Cesta: `Účetnictví → Výsledovka — účelová`**

Účelová výsledovka člení provozní náklady podle funkce, které slouží:
**náklady prodeje**, **odbytové náklady** a **správní režie**. Odpovídá
příloze č. 2 části II vyhlášky č. 500/2002 Sb.

## 51.1 Proč je nutná firemní mapa

Číslo nákladového účtu funkci samo neurčuje. Účet 518 může obsahovat službu
spojenou s výrobou, odbytem i správou. Proto systém spojuje:

- globální verzovanou mapu tržeb, ostatních provozních výnosů/nákladů,
  finanční části, daně a převodu výsledku,
- mapu konkrétní firmy pro řádky A. náklady prodeje, B. odbytové náklady a
  C. správní režie.

Firemní mapu lze zadat pro syntetiku, například `518`, nebo přesnější
analytiku, například `518.100`. Vždy vyhrává nejdelší prefix. Chcete-li jeden
druh nákladu rozdělit mezi funkce, založte pro funkce samostatné analytiky;
jediný účet nelze rozdělit procentem.

Seznam **Nepřiřazené účty** obsahuje zaúčtované nákladové účty s nenulovým
obratem, které nepokrývá globální ani firemní mapa. Přiřazení může měnit
uživatel s právem zápisu do účetnictví.

> ⚠️ **Neúplná mapa sestavení zablokuje.** Dokud zůstává nepřiřazený
> nákladový účet s obratem, výkaz ani export nevznikne. Tiché vynechání by
> nadhodnotilo hrubý zisk i výsledek hospodaření.

## 51.2 Zdroj, znaménka a společné mapování

Výkaz čte zaúčtované zůstatky od začátku období do data **Sestaveno k**.
Výnosy vstupují jako `Dal − MD`, náklady jako `MD − Dal`. Závěrkový převod
se vylučuje. Minulé období se počítá se stejnou verzí mapy; firemní funkční
mapa se uplatní i na srovnávací sloupec.

Globální část vychází z ověřené druhové mapy:

- 601, 602 a 604 se slučují do I. Tržby z prodeje výrobků, zboží a služeb,
- druhové řádky III. se slučují do II. Ostatní provozní výnosy,
- druhové řádky F. do D. Ostatní provozní náklady,
- finanční výnosy, náklady, daň a převod výsledku se překódují do řádků
  III.–K. účelového výkazu.

Řádky A./B./C. dostanou pouze účty z firemní mapy. Rozbalovací mapa nad
výkazem ukazuje aktivní prefixy a umožňuje jejich odstranění.

## 51.3 Přesné vzorce

```text
Hrubý zisk nebo ztráta = I. Tržby − A. Náklady prodeje

Provozní VH =
    Hrubý zisk
  − B. Odbytové náklady
  − C. Správní režie
  + II. Ostatní provozní výnosy
  − D. Ostatní provozní náklady

Finanční VH =
    III. − E.
  + IV.  − F.
  + V.   − G. − H.
  + VI.  − I. Ostatní finanční náklady

VH před zdaněním = Provozní VH + Finanční VH
VH po zdanění    = VH před zdaněním − J. Daň z příjmů
VH za období     = VH po zdanění − K. Převod podílu na VH společníkům

Čistý obrat = I. + II. + III. + IV. + V. + VI.
```

Výsledek za období se kontroluje proti nezávislému součtu všech výnosových a
nákladových účtů. Testovaná vazba vyžaduje, aby účelová a
[druhová výsledovka](50_Vysledovka_druhova.md) daly stejný celkový výsledek;
liší se jen členěním provozních nákladů.

## 51.4 Rozsah, zobrazení a export

Aktuální stránka nabízí výběr období. Datum sestavení ponechává prázdné, takže
backend použije dřívější z posledního dne období a dneška; rozsah ponechává na
**Automaticky** podle kategorie účetní jednotky. API podporuje i výslovné
datum a plný/malý/mikro rozsah stejně jako druhový výkaz. Malý i mikro rozsah
zobrazí nejvyšší úroveň.

Obrazovka ukazuje částky v Kč a běžné/minulé období. PDF a XLSX se zpřístupní
až po úspěšném sestavení úplně namapovaného výkazu a přebírají stejné
backendové datum a automatický rozsah jako zobrazení.

> 🛈 Při změně účtového rozvrhu nebo zavedení nové nákladové analytiky znovu
> zkontrolujte seznam nepřiřazených účtů. Mapa je rozhodnutí účetní jednotky,
> nikoli automatický odhad systému.
