# 31. Platební karty

Sekce **Peníze → Platební karty** vede firemní platební karty a jejich držitele.
Z bankovních výpisů pak aplikace pozná, kterou kartou se platilo, spáruje
platbu s účtenkou nebo fakturou téže karty a ukáže, ke kterým platbám kartou
ještě chybí doklad. Úvěrový účet ke kreditní kartě a jeho výpisy vede sekce
[Kreditní karty](112_Kreditni_karty.md).

## 31.1 Co se o kartě eviduje

Aplikace **nikdy neukládá celé číslo karty**. Stačí poslední čtyři číslice
(koncovka), podle nich se karta pozná ve výpisu. Vložíte-li do pole koncovky
celé číslo, uloží se jen poslední čtyři číslice a aplikace vás na to upozorní.
Pole Název, Jméno držitele a Poznámka celé číslo karty odmítnou.

| Pole | Význam |
|---|---|
| Název karty | vaše pojmenování, např. „Tankovací karta obchod" |
| Poslední 4 číslice | koncovka z maskovaného čísla karty |
| Typ karty | debetní, kreditní, předplacená, palivová, jiná |
| Karetní asociace | Visa, Mastercard, Maestro, American Express, jiná (nepovinné) |
| Bankovní účet | měnový účet firmy, ze kterého se platby kartou strhávají |
| Držitel | jméno držitele, případně zaměstnanec nebo uživatel aplikace |
| Platnost od–do | období, kdy karta platí; prázdné = bez omezení |
| Aktivní karta | neaktivní karta zůstává v evidenci, jen se nepoužívá |

Držitel se zobrazuje u bankovních pohybů a v přehledu plateb bez dokladu.
Pokud vyberete zaměstnance nebo uživatele, musí patřit do stejné firmy.
Smažete-li zaměstnance, karta zůstane a vazba na něj se zruší; jméno držitele
zůstává. Totéž platí pro vozidlo, jehož byl řidičem. Kartu, jejíž uložený
držitel už do firmy nepatří, jde dál upravovat — vazba se při uložení uvolní.

### 31.1.1 Platnost a archivace

Dvě karty se stejnou koncovkou nesmí u jedné firmy platit ve stejném období,
jinak by nešlo určit, čí platba to byla. Novou kartu se stejnou koncovkou
proto založte s platností navazující na tu původní.

Kartu, která se už nepoužívá, **archivujte** (akce Archivovat v detailu karty).
Archivace kartu nemaže: platby z minulosti jí zůstanou přiřazené. Pokud karta
neměla vyplněnou platnost do, doplní se dnešním datem. Archivovanou kartu
zobrazíte zaškrtnutím „Zobrazit archivované" a můžete ji obnovit.

## 31.2 Koncovka karty v bankovních pohybech

Při importu výpisu se z maskovaného čísla karty (například `PK: 000000******1234`)
uloží koncovka k pohybu. Funguje to pro GPC výpisy (doplňující řádky 078/079),
PDF výpisy CREDITAS a KB, bankovní API a e-mailová avíza, pokud maskované číslo
obsahují. Jméno obchodníka se u karetních plateb doplní do protistrany.
Maskovaný IBAN nebo číslo účtu (`CZ** **** … 1234`, `******1234/0100`) se za
kartu nepovažuje.

V detailu výpisu se u platby kartou zobrazí koncovka, a je-li karta
v evidenci, i její držitel nebo název.

Pohyby importované dřív se doplní samy při aktualizaci aplikace (krok
auto-backfill příkazu `php api/bin/migrate.php`). Ručně lze doplnění spustit
příkazem `php api/bin/backfill-card-last4.php --apply`.

## 31.3 Párování plateb kartou

Platba kartou se páruje s přijatou fakturou nebo účtenkou podle koncovky karty,
částky a data:

- **Doklad se stejnou koncovkou** a sedící částkou se spáruje automaticky.
  Doklad přitom musí být vystavený nejvýš 7 dní před zaúčtováním platby
  v bance a nejvýš 2 dny po něm.
- **Doklad placený kartou bez uvedené koncovky** je jen návrh ke kontrole,
  a to pouze tehdy, když si ho nemůže nárokovat platba jiné karty se stejnou
  částkou.
- **Doklad jiné karty se nespáruje nikdy**, ani podle shody částky a data. Dvě
  stejné platby dvěma kartami téhož dne se proto nespárují křížem.
- Dvě stejné platby toutéž kartou k jednomu dokladu jdou ke kontrole, aplikace
  nehádá, která z nich to byla.

Koncovku nese doklad vzniklý nahráním účtenky z přehledu plateb bez dokladu
(viz níže), účtenka z AI importu a doklad, ke kterému se připojil sken účtenky
s koncovkou karty.

Doklad nemusí být v aplikaci dřív než platba. Když přijde později, spáruje se
s platbou při dalším párování: akcí **Spárovat** v přehledu plateb bez dokladu
(viz níže) nebo párováním v detailu bankovního výpisu.

## 31.4 Platby kartou bez dokladu

