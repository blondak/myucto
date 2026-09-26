# 78. Cestovní náhrady

## 78.1 Účel

Agenda cestovních náhrad eviduje pracovní cestu a její vyúčtování jako podklad pro nárok zaměstnance a případný dopad do mzdy.

## 78.2 Předpoklady a oprávnění

Musí existovat zaměstnanec a vztah. Připravte schválenou cestu, časy, místo, dopravní prostředek, zálohy a účetní doklady. Sazby a kurz musí odpovídat rozhodnému dni a platným pravidlům.

## 78.3 Krokový postup

1. Otevřete **Mzdy → Cestovní náhrady** a založte cestu pro správného zaměstnance.
2. Vyplňte začátek, konec, místo, účel a použitou dopravu.
3. Doplňte doložené výdaje, poskytnutou zálohu, měny a kurzy.
4. Zvolte, kde se vypořádá rozdíl mezi nárokem a zálohou (**Vypořádání zálohy**: mzdou, nebo pokladnou).
5. Zkontrolujte vypočtené stravné, krácení podle poskytnutého jídla a vypořádání proti záloze v náhledu.
6. Schválené vyúčtování promítněte do mzdy tlačítkem **Promítnout do mzdy**.

## 78.4 Stavy

Cesta může být rozpracovaná, připravená ke kontrole, schválená nebo vypořádaná. Rozpracovaný výpočet není účetním dokladem ani příkazem k úhradě.

## 78.5 Kontroly a bezpečnost

Ověřte časová pásma, měnu, kurz, zákonné sazby a vypořádání zálohy v náhledu. Přílohy mohou obsahovat osobní údaje; ukládejte je bezpečně. Nepoužívejte cestovní náhradu jako obecnou nezdaněnou mzdovou složku.

## 78.6 Časté chyby

- Chybné datum kurzu nebo měna.
- Záloha vyplacená z pokladny, ale nezadaná do cesty. Aplikace pak do mzdy pošle celý nárok.
- Duplicitně vložená účtenka.
- Opomenuté krácení stravného.
- Schválení bez vazby na skutečný pracovní vztah a účel cesty.

## 78.7 Návaznosti

Mzdový dopad zkontrolujte v [rychlém měsíčním vstupu](79_Rychly_mesicni_vstup.md) a [mzdovém běhu](80_Mzdove_behy.md). Úhradu dokončete podle [kapitoly 58g](82_Platby_a_uhrady.md).



## 78.8 Podrobný pracovní postup a kontroly

V **Mzdy → Cestovní náhrady** vedeš tuzemské pracovní cesty a jejich vyúčtování.
U cesty zadej pracovní vztah, odjezd a návrat s časem, místo, účel a dopravní
prostředek. Čas zadáváš místní, tak jak ho vidíš na hodinách; systém si k němu
uloží časovou zónu, takže cesta a rozvržená směna na sebe sedí i v období změny
letního času. Hodinu, která se na jaře přeskakuje, formulář nepřijme — v místním
čase totiž neexistuje. K vyúčtování přidáš doložené výdaje (jízdné, ubytování, nutné
vedlejší výdaje), jízdy soukromým vozidlem v kilometrech a spotřebě na 100 km,
bezplatná jídla po dnech a poskytnutou zálohu.

Nárok se počítá z účinné vyhlášky k rozhodnému dni:

- stravné podle časových pásem pracovní cesty (5 až 12 h, nad 12 do 18 h,
  nad 18 h) za každý kalendářní den; u cesty spadající do dvou kalendářních dnů
  se použije výhodnější varianta;
- krácení stravného za každé poskytnuté bezplatné jídlo;
- základní náhrada za ujetý kilometr a náhrada za spotřebované pohonné hmoty
  z průměrné ceny podle vyhlášky, nebo z doložené ceny;
- doložené ubytování a nutné vedlejší výdaje.

Náhled ukazuje rozpad po dnech i po položkách a rozdělí výsledek na část **do
zákonného limitu**, která není předmětem daně, pojistného ani exekučních srážek,
a na **nadlimitní část**, která do mzdy vstupuje jako zdanitelný příjem a do
vyměřovacích základů. Sazba stravného nižší než zákonné minimum, chybějící
účinná sazba i zahraniční pracovní cesta skončí v ruční kontrole a vyúčtování
nelze schválit.

Schválené vyúčtování promítneš tlačítkem **Promítnout do mzdy**; založí mzdové
vstupy na složkách `CESTOVNI_NAHRADA_LIMIT` a `CESTOVNI_NAHRADA_NADLIMIT`
v období vyúčtování. Opakované promítnutí nevytvoří duplicitu.

### Vypořádání zálohy

Vyúčtování cesty je nárok minus poskytnutá záloha (§ 183 zákoníku práce).
Kde se rozdíl vyrovná, určuje u cesty volba **Vypořádání zálohy**:

- **Mzdou** — do mzdy jde celý nárok (obě části se zdaní nebo nezdaní podle
  zákona) a záloha se z výplaty odečte složkou `CESTOVNI_NAHRADA_ZALOHA`.
  Odečte se nejvýš celý nárok. Pokud záloha nárok převýšila, přeplatek se ze
  mzdy nesráží a zaměstnanec ho vrací do pokladny; aplikace ho po promítnutí
  ohlásí.
- **Pokladnou** — nezdaněná část se do mzdy nepošle a doplatek (nebo vrácení
  přeplatku) vyrovnáte pokladním dokladem. Do mzdy jde jen nadlimitní část,
  protože se musí zdanit a započítat do vyměřovacích základů.

Náhled ukazuje zálohu, dopad do výplaty, doplatek z pokladny a přeplatek, který
zaměstnanec vrací, přesně tak, jak je promítnutí založí.

V podvojném účetnictví se náhrada účtuje na nákladový účet cestovného (výchozí
kontace 512) proti závazkovému účtu pracovního vztahu, tedy proti témuž účtu,
ze kterého se zaměstnanci vyplácí mzda. Poskytnutou zálohu vyplacenou
z pokladny účtujte na pohledávku za zaměstnancem (335):

- při vypořádání **mzdou** ji odečet ve výplatě vyrovná zápisem MD 331 / D 335,
- při vypořádání **pokladnou** zaúčtuje vyúčtování nezdaněnou část MD 512 /
  D 335 a pokladní doklad doplatku (MD 335 / D 211) nebo vrácení přeplatku
  (MD 211 / D 335) pohledávku vyrovná.

Účty lze změnit v
[Nastavení mezd](90_Nastaveni_mezd.md#9081-predkontace-pro-zvlastni-mzdove-situace). Zakládat a
upravovat cesty smí role s oprávněním pro mzdové vstupy, schválení a promítnutí
vyžaduje oprávnění pro schvalování.
