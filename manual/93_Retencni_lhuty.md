# 93. Retenční lhůty

> Návod, jak zjistit, jak dlouho se mzdová data uchovávají, jak lhůtu firmy
> prodloužit, jak zadržet výmaz konkrétní osoby a koho lze navrhnout k výmazu.
> Pro mzdové účetní a osobu odpovědnou za ochranu osobních údajů.

## 93.1 Kdy to potřebujete

- Chcete vědět, jak dlouho musíte uchovávat mzdové listy, podání nebo
  dokumenty bývalých zaměstnanců a podle jakého ustanovení.
- Firmu váže smlouva nebo vnitřní předpis s delší lhůtou.
- Probíhá daňová kontrola, spor, exekuce nebo insolvence a data osoby se nesmí
  smazat.
- Připravujete výmaz osobních údajů a potřebujete vědět, koho lze navrhnout.

## 93.2 Než začnete

1. **Oprávnění** k retenčním lhůtám (`payroll.retention`).
2. **Schválená pravidla uchování** firmy, která zohledňují zákonné povinnosti,
   právní nároky, probíhající řízení a oprávněné potřeby firmy.

Odsud se nic nemaže. Uplynulá lhůta je konec povinnosti uchovávat, ne příkaz ke
skartaci. Výmaz má vlastní obrazovku, viz
[Výmaz osobních údajů](94_Vymaz_osobnich_udaju.md).

## 93.3 Krok za krokem: kontrola lhůt

1. Otevřete `Mzdy → Retenční lhůty` (nadpis stránky je **Retenční lhůty
   mzdové agendy**).
2. Nad tabulkou projděte dlaždice původu lhůty: **Ze zákona**, **Dodaná
   politika**, **Bez lhůty**.
3. V tabulce zkontrolujte u kategorie **Lhůta**, **Běží od**, **Právní
   pramen**, **Ověřeno** a sloupec **Výmaz**. Hledat můžete podle kategorie,
   zákona, ustanovení nebo tabulky.
4. Ve spodním panelu **Co z lhůt plyne pro výmaz** zvolte **K datu** a projděte,
   kolik osob lze navrhnout k výmazu a proč ostatní ne.

**Jak poznáte, že je hotovo:** U každé kategorie víte, odkud lhůta pochází,
a panel ukazuje jmenovitě osoby k výmazu i osoby držené zadržením.

## 93.4 Krok za krokem: odchylka firmy od katalogové lhůty

1. Na řádku kategorie klikněte na **Odchylka firmy**.
2. Vyplňte **Prodloužení o (roky)**. U kategorie bez lhůty v katalogu vyplňte
   **Dodaná lhůta (roky)**.
3. Vyplňte **Zdůvodnění** (vnitřní předpis, smluvní závazek, spor).
4. Klikněte na **Uložit**.

**Jak poznáte, že je hotovo:** Aplikace hlásí „Odchylka uložena." a u kategorie
je poznámka „odchylka firmy: +… let" nebo „lhůtu dodala firma: … let".

Odchylku zrušíte tlačítkem **Zrušit odchylku**; lhůta se vrátí na hodnotu
z katalogu.

## 93.5 Krok za krokem: zadržení výmazu osoby

1. Klikněte na **Zadržet výmaz**.
2. Vyberte osobu, důvod (kontrola, odvolání, spor, exekuce, insolvence),
   vyplňte č. j. nebo popis řízení a datum.
3. Uložte.
4. Až důvod pomine, klikněte u zadržení na **Uvolnit** a potvrďte.

**Jak poznáte, že je hotovo:** Zadržení je v seznamu **Zadržení výmazu**
a osoba je v panelu mezi **Osoby držené zadržením**. Uvolněné zadržení uvidíte
zaškrtnutím **Zobrazit i uvolněná**.

## 93.6 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Odchylka se neuloží, lhůtu nejde zkrátit | Zkrácení pod katalogovou lhůtu aplikace nepřijme | Lhůtu lze jen prodloužit nebo dodat. |
| Zadržení se neuloží | Chybí osoba nebo č. j. či popis řízení | Doplňte je; bez popisu nejde doložit, proč se výmaz zadržel. |
| Kategorie má ve sloupci **Výmaz** „nikdy se nenavrhne" | Kategorie nemá lhůtu (**Bez lhůty**) | Dodejte lhůtu odchylkou firmy, pokud ji máte podloženou. |
| Osoba se nenavrhuje k výmazu | Běží lhůta, trvá zadržení, chybí základ výpočtu, nebo je už anonymizovaná | Důvod je u osoby v panelu **Co z lhůt plyne pro výmaz**. |
| „Filtru neodpovídá žádná kategorie" | Kategorie schoval filtr | Klikněte na **Zrušit filtr**. |

## 93.7 Podrobnosti a pravidla

### 93.7.1 Co přehled ukazuje

Mzdový modul drží nejcitlivější osobní údaje v aplikaci a nesmí je držet navždy
ani je zahodit dřív, než smí. U každé kategorie je vidět:

- **Lhůta**: počet let, podle kterého se počítá, včetně případného prodloužení
  firmou,
