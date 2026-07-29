# 31. Dokumenty

Sekce **Dokumenty** je úložiště pro libovolné soubory, které k podnikání patří,
ale nejsou to přímo faktury — smlouvy, naskenované doklady, XML/ISDOC, datové
zprávy ze schránky (ZFO), elektronické podpisy (P7S), tabulky a další. Najdeš ji
v menu hned **před sekcí Daně**.

Vše je odděleně **per dodavatel** (firma/IČO) — co nahraješ pod jednou firmou,
nevidíš pod jinou.

## Organizace — složky, vazby a tagy

Dokumenty organizuješ třemi způsoby, které se doplňují:

- **Strom složek** — klasické složky a podsložky jako na disku. Složky jsou
  „virtuální" (soubor fyzicky leží podle svého otisku), takže přesun složky je
  okamžitý a nic se nekopíruje.
- **Vazby na entitu** — dokument můžeš připojit ke konkrétní **vystavené faktuře,
  přijaté faktuře, klientovi nebo zakázce**. Vazba je oboustranná: uvidíš ji
  jak v detailu dokumentu, tak v panelu *Dokumenty* v detailu té faktury/klienta.
- **Tagy** — volné štítky pro průřezové hledání (např. `smlouva`, `2026`, `GDPR`).

## Nahrávání

V pravém horním rohu jsou tři způsoby (potřebuješ právo zápisu):

- **Nahrát** — vybere jeden nebo více souborů.
- **Nahrát složku** — vybere celý adresář z disku; jeho podsložky se v aplikaci
  automaticky vytvoří.
- **Drag & drop** — přetáhni soubory **nebo celé složky** kamkoli do okna sekce.
  Struktura podsložek se zrekonstruuje.

Nové soubory se nahrají do **aktuálně otevřené složky**.

### Soubory ZIP — dva režimy

Přepínač **Soubory ZIP** určuje, co se stane s nahraným `.zip`:

- **Rozbalit a kategorizovat** — archiv se bezpečně rozbalí, podsložky uvnitř se
  promítnou do stromu složek a každý soubor se uloží samostatně.
- **Nahrát jako jeden ZIP** — archiv zůstane jako jeden soubor ke stažení.

### ZFO — datové zprávy ze schránky

Když nahraješ **ZFO** (stažená nebo odeslaná datová zpráva), aplikace ji
**automaticky rozbalí**:

- uloží se **veškerá metadata zprávy** — ID zprávy, odesílatel, příjemce,
  předmět, datum dodání i odeslání (zobrazí se v detailu v panelu *Datová zpráva*),
- jednotlivé **přílohy** zprávy se uloží jako samostatné dokumenty navázané na
  původní ZFO,
- případný odpojený podpis **P7S** se napáruje na podepsaný dokument.

## Náhledy a otevírání

U PDF a obrázků se generují **náhledy (thumbnaily)** a v detailu je **inline
náhled** přímo v aplikaci (stejně jako u přijatých faktur). Přímo v detailu lze
otevřít také **XML** (odsazené a čitelně formátované), **TXT, GPC a ABO** (text
s čísly řádků) a **CSV** (tabulka s automaticky rozpoznaným oddělovačem). Velké
textové soubory mají náhled omezený; celý originál zůstává vždy dostupný ke
stažení. Ostatní typy souborů se z bezpečnostních důvodů nabízejí pouze ke
**stažení**.

## Vyhledávání

Pole **Hledat** nahoře prohledává **názvy, popisy i obsah** dokumentů. U PDF
s textovou vrstvou, dokumentů Office (DOC/XLS) a XML se text indexuje při nahrání,
takže najdeš dokument i podle slova uvnitř. Naskenované PDF bez textové vrstvy
zůstává dohledatelné podle názvu a tagů.

## Párování s fakturami a klienty

V detailu dokumentu v sekci **Souvisí s** přidáš vazbu přes **našeptávač** — píšeš
a aplikace průběžně nabízí **vystavené i přijaté faktury, klienty a zakázky**.
Hledat můžeš podle **čísla dokladu, názvu firmy, e-mailu, IČ/DIČ, názvu nebo čísla
projektu**. Klikneš na nabídku a vazba je hotová.

