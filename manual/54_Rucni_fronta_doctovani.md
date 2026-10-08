# 54. K doúčtování

> Společný seznam případů, u kterých ještě chybí dokončení účetní práce: bankovní pohyby bez návrhu kontace, nezaúčtované doklady a otevřené žádosti o podklad. Kapitola je pro účetní, která chce vědět, co zbývá zaúčtovat a kam pro to kliknout.

## 54.1 Kdy to potřebujete

Kapitolu otevřete, když:

- chcete na jednom místě vidět, co ještě není zaúčtované a proč,
- se bankovní pohyb neobjevil v Automatu a přesto není v deníku,
- se blíží uzávěrka měsíce a potřebujete mít hotové všechny doklady,
- čekáte na podklad, který jste vyžádali od klienta.

Stránka je pouze čtecí. Sama nic nezaúčtuje, nespáruje ani neopraví. Každý řádek vede do agendy, ve které se případ skutečně vyřeší.

> [!WARNING]
> Bankovní návrhy patří do [Automatu](53_Automat.md). Čekající návrhy se zde záměrně nezobrazují, a to ani odložené návrhy nebo návrhy ve stavu **Vyžaduje zásah**. Jakmile pro pohyb existuje návrh v jakémkoli stavu, patří do Automatu. K doúčtování odpovídá na otázku, pro co automatika nemá hotový účetní výsledek nebo nevytvořila vůbec žádný návrh.

## 54.2 Než začnete

1. **Podvojné účetnictví.** Stránka je dostupná jen firmě v podvojném účetnictví.
2. **Právo číst účetnictví.** Možnost provést navazující akci se řídí oprávněním cílové agendy. Uživatel pouze pro čtení může frontu a zdroje prohlížet, ale nemůže je zaúčtovat.
3. **Správná firma v hlavní liště.** Fronta ukazuje jen právě zvolenou firmu.

## 54.3 Krok za krokem: projití fronty

1. Otevřete `Účetnictví → K doúčtování`.
2. Podle potřeby zúžte seznam. V první nabídce zvolte typ (**Bankovní pohyb**, **Přijatá faktura**, **Vydaná faktura**, **Vyžádaný doklad**), ve druhé důvod. U každé volby je v závorce počet.
3. Začněte žádostmi o chybějící dokumenty s blízkým nebo prošlým termínem (u řádku je uvedený termín dodání).
4. U řádku **Bankovní pohyb** klikněte na akci **Založit pravidlo / zaúčtovat ručně**, případně u cizí měny na **Zaúčtovat ručně (cizí měna)**. Otevře se detail výpisu. Ověřte, zda nejde o poplatek, daň, pojistné, výplatu, vlastní převod nebo platbu k dokladu. Pohyb připojte k dokladu, spárujte nebo zaúčtujte ručně. Opakovanou operaci pokryjte pravidlem.
5. U řádku **Vydaná faktura** nebo **Přijatá faktura** klikněte na **Zaúčtovat fakturu**. Na detailu zkontrolujte období, DPH a předkontaci a klikněte na **Zaúčtovat**.
6. U řádku **Vyžádaný doklad** klikněte na **Vyřešit požadavek**. Podklad získejte, zkontrolujte a žádost vyřešte.
7. Vraťte se do fronty. Položka zmizí podle skutečného stavu zdroje, nikoli ručním odškrtnutím na této stránce.
8. Nakonec projděte [Úplnost dokladů](61_Uplnost_dokladu.md). Může najít starý bankovní pohyb bez podkladu i v situaci, kdy K doúčtování působí prázdně.

**Jak poznáte, že je hotovo:** Fronta zobrazí text **Žádné položky nečekají na ruční zaúčtování.** a nabídne pokračování na **Bankovní pohyby k zaúčtování** a **Návrhy ke schválení**.

> [!TIP]
> Fronta je seřazená od nejnovějšího data. Na stránce je standardně 50 položek.

## 54.4 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Fronta je prázdná, ale víte o bankovním pohybu, který není zaúčtovaný | K pohybu už pravděpodobně existuje návrh kontace | Otevřete **Bankovní pohyby k zaúčtování** nebo **Návrhy ke schválení** (Automat). |
| Pohyb má důvod **Pro nespárovanou transakci není nastavené pravidlo** | Pro korunový pohyb neexistuje návrh ani pravidlo | Založte pravidlo, nebo pohyb zaúčtujte ručně na detailu výpisu. |
| Pohyb má důvod **Cizoměnová transakce vyžaduje ruční zaúčtování.** | Automatika nepodporuje nespárovaný cizoměnový pohyb | Zaúčtujte ho ručně na detailu výpisu. |
| Provizorní e-mailové avízo ve frontě chybí | Avíza nejsou skutečným pohybem z výpisu a nikdy se neúčtují | Počkejte na oficiální výpis. |
| Zálohová přijatá výzva ve frontě chybí | Nevytváří předpis na 321, účetně se projeví až skutečnou úhradou na 314 | Postupujte podle [Průvodce účetního](50_Pruvodce_ucetniho.md). |
| Doklad zmizel z fronty, ale ne z Automatu | Stejný nezaúčtovaný doklad může být vidět i na kartě **Vyžaduje zásah** v Automatu; jde o dva pracovní pohledy nad stejným stavem | Dokončete zaúčtování na detailu dokladu. |
| Řádek neumím zaúčtovat | Nemáte právo zápisu v cílové agendě | Požádejte správce rolí. |

## 54.5 Podrobnosti a pravidla

### 54.5.1 Co se do fronty zařazuje

#### 54.5.1.1 Bankovní pohyb bez návrhu

Jde o skutečný pohyb importovaný z výpisu, který:

