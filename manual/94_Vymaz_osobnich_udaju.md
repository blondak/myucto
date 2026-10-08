# 94. Výmaz osobních údajů

> Návod, jak nevratně vymazat nebo anonymizovat osobní údaje zaměstnanců, kterým
> uplynula retenční lhůta. Pro mzdovou účetní a osobu odpovědnou za ochranu
> osobních údajů.

## 94.1 Kdy to potřebujete

- Bývalým zaměstnancům uplynula retenční lhůta a data už nesmíte držet.
- Pravidelně (např. jednou ročně) čistíte mzdovou evidenci.
- Bývalý zaměstnanec žádá o výmaz a lhůta uchování už neběží.

## 94.2 Než začnete

1. **Oprávnění** k výmazu osobních údajů (`payroll.erasure`).
2. **Retenční lhůty** máte projité a víte, koho lze navrhnout (viz
   [Retenční lhůty](93_Retencni_lhuty.md)).
3. **Žádná překážka**: ověřte, že osobu nedrží zákonná, smluvní, účetní ani
   procesní povinnost (kontrola, spor, exekuce). Případně zadejte zadržení
   výmazu.
4. **Schválení** odpovědnou osobou podle pravidel firmy. Výmaz může dokončit
   jedna oprávněná účetní, aplikace kroky zapíše do auditní stopy.

## 94.3 Krok za krokem: výmaz

1. Otevřete `Mzdy → Výmaz osobních údajů`.
2. Zadejte **Sestavit návrh k datu** a klikněte na **Sestavit návrh**.
   Aplikace vezme jen osoby, kterým k tomu dni uplynula lhůta a nic je nedrží.
3. Otevřete návrh a projděte u každé osoby **Rozsah**, **Podle ustanovení**,
   **Dopad** a co **zůstane** ve zmrazeném obsahu.
4. Zkontrolujte firmu, osoby a rozsah. Je-li vše v pořádku, klikněte na
   **Schválit**; jinak na **Zamítnout**.
5. U schváleného návrhu klikněte na **Provést výmaz**.
6. V potvrzení zaškrtněte „Rozumím, že výmaz je nevratný a data nejdou
   obnovit." a opište číslo návrhu.
7. Potvrďte. Ve sloupci **Výsledek** zkontrolujte výsledek u každé osoby.
8. Vymažte nebo anonymizujte i data mimo aplikaci (exportovaná PDF, bankovní
   soubory, externí archiv) podle politiky firmy.

**Jak poznáte, že je hotovo:** Návrh má stav „Provedeno. Položky zůstávají jako
doklad, že výmaz proběhl." a souhrn uvádí, kolik osob se provedlo a kolik se
přeskočilo kvůli zadržení nebo změně podmínek.

> [!WARNING]
> Výmaz je nevratný. Nevytvářejte si nechráněnou kopii „pro jistotu", tím by
> smysl výmazu zanikl.

## 94.4 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Návrh se nesestaví, není koho navrhnout | K zadanému dni nikomu neuplynula lhůta, nebo všechny drží zadržení | Zkontrolujte důvody v [Retenční lhůty](93_Retencni_lhuty.md). |
| **Provést výmaz** nejde | Návrh není schválený, nebo je zamítnutý | Návrh schvalte; zamítnutý sestavte znovu. |
| „Opsané číslo nesouhlasí s otevřeným návrhem" | Překlep v čísle návrhu | Opište číslo přesně. |
| Osoba se při provedení přeskočila | Mezi schválením a provedením dostala zadržení nebo se změnil rozsah | Důvod je ve sloupci **Výsledek**; po vyřešení sestavte nový návrh. |
| Řádek návrhu je bez jména | Osoba je úplně vymazaná | Je to v pořádku, obrazovka to napíše. |

## 94.5 Podrobnosti a pravidla

### 94.5.1 Tři kroky výmazu

Obrazovka `Mzdy → Výmaz osobních údajů` je jediné místo, odkud se mzdová
osobní data mažou. Výmaz je **nevratný**, proto je rozdělený do tří kroků, které
lze zkontrolovat každý zvlášť.

**Sestavit návrh.** Aplikace sestaví návrh **jen** z osob, kterým lhůta
uplynula a nic je nedrží. Když není koho navrhnout, řekne to a nevytvoří nic:
prázdný návrh by se dal schválit a v přehledu by vypadal jako provedený výmaz.
Náhled nic nemaže.

**Schválit nebo zamítnout.** Detail návrhu jmenuje každou osobu a uvádí:

- **Rozsah**: *úplný výmaz* u osoby bez účetní stopy, jinak *anonymizace*;
  účetní záznam zůstane, zmizí z něj jen osobní údaj,
- **Podle ustanovení**: lhůta, o kterou se rozhodnutí opírá, a jak je doložená,
- **Dopad**: kolik řádků osobních dat zmizí, po skupinách,
- **Zbytek**: osobní údaj, který zůstane ve zmrazeném obsahu (vystavená PDF,
  odeslaná XML). Ten se nepřepisuje a návrh to říká předem.

Zamítnutý návrh zůstává v přehledu jako doklad, provést ho už nelze.

**Provést.** Provedení je samostatný krok nad **schváleným** návrhem;
neschválený aplikace odmítne. Potvrzovací dialog vypíše dotčené osoby
a vyžaduje dvojí potvrzení: zaškrtnutí nevratnosti a opsání čísla návrhu.
Každá položka se **před provedením posuzuje znovu**: co mezi schválením
a provedením dostalo zadržení nebo změnilo rozsah, se přeskočí s důvodem
a neprovede podle zastaralého rozhodnutí.

### 94.5.2 Co po výmazu zůstane

Návrh zůstává jako **doklad, že výmaz proběhl**: kdo ho schválil, kdy se
provedl a podle které lhůty se rozhodovalo. V auditní stopě zůstává i jméno
osoby; je to vědomé rozhodnutí, aby šlo doložit, o koho šlo. Samotná osobní data
z evidence zmizí; u úplně vymazané osoby proto zůstane řádek návrhu bez jména.
Dokumenty a poznámky personálního spisu se stanou nečitelnými (viz
[Zaměstnanci](86_Zamestnanci.md#861216-personalni-spis)). Dokončený výmaz
nejde použít k obnovení původního obsahu. Zálohy a externí exporty řešte podle
schválené politiky firmy.

### 94.5.3 Časté chyby

- výmaz jen kvůli žádosti bez kontroly zákonné povinnosti uchování,
- záměna osoby se stejným jménem,
- opomenutí exportovaných PDF, bankovních souborů nebo externího archivu,
- uložení úplných mazaných údajů do auditní poznámky; právní důvod zapisujte
  bez nadbytečných osobních údajů.

## 94.6 Související kapitoly

- [Retenční lhůty](93_Retencni_lhuty.md): vždy nejdřív
- [Zaměstnanci](86_Zamestnanci.md), [Dokumenty a výstupy](83_Dokumenty_a_vystupy.md),
  [Platby a úhrady](82_Platby_a_uhrady.md), [Podání a hlášení](85_Podani_a_hlaseni.md): kam vedou vazby