Obráceně: v detailu **klienta, vystavené faktury, přijaté faktury i zakázky**
najdeš panel **Dokumenty**, kde vidíš všechny připojené soubory a přes tlačítko
*Připojit dokument* k nim přidáš další.

## Hromadné akce

Zaškrtni více dokumentů **i složek současně** (v mřížce i seznamu) a v liště nahoře:

- **Přesunout** do jiné složky (přes stromový výběr cíle),
- **Otagovat** (přidat štítky — jen u souborů),
- **Stáhnout ZIP** vybraných souborů i složek (export zachová stromovou strukturu složek),
- **Smazat** (do koše — u složky včetně obsahu).

Velikost každé složky je vidět přímo v dlaždici. Na mobilu (bez najetí myší) se akce složky (přejmenovat/smazat) odkryjí prvním ťuknutím a spustí až druhým — ochrana proti nechtěnému smazání.

## Koš

Smazání je **nevratné až po vysypání koše**. Tlačítko **Koš** přepne na seznam
smazaných dokumentů i složek, kde je můžeš **Obnovit**. **Vysypat koš** je trvale
odstraní z databáze i z disku (soubor se fyzicky smaže jen tehdy, když na něj
neukazuje žádný jiný dokument — kvůli deduplikaci).

## Oprávnění

- **Jen pro čtení (readonly)** — procházení, náhledy, fulltext, stahování a export.
- **Účetní / admin** — navíc nahrávání, mazání, přesouvání, tagy a vazby.

## Firemní a osobní dokumenty

Nad seznamem je přepínač **Firemní / Osobní**, který řídí, kterou vrstvu dokumentů
zrovna procházíš:

- **Firemní** — klasické sdílené úložiště, vidí ho každý, kdo má k sekci Dokumenty
  přístup (podle role výše).