Záložka **Platby bez dokladu** ukazuje odchozí platby kartou za zvolené období,
ke kterým zatím není spárovaný žádný doklad. Platby jsou seskupené podle karty
a držitele; karty, které nejsou v evidenci, jsou na konci seznamu. Nákupy
kreditní kartou (pohyby z výpisu úvěrového účtu) tu nejsou, ani když nesou
koncovku karty: řeší se v detailu kreditní karty, sekce Nákupy bez dokladu
(viz [Kreditní karty](112_Kreditni_karty.md)).

U každé platby jsou akce:

- **Nahrát účtenku**: nahrajete PDF nebo fotografii účtenky. Doklad se vytěží
  stejně jako při AI importu přijaté faktury, dostane formu úhrady „karta"
  a koncovku karty z platby. Otevřete ho, zkontrolujte a potvrďte. Bez AI (nebo
  když vytěžení selže) se účtenka neztratí: uloží se do [Příchozích dokladů](23_Prijate_faktury.md)
  (v Dokumentech složka Příchozí doklady / rok / měsíc), navázaná na platbu
  kartou, a doklad z ní založíte tam.
- **Spárovat**: spustí párování platby znovu, typicky po potvrzení dokladu
  z účtenky. Hledá mezi přijatými doklady (ne mezi soubory v Dokumentech):
  podle variabilního symbolu, podle koncovky karty a data (doklad s formou úhrady
  karta, datem zdanitelného plnění nebo vystavení 7 dní před až 2 dny po
  zaúčtování platby) a nakonec podle částky a podobného názvu dodavatele. Najde-li
  jeden doklad, platba se hned spáruje a zaúčtuje (viz Účtování plateb kartou).
  Podobný doklad nabídne jen jako návrh, který potvrdíte v detailu výpisu. Když
  nic nenajde, platba zůstane v přehledu.
- **Výpis**: otevře bankovní výpis rovnou na této platbě.

Platbu, ke které doklad nebude, zaúčtujete v detailu výpisu jako každý jiný
bankovní pohyb: ručně (MD/D), pravidlem nebo schválením návrhu automatiky.

U platby na čerpací stanici (podle obchodníka, např. název sítě stanic nebo
pohonné hmoty v popisu) se pod obchodníkem zobrazí **vozidlo držitele karty**,
pokud ho lze určit jednoznačně. Řídí-li držitel víc vozidel, aplikace to napíše
a vozidlo neurčí.

## 31.5 Vozidlo podle karty

Karta s držitelem vybraným ze zaměstnanců slouží i [knize jízd](36_Kniha_jizd.md):
tankování zaplacené kartou (účtenka nebo faktura s koncovkou karty, bankovní
pohyb kartou, import tankování se sloupcem karty) se přiřadí vozidlu, jehož
řidičem je držitel karty. Rozhoduje karta platná k datu tankování, takže
historická tankování zůstanou u tehdejšího držitele i po výměně karty.

Vozidlo se podle karty přiřadí jen tehdy, když na dokladu není SPZ a držitel
řídí právě jedno aktivní vozidlo. Podrobnosti viz kapitola Kniha jízd, oddíl
Přiřazení vozidla.

## 31.6 Oprávnění

Evidenci karet vidí a upravují uživatelé s oprávněním ke správě bankovních
účtů firmy. Přehled plateb bez dokladu vyžaduje přístup k bance, nahrání
účtenky oprávnění k nahrávání přijatých dokladů a spárování oprávnění
k párování bankovních pohybů.

## 31.7 Účtování plateb kartou

Platba kartou z výpisu se účtuje **přímo**, stejně jako každá jiná platba
z bankovního účtu, ke kterému je karta vedená. Mezičlen se nepoužívá.

| Situace | Kdy se účtuje | Zápis |
|---|---|---|
| Platba kartou spárovaná s přijatým dokladem | při spárování, automaticky | MD 321 / D 221 |
| Kurzový rozdíl | ve stejném zápisu, když se platba v Kč liší od předpisu dokladu v cizí měně | MD 563 nebo D 663 |
| Haléřový rozdíl | ve stejném zápisu, když se platba liší od dokladu do 1 Kč | MD 548 nebo D 648 |
| Vratka na kartu spárovaná s dobropisem | při spárování | MD 221 / D 321 |
| Platba kartou bez dokladu | pravidlem, návrhem automatiky nebo ručně ve výpisu | MD 5xx (nebo 335) / D 221 |
| Poplatek za kartu | pravidlem bankovního poplatku | MD 568 / D 221 |
| Výběr hotovosti kartou | převodem přes peníze na cestě | MD 261 / D 221, MD 211 / D 261 |

Cizoměnová faktura zaplacená kartou z korunového účtu se odúčtuje v kurzu
předpisu a rozdíl proti skutečně stržené částce v Kč jde na kurzový rozdíl
563/663. Když korunová platba zbytek faktury v kurzové toleranci nepokryje,
jde pohyb k ručnímu ověření.

Zrušení párování stornuje bankovní zápis platby (v uzavřeném nebo zamčeném
období storno nejde provést).

### 31.7.1 Daňová evidence

Firma v daňové evidenci účty nemá. Platba kartou je v ní bankovní výdaj
v peněžním deníku. Přehled plateb bez dokladu slouží jako výzva k doložení
výdaje.
