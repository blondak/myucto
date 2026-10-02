# 12. Tržby (detailní statistiky vydaných faktur)

**Cesta: `Grafy → Tržby`** (nebo klik na KPI kartu Tržby v [Zisku](11_Zisk.md))

Stránka **Tržby** rozpadá příjmovou stranu firmy do KPI dlaždic, grafů a tabulek —
hloubkový pohled jen na **vydané faktury**. Nahoře je štítek **plátce / neplátce
DPH**, který určuje, jestli se obraty počítají **bez DPH** (plátce) nebo **s DPH**
(neplátce).

Pod fakturačními ukazateli je samostatná karta výsledkového dopadu
[ostatních pohledávek a závazků](50_Pruvodce_ucetniho.md#ostatni-pohledavky-a-zavazky).
Výnos se zde počítá jen u položky s výnosovým protiúčtem; rozvahové položky
ani samotné inkaso tržbu nezvyšují.

![Tržby — KPI dlaždice, měsíční obrat, top klienti, aging, predikce](img/23_trzby.webp)

## 12.1 KPI dlaždice

- **Plovoucí 12měsíční obrat** (rolling) per měna — meziroční srovnání (▲/▼ vs. předchozích 12 měsíců). Informativní obchodní ukazatel, nezávislý na kalendářním roce.
- **Obrat pro registraci DPH** (jen u neplátce) — kolik z limitu **2 000 000 Kč** za kalendářní rok už máš naplněno (progress bar). Varování při překročení 2 000 000 Kč → plátce od 1. 1. dalšího roku, resp. 2 536 500 Kč → plátce ze zákona ihned.
- **Paušální daň** (pokud je relevantní) — naplnění stropu zvoleného pásma paušální daně (§ 7a ZDP).
- **Obrat tento / minulý rok** per měna — s meziroční změnou, počty faktur, klientů a zakázek.
- **Predikce roku** per měna — medián tří odhadů (run-rate, krátkodobý růst, dlouhodobý trend) + rozsah low–high.
- **Vystaveno YTD** — počet vydaných faktur tento rok.
- **Aktivních klientů**, **Ø doba úhrady**, **obrat posledních 30 dní**, **aktivní pravidelné fakturace**.

## 12.2 Grafy a tabulky

- **Měsíční obrat** (bar) za posledních 12 měsíců + linka loňského roku, per měna
- **Kumulativní obrat YTD** vs. loni (do stejného dne v roce)
- **Tržby podle kategorie** (koláč, rolling 12 m, přepočet na CZK) — kategorii tržby vybíráš na faktuře (viz [Tržby podle kategorií](11_Zisk.md#1119-trzby-podle-kategorii) na stránce Zisk)
- **Top klienti** a **Top zakázky** — koláče za letošek a loni, plus tabulky za rolling 12 měsíců
- **Stav faktur** a **stav zakázek** (donut)
- **Závislost na klientech** (concentration risk) — podíl obratu TOP 3 / TOP 5 klientů za rolling 12 měsíců + barevný indikátor rizika
- **Doba úhrady — distribuce** (histogram), **Rozpad obratu podle sazby DPH** (jen plátce)
- **Cash-flow YTD** — kumulativní křivka skutečně inkasovaných plateb (paid_at)
- **Aging** — stáří neuhrazených pohledávek (aktuální / 1–30 / 31–60 / 61–90 / 90+ dní)
- **Distribuce velikosti faktur** (12 m, přepočet na CZK)

> [!TIP]
> Pro **souhrnný** pohled na tržby i náklady vedle sebe (zisk, marže, zdraví firmy)
> použij [Zisk](11_Zisk.md). Tato kapitola je čistě o příjmové straně.

## 12.3 Všechny firmy

Poslední položka **Grafy > Všechny firmy** se zobrazuje při přístupu k více firmám a s oprávněním k manažerskému pohledu. Souhrn zahrnuje přístupné firmy; jednotlivé části respektují oprávnění v každé firmě.

- **Přehled**: dokladové tržby, náklady a zisk za zvolené období se srovnáním proti stejným datům loňského roku, srovnání firem a samostatný účetní výsledek aktuálního období.
- **Vývoj tržeb a zisku**: měsíční tržby, náklady a zisk za zvolené období, souhrn nebo vybraná firma. Krajní měsíce zahrnují jen zvolené dny; měsíce bez dokladové aktivity mají nulové hodnoty.
- **Predikce**: odhad tržeb, nákladů a zisku běžného kalendářního roku. Používá stejné fakturové modely jako firemní grafy a samostatně přidává známý dopad ostatních položek na výsledek podle nákladových a výnosových účtů. Rozpětí představuje rozdíl modelů tržeb, nikoli interval spolehlivosti. Predikce je nezávislá na období historických grafů.
- **Cashflow**: očekávané příjmy a výdaje po týdnech na 4 až 12 týdnů. Kumulovaný čistý tok začíná nulou a není předpovědí zůstatku účtu. Daňové a mzdové závazky se zahrnují podle oprávnění; jejich vynechání je uvedeno v přehledu.
- **Peníze**: bankovní účty a pokladny v původních měnách nebo společně v CZK. Banka ukazuje poslední importovaný nebo evidovaný zůstatek včetně jeho data; pokladny jsou k dnešnímu dni.
- **Pohledávky a závazky**: stáří neuhrazených dokladů před splatností a po splatnosti, souhrn i rozpad po firmách.
- **Rizika**: pohledávky a závazky po splatnosti, dokladová ztráta a závislost na největším odběrateli nebo dodavateli.

Výchozí období zahrnuje **posledních 12 kalendářních měsíců do dneška**. Pole **Od / Do** a tlačítko **Použít období** umožňují přesný rozsah do 36 kalendářních měsíců. Tlačítko **Posledních 12 měsíců** obnoví výchozí rozsah. Zůstatky a stáří pohledávek jsou aktuální; cashflow má vlastní počet týdnů. Koncentrace odběratelů a dodavatelů používá vlastní měsíční okno, uvedené v záložce Rizika.

Výchozí měnová volba je **Vše · CZK**. Jednotlivé měny lze zobrazit samostatně. Dokladové výsledky používají evidované kurzy dokladů; pokladny jejich evidovaný přepočet. Bankovní zůstatky, pohledávky, závazky a predikce se přepočítávají posledním dostupným kurzem nejpozději k datu přehledu. Chybějící kurzy se nevydávají za kurz 1 a součet je označen jako neúplný.

Volba **Spojené osoby** na záložkách Přehled, Vývoj tržeb a zisku, Pohledávky a závazky a Rizika určuje, zda se započítají doklady s kontakty označenými jako spojená osoba. Volba **Nezapočítat** je vyřadí z tržeb, nákladů, pohledávek i závazků, takže převody mezi firmami skupiny nenafukují součet. Kontakt se jako spojená osoba označuje v jeho detailu. Účetní výsledek aktuálního období, cashflow a koncentrace odběratelů se volbou nemění. Volba zůstává v adrese stránky.

Jde o **manažerský součet bez eliminace transakcí mezi firmami**, nikoli o konsolidovanou účetní závěrku. Účetní součty spojují pouze stejnou měnu a stejný začátek i konec období. Dokladové částky jsou u plátců bez DPH, u neplátců včetně DPH; základ je uveden u každé firmy.

**Detail** u rizika přepne firmu včetně nového načtení oprávnění. U pohledávek a závazků otevře doklady po splatnosti v původní měně bez omezení na rok; seznam lze dále filtrovat. U dokladové ztráty zachová přesné období a nabídne zdrojové faktury. Koncentrace otevře odpovídající část firemního zisku v původní měně.

Cashflow zahrnuje ostatní položky i jejich splátkové kalendáře a odečítá již zaplacené splátky. Splátky jistiny úvěru ovlivňují peníze; samy o sobě nejsou náklad. Pravidelné rozvrhy se zahrnou až po vytvoření jednotlivých položek.

Chybějící oprávnění, chyby výpočtu a chybějící účetní období mají vlastní označení. Neznámý zůstatek se nezobrazuje jako nula. Částečné součty uvádějí počet zahrnutých firem a chybějících příspěvků. Záložky načítají data až při otevření; **Obnovit** načte aktuální záložku znovu.