- **Osobní** — dokumenty patřící konkrétnímu uživateli. Běžný uživatel v této
  záložce vidí **jen svoje vlastní** dokumenty; **admin** vidí osobní dokumenty
  **všech** uživatelů firmy a navedle přepínače má výběr konkrétního vlastníka
  (nebo volbu „Všichni vlastníci").

Rozlišení firemní/osobní je vlastnost **každého jednotlivého dokumentu** (ne
složky — složky samotné vrstvu nemají a procházejí se společně), a platí důsledně
napříč celou sekcí — v seznamu, fulltextovém hledání, filtru podle tagu, párování
s fakturami/klienty i v koši. Osobní dokument cizího uživatele se běžnému
uživateli nezobrazí v žádném z těchto míst, ani v hromadném ZIP exportu.

> [!NOTE]
> Vrstva firemní/osobní je nezávislá na izolaci **per dodavatel** popsané výše —
> obě běží současně. Nejdřív tě systém omezí na dokumenty tvé aktuální firmy,
> teprve uvnitř ní pak na firemní vs. tvoje osobní.

## Přílohy účetních zápisů (§33a)

Kromě obecného úložiště popsaného v této kapitole má MyÚčto ještě **samostatnou,
oddělenou evidenci příloh přímo u jednotlivých zápisů v účetním deníku** — sken
faktury, dodacího listu nebo jiného průkazného dokladu, který dokládá konkrétní
účetní zápis podle **§ 33a zákona o účetnictví**. Tahle příloha se **nenahrává
zde v sekci Dokumenty**, ale přímo v detailu zápisu v [Účetním deníku](44_Ucetni_denik.md) —
tady popisujeme jen princip, protože jde o technicky příbuzné, ale oddělené
úložiště.

### Proč oddělené úložiště

Přílohy zápisu žijí ve **vlastní databázové tabulce** a **vlastním diskovém
prostoru** (`storage/journal/…`, mimo `storage/documents/` používaný zbytkem
této kapitoly). Bajty jsou stejně jako u Dokumentů ukládané podle otisku
(content-addressed), ale dedup a mazání „naposledy zbylého souboru" počítá
**jen mezi přílohami zápisů** — nikdy se nekříží s obecným DMS. Díky tomu:

- průkazný záznam k zápisu nejde smazat ani přesunout přes DMS rozhraní,
- práva k přílohám zápisu se řídí **účetní rolí** (viz níže), ne oprávněními
  k sekci Dokumenty,
- životní cyklus přílohy je svázaný s životním cyklem zápisu (smazání zápisu
  smaže i jeho přílohy).

### Nahrávání a limity

U zápisu jde nahrát **více souborů najednou**. Systém zpracuje každý soubor
samostatně — pokud jeden selže (např. je duplicitní), zbytek dávky se přesto
nahraje a u odpovědi vidíš přehled, co se povedlo a co ne s důvodem (např.
„už evidováno", „příliš velký").

- **Max. 20 MiB na jeden soubor.**
- **Max. 100 MiB celkem na jeden zápis** (součet všech jeho příloh).
- **Dedup podle obsahu** — stejný soubor (stejný sha256 otisk) nejde ke stejnému
  zápisu přiložit dvakrát (vrátí se chyba „už evidováno"). Stejný soubor lze bez
  problému přiložit k **různým** zápisům — bajty na disku se sdílejí.
- Typ souboru se stejně jako u Dokumentů poznává **z obsahu**, ne z přípony;
  spustitelné a aktivní obsahy (skripty, HTML/SVG) jsou odmítnuty stejným
  blocklistem jako v [§ Bezpečnost](#bezpecnost).
- Rozpoznávané typy: **PDF, obrázek, XML/ISDOC(x), ZFO** — ostatní se uloží
  jako „ostatní".

### Popisek, stažení a mazání

- Ke každé příloze jde inline dopsat/upravit **popisek** (do 255 znaků) — každá
  změna se loguje (před/po) do historie zápisu.
- Stažení přílohy je vždy **jako soubor ke stažení** (`Content-Disposition:
  attachment`) — na rozdíl od Dokumentů se příloha zápisu **nikdy nezobrazuje
  inline** v prohlížeči, ani PDF.
- Smazání přílohy odstraní záznam u zápisu; samotný soubor na disku zmizí,
  jen když na jeho otisk neukazuje žádná jiná příloha (stejný princip dedupu
  jako u Dokumentů, ale počítaný odděleně).

### Oprávnění

- **Čtení** (zobrazení seznamu příloh u zápisu) — kdokoli s přístupem k
  účetnímu deníku (readonly a výš).
- **Nahrávání, mazání a úprava popisku** — jen role **účetní** nebo **admin**.

> [!WARNING]
> Plánovaná úloha `cron-backup-documents` (viz [§ Zálohování](#zalohovani) níže)
> zálohuje jen `storage/documents/` — přílohy účetního deníku (`storage/journal/`)
> v ní **aktuálně obsažené nejsou**. Počítej s tím při plánování celkové zálohy
> databáze a souborového úložiště.

## Zálohování

Dokumenty zálohuje **samostatná plánovaná úloha** `cron-backup-documents`
(viz *Systém → Plánované úlohy*), oddělená od zálohy PDF faktur. Zálohuje celé
úložiště `storage/documents/` (všechny typy souborů) do
`storage/backup/{db}-documents-RRRR-MM-DD.zip` s retencí 30 denních + 12 měsíčních
záloh. Náhledy se nezálohují (regenerují se). Zálohu lze volitelně šifrovat
heslem `cron.backup.password` v `cfg.php` (AES-256, společné pro všechny typy
záloh — viz [§ 5.5 Cron skripty](05_Po_instalaci.md#55-cron-skripty)).

## Bezpečnost

Sekce přijímá libovolné soubory, proto je upload chráněný: typ se ověřuje podle
**obsahu** (ne podle přípony), spustitelné soubory a HTML/SVG jsou odmítnuty,
rozbalování ZIP má ochranu proti „zip bombě" i průniku cesty (Zip Slip) a
parsování ZFO/XML je chráněno proti útokům přes XML entity (XXE).
