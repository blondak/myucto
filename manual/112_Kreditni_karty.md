# 112. Kreditní karty

Sekce **Peníze → Kreditní karty** vede úvěrové účty ke kreditním kartám. Kreditní
karta je v podstatě kontokorent: nákupy kartou zvyšují dluh vůči bance, splátky ho
snižují. Aplikace načte PDF výpis ke kreditní kartě, pohyby zaúčtuje na
**231 Krátkodobé úvěry** s analytikou pro každý úvěrový účet a nákupy spáruje
s přijatými doklady stejně jako platby z běžného účtu.

Samotné karty (koncovka, držitel) dál vedete v [Platebních kartách](31_Platebni_karty.md)
s typem „kreditní". Stránka Kreditní karty eviduje úvěrový účet, ke kterému karty
patří, a jeho výpisy.

## 112.1 Načtení výpisu

Akce **Nahrát výpis** načte PDF výpis ke kreditní kartě. Podporované banky:

| Banka | Výpis | Identifikace účtu |
|---|---|---|
| Komerční banka | Výpis z účtu ke kreditní kartě | číslo úvěrového účtu |
| Raiffeisenbank | Výpis z kartového účtu | referenční číslo karty (RB číslo účtu netiskne) |
| Česká spořitelna (Erste) | Výpis z kartového účtu | číslo kartového účtu |
| ČSOB | Výpis z úvěrového účtu ke kartě | číslo úvěrového účtu |

Výpis kreditní karty jde načíst i v sekci Banka akcí Nahrát PDF: aplikace ho
pozná a zpracuje stejně jako na stránce Kreditní karty. Výpis z e-mailové
schránky se načte automaticky, pokud úvěrový účet už v aplikaci je; první výpis
nového účtu nahrajte ručně.

Při načtení aplikace:

- **zkontroluje výpis**: součet pohybů musí sedět na rozdíl konečného
  a počátečního zůstatku na haléř, jinak výpis odmítne a nic neuloží,
- **založí úvěrový účet**, pokud ho firma ještě nemá. Účet založený importem je
  označený „Ke kontrole", dokud jeho údaje neuložíte v detailu,
- **převezme z výpisu** úvěrový limit a u Raiffeisenbank i účet a variabilní
  symbol pro splátku (jen do prázdných polí),
- **uloží pohyby** jako bankovní pohyby účtu. Stejný soubor ani pohyb
  z překrývajícího se výpisu se neuloží dvakrát,
- **zaúčtuje** pohyby, které umí (viz Účtování), ostatní jdou do fronty
  Zaúčtuj doklady stejně jako pohyby z běžného účtu.

Úvěrový účet, který eviduje jiná firma, načíst nejde. Kreditní účet vedený
v cizí měně aplikace odmítne; všechny podporované banky vedou kreditní účty
podnikatelů v korunách.

### 112.1.1 Účet vedený dřív jako bankovní účet

Výpis ČSOB ke kreditní kartě má stejný vzhled jako výpis běžného účtu. Pokud jste
ho v minulosti načetli v sekci Banka, vede ho aplikace jako bankovní účet a jeho
pohyby jsou zaúčtované na 221. Při dalším načtení nabídne **převod na kreditní
kartu**. Převod:

- přepne účet na úvěrový účet kreditní karty a přidělí mu analytiku 231,
- přepíše zaúčtované pohyby účtu z 221 na analytiku 231; protiúčty, částky ani
  data se nemění,
- nejde provést, pokud má účet zaúčtované pohyby v uzavřeném účetním období.

Přesun mezi 221 a 231 nemá vliv na daně, proto se zápisy přepisují na místě i
v datu zamčeném podaným přiznáním k DPH.

## 112.2 Přehled a detail

Přehled ukazuje u každého úvěrového účtu banku, číslo účtu, analytiku 231, limit,
**zůstatek v účetnictví** a poslední výpis. Pod zůstatkem je kontrola proti
výpisu: zůstatek analytiky ke dni posledního výpisu se porovná s jeho konečným
zůstatkem. „Sedí na výpis" znamená, že účetnictví odpovídá bance. Dluh je
v účetnictví i na výpisu záporný.

Počáteční zůstatek prvního načteného výpisu se sám nezaúčtuje: aplikace neví,
kde byl dosavadní dluh veden. Pokud kontrola po prvním výpisu ukazuje rozdíl ve
výši počátečního zůstatku, zaúčtujte ho ručním zápisem na analytiku úvěrového
účtu (například převodem z účtu, na kterém byl dluh veden dřív).

V detailu úvěrového účtu upravíte:

| Pole | Význam |
|---|---|
| Název | vaše pojmenování účtu |
| Úvěrový limit | limit úvěru podle smlouvy nebo výpisu |
| Účet pro splátku, kód banky | kam posíláte splátku z běžného účtu |
| Variabilní symbol splátky | VS, pod kterým banka splátku přijímá |
| Poznámka | volný text |

