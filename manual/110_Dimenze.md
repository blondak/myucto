# Dimenze

Dimenze jsou analytické členění dokladů a účetních zápisů: středisko, projekt,
vozidlo, lokalita, obchodní případ nebo vlastní typ. Každý doklad i každý řádek
deníku může nést hodnotu každého typu (nejvýš jednu za typ) a sestavy pak ukážou
výnosy a náklady po hodnotách.

Dimenze nemění účty, částky ani období. Jde jen o analytiku, proto je lze doplnit
nebo změnit i u zaúčtovaného dokladu a v uzavřeném období.

## Zapnutí

Dimenze se zapínají pro každou firmu zvlášť v `Firma → Nastavení`, v boxu
**Dimenze**. Dokud jsou vypnuté, nikde se nic nezobrazí a doklady ani deník se
nemění. Po zapnutí přibude v menu `Firma → Dimenze` a v Účetnictví sestava
**Výsledovka po dimenzi**.

Na stránce Dimenze jde založit výchozí typy jedním tlačítkem: Středisko, Projekt,
Vozidlo, Lokalita a Obchodní případ.

## Firemní a globální dimenze

- **Firemní** typ patří jedné firmě. Jeho hodnoty vidí jen ona.
- **Globální** typ patří **skupině firem** a jeho hodnoty sdílí všechny firmy
  skupiny. Typicky projekt nebo lokalita, které vede mateřská firma i její SPV:
  stejný projekt se pak dá sečíst přes všechny firmy.

Skupinu firem spravuje správce firmy na záložce **Globální**: založí novou skupinu,
nebo firmu připojí ke skupině, do které patří jiná firma, kterou spravuje. Firma,
která skupinu opustí, přestane globální dimenze skupiny vidět.

Výchozí typy Projekt a Lokalita vzniknou jako globální, pokud firma do skupiny
patří. Jinak jsou firemní. Středisko, Vozidlo a Obchodní případ jsou vždy firemní.

## Typy a hodnoty

Typ má název, kód, druh a nastavení, zda se nabízí na dokladech. Typ, který se
nenabízí na dokladech, se zadává jen v deníku.

Hodnoty tvoří **strom**: každá hodnota může mít nadřízenou. Sestava za nadřízenou
hodnotu sečte celou její větev. Hodnotu lze:

- přidat jako podřízenou jiné hodnoty,
- přejmenovat nebo přesunout pod jinou nadřízenou (ne pod sebe ani pod svou
  podřízenou),
- **uzavřít**, když už se nepoužívá. Uzavřená hodnota se na dokladech nenabízí,
  na dokladech a zápisech, kde už je, zůstává,
- smazat. Hodnota, která je použitá nebo má podřízené, se místo smazání uzavře.

Kód hodnoty se po založení nemění.

K hodnotě lze uvést **odpovědnou osobu** (uživatele firmy nebo text). Je to údaj
o hodnotě: do účetního deníku se nekopíruje, zápisy nesou jen odkaz na hodnotu.

### Vazby na evidence firmy

| Druh typu | Vazba hodnoty |
|---|---|
| Středisko | středisko v číselníku středisek (`Nástroje → Střediska`) |
| Vozidlo | vůz z knihy jízd |
| Projekt | zakázka pro fakturaci (`Zakázky`) |

Nová hodnota typu Středisko si středisko se stejným kódem v číselníku najde, nebo
ho založí. Uzavření hodnoty středisko deaktivuje. Řádky deníku, které nesou jen
kód střediska (mzdy, starší ruční zápisy), se v sestavách po středisku započítají
k hodnotě navázané na to středisko.

Hodnota projektu navázaná na zakázku doplní zakázku i na řádky deníku, takže se
promítne do ziskovosti zakázky.

Globální hodnota na záznamy jedné firmy odkazovat nemůže.

## Dimenze na dokladech

Při zapnutých dimenzích mají výběr dimenzí:

