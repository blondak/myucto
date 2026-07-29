# 52. Saldokonto

**Cesta: `Účetnictví → Saldokonto`**

Saldokonto porovnává otevřené položky podle partnerů se zůstatkem
saldokontního účtu v hlavní knize k vybranému dni. Je podkladem pro
inventarizaci pohledávek, závazků a záloh a navazuje na
[Inventarizaci účtů](63_Inventarizace_rozvahovych_uctu.md).

## 52.1 Období a účty

Vyberte účetní období a **Rozvahový den** uvnitř jeho hranic. Bez data se
použije dřívější z posledního dne období a dneška. Filtr **Účet** nabízí:

| Volba | Otevřené položky |
|---|---|
| Vše | 311, 321, 314 a 324; prázdné účty se vynechají |
| 311 | vydané faktury a dobropisy odběratelů |
| 321 | přijaté faktury a dobropisy dodavatelů |
| 314 | poskytnuté a dosud nezúčtované zálohy |
| 324 | přijaté a dosud nezúčtované zálohy |

Server podporuje i jiný existující číselný účet a volitelné omezení na
partnera, běžná obrazovka však nabízí uvedenou čtveřici. Explicitně zvolený
účet se zobrazí i s nulami.

## 52.2 Dvě nezávislé strany konfrontace

**Zůstatek hlavní knihy** vychází ze všech zaúčtovaných řádků účtu do
rozvahového dne. Používá stejnou otevírací kotvu jako rozvaha a vylučuje
vlastní závěrkový převod období, aby uzavření knih historický stav nevynulovalo.
Zůstatek se otočí na normální stranu účtu: pohledávka na MD i závazek na Dal
se proto zobrazují kladně.

**Otevřené položky** vznikají z dokladů navázaných na účetní zápis. U každého
dokladu se vezme jeho zaúčtovaná hodnota v Kč a poměr úhrady známý k
rozvahovému dni:

`zbývá = zaúčtováno × (1 − poměr úhrady)`

Výpočet úhrady respektuje datum platby. Pozdější úhrada nezmění historické
saldokonto. Plně vyrovnaná položka se vynechá, záporná otevřená položka
(například dobropis) se zachová a snižuje součet partnera. Částky v cizí měně
se konfrontují v zaúčtované Kč hodnotě; obrazovka současně ukáže původní měnu.

## 52.3 Zálohy 314 a 324

U záloh se neporovnává jen stav dokladu. Systém sleduje skutečnou
zaúčtovanou platbu a její následné čerpání:

- na **314** je otevřená poskytnutá záloha snížena o zúčtování ve finální
  přijaté faktuře i o kredit 314 z přijatého daňového dokladu k platbě,
- na **324** je přijatá platba vydané proformy snížena o daňový doklad k
  přijaté platbě a o vyúčtovací fakturu.

Plně zúčtovaná záloha proto zmizí z otevřených položek, její historické
účetní pohyby však zůstanou v deníku.

## 52.4 Rozdíl a jeho interpretace

Pro každý účet platí:

`Rozdíl = zůstatek hlavní knihy − Σ otevřených položek`

Rovnost se kontroluje na haléře. Nulový rozdíl znamená, že dokladový rozpad
vysvětluje zůstatek účtu. Nenulový rozdíl může ukazovat na ruční zápis bez
vazby na doklad, chybějící či špatně datovanou úhradu, storno, kurzový pohyb
nebo nezúčtovanou zálohu.

Nulový rozdíl sám nepotvrzuje existenci pohledávky, vymahatelnost ani úplnost
závazků. Tyto skutečnosti je nutné doložit nezávislými podklady.

## 52.5 Partner a doklady

Partneři jsou řazeni podle názvu. Rozbalení partnera zobrazí číslo dokladu,
datum vystavení, splatnost, počet dní po splatnosti, původní částku, částku
zaúčtovanou v Kč, uhrazeno a zbývá. Doklad vede na detail vydané nebo přijaté
faktury.

Storno datované až po rozvahovém dni položku k dřívějšímu dni neskrývá.
K datu storna a později se účetní i dokladová strana vyruší.

## 52.6 Export

PDF a XLSX vytvoří inventarizační protokol pro aktuální účet, partnera a
rozvahový den. Obsahují zůstatek hlavní knihy, součet otevřených položek,
rozdíl a rozpad partnerů s doklady.

> 💡 Nenulový rozdíl řešte od konfrontačního pruhu přes partnera a doklad; pro
> ruční či kurzový zápis pokračujte proklikem do opisu účtu v
> [Hlavní knize](47_Hlavni_kniha.md).