Detail dále ukazuje analytiku úvěru, výpisy účtu (s odkazem do detailu výpisu
v sekci Banka) a pohyby s druhem pohybu a stavem zaúčtování. Druh pohybu
(nákup, vratka, splátka, úrok, poplatek, výběr hotovosti, odměna) se určuje
podle typu transakce na výpisu.

Úvěrový účet, který už nepoužíváte, **archivujte**. Výpisy i zaúčtované pohyby
zůstanou beze změny, účet se jen skryje z přehledu.

## 112.3 Účtování

Každý úvěrový účet má vlastní analytiku **231.101, 231.102 …**, přidělovanou
postupně. Analytiku, na které už leží cizí zápisy (typicky bankovní úvěr),
si úvěrový účet automaticky nevezme. Jinou existující analytiku 231 lze vybrat
ručně v detailu účtu, pokud na stávající analytice není zůstatek.

Datum zápisu je **datum zaúčtování bankou**, ne datum transakce. Zůstatek
analytiky tak ke každému dni odpovídá výpisu. Datum transakce a původní částka
v cizí měně zůstávají v popisu pohybu.

| Případ | Zápis |
|---|---|
| Nákup kartou spárovaný s přijatým dokladem | MD 321 / D 231.x |
| Nákup kartou při zapnutém mezičlenu platebních karet | MD 378.x / D 231.x, vypořádání MD 321 / D 378.x |
| Nákup bez dokladu podle pravidla nebo ručně | MD 5xx / D 231.x |
| Vratka spárovaná s dobropisem | MD 231.x / D 321 |
| Splátka z vlastního běžného účtu | kreditní karta MD 231.x / D 261, běžný účet MD 261 / D 221.x |
| Splátka bez protiúčtu na výpisu | MD 231.x / D 261 |
| Úrok | MD 562 / D 231.x |
| Poplatek | MD 568 / D 231.x |
| Výběr hotovosti kartou | MD 261 / D 231.x |
| Odměna, cashback připsaný na úvěrový účet | MD 231.x / D 648 |

Nákupy kartou páruje a účtuje bankovní automatika stejně jako platby z běžného
účtu: spárovaná úhrada, pravidla, naučené kontace i návrhy AI. V pravidlech se
strana banky zadává jako 221; u pohybu kreditní karty se zapíše na analytiku 231
úvěrového účtu. Kurzový rozdíl mezi dokladem v cizí měně a platbou v korunách
jde na 563 nebo 663 jako u platby z běžného účtu.

Splátku z vlastního účtu aplikace spáruje jako převod mezi vlastními účty přes
261. Raiffeisenbank přijímá splátky na svůj sběrný účet s variabilním symbolem:
když ho v detailu úvěrového účtu vyplníte, platba z běžného účtu na tento účet
a s tímto VS se zaúčtuje jako převod MD 261 / D 221.x, ne jako výdaj.

Úroky, poplatky, splátky bez protiúčtu, výběry a odměny se účtují automaticky
nebo jako návrh podle nastavení automatiky: úroky a odměny podle typu Bankovní
úroky, poplatky podle Bankovní poplatky, splátky a výběry podle Převody mezi
vlastními účty. Zamítnutý návrh se u pohybu znovu nenabízí.

### 112.3.1 Nastavení účtování

Záložka **Nastavení účtování** určuje účty pro pohyby, které nejsou nákupem:

| Pole | Výchozí účet | Povolené účty |
|---|---|---|
| Úroky z úvěru | 562 | 56x |
| Poplatky | 568 | 5xx |
| Splátka bez protiúčtu | 261 | 261, 395 |
| Výběr hotovosti kartou | 261 | 261, 211 |
| Odměna a cashback | 648 | 6xx |

Změna platí jen pro nové zápisy, zaúčtované pohyby se nepřeúčtovávají.

### 112.3.2 Daňová evidence

Firma v daňové evidenci výpisy kreditní karty načte stejně. Pohyby vstoupí do
peněžního deníku: nákup spárovaný s dokladem je výdaj v den zaúčtování bankou,
splátka z vlastního účtu je převod mezi účty. Účtování na 231 a nastavení
účtování se uplatní jen v podvojném účetnictví.

## 112.4 Oprávnění

Přehled, detail a nastavení vidí uživatelé s přístupem k bance. Načtení výpisu
vyžaduje oprávnění k importu bankovních výpisů. Nastavení účtování, výběr
analytiky 231 a převod účtu na kreditní kartu vyžadují oprávnění k zaúčtování
bankovních pohybů. Údaje úvěrového účtu upravují uživatelé s oprávněním ke
správě bankovních účtů firmy.
