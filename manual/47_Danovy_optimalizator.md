# 47. Daňový optimalizátor (OSVČ)

> Návod, jak si v MyÚčtu orientačně porovnat paušální daň a standardní režim
> a jak hlídat roční limity během roku. Pro osoby samostatně výdělečně činné.

> [!WARNING]
> Jde o orientační scénářový model, ne o výpočet přiznání. Používá zjednodušený
> klientský výpočet a nepokrývá všechny vstupy, kontroly ani zaokrouhlení
> finálního DPFO. Pro podání použijte [Daň z příjmů](43_Dan_z_prijmu.md).

## 47.1 Kdy to potřebujete

Kapitolu otevřete, když:

- se rozhodujete, zda zůstat v paušální dani, nebo přejít na standardní režim,
- chcete vědět, kolik vám po odvodech zhruba zbude,
- během roku hlídáte, zda nepřekročíte limit pro DPH nebo pásmo paušální daně,
- jste ve vedlejší činnosti a zajímá vás, zda vznikne povinnost platit sociální
  pojištění,
- se změnila měsíční záloha paušální daně a chcete vědět, zda vám vznikl
  přeplatek.

<!-- cols: 24 44 32 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| na začátku roku | Porovnat režimy podle loňských příjmů, [§ 47.3](#473-krok-za-krokem-porovnani-rezimu-za-minuly-rok) | `Daně → Daňový optimalizátor`, rok s označením retrospektiva |
| průběžně během roku | Zkontrolovat projekci příjmů vůči limitům, [§ 47.4](#474-krok-za-krokem-hlidani-limitu-v-bezicim-roce) | stejná stránka, rok s označením predikce |
| před podáním přiznání | Porovnat výsledek s náhledem finálního DPFO, [§ 47.5](#475-krok-za-krokem-overeni-proti-priznani) | `Daně → Daň z příjmů` |
| při změně sazby paušální daně | Upravit rozvrh záloh, [§ 47.6](#476-krok-za-krokem-zmena-mesicni-zalohy-pausalni-dane) | `Systém → Daňové konstanty` |

## 47.2 Než začnete

1. **Firma vedená jako fyzická osoba.** Položka `Daně → Daňový optimalizátor`
   se zobrazí jen OSVČ a jen s komerčními funkcemi.
2. **Evidované příjmy.** Optimalizátor čte zaplacené vydané faktury (případně
   příjmy z daňové evidence). Faktury proto mějte označené jako uhrazené
   s datem úhrady.
3. **Znalost vlastní situace.** Budete zadávat typ činnosti, pásmo paušální
   daně, vedlejší činnost, slevy na manžela a děti, úroky z hypotéky
   a penzijní připojištění.
4. **Správce pro úpravu konstant.** Roční sazby, minima, limity a rozvrh záloh
   paušální daně mění administrátor v `Systém → Daňové konstanty`.

## 47.3 Krok za krokem: porovnání režimů za minulý rok

1. Otevřete `Daně → Daňový optimalizátor`.
2. Nahoře zvolte rok označený jako retrospektiva.
3. V části **Daňový profil** zvolte **Typ činnosti (výdajový paušál)**.
4. V poli **Výdaje** zvolte **Paušál %**, nebo **Skutečné** a zadejte roční
   výdaje v Kč.
5. Zvolte **Pásmo paušální daně**, případně **Nejsem v paušálu**.
6. Zaškrtněte **Vedlejší činnost**, uplatňujete-li ji celý rok.
7. Podle potřeby vyplňte **Sleva na manželku/manžela**, **Počet dětí**,
   **Úroky z hypotéky / rok** a **Penzijní připojištění / rok**.
8. Klikněte na **Uložit profil**.
9. Porovnejte karty **Paušální daň** a **Běžný režim**. Lepší varianta nese
   štítek **Nejlepší** a pod kartami je věta s odhadem úspory.
10. Projděte rozpad běžného režimu: příjmy, výdaje, odpočty, základ daně,
    daň po slevách, sociální a zdravotní pojištění, čistý příjem a efektivní
    sazba odvodů.

**Jak poznáte, že je hotovo:** Profil je uložený (**Daňový profil uložen.**)
a vidíte verdikt, který režim je pro vás výhodnější, včetně čistého příjmu.

> [!TIP]
> Přepínejte jen skutečně možné varianty. Rozdíl mezi režimy porovnávejte
> vždy s výsledkem finálního DPFO ([§ 47.5](#475-krok-za-krokem-overeni-proti-priznani)).

## 47.4 Krok za krokem: hlídání limitů v běžícím roce

1. Otevřete `Daně → Daňový optimalizátor` a zvolte aktuální rok s označením
   predikce.
2. Přečtěte karty **Příjem letos**, **Tempo / měsíc** a **Projekce na rok**.
3. V části **Teploměr k limitům** zkontrolujte, zda a kdy projekce překročí
   strop zvoleného pásma, limit DPH / paušálu nebo okamžitého plátce DPH.
4. Jste-li ve vedlejší činnosti, zkontrolujte řádek s limitem pro sociální
   pojištění. Měří se proti zisku, ne proti příjmu.
5. Je-li překročení limitu blízko, ověřte datum skutečné úhrady, strukturu
   činností a podmínky režimu s účetní.

**Jak poznáte, že je hotovo:** U každého limitu vidíte buď „letos nepřekročíš“,
nebo měsíc, ve kterém projekce limit překročí.

## 47.5 Krok za krokem: ověření proti přiznání

1. Rozpad příjem, výdaje, základ, daň a pojistné použijte pro citlivostní
   analýzu.
2. Otevřete `Daně → Daň z příjmů` a porovnejte výsledek s náhledem finálního
   DPFO.
3. Rozdíly vysvětlete podle seznamu v
   [§ 47.8.4](#4784-profil-a-co-skutecne-modeluje).
4. Při blízkosti limitu ověřte podmínky režimu s účetní.

**Jak poznáte, že je hotovo:** Víte, které rozdíly mezi optimalizátorem
a DPFO jsou způsobené zjednodušením modelu a které jsou skutečná chyba v datech.

## 47.6 Krok za krokem: změna měsíční zálohy paušální daně

Postup je určený administrátorovi.

1. Otevřete `Systém → Daňové konstanty`.
2. Otevřete záložku **OSVČ a pojistné**. V sekci **Paušální daň (měsíční
   zálohy)** klikněte na **+ přidat změnu sazby**. Vznikne další období.
3. Zvolte měsíc, od kterého nová záloha platí, a zadejte výši zálohy pro
   jednotlivá pásma.
4. Klikněte na **Uložit**.
5. V `Daně → Daňový optimalizátor` zkontrolujte kartu paušální daně. Ukazuje
   zálohu po obdobích a roční částku jako jejich součet.

**Jak poznáte, že je hotovo:** Karta paušální daně ukazuje všechna období
záloh a při snížení sazby upozornění s přeplatkem a částkou, o kterou lze
snížit nejbližší zálohu.

## 47.7 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Položka `Daňový optimalizátor` v menu chybí | Firma není vedená jako OSVČ, nebo chybí komerční funkce | Ověřte typ firmy a licenci. |
| Příjem je nižší, než čekáte | Počítají se jen zaplacené vydané faktury s datem úhrady v roce | Zkontrolujte, zda jsou faktury označené jako uhrazené a mají datum úhrady. |
| „V paušálu nejsi / nelze – počítá se běžný režim.“ | Jste plátce DPH, příjem přesáhl strop, nebo jste zvolili **Nejsem v paušálu** | Zkontrolujte pásmo a stav plátcovství. |
| U karty paušální daně je „plátce DPH → nelze“ nebo „příjem > limit → nelze“ | Paušální daň je při těchto podmínkách nedostupná | Porovnávejte jen běžný režim. |
| Optimalizátor se liší od finálního DPFO | Je zjednodušený (viz [§ 47.8.4](#4784-profil-a-co-skutecne-modeluje)) | Pro podání vždy použijte `Daně → Daň z příjmů`. |
| Záloha paušální daně neodpovídá vašemu rozvrhu | Rozvrh záloh se mění v daňových konstantách | Požádejte administrátora o úpravu podle [§ 47.6](#476-krok-za-krokem-zmena-mesicni-zalohy-pausalni-dane). |

## 47.8 Podrobnosti a pravidla

### 47.8.1 Zdroj příjmů

Pro minulý rok se standardně používají zaplacené vydané faktury s datem úhrady
v roce, po přepočtu kurzem dokladu. U plátce se bere částka bez DPH, u neplátce
brutto; doklady označené jako příjem mimo základ se zobrazí odděleně. Pokud se
optimalizátor spustí nad daňovou evidencí s volbou evidence, může místo toho
použít daňové příjmy a výdaje peněžního deníku.

Pro běžící rok se sčítají měsíční příjmy a dosavadní tempo se lineárně
promítne do konce roku. Jde o odhad, nikoli časové rozlišení smluv, sezónnosti
či budoucích plateb. U daňové evidence rozhoduje inkaso, takže vystavení
prosincové faktury samo neurčuje, do kterého roku příjem vstoupí.

### 47.8.2 Porovnávané scénáře

Retrospektiva porovná:

- paušální daň ve zvoleném pásmu,
- standardní režim s jednou zvolenou sazbou výdajového paušálu nebo ručně
  zadanými skutečnými výdaji,
- orientační daň, sociální a zdravotní pojistné a čistý příjem.

Predikce sleduje roční limity z daňových konstant: pásmo paušální daně,
hranici registrace DPH a u vedlejší činnosti rozhodnou částku sociálního
pojištění. Limity se mohou v průběhu času měnit a některé se posuzují odlišně
od prostého ročního součtu; upozornění je proto signálem ke kontrole, nikoli
právním rozhodnutím.

### 47.8.3 Změna měsíční zálohy uprostřed roku

Záloha paušální daně se může změnit i během roku (2026 klesá 1. pásmo od
1. července z 9 984 na 9 162 Kč). Karta paušálu proto ukazuje **zálohu po
obdobích**, ne průměr, a roční částka je jejich součtem (2026 v 1. pásmu
114 876 Kč). Při snížení sazby navíc přibude upozornění s **přeplatkem**
za měsíce zaplacené vyšší zálohou a s částkou, na kterou lze **snížit
nejbližší zálohu**. Alternativou je požádat o vrácení až po skončení roku.

Rozvrh záloh je editovatelný v
[Systém → Daňové konstanty](100_Danove_konstanty.md) (záložka **OSVČ
a pojistné**, sekce *Paušální daň*): tlačítkem **+ přidat změnu sazby** vznikne další období od zvoleného měsíce.
Roční částka se z rozvrhu dopočítá, needituje se.

### 47.8.4 Profil a co skutečně modeluje

Profil se ukládá po jednotlivých letech. Obsahuje jednu hlavní sazbu činnosti,
volbu paušálních nebo skutečných výdajů, pásmo paušální daně, celoroční
příznak vedlejší činnosti, jednoduchý nárok na manžela/manželku a počet dětí,
úroky z bytové potřeby a vybrané penzijní či pojistné odpočty.

Optimalizátor nepracuje s podrobným seznamem činností, měsíci dětí
a manžela/manželky, pořadím a ZTP/P dětí, měsíčním průběhem hlavní/vedlejší
činnosti, invaliditou, DIP, dlouhodobou péčí, § 6, § 8 až § 10, ztrátami,
zaplacenými zálohami ani přechodovými úpravami. Neprovádí ani finální kontrolu
důkazních podkladů.

Proto se může lišit od DPFO například takto:

- finální DPFO uplatní strop výdajového paušálu jednou pro všechny činnosti se
  stejnou sazbou; optimalizátor používá jedinou sazbu,
- DPFO krátí některé odpočty podle měsíců a provádí zákonná zaokrouhlení na
  stokoruny a celé koruny; optimalizátor pracuje průběžně s desetinnými
  částkami,
- finální pojistné respektuje podrobnější měsíční data a přesto má omezení
  popsaná v kapitole
  [Pojistné OSVČ](43_Dan_z_prijmu.md#431116-pojistne-osvc-dulezita-omezeni),
- paušální daň má i nečíselné podmínky vstupu, které samotné porovnání nákladů
  neověří.

### 47.8.5 Jak výsledek používat

Rozpad příjem, výdaje, základ, daň a pojistné použijte pro citlivostní
analýzu. Přepínejte pouze skutečně možné varianty a porovnejte výsledek
s náhledem finálního DPFO. Při blízkosti limitu ověřte datum skutečné úhrady,
strukturu činností a podmínky režimu s účetní. Aplikace neslouží
k doporučení účelového posouvání faktur nebo plateb.

### 47.8.6 Daňové konstanty

Roční sazby, minima a limity spravuje admin v
[Systém → Daňové konstanty](100_Danove_konstanty.md). Výchozí hodnoty jsou
verzované podle roku, ale jejich existence nepotvrzuje, že na konkrétní situaci
dopadá obecné pravidlo bez výjimky. Změna konstanty ovlivní budoucí přepočet;
sama nemění již uložený finální snapshot DPFO.

## 47.9 Související kapitoly

- [Daň z příjmů](43_Dan_z_prijmu.md) - finální přiznání DPFO a pojistné OSVČ
- [Daňové konstanty](100_Danove_konstanty.md) - sazby, limity a rozvrh záloh
