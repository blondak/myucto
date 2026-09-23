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

## Výchozí dimenze

Klient a zakázka mohou mít **výchozí dimenze**: pro každý typ nejvýš jednu hodnotu,
firemní i globální. Nastavují se ve formuláři klienta a zakázky v sekci **Výchozí
dimenze**, detail klienta a zakázky je ukazuje jako štítky. Karta klienta slouží
pro obě role, výchozí dimenze tedy platí pro vystavené faktury odběratele
i přijaté faktury dodavatele.

Kde se použijí:

- **Vystavená faktura** a **přijatá faktura**: po výběru klienta (dodavatele) nebo
  zakázky se předvyplní prázdné dimenze hlavičky. Stejně u nové faktury otevřené
  z karty klienta nebo zakázky a u přijaté faktury vytěžené z PDF nebo ISDOC.
- **Pokladní doklad**: u úhrady faktury se převezmou dimenze placené faktury,
  jinak výchozí dimenze partnera, jehož název přesně odpovídá klientovi
  v adresáři.
- **Bankovní pohyb**: u spárovaného pohybu nabídne panel dimenzí hodnoty
  spárované faktury (její dimenze, případně výchozí dimenze její zakázky
  a klienta). Uloží se tlačítkem **Uložit dimenze**.

Pravidla přednosti:

- Výchozí dimenze zakázky mají přednost před výchozími dimenzemi klienta, typ
  po typu. Typ, který zakázka nenastavuje, doplní klient.
- Předvyplnění nikdy nepřepíše hodnotu, kterou už doklad má nebo kterou jste
  vybrali ručně. Když změníte klienta nebo zakázku, změní se jen hodnoty, které
  se předvyplnily automaticky a které jste neupravili.
- Uzavřená hodnota a neaktivní typ se nepředvyplňují.

Při zaúčtování platí totéž i pro doklady, které editorem neprošly (import,
vytěžení, opakované faktury, automatizace): typ, pro který doklad hodnotu nemá,
dostane výchozí hodnotu zakázky, jinak klienta. Dimenze uvedená na dokladu
vždy vyhrává. U pokladního dokladu platí výchozí dimenze jeho zakázky a pak
dimenze placené faktury, u bankovního pohybu dimenze spárované faktury.

Změna výchozích dimenzí už zaúčtované doklady nemění. Projeví se u dokladů
zaúčtovaných později a u dokladu, jehož dimenze na detailu znovu uložíte.
Smazáním klienta, zakázky nebo hodnoty dimenze se výchozí nastavení odstraní.

## Pravidla dimenzí podle účtu

Na záložce **Pravidla** v sekci Firma → Dimenze se nastavuje, které účty musí
nést hodnotu kterého typu dimenze, například „náklady a výnosy musí mít
středisko". Pravidlo má:

- **Účty** – předpony účtů oddělené čárkou, vyloučení vykřičníkem. `5, 6, !59, !69`
  znamená třídy 5 a 6 kromě daně z příjmů. Maska `518` platí pro 518 i všechny
  jeho analytiky.
- **Typ dimenze**, kterou řádek musí nést.
- **Vynucení**:
  - **Povinné** – doklad ani ruční zápis bez hodnoty nejde zaúčtovat. Chyba
    jmenuje účet, stranu, částku a chybějící dimenzi.
  - **Varovat** – zápis se zaúčtuje a aplikace zobrazí upozornění.
  - **Jen doplnit** – pravidlo nic nevynucuje, jen doplňuje výchozí hodnotu.
- **Výchozí hodnota** – doplní se řádku na účtu z masky, který hodnotu typu
  nemá. Hodí se jako výchozí hodnota firmy, třeba projekt, ke kterému firma patří.
- **Doplnit vozidlo podle platební karty** (jen u typu Vozidlo) – u platby kartou
  z bankovního výpisu a u přijatého dokladu zaplaceného kartou se podle koncovky
  karty najde karta, její držitel a jeho jediné aktivní vozidlo. Hodnota typu
  Vozidlo navázaná na tento vůz se doplní na řádky z masky. Když se vozidlo
  určit nedá, použije se výchozí hodnota pravidla.
- **Platí od / do** – rozhoduje datum účetního případu. Pravidlo zavedené od
  určitého data nezablokuje přeúčtování starších dokladů.

Pořadí přednosti hodnoty na řádku: ruční volba na řádku, dimenze položky
a hlavičky dokladu, výchozí dimenze zakázky a klienta, teprve potom výchozí
hodnota pravidla. Když na jeden účet míří víc pravidel téhož typu, výchozí
hodnotu určí pravidlo s delší předponou a vynucení to nejpřísnější.

Pravidla platí pro zaúčtování vystavených a přijatých faktur, pokladních dokladů,
bankovních pohybů a ručních zápisů. Automatické zápisy (uzávěrka, otevření účtů,
odpisy, mzdy, přeúčtování DPH, kurzové rozdíly) pravidla neblokují. Při ruční
úpravě dimenzí řádků v deníku nejde povinnou dimenzi z řádku odebrat.

Firma bez pravidel účtuje stejně jako dřív.

### Kontrola deníku

Pod seznamem pravidel se za zvolené období spouští **Zkontrolovat deník**.
Výsledek ukáže zaúčtované řádky, kterým podle pravidel chybí hodnota. Souhrn je
po účtech, zdrojích zápisu a vynucení, seznam řádků vede do deníku. Typicky jde
o převzatou historii, automatické zápisy nebo zápisy z doby před zavedením
pravidla. Uzávěrka, otevření účtů a stornované zápisy se nepočítají.

**Pokrytí účtů** ukáže, jaká část řádků na každém syntetickém účtu nese hodnotu
typu dimenze. Účet, který historie členila téměř vždy (aspoň 90 % z deseti
a více řádků), nabídne tlačítko **Vytvořit pravidlo**. Hodí se po převodu
z jiného systému.

## Rozpad mezi více hodnot

Náklad, který patří víc střediskům nebo projektům, se dá rozdělit tlačítkem
**Rozpad**:

- na detailu dokladu v panelu Dimenze (rozpad celého dokladu),
- v účetním deníku v úpravě dimenzí řádků (rozpad jednoho řádku).

V rozpadu se vybere typ dimenze a hodnoty s procentem, u řádku deníku také
s částkou. Součet musí dát 100 %, resp. částku řádku. Typ s rozpadem pak nemá
jedinou hodnotu. Když typu později vyberete jedinou hodnotu, rozpad se zruší.

Řádek zápisu se rozpadem nedělí, zůstává jeden se svou částkou. Rozpad se ukládá
jako podíly. **Výsledovka po dimenzi** ho počítá poměrem po haléřích, součet
sestavy tak dál sedí na výsledek firmy. Filtr na dimenzi ve Výsledovce, Obratové
předvaze a Hlavní knize bere jen řádky s jedinou hodnotou. Rozpad dokladu se při
zaúčtování přenese na řádky zápisu. Storno zápisu přenese stejný rozpad, takže
odečte přesně to, co původní zápis přičetl. Rozpad splní i povinnou dimenzi
pravidla.

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
k nastavení firmy, skupinu firem spravuje správce firmy. Výchozí dimenze klienta
a zakázky smí měnit ten, kdo smí upravovat klienta, resp. zakázku.

## Převod z Money S3

Převod z Money S3 dimenze zapne a středisko i zakázku z deníku Money převede na
dimenze. Podrobnosti v kapitole [Přechod z Money S3](103_Prechod_z_Money_S3.md).
