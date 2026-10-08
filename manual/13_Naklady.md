# 13. Náklady

> Návod, jak z přijatých faktur zjistit, kolik firma utrácí, komu a kolik
> ještě dluží. Pro podnikatele a účetní. Zrcadlový protějšek kapitoly [Tržby](12_Trzby.md).

**Cesta: `Grafy → Náklady`** (nebo klik na KPI kartu Náklady ve [Zisku](11_Zisk.md))

## 13.1 Kdy to potřebujete

Kapitolu otevřete, když:

- chcete vědět, kolik firma za rok a za posledních 12 měsíců utratila,
- potřebujete seznam největších dodavatelů,
- zjišťujete, kolik ještě dlužíte dodavatelům a kolik z toho je po splatnosti,
- plánujete platby: kolik a kdy bude potřeba zaplatit,
- chcete odhad nákladů do konce roku,
- hlídáte závislost na jednom dodavateli.

## 13.2 Než začnete

- Evidujte přijaté faktury v aplikaci. Stránka počítá jen z **přijatých faktur**.
- U **plátce DPH** se náklady počítají **bez DPH** (na vstupu se odečte), u neplátce **s DPH**.
- Přiřazujte **kategorie nákladů** v editoru přijaté faktury. Bez nich se rozpad
  „Náklady podle kategorií“ smrskne na jediný řádek **Bez kategorie**.

## 13.3 Krok za krokem: zjistit náklady a závazky

1. Otevřete `Grafy → Náklady`.
2. V dlaždicích zkontrolujte **Plovoucí 12měsíční náklady** a meziroční srovnání.
3. Dlaždice **Nezaplacené závazky** ukazuje, kolik čeká na úhradu dodavatelům a kolik z toho je po splatnosti.
4. V grafu **Náklady za posledních 12 měsíců** projděte vývoj a loňskou linku.
5. V tabulce **Top dodavatelé - posledních 12 měsíců** najděte největší dodavatele.
6. V boxu **Plán plateb dodavatelům** zjistěte, kolik je splatné do 30, 60 a 90 dní.
7. V grafu **Aging - stáří závazků** zkontrolujte stáří neuhrazených přijatých faktur.

**Jak poznáte, že je hotovo:** znáte výši nákladů, největší dodavatele a platební povinnosti v čase.

> [!TIP]
> Souhrnný pohled tržby versus náklady nabízí [Zisk](11_Zisk.md).

## 13.4 Když něco nejde

<!-- cols: 34 33 33 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Rozpad podle kategorií je jediný řádek **Bez kategorie** | Přijaté faktury nemají kategorii nákladů | Doplňte kategorii v editoru přijaté faktury |
| Doklad se mezi nezaplacenými závazky nezobrazuje | Je plně vyrovnaný bankovní úhradou, zápočtem nebo zápočtem proti účtu | Je to správně, viz [Podrobnosti](#135-podrobnosti-a-pravidla) |
| Chybí graf **Nárok na odpočet DPH podle sazby** | Jste neplátce | Graf je jen pro plátce DPH |

## 13.5 Podrobnosti a pravidla

### 13.5.1 KPI dlaždice

- **Plovoucí 12měsíční náklady** po měnách s meziročním srovnáním.
- **Náklady tento / minulý rok** po měnách, s počty přijatých faktur a dodavatelů.
- **Náklady RRRR celkem v CZK** (při nákupech ve více měnách) - součet všech měn přepočtený kurzem dokladu.
- **Odhad nákladů roku** po měnách - sezonalita loňska krát meziroční změna.
- **Přijato YTD** - počet přijatých faktur tento rok.
- **Aktivních dodavatelů**, **Ø doba úhrady dodavatelům**, **náklady posledních 30 dní**.
- **Nezaplacené závazky** - kolik čeká na úhradu dodavatelům, z toho kolik po splatnosti.

### 13.5.2 Grafy a tabulky

- **Náklady za posledních 12 měsíců** s loňskou linkou a **Kumulativní platby dodavatelům YTD** (skutečně zaplaceno podle data úhrady).
- **Náklady po rocích** a **Náklady po měsících (12)** (tabulky).
- **Top dodavatelé** za letošek a za posledních 12 měsíců.
- **Náklady podle kategorií** (12 měsíců) - vyžaduje přiřazené [kategorie nákladů](11_Zisk.md#1168-naklady-a-trzby-podle-kategorii) na přijatých fakturách.
- **Nárok na odpočet DPH podle sazby** (jen plátce).
- **Závislost na dodavatelích** - podíl nákladů TOP 3 / TOP 5 dodavatelů a indikátor rizika.
- **Doba úhrady dodavatelům - distribuce** (histogram).
- **Plán plateb dodavatelům** - splatné závazky v příštích 30, 60 a 90 dnech (kumulativně).
- **Aging - stáří závazků** - stáří neuhrazených přijatých faktur (aktuální, 1-30, 31-60, 61-90, 90+ dní po splatnosti).
- **Distribuce velikosti přijatých faktur** (12 měsíců).

### 13.5.3 Jak se počítají závazky

Závazkové přehledy, aging a odhad budoucích plateb pracují se zbytkem po odečtení
bankovních úhrad, vzájemných zápočtů a zápočtů proti účtu. Plně vyrovnaný doklad se
proto mezi nezaplacenými závazky nezobrazuje.

### 13.5.4 Ostatní pohledávky a závazky

Samostatná karta výsledkového dopadu
[ostatních pohledávek a závazků](50_Pruvodce_ucetniho.md#50104-ostatni-pohledavky-a-zavazky)
ukazuje náklady jen podle nákladového protiúčtu. Splátka jistiny či vrácená kauce jsou
peněžní pohyby, které samy náklad nevytvářejí.

## 13.6 Související kapitoly

- [Zisk](11_Zisk.md) - tržby a náklady vedle sebe
- [Tržby](12_Trzby.md) - protějšek pro vydané faktury
- [Průvodce účetního](50_Pruvodce_ucetniho.md#50104-ostatni-pohledavky-a-zavazky) - ostatní pohledávky a závazky