- **Běží od**: kalendářní roky po roce, kterého se záznam týká, roky po roce
  vyhotovení, nebo roky od konce účetního období,
- **Právní pramen**: konkrétní ustanovení, ne jen číslo zákona, a u lhůt,
  jejichž číslo se v posledních letech měnilo, i novela, která dnešní znění
  zavedla,
- **Ověřeno**: den, ke kterému se citace porovnala s účinným zněním,
- **Dotčené tabulky**: čeho přesně se lhůta drží.

### 93.7.2 Původ lhůty

Nejdůležitější sloupec není číslo, ale odkud se vzalo:

- **Ze zákona**: číslo stojí v předpise a pramen říká kde.
- **Dodaná politika**: číslo dodala aplikace, protože zákon pro tuto skupinu
  záznamů lhůtu nemá. Týká se to zdravotního pojištění: v zákoně č. 592/1992 Sb.
  žádná uschovávací lhůta není, deset let je bezpečné rozhodnutí, ne právo,
  a přehled to říká nahlas.
- **Bez lhůty**: doloženo, že předpis lhůtu nestanoví. Spis k exekučním srážkám
  lhůtu nemá: v občanském soudním řádu se uschovávání týká jen prodeje
  nevyzvednutých movitých věcí a v exekučním řádu je povinnost uložena
  exekutorovi, ne plátci mzdy.

Kategorie bez lhůty se k výmazu **nikdy** nenavrhne, dokud lhůtu nedodá firma
vlastní politikou.

### 93.7.3 Co z lhůt plyne pro výmaz

Spodní panel přepočítá lhůty na konkrétní osoby k zadanému dni: kolik jich lze
navrhnout k výmazu a hlavně proč se ostatní nenavrhly. Rozlišuje běžící lhůtu,
zadržení výmazu, neurčenou lhůtu, chybějící základ výpočtu a osoby, které už
anonymizované jsou. Návrh, který někoho mlčky vynechá, se nedá zkontrolovat, proto
panel osoby **jmenuje**: kdo je na řadě k výmazu a koho drží zadržení. Nevratný
úkon se podle samotného čísla odklepnout nedá.

Lhůty účetních a daňových záznamů firmy jako celku (§ 31 a § 32 zákona
o účetnictví) mají vlastní přehled `Nástroje → Retenční lhůty` (odkaz **Účetní
retenční lhůty**, viz [Účetní nástroje](73_Ucetni_nastroje.md)).

### 93.7.4 Odchylka firmy

Odchylka má jeden formulář s jedním uložením:

- **Prodloužení o (roky)** se přičte ke katalogové lhůtě; použije se, když
  firmu váže smlouva nebo vnitřní předpis s delší lhůtou,
- **Dodaná lhůta (roky)** se nabídne **jen** u kategorií bez lhůty v katalogu
  (dnes spis k exekučním srážkám); dokud ji nikdo nedodá, osoba se k výmazu
  nenavrhne,
- **Zdůvodnění** je povinné, odchylka od zákonné lhůty musí být doložitelná.

**Lhůtu nelze zkrátit.** Není to omezení formuláře, ale pravidlo aplikace:
zkrácení pod hodnotu z katalogu, zákonnou i dodanou politikou, se odmítne
s vysvětlením, odkud lhůta pochází.

### 93.7.5 Zadržení výmazu (legal hold)

Zadržení drží data osoby i po uplynutí lhůty kvůli daňové kontrole, odvolání,
soudnímu sporu, exekuci nebo insolvenci (§ 32 zákona o účetnictví a mzdové
důvody). Váže se na osobu, ne na období. Dokud trvá, osoba se k výmazu
nenavrhne a už schválený návrh ji přeskočí. **Uvolnění** je vědomý úkon
s potvrzením; výmaz pak zase půjde navrhnout a provést. Uvolněný záznam
nezmizí, jen dostane datum uvolnění.

Zadržení zadané na účetní straně (`Nástroje → Retenční lhůty`) platí na celou
firmu, tedy i na mzdy. Opačně to neplatí: zadržení jedné osoby nemá co blokovat
mazání faktur.

### 93.7.6 Bezpečnost a časté chyby

Retenci pravidelně revidujte, ideálně s kontrolou druhou osobou. Nezkracujte
lhůty kvůli úspoře místa. Přehledy obsahují osobní údaje a musí mít omezený
přístup; export seznamu chraňte stejně jako původní data. Uplynutí lhůty není
automatickým výmazem a zadržení má přednost před plánovaným odstraněním.

Časté chyby:

- počítání lhůty od data vložení místo od právně rozhodné události,
- přehlédnutí probíhajícího sporu, kontroly nebo exekuce,
- domněnka, že stav po lhůtě data sám odstranil,
- posouzení jen databázového záznamu bez dokumentů a exportů.

## 93.8 Související kapitoly

- [Výmaz osobních údajů](94_Vymaz_osobnich_udaju.md)
- [Účetní nástroje](73_Ucetni_nastroje.md): účetní retenční lhůty
- [Dokumenty a výstupy](83_Dokumenty_a_vystupy.md) a [Podání a hlášení](85_Podani_a_hlaseni.md): uchovávané výstupy