- přijaté a vydané faktury, v hlavičce i u každé položky,
- pokladní doklady,
- bankovní pohyby (v detailu výpisu i v seznamu všech pohybů, viz níže),
- ruční zápisy, u každého řádku,
- šablony ručních zápisů (dimenze se předvyplní do zápisu).

Výběr hledá v kódu, názvu i v nadřízených hodnotách: hledání „Morava" najde
i hodnotu Brno, která pod Moravou leží.

Na detailu faktury a pokladního dokladu lze dimenze změnit i u zaúčtovaného
dokladu.

### Bankovní pohyby

V detailu bankovního výpisu ukazuje každý pohyb své dimenze jako štítky pod
protistranou. Dimenze se nastavují v nabídce **…** u pohybu položkou **Dimenze**
(nebo kliknutím na štítky), která pod pohybem otevře výběr dimenzí; tlačítko
**Uložit dimenze** je uloží. Zaúčtování pohybu je zapíše na všechny řádky jeho
zápisu v deníku. Změna dimenzí už zaúčtovaného pohybu se do řádků deníku promítne
hned, i v uzavřeném období, protože mění jen analytické členění.

### Jak se dimenze dostanou do deníku

- Dimenze hlavičky dokladu se při zaúčtování zapíšou na všechny řádky zápisu.
- Dimenze položky má přednost před hlavičkou (typ po typu) na výsledkových řádcích,
  tedy na nákladu a výnosu. Mají-li položky jednoho nákladového řádku různé
  dimenze, rozdělí se řádek při zaúčtování v poměru základu položek. Účet, strana
  a součet se nemění a zápis zůstává vyvážený na haléř.
- Storno přebírá dimenze stornovaného řádku.
- Změna dimenzí už zaúčtovaného dokladu se promítne do jeho řádků deníku. Řádky
  se přitom nedělí: když by rozdělení bylo potřeba (položky nově nesou různé
  hodnoty), aplikace upozorní, že doklad je potřeba **přeúčtovat**.

U řádků zápisu lze dimenze upravit i ručně v rozbaleném zápisu deníku tlačítkem
**Upravit dimenze**. U zápisu z dokladu platí doklad: další změna dimenzí dokladu
ruční úpravu řádků přepíše.

## Účetní deník

Účetní deník má ve filtrech výběr dimenze: typ, hodnotu a volbu **včetně
podřízených**. Deník pak ukáže celé zápisy, u kterých aspoň jeden řádek nese
vybranou hodnotu (nebo hodnotu pod ní). U Střediska se počítají i řádky, které
nesou jen kód navázaného střediska, stejně jako v sestavách. Filtr se ukládá do
adresy stránky i do uložených pohledů a platí i pro export deníku do PDF a XLSX.

## Sestavy

- **Výsledovka**, **Obratová předvaha** a **Hlavní kniha** mají filtr na dimenzi:
  vybere se typ a hodnota, volitelně včetně podřízených hodnot. Sestava pak
  obsahuje jen řádky deníku s touto hodnotou, ne protistranu zápisu. Kontrolní
  vazby předvahy na celý deník proto s filtrem nemusí sedět.
- **Výsledovka po dimenzi** ukáže pro hodnoty jednoho typu výnosy, náklady
  a výsledek. Nadřízená hodnota sčítá celou větev, řádek **Bez hodnoty** doplní
  součet do výsledku firmy za období. U globálního typu lze zaškrtnout **Sečíst
  všechny firmy skupiny**: sečtou se firmy skupiny, ke kterým máte účetní přístup.

## Oprávnění

Číselník dimenzí a výběry na dokladech vidí uživatel s právem číst účetnictví,
měnit je smí uživatel s právem zápisu do účetnictví. Zapnutí dimenzí patří
k nastavení firmy, skupinu firem spravuje správce firmy.

## Převod z Money S3

Převod z Money S3 dimenze zapne a středisko i zakázku z deníku Money převede na
dimenze. Podrobnosti v kapitole [Přechod z Money S3](103_Prechod_z_Money_S3.md).