- není ignorovaný,
- nemá aktivní účetní zápis typu banka,
- a nemá žádný návrh bankovní kontace.

Korunový pohyb dostane důvod **Pro nespárovanou transakci není nastavené pravidlo** a akci pro vytvoření
pravidla nebo ruční zaúčtování. Pohyb v jiné měně dostane důvod
**Cizoměnová transakce vyžaduje ruční zaúčtování.** a vede k ručnímu
zaúčtování na detailu výpisu.

Provizorní e-mailová avíza se do fronty nezařazují. Nejsou skutečným pohybem
z výpisu a nikdy se neúčtují.

#### 54.5.1.2 Nezaúčtovaný vydaný doklad

Zařazují se doklady bez data zaúčtování, které nejsou koncept ani stornované a
patří mezi zaúčtovatelné typy:

- faktura,
- dobropis,
- daňový doklad,
- penále.

Řádek vede na detail vydaného dokladu. Tam zkontrolujte položky, DPH, datum
účetního případu a předkontaci a teprve potom použijte **Zaúčtovat**.

#### 54.5.1.3 Nezaúčtovaný přijatý doklad

Zařazují se přijaté doklady bez data zaúčtování, které nejsou koncept ani
stornované. Zálohová výzva se nezobrazuje:
nevytváří předpis na 321, účetně se projeví až skutečnou úhradou na 314 a
následným vyúčtováním.

Částka přijatého dokladu je ve frontě zobrazena záporně, aby byl na první
pohled odlišen výdajový směr.

#### 54.5.1.4 Otevřená žádost o dokument

Zařazuje se žádost ve stavu **Vyžádáno**. Řádek může nést datum případu,
částku, protistranu, vlastní popis a termín dodání. Odkaz vede do přehledu
žádostí o dokumenty. Vyřešená žádost z fronty zmizí.

### 54.5.2 Filtry, pořadí a stránkování

Frontu lze filtrovat podle:

- **typu** - banka bez návrhu, přijatý doklad, vydaný doklad nebo žádost o
  dokument,
- **důvodu** - například bez pravidla, nepodporovaná cizí měna, doklad není
  zaúčtovaný nebo dokument chybí.

Počty u typů a důvodů se počítají z celé aktuální fronty ještě před filtrováním.
Řádky jsou seřazené od nejnovějšího data. Na stránce je standardně 50 položek; server dovoluje nejvýše
200 na stránku.

| Typ řádku | Kam vede | Co udělat |
|---|---|---|
| Banka bez návrhu | Detail bankovního výpisu s filtrem nezaúčtovaných pohybů | Dohledat význam, připojit doklad, spárovat nebo ručně zkontovat; opakovanou operaci lze pokrýt pravidlem. |
| Vydaný doklad | Detail faktury | Ověřit předpis, období a DPH a doklad zaúčtovat. |
| Přijatý doklad | Detail přijaté faktury | Ověřit věcnou a daňovou klasifikaci, předkontaci, období a doklad zaúčtovat. |
| Žádost o dokument | Žádosti o dokumenty | Podklad získat, zkontrolovat a žádost vyřešit; samotné doručení ještě neprokazuje správnou kontaci. |

### 54.5.3 Rozdíl proti Automatu a Úplnosti dokladů

| Stránka | Hlavní otázka | Provádí akce? |
|---|---|---|
| **Automat** | Co systém zaúčtoval, co navrhl a co v návrhu potřebuje rozhodnutí? | Ano - schválení, zamítnutí, úprava kontace, odložení a storno. |
| **K doúčtování** | Který známý případ nemá hotový zápis a není už obsloužen návrhem? | Ne - pouze vede na zdroj. |
| **Úplnost dokladů** | Které starší bankovní pohyby nemají doklad a které otevřené doklady jsou po splatnosti? | Ne - jde o kontrolní sestavu s agingem a saldokontem. |

Stejný nezaúčtovaný doklad může být vidět v K doúčtování i na kartě
**Vyžaduje zásah** v Automatu. Není to dvojí účetní případ; jde o dva různé
pracovní pohledy nad stejným stavem. Bankovní pohyb s čekajícím návrhem je
naopak pouze v Automatu.

### 54.5.4 Oprávnění a hranice kontroly

Stránka je dostupná jen firmě v podvojném účetnictví a vyžaduje právo číst
účetnictví. Její API je tenantově omezené na právě zvolenou firmu. Možnost
provést navazující akci se řídí oprávněním cílové agendy; uživatel pouze pro
čtení může frontu a zdroje prohlížet, ale nemůže je zaúčtovat.

Prázdná fronta znamená jen to, že systém neeviduje žádný známý případ podle
výše uvedených predikátů. Neodhalí fakturu, smlouvu, závazek, majetek, dohad
ani časové rozlišení, které v aplikaci vůbec nemá zdrojová data. Nenahrazuje
inventarizaci, saldokonto ani odborné posouzení účetní.

Prázdný stav nabízí přímé pokračování na **Bankovní pohyby k zaúčtování** a **Návrhy ke schválení** (Automat). Použijte je zejména tehdy, když víte o bankovním pohybu, který ve frontě
není: pravděpodobně už k němu existuje návrh kontace a patří do jedné z těchto agend.

## 54.6 Související kapitoly

- [Automat](53_Automat.md) - bankovní návrhy a schvalování
- [Úplnost dokladů](61_Uplnost_dokladu.md) - starší bankovní pohyby bez dokladu a doklady po splatnosti
- [Průvodce účetního](50_Pruvodce_ucetniho.md) - denní, měsíční a roční postup
- [Účetní deník](52_Ucetni_denik.md) - výsledné zápisy
